# Plan 13 — P3.2b Reconciliation (중복 탐지 · 승인 큐 · 병합 · Value Suggest)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Identity Manager의 나머지 절반 — 중복 후보 배치 탐지(워커), 인간 승인 큐, 병합 실행(패자 URI 영구 리다이렉트 + merged 피드 + sameAs), 외부 전거 검색(Value Suggest). 완료 시 **P3.2 단계 완료**(스펙 §3 전체 + §8 P3.2 시연 4항목).

**Architecture:** 병합 기록은 `entity_merges`(loserUuid가 리다이렉트 키), 후보는 `recon_candidates`(정규화 쌍 유니크). 병합은 단일 tx에서 참조 전수 이전(스카우트로 확정된 FK 지도) → 패자 제거(agent 소프트 삭제/term 하드 삭제+snapshot) → `action='merge'` 감사(Plan 12 선등록 소비). resolve-id·jsonld·feed는 entity_merges를 조회해 각각 301 체인·승자 sameAs·merge 페이로드를 제공. 자동 병합 금지 — 전 경로 인간 게이트(스펙 §3.3).

**Tech Stack:** 기존 그대로. 외부 API: Wikidata(필수)·Getty SPARQL(베스트-에포트) — 서버 프록시로만.

**스펙:** `/Users/daejinan/claude/moma-binder/binder/docs/superpowers/specs/2026-07-06-phase3-knowledge-graph-hub-design.md` §3.2(Value Suggest)·§3.3 전체 + §8 P3.2 잔여 시연(ULAN 링크, 중복 전거 병합→구 URI 리다이렉트, merged 이벤트 포함 피드).

## Global Constraints

- 변이는 Server Actions만(admin:manage). 신규 GET 라우트는 `/api/v1/authority-search`(withAuth) 1본.
- 마이그레이션은 drizzle-kit generate만. 현재 마지막 = 0028 → 이번 0029.
- 변이 함수는 tx+writeAudit(entityUuid). 자동 병합 금지 — mergeAgents/mergeTerms는 명시적 actorId 필수(null 금지).
- 외부 API 프록시: AbortController 5s 타임아웃, 실패는 빈 배열(500 금지), 결과는 텍스트 렌더만(링크화 금지 — XSS 선행 조건, 원장 P3-12 기록).
- 게이트: `pnpm -r typecheck && pnpm --filter @binder/db test` + e2e `cd apps/web && pnpm e2e`(기존 24 무수정). e2e는 외부 API 미의존.
- dev DB 데이터 변형 스크립트 금지(시드 멱등 재실행은 Task 7 절차로).
- 커밋 트레일러: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- 작업 디렉터리: `/Users/daejinan/claude/moma-binder/binder-next`
- **실측 참조(2026-07-06 스카우트)**: agents 참조 = events.agentId(set null)·rights.rightsHolderAgentId(set null)+폴리모픽 entity_relations·entity_identifiers. terms 참조 13 FK = nodes.levelTermId(restrict)/departmentTermId/componentTypeTermId, nodeTerms.termId(restrict, 복합 PK), events.typeTermId(restrict), rights.basisTermId(restrict)/copyrightStatusTermId, rightsActs.actTermId(restrict), relations.typeTermId(구, restrict), notes.typeTermId(restrict), aips.typeTermId, instantiations.roleTermId(restrict), files.mediaTypeTermId + 자기참조 reciprocalTermId/parentTermId. 유니크: agents_slug_live_uq, terms_taxonomy_machine_name_uq, entity_relations_untimed_uq, entity_identifiers_uq.

---

### Task 1: entity_merges + recon_candidates 스키마·기본 쿼리

**Files:**
- Create: `packages/db/src/schema/reconciliation.ts`
- Modify: `packages/db/src/schema/index.ts`
- Create: `packages/db/src/queries/reconciliation.ts` (후보 CRUD — 병합 실행은 Task 2/3)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/reconciliation-schema.test.ts`

**Interfaces:**
- Produces:
  - `reconStatusEnum = pgEnum('recon_status', ['pending','merged','dismissed'])`
  - `entityMerges(id serial PK, entityType entityTypeEnum NOT NULL`(재사용)`, loserId integer NOT NULL, loserUuid uuid NOT NULL, winnerId integer NOT NULL, winnerUuid uuid NOT NULL, loserSnapshot jsonb NOT NULL, transferStats jsonb NOT NULL, mergedBy integer FK→users [set null], mergedAt timestamp default now())` + 유니크 `entity_merges_loser_uq(entityType, loserUuid)`(한 패자는 한 번만) + 인덱스 `entity_merges_winner_ix(entityType, winnerUuid)`
  - `reconCandidates(id serial PK, entityType entityTypeEnum NOT NULL, aId integer NOT NULL, bId integer NOT NULL, score integer NOT NULL, reasons jsonb NOT NULL`(string[])`, status reconStatusEnum NOT NULL default 'pending', createdAt timestamp default now(), resolvedBy integer FK→users [set null], resolvedAt timestamp)` + 유니크 `recon_candidates_pair_uq(entityType, aId, bId)` — **쌍 정규화는 앱 계층에서 aId<bId 강제**(insert 전 min/max 정렬, 위반 insert 시도는 throw)
  - 타입·쿼리(전부 barrel export):

```ts
export type InsertReconCandidateInput = { entityType: 'agent' | 'term'; aId: number; bId: number; score: number; reasons: string[] };
export type ReconCandidateView = {
  id: number; entityType: EntityType; aId: number; bId: number;
  aLabel: I18nText | null; bLabel: I18nText | null;   // 라벨 조인(삭제된 엔터티는 null — 표시 시 '#id' 폴백)
  aUuid: string | null; bUuid: string | null;          // Task 6 행 data 속성·Task 7 e2e가 소비
  score: number; reasons: string[]; status: 'pending' | 'merged' | 'dismissed'; createdAt: Date;
};
```

  - `insertReconCandidate(db, input)`(정규화 aId<bId 강제+onConflictDoNothing, audit 불요 — 시스템 산출물, README에 근거 기록), `listReconCandidates(db, { status?, entityType? }): Promise<ReconCandidateView[]>`, `dismissReconCandidate(db, id, actorId)`(status→dismissed+resolvedBy/At, audit update), `markReconCandidateMerged(db, id, actorId)`(status→merged — Task 2/6이 소비), `getMergeByLoserUuid(db, entityType, uuid)`, `listMergesForWinner(db, entityType, winnerId)`

- [ ] **Step 1: 실패 테스트** — (a) 후보 삽입 시 (b,a) 입력이 (a,b)로 정규화 저장+중복 onConflict 무시, (b) listReconCandidates가 agent 이름 라벨 포함, (c) dismiss → status·resolvedBy·audit update 행, (d) entity_merges 유니크(같은 loser 재삽입 → 23505).
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- reconciliation-schema` / Expected: FAIL
- [ ] **Step 3: 스키마+마이그레이션** — Run: `pnpm --filter @binder/db db:generate`(SQL 검토) `&& pnpm --filter @binder/db db:migrate` / Expected: 0029
- [ ] **Step 4: 구현 → 통과** / **Step 5: 커밋** — `git commit -m "feat(db): entity_merges + recon_candidates"`

---

### Task 2: mergeAgents

**Files:**
- Modify: `packages/db/src/queries/audit.ts` (**merge 감사 지원** — 아래)
- Create: `packages/db/src/queries/merge.ts` (mergeAgents — Task 3이 mergeTerms 추가)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/merge-agents.test.ts`

**Interfaces:**
- Produces:

```ts
export class MergeValidationError extends Error {}
export type MergeResult = { mergeId: number; transferStats: Record<string, number> };
export async function mergeAgents(db: PostgresJsDatabase, winnerId: number, loserId: number, actorId: number,
  opts?: { candidateId?: number }): Promise<MergeResult>;
```

  - **writeAudit 확장(실측: 현 AuditEntry는 changes 필드가 없고 rowSnapshot은 delete 전용 — 그대로는 merge 감사 구현 불가)**: AuditEntry에 `changes?: Record<string, [unknown, unknown]>` 패스스루 허용(전달 시 diff 계산 대신 그대로 저장 — merge 전용 용례), rowSnapshot 저장 조건을 `action === 'delete' || action === 'merge'`로 확장. 기존 동작(update diff·delete snapshot) 불변 — 회귀는 기존 audit 테스트가 가드.
  - **transferStats 키 규칙**: FK 이전은 `'<table>.<column>'`(예: 'events.agentId'), 특수 키 `relationsDeduped`/`identifiersDeduped`/`residualRelations`(+Task 3: `nodeTermsDeduped`).
  - 단일 tx 절차(내부 함수에 tx 전달 — savepoint 패턴):
    1. 검증: winner≠loser, 둘 다 live agents, loser가 entity_merges에 없음. 위반 → MergeValidationError.
    2. 이전(live 행만 스코프, 각 카운트 기록):
       - `events.agentId`: loser→winner UPDATE.
       - `rights.rightsHolderAgentId`: loser→winner UPDATE.
       - `entity_relations` **양방향**(실측: performed_by는 agent가 항상 **target**): (i) sourceType='agent' AND sourceId=loser AND deleted_at IS NULL → sourceId=winner, (ii) targetType='agent' AND targetId=loser AND deleted_at IS NULL → targetId=winner. **유니크 충돌 사전 탐지는 무기한(start/end NULL)+live 행에 한정**(entity_relations_untimed_uq가 부분 인덱스 — 기간 있는 관계는 무조건 이전 가능): winner 측 동일 튜플 존재 시 해당 loser 행 소프트 삭제(+audit delete, relationsDeduped++). **self-relation 처리**: 이전 결과 source==target(winner↔winner)이 되는 행은 이전 대신 소프트 삭제(+audit delete, relationsDeduped++).
       - `entity_identifiers`: entityType='agent' AND entityId=loser → winner. 유니크 충돌 시 loser 행 하드 삭제(+rowSnapshot audit — removeEntityIdentifier 패턴 인라인, identifiersDeduped++).
       - `recon_candidates`: opts.candidateId가 주어지면 그 후보는 **status→merged**(markReconCandidateMerged), 그 외 loser가 낀 pending 후보는 dismissed(resolvedBy=actorId).
    3. 패자 소프트 삭제: 인라인(agents.deletedAt=sql`now()`) + 방어적 softDeleteRelationsForEntity(2에서 전부 이전됐으므로 잔여 0이어야 정상 — residualRelations로 기록, 0이 아니면 로직 결함 신호).
    4. `entity_merges` insert(loserSnapshot=병합 전 loser 행 전체, transferStats).
    5. 감사: `writeAudit(tx, { tableName: 'agents', rowId: loserId, action: 'merge', actorId, origin: 'user', entityUuid: loserUuid, snapshot: loser행, changes: { merged_into: [null, winnerUuid] } })` — 확장된 writeAudit가 snapshot을 rowSnapshot으로 저장(스펙 §3.3 '병합 전 스냅샷 포함'). 피드 페이로드는 entity_merges 조인(changes는 사람용 참고).
  - **주의**: agents_slug_live_uq — 패자 소프트 삭제로 slug 해제(live-only 인덱스라 충돌 없음).

- [ ] **Step 1: 실패 테스트** — 셋업: 시드+에이전트 2명(**incoming 관계 필수 포함** — 이벤트→loser performed_by, node→loser 일반 관계, 이벤트·권리·식별자 부착). 케이스: (a) 병합 후 events/rights/entity_identifiers/**entity_relations 양방향(incoming performed_by 포함)**이 전부 winner 참조, (b) loser 소프트 삭제+entity_merges 행(snapshot·stats — 'events.agentId' 키 존재 단언), (c) audit action='merge' 행(entityUuid=loserUuid, **rowSnapshot=병합 전 loser 행 단언**), (d) 무기한 관계 유니크 충돌(source·target 각 변형) 시 loser 행 소프트 삭제·winner 측 1건 잔존·**기간 있는 동일 관계는 이전됨**, (e) 식별자 유니크 충돌 시 중복 제거, (f) 재병합 → MergeValidationError, (g) 자기 병합 → MergeValidationError, (h) loser↔winner 직접 관계 → self-relation 소프트 삭제, (i) candidateId 전달 시 그 후보 status='merged'.
- [ ] **Step 2~4: TDD 사이클 → 전체 통과** — Run: `pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 5: 커밋** — `git commit -m "feat(db): mergeAgents — full reference transfer + merge audit"`

---

### Task 3: mergeTerms

**Files:**
- Modify: `packages/db/src/queries/merge.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/merge-terms.test.ts`

**Interfaces:**
- Produces: `mergeTerms(db, winnerId, loserId, actorId, opts?: { candidateId?: number }): Promise<MergeResult>` — mergeAgents와 동일 프레임(transferStats 키 규칙·candidateId 의미론·감사 형태 동일 — **감사는 merge 1행(rowSnapshot 포함)뿐, 별도 delete 행 없음: 피드에 deleted/merged 중복 이벤트 배제**)이되:
  - 추가 검증: **같은 taxonomy**(위반 → MergeValidationError — SKOS 승격·시스템 어휘 불문 동일 규칙).
  - 이전 대상(실측 13 FK + 자기참조 2 — 전수, 각 카운트 기록): nodes.levelTermId / nodes.departmentTermId / nodes.componentTypeTermId / nodeTerms.termId(**복합 PK 충돌 시 loser 행 DELETE** — winner 조합이 이미 있으면) / events.typeTermId / rights.basisTermId / rights.copyrightStatusTermId / rightsActs.actTermId / relations.typeTermId(구) / notes.typeTermId / aips.typeTermId / instantiations.roleTermId / files.mediaTypeTermId / terms.reciprocalTermId / terms.parentTermId(**자기참조 — loser를 부모/역상으로 갖는 텀들을 winner로; winner 자신을 가리키게 되는 자기 참조는 null로**) + entity_relations(sourceType/targetType='term') + entity_identifiers(entityType='term').
  - nodeTerms 복합 PK 충돌 DELETE: **행 단위 감사 불가**(node_terms에 serial id 없음 — writeAudit rowId 요구) — transferStats.nodeTermsDeduped 카운트로만 기록(플랜 확정).
  - 패자 제거: terms는 deletedAt이 없으므로 **하드 DELETE + action='merge' 감사 1행(rowSnapshot=삭제 전 행)**. usage 가드 불요(방금 전수 이전) — 단 DELETE 전 **참조 잔여 0 검증**: 13 FK 서브셀렉트 합산 단일 SQL로(**주의: 기존 deleteTerm의 usage 카운트는 11곳뿐 — instantiations.roleTermId·terms.parentTermId 누락. 복붙 금지, 이 플랜의 13 FK 목록 기준으로 신규 작성**), 잔여 있으면 throw·롤백.
  - 감사 action='merge'(tableName 'terms', entityUuid=loserUuid).
  - machineName 충돌: loser 하드 삭제로 자연 해소. **단 machineName 있는 시드 텀은 병합 금지**(MergeValidationError — 시스템 어휘 보호; machineName IS NULL인 데이터 유래 텀만 패자 가능. 승자는 제한 없음).

- [ ] **Step 1: 실패 테스트** — 셋업: 시드+같은 택소노미에 데이터 유래 텀 2개(+노드 부착·nodeTerms·관계). 케이스: (a) 13 FK 대표 4종(nodes.departmentTermId, nodeTerms 충돌 케이스, instantiations.roleTermId, terms.parentTermId)이 winner로 이전, (b) loser 하드 삭제+snapshot 감사+entity_merges, (c) 타 택소노미 병합 → throw, (d) machineName 보유 loser → throw, (e) 참조 잔여 검증(정상 경로에서 0).
- [ ] **Step 2~4: TDD → 전체 통과** / **Step 5: 커밋** — `git commit -m "feat(db): mergeTerms — 13-FK transfer + hard-delete with snapshot"`

---

### Task 4: 병합 소비면 — resolve-id 301 체인 · jsonld sameAs · feed merge 페이로드

**Files:**
- Modify: `packages/db/src/queries/resolve-id.ts`
- Modify: `packages/db/src/queries/jsonld.ts`
- Modify: `packages/db/src/queries/feed.ts`
- Modify: `packages/db/src/index.ts` (FeedEvent 타입 갱신 export)
- Test: `packages/db/test/merge-surfaces.test.ts`

**Interfaces:**
- Produces:
  - resolve-id: **agent·term 분기에만**(v1 병합 대상 — node/event/instantiation은 기존 동작 불변, node의 마이그레이션 리다이렉트와 상호작용 없음) gone/not-found 판정 직전에 `getMergeByLoserUuid` 조회 — 매치 시 `{ kind: 'redirect', location: '/id/{type}/{winnerUuid}' }`. **체인**: winner가 또 병합된 경우 resolve 계층에서 최대 10단 추적(마지막 승자 uuid로 1회 리다이렉트, 루프 가드).
  - jsonld: agent·term 문서의 owl:sameAs 조립부(스카우트 실측 지점)에 `listMergesForWinner` 결과의 패자 `/id/{type}/{loserUuid}` URI들을 추가(외부 식별자와 합쳐 중복 제거).
  - feed: `FeedEvent`에 `merge?: { winnerUuid: string; winnerUri: string; loserUuid: string; loserUri: string }` 추가 — action='merge' 행에서 entity_merges를 **(entityType(table_name 매핑), loserUuid=audit entityUuid) 복합 키**로 조건부 LEFT JOIN(a.action='merge'일 때만 매치 — 유니크도 이 조합)해 조립(조인 실패 시 merge 필드 생략, 이벤트는 유지). README 피드 절의 'Plan 13에서 확장' 문구 갱신.

- [ ] **Step 1: 실패 테스트** — 셋업: 시드+에이전트 2명 병합(mergeAgents 실호출). 케이스: (a) resolveEntityId('agent', loserUuid) → redirect(/id/agent/{winnerUuid}), (b) 2단 체인(A→B 병합 후 B→C 병합, A 해석 → C로 1회 리다이렉트), (c) 승자 jsonld에 owl:sameAs로 /id/agent/{loserUuid} 포함(+기존 외부 식별자 공존), (d) 피드에서 action merged+merge 페이로드(4필드), (e) term 병합 후 resolveEntityId('term', loserUuid) → redirect(하드 삭제된 텀).
- [ ] **Step 2~4: TDD → 전체 통과 + typecheck** / **Step 5: 커밋** — `git commit -m "feat(db): merge surfaces — 301 chain, winner sameAs, feed merge payload"`

---

### Task 5: 중복 탐지 워커

**Files:**
- Modify: `packages/integrations/src/queue/contract.ts` (QUEUES.reconcile + ReconcileJobPayload)
- Create: `apps/worker/src/jobs/reconcile.ts`
- Modify: `apps/worker/src/index.ts` (워커 등록 — 크론 없음, 온디맨드)
- Create: `packages/db/src/queries/recon-scan.ts` (탐지 로직 — 워커·테스트 공용)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/recon-scan.test.ts`

**Interfaces:**
- Produces:
  - `QUEUES.reconcile = 'reconcile'`, `ReconcileJobPayload = { entityType: 'agent' | 'term' }`
  - `runReconScan(db, entityType): Promise<{ candidates: number }>` — 자동 병합 절대 금지, 후보 적재만:
    - agents: (i) **정규화 이름 일치** — ko/en 각각 lower·trim·연속 공백 축약·구두점(.,·)제거 후 동일 쌍(live만, 자기 쌍 제외). **NULL/빈 로케일은 매치 후보에서 제외(coalesce 금지 — 시드 다수가 en-only라 ''끼리 허위 전체 매치 위험)**. 근거 'name-match:{ko|en}', score 60; (ii) **외부 식별자 공유** — 같은 (scheme,uri)의 서로 다른 agent 쌍, 근거 'shared-identifier:{scheme}', score 90. **집계는 스캔 내 메모리에서 pair별로 수행 후 단일 insert**(reasons 병합·score 최대값 — insertReconCandidate가 onConflictDoNothing이라 별도 insert 2회로는 병합 불가).
    - terms: 같은 taxonomy 내 정규화 이름 일치(둘 다 machineName 보유면 skip — 시드 텀 보호), score 60.
    - 판단 기록: live agents 수십 규모 — 인덱스 없는 정규화 자기조인 허용(O(n²) 무해).
    - insertReconCandidate로 적재(onConflict 무시 — 재스캔 멱등). merged/dismissed 쌍은 pair 유니크(status 불문)+행 잔존으로 재적재되지 않음. **단 영구 제외는 동일 id 쌍에 한함 — 엔터티 재생성(시드 dup 부활 포함) 시 새 id 쌍으로 후보 재등장(의도된 동작, README 명기)**.
  - 워커: `new Worker<ReconcileJobPayload>(QUEUES.reconcile, job => runReconScan(db, job.data.entityType))` — 등록만, 크론 없음. **현재 트리거 경로 없음(대규모 대비 예비 — Task 6이 인라인 실행 채택): 이 근거를 README에 명기. apps/web에 enqueue 헬퍼는 만들지 않는다.**

- [ ] **Step 1: 실패 테스트** — (a) '백남준'/'백남준 '(공백) 두 agent → 후보 1건(name-match, 정규화 확인), (b) 같은 wikidata URI 공유 두 agent → score 90·shared-identifier 근거, (c) 재스캔 멱등(count 불변), (d) dismissed 쌍 재적재 안 됨, (e) machineName 보유 텀 쌍 skip.
- [ ] **Step 2~4: TDD → 전체 통과** + `pnpm --filter @binder/worker test`(기존 워커 테스트 그린) / **Step 5: 커밋** — `git commit -m "feat(db+worker): reconcile scan — candidate detection (no auto-merge)"`

---

### Task 6: admin 워크벤치 UI + Value Suggest + 시드

**Files:**
- Create: `apps/web/app/(app)/admin/reconciliation/page.tsx`
- Create: `apps/web/components/admin/ReconCandidateRow.tsx`
- Create: `apps/web/lib/actions/admin-reconciliation.ts` (scan 트리거·merge·dismiss)
- Create: `apps/web/app/api/v1/authority-search/route.ts` (GET withAuth — Wikidata 필수·Getty 베스트-에포트)
- Modify: `apps/web/components/admin/AgentIdentifiers.tsx` (검색 보조)
- Modify: `apps/web/app/(app)/admin/layout.tsx` (네비)
- Modify: `packages/db/src/seed/seed-demo.ts` (중복 전거 시드 블록)
- Modify: `apps/web/messages/ko.json`, `en.json`
- Test: web typecheck + db 전체(시드 변경 반영)

**Interfaces:**
- Produces:
  - **워크벤치**: pending 후보 테이블(양쪽 이름·kind·score·근거 뱃지) — 각 행(ReconCandidateRow)에 **`data-a-uuid`/`data-b-uuid` 속성**(ReconCandidateView의 aUuid/bUuid — Task 7 e2e가 loserUuid 취득) + 승자 라디오(`recon-pick-a`/`recon-pick-b`)+병합 버튼(`recon-merge`, confirm)+기각(`recon-dismiss`). 상단 스캔 버튼(`recon-scan-agents`/`recon-scan-terms`) — **Server Action이 runReconScan을 인라인(동기) 실행**(v1 확정 — 데이터 소규모·e2e 안정성; 큐/워커 경유 없음) 후 revalidatePath. Server Actions 전부 admin:manage. merge 액션은 mergeAgents/mergeTerms(candidateId 전달 — 해당 후보 status='merged') + MergeValidationError → { error }.
  - **Value Suggest**: GET `/api/v1/authority-search?q=&source=wikidata|getty` — withAuth 프록시, AbortController 5s: wikidata = `https://www.wikidata.org/w/api.php?action=wbsearchentities&search={q}&language=en&format=json`(User-Agent 헤더 명시) → `[{ label, description?, uri: 'http://www.wikidata.org/entity/'+id, scheme: 'wikidata' }]` 10건; getty = SPARQL `http://vocab.getty.edu/sparql.json`에 ULAN 라벨 검색 쿼리 — **베스트-에포트: 실패·타임아웃 시 빈 배열**(로그만). 어느 소스든 실패는 200 + `{ results: [], sourceError?: true }`.
  - AgentIdentifiers: 검색 인풋(`identifier-suggest-q`)+소스 select+검색 버튼 → 결과 리스트(`identifier-suggest-option`, **텍스트 렌더만 — 링크화 금지**) 클릭 시 uri·scheme 폼 자동 채움 — **자동 채움을 위해 uri·scheme 입력을 제어 컴포넌트(value/onChange state)로 전환**(현행 비제어 입력은 프로그램적 채움 불가). 외부 API 실패 시 "검색 불가" 메시지(폼 수동 입력은 항상 가능).
  - **시드**: seed-demo에 가드 블록 — `nam-june-paik-dup` agent(name ko '백남준', en 'Paik, Nam June') ensureAgent(기존 가드 자연 멱등 — 병합으로 soft-delete되면 재실행 시 부활: 시연 반복 지원, README 문서화).
  - i18n admin.reconciliation 네임스페이스(ko/en).

- [ ] **Step 1: 구현** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 2: Value Suggest 수동 스모크** — dev 서버에서 `curl -s "localhost:3000/api/v1/authority-search?q=Nam+June+Paik&source=wikidata" -H "cookie: …"` 결과 기록(외부 API — 실패 시 빈 배열 확인도 유효한 결과). Getty는 시도 결과를 report에(성공/실패 무관).
- [ ] **Step 3: 커밋** — `git commit -m "feat(web+db): reconciliation workbench + authority search + dup seed"`

---

### Task 7: e2e + 시연 + 마감

**Files:**
- Modify: `apps/web/e2e/core-flows.spec.ts` (신규 1본(통합) — 기존 24 무수정)
- Modify: `packages/db/scripts/clean-e2e-records.ts` (e2e 생성 agent 정리 — `name->>'ko' LIKE 'E2E %'` live만 soft-delete, **AgentInUseError는 catch해 해당 건 skip+로그**(참조 잔존 시 스크립트 전체 중단 방지))
- Modify: `packages/db/README.md` (reconciliation 절 — dismissed 영구 제외는 동일 id 쌍 한정·시드 dup 부활·병합 취소는 감사 스냅샷 수동·워커 예비 근거)
- Modify: `.superpowers/sdd/progress.md` (컨트롤러 작성)

**Interfaces:**
- e2e 1본(리다이렉트+피드 통합 — 외부 API 미의존, 테스트-자체-생성 데이터로 멱등):

```ts
test('reconciliation merge roundtrip with uri redirect and merged feed event', async ({ page }) => {
  await login(page);
  // 1) dup 쌍 생성: 관리>에이전트 AddAgentForm(실존 — name_ko/name_en input + add-agent-submit testid)으로
  //    이름 'E2E 병합대상' 동일 2건 생성(slug 자동 dedupe 확인됨)
  // 2) 병합 전 피드 tail 커서 확보: /api/v1/feed를 nextCursor로 끝까지 전진해 마지막 커서 저장
  // 3) /admin/reconciliation → recon-scan-agents 클릭(인라인 실행 — 즉시 완료) → 'E2E 병합대상' 후보 행 표시
  // 4) 행의 data-a-uuid/data-b-uuid 읽기 → recon-pick-a 선택 → recon-merge(confirm) → 후보 행 사라짐
  // 5) /id/agent/{b-uuid(=loser)} page.request.get(maxRedirects:0) → 301 + Location에 a-uuid 포함
  // 6) merged 피드: 2)의 커서 이후 구간을 expect.poll(마진 3s 경과 재시도, 최대 15s)로 조회 —
  //    action==='merged' && merge.winnerUri/loserUri 4필드 단언
  // 7) 정리(멱등): 승자 agent를 admin에서 삭제 — clean-e2e-records가 잔여 정리
});
```

  주석은 흐름 설계이고 **구현 시 전부 실코드로 완성**(같은 태스크에서 UI 셀렉터 계약 — Task 6의 data-a-uuid/data-b-uuid·recon-* testid — 을 알고 작성하므로 placeholder 아님, Plan 12 Task 7 전례). 두 시나리오(리다이렉트·피드)를 한 테스트로 합쳐 커서 확보→병합→검증의 순서를 보장(피드 단언은 tail 커서 이후 구간 + 마진 대기 — limit=100 오래된-먼저 함정 회피).
- **시연(컨트롤러 별도)**: Value Suggest로 'Nam June Paik' 검색 → ULAN/Wikidata URI 선택 → 백남준 링크(§8 'ULAN 링크' — 외부 API라 e2e 제외, 프리뷰 검증); 시드 dup(백남준 중복) 병합 → /id 301.

- [ ] **Step 1: e2e 작성·실행** — Run: `cd apps/web && pnpm e2e` / Expected: **25/25**(기존 24+통합 1본). 실패 시 완화 금지·원인 보고.
- [ ] **Step 2: clean-e2e-records 확장** — Files 명세대로(패턴·live·catch-skip) + 실행 1회로 잔재 정리.
- [ ] **Step 3: 문서** — README reconciliation 절.
- [ ] **Step 4: 전체 게이트** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test` / Expected: 그린
- [ ] **Step 5: 커밋** — `git commit -m "test(web): reconciliation e2e + docs"` (푸시는 컨트롤러 최종 리뷰 후)

---

## 자기 리뷰 체크 (적대적 리뷰 2렌즈 반영 완료)

- 스펙 §3.3 전수: 배치 탐지(T5 — 이름 정규화·외부 식별자, 연대 미포함 근거=agents 생몰년 부재 실측), 인간 승인 큐(T6), 자동 병합 금지, 병합 이전 목록(**entity_relations 양방향 — performed_by는 agent가 target** + FK 전수 실측 지도), 패자 URI 영구 리다이렉트(T4 체인), sameAs(T4 — 기록 실체는 entity_merges·발행 시 조립: 스펙과 정합 판정), merged 감사(**writeAudit 확장으로 rowSnapshot 저장 — 스펙 '병합 전 스냅샷' 이행**)·피드(T4), 병합 취소=감사 스냅샷 수동. §3.2 Value Suggest(T6). §8 잔여 시연: 병합→구 URI 리다이렉트+merged 피드(e2e 통합 1본 — tail 커서+마진 대기로 결정적), ULAN 링크(컨트롤러 시연).
- 블로커 해소: entity_relations 양방향 이전(+self-relation·기간 관계 의미론), writeAudit merge 지원(Files에 audit.ts 포함).
- 결정 명시: scan 인라인 실행(T6 확정 — 워커·큐는 예비 유지+README 근거, enqueue 헬퍼 없음), 후보 status 'merged'는 candidateId 경로로 실사용, dismissed 영구 제외는 동일 id 쌍 한정(재생성 시 재등장 — README), machineName 텀 병합 금지, terms 참조 잔여 0 검증은 13 FK 기준 신규 작성(기존 deleteTerm 11곳 복붙 금지), nodeTerms 중복은 카운트만(복합 PK — 행 감사 불가).
- 인터페이스 계약: ReconCandidateView(aLabel/bLabel·aUuid/bUuid)·InsertReconCandidateInput·transferStats 키 규칙(`<table>.<column>`+특수 4키)·data-a-uuid/data-b-uuid(T6↔T7)·FeedEvent.merge 4필드(T4↔T7).
- XSS 선행 조건 이행: authority-search 결과 텍스트 렌더만, uri는 기존 서버 검증(http/https) 경유 저장, 자동 채움은 제어 컴포넌트로.
- YAGNI: 자동 병합·언두·폴링·연대 유사도·node 병합 없음.
