#!/bin/sh
# Run a genuine fixity scan of every AIP in the storage service and report
# the results to Binder — the same protocol as artefactual's `fixity` tool
# (GET SS /api/v2/file/<uuid>/check_fixity/ then POST Binder /api/fixity/<uuid>).
#
# Usage (from this directory, stack up, demo user seeded):
#   sh fixity-scan.sh
set -e

SS=http://localhost:8001
SS_AUTH='Authorization: ApiKey spike:spike'
BINDER=http://localhost:8090
BINDER_USER=demo@example.com
BINDER_PASS=demo

JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT

curl -sf -c "$JAR" -X POST "$BINDER/api/users/authenticate" \
  -H 'Content-Type: application/json' \
  -d "{\"username\":\"$BINDER_USER\",\"password\":\"$BINDER_PASS\"}" >/dev/null

SESSION=$(python3 -c "import uuid; print(uuid.uuid4())")
echo "==> fixity scan session $SESSION"

UUIDS=$(curl -sf "$SS/api/v2/file/?package_type=AIP" -H "$SS_AUTH" | \
  python3 -c "import json,sys; [print(o['uuid']) for o in json.load(sys.stdin)['objects']]")

for UUID in $UUIDS; do
  STARTED=$(date +%s)
  RESULT=$(curl -sf "$SS/api/v2/file/$UUID/check_fixity/?format=json" -H "$SS_AUTH")
  FINISHED=$(date +%s)

  PAYLOAD=$(printf '%s' "$RESULT" | python3 -c "
import json, sys
r = json.load(sys.stdin)
print(json.dumps({
    'success': bool(r.get('success')),
    'message': r.get('message') or '',
    'failures': r.get('failures') or {},
    'session_uuid': '$SESSION',
    'started': $STARTED,
    'finished': $FINISHED,
}))")

  CODE=$(printf '%s' "$PAYLOAD" | curl -s -o /dev/null -w '%{http_code}' \
    -b "$JAR" -X POST "$BINDER/api/fixity/$UUID" \
    -H 'Content-Type: application/json' -d @-)
  OK=$(printf '%s' "$RESULT" | python3 -c "import json,sys; print('OK' if json.load(sys.stdin).get('success') else 'FAILED')")
  echo "    $UUID fixity=$OK reported=$CODE"
done
echo 'Done.'
