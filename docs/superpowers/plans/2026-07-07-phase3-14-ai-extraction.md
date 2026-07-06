# Plan 14 — P3.3 AI 추출 파이프라인 Implementation Plan (적대적 리뷰 반영판)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 기술문·CSV·수동 텍스트에서 LLM으로 트리플 후보를 추출하고, 스키마 검증(Self-Correction)·IM 그라운딩을 거쳐 인간 검수(승인/수정/반려) 승인분만 `entity_relations(origin='extraction')`로 커밋하는 파이프라인 — 전 계보(XAI) 역추적 가능.

**Architecture:** 파이프라인 코어는 `packages/db/src/extract/`의 순수 함수(runExtractJob — LLM은 인터페이스 주입). Anthropic 클라이언트·Fake 클라이언트는 `packages/integrations/src/llm/`. v1 실행 경로는 **Server Action 인라인**(Plan 13 reconcile 전례 — 소규모 입력·e2e 결정성·web-Redis 무결합), EXTRACT 큐+워커 잡은 스캐폴딩으로 등록(대용량 DIP 입력 도입 시 전환). 검수 큐 UI는 `/admin/extraction`.

**Tech Stack:** Drizzle+PG16(0030), @anthropic-ai/sdk(structured outputs — 동적 relation enum 스키마 강제), BullMQ(예비), Next 15 Server Actions, csv-parse, Playwright(fake provider).

## Global Constraints (스펙 §4·§7 + BINDING 컨벤션)

- **자동 커밋 절대 금지**: 그래프에는 검수 통과분만. runExtractJob은 extract_candidates 삽입까지만 — createRelation 호출 0.
- **XAI(D7)**: extract_jobs에 provider·model ID·params·prompt(sha256 해시+스냅샷 원문)·**attempt별 검증 오류**(성공 경로 포함) 기록. 커밋된 관계는 candidate→job으로 역추적(candidate.committedRelationId + relation 감사 jobRef).
- **스키마 주입 프롬프트**: relation_types+SKOS+전거 요약을 DB에서 동적 구성 — 관계 목록 하드코딩 금지(테스트로 강제). SKOS 범위는 승격 6종만.
- **Self-Correction**: maxRetries=**재시도** 횟수(총 LLM 호출 = 1+maxRetries), 기본 2(env `EXTRACT_SELF_CORRECTION_RETRIES`). 소진 시 job status='failed'+error 가시화(목록+트리거 UX 양쪽).
- **unmatched-entity 보존**: 그라운딩 실패는 폐기 아님 — 후보로 저장, 검수에서 신규 전거 생성 또는 기존 연결로 승격.
- **LLM 규칙(claude-api)**: 기본 모델 `claude-opus-4-8`(env `EXTRACT_MODEL`), 샘플링 파라미터 전송 금지(400), 프리필 금지, `output_config.format` json_schema(모든 object에 `additionalProperties:false`+`required`; nullable은 **anyOf** 형태 — type 배열 유니온 회피), max_tokens 기본 16000(env — 16K 초과 상향은 비스트리밍 SDK 타임아웃 위험, README 명시).
- **키 유출 방지**: selectExtractLlm 호출은 'use server' 액션과 worker에만. **클라이언트 컴포넌트에서 @binder/integrations import 금지.**
- Phase 2 컨벤션 전면: 변이는 Server Actions만(admin:manage), '.js' ESM(스키마 내부 무확장), 클라이언트는 @binder/core barrel 금지+@binder/db type-only, 단일 시계(SQL now()), i18n ko/en 중첩키(dot 금지), LLM 산출 텍스트(subject/object/evidence)는 React 텍스트 노드로만 렌더(dangerouslySetInnerHTML 금지), 마이그레이션은 drizzle-kit generate만, 커밋 트레일러 `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`, dev DB 변형 스크립트 금지.
- 게이트: `pnpm -r typecheck && pnpm --filter @binder/db test`(BASE 352) / worker는 `pnpm --filter worker test` / e2e는 `cd apps/web && pnpm e2e`(BASE 25본 무수정).
- BASE: 6e9471c.

**리뷰 반영 핵심 결정(원장 기록 대상):**
- R1. 검수 '수정' 1급 지원(§4.7·§6) — updateExtractCandidate(관계 유형·날짜·uncertain 패치) + 인라인 수정 폼. 승격(그라운딩 변경)과 별개 기능.
- R2. promptSnapshot이 유일 진실 — runExtractJob은 재빌드하지 않고 sha256(promptSnapshot)===promptHash 무결성만 검증, relationMachines도 스냅샷 schema enum에서 도출.
- R3. origin 표시 표면 신설 — RelationList origin 뱃지(스펙 §8 시연 표면). audit origin은 'ingest'로 접히는 기존 매핑 유지(문서화), 표시는 relation 행의 origin 컬럼이 담당.
- R4. 커밋 감사 잡 연계는 jobRef(기존 컨벤션 — aips.ts 전례), actorRef 미사용.
- R5. uncertain은 커밋 시 entity_relations.certainty='uncertain'으로 전달(소실 방지).

---

### Task 1: extract_jobs + extract_candidates 스키마 + 쿼리

**Files:**
- Create: `packages/db/src/schema/extraction.ts`
- Create: `packages/db/src/queries/extraction.ts`
- Modify: `packages/db/src/schema/index.ts`, `packages/db/src/index.ts` (barrel 등록 — 기존 스타일 모방)
- Migration: `pnpm --filter @binder/db db:generate` → `0030_*.sql` (수기 작성 금지)
- Test: `packages/db/test/extraction-schema.test.ts`

**Interfaces:**
- Consumes: users, relationTypes, entityTypeEnum(기존 'node'|'agent'|'event'|'term'|'instantiation' — 후보 그라운딩은 agent/term/node 3종만 사용, 컬럼 타입은 재사용), entityRelations.
- Produces (후속 태스크가 의존하는 정확 시그니처):

```ts
// schema/extraction.ts
export const extractInputKindEnum = pgEnum('extract_input_kind', ['manual_text', 'node_description', 'csv']);
export const extractJobStatusEnum = pgEnum('extract_job_status', ['received', 'extracting', 'validating', 'grounding', 'review', 'failed']);
export const extractCandidateStatusEnum = pgEnum('extract_candidate_status', ['pending', 'approved', 'rejected']);

export const extractJobs = pgTable('extract_jobs', {
  id: uuid('id').primaryKey().defaultRandom(),
  inputKind: extractInputKindEnum('input_kind').notNull(),
  inputRef: text('input_ref'),                       // node slug 등 출처 표시(선택)
  inputText: text('input_text').notNull(),           // 실제 추출 대상 텍스트(CSV는 직렬화본)
  status: extractJobStatusEnum('status').notNull().default('received'),
  attempt: integer('attempt').notNull().default(1),  // 총 LLM 호출 수(1+재시도)
  attemptErrors: jsonb('attempt_errors').$type<string[][]>(), // attempt별 검증 오류(성공 경로에도 기록 — XAI 재구성)
  error: jsonb('error').$type<Record<string, unknown>>(),
  // XAI(D7)
  provider: text('provider').notNull(),              // 'anthropic' | 'fake'
  model: text('model').notNull(),
  modelParams: jsonb('model_params').$type<Record<string, unknown>>(),
  promptHash: text('prompt_hash').notNull(),         // sha256(promptSnapshot)
  promptSnapshot: text('prompt_snapshot').notNull(), // ExtractPrompt JSON 직렬화 — 유일 진실(R2)
  requestedBy: integer('requested_by').references(() => users.id),
  startedAt: timestamp('started_at', { withTimezone: true }).notNull().defaultNow(),
  finishedAt: timestamp('finished_at', { withTimezone: true }),
});

export const extractCandidates = pgTable('extract_candidates', {
  id: serial('id').primaryKey(),
  jobId: uuid('job_id').notNull().references(() => extractJobs.id),
  subjectText: text('subject_text').notNull(),
  subjectEntityType: entityTypeEnum('subject_entity_type'), // null = unmatched
  subjectEntityId: integer('subject_entity_id'),
  relationTypeId: integer('relation_type_id').notNull().references(() => relationTypes.id),
  objectText: text('object_text').notNull(),
  objectEntityType: entityTypeEnum('object_entity_type'),
  objectEntityId: integer('object_entity_id'),
  startDate: date('start_date'),
  endDate: date('end_date'),
  uncertain: boolean('uncertain').notNull().default(false),
  evidence: text('evidence').notNull(),              // 근거 출처 필수(스펙 §4.8)
  status: extractCandidateStatusEnum('status').notNull().default('pending'),
  reviewNote: text('review_note'),
  reviewedBy: integer('reviewed_by').references(() => users.id),
  reviewedAt: timestamp('reviewed_at', { withTimezone: true }),
  committedRelationId: integer('committed_relation_id').references(() => entityRelations.id), // 단방향 — 순환 없음(검증됨)
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});
```

```ts
// queries/extraction.ts — 전부 DbExecutor 수용(tx 호환)
export type CreateExtractJobInput = {
  inputKind: 'manual_text' | 'node_description' | 'csv';
  inputRef?: string | null; inputText: string;
  provider: string; model: string; modelParams?: Record<string, unknown>;
  promptHash: string; promptSnapshot: string; requestedBy: number | null;
};
export async function createExtractJob(db, input: CreateExtractJobInput): Promise<{ id: string }>;
export async function updateExtractJobStatus(db, jobId: string, status: ExtractJobStatus,
  patch?: { error?: Record<string, unknown>; attempt?: number; attemptErrors?: string[][]; finished?: boolean }): Promise<void>;
  // finished=true → finishedAt = sql`now()` (단일 시계 — JS Date 금지)
export async function getExtractJob(db, jobId: string): Promise<ExtractJobRow | null>;
export async function listExtractJobs(db, opts?: { limit?: number }): Promise<ExtractJobListItem[]>;
  // startedAt desc. select 컬럼 축소 — promptSnapshot/inputText 제외(목록 페이로드)
export type InsertCandidateInput = {
  jobId: string; subjectText: string; subjectEntityType?: EntityType | null; subjectEntityId?: number | null;
  relationTypeId: number; objectText: string; objectEntityType?: EntityType | null; objectEntityId?: number | null;
  startDate?: string | null; endDate?: string | null; uncertain?: boolean; evidence: string;
};
export async function insertExtractCandidates(db, rows: InsertCandidateInput[]): Promise<number>;
export async function listExtractCandidates(db, opts: { status?: CandidateStatus; jobId?: string }): Promise<ExtractCandidateView[]>;
  // View: relationTypes 조인(machineName·labelForward)+양측 grounded 라벨.
  // 라벨 조인은 reconciliation.ts fetchLabelsAndUuids '패턴 모방'(비공개 함수·agent/term만 — node title 분기는 신규 작성임을 인지).
export async function updateExtractCandidate(db, candidateId: number, actorId: number, patch: {
  relationTypeId?: number; startDate?: string | null; endDate?: string | null; uncertain?: boolean;
}): Promise<void>;
  // R1(스펙 §4.7 '수정'): pending 한정(아니면 throw), 변경분 writeAudit(action 'update', changes, entityUuid 없음 — 후보는 발행 대상 아님).
  // evidence·subjectText/objectText는 수정 불가(추출 원본 보존 — 수정하고 싶으면 반려 후 재추출; 그라운딩 변경은 promote가 담당).
export async function countPendingCandidates(db): Promise<number>; // 대시보드 위젯용
```

- [ ] **Step 1: 실패 테스트 작성** — `extraction-schema.test.ts`: (a) createExtractJob→getExtractJob 라운드트립(XAI 필드·attemptErrors 보존), (b) insertExtractCandidates 벌크+listExtractCandidates(status 필터·조인 라벨 — agent/term/node 3종 각 1건), (c) updateExtractJobStatus finished→finishedAt not null, (d) updateExtractCandidate: pending 패치 성공+감사 행, 비-pending throw, (e) countPendingCandidates, (f) listExtractJobs가 promptSnapshot을 포함하지 않음.
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- extraction` / Expected: FAIL (모듈 없음)
- [ ] **Step 3: 스키마+쿼리 구현 → `pnpm --filter @binder/db db:generate`** — 0030 생성 확인(수기 편집 금지).
- [ ] **Step 4: 통과 확인** — Run: `pnpm --filter @binder/db test` / Expected: 352+신규 전부 PASS
- [ ] **Step 5: 커밋** — `feat(db): extract_jobs + extract_candidates schema and queries (0030)`

---

### Task 2: 스키마 주입 프롬프트 빌더 + 트리플 타입 + 검증기(Self-Correction 드라이버)

**Files:**
- Create: `packages/db/src/extract/types.ts`, `packages/db/src/extract/prompt.ts`, `packages/db/src/extract/validate.ts`
- Modify: `packages/db/src/index.ts` (barrel)
- Test: `packages/db/test/extract-prompt.test.ts`, `packages/db/test/extract-validate.test.ts`

**Interfaces:**
- Consumes: listRelationTypes(machineName·labelForward·labelInverse), listTaxonomies, listAgents.
- Produces:

```ts
// extract/types.ts
export type RawTriple = {
  subject: string; relation: string; object: string;
  startDate?: string | null; endDate?: string | null;
  uncertain?: boolean; evidence: string;
};
export type ExtractPrompt = { system: string; user: string; schema: Record<string, unknown> };
export interface ExtractLlm {
  extract(prompt: ExtractPrompt): Promise<string>;
}

// extract/prompt.ts
export const SKOS_PROMPT_TAXONOMIES = [
  'level_of_description', 'media_type', 'department', 'classification', 'component_type', 'instantiation_role',
] as const; // 승격 6종 — apps/web SKOS_TAXONOMIES와 동일 목록(웹 상수는 db를 import 못 하므로 여기 명기가 원본이 되도록 README에 드리프트 주의 기록)
export async function buildExtractPrompt(db, inputText: string): Promise<ExtractPrompt>;
// system: 역할·제약(스키마 내 관계만, 날짜 YYYY-MM-DD 또는 YYYY, 불확실하면 uncertain:true,
//   evidence는 입력 원문 발췌 필수, 개체명은 원문 표기 그대로) +
//   [동적] 관계유형 목록(machine·정/역 라벨 ko/en) + SKOS 어휘 요약(SKOS_PROMPT_TAXONOMIES 필터 — 시스템 어휘(note_type 등) 제외) +
//   전거 요약(live agents 이름 최대 50)
// user: inputText
// schema: { type:'object', additionalProperties:false, required:['triples'], properties:{ triples:{ type:'array',
//   items:{ type:'object', additionalProperties:false,
//     required:['subject','relation','object','evidence','uncertain','startDate','endDate'],
//     properties:{ subject:{type:'string'}, relation:{type:'string', enum:[...machine names 동적]},
//       object:{type:'string'}, evidence:{type:'string'}, uncertain:{type:'boolean'},
//       startDate:{ anyOf:[{type:'string'},{type:'null'}] }, endDate:{ anyOf:[{type:'string'},{type:'null'}] } } } } } }
// (R 반영: nullable은 anyOf — type 배열 유니온 회피. minLength 불가 — 빈 문자열 검사는 validate에서)
export function relationMachinesFromPrompt(prompt: ExtractPrompt): Set<string>;
// R2: 스냅샷 schema의 relation enum에서 도출 — 라이브 DB가 아니라 기록된 프롬프트가 진실.

// extract/validate.ts
export type ValidationResult = { ok: true; triples: RawTriple[] } | { ok: false; errors: string[] };
export function parseAndValidate(rawJson: string, relationMachines: Set<string>): ValidationResult;
// 검사: JSON 파스 → triples 배열 → 각 항목: subject/object/evidence trim 후 비어있지 않음,
//   relation ∈ relationMachines(이중 방어 — fake는 스키마 비강제, 실 클라이언트도 방어), subject !== object,
//   날짜 null 또는 /^\d{4}(-\d{2}-\d{2})?$/ (YYYY형은 date 컬럼 저장 시 YYYY-01-01 변환 — 변환은 파이프라인 삽입부),
//   endDate < startDate 거부. 오류 메시지는 Self-Correction 피드백에 그대로 삽입 가능한 형태.
export function runSelfCorrectingExtraction(
  llm: ExtractLlm, prompt: ExtractPrompt, relationMachines: Set<string>, maxRetries: number,
): Promise<{ triples: RawTriple[]; attempts: number; attemptErrors: string[][] }>;
// 의미론 고정: maxRetries=재시도 횟수, 총 호출=1+maxRetries, 반환 attempts=총 호출 수.
// attemptErrors: 각 실패 attempt의 오류 배열(성공 attempt는 기록 없음) — 성공 경로에서도 job에 저장(XAI).
// 재시도 user = 원 user + '\n\n[이전 응답 오류]\n{errors}\n오류를 수정해 동일 스키마로 재출력하세요.'
// 소진 시 throw ExtractValidationError(errors, attemptErrors).
export class ExtractValidationError extends Error {
  constructor(public errors: string[], public attemptErrors: string[][]) { super(errors.join('; ')); }
}
```

- [ ] **Step 1: 실패 테스트** — prompt: (a) 시드 relation machine 전부가 schema enum에 포함(+임시 relation type 삽입 후 재빌드 시 enum 반영 — 하드코딩 방지 앵커), (b) system에 SKOS 6종 라벨 포함 **그리고 note_type 등 비-SKOS 택소노미 미포함**, (c) user===inputText, (d) relationMachinesFromPrompt가 schema에서 정확 도출. validate: 유효/빈 subject/미등록 relation/자기관계/역전 날짜/YYYY 연도형/anyOf null 각 케이스 + runSelfCorrectingExtraction: 1회 실패 후 성공 FakeLlm(클로저 카운터)로 attempts=2·attemptErrors 길이 1, 전부 실패 시 ExtractValidationError(maxRetries=2 → 총 호출 3 확인).
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- extract-` / Expected: FAIL
- [ ] **Step 3: 구현**
- [ ] **Step 4: 통과 확인** — Run: `pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 5: 커밋** — `feat(db): schema-injected extraction prompt + triple validation with self-correction`

---

### Task 3: IM 그라운딩 (퍼지 매칭 + unmatched 보존)

**Files:**
- Create: `packages/db/src/extract/ground.ts`
- Test: `packages/db/test/extract-ground.test.ts`

**Interfaces:**
- Consumes: pg_trgm(0015 활성), agents.name(I18nText)·terms.name·nodes.title.
- Produces:

```ts
export type GroundingHit = { entityType: 'agent' | 'term' | 'node'; entityId: number; score: number; label: string };
export async function groundName(db, name: string, opts?: { threshold?: number }): Promise<GroundingHit | null>;
// threshold 기본: Number(process.env.EXTRACT_GROUND_THRESHOLD ?? 0.4) — 파라미터 주입 가능(테스트).
// 1) 정확 일치(정규화: lower·trim·공백 축약): agents/terms/nodes의 ko/en — live만.
//    우선순위 agent > term > node(전거 우선), 동일 타입 내 동명 다건은 ORDER BY id LIMIT 1(결정적 타이브레이크 —
//    시드에 '백남준' 라이브 2건(nam-june-paik + dup) 실존하므로 필수).
// 2) 퍼지: 단일 raw SQL로 3 테이블 UNION ALL, similarity(lower(name_locale), lower(${name})) 최대값,
//    임계 이상 최고점 1건(동점은 타입 우선순위→id). 파라미터 바인딩 필수(원문 보간 금지 — Plan 12 교훈).
// 3) 미달 → null (호출측이 unmatched로 보존).
export async function groundTriples(db, triples: RawTriple[]): Promise<Array<{
  triple: RawTriple; subject: GroundingHit | null; object: GroundingHit | null;
}>>;
// 유니크 이름 셋으로 groundName 1회씩 → 매핑.
```

- [ ] **Step 1: 실패 테스트** — (a) 정확 일치(ko), (b) 정확 일치(en·대소문자/공백 변형), (c) **동명 라이브 2건 → 최저 id 반환(결정성)**, (d) 퍼지 일치(긴 이름 부분 변형 — 시드 실측으로 케이스 확정, 임계는 opts 주입으로 캘리브레이션 근거 기록), (e) 임계 미달 → null, (f) soft-deleted agent 제외, (g) agent가 node보다 우선, (h) groundTriples 배치 dedup.
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- extract-ground` / Expected: FAIL
- [ ] **Step 3: 구현**
- [ ] **Step 4: 통과 확인** — Run: `pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 5: 커밋** — `feat(db): IM grounding — exact + pg_trgm fuzzy entity matching`

---

### Task 4: 파이프라인 오케스트레이션 + 커밋 경로 + XAI 계보

**Files:**
- Create: `packages/db/src/extract/pipeline.ts`, `packages/db/src/extract/commit.ts`
- Modify: `packages/db/src/queries/entity-relations.ts` (createRelation opts에 `jobRef?: string` 추가 — writeAudit로 통과. 기존 동작 불변)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/extract-pipeline.test.ts`, `packages/db/test/extract-commit.test.ts`

**Interfaces:**
- Consumes: Task 1 쿼리, Task 2 prompt/validate(relationMachinesFromPrompt), Task 3 ground, createRelation, writeAudit.
- Produces:

```ts
// pipeline.ts
export async function runExtractJob(db, llm: ExtractLlm, jobId: string, opts?: { maxRetries?: number }):
  Promise<{ candidates: number; status: 'review' | 'failed' }>;
// R2 확정 흐름(재빌드 없음):
//   getExtractJob(status 'received' 아니면 throw)
//   → 무결성: sha256(job.promptSnapshot) !== job.promptHash 이면 status 'failed'+error{reason:'prompt-integrity'} → { candidates:0, status:'failed' }
//   → prompt = JSON.parse(job.promptSnapshot); machines = relationMachinesFromPrompt(prompt)
//   → status 'extracting' → 'validating': runSelfCorrectingExtraction(llm, prompt, machines, maxRetries ?? env 기본 2)
//     - ExtractValidationError → status 'failed'+error{errors}+attemptErrors+attempt+finished → { candidates:0, status:'failed' }
//     - 기타 LLM throw(네트워크 등) → status 'failed'+error{message}+finished 후 재던짐(큐 경로 재시도 정책 대상; 인라인 경로는 액션이 catch)
//   → 성공: attempt·attemptErrors 기록(성공 경로에도 — XAI)
//   → status 'grounding': groundTriples
//   → 삽입: machine→relationTypeId 매핑(listRelationTypes 1회) — 매핑 실패(스냅샷 이후 관계 유형 삭제)는
//     status 'failed'+error{reason:'relation-type-missing', machine} (부분 삽입 금지 — 전량 원자)
//     YYYY형 날짜는 YYYY-01-01로 변환해 date 컬럼 저장.
//   → status 'review'+finished → { candidates: n, status: 'review' }
// {"triples":[]} 유효 응답은 review+0건 — failed와 구분됨(반환 status로 액션이 분기).

// commit.ts
export class CandidateCommitError extends Error {} // 'not-pending' | 'unmatched-endpoint' | ...
export async function approveAndCommitCandidate(db, candidateId: number, actorId: number): Promise<{ relationId: number }>;
// tx(중첩 시 merge.ts:253의 `(tx as unknown as PostgresJsDatabase).transaction` savepoint 캐스트 패턴 참조):
//   단일 UPDATE ... WHERE id=? AND status='pending' RETURNING 으로 선점(이중 커밋 원자 차단; 실패 시 'not-pending')
//   → 양측 grounded 필수: null 있으면 롤백+'unmatched-endpoint'(승격 먼저)
//   → createRelation(db, { sourceType, sourceId, targetType, targetId, relationTypeMachine, startDate?, endDate?,
//        certainty: candidate.uncertain ? 'uncertain' : undefined,   // R5 — 불확실성 소실 방지
//        evidence: candidate.evidence }, actorId,
//        { origin: 'extraction', actorKind: 'user', jobRef: `extract:${jobId}` })  // R4 — 잡 연계는 jobRef 컨벤션
//     (의미론: 커밋 주체는 인간 승인자 — actorId·actorKind 'user'. AI 계보는 origin+jobRef+candidate 체인.
//      주의: createRelation은 audit origin을 'ingest'로 접는다(기존 매핑) — 표시는 relation.origin 컬럼이 담당(R3), README 문서화.)
//   → 후보 UPDATE: status 'approved', reviewedBy/At(SQL now()), committedRelationId
//   → 후보 상태 변화 감사: writeAudit({ tableName:'extract_candidates', action:'update', changes, ... })
export async function rejectCandidate(db, candidateId: number, actorId: number, reason: string): Promise<void>;
// pending→rejected + reviewNote(사유 필수 — 스펙 §4.7) + 감사.
export async function promoteUnmatched(db, candidateId: number, side: 'subject' | 'object',
  resolution: { kind: 'create-agent'; agentKind: 'person' | 'organization'; name: I18nText }
            | { kind: 'link'; entityType: 'agent' | 'term' | 'node'; entityId: number },
  actorId: number): Promise<void>;
// create-agent: slug `extracted-{uuid 앞 8}`(한글 slug 함정 회피) + 감사 insert.
// link: 대상 존재 검증. 후보 해당 side UPDATE + 감사. pending 한정.
```

- [ ] **Step 1: 실패 테스트** — pipeline: FakeLlm(고정 트리플)로 end-to-end — received→review, grounded/unmatched 혼재 픽스처, attempt·attemptErrors 기록(재시도 성공 케이스 포함), 검증 실패 LLM → { status:'failed' }+error+attemptErrors, promptHash 불일치 → prompt-integrity failed, YYYY→YYYY-01-01 변환, {"triples":[]} → review+0건. commit: 승인 커밋(entity_relations origin='extraction'+evidence+**certainty 'uncertain'**(uncertain 후보)+감사 jobRef 검증, 후보 approved+committedRelationId), unmatched 커밋 거부, 이중 커밋 원자 차단(2연속 두 번째 'not-pending'), reject 사유 필수, updateExtractCandidate 수정 후 승인(수정된 relationTypeId로 커밋 — R1 경로), promoteUnmatched 양 분기, **계보 역추적**: relation id → candidate → job(promptSnapshot·attemptErrors) 조인으로 입력→모델→후보→검수자→커밋 체인 단언.
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- extract-pipeline` 및 `-- extract-commit` / Expected: FAIL
- [ ] **Step 3: 구현**
- [ ] **Step 4: 통과 확인** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 5: 커밋** — `feat(db): extraction pipeline orchestration + human-gated commit with XAI lineage`

---

### Task 5: Anthropic/Fake LLM 클라이언트 + EXTRACT 큐 + 워커 잡(스캐폴딩)

**Files:**
- Create: `packages/integrations/src/llm/extract-llm.ts`
- Modify: `packages/integrations/src/index.ts`(barrel), `packages/integrations/package.json`(**+ `@anthropic-ai/sdk`, + `"@binder/db": "workspace:*"`** — 현재 fast-xml-parser만 의존, db→integrations 역의존 없어 순환 없음(실측))
- Modify: `packages/integrations/src/queue/contract.ts` (QUEUES.extract + ExtractQueueJobPayload)
- Create: `apps/worker/src/jobs/extract.ts`
- Modify: `apps/worker/src/index.ts` (워커 등록 — 크론 없음)
- Modify: `.env.example` + `apps/web/.env.local.example` (ANTHROPIC_API_KEY, EXTRACT_PROVIDER, EXTRACT_MODEL, EXTRACT_MAX_TOKENS, EXTRACT_SELF_CORRECTION_RETRIES, EXTRACT_GROUND_THRESHOLD — 웹 인라인 경로는 apps/web env를 읽으므로 양쪽 필요)
- Test: `apps/worker/test/extract-job.test.ts`, integrations 테스트(패키지 실명·테스트 스크립트 존재를 먼저 실측 — 없으면 vitest 설정 추가가 아니라 db 테스트 쪽에 배치해도 무방, report에 근거 기록)

**Interfaces:**
- Consumes: ExtractLlm/ExtractPrompt(@binder/db), runExtractJob.
- Produces:

```ts
// integrations/src/llm/extract-llm.ts
import Anthropic from '@anthropic-ai/sdk';
export class AnthropicExtractLlm implements ExtractLlm {
  constructor(private opts: { apiKey?: string; model: string; maxTokens: number }) {}
  async extract(prompt: ExtractPrompt): Promise<string> {
    const client = new Anthropic(this.opts.apiKey ? { apiKey: this.opts.apiKey } : {});
    const res = await client.messages.create({
      model: this.opts.model,               // 기본 'claude-opus-4-8'
      max_tokens: this.opts.maxTokens,      // 기본 16000
      system: prompt.system,
      messages: [{ role: 'user', content: prompt.user }],
      output_config: { format: { type: 'json_schema', schema: prompt.schema } },
    });
    const text = res.content.find((b) => b.type === 'text');
    if (!text) throw new Error(`extract: no text block (stop_reason=${res.stop_reason})`);
    return text.text;
  }
}
export class FakeExtractLlm implements ExtractLlm {
  // 결정적: prompt.user에서 `[[TRIPLE subject|relation|object|evidence]]` 마커 파싱 →
  // {triples:[{...uncertain:false,startDate:null,endDate:null}]} JSON. 마커 0건 → {"triples":[]}.
  // [[BAD-RELATION ...]] → relation 'no-such-relation'(재시도에도 동일 — failed 경로 재현).
  async extract(prompt: ExtractPrompt): Promise<string>;
}
export function selectExtractLlm(env: NodeJS.ProcessEnv): ExtractLlm;
// EXTRACT_PROVIDER 'fake' → Fake; 그 외('anthropic' 기본) → Anthropic({ apiKey: env.ANTHROPIC_API_KEY,
//   model: env.EXTRACT_MODEL ?? 'claude-opus-4-8', maxTokens: Number(env.EXTRACT_MAX_TOKENS ?? 16000) })

// queue/contract.ts 추가
export const QUEUES = { ...기존, extract: 'extract' } as const;
export type ExtractQueueJobPayload = { extractJobId: string };

// apps/worker/src/jobs/extract.ts
export async function runExtractQueueJob(db: PostgresJsDatabase, payload: ExtractQueueJobPayload): Promise<void>;
// selectExtractLlm(process.env) → runExtractJob(db, llm, payload.extractJobId,
//   { maxRetries: Number(process.env.EXTRACT_SELF_CORRECTION_RETRIES ?? 2) })
// index.ts 등록: reconcile 패턴(failed 핸들러·shutdown, 크론·enqueue 헬퍼 없음 — v1 인라인, 근거 Task 7 README).
```

- [ ] **Step 1: 실패 테스트** — FakeExtractLlm 마커 파싱(2건·0건·BAD-RELATION), selectExtractLlm 분기. Anthropic은 실호출 없이 인스턴스화만(요청 페이로드 구성 검증은 분리 가능하면 단위, 아니면 생략 명기). worker: FakeLlm로 runExtractQueueJob이 테스트 DB job을 review까지 전이(기존 worker 테스트의 DB 사용 방식 실측 후 동일 패턴).
- [ ] **Step 2: 실패 확인** — Run: worker/integrations 테스트 / Expected: FAIL
- [ ] **Step 3: 구현** (pnpm add — packages/integrations)
- [ ] **Step 4: 통과 확인** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test && pnpm --filter worker test` + integrations 테스트 / Expected: PASS
- [ ] **Step 5: 커밋** — `feat(integrations+worker): anthropic/fake extract llm + EXTRACT queue scaffolding`

---

### Task 6: 추출 트리거 + 검수 큐 UI(승인/수정/반려/승격) + origin 뱃지 + 대시보드 위젯

**Files:**
- Create: `apps/web/app/(app)/admin/extraction/page.tsx` (server component, force-dynamic)
- Create: `apps/web/components/admin/ExtractTriggerForm.tsx` (client — useActionState, pending 동안 제출 비활성화)
- Create: `apps/web/components/admin/ExtractCandidateRow.tsx` (client)
- Create: `apps/web/components/dashboard/ReviewBacklogCard.tsx` (+ 대시보드 page.tsx 배치)
- Create: `apps/web/lib/actions/admin-extraction.ts`
- Modify: `apps/web/components/records/RelationList.tsx` (**R3: origin 뱃지** — RelationView.origin이 이미 props로 도달(실측), origin!=='user'일 때 뱃지 렌더 `data-testid="relation-origin-{origin}"`, evidence는 title 툴팁. 변경량 소 — 기존 24 e2e 무영향 확인)
- Modify: `apps/web/app/(app)/admin/layout.tsx` (네비 — reconciliation 다음)
- Modify: `apps/web/package.json` (+ `csv-parse`)
- Modify: `apps/web/messages/ko.json`, `en.json` (admin.extraction ns + records.relationOrigin ns)
- Test: `pnpm -r typecheck` + db 전체(회귀)

**Interfaces:**
- Consumes: Task 1 쿼리(updateExtractCandidate 포함)·Task 4 pipeline/commit·Task 5 selectExtractLlm(**서버 전용** — 클라이언트 컴포넌트에서 @binder/integrations import 금지), getNodeDetail, entity-search GET 라우트, requireUser/hasPermission.
- Produces:
  - **트리거 폼**: 입력 소스 select(`extract-input-kind`) — manual: textarea(`extract-input-text`); node: slug 입력(`extract-input-node`); csv: file input(`extract-input-file`). 제출(`extract-trigger-submit`).
  - **extractTriggerAction**(admin:manage): 입력 조립 — manual 그대로 / node: getNodeDetail(slug).node.descriptive+title 직렬화(없으면 { error:'node-not-found' }) / csv: `parse(buffer, { columns: true, bom: true })`(csv-parse/sync) 행별 `컬럼: 값; ...` 직렬화, 행 상한 200(파싱 후 검사 — 파싱 폭탄의 실질 상한은 Next Server Action bodySizeLimit 기본 1MB, 초과는 Next 레벨 거절이라 친절한 에러 불가함을 인지) → buildExtractPrompt → createExtractJob(promptHash=sha256(promptSnapshot), promptSnapshot=JSON.stringify(prompt), provider/model/modelParams는 selectExtractLlm 구성값) → **runExtractJob 인라인**(v1) → 반환 status 분기: 'failed' → { error: 'extract-failed' }(job 목록에도 가시화 — 스펙 §7), 'review' → { info: '후보 N건 생성' }(신규 삽입 수 그대로 — 오해 없음) → revalidatePath.
  - **검수 큐 테이블**: pending 행(`tr[data-candidate-id]`) — subject/object(grounded 라벨+타입 뱃지 또는 `unmatched` 뱃지 `cand-unmatched-subject`/`-object`)·relation 정라벨·uncertain 뱃지·evidence 툴팁·잡 링크. 행 액션: 승인(`cand-approve`, confirm — 서버가 'unmatched-endpoint'면 { error }), **수정(`cand-edit` — 인라인 폼: 관계 유형 select(전체 relation_types)+start/end date+uncertain 체크 → candidateEditAction, R1)**, 반려(`cand-reject`+사유 `cand-reject-reason` 필수), 승격(`cand-promote-subject`/`-object` — 신규 전거(name ko/en+kind) 또는 기존 연결(entity-search 자동완성, entityType+entityId)).
  - **Server Actions**: candidateApproveAction(approveAndCommitCandidate — CandidateCommitError → { error }), candidateEditAction(updateExtractCandidate), candidateRejectAction(reason 필수), candidatePromoteAction. 전부 admin:manage+revalidatePath('/admin/extraction').
  - **ReviewBacklogCard**: countPendingCandidates → 카드(건수+/admin/extraction 링크) — 스펙 §7 적체 위젯.
  - i18n admin.extraction + records.relationOrigin(extraction/import 라벨) ko/en 대칭.

- [ ] **Step 1: 구현** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 2: 수동 스모크(fake)** — dev 서버 EXTRACT_PROVIDER=fake: `[[TRIPLE ...]]` 트리거 → 후보 → 수정 → 승격 → 승인 → 레코드 상세 RelationList origin 뱃지 확인(기록). **실 ANTHROPIC_API_KEY가 env에 있으면 structured outputs 스키마 수락 검증 1회 필수에 준함**(anyOf 형태가 실 API에서 통과하는지 — 결과를 report에 기록; 키 없으면 '미검증' 명기).
- [ ] **Step 3: 커밋** — `feat(web): extraction trigger + review queue (approve/edit/reject/promote) + origin badge + backlog widget`

---

### Task 7: e2e 통합 + 정리 스크립트 확장 + README + 마감

**Files:**
- Modify: `apps/web/e2e/core-flows.spec.ts` (신규 1본 — 기존 25본 무수정)
- Modify: `apps/web/playwright.config.ts` (webServer에 **env 블록 신설**: `env: { EXTRACT_PROVIDER: 'fake' }` — Playwright가 process.env와 병합. 현재 env 블록 없음(실측). **주의: reuseExistingServer로 로컬 기존 서버 재사용 시 env 미적용 — e2e 전 :3100 기존 서버 종료 또는 그 서버를 EXTRACT_PROVIDER=fake로 기동**)
- Modify: `packages/db/scripts/clean-e2e-records.ts` (extract 정리 — inputText LIKE '%E2E 추출%' 잡의 후보 먼저, 잡 나중 하드 delete(FK 순서). committedRelationId 계보 사슬 절단은 테스트 데이터 한정 예외임을 주석 명시)
- Modify: `packages/db/README.md` (extraction 절)
- Modify: `.superpowers/sdd/progress.md` (컨트롤러 작성)

**Interfaces:**
- e2e 1본(외부 API 무의존 — fake provider). **픽스처 확정(리뷰 반영)**: relation은 시드 실존 `references`(8종 중 — created_by 없음), object는 **시드 노드 title**(예: 'Tetris' — agent/term 이름과 비충돌을 픽스처 조건으로 확인; 그라운딩 우선순위 agent>term>node라 겹치면 안 됨)로 node 그라운딩 + 승인 후 해당 레코드 상세 RelationList에서 검증:

```ts
test('extraction pipeline roundtrip with promotion and origin badge', async ({ page }) => {
  await login(page);
  // 1) /admin/extraction → manual_text:
  //    'E2E 추출 문서. [[TRIPLE E2E 추출대상 신규작가|references|Tetris|E2E 근거 구절]]'
  //    (subject 미존재 → unmatched, object 'Tetris'는 시드 노드 title → grounded(node))
  // 2) extract-trigger-submit → tr[data-candidate-id] 등장 + cand-unmatched-subject 뱃지
  // 3) cand-approve → 'unmatched-endpoint' 에러 표시(자동 커밋 없음의 UI 증거)
  // 4) cand-promote-subject → 신규 전거(ko 'E2E 추출대상 신규작가', person) → grounded 뱃지 갱신
  //    (특정 entityId 비의존 — 뱃지 존재까지만 단언)
  // 5) (선택) cand-edit로 uncertain 토글 → 저장 확인
  // 6) cand-approve(confirm) → 행 소멸
  // 7) Tetris 레코드 상세(/records/{slug}) → RelationList에 신규 관계 행 + data-testid="relation-origin-extraction" 뱃지 단언(R3 — 확정 표면)
  // 8) 정리: 전거·잡·후보는 clean-e2e-records(afterAll 기존 훅)가 정리; 관계는 전거 소프트삭제 캐스케이드로 무해화(실측: softDeleteAgent가 관계 소프트삭제, in-use 검사는 events/rights만 카운트 — 차단 없음)
});
```

- README extraction 절: 파이프라인 다이어그램(입력→프롬프트(스키마 주입·SKOS 6종)→LLM(structured outputs)→검증(Self-Correction: 총 호출=1+재시도)→그라운딩(agent/term/node v1 — event/instantiation 범위 외)→검수(승인/수정/반려/승격)→커밋(origin='extraction'·certainty·jobRef)), XAI 계보 역추적 쿼리 예시(attemptErrors 포함), audit origin 'ingest' 매핑 주의, v1 인라인+EXTRACT 큐 예비 근거(전환 조건: DIP 텍스트·대용량 배치), fake provider(EXTRACT_PROVIDER=fake), EXTRACT_MAX_TOKENS 16K 초과 시 비스트리밍 타임아웃 주의, SKOS_PROMPT_TAXONOMIES와 웹 SKOS_TAXONOMIES 드리프트 주의, 자동 커밋 금지 원칙.

- [ ] **Step 1: e2e 작성·실행** — Run: `cd apps/web && pnpm e2e` / Expected: **26/26**. 알려진 병렬 부하 플레이크는 격리 재실행 판정만 — 수정 금지.
- [ ] **Step 2: clean-e2e-records 확장 + 1회 실행** — 잔재 0 확인.
- [ ] **Step 3: README**
- [ ] **Step 4: 전체 게이트** — Run: `pnpm -r typecheck && pnpm --filter @binder/db test && pnpm --filter worker test` / Expected: 그린
- [ ] **Step 5: 커밋** — `test(web): extraction e2e + docs` (푸시는 컨트롤러 최종 리뷰 후)

---

## 자기 리뷰 체크 (2렌즈 적대적 리뷰 반영 완료)

- 스펙 §4 전수: 1 입력 3종(DIP 이연 명기) → T6. 2 영속 구조 → T1. 3 스키마 주입(하드코딩 방지 앵커+SKOS 6종 필터) → T2. 4 LLM(모델 env·enum·날짜·uncertain — **커밋 시 certainty 전달 R5**) → T2·T4·T5. 5 검증+Self-Correction(의미론 고정·**attempt별 오류 XAI 기록**) → T2·T4. 6 그라운딩(퍼지·unmatched 보존·**동명 타이브레이크**) → T3. 7 검수 큐(**승인/수정/반려 3종 완비 R1**+승격) → T1·T6. 8 커밋(승인분만·origin·근거·감사 **jobRef R4**) → T4. 원칙(검수 통과분만) → e2e 3단계 증거. XAI → T1 컬럼+T4 계보 테스트. §7(재시도는 큐 예비·실패 가시화 트리거+목록·적체 위젯) → T4~T6. §8 시연(**origin=extraction 표시 표면 신설 R3** — RelationList 뱃지, e2e 7단계 확정) → T6·T7.
- 블로커 해소: R1 수정 기능, R2 스냅샷 단일 진실(재빌드 모순 제거), R3 origin 표면(+e2e object를 노드로 변경), integrations @binder/db 의존 명시.
- Important 해소: certainty 전달, jobRef 컨벤션, attemptErrors 기록, SKOS 6종 상수 명기, playwright env 신설+재사용 서버 주의, 반환 status 분기, anyOf 날짜 스키마+실키 스모크 격상, references 픽스처+타이브레이크.
- Minor 반영: integrations 의존·env 이원화(.env.example+apps/web)·임계 env화·bodySizeLimit 인지·tx 캐스트 패턴 참조·정리 스크립트 FK 순서·키 유출 방지 컨벤션·listExtractJobs 컬럼 축소·툴팁 텍스트 렌더.
- 타입 일관성: ExtractLlm/ExtractPrompt(T2→T5→T4·T6), relationMachinesFromPrompt(T2→T4), InsertCandidateInput(T1→T4), GroundingHit(T3→T4), updateExtractCandidate(T1→T6), approveAndCommitCandidate(T4→T6), createRelation jobRef(T4 수정→커밋 경로) 대조 완료.
