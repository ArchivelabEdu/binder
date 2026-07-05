# Plan 11 — P3.1b 그래프 UI + 파생 시드 + 시연

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plan 10이 만든 그래프 코어(Record/Instantiation·entity_relations)를 UI로 노출한다 — 통합 그래프 뷰, Instantiation 패널, 관계 편집, 관계유형·SKOS 관리 — 그리고 파생 계보 시드로 스펙 §8 P3.1 시연 기준을 충족한다.

**Architecture:** 기존 ContextBrowser 파이프라인(서버 계산 레이아웃 → ReactFlow 클라이언트)을 **확장**해 Instantiation 노드·파생 엣지·신 관계를 통합 렌더. RelationList는 구 relations에서 entity_relations로 전환. 관리 UI는 taxonomies admin 패턴 복제. 변이는 전부 Server Actions(CSRF 불변식 유지), TTL 다운로드만 GET API 1본.

**Tech Stack:** Next.js 15 (기존), @xyflow/react v12 (기존), Drizzle (기존). 신규 의존성 없음.

**스펙:** `/Users/daejinan/claude/moma-binder/binder/docs/superpowers/specs/2026-07-06-phase3-knowledge-graph-hub-design.md`(작업 리포 밖 — 형제 binder 리포) §6(P3.1 UI 4종), §8 P3.1 행(시연: Tetris 하위 Record·백남준 물리 Instantiation·파생 계보가 그래프 뷰에 표시 + TTL 발행). 선행: Plan 10 완료(HEAD e982029, db 232 tests).

**실측 slug(2026-07-06 dev DB — slugify는 ko 타이틀 우선, 추정 금지):** Lovers=`lovers`(명시 시드), GF=`그로스-파티그`, Tetris=`테트리스`, 백남준=`무제-백남준`, Official Welcome=`오피셜-웰컴`. 브라우저/Playwright 경로엔 한글 그대로, curl엔 `encodeURIComponent` 필요.

**dev DB 실측 instantiation 수(2026-07-06, census와 다름 — 잔존 데이터):** 백남준 6(equipment 5+AIP 1), GF 4(파생 시드 후 6), Lovers 6, OW 1(시드 후 3), 총 28.

## Global Constraints

- 변이는 **Server Actions만**(신규 상태변경 API 라우트 금지). 예외 없음 — TTL 다운로드는 GET 전용 `withAuth`.
- 클라이언트 컴포넌트: `@binder/core` barrel 금지(`@binder/core/i18n`만 허용), `@binder/db`는 type-only import.
- 모든 import '.js' ESM(웹 앱 내부 상대 import는 Next 관행 그대로 확장자 없이 — 기존 파일 스타일 준수).
- 모든 페이지 `requireUser()`, 변이 액션은 권한 검사(`records:write` / `admin:manage`) + audit(DB 계층이 수행).
- 한글 slug revalidatePath/redirect는 `encodeURIComponent` (node-edit.ts:142-145 패턴).
- i18n: ko/en 병기, 중첩 키(dot 금지). 기존 네임스페이스: admin/create/dashboard/detail/edit/login/records/reports/settings/shell.
- DRMC 디자인 토큰 유지: entity 색(work #161514, record #4e735b, supporting #af164f, aip/instantiation 계열 #28cfff, agent #c97bc9, file #ff8800), 링크 #0088cc.
- 게이트: `pnpm -r typecheck && pnpm --filter @binder/db test` + e2e는 **`cd apps/web && pnpm e2e`**(스크립트명 `e2e`, `test:e2e` 아님).
- dev DB 파괴적 작업 금지(파생 시드 스크립트는 Task 7에서 컨트롤러 승인 절차로만 dev 적용).
- 커밋 트레일러: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- 작업 디렉터리: `/Users/daejinan/claude/moma-binder/binder-next`

---

### Task 1: 파생 계보 시드 (seedDerivedDemo)

**Files:**
- Create: `packages/db/src/seed/seed-derived.ts`
- Create: `packages/db/scripts/seed-derived.ts` (CLI 래퍼)
- Modify: `packages/db/package.json` (script `"db:seed-derived": "tsx scripts/seed-derived.ts"`)
- Modify: `packages/db/src/index.ts` (seedDerivedDemo export)
- Test: `packages/db/test/seed-derived.test.ts`

**Interfaces:**
- Consumes: `listInstantiationsForRecord`, `createInstantiation`, `getDerivationChain`(Plan 10, barrel export 확인됨 index.ts:36-42)
- Produces: `seedDerivedDemo(db): Promise<{ created: number; skipped: string[] }>` — **마이그레이션 이후 전용** 시드:
  - 가드 1(전제): instantiations 테이블에 live 행이 없으면 아무것도 하지 않고 `{ created: 0, skipped: ['not-migrated'] }` 반환(fresh env에서 migrate-graph 전에 실행돼도 무해).
  - 가드 2(멱등): 해당 레코드에 role=access_copy인 live instantiation이 이미 있으면 그 레코드는 skip.
  - 대상: **Grosse Fatigue(accession 232.2014)와 Official Welcome(accession 1.2011)** — 각각 AIP 유래 preservation_master instantiation을 찾아 `access_copy`(derivedFrom=master) → `thumbnail`(derivedFrom=access_copy) 2단 파생을 digital carrier로 생성(description: `{ ko: '데모 파생본', en: 'Demo derivative' }`).
  - 레코드/마스터를 못 찾으면 해당 항목 skipped에 사유 기록(throw 금지 — 시드는 관용적).
- 실행 순서 문서(README, Task 7): fresh env = `db:seed` → `db:seed-demo` → `migrate-graph` → `db:seed-derived`.

- [ ] **Step 1: 실패 테스트** — `packages/db/test/seed-derived.test.ts`

```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { seedTaxonomies } from '../src/seed/seed-taxonomies.js';
import { seedVocabularies } from '../src/seed/seed-vocabularies.js';
import { seedRelationTypes } from '../src/seed/seed-relation-types.js';
import { seedDemo } from '../src/seed/seed-demo.js';
import { seedDerivedDemo } from '../src/seed/seed-derived.js';
import { runGraphMigration } from '../src/migrate-graph/run.js';
import { getDerivationChain, listInstantiationsForRecord } from '../src/queries/instantiations.js';
import { sql } from 'drizzle-orm';

const { db, sql: client } = testDb();
beforeAll(async () => {
  await truncate(client, 'instantiations', 'entity_relations', 'relation_types', 'vocab_mappings', 'vocabularies',
    'events', 'audit_log', 'aip_copies', 'files', 'aips', 'node_terms', 'notes', 'relations', 'node_rights',
    'rights_acts', 'rights', 'nodes', 'agents', 'search_docs', 'terms', 'taxonomies',
    'collection_profile_versions', 'collection_profiles', 'storage_backends');
  await seedTaxonomies(db);
  await seedVocabularies(db);
  await seedRelationTypes(db);
  await seedDemo(db);
});
afterAll(() => client.end());

describe('seedDerivedDemo', () => {
  it('does nothing before migration', async () => {
    const r = await seedDerivedDemo(db);
    expect(r.created).toBe(0);
    expect(r.skipped).toContain('not-migrated');
  });
  it('creates 2-step derivation chains for GF and Official Welcome after migration, idempotently', async () => {
    await runGraphMigration(db);
    const first = await seedDerivedDemo(db);
    expect(first.created).toBe(4); // (access_copy + thumbnail) × 2 works
    const second = await seedDerivedDemo(db);
    expect(second.created).toBe(0); // 멱등
    // GF: master → access → thumbnail 체인 검증
    const [gf] = (await db.execute(sql`SELECT id FROM nodes WHERE accession_number = '232.2014' AND deleted_at IS NULL`)) as unknown as Array<{ id: number }>;
    const insts = await listInstantiationsForRecord(db, gf.id);
    const thumb = insts.find((i) => i.derivedFromInstantiationId != null
      && insts.some((j) => j.id === i.derivedFromInstantiationId && j.derivedFromInstantiationId != null));
    expect(thumb).toBeDefined();
    const chain = await getDerivationChain(db, thumb!.id);
    expect(chain).toHaveLength(3); // thumbnail → access → master
  });
});
```

- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- seed-derived` / Expected: FAIL
- [ ] **Step 3: 구현** — Interfaces 명세대로. role 텀 해석은 `findTermId(db, 'instantiation_role', ...)`. master 탐색: 해당 노드의 live instantiations 중 role=preservation_master이고 aipId가 있는 첫 행.
- [ ] **Step 4: 통과 확인** — Run: `pnpm --filter @binder/db test -- seed-derived` && 전체 `pnpm --filter @binder/db test` / Expected: PASS (기존 graph-migration.integration은 seedDerivedDemo를 호출하지 않으므로 census 27 불변)
- [ ] **Step 5: 커밋** — `git commit -m "feat(db): demo derivation-chain seed (post-migration)"`

---

### Task 2: getEntityGraph 쿼리 + getNodeDetail 확장

**Files:**
- Modify: `packages/db/src/queries/detail.ts` (getContextGraph 확장 → getEntityGraph + getNodeDetail에 instantiations·entityRelations 필드 추가)
- Create: `packages/db/src/queries/entity-labels.ts` (엔터티 라벨 해석 헬퍼)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/entity-graph.test.ts`

**Interfaces:**
- Consumes: 기존 `getContextGraph`(detail.ts:139-206, ContextGraph 타입 detail.ts:130-134, 레이아웃 상수 COL_W=280/ROW_H=96), `listInstantiationsForRecord`, `listRelationsForEntity`(RelationView: { id, direction, relationTypeMachine, label, otherType, otherId, startDate, endDate, origin })
- Produces:
  - `resolveEntityLabels(db, refs: Array<{ type: EntityType; id: number }>): Promise<Map<string, { label: I18nText; slug?: string }>>` — key `${type}:${id}`; node→title+slug(live), agent→name, event→label??dateDisplay, term→name, instantiation→role 텀 name. (entity-labels.ts)
  - **ContextGraph 타입 확장**(하위 호환 — 기존 필드 유지):
    - nodes[].kind: `'record' | 'aip' | 'supporting' | 'instantiation' | 'agent'` (기존 3종 + 2종)
    - nodes[].meta에 `role?: string`(instantiation role 텀 ko/en 중 라벨은 label에, machine은 meta.role), `carrier?: 'physical' | 'digital'`
    - edges[].kind: `'tree' | 'aip' | 'relation' | 'instantiation' | 'derived'` (+2종)
  - `getEntityGraph(db, rootNodeId): Promise<ContextGraph>` — 기존 getContextGraph 로직에 추가:
    1. **Instantiation 노드**: 중심 노드와 그 하위 record들의 live instantiations를 `instantiation` kind 노드로(라벨 = role 텀 이름, meta.carrier). 기존 'aip' 노드 로직은 **instantiation이 aipId를 가진 경우 instantiation 노드로 대체**(같은 자리 — 부모 record의 오른쪽 컬럼, 기존 AIP 충돌 회피 배치 재사용). aipId 없는(물리) instantiation도 동일 컬럼.
    2. **derived 엣지**: derivedFromInstantiationId → `{ kind: 'derived', source: 파생, target: 원본 }` (파생→원본 방향, 스펙 §2.2).
    3. **instantiation 엣지**: record → instantiation `{ kind: 'instantiation' }`.
    4. **신 관계**: 구 relations 쿼리(기존 'relation' 엣지+supporting 노드 로직)를 **entity_relations 기반으로 교체** — 중심 노드의 outgoing/incoming 중 상대가 node인 것은 기존처럼 노드+relation 엣지(라벨 = 방향별 relation_types 라벨), 상대가 agent인 것은 `agent` kind 노드. **agent 노드 배치 확정**: supporting과 동일 컬럼(모든 instantiation·파생 노드 배치를 마친 후 계산한 maxX + COL_W), 동일 컬럼 y 충돌 회피 루프 재사용. **has_event/performed_by 관계는 그래프와 entityRelations 둘 다에서 제외**(`relationTypeMachine ∈ {has_event, performed_by}` 필터 — 창작자는 헤더에 이미 표시, 노이즈 방지).
  - `getContextGraph`는 `getEntityGraph`의 별칭으로 유지(기존 호출부 무수정 컴파일).
  - `getNodeDetail` 반환에 추가: `instantiations: Array<InstantiationRow & { roleName: I18nText; derivedFromRole?: I18nText; aipFilename?: string }>`, `entityRelations: { outgoing: Array<RelationView & { otherLabel: I18nText; otherSlug?: string }>; incoming: Array<...동일> }` — otherType이 node|agent인 행만 포함(event 등 제외). **라벨 fallback**: resolveEntityLabels Map에 키가 없으면(삭제된 상대 등) `{ en: '{otherType}#{otherId}' }` 사용 — `.get()!` 크래시 금지. 기존 `relations` 필드(구 모델)는 **이 태스크에서 제거하지 않는다** — `// deprecated: Task 3에서 소비처 전환과 함께 제거` 주석만 달아 유지(웹 typecheck 그린 유지).

- [ ] **Step 1: 실패 테스트** — `packages/db/test/entity-graph.test.ts` — 셋업: Task 1 테스트와 동일 패턴(seed 4종+seedDemo+runGraphMigration+seedDerivedDemo). 케이스: (a) GF 노드의 getEntityGraph에 instantiation 노드 ≥3(master/access/thumbnail) + derived 엣지 2 + instantiation 엣지 ≥3, (b) 백남준(211.1993) 그래프에 equipment instantiation 5 + aip 유래 1, (c) Lovers 그래프에 relation 엣지(requires→Pioneer supporting 노드, 라벨은 labelForward), (d) getNodeDetail(GF)의 instantiations에 roleName 존재 + entityRelations 구조, (e) 모든 노드 좌표가 유한수이고 겹침 없는 y(같은 x 컬럼 내 y 중복 없음).
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- entity-graph` / Expected: FAIL
- [ ] **Step 3: 구현** — Interfaces 명세대로. 레이아웃: instantiation 컬럼은 기존 AIP 컬럼 위치(부모 x+COL_W), 파생은 +COL_W 추가 열(체인 깊이만큼). 기존 detail.ts:173-175 충돌 회피 반복 재사용.
- [ ] **Step 4: 통과 확인** — Run: `pnpm --filter @binder/db test -- entity-graph` && 전체 `pnpm --filter @binder/db test` && `pnpm -r typecheck` / Expected: 전부 PASS (relations 필드를 유지하므로 웹도 그린)
- [ ] **Step 5: 커밋** — `git commit -m "feat(db): entity graph query with instantiation/derived edges"`

---

### Task 3: 상세 페이지 — Instantiation 패널 + RelationList 전환

**Files:**
- Create: `apps/web/components/records/InstantiationPanel.tsx`
- Modify: `apps/web/components/records/RelationList.tsx` (entityRelations 기반 재작성)
- Modify: `apps/web/app/(app)/records/[slug]/page.tsx` (패널 배치 + props 갱신)
- Modify: `apps/web/messages/ko.json`, `apps/web/messages/en.json` (detail 네임스페이스 확장)
- Test: 기존 web typecheck + `pnpm --filter @binder/db test`(불변) — UI 검증은 Task 7 e2e

**Interfaces:**
- Consumes: Task 2의 `NodeDetail.instantiations`/`NodeDetail.entityRelations`
- Produces:
  - `InstantiationPanel({ instantiations, locale, t })` — 서버 컴포넌트. 카드(기존 AipList 카드 스타일 — border+rounded+heading): 각 instantiation 행 = role 뱃지(pill, carrier가 physical이면 배경 #4e735b 계열/digital이면 #28cfff 계열 연한 톤 + 진한 텍스트), carrier 라벨, AIP filename(있으면), 파생 관계는 `↳ {derivedFromRole}에서 파생` 들여쓰기 표기. `data-testid="instantiation-row"`.
  - RelationList 재작성: props `{ outgoing, incoming, locale, t }` — **node·agent 상대만 렌더**(Task 2가 이미 필터하지만 방어적으로 동일 규칙). 방향 그룹별로 관계유형 라벨(pickI18n) + 상대 라벨. 상대가 node면 `/records/{otherSlug}` Link(#0088cc), agent면 EntityDot(agent 색)+텍스트. `data-testid="relation-row"` **신설**(현행 RelationList에는 testid 없음). 기존 e2e 13번(relation hop)의 실제 호환 조건은 testid가 아니라 **node 상대의 타이틀 텍스트가 `<Link>`로 렌더**되는 것(`getByRole('link', /Pioneer|파이오니어/)`로 클릭) — 반드시 유지.
  - 이 태스크에서 `NodeDetail.relations`(구 모델 deprecated 필드)와 detail.ts의 구 relations 쿼리를 **제거**(소비처 전환 완료 시점 — RelationList.tsx:7과 page.tsx:72 두 곳이 전부임을 grep으로 재확인).
  - 페이지 배치: 좌 열 ContextBrowser → **InstantiationPanel** → AipList → RelationList (스카우트 §4 확인 구조에 삽입).
  - i18n `detail` 네임스페이스 추가 키(중첩): `instantiations: { title, physical, digital, derivedFrom }`, `relationsNew: { outgoing, incoming }` 등 — 기존 키 재사용 우선, dot 금지.

- [ ] **Step 1: 구현** — Interfaces 명세대로. pickI18n은 서버 컴포넌트에서 `@binder/core`(서버는 barrel 허용 — 기존 page.tsx 스타일 확인 후 동일하게).
- [ ] **Step 2: 게이트** — Run: `pnpm -r typecheck && pnpm --filter web test` / Expected: PASS (relations 필드 제거로 인한 컴파일 에러가 여기서 전부 해소돼야 함 — 다른 소비처가 있으면 grep으로 찾아 함께 수리)
- [ ] **Step 3: 수동 스모크** — Run: `cd apps/web && pnpm dev -p 3200 &` 후 `curl -s localhost:3200/api/v1/health` 확인, 로그인 후 GF 상세 SSR HTML에 instantiation-row 존재 grep — **경로는 실측 slug**: `curl -s "localhost:3200/records/$(python3 -c "import urllib.parse; print(urllib.parse.quote('그로스-파티그'))")" -H "cookie: ..."` (또는 dev 서버 없이 next build로 대체 — 구현자가 빠른 쪽 선택, 서버 기동 시 종료 필수)
- [ ] **Step 4: 커밋** — `git commit -m "feat(web): instantiation panel + entity-relations RelationList"`

---

### Task 4: 통합 그래프 뷰 (ContextBrowser 확장)

**Files:**
- Modify: `apps/web/components/records/graph-theme.ts` (NODE_STYLE/EDGE_STYLE 확장)
- Modify: `apps/web/components/records/ContextBrowser.tsx` (신규 kind 렌더·클릭 규칙)
- Modify: `apps/web/app/(app)/records/[slug]/page.tsx` (getEntityGraph 호출로 교체 — 별칭이면 무수정)
- Test: web typecheck — 시각 검증은 Task 7

**Interfaces:**
- Consumes: Task 2 ContextGraph 확장 타입
- Produces:
  - `NODE_STYLE` 추가: `instantiation`(배경 #e0f9ff, 테두리 #28cfff 1.5px, 둥근 모서리 — aip 스타일과 구분되게 테두리 강조), `agent`(배경 #f7ecf7, 테두리 #c97bc9). 기존 record/aip/supporting 불변.
  - `EDGE_STYLE` 추가: `instantiation`(실선 #28cfff), `derived`(점선 "4 4" #ff8800 + 화살표 marker — ReactFlow `markerEnd: { type: MarkerType.ArrowClosed }`, 방향 파생→원본).
  - **e2e 식별자 계약**: ReactFlow 노드에 `className: 'rf-node-{kind}'`, 엣지에 `className: 'rf-edge-{kind}'`를 부여(ContextBrowser의 nodes/edges useMemo 매핑에서) — Task 7 e2e가 `.rf-node-instantiation`, `.rf-edge-derived` 셀렉터로 카운트한다.
  - 클릭 규칙: record/supporting → `/records/{slug}`(기존), instantiation/agent → no-op(커서 default).
  - 범례(legend) 추가: 그래프 카드 하단에 kind별 색 점+라벨(i18n `detail.graphLegend.*`) — 시연 가독성.

- [ ] **Step 1: 구현** — Interfaces 명세대로. MarkerType은 '@xyflow/react' import(기존 파일에 이미 있는지 확인).
- [ ] **Step 2: 게이트** — Run: `pnpm -r typecheck` / Expected: PASS
- [ ] **Step 3: 커밋** — `git commit -m "feat(web): unified graph view — instantiation nodes + derived edges + legend"`

---

### Task 5: 관계 편집 UI (Server Actions)

**Files:**
- Create: `apps/web/lib/actions/relations.ts`
- Create: `apps/web/components/records/RelationEditor.tsx` ('use client')
- Create: `apps/web/app/api/v1/entity-search/route.ts` (GET 전용, withAuth — 대상 자동완성)
- Modify: `apps/web/app/(app)/records/[slug]/page.tsx` (records:write일 때 RelationList 카드에 편집기 렌더)
- Modify: `apps/web/messages/ko.json`, `en.json`
- Test: `packages/db/test` 불변 + web typecheck — 동작 e2e는 Task 7

**Interfaces:**
- Consumes: `createRelation`/`softDeleteRelation`/`listRelationTypes`/`DuplicateRelationError`(@binder/db), `searchEntities`(node만 인덱싱 — search.ts:39), `listAgents`
- Produces:
  - `addRelationAction(prev, formData)` — fields: nodeId, direction('outgoing'|'incoming'), relationTypeMachine, targetType('node'|'agent'), targetId. requireUser+`records:write` → createRelation(direction이 incoming이면 source/target 스왑 — source=상대, target=현재 노드) → DuplicateRelationError는 `{ error: 'duplicate' }` → revalidatePath(encodeURIComponent slug). 반환 `{ error?: string; warnings?: string[]; saved?: boolean }`(도메인 힌트 warnings 노출).
  - `removeRelationAction(prev, formData)` — relationId → softDeleteRelation + revalidate. 권한 동일.
  - GET `/api/v1/entity-search?q=&type=node|agent` — withAuth(GET 전용 — CSRF 불변식 준수, 상태 변경 없음): type=node → searchEntities(q, {entityType:'node', limit:10}) 후 노드 title/slug 조인, type=agent → listAgents 후 name 부분일치 필터(서버 측 10건). 반환 `[{ type, id, label, slug? }]`.
  - `RelationEditor` — 관계유형 select: 유형당 2옵션(정방향=labelForward, 역방향=labelInverse), **option value = `{machine}:{direction}`**(예: `has_part:outgoing` / `has_part:incoming`) — 제출 시 클라이언트에서 split해 hidden input 2개(relationTypeMachine, direction)로 전달. 대상 type 토글(record/agent), 검색 인풋(fetch /api/v1/entity-search, 300ms 디바운스, 결과 리스트 클릭 선택), 추가 버튼(useActionState). 각 기존 관계 행에 삭제 버튼(removeRelationAction, confirm). warnings는 앰버 배너.
  - **e2e 셀렉터 계약(Task 7이 소비 — 반드시 이 testid로)**: `relation-type-select`(select), `relation-target-search`(input), `relation-target-option`(검색 결과 각 항목), `relation-add`(추가 버튼), `relation-delete`(행별 삭제 버튼).
  - i18n `detail.relationEditor.*` 키.

- [ ] **Step 1: 구현** — Interfaces 명세대로. AddTermForm.tsx(useActionState 패턴)·admin-terms.ts(intent 패턴) 스타일 준수. 클라이언트에서 relationTypes는 서버에서 props로 전달(page.tsx에서 listRelationTypes 호출).
- [ ] **Step 2: 게이트** — Run: `pnpm -r typecheck && pnpm --filter web test` / Expected: PASS. CSRF 확인: 신규 라우트가 GET뿐인지 `grep -r "export const POST\|PUT\|DELETE" apps/web/app/api/v1/entity-search/` 빈 결과.
- [ ] **Step 3: 커밋** — `git commit -m "feat(web): relation editor with entity search (server actions)"`

---

### Task 6: 관리 UI — 관계유형 + SKOS(broader) + TTL 다운로드

**Files:**
- Create: `apps/web/app/(app)/admin/relation-types/page.tsx`
- Create: `apps/web/components/admin/RelationTypeRow.tsx`, `apps/web/components/admin/AddRelationTypeForm.tsx`
- Create: `apps/web/lib/actions/admin-relation-types.ts`
- Modify: `packages/db/src/queries/terms.ts` — `updateTermParent(db, termId, parentTermId | null, actorId)` 추가(동일 택소노미 검증 + 사이클 가드 + audit) **및 listTaxonomies 반환에 각 텀의 parentTermId 포함**(현행 반환은 {id, name, machineName}뿐 — terms.ts:22-51)
- Modify: `apps/web/app/(app)/admin/taxonomies/page.tsx` — 승격 대상 택소노미일 때 TermRow에 `siblings`(같은 택소노미 텀 목록)·`parentTermId`·`taxonomyMachine` 전달(page.tsx:20-27 호출부)
- Modify: `apps/web/components/admin/TermRow.tsx` (SKOS 승격 택소노미에 broader select 추가 — 신규 props 수용)
- Modify: `apps/web/lib/actions/admin-terms.ts` (intent='parent' 추가)
- Create: `apps/web/app/api/v1/ontology.ttl/route.ts` (GET, withAuth)
- Modify: `apps/web/app/(app)/admin/layout.tsx` (네비: relation-types 링크 + TTL 다운로드 링크)
- Modify: `apps/web/messages/ko.json`, `en.json` (admin 네임스페이스)
- Test: `packages/db/test/terms-parent.test.ts` + web typecheck

**Interfaces:**
- Consumes: `listRelationTypes`/`createRelationType`/`updateRelationType`(delete는 Plan 10에 없음 — **만들지 않는다**, YAGNI), `exportOntologyTtl`, terms.parentTermId(Plan 10)
- Produces:
  - 관계유형 페이지: 테이블(machine, labelForward ko/en, labelInverse ko/en, sourceKinds/targetKinds 표시) + 행별 라벨 rename(intent 방식) + 신규 생성 폼(machine+라벨 4필드; sourceKinds/targetKinds는 v1 미편집 — 시드 전용, 표시만).
  - `updateTermParent`: 같은 taxonomy 내 텀만 허용(위반 throw), 자기 자신·후손 지정 시 사이클 방지(부모 체인 32단 순회 가드), audit(action update, before/after parentTermId).
  - SKOS broader UI: **승격 대상 택소노미만**(level_of_description, media_type, department, classification, component_type, instantiation_role — 스펙 SKOS 승격 범위 표) TermRow에 부모 select(같은 택소노미 텀 목록, 공백=없음). 시스템 어휘 택소노미에는 미표시.
  - TTL: GET `/api/v1/ontology.ttl` — withAuth(핸들러 반환 타입은 `Promise<NextResponse>` — api.ts:8-10), **`new NextResponse(ttl, { headers: { 'Content-Type': 'text/turtle; charset=utf-8', 'Content-Disposition': 'attachment; filename="binder-ontology.ttl"' } })`**. `ontology.ttl` 디렉터리명은 App Router에서 유효. admin 네비 하단에 다운로드 링크(`<a href>`).

- [ ] **Step 1: db 실패 테스트** — `terms-parent.test.ts`: (a) 같은 택소노미 부모 설정+audit 행, (b) 다른 택소노미 부모 → throw, (c) 사이클(A→B→A) → throw.
- [ ] **Step 2: 실패 확인 → 구현 → 통과** — Run: `pnpm --filter @binder/db test -- terms-parent` / Expected: FAIL → PASS
- [ ] **Step 3: 웹 구현** — Interfaces 명세대로, taxonomies admin 패턴(page.tsx 13행 구조, TermRow/AddTermForm) 복제.
- [ ] **Step 4: 게이트** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 5: 커밋** — `git commit -m "feat(web+db): relation-type admin, skos broader editing, ontology ttl download"`

---

### Task 7: e2e + 시연 + 마감

**Files:**
- Modify: `apps/web/e2e/core-flows.spec.ts` (신규 4본 추가 — 기존 13본 무수정)
- Modify: `packages/db/README.md` (fresh env 순서에 seed-derived 추가)
- Modify: `.superpowers/sdd/progress.md` (PLAN 11 COMPLETE — 컨트롤러가 작성)
- Test: 전체 게이트 + e2e 17본

**Interfaces:**
- Consumes: 전 태스크 산출물. dev DB에 파생 시드 적용(아래 Step 1 — 이 플랜에서 유일한 dev 변형, 컨트롤러 사전 승인됨).

- [ ] **Step 1: dev 실측 기록 + 파생 시드 적용** — 적용 전 레코드별 instantiation 카운트를 psql로 실측해 report에 기록(백남준 6·GF 4·OW 1 기대 — 다르면 잔존 데이터 드리프트이므로 **단언 조정이 아니라 원인 보고**). Run: `pnpm --filter @binder/db db:seed && pnpm --filter @binder/db db:seed-derived` / Expected: created=4(GF·OW 각 2). 백업 불요(추가 전용 시드).
- [ ] **Step 2: 신규 e2e 4본** — 추가(기존 로그인 헬퍼 재사용, **slug는 헤더의 실측값** — 그로스-파티그/무제-백남준/테트리스):

```ts
test('graph view renders instantiation nodes and derived edges (GF)', async ({ page }) => {
  await login(page);
  await page.goto('/records/그로스-파티그');
  const graph = page.getByTestId('context-browser');
  await expect(graph.locator('.rf-node-instantiation').first()).toBeVisible();
  expect(await graph.locator('.rf-node-instantiation').count()).toBeGreaterThanOrEqual(3); // master+access+thumbnail
  expect(await graph.locator('.rf-edge-derived').count()).toBe(2); // access→master, thumbnail→access
  // Instantiation 패널의 파생 표기
  await expect(page.getByTestId('instantiation-row').first()).toBeVisible();
});

test('paik untitled shows equipment instantiations; tetris shows child records (triage demo)', async ({ page }) => {
  await login(page);
  await page.goto('/records/무제-백남준');
  // dev 실측 6(equipment 5 + AIP 1) — 깨지면 dev 데이터 드리프트 의심: 완화 금지, 원인 보고
  await expect(page.getByTestId('instantiation-row')).toHaveCount(6);
  await page.goto('/records/테트리스');
  const graph = page.getByTestId('context-browser');
  // 하위 Record 3종(1984 IBM PC / 1988 NES / 1988 Game Boy)이 record 노드로 잔존
  await expect(graph.locator('.rf-node-record', { hasText: '1984 IBM PC' })).toBeVisible();
  expect(await graph.locator('.rf-node-instantiation').count()).toBeGreaterThanOrEqual(3); // 플랫폼별 AIP 유래
});

test('relation editor adds and removes a relation', async ({ page }) => {
  await login(page);
  await page.goto('/records/테트리스');
  await page.getByTestId('relation-type-select').selectOption('references:outgoing');
  await page.getByTestId('relation-target-search').fill('러버스');
  await page.getByTestId('relation-target-option').first().click();
  await page.getByTestId('relation-add').click();
  await expect(page.getByTestId('relation-row').filter({ hasText: '러버스' })).toBeVisible();
  // 멱등 보장: 삭제까지 완주(2회 실행 안전)
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('relation-row').filter({ hasText: '러버스' }).getByTestId('relation-delete').click();
  await expect(page.getByTestId('relation-row').filter({ hasText: '러버스' })).toHaveCount(0);
});

test('ontology ttl download responds with turtle', async ({ page }) => {
  await login(page);
  const res = await page.request.get('/api/v1/ontology.ttl');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('text/turtle');
  expect(await res.text()).toContain('@prefix');
});
```

라벨 텍스트('러버스', '1984 IBM PC')는 시드 실값 — 구현 시 재확인, 임의 변경 금지.
- [ ] **Step 3: 전체 게이트** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test && cd apps/web && pnpm e2e` / Expected: db 그린 + e2e 17/17 (기존 13 + 신규 4). 실패한 기존 본이 있으면 **원인 분석 후 report에 기록, 단언 완화 금지**.
- [ ] **Step 4: 문서** — README fresh-env 순서 4단으로 갱신(seed → seed-demo → migrate-graph → seed-derived) + 시연 동선 한 줄(트리아지 시연=테트리스·무제-백남준, 파생 계보 시연=그로스-파티그·오피셜-웰컴).
- [ ] **Step 5: 커밋** — `git commit -m "test(web): graph-ui e2e + derived seed rollout"` (푸시는 컨트롤러가 최종 리뷰 후. 시연 스크린샷 검증도 컨트롤러가 프리뷰로 수행)

---

## 자기 리뷰 체크 (적대적 리뷰 2렌즈 반영 완료)

- 스펙 §6 P3.1 4행 커버: 그래프 뷰(T2+T4 — Record·Instantiation·파생·관계 통합, rf-node/rf-edge 식별자), Instantiation 패널(T3), 관계 편집(T5 — 방향별 라벨+셀렉터 계약), 관계유형·SKOS 관리(T6 — 데이터 경로 포함). §8 시연: e2e 4본이 그래프 노드·derived 엣지 카운트·Tetris 하위 Record·백남준 equipment·TTL 응답을 직접 단언 + 컨트롤러 프리뷰 스크린샷.
- CSRF 불변식: 신규 상태변경 라우트 0(entity-search·ontology.ttl 모두 GET withAuth, NextResponse 반환).
- 타입 일관성: NodeDetail.relations는 T2 유지(deprecated)→T3 제거(소비처 2곳 전환과 동시) — 모든 태스크 게이트가 typecheck 그린. entityRelations는 node|agent만 + has_event/performed_by 제외 + 라벨 fallback. RelationEditor option value `{machine}:{direction}` ↔ addRelationAction 필드 계약 일치.
- YAGNI 유지: 관계유형 delete 없음, vocabularies admin 없음, Instantiation 생성/편집 UI 없음(패널=표시 전용), sourceKinds 편집 없음.
- 실측 고정: slug(그로스-파티그·테트리스·무제-백남준)·instantiation 카운트(백남준 6)·라벨 텍스트는 dev 실측치 — 어긋나면 완화 금지·원인 보고. dev 파생 시드는 추가 전용(백업 불요), 적용 전 실측 기록.
