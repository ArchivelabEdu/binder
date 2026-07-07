#!/usr/bin/env bash
# apply-real-thumbs.sh
#
# Re-applies real artwork thumbnail images from binder-next real-thumbs
# to the DRMC thumbnail directory (gitignored, not committed).
#
# Run from repo root: bash scripts/apply-real-thumbs.sh
# Requires: imagemagick (convert / magick command)
#
# Mapping:
#   175258.jpg -> Manifestos 2       -> manifestos2_thumb.png
#   81362.jpg  -> Lovers             -> lovers_thumb.png
#   175938.jpg -> Grosse Fatigue     -> grossefatigue_thumb.png
#   169996.jpg -> Space Invaders     -> spaceinvaders_thumb.png
#   143751.jpg -> Official Welcome   -> officialwelcome_thumb.png
#
# No real-thumb for: Sher-e-Bangla Nagar, Acorde, As You See
# (DRMC-specific stubs with no MoMA objectId in binder-next fixtures)
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REAL_DIR="${REPO_ROOT}/../binder-next/apps/web/public/real-thumbs"
THUMBS_DIR="${REPO_ROOT}/uploads/r/drmc-thumbs"

if [ ! -d "$REAL_DIR" ]; then
  echo "ERROR: real-thumbs dir not found at $REAL_DIR" >&2
  exit 1
fi

mkdir -p "$THUMBS_DIR"

convert_thumb() {
  local src="$1"
  local dest="$2"
  local label="$3"
  if [ ! -f "$src" ]; then
    echo "[skip]    $label — source not found: $src"
    return
  fi
  # Try modern magick first, fall back to convert
  if command -v magick &>/dev/null; then
    magick "$src" -resize 400x300^ -gravity center -extent 400x300 "$dest"
  else
    convert "$src" -resize 400x300^ -gravity center -extent 400x300 "$dest"
  fi
  echo "[ok]      $label -> $(basename $dest)"
}

convert_thumb "$REAL_DIR/175258.jpg"  "$THUMBS_DIR/manifestos2_thumb.png"    "Manifestos 2"
convert_thumb "$REAL_DIR/81362.jpg"   "$THUMBS_DIR/lovers_thumb.png"          "Lovers"
convert_thumb "$REAL_DIR/175938.jpg"  "$THUMBS_DIR/grossefatigue_thumb.png"   "Grosse Fatigue"
convert_thumb "$REAL_DIR/169996.jpg"  "$THUMBS_DIR/spaceinvaders_thumb.png"   "Space Invaders"
convert_thumb "$REAL_DIR/143751.jpg"  "$THUMBS_DIR/officialwelcome_thumb.png" "Official Welcome"

echo ""
echo "Done. Unchanged (no real-thumb available):"
echo "  sherebanglanagar_thumb.png, acorde_thumb.png, asyousee_thumb.png"
echo ""
echo "Verify: curl http://localhost:8090/uploads/r/drmc-thumbs/manifestos2_thumb.png -o /dev/null -w '%{http_code}\n'"
