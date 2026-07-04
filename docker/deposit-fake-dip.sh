#!/bin/sh
# POST the fake DIP (built by docker/build-fake-dip.sh) to Binder's SWORD
# deposit endpoint, mimicking Archivematica's upload_qubit.py exactly:
# deposit "by reference" — empty body, package located via Content-Location
# file://<basename>, which qtPackageExtractorBase::grab() joins to
# app_sword_deposit_dir (/app/uploads/sword-deposits in the app container).
#
# Target "ar:100002" = artwork record with TMS object id 100002.
# Expected response: HTTP 202 (job queued on gearman; the worker container
# does the actual ingest — follow it with: docker compose logs -f worker).
set -eu

BASE_URL="${BINDER_URL:-http://localhost:8090}"
TARGET="ar:100002"

AIP_UUID="aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001"
DIP_NAME="Demo_ingest-${AIP_UUID}"

# BasicAuth: qtSwordPluginHttpAuthFilter -> QubitUser::checkCredentials
# (username = Binder user email)
SWORD_USER="${SWORD_USER:-demo@example.com}"
SWORD_PASS="${SWORD_PASS:-demo}"

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
if [ ! -d "${SCRIPT_DIR}/../uploads/sword-deposits/${DIP_NAME}" ]; then
  echo "warning: ${DIP_NAME} not found under uploads/sword-deposits/ — run docker/build-fake-dip.sh first" >&2
fi

# Headers exactly as Archivematica's upload_qubit.py sends them.
# X-Packaging must match qtSwordPluginConfiguration::$packaging['1.0'].
# Content-Type is ignored for deposits by reference but sent for fidelity.
curl -s -i -X POST "${BASE_URL}/sword/deposit/${TARGET}" \
  -u "${SWORD_USER}:${SWORD_PASS}" \
  -H 'User-Agent: Archivematica' \
  -H 'X-Packaging: http://purl.org/net/sword-types/METSArchivematicaDIP' \
  -H 'X-No-Op: false' \
  -H 'X-Verbose: false' \
  -H 'Content-Type: application/zip' \
  -H "Content-Location: file:///${DIP_NAME}" \
  --data-binary ''

echo ""
echo "(202 Accepted = job queued; watch: docker compose -f ${SCRIPT_DIR}/docker-compose.yml logs -f worker)"
