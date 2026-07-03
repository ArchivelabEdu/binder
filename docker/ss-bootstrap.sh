#!/bin/sh
# Bootstrap the Archivematica Storage Service for the Binder spike:
# migrations, API user (matching the app's ARCHIVEMATICA_SS_* env), pipeline,
# local-filesystem space/locations, and registration of the three fake AIPs
# whose UUIDs binder:seed-demo uses. Idempotent: safe to re-run.
#
# Run from this directory AFTER `docker compose up -d ss`:
#   sh ss-bootstrap.sh
set -e

SS=http://localhost:8001
AUTH='Authorization: ApiKey spike:spike'
JSON='Content-Type: application/json'
PIPELINE_UUID=00000000-0000-0000-0000-000000000000

manage() {
  docker compose exec -T ss pyenv exec python3 -m archivematica.storage_service.manage "$@"
}

echo '==> migrate'
manage migrate --noinput | tail -1

echo '==> create user spike (api key spike)'
manage create_user --username spike --email spike@example.com \
  --password spike --api-key spike --superuser 2>&1 | tail -1 || true

echo '==> pipeline'
curl -sf "$SS/api/v2/pipeline/$PIPELINE_UUID/" -H "$AUTH" >/dev/null 2>&1 || \
  curl -sf -X POST "$SS/api/v2/pipeline/" -H "$AUTH" -H "$JSON" -d "{
    \"uuid\": \"$PIPELINE_UUID\",
    \"description\": \"Binder spike pipeline\",
    \"remote_name\": \"http://app\",
    \"create_default_locations\": false}" >/dev/null
echo "    pipeline $PIPELINE_UUID OK"

echo '==> space + locations'
mkdir -p ss-home/transfer_source ss-home/aip_storage ss-home/staging
chmod -R 777 ss-home

SPACE=$(curl -s "$SS/api/v2/space/" -H "$AUTH" | python3 -c \
  "import json,sys; o=[s for s in json.load(sys.stdin)['objects'] if s['path']=='/home']; print(o[0]['uuid'] if o else '')")
if [ -z "$SPACE" ]; then
  SPACE=$(curl -sf -X POST "$SS/api/v2/space/" -H "$AUTH" -H "$JSON" \
    -d '{"access_protocol":"FS","path":"/home","staging_path":"/home/staging"}' | \
    python3 -c "import json,sys; print(json.load(sys.stdin)['uuid'])")
fi
echo "    space $SPACE"

get_or_create_location() { # $1 purpose, $2 relative_path, $3 description
  LOC=$(curl -s "$SS/api/v2/location/?purpose=$1" -H "$AUTH" | python3 -c \
    "import json,sys; o=[l for l in json.load(sys.stdin)['objects'] if l['relative_path']=='$2']; print(o[0]['uuid'] if o else '')")
  if [ -z "$LOC" ]; then
    LOC=$(curl -sf -X POST "$SS/api/v2/location/" -H "$AUTH" -H "$JSON" -d "{
      \"pipeline\": [\"/api/v2/pipeline/$PIPELINE_UUID/\"],
      \"space\": \"/api/v2/space/$SPACE/\",
      \"purpose\": \"$1\",
      \"relative_path\": \"$2\",
      \"description\": \"$3\"}" | \
      python3 -c "import json,sys; print(json.load(sys.stdin)['uuid'])")
  fi
  echo "$LOC"
}
TS=$(get_or_create_location TS transfer_source "Binder spike origin")
AS=$(get_or_create_location AS aip_storage "Binder spike AIP storage")
echo "    transfer_source $TS / aip_storage $AS"

echo '==> fake AIPs (uncompressed BagIt bags: no pointer file needed, and'
echo '    the storage service can genuinely fixity-check them)'
register_aip() { # $1 uuid, $2 name
  BAG="$2-$1"
  if curl -sf "$SS/api/v2/file/$1/" -H "$AUTH" >/dev/null 2>&1; then
    echo "    $2 already registered"; return
  fi
  if [ ! -d "ss-home/transfer_source/$BAG" ]; then
    python3 - "$1" "$2" <<'PY'
import hashlib, os, sys
uuid, name = sys.argv[1], sys.argv[2]
bag = os.path.join('ss-home', 'transfer_source', '%s-%s' % (name, uuid))
data = os.path.join(bag, 'data')
os.makedirs(data)
payload = 'Fake AIP payload for the Binder spike (%s / %s)\n' % (name, uuid)
with open(os.path.join(data, 'README.txt'), 'w') as f:
    f.write(payload)
with open(os.path.join(bag, 'bagit.txt'), 'w') as f:
    f.write('BagIt-Version: 0.97\nTag-File-Character-Encoding: UTF-8\n')
md5 = hashlib.md5(payload.encode()).hexdigest()
with open(os.path.join(bag, 'manifest-md5.txt'), 'w') as f:
    f.write('%s  data/README.txt\n' % md5)
with open(os.path.join(bag, 'bag-info.txt'), 'w') as f:
    f.write('Payload-Oxum: %d.1\n' % len(payload))
# The storage service checksums the tagmanifest for uncompressed packages
def filemd5(p):
    with open(p, 'rb') as f:
        return hashlib.md5(f.read()).hexdigest()
with open(os.path.join(bag, 'tagmanifest-md5.txt'), 'w') as f:
    for tag in ('bag-info.txt', 'bagit.txt', 'manifest-md5.txt'):
        f.write('%s %s\n' % (filemd5(os.path.join(bag, tag)), tag))
PY
    chmod -R 777 "ss-home/transfer_source/$BAG"
  fi
  SIZE=$(du -sk "ss-home/transfer_source/$BAG" | cut -f1)
  curl -sf -X POST "$SS/api/v2/file/" -H "$AUTH" -H "$JSON" -d "{
    \"uuid\": \"$1\",
    \"origin_location\": \"/api/v2/location/$TS/\",
    \"origin_path\": \"$BAG/\",
    \"current_location\": \"/api/v2/location/$AS/\",
    \"current_path\": \"$BAG\",
    \"package_type\": \"AIP\",
    \"size\": $((SIZE * 1024)),
    \"origin_pipeline\": \"/api/v2/pipeline/$PIPELINE_UUID/\"}" >/dev/null
  echo "    $2 registered"
}
register_aip 6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52 lovers_archival_master
register_aip 9559945a-52e6-4c0e-9d54-83e8e548dd1d lovers_exhibition_format
register_aip d3a3f8d6-8a5b-4e6c-9c1a-2f4b7c1d9e0f grossefatigue_transfer_1

echo '==> smoke checks'
curl -s -o /dev/null -w "    pipeline:  HTTP %{http_code}\n" "$SS/api/v2/pipeline/$PIPELINE_UUID/" -H "$AUTH"
curl -s -o /dev/null -L -w "    download:  HTTP %{http_code} (%{size_download} bytes)\n" "$SS/api/v2/file/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/download/" -H "$AUTH"
curl -s -L "$SS/api/v2/file/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/check_fixity/?format=json" -H "$AUTH" | head -c 200; echo
echo 'Done.'
