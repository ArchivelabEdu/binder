# Binder Revival Spike — Findings & Plan

Time-boxed feasibility spike for **Phase 1** (get the original Binder running).
Goal: measure how far the 2015 codebase boots when containerized, decoupled from
Archivematica, and produce a go/no-go + effort estimate for the full Phase 1 scope.

Working copy: fork `ArchivelabEdu/binder`, branch `qa/0.9.x`.

---

## 1. What Binder is — stack map

Original deployment = **two Ubuntu 14.04 (trusty) VMs** (per authors' install gist
<https://gist.github.com/sevein/e0b1d036721435add3cd>):

**`binder` VM** — the visible app (this repo):
- AtoM fork: **PHP 5.3.10+ / Symfony 1.x / Propel** (`config/package.xml` min = 5.3.10)
- DRMC-specific plugins: `arDrmcPlugin` (incl. AngularJS frontend), `arRestApiPlugin`,
  `arElasticSearchPlugin` (enabled in `config/ProjectConfiguration.class.php`)
- Data/infra: **MySQL 5.5**, **Elasticsearch 1.5**, memcached, gearman, nginx, php5-fpm, Java 8
- Media tools: imagemagick, ghostscript, poppler-utils, ffmpeg
- Auth: LDAP (optional)

**`archivematica` VM** — the ingest→AIP pipeline:
- Archivematica 1.3.x + Storage Service + **Elasticsearch 0.90** + ClamAV + FITS + Gearman

**Frontend** (`plugins/arDrmcPlugin/frontend/`): AngularJS **1.2.28**, d3 3.5.5, jquery 2.1,
rickshaw, built with **grunt 0.4.5** (`npm install && grunt build`).

**App init sequence:** `php symfony tools:purge` → `php symfony binder:bootstrap` →
`php symfony search:populate`.

---

## 2. Documented blockers (from the original authors)

The README + install gist explicitly state Binder is **"not ready … requires further
development for the code to function even in a development environment."** Known issues the
authors left in the gist TODO:

- **ES 1.x incompatibilities** (code targeted ES 0.90):
  - `script.disable_dynamic: false` now required
  - Total-size facets break (Groovy syntax)
  - `$query->setFields(...)` breaks on non-leaf `i18n` fields (should use `i18n.*`)
  - a block in `arElasticSearchInformationObjectPdo` "breaks badly"
- Archivematica→Binder upload flow incomplete; Binder worker config undocumented
- Uncertainty about what `binder:bootstrap` does re: DB migrations
- **Every apt PPA in the gist is now dead** (elasticsearch.org apt, webupd8 java,
  chris-lea node, archivematica 1.3.x) → the gist is not runnable as written

---

## 3. Local environment

macOS **arm64** (Apple Silicon), Docker 29.2.1, docker compose v5, vagrant 2.4.9, node 25.
No local PHP. Legacy **amd64** images all pullable (php:5.6-fpm, mysql:5.6, elasticsearch:1.7,
memcached, node:0.10, ubuntu:14.04) → run via `platform: linux/amd64` (emulated, slower).

---

## 4. Spike approach — decouple, containerize the Binder app only

**Decision:** for the feasibility spike, **skip Archivematica**. Stand up only the Binder PHP
app + its direct data deps (MySQL, Elasticsearch, memcached) in docker-compose, and
seed/mock data instead of live ingest. Rationale: the UI (artwork records, context browser,
dashboard, search) is served by the AtoM/PHP app and is testable without the heavyweight
ClamAV/FITS/Storage-Service/MCP pipeline. Maximizes information per unit of effort.

### Milestones (each = a recorded finding; stop when go/no-go is clear)

| # | Milestone | Status | Notes |
|---|-----------|--------|-------|
| 0 | Recon: stack map + blockers documented | ✅ | this doc |
| 1 | PHP app image builds (php5.6 + nginx + symfony1 deps) | ✅ | finding #1 |
| 2 | Containers boot; MySQL+ES reachable from app | ✅ | findings #2, #5 |
| 3 | Schema loads (`tools:purge`) + `binder:bootstrap` seeds DRMC data | ✅ | findings #3, #4 |
| 4 | nginx+fpm serves the app; login + dashboard render in browser | ✅ | findings #6, #7 |
| 5 | Frontend builds (node 8 + grunt) → dist/Binder.{vendor,app}.js | ✅ | finding #6 |
| 6 | `search:populate` + browse UI against ES | ✅ | **ES 5.6**, not 1.x — finding #5 |

**Spike verdict: GO.** The app boots end-to-end (decoupled from Archivematica): login →
dashboard → browse/search with facets, REST API authenticated, 262 docs indexed in ES 5.6
with zero errors. The 2015 "doesn't work even in dev" warning largely predates the
2015–2019 modernization work on this branch (see finding #5).

### Findings log

- **#1 (M1)** — `php:5.6-fpm` base is **Debian 9 stretch**, not jessie. First build pointed apt
  at the jessie archive → held-broken-packages. Fix: apt → `archive.debian.org … stretch`;
  stretch pkg names (`libpng-dev`, `default-mysql-client`, add `libsasl2-dev`).
- **#2 (M2)** — ES 1.7 rejects mixing deprecated `script.disable_dynamic` with fine-grained
  script settings (initial compose config crashed the container). Moot after #5.
- **#3 (M3)** — `ARCHIVEMATICA_SS_{PIPELINE_UUID,USER,API_KEY}` and `ATOM_DRMC_TMS_URL` are
  **hard-required at bootstrap** (`qubitConfiguration.class.php:141` throws) even with no
  Archivematica/TMS present. Dummy env values suffice for the decoupled app. php-fpm also
  needs `clear_env = no` for them to reach web requests.
- **#4 (M3)** — App expects services at localhost: memcached host fixed via untracked
  `apps/qubit/config/app.yml` override; ES host via untracked `config/search.yml` override
  (both created by docker/app/entrypoint.sh; both paths are gitignored upstream).
- **#5 (M6, KEY FINDING)** — **`qa/0.9.x` does NOT target the 2014 stack.** The branch was
  developed through 2019 tracking AtoM 2.5.x-era code: ES mappings use `keyword`/`text`
  (ES 5+ types), vendored Elastica is modern (has `Reindex.php`). ES 1.7 rejects the mapping
  outright (`No handler for type [keyword]`); **ES 5.6.16 accepts everything** —
  `search:populate` indexed 262 docs, 0 errors. The 2014 install gist (Ubuntu 14.04,
  ES 0.90/1.5, its ES-1.x blocker list) documents an OLDER generation (`qa/0.8.x`) and is
  obsolete for this branch. An audited fix-list for any leftover pre-5.x call sites is in
  `docs-spike/es56-audit.md` (multi-agent audit).
- **#6 (M5)** — Frontend builds with node 8 + `npm install --ignore-scripts` (skips the dead
  `contextify` native dep from d3 3.5's chain — same pain the 2015 gist complained about) +
  local `grunt-cli@1.2.0` (grunt 0.4 CLI; newer grunt-cli needs node ≥10.10). `grunt build`'s
  lint step is skippable: run `build-js build-css`. arDominionPlugin `main.css` must be
  compiled from `main.less` separately (`lessc`); AtoM's usual asset build does this.
- **#7 (M4)** — Login quirk: the "Username" field authenticates against the **email** column
  (`QubitUser::checkCredentials` matches `user.email`), so log in with `demo@example.com`,
  not `demo`. Cosmetic gap: font-awesome is referenced
  (`/plugins/vendor/fonts/font-awesome/...`) but never shipped in the repo → icons render
  as ☒; drop font-awesome 4.x into place later.

### How to run (current state)

```bash
cd docker && docker compose up -d --build
docker compose exec app sh -c 'cd /app && php symfony tools:purge --no-confirmation \
  --title=Binder --description="Binder spike" --username=demo \
  --email=demo@example.com --password=demo'
docker compose exec app sh -c 'cd /app && php symfony binder:bootstrap && php symfony search:populate'
# open http://localhost:8090/drmc — log in as demo@example.com / demo
```

Frontend rebuild (only when JS/LESS change):

```bash
cd plugins/arDrmcPlugin/frontend
docker run --rm --platform linux/amd64 -v "$PWD/../../..":/repo -w /repo/plugins/arDrmcPlugin/frontend \
  node:8-stretch sh -c 'npm install --ignore-scripts && npm i --no-save grunt-cli@1.2.0 && \
  ./node_modules/.bin/grunt build-js build-css --force'
```

### Next steps (post-spike)

1. ✅ **Done** — `php symfony binder:seed-demo` seeds 3 artworks + components + tech record +
   fake AIPs (spec: `docs-spike/seed-demo-spec.md`). Verified: works browse with facets, work
   view TMS panel, context browser graph, AIP detail + file list all render.
2. ✅ **Done (P1)** — the four API-blocking Elastica 5.x fixes from `docs-spike/es56-audit.md`
   are applied and verified. P2 (legacy AtoM server-rendered browse pages) remains open —
   cheap option is to 404 those routes; the DRMC Angular UI does not use them.
3. ✅ **Done** — Font Awesome 3.2.1 vendored at `plugins/vendor/fonts/font-awesome/` (the
   path `arDominionPlugin/css/main.css` imports); icons render everywhere.
4. ✅ **Done** — full UI walk + GET API sweep (`docs-spike/api-sweep.md`): dashboard with
   charts, components/files/tech-records/saved-searches browses, granular-ingest and
   component-level reports, TMS lookup page all render. 59 GET endpoints probed → 51 OK,
   0 real code bugs (8 failures = required-param validation or absent demo data:
   METS/premis and saved queries only exist after real Archivematica ingest).
5. ✅ **Done — P2**: legacy AtoM server-rendered routes are 404ed at nginx (default-deny;
   only /drmc, /api and assets exposed). Porting them to aggregations stays optional
   (`docs-spike/es56-audit.md` P2 list).
6. ✅ **Done — Storage Service integration**: real Archivematica Storage Service v0.24.0
   runs as the `ss` compose service (SQLite). `docker/ss-bootstrap.sh` registers the
   spike user/API key, the pipeline UUID Binder is configured with, an FS space +
   transfer-source/AIP-storage locations, and the three seeded AIP UUIDs as uncompressed
   BagIt bags (no pointer file needed; SS can genuinely fixity-check them).
   Verified end-to-end: Binder `downloadCheck` → `available:true`; full AIP download
   proxied through Binder returns the bag as tar; `docker/fixity-scan.sh` runs real
   BagIt validation in SS and reports to `/api/fixity/<uuid>` (same protocol as
   artefactual's `fixity` tool) → dashboard fixity widget + AIP-page report table
   show live data. Two fixityBrowse bugs fixed along the way (ES field `aip.uuid`,
   null last-recovery crash).
7. ✅ **Done — AIP recovery end-to-end**: corrupted a stored bag → SS fixity scan caught it
   (real Payload-Oxum diagnostic) → failure reported to Binder → Binder
   `POST /api/recover/<report-id>` → SS RECOVER_REQ (fixed `recoverRequestAction`: it read
   a nonexistent `$client->config` for the pipeline UUID → SS 500) → admin approval in the
   SS web UI → bag restored from the AR location → SS callback to `/api/recover/results`
   (configured via `recover_request_notification_*` settings in ss-bootstrap.sh) →
   Binder recovery record completed → re-scan Success. The AIP page shows the full story:
   Success → Failed×2 → AIP recovery (APPROVE) → Success.
   Ops note: ES needed X-Pack extras disabled + a data volume (watcher/ML OOM-killed the
   512MB heap; recreation wiped the unvolumed index).
8. **Open**: full Archivematica pipeline (DIP upload → new artworks) — the only remaining
   unexercised integration; LDAP-off auth story (email login already works via QubitUser).

### Deliverable
This table filled in + effort estimate + recommendation on whether full Phase 1 (faithful
revival incl. Archivematica) is worth it, or whether to pivot toward Phase 2 (modern rebuild).
