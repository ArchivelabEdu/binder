# Phase 2 — Plan 09: 데모 확충 + 백로그 청산 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** MoMA CC0 메타데이터 기반으로 데모 컬렉션을 PDF(DRMC 원본 문서) 수준으로 확충(8작품 추가 + 컴포넌트 계층 + 실재생 파일 + 썸네일 렌더링)하고, Plans 07/08 잔여 백로그를 청산한다.

**Architecture:** 메타데이터는 MoMA CC0 데이터셋에서 추출한 커밋된 JSON 픽스처가 정본(이미지 제외 — 썸네일은 ImageMagick 생성 플레이스홀더를 `apps/web/public`에 커밋). 실파일은 ffmpeg 생성 미디어를 스크립트로 만들어 LocalFs 백엔드 tar에 담는다(비커밋·생성형). 시더는 기존 seed-demo 패턴을 확장.

**Tech Stack:** 기존 스택 + 로컬 도구 ffmpeg 8(/opt/homebrew/bin/ffmpeg)·ImageMagick 7(magick) — **런타임 의존성 추가 없음**(생성 스크립트는 child_process로 도구 호출, 도구 부재 시 명확한 에러).

## Global Constraints

- 공통 규칙(BINDING, Plans 07/08과 동일): 상태 변경은 Server Action만, 클라이언트 배럴 금지, 메시지 중첩 키·ko/en 패리티, audit 규약, conventional commits + `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- **저작권 정책(BINDING)**: MoMA 메타데이터는 CC0(공식 데이터셋) — 픽스처 파일 머리에 출처·attribution(MoMA 요청)·DOI `10.5281/zenodo.21061248` 주석. **이미지는 CC0에서 명시 제외** — `ImageURL`(moma.org CDN)을 저장·커밋·핫링크하지 않는다. 썸네일은 전부 자체 생성 플레이스홀더. 실파일도 전부 생성물(ffmpeg testsrc/컬러바) — 외부 저작물 반입 금지.
- 시드 데이터 정본(스카우트 검증 완료 — 변경 금지):

| 작품 | ObjectID | Accession | 작가 | 연도 | 부서 | 구조 |
|---|---|---|---|---|---|---|
| Tetris | 152403 | 928.2012 | Alexey Pajitnov | 1984 | Architecture & Design | work + 컴포넌트 3(expression: `1984 IBM PC`/`1988 NES`/`1988 Game Boy` — PDF FRBR 예시) |
| Manifestos 2 | 175258 | 1206.2013 | Charles Gaines | 2013 | Media and Performance | work + 채널 컴포넌트 4(AIP명 `Gaines--1206.2013.1.a.x3`~`x6` — PDF 실측 패턴) |
| 10 Days | 173989 | 875.2013 | Stuart Brisley | 1978 | Media and Performance | work 단독(16mm→video, 51:23) |
| SimCity 2000 | 152406 | 930.2012 | Will Wright | 1993 | Architecture & Design | work 단독 |
| The Sims | 162461 | 932.2012 | Will Wright | 2000 | Architecture & Design | work 단독 |
| Space Invaders | 169996 | 701.2013 | Tomohiro Nishikado | 1978 | Architecture & Design | work 단독 |
| Official Welcome | 143751 | 1.2011 | Andrea Fraser | 2001/2003 | Media and Performance | work 단독(video 30분) |
| Untitled (백남준) | 81152 | (dataset 행 참조) | Nam June Paik | 1993 | Painting & Sculpture(dataset 확인) | work + 컴포넌트 5(player piano/televisions×15/cameras×2/laser disc players×2/electric light — MoMA 보존 블로그 실측) |

- 기존 Lovers(81362, 331.1998)·Grosse Fatigue(175938, 1207.2013)는 유지하고 파일·AIP만 보강(GF AIP 명명 `Henrot--1207.2013.x1`~ 패턴, PDF 실측). 데모 부서 term이 없으면 'department' 택소노미에 용어 추가(Media and Performance / Architecture & Design / Painting & Sculpture — ko 병기).
- MoMA CSV 접근: Git LFS URL `https://media.githubusercontent.com/media/MuseumofModernArt/collection/main/Artworks.csv` (~70MB — **시더에서 다운로드 금지**; 추출은 1회성 스크립트, 결과 JSON만 커밋).
- 게이트(전 태스크): `pnpm test`+`pnpm typecheck`+`pnpm --filter web build` 그린. e2e는 Task 6(총 13본: 기존 12 + 썸네일 1).
- 마이그레이션 없음(스키마 변경 0). dev DB 재시드는 멱등 가드 갱신 후 `db:seed-demo` 재실행으로.

---

### Task 1: 백로그 청산 A — sync·관리 잔여 7건

**Files:**
- Modify: `packages/db/src/queries/sources.ts` (createSource/updateSource config 검증, countConflicts)
- Modify: `apps/worker/src/jobs/sync.ts` (밑줄 없는 env 폴백 제거)
- Modify: `apps/web/app/(app)/admin/sources/page.tsx` (COUNT 배지 + 헤더 i18n)
- Modify: `apps/web/components/admin/ConflictCard.tsx` + conflicts page (locale prop)
- Modify: `apps/web/lib/actions/admin-agents.ts` (빈 이름 가드) + `apps/web/lib/actions/admin-sources.ts` (InvalidSourceConfigError → { error: code } 매핑)
- Modify: `apps/web/components/admin/AddAgentForm.tsx`, `AddSourceForm.tsx` (신규 에러 코드 표시 라인 — 기존 useActionState 상태에 렌더 분기 추가)
- Modify: `packages/db/src/index.ts` (InvalidSourceConfigError·countConflicts export)
- Modify: `apps/web/messages/{ko,en}.json`
- Test: `packages/db/test/sources-crud.test.ts`, `agents-crud.test.ts` (기존에 추가)

**Interfaces:**
- Produces:
  - `validateSourceConfig(db, config: Record<string, unknown>): Promise<string | null>` (sources.ts 내부 export) — **존재하는 키만 검증한다(빈 config = 유효; 기존 sources-crud.test.ts의 `{}`·`{v:1}` 픽스처는 무수정 통과해야 함)**: `profileId` 또는 `profileVersion`이 있으면 둘 다 숫자이고 해당 버전 존재(`getProfileFieldSchema` 재사용 — 한쪽만 있으면 위반); `levelTermMachine`이 있으면 `findTermId(db, 'level_of_description', ...)` 해석 가능(try/catch — `../seed/seed-taxonomies.js`에서 import, 배럴에 없음); `targetParentId`가 있으면 라이브 노드 존재. 위반 시 'config-profile'/'config-level'/'config-parent' 반환, 정상 null. createSource/updateSource가 config 변경 시 호출해 throw `new InvalidSourceConfigError(code)` — 액션은 `{ error: code }` 매핑.
  - `countConflicts(db): Promise<number>` — `SELECT count(*) FROM node_sources WHERE sync_status='conflict'`; sources page 배지가 listConflicts 대신 사용.
  - sync.ts: `${prefix}KEY_IDENTITY`(밑줄 없음) 폴백 삭제 — 계약은 `${prefix}_KEY_IDENTITY`뿐(주석).
  - ConflictCard: `locale: Culture` prop 추가, `pickI18n(v, locale, 'ko')`; conflicts page가 `getLocale()`로 전달.
  - sources page 하드코드 영문 헤더(메인 테이블 'Base URL'/'Last run'/'Actions' + 실행 이력 테이블 'ID'/'Kind'/'Status'/'Stats'/'Started') → admin.sources 중첩 키로 이관(ko/en — 키 이름은 구현 재량, 패리티만 고정).
  - agentAdminAction create: name_ko/name_en 모두 공백 → `{ error: 'name-required' }` (+메시지 키).
  - 테스트: config 검증 3계열(무효 프로파일/레벨/부모) rejects, countConflicts 스모크, 빈 agent 이름은 액션 계층이라 db 테스트 불요 — rights-path in-use 가드 테스트 추가(rights.rights_holder_agent_id 참조 심고 softDeleteAgent → AgentInUseError — agents-crud.test.ts의 truncate 목록에 rights 계열 테이블 추가 필요).

- [ ] **Step 1: 실패 테스트**(config 검증·countConflicts·rights-path 가드) → **Step 2: 구현 + 그린** → **Step 3: 게이트 + 커밋**

```bash
git add -A && git commit -m "fix(db,web,worker): source config validation, conflict count badge and admin polish"
```

---

### Task 2: 백로그 청산 B — deploy 잔여 + 대시보드 works 시맨틱

**Files:**
- Modify: `docker/worker.Dockerfile` (per-package node_modules 복사 취약점 해소), `docker/web.Dockerfile` (pnpm fetch 중복 레이어 제거)
- Modify: `packages/db/src/queries/dashboard.ts` (getCollectionTotals works 카운트)
- Test: `packages/db/test/` 대시보드 기존 테스트 갱신(있으면), 컨테이너 재스모크

**Interfaces:**
- Produces:
  - worker.Dockerfile: **전체 트리 복사 방식으로 확정**(pnpm deploy는 pnpm 9 모노레포에서 미검증 리스크) — deps 스테이지의 install 후 런타임 스테이지에 루트 `node_modules`뿐 아니라 `apps/worker/node_modules`와 `packages/*/node_modules`(pnpm 워크스페이스 심링크)도 COPY --from으로 보존한다(현재는 컨텍스트에서 복사해 .dockerignore가 심링크를 날림). 완료 후 **컨테이너 재스모크 필수**(compose --profile app, 캐시 빌드 무효화 `--build`, health + worker 로그, 종료·정리 — dev 인프라 보존).
  - web.Dockerfile: `pnpm fetch` 라인 제거(소스 선복사 상황에서 무의미 — 주석).
  - `getCollectionTotals`: works 카운트를 `parent_id IS NULL AND kind = 'work' AND deleted_at IS NULL`로 교정. **대시보드 메시지 라벨도 함께 교정**(현재 ko '작품/기록', en 'Works/records' — 카운트 의미 변경에 맞춰 '작품'/'Works'로; apps/web/messages/{ko,en}.json을 Files에 추가). 기존 dashboard-queries.test.ts(works:1, kind='work' 픽스처)와 e2e test 1은 무영향(검증 완료) — 확인만.

- [ ] **Step 1: dashboard 수정 + 테스트** → **Step 2: Dockerfile 교정 + 재스모크** → **Step 3: 게이트 + 커밋**

```bash
git add -A && git commit -m "fix(deploy,db): resilient worker image, docker layer cleanup and works count semantics"
```

---

### Task 3: MoMA CC0 픽스처 + 시더 확장 (메타데이터·계층)

**Files:**
- Create: `packages/db/src/seed/moma-fixtures.json` (10작품 — 기존 2 + 신규 8; CC0 출처 주석은 JSON이 주석 불가하므로 `_license` 최상위 키로)
- Create: `packages/db/scripts/extract-moma-fixtures.ts` (재생성용 1회성 스크립트 — LFS URL에서 스트리밍 추출, ObjectID 화이트리스트)
- Modify: `packages/db/src/seed/seed-demo.ts` (신규 8작품 + 컴포넌트 계층 + 작가/이벤트/부서)
- Modify: `packages/db/package.json` (script `demo:extract-moma` — 실행은 선택)
- Test: `packages/db/test/seed-demo.test.ts` (**기존 파일 — Modify**: 기존 AIP 3건·fixity 3건 고정 카운트 단언을 확장 후 값으로 갱신, 신규 구조 단언 추가)

**Interfaces:**
- Produces:
  - `moma-fixtures.json` 형태: `{ _license: { source: 'github.com/MuseumofModernArt/collection', license: 'CC0 1.0', note: 'Metadata only — images are NOT part of the CC0 dataset and are not used.', attribution: 'The Museum of Modern Art (MoMA) collection data', doi: '10.5281/zenodo.21061248' }, works: [{ objectId, title, artist, artistBio?, date, medium, accessionNumber, department, url, classification }] }` — Global Constraints 표의 10작품. **ImageURL 컬럼은 추출하지 않는다.**
  - `extract-moma-fixtures.ts`: node로 LFS CSV 스트리밍 → ObjectID 화이트리스트 매칭 → 위 형태로 저장. DOI는 계획의 리터럴을 믿지 말고 **데이터셋 README에서 추출 시점에 그대로 복사**해 _license에 기록. 네트워크 필수라 CI/시드에서 호출하지 않음(주석). csv 파싱은 단순 라인 파서 금지 — 필드 내 쉼표/따옴표 처리 필요: 의존성 추가 대신 ~40줄 RFC4180 미니 파서를 스크립트 안에(테스트 불요, 1회성 도구).
  - seed-demo 확장: 픽스처 로드는 **fs.readFileSync + JSON.parse + import.meta.url 경로 해석**(seed-demo.ts에 이미 있는 패턴 — createRequire 선례 없음) → 부서 term ensure(3종, ko 병기: '미디어 퍼포먼스'/'건축 디자인'/'회화 조각') → 작가 ensureAgent 7명(pajitnov/gaines/brisley/wright/nishikado/fraser/paik — kind person, name {en, ko 병기(백남준만 ko '백남준')}) → 각 작품 createNode(kind 'work', level artwork_record, 미디어아트 프로파일, title {en: 제목, ko: 통용 한국어 표기(테트리스/심시티 2000/더 심즈/스페이스 인베이더/오피셜 웰컴/그로스 파티그 기존 유지/10일/매니페스토 2/무제(백남준))}, identifier=objectId, accessionNumber, descriptive {medium}, departmentTermId) + setCreationEvent(작가, 연도).
  - 컴포넌트 계층: Tetris 자식 3(kind 'component', level `component`, title ko/en `1984 IBM PC`/`1988 NES`/`1988 Game Boy`, componentNumber `928.2012.1`~`.3`); Manifestos 2 자식 4(`채널 1`~`4`/`Channel 1`~`4`, componentNumber `1206.2013.1.a`~`.d`); 백남준 Untitled 자식 5(자동 피아노/텔레비전 ×15/카메라 ×2/레이저디스크 플레이어 ×2/전구 조명 — en 병기, componentNumber 없음).
  - 멱등 재설계(**이 태스크에서 완결 — 부분 시드된 dev DB 재실행이 깨지지 않아야 함**): ① 최상단 early-return 가드 삭제 ② 프로파일/레벨/부서/작가 ensure는 무조건 실행(이미 멱등) — **mediaArt 프로파일 id·레벨 term id 해석을 기존 fresh-only 분기(노드 미존재 시에만 실행) 바깥으로 이동** ③ 기존 Lovers/GF/fonds 생성 블록은 기존 가드(`accession '1250.2011'` 존재 시 skip) 유지 ④ 신규 8작품 블록은 자체 가드(`SELECT id FROM nodes WHERE identifier = '81152'` 존재 시 skip) ⑤ **모든 mkAip 호출(기존 3건 포함) 직전에 `SELECT id FROM aips WHERE uuid=... AND deleted_at IS NULL` 존재 시 skip**(createAip는 라이브 중복에 AipAlreadyExistsError를 던지므로 이 가드 없이는 재시드가 크래시). `buildNodeSearchDocs`를 신규 노드 전부에 호출.
  - **기존 Lovers/GF 정체성 주의**: 픽스처의 81362/175938 행은 출처 표기용(provenance-only) — createNode 루프에서 **제외**한다(시드된 Lovers는 identifier '100001'/accession '1250.2011', GF는 '100002'/'232.2014'로 이미 존재 — 픽스처 값으로 재생성하면 중복 노드가 생긴다).
  - seed-demo.test.ts: binder_test에서 seedDemo 2회 실행 → 노드 수 동일(멱등), Tetris 자식 3, Manifestos 자식 4, Paik 자식 5, 부서 term 존재, 검색 '테트리스' 히트.

- [ ] **Step 1: 추출 스크립트 작성 + 1회 실행으로 픽스처 생성**(실데이터 검증: 10행, accession 일치 — 불일치 시 STOP 보고) → **Step 2: 실패 테스트(seed-demo.test.ts)** → **Step 3: 시더 구현 + 그린** → **Step 4: dev DB 재시드 + 게이트 + 커밋**

```bash
git add -A && git commit -m "feat(db): moma cc0 fixture-driven demo collection with component hierarchies"
```

---

### Task 4: 생성 미디어 + AIP·파일 시더 확장

**Files:**
- Create: `scripts/generate-demo-media.mjs` (레포 루트 — ffmpeg/magick 호출)
- Modify: `packages/db/src/seed/seed-demo.ts` (신규 AIP/파일 행 + tar 스텁 확장)
- Modify: `.gitignore` (`docker/demo-aips/` 기존 유지 확인)
- Test: `packages/db/test/seed-demo.test.ts` (AIP 검증 추가)

**Interfaces:**
- Produces:
  - `generate-demo-media.mjs`: `node scripts/generate-demo-media.mjs` — `docker/demo-aips/media/` 아래에 작품별 짧은(10초) 실재생 영상 생성 후 각 AIP uuid의 tar로 포장(`tar -cf <uuid>.tar -C <워크dir> objects/`). ffmpeg 부재 시 명확한 에러+exit 1. drawtext는 이 머신의 ffmpeg 8(libfreetype+fontconfig)에서 fontfile 지정 없이 동작(검증 완료) — `drawtext=text='<제목>':fontcolor=white:fontsize=48` 형태. 생성 스펙(각각 `-f lavfi -i testsrc2=duration=10:size=<W>x<H>:rate=<fps>` + drawtext + 지정 코덱):

| AIP(영상 8종 — 게임 6종은 아래 별도) | uuid(고정, 시더와 일치) | 파일 | 코덱/스펙 |
|---|---|---|---|
| `Gaines--1206.2013.1.a.x3` | `aaaa0001-...`(시더에서 고정 배열로 선언 — 구현 시 uuid v4 형식 리터럴 **14개** 확정) | ch1.mov | **prores_ks -profile:v 0(proxy) 1280x720**(1080p ProRes는 tar가 수백 MB — 금지) |
| `Gaines--1206.2013.1.a.x4`~`x6` | 〃 | ch2~4.mov | 〃 |
| `Henrot--232.2014.x1` (DB의 GF accession 기준 — 픽스처의 1207.2013 아님) | 〃 | grosse_fatigue_excerpt.mp4 | h264 1920x1080 |
| `Brisley--875.2013.x1` | 〃 | ten_days_reel1.mov | mjpeg 720x576(SD — 16mm 전사 모사) |
| `Fraser--1.2011.x1` | 〃 | official_welcome.mp4 | h264 720x480 |
| `Paik--81152.x1` | 〃 | untitled_laserdisc_a.mov | mjpeg 640x480 |

  - 비디오게임 AIP는 **디스크이미지 모사**(각 1~4MB 랜덤 바이너리 `.img` + `README.txt` tar 포장; 파일 행 formatName 'Disk Image', mimeType 'application/octet-stream'): **Tetris는 expression 컴포넌트별 1개씩 3 AIP**(FRBR 시연의 핵심 — 각 컴포넌트 노드에 attach), SimCity 2000/The Sims/Space Invaders는 각 work에 1 AIP. **uuid 리터럴 총 14개**(영상 8 + 게임 6)를 전부 `DEMO_AIP_UUIDS` 배열에 추가(스텁 tar 자동 생성이 이 배열을 순회 — 검증된 메커니즘). `mkAip`에 `filename` 파라미터 추가(현재 uuid.slice(0,8) 하드코드 — PDF 명명 패턴을 쓰려면 필수).
  - 시더 확장(Phase 2에): 위 표의 AIP를 `mkAip` 패턴으로 생성 — filename은 PDF 명명 패턴, files 행은 생성 스펙과 **일치하는 진실값**(videoCodecs/resolution/bitDepth 8/sampleRate 48000/chromaSubsampling '4:2:0'/colorSpace 'bt709' 등 — 생성 커맨드가 실제 만드는 값), sizeBytes는 tar 실측이 이상적이나 시더는 파일 부재 시에도 돌아야 하므로 **고정 근사값 + 주석**(스텁 tar 폴백 유지: media 스크립트를 안 돌려도 시더는 기존처럼 1KB 스텁 생성). DEMO_AIP_UUIDS 배열 확장.
  - video_characteristics 리포트가 신규 영상 파일 행들로 풍성해짐(6+행) — 테스트로 고정.

- [ ] **Step 1: 스크립트 작성 + 로컬 실행 검증**(생성물 재생 가능 — ffprobe로 코덱 확인 1회) → **Step 2: 시더 확장 + 테스트(멱등 + video_characteristics 행 수)** → **Step 3: dev DB 재시드(가드 때문에 재시드 안 되면 신규분만 들어가는지 확인 — 가드가 Task 3에서 노드 기준이 됐으므로 AIP 삽입 로직에 별도 exists 가드 필요: aips uuid 조회 후 skip) + 게이트 + 커밋**

```bash
git add -A && git commit -m "feat(db): generated demo media with pdf-faithful aip naming and rich file facets"
```

---

### Task 5: 썸네일 — 생성 + UI 렌더링

**Files:**
- Create: `scripts/generate-demo-thumbs.mjs` (magick 호출 — `apps/web/public/demo-thumbs/<objectId>.png` 12장: 작품 10 + 기존 2)
- Create: `apps/web/public/demo-thumbs/*.png` (커밋 — 작고 자체 생성물, **총 10장**: 픽스처 10작품 = 기존 2 + 신규 8)
- Modify: `packages/db/src/seed/seed-demo.ts` (thumbnailUrl = `/demo-thumbs/<objectId>.png` — 신규는 createNode 입력; 기존 Lovers/GF는 픽스처 objectId(81362/175938) 파일명을 쓰되 UPDATE 조건은 **DB 정체성**으로: `UPDATE nodes SET thumbnail_url='/demo-thumbs/81362.png' WHERE accession_number='1250.2011' AND deleted_at IS NULL` / GF는 '232.2014'에 175938.png — 멱등)
- Modify: `packages/db/src/queries/nodes.ts`?? — 불필요(thumbnailUrl은 CreateNodeInput에 없음 — **확인 후**: 없으면 CreateNodeInput에 `thumbnailUrl?: string` 추가(스키마 컬럼 기존재, values에 관통) — 1줄)
- Modify: `apps/web/components/records/RecordCard.tsx` (img 렌더), `apps/web/app/(app)/records/[slug]/page.tsx` (헤더 img)
- Modify: `docker/web.Dockerfile` — **CRITICAL: public/ COPY 복원** `COPY --from=build /repo/apps/web/public ./apps/web/public` (Plan 08에서 디렉토리 부재로 제거했던 라인 — 이제 public/이 생기므로 없으면 컨테이너에서 썸네일 전부 404; 낡은 주석도 삭제)
- Test: e2e는 Task 6

**Interfaces:**
- Produces:
  - generate-demo-thumbs.mjs: 작품별 결정적 팔레트(objectId 해시 → 두 색 그라디언트) + 제목 타이포 카드 480x640 PNG. `magick -size 480x640 gradient:'#<c1>-#<c2>' -gravity center -pointsize 36 -fill white -annotate 0 '<title>' out.png` 형태. **10장** 생성해 커밋(각 ~수십 KB).
  - RecordCard: 카드 상단에 `{item.thumbnailUrl && <img src={item.thumbnailUrl} alt="" className="mb-2 aspect-[3/4] w-full rounded object-cover" loading="lazy" data-testid="record-thumb" />}` (썸네일 없으면 기존 레이아웃 그대로 — 조건부).
  - 상세 페이지 헤더: 제목 옆/위에 `{d.node.thumbnailUrl && <img ... className="h-40 rounded object-cover" />}`.
  - 시더: 신규 작품 createNode에 thumbnailUrl 전달, Lovers/GF는 `UPDATE nodes SET thumbnail_url=...`(멱등).

- [ ] **Step 1: 스크립트 + PNG 12장 생성·커밋** → **Step 2: CreateNodeInput 확인·확장 + 시더 반영 + UI 렌더** → **Step 3: dev 재시드 + 수동 확인(브라우즈에 썸네일) + 게이트 + 커밋**

```bash
git add -A && git commit -m "feat(web,db): generated placeholder thumbnails rendered in browse and detail"
```

---

### Task 6: E2E + README + 최종 검증

**Files:**
- Modify: `apps/web/e2e/core-flows.spec.ts` (썸네일 1본 — 총 13)
- Modify: `README.md` (데모 데이터 섹션)
- Test: 전체 게이트

**Interfaces:**
- E2E 추가:

```ts
test('browse shows generated thumbnails and enriched collection', async ({ page }) => {
  await login(page);
  await page.goto('/records?kind=work');
  await expect(page.getByTestId('record-thumb').first()).toBeVisible();
  // 확충 검증: 테트리스 검색 → 상세 → 컴포넌트(1984 IBM PC) 확인
  await page.fill('input[name=q]', '테트리스');
  await page.press('input[name=q]', 'Enter');
  await page.getByTestId('record-card').first().click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/테트리스|Tetris/);
  await expect(page.getByText('1984 IBM PC')).toBeVisible();   // context browser 또는 하위 구조 표기 — 실렌더 위치 확인 후 셀렉터 조정
});
```

- README `### 데모 데이터` 섹션: 구성(**작품 10·컴포넌트 12(테트리스 3+매니페스토 4+백남준 5)·AIP 14+·생성 미디어**), **출처 명시**: "작품 메타데이터는 MoMA 공식 CC0 컬렉션 데이터셋(github.com/MuseumofModernArt/collection, DOI 10.5281/zenodo.21061248)에서 추출 — MoMA에 감사를 표합니다. 이미지·미디어 파일은 CC0 범위 밖이므로 **전부 자체 생성 플레이스홀더**이며 실제 작품의 이미지·콘텐츠가 아닙니다." + 재생성 방법(`node scripts/generate-demo-media.mjs`, `generate-demo-thumbs.mjs`, `pnpm --filter @binder/db demo:extract-moma`).
- 최종 게이트: `pnpm test && pnpm typecheck && pnpm --filter web build && pnpm e2e`(13/13) + **컨테이너 재스모크**(`--profile app up -d --build` → health 200 + `curl -sI localhost:3000/demo-thumbs/<objectId>.png` = 200 — public COPY 복원 검증 — → 정리, dev 인프라 보존).

- [ ] **Step 1: e2e 추가 + 13/13** → **Step 2: README** → **Step 3: 최종 게이트 + 커밋**

```bash
git add -A && git commit -m "feat(web): demo collection e2e coverage and provenance docs"
```

---

## Self-Review

1. **Coverage**: 사용자 요구(PDF의 Tetris 3종=expression 컴포넌트·Manifestos 2·10 Days + 백남준 1종) 전부 Task 3; 썸네일 체감=Task 5; 실파일 체감=Task 4(영상+디스크이미지, PDF AIP 명명); 백로그=Tasks 1–2(sync 7건+deploy 2건+works 시맨틱). 저작권 정책 전 태스크 관통(CC0 메타만, 이미지·미디어 전부 생성물).
2. **Placeholder scan**: Task 4의 uuid 8개("구현 시 리터럴 확정")와 Task 6의 컴포넌트 표기 셀렉터("실렌더 위치 확인")는 구현 시점 확정 항목으로 정본 위임을 명시 — 나머지는 실코드/실값. Task 5의 CreateNodeInput thumbnailUrl은 "확인 후 1줄 추가"로 조건 명시.
3. **Type consistency**: moma-fixtures.json 형태(T3)↔시더 로드; DEMO_AIP_UUIDS·mkAip(기존)↔T4; thumbnailUrl 경로 `/demo-thumbs/<objectId>.png`(T5)↔시더·UI; record-thumb testid(T5)↔T6 e2e.
4. **순서**: T1·T2 독립 → T3 → T4(T3의 노드) → T5(T3) → T6(T4·T5). 파일럿(Omeka S 실수확)은 이 계획 범위 밖 — Plan 09 완료 후 별도 진행.
