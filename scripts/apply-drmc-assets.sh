#!/usr/bin/env bash
# apply-drmc-assets.sh
#
# Attaches real artwork images (private demo assets) to the seeded DRMC
# file IOs as digital objects and reindexes Elasticsearch, so the context
# browser sidebar file gallery (thumbnails mode) shows real previews.
#
# Asset layout (gitignored, host side = repo root):
#   uploads/r/drmc-assets/{identifier}/*.jpg|jpeg|png
#   e.g. uploads/r/drmc-assets/81362/installation_view_1.jpg  (Lovers)
#        uploads/r/drmc-assets/175938/still_01.jpg            (Grosse Fatigue)
#        uploads/r/drmc-assets/175258/channel_1.jpg           (Manifestos 2)
#
# Idempotent — safe to re-run whenever new assets arrive.
# Run from repo root: bash scripts/apply-drmc-assets.sh
#
set -euo pipefail

APP_CONTAINER="${APP_CONTAINER:-binder-spike-app-1}"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ASSETS_DIR="${REPO_ROOT}/uploads/r/drmc-assets"

if [ ! -d "$ASSETS_DIR" ] || [ -z "$(find "$ASSETS_DIR" -name '*.jpg' -o -name '*.jpeg' -o -name '*.png' 2>/dev/null | head -1)" ]; then
  echo "No image assets under $ASSETS_DIR — nothing to do."
  echo "Drop images as $ASSETS_DIR/{identifier}/*.jpg and re-run."
  exit 0
fi

echo "== Attaching assets to file IOs (container: $APP_CONTAINER) =="
docker exec "$APP_CONTAINER" sh -c "cd /app && php symfony binder:attach-drmc-assets"

echo ""
echo "== Reindexing Elasticsearch =="
docker exec "$APP_CONTAINER" sh -c "cd /app && php symfony search:populate"

echo ""
echo "== Verifying thumbnail derivative URLs (HTTP 200 expected) =="
docker exec "$APP_CONTAINER" sh -c '
  found=0
  for f in $(find /app/uploads/r -path /app/uploads/r/drmc-thumbs -prune -o -path /app/uploads/r/drmc-assets -prune -o -name "*_141.*" -print 2>/dev/null | head -5); do
    found=1
    url="${f#/app}"
    code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost${url}")
    echo "  $url -> HTTP:$code"
  done
  [ "$found" = "0" ] && echo "  (no thumbnail derivatives found yet)"
'

echo ""
echo "Done. Check the works view context browser sidebar: Files > grid icon."
