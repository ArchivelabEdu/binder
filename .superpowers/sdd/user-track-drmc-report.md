# DRMC PDF Data Enrichment Report

Date: 2026-07-07  
Branch: spike/docker-revival  
Task: `binder:seed-drmc-pdf`

---

## 1. Seeder Design

**File**: `lib/task/binder/arDrmcSeedDrmcPdfTask.class.php`  
**Command**: `php symfony binder:seed-drmc-pdf`  
**Idempotent**: yes — all operations guarded by identifier+LOD lookup before insert.

The task:
1. Calls `sfConfig::add(arDrmcBootstrapTask::getDrmcConfigArray())` to resolve runtime term IDs
2. Ensures `isDerivativeOf` and `isCopyOf` terms exist in the Associative relationship types taxonomy (560)
3. Creates 3 PDF-accurate artworks with full component trees
4. Creates 5 search-result stub artworks (artwork records only, no components)
5. Prints "Run search:populate" at end (does not invoke it itself)

Follows all patterns from `arDrmcSeedDemoTask` and `docs-spike/seed-demo-spec.md`.

---

## 2. Term Mapping Table

| Component color in PDF | LOD term name | sfConfig key | term.id |
|---|---|---|---|
| Artwork record (black) | Artwork record | `app_drmc_lod_artwork_record_id` | 537 |
| Description (grey) | Description | `app_drmc_lod_description_id` | 538 |
| Component (grey) | Component | `app_drmc_lod_component_id` | 539 |
| Artist supplied master (red) | Artist supplied master | `app_drmc_lod_artist_supplied_master_id` | 540 |
| Artist verified proof (orange) | Artist verified proof | `app_drmc_lod_artist_verified_proof_id` | 541 |
| Archival master (red) | Archival master | `app_drmc_lod_archival_master_id` | 542 |
| Exhibition format (green) | Exhibition format | `app_drmc_lod_exhibition_format_id` | 543 |
| Documentation (grey) | Documentation | `app_drmc_lod_documentation_id` | 544 |
| AIP (blue) | AIP | `app_drmc_lod_aip_id` | 547 |
| Digital object | Digital object | `app_drmc_lod_digital_object_id` | 548 |

### Derivation relation terms (taxonomy 560 — Associative relationship types)

| PDF arrow label | term name | created by seeder |
|---|---|---|
| isDerivativeOf | isDerivativeOf | yes |
| isCopyOf | isCopyOf | yes |

---

## 3. Thumbnail Generation

**Method**: ImageMagick `convert` in container (`/usr/bin/convert` — IM 6.9.7-4).  
**Output dir**: `/app/uploads/r/drmc-thumbs/` (volume-mounted from host `binder/uploads/r/drmc-thumbs/`)  
**No real photos used** — all geometric/abstract per Plan 09 policy.

| Artwork | File | Style |
|---|---|---|
| Manifestos 2 | `manifestos2_thumb.png` | Beige gradient + 4 dark grey rectangles (screens in gallery) |
| Lovers | `lovers_thumb.png` | Dark blue gradient + abstract silhouette ellipses |
| Grosse Fatigue | `grossefatigue_thumb.png` | Deep purple radial gradient (nebula) + yellow browser window |
| Space Invaders | `spaceinvaders_thumb.png` | Black + green pixel alien pattern |
| Official Welcome | `officialwelcome_thumb.png` | Dark blue gradient + warm oval (speaker/figure) |
| Sher-e-Bangla Nagar | `sherebanglanagar_thumb.png` | Beige + brown architectural line drawing |
| Acorde | `acorde_thumb.png` | Orange/yellow gradient + book-cover rectangles |
| As You See | `asyousee_thumb.png` | Dark grey gradient + grey ellipses + black borders (film still) |

Thumbnail path stored in `Thumbnail` and `FullImage` properties on artwork IO (consumed by ES → `tmsObject.thumbnail`).

---

## 4. Component Counts per Artwork

### Manifestos 2 (identifier 175258, IO id 670)
- Descendants in tree: **40** (including AIP and file IOs)
- Structural components: 4 channels × (1 mov master + 1 DVD chain + 1 mp4 chain + 1 AIFF) = 24 component IOs + 10 flat equipment/docs + Components parent + AIP IO + 4 file IOs + Grosse Fatigue IO itself
- Derivation relations: **12** (3 per channel × 4 channels)
- AIP: `a1b2c3d4-e5f6-7890-abcd-ef1234567800` (38 digital objects, ~50 GB)

### Lovers (identifier 81362, IO id 763)
- Descendants: **43** (37 components + Components parent + AIP IO + 4 file IOs + artwork)
- Components: 37 flat items (laser discs 1-5, slides, projectors, equipment, CD-Rs, etc.)
- Derivation relations: **0** (all flat per PDF p.19 — no derivation chains)
- AIP: `a504d67c-b73b-49c6-b76d-e33cb5cf5abc` (real UUID from PDF p.21, 31 digital objects)

### Grosse Fatigue (identifier 175938, IO id 850)
- Descendants: **22** (Blue box + Optical media + Mac.mini + Blu-Rays group + 2 Blu-Ray chains + DVDs group + 2 DVD chains + AIP IO + 5 file IOs)
- Components: 12 component IOs + 3 Description group nodes
- Derivation relations: **8** (ProRes→BR1, BR1→BR1df, ProRes→BR2, BR2→BR2df, ProRes→DVD1, DVD1→DVD1df, ProRes→DVD2, DVD2→DVD2df)
- AIP: `8dcbb052-aaaa-bbbb-cccc-dd1234567890` (48 digital objects, ~30 GB)

### Search-result stubs (p.12)
5 artwork records only: Space Invaders (102345), Official Welcome (103456), Sher-e-Bangla Nagar (104567), Acorde (105678), As You See (106789). No components.

---

## 5. Verification Results

### DB verification (post-run)
```
Total artworks (LOD=537): 11
  PDF artworks:   3 (175258, 81362, 175938)
  Demo artworks:  3 (100001, 100002, 100003) — unchanged
  Stub artworks:  5 (search result works)

Derivation relations (isDerivativeOf + isCopyOf total): 20
  Manifestos 2: 12 (3 per channel × 4)
  Grosse Fatigue: 8 (4 per disc format × 2 formats)
  Lovers: 0 (flat)

AIPs total: 7
  Demo AIPs: 4 (original seed + demo ingest)
  PDF AIPs: 3 (M2, Lovers, GF)
```

### ES verification (post search:populate)
```
Index atom: 441 documents in 7.67s
Artworks with PDF thumbnails in ES: 8 (3 PDF + 5 stubs)
Demo artworks: still using /images/binder-logo.png (unchanged)
```

### Tree structure (Grosse Fatigue — sampled)
```
Grosse Fatigue (537)
  Components (538)
    Blue presentation box (539)
    Optical media (538)
      Mac.mini (540 = Artist supplied master)
      Blu-Rays (538)
        ProRes 422.mov [Blu-Ray master] (540)
          [AIP IO + 5 file IOs]
        Blu-Ray 1 (542 = Archival master)
        Blu-Ray (digital file) 1 (543 = Exhibition format)
        Blu-Ray 2 (542)
        Blu-Ray (digital file) 2 (543)
      DVDs (538)
        ProRes 422.mov [DVD master] (540)
        DVD 1 (542)
        DVD (digital file) 1 (543)
        DVD 2 (542)
        DVD (digital file) 2 (543)
```
Context browser colors: red=540, red=542, green=543 — matches PDF p.20.

---

## 6. Deviations from PDF Spec

1. **Manifestos 2 "untitled ×4" nodes**: PDF shows 4 untitled grey components plus synchronizer, pedestals etc. Implemented as named components (synchronizer, pedestals, 4 Samsung TVs, etc.) rather than untitled because QubitInformationObject requires a title or else the tree shows "Untitled" — chose descriptive names matching PDF intent.
2. **Lovers 31 vs 37 components**: PDF p.19 lists ~31 items; implemented 37 to fully cover all named items in the spec including 5 laser discs, 5 projectors, and 3 disc image digital files. Count differs because spec lists "×3" disc images.
3. **isCopyOf direction in Manifestos 2**: PDF shows `.mov → DVD digital files → DVD` (two hops). Implemented as `.mov isDerivativeOf DVD digital files` and `DVD digital files isCopyOf DVD` — matches the associative relation direction the context browser reads.
4. **HTTP auth for API verification**: All `/api/*` routes are `is_secure: true`. Verified structure via direct ES query and PHP CLI instead of HTTP API calls. The data is confirmed correct in DB and ES.
5. **Lovers `Pioneer LD-V8000` tech record**: Not created here (already exists from demo seed linked to artwork 100001/Lovers). The PDF Lovers (81362) does not show a tech record in the context browser — intentionally omitted.

---

## 7. Files Changed/Created

- **NEW**: `lib/task/binder/arDrmcSeedDrmcPdfTask.class.php` — the seeder task
- **NEW**: `uploads/r/drmc-thumbs/*.png` — 8 generated thumbnails (committed as binary, each <100KB)
