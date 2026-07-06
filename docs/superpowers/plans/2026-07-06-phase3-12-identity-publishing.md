# Plan 12 — P3.2a 식별·발행 기반 (entity_identifiers · /id/ 해석 · JSON-LD 이중 프로파일 · 변경 피드)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Identity Manager의 기반 절반 — 외부 식별자 저장(entity_identifiers), `/id/{type}/{uuid}` 해석 계층(+마이그레이션 리다이렉트), 매핑-드리븐 JSON-LD 발행(RiC-O 기본 + schema.org 프로파일, D6 시연), 변경 피드((at,id) 커서). reconciliation·병합·외부 전거 검색 UI·ULAN 실링크 시연은 **Plan 13(P3.2b)**.

**Architecture:** 피드는 audit_log에서 파생(스펙 §3.4). uuid 식별은 이중 전략 — 핵심 엔터티 쓰기 경로(nodes/agents/terms)에 entityUuid 신규 전달 + 과거 행은 피드 조회 시 해석 폴백(COALESCE: entityUuid → 엔터티 조인 → rowSnapshot). 커서 정밀도는 **PG 텍스트 타임스탬프(µs 보존)** 로 해결. audit_action enum에 'merge' 선등록(Plan 13 대비). 이벤트 수명주기(Plan 10 인계 ④) 확정: 노드 소프트 삭제 시 창작 이벤트도 소프트 삭제.

**Tech Stack:** 기존 그대로(Drizzle·PG16·Next 15). 신규 의존성 없음.

**스펙:** `/Users/daejinan/claude/moma-binder/binder/docs/superpowers/specs/2026-07-06-phase3-knowledge-graph-hub-design.md` §3.1·§3.2(저장 계층+수동 관리 UI — Value Suggest는 Plan 13)·§3.4 + §2.7(schema.org 프로파일). §8 P3.2 시연 중 이 플랜 몫: JSON-LD·변경 피드 응답, 이중 프로파일 발행. ULAN 링크·병합·중복 전거 시드는 Plan 13.

## Global Constraints

- 변이는 Server Actions만(신규 상태변경 API 라우트 금지). `/id/`·`/api/v1/feed`는 **GET 전용 withAuth**(withAuth는 req.cookies 직접 판독 — top-level 라우트에서 동작 확인됨).
- 마이그레이션은 drizzle-kit generate만. 현재 마지막 = 0026. drizzle-kit 0.28은 `ALTER TYPE ... ADD VALUE`를 생성하며 PG16은 tx 내 허용(0028에서 신값 미사용 — 안전 확인됨).
- 변이 함수는 tx+writeAudit(entityUuid 전달). '.js' ESM(스키마 내부 상호 import는 확장자 없이). i18n ko/en·dot 금지.
- 게이트: `pnpm -r typecheck && pnpm --filter @binder/db test` + e2e는 Task 7에서 `cd apps/web && pnpm e2e`.
- dev DB 데이터 변형 스크립트 금지(테스트 DB(binder_test)의 audit_log 백데이트 UPDATE는 테스트 셋업으로 허용 — dev 아님).
- 커밋 트레일러: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- 작업 디렉터리: `/Users/daejinan/claude/moma-binder/binder-next`
- **실측 참조**(2026-07-06): writeAudit 41 sites 중 entityUuid 전달 8(events 2·instantiations 3·relation_types 2·migrate-graph 1); audit_log 인덱스는 audit_row_ix뿐; audit.at은 timestamptz(µs); events.deletedAt 존재하나 미사용; admin/agents는 table+AgentRow(tr) 구조.

---

### Task 1: entity_identifiers 스키마 + 쿼리

**Files:**
- Create: `packages/db/src/schema/entity-identifiers.ts`
- Modify: `packages/db/src/schema/index.ts`
- Create: `packages/db/src/queries/entity-identifiers.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/entity-identifiers.test.ts`

**Interfaces:**
- Produces:
  - `identifierSchemeEnum = pgEnum('identifier_scheme', ['ulan','aat','tgn','wikidata','viaf','custom'])` — 'custom'이 기관 자체 스킴 수용(세분화 필요 시 ADD VALUE로 확장, note가 기관명 기술 — README에 1줄).
  - `entityIdentifiers(id serial PK, entityType entityTypeEnum NOT NULL`(schema/entity-relations.ts enum 재사용)`, entityId integer NOT NULL, scheme identifierSchemeEnum NOT NULL, uri text NOT NULL, verified boolean NOT NULL default false, note jsonb I18nText, createdBy integer FK→users [set null], createdAt timestamp default now())`
  - 인덱스: `entity_identifiers_entity_ix(entityType, entityId)`, 유니크 `entity_identifiers_uq(entityType, entityId, scheme, uri)`
  - 쿼리·타입(전부 `queries/entity-identifiers.ts`에 정의, `src/index.ts` barrel export — 테스트가 import): `addEntityIdentifier(db, input: { entityType: EntityType; entityId: number; scheme: IdentifierScheme; uri: string; verified?: boolean; note?: I18nText }, actorId): Promise<EntityIdentifierRow>`(대상 live 존재 검증 — entity-relations.ts verifyEntityExists 패턴; 23505→`export class DuplicateIdentifierLinkError extends Error {}`), `removeEntityIdentifier(db, id, actorId)`(하드 delete + rowSnapshot 감사 — removeMapping 패턴), `listEntityIdentifiersFor(db, entityType, entityId)`, `setIdentifierVerified(db, id, verified, actorId)`(update+audit)
  - SKOS exactMatch 저장처 겸용(스펙 §2.4) — terms에도 동일 API.

- [ ] **Step 1: 실패 테스트** — 셋업: truncate + seedTaxonomies + 에이전트 1명. 케이스: (a) agent ulan URI 추가 → 행+audit insert, (b) 동일 (agent,ulan,uri) 재추가 → DuplicateIdentifierLinkError, (c) 없는 entityId → throw, (d) remove → 삭제+audit delete(rowSnapshot), (e) term에 aat URI(exactMatch 용례) → listEntityIdentifiersFor 1건, (f) setIdentifierVerified(true) → 반영+audit update.
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- entity-identifiers` / Expected: FAIL
- [ ] **Step 3: 스키마+마이그레이션** — Run: `pnpm --filter @binder/db db:generate`(SQL 검토) `&& pnpm --filter @binder/db db:migrate` / Expected: 0027
- [ ] **Step 4: 구현+barrel → 통과** — Run: `pnpm --filter @binder/db test -- entity-identifiers` / Expected: PASS
- [ ] **Step 5: 커밋** — `git commit -m "feat(db): entity_identifiers — external authority links"`

---

### Task 2: 감사 uuid 정비 + 피드 인덱스 + merge 액션 선등록

**Files:**
- Modify: `packages/db/src/schema/audit.ts` (enum 'merge' + (at,id) 인덱스)
- Modify: `packages/db/src/queries/nodes.ts`, `agents.ts`, `terms.ts`
- Test: `packages/db/test/audit-uuid-coverage.test.ts`

**Interfaces:**
- Produces:
  - `auditActionEnum`에 `'merge'` 추가(AuditEntry.action 타입 포함 — 이 플랜에 기록 경로 없음, Plan 13 몫).
  - `audit_feed_ix` 인덱스 `(at, id)`.
  - nodes(3곳: 86/166/225 부근)·agents(3곳: 56/78/126)·terms(4곳: 64/86/128/174)의 writeAudit에 entityUuid 전달. **주의(실측)**: agents updateAgentName, terms createTerm/updateTermName/updateTermParent/deleteTerm 5곳은 기존 select/returning에 uuid 컬럼이 없음 — **기존 조회의 select 컬럼 목록에 uuid를 추가**해서 취득(신규 쿼리 발행 금지, 컬럼 확장은 허용).
  - `deleteTerm`의 snapshot에 uuid 포함(하드 delete라 피드 폴백의 마지막 수단 — rowSnapshot->>'uuid').

- [ ] **Step 1: 실패 테스트** — 노드 생성→수정→소프트 삭제, 에이전트 생성·rename, 텀 생성·rename·삭제 후 audit_log 각 행의 entityUuid가 엔터티 uuid와 일치(+deleteTerm 행은 rowSnapshot->>'uuid'도 일치). `pg_indexes`에 audit_feed_ix 존재.
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- audit-uuid-coverage` / Expected: FAIL
- [ ] **Step 3: 스키마+마이그레이션** — Run: `pnpm --filter @binder/db db:generate`(SQL 검토: ADD VALUE + CREATE INDEX) `&& pnpm --filter @binder/db db:migrate` / Expected: 0028
- [ ] **Step 4: 호출 수정 → 전체 통과** — Run: `pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 5: 커밋** — `git commit -m "feat(db): audit entity uuids + feed index + merge action"`

---

### Task 3: 이벤트 수명주기 확정 + getCreators uuid (Plan 10 인계 ④)

**Files:**
- Modify: `packages/db/src/queries/nodes.ts` (softDeleteNode 이벤트 연쇄)
- Modify: `packages/db/src/queries/events.ts` (live 필터 + getCreators 반환에 agent uuid 추가)
- Modify: `packages/db/src/queries/detail.ts` (`e.node_id` raw SQL deleted_at 필터)
- Modify: `packages/db/README.md` (인계 ④ 해소)
- Test: `packages/db/test/event-lifecycle.test.ts`

**Interfaces:**
- Produces:
  - **결정**: softDeleteNode의 tx에서 그 노드의 live events도 소프트 삭제(deletedAt=sql`now()`) + 행별 writeAudit(delete, snapshot, entityUuid=event uuid). events.nodeId notNull·cascade는 유지(하드 삭제 경로 없음 — 무해). getCreators 양 경로·detail.ts·(reports.ts도 grep해서 있으면)에 `deleted_at IS NULL` 필터.
  - `getCreators` 반환 확장: `Array<{ agentId: number; agentUuid: string; name: I18nText }>` — 이미 agents 조인 중이므로 컬럼 추가만(Task 4의 JSON-LD creator @id 재료). 웹 소비처는 grep해서 타입만 정합(agentUuid 미사용 소비처는 무수정).

- [ ] **Step 1: 실패 테스트** — 노드+creation 이벤트 → softDeleteNode → (a) events.deletedAt 설정, (b) getCreators 빈 결과, (c) audit events delete 행(entityUuid), (d) 타 노드 이벤트 무영향, (e) live 노드의 getCreators가 agentUuid 반환.
- [ ] **Step 2: 실패 확인 → 구현 → 전체 통과** — Run: `pnpm --filter @binder/db test` / Expected: PASS (+`pnpm --filter web typecheck`)
- [ ] **Step 3: 커밋** — `git commit -m "feat(db): event lifecycle on node soft-delete + creator uuids (handoff #4)"`

---

### Task 4: JSON-LD 직렬화기 (매핑-드리븐, 이중 프로파일)

**Files:**
- Modify: `packages/db/src/seed/vocabulary-data.ts` (property 매핑 시드 5건 추가)
- Create: `packages/db/src/queries/jsonld.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/jsonld.test.ts`

**Interfaces:**
- Consumes: `listAllMappings`, `internalClassFor`(@binder/core), Task 1 `listEntityIdentifiersFor`, `listRelationsForEntity`, `listInstantiationsForRecord`, Task 3 `getCreators`(agentUuid 포함), pickI18n
- Produces:

```ts
export type JsonLdProfile = 'rico' | 'schema';
export async function buildEntityJsonLd(
  db: PostgresJsDatabase,
  type: EntityType, uuid: string,
  opts?: { profile?: JsonLdProfile },     // 기본 'rico'
): Promise<Record<string, unknown> | null>;  // null = 없음 또는 소프트 삭제
```

  - **매핑 시드 확장**(§8 "시연에 필요한 시드는 해당 단계 범위" — MAPPING_SEEDS에 추가, seedVocabularies 멱등 재실행으로 반영):

```ts
{ subjectKind: 'property', subjectKey: 'label', prefix: 'schema', localName: 'name' },
{ subjectKind: 'property', subjectKey: 'date_created', prefix: 'schema', localName: 'dateCreated' },
{ subjectKind: 'property', subjectKey: 'creator', prefix: 'schema', localName: 'creator' },
{ subjectKind: 'property', subjectKey: 'identifier', prefix: 'dcterms', localName: 'identifier' },
{ subjectKind: 'property', subjectKey: 'identifier', prefix: 'schema', localName: 'identifier' },
```

  - **프로파일 필터**: 'rico' = prefix∈{rico, skos, dcterms}, 'schema' = prefix∈{schema}. **고정 어노테이션(원칙의 선언된 예외 — 코드 고정 허용)**: `rdfs:label`(rico 프로파일의 라벨), `owl:sameAs`(전 프로파일 — entity_identifiers), `skos:exactMatch`(term × rico). rdfs/owl prefix는 @context에 고정 추가. **그 외 속성은 매핑 존재 시에만 출력, 임의 URI 발명 금지.**
  - `@id` = `buildEntityUri(type, uuid)`(상대 경로 — @binder/core). `@type` = class 매핑(내부 클래스 키: node→internalClassFor(kind, levelMachine — **노드 조회 시 levelTermId→terms.machineName 조인으로 취득**), agent→'agent_person'|'agent_organization', event→'event', term→'concept', instantiation→'instantiation'), 프로파일 필터 적용, CURIE 표기.
  - 라벨: title/name/label을 rico→rdfs:label, schema→'label' 매핑(schema:name)으로 — `[{ "@value": …, "@language": "ko"|"en" }]`.
  - node: identifier/accessionNumber → 'identifier' 매핑(프로파일별), dateCreated → 'date_created' 매핑, creators(getCreators) → 'creator' 매핑으로 agent @id 배열.
  - 관계: listRelationsForEntity outgoing(node|agent 상대)을 relation_type 매핑으로(프로파일 내 매핑 있는 유형만), 값 = 상대 @id. **상대 uuid 조달**: RelationView는 otherId만 있으므로 jsonld.ts 내부에 **배치 uuid 해석 헬퍼** `resolveUuids(db, refs: Array<{type, id}>): Promise<Map<string,string>>`(타입별 테이블 IN 조회, live 필터 — 해석 실패 상대는 그 속성에서 제외)를 Produces로 정의.
  - node의 instantiations: 'has_instantiation' 매핑(rico:hasOrHadInstantiation — 시드 실존)으로 live instantiation @id 배열(`/id/instantiation/{uuid}` — instantiation 행에 uuid 있음). instantiation 문서: 'is_derived_instantiation_of' 매핑으로 원본 @id, role 라벨은 rdfs:label에 병기.
  - 외부 식별자: owl:sameAs uri 배열(전 타입), term은 skos:exactMatch(rico).
  - 소프트 삭제 → null.

- [ ] **Step 1: 실패 테스트** — 셋업: 그래프 통합 셋업 복제(시드 4종+seedDemo+runGraphMigration+seedDerivedDemo). 케이스: (a) 백남준 agent rico → @type에 rico:Person(CURIE)+@context rico, (b) 동일 agent schema → @type schema:Person + schema:name 존재 + rico prefix 부재, (c) GF node rico → **문서 @id는 `/id/node/{uuid}`**, rico:hasOrHadInstantiation 배열 ≥3이고 **항목들의 @id는 `/id/instantiation/{uuid}` 형식**, (d) GF thumbnail instantiation rico → is_derived 매핑 속성에 원본 @id, (e) agent에 ulan 추가 후 → owl:sameAs 포함, (f) node schema 프로파일 → schema:creator에 agent @id + schema:identifier 존재, (g) 없는 uuid → null, (h) 소프트 삭제 노드 → null.
- [ ] **Step 2: 실패 확인 → 구현 → 통과** — Run: `pnpm --filter @binder/db test -- jsonld` 후 전체 / Expected: PASS
- [ ] **Step 3: 커밋** — `git commit -m "feat(db): mapping-driven JSON-LD — rico + schema.org profiles (D6)"`

---

### Task 5: /id/ 해석 라우트 (+마이그레이션 리다이렉트)

**Files:**
- Create: `packages/db/src/queries/resolve-id.ts`
- Create: `apps/web/app/id/[type]/[uuid]/route.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/resolve-id.test.ts`

**Interfaces:**
- Produces:

```ts
export type IdResolution =
  | { kind: 'not-found' }
  | { kind: 'live'; nodeSlug?: string }              // live 엔터티 (node면 slug 동봉)
  | { kind: 'redirect'; location: string }           // 삭제 node + migratedFromNodeId 역참조 → /id/instantiation/{uuid}
  | { kind: 'gone' };
export async function resolveEntityId(db, type: EntityType, uuid: string): Promise<IdResolution>;
```

  - node: live→`{live, nodeSlug}`; 삭제됐고 그 노드 id를 migratedFromNodeId로 갖는 live instantiation 존재→`{redirect}`(인계 ① 이행 — 삭제 노드 조회는 deletedAt 무시); 아니면 gone. 타 타입: live→live, 삭제→gone, 없음→not-found.
  - 라우트(GET withAuth) — **분기 순서 확정: resolveEntityId 판정 먼저, format 무관**: not-found→404, gone→410, redirect→301(Location — format=jsonld여도 301; 기계 소비자도 URI 영속성 §2.1을 따라감). live일 때만: `?format=jsonld`(+`?profile=schema`)→buildEntityJsonLd 응답(Content-Type `application/ld+json`; null이면 404 방어), format 미지정 → node는 302 `/records/{slug}`, 그 외 타입은 JSON-LD 응답(사람용 화면 부재 — 스펙 §3.1 '또는').
  - 타입 파라미터가 ENTITY_TYPES 외이면 404.

- [ ] **Step 1: 실패 테스트(해석 로직)** — (a) live 노드→live+slug, (b) 마이그레이션 삭제 컴포넌트 노드 uuid→redirect(/id/instantiation/{대상 uuid} — migratedFromNodeId 일치 확인), (c) live instantiation→live, (d) 무작위 uuid→not-found, (e) redirect 케이스는 **format과 무관**함을 라우트 계층 주석으로 남기고 로직 테스트는 (b)로 충분.
- [ ] **Step 2: 실패 확인 → 구현 → 통과 + typecheck** — Run: `pnpm --filter @binder/db test -- resolve-id && pnpm -r typecheck` / Expected: PASS
- [ ] **Step 3: 커밋** — `git commit -m "feat(web+db): /id resolver with migration redirect (handoff #1)"`

---

### Task 6: 변경 피드 API

**Files:**
- Create: `packages/db/src/queries/feed.ts`
- Create: `apps/web/app/api/v1/feed/route.ts` (GET withAuth 얇은 래퍼)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/feed.test.ts`

**Interfaces:**
- Produces:

```ts
export type FeedCursor = { at: string; id: number };   // at = PG 텍스트 타임스탬프 그대로(µs 보존)
export type FeedEvent = {
  seq: FeedCursor;
  kind: 'entity' | 'relation';
  action: 'created' | 'updated' | 'deleted' | 'merged';
  entity?: { type: EntityType; uuid: string; uri: string };   // uri = buildEntityUri(type, uuid) 상대 경로
  relation?: { relationType: string; source: { type: EntityType; uuid: string } | null; target: { type: EntityType; uuid: string } | null };
  origin: 'user' | 'sync' | 'ingest';
  actorKind: 'user' | 'ai' | 'workflow';
  at: string;                                           // seq.at와 동일 텍스트
};
export async function readChangeFeed(
  db: PostgresJsDatabase,
  opts?: { cursor?: FeedCursor; limit?: number; marginMs?: number },  // limit 기본 100 최대 500, marginMs 기본 3000(테스트는 0 주입)
): Promise<{ events: FeedEvent[]; nextCursor: FeedCursor | null }>;
```

  - **정밀도(블로커 대응)**: SELECT에서 `at::text AS at_text`로 취득(µs 보존), 커서 비교는 raw SQL `(at, id) > (${cursor.at}::timestamptz, ${cursor.id})`, 마진은 `at <= now() - (${marginMs} * interval '1 millisecond')`. ORDER BY at, id LIMIT n.
  - **대상**: nodes/agents/terms/events/instantiations(→kind 'entity', type 매핑 표) + entity_relations(→'relation'). 그 외 테이블 행 제외(쿼리 WHERE table_name IN).
  - **액션 매핑**: insert→created, update→updated, delete→deleted, merge→merged, **restore→updated**.
  - **uuid 해석**: `COALESCE(a.entity_uuid, n.uuid, ag.uuid, t.uuid, ev.uuid, inst.uuid, a.row_snapshot->>'uuid')` — 테이블별 LEFT JOIN(`ON a.table_name='nodes' AND n.id=a.row_id` 식, deletedAt 무시). 해석 실패 이벤트는 events 배열에서 **skip**(README에 유실 범위 명기: entityUuid NULL이고 하드 삭제된 과거 term 등).
  - **nextCursor(정지 방지)**: **스캔 행이 1개 이상이면 nextCursor = 마지막 스캔 행의 (at_text, id) — skip 여부 무관.** 스캔 0행일 때만 null(호출자가 커서 보존).
  - relation payload: entity_relations LEFT JOIN(삭제 포함) → relation_types.machineName, source/target 각각 동일 COALESCE 해석(실패 시 null).
  - 라우트: GET `/api/v1/feed?at=&id=&limit=` → readChangeFeed(marginMs 기본값 사용), 응답 `{ events, nextCursor }`.

- [ ] **Step 1: 실패 테스트** — 셋업: 전면 truncate+시드+노드/에이전트/관계 생성. **전 케이스 marginMs: 0 주입**(마진 자체 검증 케이스 제외). 케이스: (a) 커서 없이 → created 이벤트 uuid·uri, (b) 과거 행 폴백: entity_uuid NULL인 nodes insert audit 행 직접 INSERT → 조인 해석, (c) 관계 생성 → relation payload(source/target uuid), (d) 커서 증분: 1차 nextCursor로 2차 조회 시 이전 이벤트 0건 + 신규 변이만(같은 ms 몰림 검증 — 연속 insert 5건 후 중간 커서로 재조회, µs 커서로 중복 없음), (e) **마진 검증**: marginMs 3000으로 호출 시 방금 행 미포함 → `UPDATE audit_log SET at = at - interval '10 seconds'`(테스트 DB) 후 포함, (f) action='merge' 행 직접 INSERT → 'merged', (g) users 행 미노출, (h) **all-skip 창**: 해석 불가 행(entity_uuid NULL + 조인 불가 table row + snapshot 무 uuid)만 존재하는 구간 → events 0건이지만 nextCursor는 전진.
- [ ] **Step 2: 실패 확인 → 구현 → 전체 통과** — Run: `pnpm --filter @binder/db test -- feed` 후 전체 / Expected: PASS
- [ ] **Step 3: 라우트 + typecheck** — Run: `pnpm -r typecheck` / Expected: PASS
- [ ] **Step 4: 커밋** — `git commit -m "feat(db+web): change feed — µs-safe (at,id) cursor + margin + resolution fallback (§3.4)"`

---

### Task 7: 외부 식별자 관리 UI + JSON-LD alternate + e2e + 마감

**Files:**
- Modify: `apps/web/app/(app)/admin/agents/page.tsx` (AgentRow에 식별자 데이터 전달)
- Modify: `apps/web/components/admin/AgentRow.tsx` (펼침 토글 + data-agent-uuid)
- Create: `apps/web/components/admin/AgentIdentifiers.tsx`
- Create: `apps/web/lib/actions/admin-identifiers.ts`
- Modify: `apps/web/app/(app)/records/[slug]/page.tsx` (generateMetadata 신규 — JSON-LD alternate link; Task 5 라우트의 소비 지점, 이 태스크 소속)
- Modify: `apps/web/messages/ko.json`, `en.json`
- Modify: `apps/web/e2e/core-flows.spec.ts` (신규 3본 — 기존 17본 무수정)
- Modify: `packages/db/README.md`
- Modify: `.superpowers/sdd/progress.md` (컨트롤러 작성)

**Interfaces:**
- Consumes: Task 1 쿼리, Task 5 라우트(/id/agent/{uuid}?format=jsonld), Task 6 피드, listAgents(agents full row — uuid 포함)
- Produces:
  - **admin agents 배치 확정**: AgentRow(tr)에 펼침 토글 버튼 추가 → 펼치면 다음 `<tr><td colSpan={전체 열수}>`에 `AgentIdentifiers`(agentId·agentUuid props) 렌더. AgentIdentifiers: 식별자 목록(scheme·uri·verified 뱃지) + 추가 폼(scheme select + uri input, `data-testid="identifier-add"`) + 행별 삭제(`identifier-delete`) + verified 토글(`identifier-verify`). 컨테이너에 `data-agent-uuid={agentUuid}`. Server Actions(admin:manage, revalidatePath '/admin/agents').
  - **generateMetadata**(records/[slug]/page.tsx — 현재 없음, 신규): `alternates: { types: { 'application/ld+json': `/id/node/${uuid}?format=jsonld` } }` — uuid는 기존 getNodeDetail 결과.
  - e2e 3본(실코드 — 기존 로그인 헬퍼):

```ts
test('agent identifier roundtrip surfaces in jsonld sameAs', async ({ page }) => {
  await login(page);
  await page.goto('/admin/agents');
  // 백남준 행 펼침 → 식별자 추가
  const row = page.locator('tr', { hasText: '백남준' }).first();
  await row.getByRole('button').first().click(); // 펼침 토글(구현 testid가 있으면 그것으로)
  const panel = page.locator('[data-agent-uuid]', { hasText: 'ULAN' }).first().or(page.locator('[data-agent-uuid]').first());
  const agentUuid = await panel.getAttribute('data-agent-uuid');
  await panel.locator('select').selectOption('ulan');
  await panel.locator('input[name=uri]').fill('https://vocab.getty.edu/ulan/e2e-demo');
  await panel.getByTestId('identifier-add').click();
  await expect(panel.getByText('ulan/e2e-demo')).toBeVisible();
  const ld = await page.request.get(`/id/agent/${agentUuid}?format=jsonld`);
  expect(ld.status()).toBe(200);
  expect(JSON.stringify(await ld.json())).toContain('ulan/e2e-demo'); // owl:sameAs
  // 정리(멱등)
  page.once('dialog', (d) => d.accept());
  await panel.getByTestId('identifier-delete').first().click();
  await expect(panel.getByText('ulan/e2e-demo')).toHaveCount(0);
});

test('id route negotiates jsonld with dual profiles via alternate link', async ({ page }) => {
  await login(page);
  await page.goto('/records/그로스-파티그');
  const href = await page.locator('link[rel=alternate][type="application/ld+json"]').getAttribute('href');
  const rico = await page.request.get(href!);
  expect(rico.status()).toBe(200);
  expect(JSON.stringify((await rico.json())['@context'])).toContain('ica.org');
  const schema = await page.request.get(href! + '&profile=schema');
  expect(JSON.stringify((await schema.json())['@context'])).toContain('schema.org');
});

test('change feed returns cursor-paged events', async ({ page }) => {
  await login(page);
  const r1 = await page.request.get('/api/v1/feed?limit=5');
  expect(r1.status()).toBe(200);
  const b1 = await r1.json();
  expect(b1.events.length).toBeGreaterThan(0);
  expect(b1.events[0].entity?.uri ?? b1.events[0].relation).toBeTruthy();
  if (b1.nextCursor) {
    const r2 = await page.request.get(`/api/v1/feed?at=${encodeURIComponent(b1.nextCursor.at)}&id=${b1.nextCursor.id}&limit=5`);
    expect(r2.status()).toBe(200);
  }
});
```

  (첫 테스트의 펼침 토글·panel 로케이터는 구현된 testid 기준으로 구현 직후 정밀화 — 셀렉터 계약: 토글 `identifier-toggle`, 패널 컨테이너에 `data-agent-uuid`. 구현과 테스트를 같은 태스크에서 작성하므로 placeholder 아님.)

- [ ] **Step 1: UI+액션+generateMetadata 구현** — Run: `pnpm -r typecheck` / Expected: PASS
- [ ] **Step 2: e2e 3본 작성·실행** — Run: `cd apps/web && pnpm e2e` / Expected: **20/20**. 실패 시 완화 금지·원인 보고.
- [ ] **Step 3: 문서** — README: 인계 ①·④ 해소 표기, 피드 절(커서·마진·해석 폴백과 유실 범위 — 하드 삭제된 과거 term 등), identifier scheme 'custom' 노트.
- [ ] **Step 4: 전체 게이트** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test` / Expected: 그린
- [ ] **Step 5: 커밋** — `git commit -m "feat(web): agent identifiers admin + jsonld alternate + feed e2e"` (푸시는 컨트롤러 최종 리뷰 후)

---

## 자기 리뷰 체크 (적대적 리뷰 반영 완료)

- 블로커 해소: marginMs 옵션(기본 3000, 테스트 0 주입) + 마진 검증은 백데이트 UPDATE(테스트 DB 한정 — Global Constraints에 허용 명기).
- 커서 정밀도: at::text(µs 보존) 취득·반환, 비교는 ::timestamptz 캐스트 — 같은 ms 다중 행 중복/유실 없음(테스트 (d)가 검증).
- JSON-LD uuid 조달 3종 해소: resolveUuids 헬퍼(관계 상대), getCreators agentUuid(Task 3), levelMachine terms 조인 명기. schema 프로파일 자기모순 해소: property 매핑 시드 5건 + 고정 어노테이션 명단(rdfs:label/owl:sameAs/skos:exactMatch) 선언.
- e2e 스텁 해소: 3본 전부 실코드, 첫 테스트가 owl:sameAs를 /id/ 라우트로 직접 단언(정리 포함 멱등).
- skip-정지 해소: nextCursor는 스캔 기준 전진(all-skip 테스트 (h)).
- /id/ 분기: resolveEntityId 우선(format 무관 301/410/404) — URI 영속성 유지.
- 범위 선긋기: reconciliation·병합 실행·Value Suggest(Wikidata/Getty API)·ULAN 실링크·중복 전거 시드 = Plan 13. restore→updated 매핑 명시. DuplicateIdentifierLinkError 정의·export 명시.
