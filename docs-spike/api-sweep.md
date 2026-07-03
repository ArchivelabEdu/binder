# Binder DRMC REST API Sweep — dev stack (localhost:8090)

Date: 2026-07-04
Scope: 59 GET endpoints probed against the arRestApiPlugin API of the local Binder dev stack.

Result: **51 OK, 8 failed**. Of the 8 failures, after live diagnosis **0 require a code change**: the single failure originally triaged as a "real bug" turned out to be a caller error (missing required query parameter), and the remaining 7 are missing demo data (endpoints behaving as designed against an unseeded database/index).

## Endpoint table

| # | Endpoint | Result | Notes |
|---|----------|--------|-------|
| 1 | `/api/dashboard` | 200 OK | |
| 2 | `/api/aips` | 200 OK | |
| 3 | `/api/aips?sort=sizeDown&limit=2` | 200 OK | |
| 4 | `/api/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52` | 200 OK | |
| 5 | `/api/aips/9559945a-52e6-4c0e-9d54-83e8e548dd1d` | 200 OK | |
| 6 | `/api/aips/d3a3f8d6-8a5b-4e6c-9c1a-2f4b7c1d9e0f` | 200 OK | |
| 7 | `/api/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/files` | 200 OK | |
| 8 | `/api/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/downloadCheck` | 400 FAIL | Caller error: required `?reason=` param omitted by the sweep (see below) |
| 9 | `/api/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/downloadCheck?reason=api%20sweep%20verification%20test` | 200 OK | Same endpoint, correct params |
| 10 | `/api/aips/download` | 400 FAIL | Data/caller: requires `?url=` + `?filename=` (intentional validation) |
| 11 | `/api/informationobjects` | 200 OK | |
| 12 | `/api/informationobjects?limit=3` | 200 OK | |
| 13 | `/api/informationobjects/579` | 200 OK | |
| 14 | `/api/informationobjects/607` | 200 OK | |
| 15 | `/api/informationobjects/622` | 200 OK | |
| 16 | `/api/informationobjects/587` | 200 OK | |
| 17 | `/api/informationobjects/579/tree` | 200 OK | |
| 18 | `/api/informationobjects/579/tree/associations` | 200 OK | |
| 19 | `/api/informationobjects/579/files` | 200 OK | |
| 20 | `/api/informationobjects/579/tms` | 200 OK | |
| 21 | `/api/informationobjects/579/aips` | 200 OK | |
| 22 | `/api/informationobjects/association/596` | 200 OK | |
| 23 | `/api/informationobjects/579/technologies` | 200 OK | |
| 24 | `/api/informationobjects/579/mets` | 404 FAIL | Data: no METS data in demo seed |
| 25 | `/api/informationobjects/587/mets` | 404 FAIL | Data: no METS data in demo seed |
| 26 | `/api/informationobjects/590/mets` | 404 FAIL | Data: no METS data in demo seed |
| 27 | `/api/informationobjects/593/mets` | 404 FAIL | Data: no METS data in demo seed |
| 28 | `/api/informationobjects/works` | 200 OK | |
| 29 | `/api/informationobjects/works?sort=alphabetic&limit=2` | 200 OK | |
| 30 | `/api/informationobjects/components` | 200 OK | |
| 31 | `/api/informationobjects/technologies` | 200 OK | |
| 32 | `/api/informationobjects/files` | 200 OK | |
| 33 | `/api/informationobjects/579/status` | 200 OK | |
| 34 | `/api/actors` | 200 OK | |
| 35 | `/api/actors/teiji-furuhashi` | 200 OK | |
| 36 | `/api/actors/camille-henrot` | 200 OK | |
| 37 | `/api/taxonomies/34` | 200 OK | |
| 38 | `/api/taxonomies/46` | 200 OK | |
| 39 | `/api/countries` | 200 OK | |
| 40 | `/api/activity/downloads` | 200 OK | |
| 41 | `/api/activity/ingestion` | 200 OK | |
| 42 | `/api/summary/ingestion` | 200 OK | |
| 43 | `/api/summary/artworkbydate` | 200 OK | |
| 44 | `/api/summary/storagebymediacategory` | 200 OK | |
| 45 | `/api/summary/storagebycodec` | 200 OK | |
| 46 | `/api/summary/departmentartworkcount` | 200 OK | |
| 47 | `/api/summary/mediafilesizebycollectionyear` | 200 OK | |
| 48 | `/api/summary/storagesizebydate` | 200 OK | |
| 49 | `/api/search/autocomplete?query=lovers` | 200 OK | |
| 50 | `/api/searches` | 200 OK | |
| 51 | `/api/searches/1` | 404 FAIL | Data: no saved searches exist (`saved_query` empty) |
| 52 | `/api/users/authenticate` | 200 OK | |
| 53 | `/api/fixity/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52` | 200 OK | |
| 54 | `/api/fixity/status` | 200 OK | |
| 55 | `/api/report?type=high_level_ingest` | 200 OK | |
| 56 | `/api/report?type=component_level` | 200 OK | |
| 57 | `/api/reportcsv?type=high_level_ingest` | 200 OK | |
| 58 | `/api/reports/1` | 404 FAIL | Data: no saved reports exist (`saved_query` empty) |
| 59 | `/api/reports/browse` | 200 OK | |

## Triaged "real bug" — resolved as caller error, no code change needed

### GET `/api/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/downloadCheck` → 400 "Missing parameter: reason"

- **URL:** `http://localhost:8090/api/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/downloadCheck`
- **Root cause:** `/Users/daejinan/claude/moma-binder/binder/plugins/arRestApiPlugin/modules/api/actions/aipsDownloadCheckAction.class.php:31-39` — the 400 is the endpoint's intentional input validation. It throws `QubitApiBadRequestException('Missing parameter: reason')` when `?reason=` is absent (and `'Parameter reason is not long enough'` when under 10 chars), because the reason is required for the AIP access log (`logAccessAttempt`, line 119). The sweep called the URL without the parameter.
- **Verification:** the same URL with `?reason=automated%20sweep%20verification%20test` returns HTTP 200 with valid JSON (`{"available":false,"reason":"Request to Archivematica Storage Service failed: Connection refused..."}` — graceful handling of the absent storage service in this dev stack, per the error branch at lines 72-78). ES lookup, access logging, and response serialization all work; no Elastica 5.x issue, no code bug.
- **Minimal fix:** no code change. Fix the sweep/caller: append a `reason` query parameter of at least 10 characters, e.g. `GET /api/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/downloadCheck?reason=automated%20sweep%20verification%20test`
- **Confidence:** high

## Data-only failures (endpoint behaved as designed; demo stack lacks the data or params)

Grouped by shared root cause:

**Missing required query params (intentional validation, same pattern as downloadCheck):**
- `http://localhost:8090/api/aips/download` — `aipsDownloadAction.class.php:24-26` throws `QubitApiBadRequestException('Missing parameter: url')` when `?url=` is absent; the endpoint is a pass-through proxy to the Archivematica Storage Service requiring `?url=` and `?filename=` from the frontend. Verified live: with dummy params it returns 200 with correct Content-Disposition headers.

**No METS/PREMIS data in demo seed (same root cause x4):** `informationobjectsMetsAction.class.php:42-45` throws the designed `QubitApi404Exception('METS data not found')` because `metsData` is only indexed (`arElasticSearchInformationObjectPdo.class.php:1186-1191`) for 'Digital object'-LOD IOs with `premis_object` rows — populated only by real Archivematica DIP ingest, never by the demo seeder (`arDrmcSeedDemoTask`). Live DB: 0 rows in `premis_object`; 0/296 ES docs carry `metsData`. Elastica 5.x setSource/getData paths executed correctly.
- `http://localhost:8090/api/informationobjects/579/mets` — IO 579 is an 'Artwork record' (LOD 537); ES doc has no `metsData` field.
- `http://localhost:8090/api/informationobjects/587/mets` — IO 587 is 'Archival master' component; doc found but `_source` lacks `metsData`.
- `http://localhost:8090/api/informationobjects/590/mets` — IO 590 is LOD 543 'Exhibition format'; no `metsData` in `_source`.
- `http://localhost:8090/api/informationobjects/593/mets` — IO 593 is 'Duplicating copy' component (LOD 549); `_exists_:metsData` matches 0 docs index-wide.

**Empty `saved_query` table / QubitSavedQuery ES type (same root cause x2):** `getDocument(1)` throws `Elastica\Exception\NotFoundException`, deliberately mapped to a 404. Verified: `saved_query` has 0 rows and `atom/QubitSavedQuery/_count` is 0.
- `http://localhost:8090/api/searches/1` — `searchesReadAction.class.php:28-32` → `QubitApi404Exception('Search not found')`; no saved search with id 1 exists.
- `http://localhost:8090/api/reports/1` — `reportsReadAction.class.php:28-33` → `QubitApi404Exception('Report not found')`; endpoint behaved correctly for a nonexistent record.
