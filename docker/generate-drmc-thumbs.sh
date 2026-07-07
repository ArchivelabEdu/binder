#!/bin/sh
# Generate DRMC demo thumbnails using ImageMagick.
# Run this inside the app container or on a host with ImageMagick installed.
# Output goes to /app/uploads/r/drmc-thumbs/ (adjust DEST if running on host).
#
# Usage (inside container): sh /app/docker/generate-drmc-thumbs.sh
# Usage (on host):          DEST=/path/to/binder/uploads/r/drmc-thumbs sh docker/generate-drmc-thumbs.sh

DEST="${DEST:-/app/uploads/r/drmc-thumbs}"
mkdir -p "$DEST"

# Manifestos 2: white gallery space with 4 dark screens (four-channel video)
convert \
  -size 400x300 gradient:'#f5f5f0-#e8e8e0' \
  -fill '#ccccbc' -stroke '#aaaaaa' -strokewidth 1 \
  -draw "rectangle 20,30 115,230" \
  -draw "rectangle 125,30 220,230" \
  -draw "rectangle 230,30 325,230" \
  -draw "rectangle 335,30 380,230" \
  -fill '#111111' \
  -draw "rectangle 28,38 107,222" \
  -draw "rectangle 133,38 212,222" \
  -draw "rectangle 238,38 317,222" \
  -draw "rectangle 343,38 372,222" \
  -quality 90 "$DEST/manifestos2_thumb.png"

# Lovers: dark blue with abstract human silhouettes
convert \
  -size 400x300 gradient:'#050510-#0a0a20' \
  -fill '#8888cc' -draw "ellipse 120,200 30,80 0,360" \
  -fill '#6666aa' -draw "ellipse 280,200 30,80 0,360" \
  -fill '#aaaadd' -draw "ellipse 120,120 15,20 0,360" \
  -fill '#aaaadd' -draw "ellipse 280,120 15,20 0,360" \
  -fill none -stroke '#6666bb' -strokewidth 2 \
  -draw "bezier 120,160 160,250 240,250 280,160" \
  -quality 90 "$DEST/lovers_thumb.png"

# Grosse Fatigue: nebula background + yellow browser window
convert \
  -size 400x300 radial-gradient:'#1a0a2e-#050510' \
  -fill '#3d1a60' -draw "ellipse 200,150 120,80 0,360" \
  -fill '#5a2080' -draw "ellipse 100,200 60,40 0,360" \
  -fill '#4a1870' -draw "ellipse 320,100 40,30 0,360" \
  -fill '#ddcc00' -stroke '#ffee22' -strokewidth 2 \
  -draw "rectangle 140,80 310,200" \
  -fill '#222200' -draw "rectangle 145,85 305,195" \
  -fill '#ddcc00' -draw "rectangle 145,85 305,97" \
  -fill '#333300' \
  -draw "rectangle 148,88 155,94" \
  -draw "rectangle 158,88 165,94" \
  -draw "rectangle 168,88 175,94" \
  -quality 90 "$DEST/grossefatigue_thumb.png"

# Space Invaders: black with green pixel alien pattern
convert \
  -size 400x300 xc:'#000000' \
  -fill '#00ff00' \
  -draw "rectangle 50,50 58,58" -draw "rectangle 60,50 68,58" \
  -draw "rectangle 46,60 68,68" -draw "rectangle 50,70 58,78" -draw "rectangle 60,70 68,78" \
  -draw "rectangle 150,80 158,88" -draw "rectangle 160,80 168,88" \
  -draw "rectangle 146,90 168,98" -draw "rectangle 150,100 158,108" -draw "rectangle 160,100 168,108" \
  -draw "rectangle 250,50 258,58" -draw "rectangle 260,50 268,58" \
  -draw "rectangle 246,60 268,68" -draw "rectangle 250,70 258,78" -draw "rectangle 260,70 268,78" \
  -draw "rectangle 100,150 108,158" -draw "rectangle 110,150 118,158" \
  -draw "rectangle 96,160 118,168" -draw "rectangle 100,170 108,178" -draw "rectangle 110,170 118,178" \
  -draw "rectangle 200,130 208,138" -draw "rectangle 210,130 218,138" \
  -draw "rectangle 196,140 218,148" -draw "rectangle 200,150 208,158" -draw "rectangle 210,150 218,158" \
  -draw "rectangle 300,160 308,168" -draw "rectangle 310,160 318,168" \
  -draw "rectangle 296,170 318,178" -draw "rectangle 300,180 308,188" -draw "rectangle 310,180 318,188" \
  -quality 90 "$DEST/spaceinvaders_thumb.png"

# Official Welcome: dark blue with speaker/figure silhouette
convert \
  -size 400x300 gradient:'#1a3a6a-#0a1a3a' \
  -fill '#c8a87a' -draw "ellipse 200,110 55,65 0,360" \
  -fill '#a08060' -draw "rectangle 165,170 235,240" \
  -fill '#888888' -draw "rectangle 170,238 230,280" \
  -fill '#444444' -draw "rectangle 140,278 260,290" \
  -fill '#aaaaaa' -strokewidth 1 -stroke '#ffffff' \
  -draw "rectangle 195,155 205,175" \
  -quality 90 "$DEST/officialwelcome_thumb.png"

# Sher-e-Bangla Nagar: beige architectural tracing drawing
convert \
  -size 400x300 xc:'#f5f0e0' \
  -fill none -stroke '#8a7050' -strokewidth 1 \
  -draw "rectangle 30,30 370,270" \
  -draw "rectangle 60,60 340,240" \
  -draw "line 60,150 340,150" \
  -draw "line 200,60 200,240" \
  -draw "rectangle 80,80 150,140" \
  -draw "rectangle 250,80 320,140" \
  -draw "rectangle 80,160 150,220" \
  -draw "rectangle 250,160 320,220" \
  -draw "ellipse 200,60 30,20 0,360" \
  -quality 90 "$DEST/sherebanglanagar_thumb.png"

# Acorde: orange/yellow book cover
convert \
  -size 400x300 gradient:'#ff8800-#ffcc00' \
  -fill '#ff6600' -draw "rectangle 80,40 320,260" \
  -fill '#ffdd44' -draw "rectangle 88,48 312,252" \
  -fill '#cc4400' -draw "line 200,60 200,240" \
  -draw "line 88,150 312,150" \
  -fill '#884400' -strokewidth 2 -stroke '#884400' \
  -draw "rectangle 110,80 200,140" \
  -draw "rectangle 210,80 300,140" \
  -quality 90 "$DEST/acorde_thumb.png"

# As You See: black and white film still
convert \
  -size 400x300 gradient:'#111111-#333333' \
  -fill '#999999' -draw "ellipse 130,130 50,60 0,360" \
  -fill '#cccccc' -draw "ellipse 270,130 50,60 0,360" \
  -fill '#444444' -draw "rectangle 0,240 400,300" \
  -fill '#888888' -draw "rectangle 100,180 300,240" \
  -fill '#222222' -draw "rectangle 0,0 400,30" \
  -draw "rectangle 0,270 400,300" \
  -quality 90 "$DEST/asyousee_thumb.png"

echo "Done. Generated 8 thumbnails in $DEST"
