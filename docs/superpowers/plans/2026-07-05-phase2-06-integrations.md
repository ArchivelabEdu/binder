# Phase 2 — Plan 06: Integrations (SS 어댑터·워커·fixity·인제스트) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Archivematica Storage Service 어댑터(AmSsBackend) + BullMQ 워커(fixity 크론·인제스트 큐) + am_dip METS 인제스트 + AIP 다운로드/복구 플로우를 붙여, Phase 1에서 검증한 보존 코어를 Phase 2 스택에서 실동시킨다.

**Architecture:** `packages/integrations`(순수 어댑터: StorageBackend 구현체·METS 파서·큐 계약) → `packages/db`(트랜잭션 오케스트레이션: ingest·download_log·recovery) → `apps/worker`(BullMQ 글루) / `apps/web`(라우트·서버 액션·UI). 상태 변경 API는 Bearer-only 또는 Basic(콜백) — 쿠키 인증 상태 변경 라우트는 0개 유지(CSRF 전제조건 이행). 브라우저발 변경은 Next.js Server Action(origin 검증 내장)으로만.

**Tech Stack:** BullMQ ^5 + ioredis(동봉), fast-xml-parser ^4, 기존 스택(Next.js 15, Drizzle, PostgreSQL 16 :15432, Redis 7 :16379).

## Global Constraints

- 모노레포 규칙: turbo task 이름은 `build`/`test`/`typecheck`/`dev` 고정. **신규 패키지는 `build` 스크립트를 두지 않는다** — `@binder/core`/`@binder/db`와 동일 패턴(소스 TS 그대로 소비; web은 transpilePackages, worker는 tsx 실행). 신규 패키지는 `pnpm-workspace.yaml` 자동 포함(디렉토리만 생성).
- import 규칙(BINDING): `packages/db/src/schema/*.ts` 내부 상호 import만 확장자 없음; 그 외 모든 ESM 상대 import는 `.js`. 클라이언트 컴포넌트에서 `@binder/core` 배럴 금지(`@binder/core/i18n` 서브패스만), `@binder/db`는 type-only.
- soft-delete 규칙: 라이브 유니크는 partial unique `WHERE deleted_at IS NULL`. 모든 신규 쿼리는 `deleted_at IS NULL` 필터.
- **CSRF/Bearer HARD 전제조건 이행(본 계획에서 확정)**: 상태 변경 `/api/v1` 라우트는 ① `withBearerAuth`(Authorization: Bearer만 허용, 쿠키 거부) 또는 ② `withBasicAuth`(SS 콜백 전용, env 자격증명) 중 하나만 사용한다. `withAuth`(쿠키 허용)는 읽기 전용 GET에만. 브라우저 UI의 변경 조작은 전부 Server Action. 다운로드 GET은 "로그가 남는 읽기"로 분류(Phase 1 downloadCheck?reason= 계승) — 쿠키 인증 허용, 근거를 코드 주석으로 명시.
- env 변수(신규, 기존 패턴 = 소비 모듈에서 `process.env.X ?? 기본값`): `SS_URL`(기본 `http://localhost:8001`), `SS_USER`, `SS_API_KEY`, `SS_PIPELINE_UUID`(기본 `00000000-0000-0000-0000-000000000000`), `REDIS_URL`(기본 `redis://localhost:16379`), `FIXITY_CRON`(기본 `0 3 * * *`), `FIXITY_WEBHOOK_URL`(선택), `RECOVERY_CALLBACK_USER`/`RECOVERY_CALLBACK_PASS`(콜백 Basic).
- SS v2 API 계약(Phase 1 실검증, 변경 금지): 인증 헤더 `Authorization: ApiKey <user>:<key>`; `GET /api/v2/file/<uuid>/check_fixity/?format=json` → `{success, message, failures}`; `GET /api/v2/file/<uuid>/download/` → 파일 스트림; `POST /api/v2/file/<uuid>/recover_aip/?format=json` body `{event_reason, pipeline, user_id, user_email}` → `{id: <int>}`; 복구 콜백(Binder 수신) body `{event_id, success, message}` + Basic 인증.
- LocalFsBackend locator 계약: `fs://<uuid>` → `<config.root>/<uuid>.tar`.
- 큐 계약: 큐 이름 `fixity` / `ingest`; ingest 잡 페이로드 `{ ingestJobId: string }` (uuid).
- RBAC: 권한 문자열 `aips:recover`(복구 요청), `ingest:write`(인제스트 API). editor에 두 권한 추가, admin은 `'*'` 와일드카드.
- i18n: 신규 UI 문자열은 `apps/web/messages/{ko,en}.json` 키 패리티 유지, aux JSONB 표시는 `pickI18n(x, locale, 'ko')`.
- 커밋: conventional commits + `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- 게이트(전 태스크 공통): `pnpm test` + `pnpm typecheck` + `pnpm --filter web build` 그린 유지. e2e는 Task 8에서.

---

### Task 1: Plan 05 검색 백로그 흡수 + UI 정리

**Files:**
- Modify: `packages/db/src/queries/search.ts`
- Modify: `packages/db/src/queries/browse.ts` (주석 1줄)
- Modify: `packages/db/src/queries/detail.ts` (정책 주석 + meta 정리)
- Modify: `apps/web/components/records/ContextBrowser.tsx` (meta 필드 참조 없음 — 확인만)
- Modify: `apps/web/messages/ko.json`, `apps/web/messages/en.json` (`records.size` 키 제거)
- Test: `packages/db/test/search.test.ts` (기존 파일에 추가)

**Interfaces:**
- Consumes: `searchEntities(db, query, opts)` 기존 시그니처 (변경 없음)
- Produces: 동일 시그니처 — SQL 내 LIMIT로 상한 보장. `ContextGraph['nodes'][].meta`에서 `level`/`sizeBytes` 제거(타입+생성부), `componentNumber`만 유지.

- [ ] **Step 1: 실패 테스트 — SQL LIMIT 검증**

`packages/db/test/search.test.ts`에 추가 (기존 테스트 픽스처 패턴 재사용):

```ts
it('searchEntities caps result count in SQL (limit smaller than matches)', async () => {
  // 픽스처: 동일 질의어에 매칭되는 노드 3건 이상 존재하도록 upsertSearchDoc으로 직접 주입
  for (let i = 1; i <= 4; i++) {
    await upsertSearchDoc(db as any, { entityType: 'limit-test', entityId: i, culture: 'ko', textContent: `리미트검증 문서 ${i}` });
  }
  const hits = await searchEntities(db, '리미트검증', { entityType: 'limit-test', limit: 2 });
  expect(hits).toHaveLength(2);
  // rank 내림차순 유지 확인
  expect(hits[0].rank).toBeGreaterThanOrEqual(hits[1].rank);
});
```

- [ ] **Step 2: 실행 — 실패 확인**

Run: `pnpm --filter @binder/db test -- search`
Expected: 신규 테스트는 **통과할 수도 있다**(JS slice도 2건 반환). 이 테스트는 회귀 방지용 계약 고정이다 — 실패하지 않아도 진행하되, Step 3의 SQL 변경 후 반드시 재실행한다.

- [ ] **Step 3: searchEntities — LIMIT를 SQL로**

`packages/db/src/queries/search.ts`의 `searchEntities` 본문 중 SQL과 후처리를 다음으로 교체:

```ts
export async function searchEntities(
  db: PostgresJsDatabase,
  query: string,
  opts: { entityType?: string; limit?: number } = {},
): Promise<Array<{ entityType: string; entityId: number; culture: string; rank: number }>> {
  const q = query.trim();
  if (!q) return [];
  const limit = opts.limit ?? 50;
  const like = `%${q.replace(/[\\%_]/g, (m) => `\\${m}`)}%`;
  const rows = (await db.execute(sql`
    SELECT entity_type, entity_id, culture, rank FROM (
      SELECT DISTINCT ON (entity_type, entity_id)
             entity_type, entity_id, culture,
             GREATEST(
               COALESCE(ts_rank(tsv, plainto_tsquery('simple', ${q})), 0),
               similarity(text_content, ${q})
             ) AS rank
      FROM search_docs
      WHERE (tsv @@ plainto_tsquery('simple', ${q}) OR text_content ILIKE ${like})
        ${opts.entityType ? sql`AND entity_type = ${opts.entityType}` : sql``}
      ORDER BY entity_type, entity_id, rank DESC
    ) t ORDER BY rank DESC LIMIT ${limit}
  `)) as unknown as Array<{ entity_type: string; entity_id: number; culture: string; rank: number }>;
  return rows.map((r) => ({ entityType: r.entity_type, entityId: Number(r.entity_id), culture: r.culture, rank: Number(r.rank) }));
}
```

- [ ] **Step 4: browse.ts 500-캡 트레이드오프 주석**

`packages/db/src/queries/browse.ts`의 `searchEntities(db, opts.q, { entityType: 'node', limit: 500 })` 호출 직전 줄에 추가:

```ts
    // q-path caps hits at 500 (SQL LIMIT in searchEntities): totals/facets under q are
    // computed over at most 500 top-ranked matches. Accepted at target scale (works ~10^3);
    // revisit if collections grow past that. (Plan 05 final-review backlog, resolved here.)
```

- [ ] **Step 5: detail.ts — 정책 주석 + 미사용 meta 필드 제거**

`getNodeDetail` 함수 선언 바로 위에 정책 주석 추가:

```ts
// aux-JSONB display policy (confirmed 2026-07-04): every I18nText coming out of this module
// is rendered with pickI18n(x, locale, 'ko') — Korean is the fallback culture for auxiliary
// JSONB (notes/rights/relations/terms). Keep any new i18n field consistent with this.
```

`ContextGraph` 타입의 `meta`를 `meta?: { componentNumber?: string }`로 축소하고, `getContextGraph` 내 두 곳 수정:
- tree 노드 push의 `meta: { level: ..., componentNumber: ... }` → `meta: { componentNumber: compByNode.get(t.id) ?? undefined }` (이에 따라 `levelNames`/`getTermNames` 호출과 `levelTermId` 수집이 미사용이 되면 함께 제거)
- AIP 노드 push의 `meta: { sizeBytes: Number(a.size_bytes) }` → `meta` 프로퍼티 자체 삭제 (SELECT 목록의 `a.size_bytes`도 제거)

`apps/web/components/records/ContextBrowser.tsx`가 `meta.level`/`meta.sizeBytes`를 참조하지 않음을 grep으로 확인만 한다(참조 없음이 정상 — 참조가 있으면 STOP, 계획 모순 보고).

- [ ] **Step 6: records.size 키 제거**

`apps/web/messages/ko.json`과 `en.json`에서 `records.size` 키 한 줄씩 제거. `grep -rn "records.size\|t('size')\|\.size'" apps/web/app apps/web/components`로 사용처 0건 확인.

- [ ] **Step 7: 게이트 + 커밋**

Run: `pnpm test && pnpm typecheck && pnpm --filter web build`
Expected: 전부 그린 (db 88+/core 28).

```bash
git add -A && git commit -m "fix(db): push search LIMIT into SQL and absorb plan-05 backlog notes"
```

---

### Task 2: Migration 0017 + 보존 계약 확장 (download_log·재인제스트·복구 message)

**Files:**
- Modify: `packages/db/src/schema/preservation.ts` (aips uuid partial unique + downloadLog 테이블)
- Modify: `packages/db/src/schema/fixity.ts` (fixityRecoveries.message)
- Modify: `packages/db/src/schema/ingest.ts` (ingestJobs.params)
- Modify: `packages/db/src/schema/index.ts` (downloadLog export — 기존 패턴 확인 후 동일하게)
- Create: `packages/db/migrations/0017_*.sql` (drizzle-kit generate + 수기 보정)
- Modify: `packages/db/src/queries/fixity.ts` (registerFixityReport live-only)
- Modify: `packages/db/src/queries/aips.ts` (uuid 사전검사 live-only)
- Create: `packages/db/src/queries/downloads.ts`
- Modify: `packages/db/src/seed/seed-roles.ts` (editor 권한 추가)
- Modify: `packages/db/src/seed/seed-demo.ts` (더미 tar + config.root 절대경로)
- Modify: `packages/core/src/permissions.ts` (Create) + `packages/core/src/index.ts`
- Modify: `packages/db/src/index.ts` (신규 export)
- Test: `packages/db/test/fixity-queries.test.ts`, `packages/db/test/aips-queries.test.ts`(기존 파일에 추가 — 이 이름이 실제 파일명이다), `packages/db/test/downloads.test.ts`(신규), `packages/core/test/permissions.test.ts`(신규)

**Interfaces:**
- Produces:
  - `downloadLog` 테이블: `id serial PK, aip_id int NOT NULL FK aips(restrict), user_id int NOT NULL FK users(restrict), reason text NOT NULL, created_at timestamptz NOT NULL default now()`
  - `insertDownloadLog(db: PostgresJsDatabase, e: { aipId: number; userId: number; reason: string }): Promise<void>` — reason 공백이면 throw `new Error('download reason is required')`
  - `registerFixityReport`: uuid 조회에 `deleted_at IS NULL` 추가; 미존재 시 `throw new UnknownAipError(uuid)` (신규 export 클래스, 기존 반환 계약은 유지 — 기존엔 미존재 시 어떤 동작이었는지 확인해 테스트로 고정)
  - `aips.uuid` 유니크 → partial unique `WHERE deleted_at IS NULL` (제약명 `aips_uuid_live_uq`) — soft-delete 후 동일 uuid 재인제스트 허용
  - `ingestJobs.params: jsonb` — `{ targetNodeId: number, dipPath: string, backendId?: number }`
  - `fixityRecoveries.message: text` (nullable)
  - `hasPermission(perms: string[], required: string): boolean` — `'*'` 와일드카드 지원, `@binder/core` 배럴 export
  - editor 권한: `['records:read','records:write','aips:read','aips:download','aips:recover','ingest:write']`

- [ ] **Step 1: 실패 테스트 작성**

`packages/core/test/permissions.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { hasPermission } from '../src/permissions.js';

describe('hasPermission', () => {
  it('matches exact permission', () => {
    expect(hasPermission(['aips:read'], 'aips:read')).toBe(true);
    expect(hasPermission(['aips:read'], 'aips:recover')).toBe(false);
  });
  it('wildcard * grants everything', () => {
    expect(hasPermission(['*'], 'anything:at-all')).toBe(true);
  });
  it('empty set grants nothing', () => {
    expect(hasPermission([], 'records:read')).toBe(false);
  });
});
```

`packages/db/test/fixity-queries.test.ts`에 추가(기존 하네스·픽스처 재사용):

```ts
it('registerFixityReport refuses soft-deleted aips (looks up live only)', async () => {
  // 픽스처 헬퍼로 노드+AIP 생성 후 softDeleteAip
  // (파일 상단의 기존 헬퍼/시드 패턴을 재사용할 것)
  await softDeleteAip(db, aipId, null);
  await expect(
    registerFixityReport(db, { aipUuid: theUuid, success: true, startedAt: new Date(), completedAt: new Date() }),
  ).rejects.toThrow(UnknownAipError);
});
```

`packages/db/test/aips-queries.test.ts`에 추가:

```ts
it('allows re-ingest of same uuid after soft delete (partial unique)', async () => {
  // AIP 생성 → softDeleteAip → 동일 uuid로 createAip 재호출이 성공해야 한다
  const first = await createAip(db, input);       // input.uuid = 고정 uuid
  await softDeleteAip(db, first.id, null);
  const second = await createAip(db, input);
  expect(second.uuid).toBe(input.uuid);
  expect(second.id).not.toBe(first.id);
});
```

`packages/db/test/downloads.test.ts` (신규):

```ts
import { describe, it, expect } from 'vitest';
// 기존 db 테스트 파일들의 setup 패턴(글로벌 테스트 db 헬퍼)을 그대로 따를 것
describe('insertDownloadLog', () => {
  it('writes a row with reason', async () => {
    await insertDownloadLog(db, { aipId, userId, reason: '연구 열람' });
    const rows = await db.execute(sql`SELECT reason FROM download_log WHERE aip_id = ${aipId}`);
    expect(rows).toHaveLength(1);
  });
  it('rejects empty reason', async () => {
    await expect(insertDownloadLog(db, { aipId, userId, reason: '  ' })).rejects.toThrow(/reason/);
  });
});
```

- [ ] **Step 2: 실행 — 실패 확인**

Run: `pnpm --filter @binder/core test && pnpm --filter @binder/db test -- fixity`
Expected: FAIL (`hasPermission`/`UnknownAipError`/`download_log` 미존재)

- [ ] **Step 3: 스키마 변경**

`packages/db/src/schema/preservation.ts` — `aips` 테이블 정의에서 `uuid: uuid('uuid').notNull().unique()` → `uuid: uuid('uuid').notNull()`로 바꾸고, 테이블 세 번째 인자 배열에 추가:

```ts
  uniqueIndex('aips_uuid_live_uq').on(t.uuid).where(sql`deleted_at IS NULL`),
```

(파일 상단 import에 `uniqueIndex`, `sql` 필요 — 스키마 파일 내 상호 import는 확장자 없음 규칙 유지.)

같은 파일에 `downloadLog` 추가:

```ts
export const downloadLog = pgTable('download_log', {
  id: serial('id').primaryKey(),
  aipId: integer('aip_id').notNull().references(() => aips.id, { onDelete: 'restrict' }),
  userId: integer('user_id').notNull().references(() => users.id, { onDelete: 'restrict' }),
  reason: text('reason').notNull(),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});
```

`packages/db/src/schema/fixity.ts` — `fixityRecoveries`에 `message: text('message'),` 추가 (`externalEventRef` 다음 줄).

`packages/db/src/schema/ingest.ts` — `ingestJobs`에 `params: jsonb('params').$type<Record<string, unknown>>(),` 추가 (`packageRef` 다음 줄).

`packages/db/src/schema/index.ts`의 기존 export 패턴에 맞춰 `downloadLog` 노출.

- [ ] **Step 4: 마이그레이션 생성 + 검증**

Run: `pnpm --filter @binder/db db:generate`
Expected: `migrations/0017_*.sql` 생성. **내용을 열어 반드시 확인**: ① `aips_uuid_unique` 제약 DROP + `aips_uuid_live_uq` partial unique CREATE, ② `download_log` CREATE TABLE, ③ `fixity_recoveries.message` ADD COLUMN, ④ `ingest_jobs.params` ADD COLUMN. drizzle-kit이 uuid 제약 교체를 DROP+CREATE로 못 만들면 SQL을 수기 보정한다(기존 0001 partial unique 선례 참조).

Run: `pnpm --filter @binder/db db:migrate`
Expected: dev DB 적용 성공.

- [ ] **Step 5: 쿼리·헬퍼 구현**

`packages/core/src/permissions.ts`:

```ts
export function hasPermission(perms: string[], required: string): boolean {
  return perms.includes('*') || perms.includes(required);
}
```

`packages/core/src/index.ts`에 `export { hasPermission } from './permissions.js';` 추가.

`packages/db/src/queries/fixity.ts` — 파일 상단에:

```ts
export class UnknownAipError extends Error {
  constructor(uuid: string) {
    super(`unknown live aip uuid: ${uuid}`);
    this.name = 'UnknownAipError';
  }
}
```

`registerFixityReport`의 uuid 조회 where를 `and(eq(aips.uuid, input.aipUuid), isNull(aips.deletedAt))`로 바꾸고, 미존재 시 `throw new UnknownAipError(input.aipUuid)`.

`packages/db/src/queries/aips.ts` — `createAip`의 중복 uuid 사전검사(pre-check) SELECT에 `deleted_at IS NULL` 조건 추가(라이브 중복만 `AipAlreadyExistsError`). `isAipUuidUniqueViolation`의 제약명 매칭을 확인 — 기존 매칭은 `'aips_uuid'` 부분 문자열이라 `aips_uuid_live_uq`도 잡는다(그대로 두되 확인). 단 `aips.ts:14` 부근의 낡은 주석("Constraint name from migrations/0011…: aips_uuid_unique")을 새 이름으로 갱신하고, 이제 제약이 아닌 partial unique **index**임을 명시(PG는 23505에서 인덱스명도 constraint_name 필드로 보고하므로 매칭 로직은 유효).

`packages/db/src/queries/downloads.ts`:

```ts
import { sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { downloadLog } from '../schema/index.js';

export async function insertDownloadLog(
  db: PostgresJsDatabase,
  e: { aipId: number; userId: number; reason: string },
): Promise<void> {
  const reason = e.reason.trim();
  if (!reason) throw new Error('download reason is required');
  await db.insert(downloadLog).values({ aipId: e.aipId, userId: e.userId, reason });
}
```

`packages/db/src/index.ts`에 export 추가:

```ts
export { insertDownloadLog } from './queries/downloads.js';
export { UnknownAipError } from './queries/fixity.js';
```

- [ ] **Step 6: 시드 갱신**

`packages/db/src/seed/seed-roles.ts` — editor 배열을 `['records:read', 'records:write', 'aips:read', 'aips:download', 'aips:recover', 'ingest:write']`로 확장 (onConflictDoUpdate라 재시드로 반영됨).

`packages/db/src/seed/seed-demo.ts` — 시더 함수 시작부(멱등 가드 **앞**)에 추가:

```ts
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

// demo AIP payloads for LocalFsBackend download (fs://<uuid> → <root>/<uuid>.tar)
const DEMO_AIP_UUIDS = [
  '6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52',
  '9559945a-52e6-4c0e-9d54-83e8e548dd1d',
  'd3a3f8d6-8a5b-4e6c-9c1a-2f4b7c1d9e0f',
];
const demoAipRoot = join(dirname(fileURLToPath(import.meta.url)), '../../../..', 'docker', 'demo-aips');
mkdirSync(demoAipRoot, { recursive: true });
for (const u of DEMO_AIP_UUIDS) {
  const p = join(demoAipRoot, `${u}.tar`);
  if (!existsSync(p)) writeFileSync(p, Buffer.from(`demo aip payload ${u}\n`.repeat(32)));
}
```

(경로 계산은 seed-demo.ts의 실제 위치 기준으로 repo 루트의 `docker/demo-aips`가 되도록 `join` 세그먼트를 조정할 것 — `packages/db/src/seed/` 기준이면 위처럼 4단계 상위다. 구현 후 `node -e` 한 줄로 실경로 출력 검증.)

`config` root 갱신은 **멱등 가드(`if (...) return;`) 앞** — 위 tar 생성 블록 바로 다음에 배치한다. 가드 뒤에 두면 이미 시드된 dev DB(일반 케이스)에서 root가 `/var/binder/aips`로 남아 Task 7/8 다운로드 검증이 깨진다:

```ts
await db.update(storageBackends).set({ config: { root: demoAipRoot } })
  .where(eq(storageBackends.name, 'demo local storage'));
```

`docker/demo-aips/`를 `.gitignore`에 추가.

- [ ] **Step 7: 테스트·게이트 통과 + 재시드**

Run: `pnpm test` → core/db 전부 그린 (신규 테스트 포함). `pnpm --filter @binder/db db:seed && pnpm --filter @binder/db db:seed-demo` 재실행(멱등 확인 + editor 권한/데모 root 반영). `pnpm typecheck` 그린.

- [ ] **Step 8: 커밋**

```bash
git add -A && git commit -m "feat(db): download_log, live-only aip uuid, ingest params, recovery message (migration 0017)"
```

---

### Task 3: packages/integrations — StorageBackend 인터페이스 + AmSsBackend + LocalFsBackend + 큐 계약

**Files:**
- Create: `packages/integrations/package.json`, `packages/integrations/tsconfig.json`, `packages/integrations/vitest.config.ts`
- Create: `packages/integrations/src/index.ts`
- Create: `packages/integrations/src/storage/types.ts`
- Create: `packages/integrations/src/storage/am-ss.ts`
- Create: `packages/integrations/src/storage/local-fs.ts`
- Create: `packages/integrations/src/storage/factory.ts`
- Create: `packages/integrations/src/queue/contract.ts`
- Test: `packages/integrations/test/am-ss.test.ts`, `packages/integrations/test/local-fs.test.ts`

**Interfaces:**
- Consumes: 없음 (순수 패키지 — @binder/db에 의존하지 않는다. 백엔드 행은 구조 타입으로 받음)
- Produces (배럴 export, 이후 태스크가 사용):
  - `type FixityResult = { success: boolean; message: string; failures: Record<string, unknown> }`
  - `type DownloadStream = { stream: ReadableStream<Uint8Array>; filename: string; sizeBytes: number | null }`
  - `class UnsupportedOperationError extends Error`, `class BackendRequestError extends Error { status: number | null }`
  - `interface StorageBackend { download(locator: string): Promise<DownloadStream>; checkFixity(locator: string): Promise<FixityResult>; requestRecovery(locator: string, opts: { reason: string; userId: number; userEmail: string }): Promise<{ externalEventRef: string }>; readonly supportsFixity: boolean }`
  - `class AmSsBackend implements StorageBackend` — `new AmSsBackend(cfg: { baseUrl: string; user: string; apiKey: string; pipelineUuid: string })`
  - `class LocalFsBackend implements StorageBackend` — `new LocalFsBackend(cfg: { root: string })`, download만 지원(`supportsFixity = false`, checkFixity/requestRecovery는 `UnsupportedOperationError`)
  - `getBackendForRow(row: { kind: 'am_ss' | 'local_fs' | 's3'; config: Record<string, unknown> }): StorageBackend` — am_ss는 env(SS_URL/SS_USER/SS_API_KEY/SS_PIPELINE_UUID)에서, local_fs는 `config.root`에서 구성. s3 → `UnsupportedOperationError`
  - `QUEUES = { fixity: 'fixity', ingest: 'ingest' } as const`, `type IngestJobPayload = { ingestJobId: string }`

**세부 계약 (AmSsBackend — Phase 1 검증 API):**
- 모든 요청 헤더: `Authorization: ApiKey ${user}:${apiKey}`, `User-Agent: binder-next`
- locator = SS 파일 uuid (`fs://` 같은 스킴 없음). `aip_copies.locator`에 uuid 그대로 저장하는 것이 am_ss 규약.
- `download(uuid)`: ① `GET ${baseUrl}/api/v2/file/${uuid}?format=json` → JSON에서 `current_full_path` 취득, `filename = basename(current_full_path) + '.tar'` ② `GET ${baseUrl}/api/v2/file/${uuid}/download/` (redirect follow) → `{ stream: res.body, filename, sizeBytes: Number(res.headers.get('content-length')) || null }`. 비-2xx면 `BackendRequestError(status)`.
- `checkFixity(uuid)`: `GET ${baseUrl}/api/v2/file/${uuid}/check_fixity/?format=json` → `{ success: Boolean(j.success), message: String(j.message ?? ''), failures: (j.failures ?? {}) }`
- `requestRecovery(uuid, opts)`: `POST ${baseUrl}/api/v2/file/${uuid}/recover_aip/?format=json` body `JSON.stringify({ event_reason: opts.reason, pipeline: pipelineUuid, user_id: opts.userId, user_email: opts.userEmail })`, `Content-Type: application/json` → 응답 `{ id }` → `{ externalEventRef: String(j.id) }`
- fetch는 전역 fetch 사용(Node 22), 신규 dep 불요.

- [ ] **Step 1: 스캐폴드**

`packages/integrations/package.json` (core 패키지의 것을 열어 형식을 맞출 것 — name `@binder/integrations`, `"type": "module"`, exports `{ ".": "./src/index.ts" }`, scripts `test`(vitest run)/`typecheck`(tsc --noEmit) — **`build` 스크립트 없음**, core/db 패턴). tsconfig/vitest.config도 core의 것을 복사 후 조정. `pnpm install`로 워크스페이스 등록 확인.

- [ ] **Step 2: 실패 테스트 — 계약 픽스처 서버**

`packages/integrations/test/am-ss.test.ts` — node:http로 SS 흉내(신규 dep 금지):

```ts
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { createServer, type Server } from 'node:http';
import { AmSsBackend } from '../src/storage/am-ss.js';

const UUID = '6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52';
let server: Server; let baseUrl = ''; let lastAuth = ''; let lastRecoverBody = '';

beforeAll(async () => {
  server = createServer((req, res) => {
    lastAuth = req.headers.authorization ?? '';
    if (req.url === `/api/v2/file/${UUID}?format=json`) {
      res.setHeader('content-type', 'application/json');
      res.end(JSON.stringify({ current_full_path: `/aips/6f19/lovers_archival_master-${UUID}` }));
    } else if (req.url === `/api/v2/file/${UUID}/download/`) {
      res.setHeader('content-length', '10');
      res.end('0123456789');
    } else if (req.url === `/api/v2/file/${UUID}/check_fixity/?format=json`) {
      res.setHeader('content-type', 'application/json');
      res.end(JSON.stringify({ success: false, message: 'Payload-Oxum mismatch', failures: { files: [{ path: 'data/objects/x.mov' }] } }));
    } else if (req.url === `/api/v2/file/${UUID}/recover_aip/?format=json` && req.method === 'POST') {
      let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => {
        lastRecoverBody = b;
        res.setHeader('content-type', 'application/json');
        res.end(JSON.stringify({ id: 42 }));
      });
    } else { res.statusCode = 404; res.end('{}'); }
  });
  await new Promise<void>((r) => server.listen(0, () => r()));
  const addr = server.address();
  baseUrl = `http://127.0.0.1:${typeof addr === 'object' && addr ? addr.port : 0}`;
});
afterAll(() => new Promise<void>((r) => server.close(() => r())));

function backend() {
  return new AmSsBackend({ baseUrl, user: 'spike', apiKey: 'spike', pipelineUuid: '00000000-0000-0000-0000-000000000000' });
}

describe('AmSsBackend', () => {
  it('sends ApiKey auth header', async () => {
    await backend().checkFixity(UUID);
    expect(lastAuth).toBe('ApiKey spike:spike');
  });
  it('checkFixity maps success/message/failures', async () => {
    const r = await backend().checkFixity(UUID);
    expect(r.success).toBe(false);
    expect(r.message).toContain('Payload-Oxum');
    expect(r.failures).toHaveProperty('files');
  });
  it('download derives filename from current_full_path and streams bytes', async () => {
    const d = await backend().download(UUID);
    expect(d.filename).toBe(`lovers_archival_master-${UUID}.tar`);
    expect(d.sizeBytes).toBe(10);
    const text = await new Response(d.stream).text();
    expect(text).toBe('0123456789');
  });
  it('requestRecovery posts pipeline/user and returns event ref', async () => {
    const r = await backend().requestRecovery(UUID, { reason: 'Request from Binder', userId: 7, userEmail: 'demo@example.com' });
    expect(r.externalEventRef).toBe('42');
    const body = JSON.parse(lastRecoverBody);
    expect(body).toEqual({ event_reason: 'Request from Binder', pipeline: '00000000-0000-0000-0000-000000000000', user_id: 7, user_email: 'demo@example.com' });
  });
  it('non-2xx becomes BackendRequestError with status', async () => {
    await expect(backend().checkFixity('deadbeef-0000-0000-0000-000000000000')).rejects.toMatchObject({ status: 404 });
  });
});
```

`packages/integrations/test/local-fs.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { LocalFsBackend } from '../src/storage/local-fs.js';
import { UnsupportedOperationError } from '../src/storage/types.js';

const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001';

describe('LocalFsBackend', () => {
  it('streams <root>/<uuid>.tar for fs://<uuid> locator', async () => {
    const root = mkdtempSync(join(tmpdir(), 'lfs-'));
    writeFileSync(join(root, `${UUID}.tar`), 'tar-bytes');
    const d = await new LocalFsBackend({ root }).download(`fs://${UUID}`);
    expect(d.filename).toBe(`${UUID}.tar`);
    expect(await new Response(d.stream).text()).toBe('tar-bytes');
  });
  it('missing file → BackendRequestError(null status)', async () => {
    const root = mkdtempSync(join(tmpdir(), 'lfs-'));
    await expect(new LocalFsBackend({ root }).download(`fs://${UUID}`)).rejects.toMatchObject({ name: 'BackendRequestError' });
  });
  it('checkFixity unsupported', async () => {
    await expect(new LocalFsBackend({ root: '/x' }).checkFixity('fs://y')).rejects.toBeInstanceOf(UnsupportedOperationError);
  });
});
```

Run: `pnpm --filter @binder/integrations test`
Expected: FAIL (모듈 미존재)

- [ ] **Step 3: 구현**

`src/storage/types.ts` — 위 Interfaces 블록의 타입/에러 클래스 그대로:

```ts
export type FixityResult = { success: boolean; message: string; failures: Record<string, unknown> };
export type DownloadStream = { stream: ReadableStream<Uint8Array>; filename: string; sizeBytes: number | null };

export class UnsupportedOperationError extends Error {
  constructor(op: string) { super(`unsupported operation: ${op}`); this.name = 'UnsupportedOperationError'; }
}
export class BackendRequestError extends Error {
  status: number | null;
  constructor(message: string, status: number | null) { super(message); this.name = 'BackendRequestError'; this.status = status; }
}

export interface StorageBackend {
  readonly supportsFixity: boolean;
  download(locator: string): Promise<DownloadStream>;
  checkFixity(locator: string): Promise<FixityResult>;
  requestRecovery(locator: string, opts: { reason: string; userId: number; userEmail: string }): Promise<{ externalEventRef: string }>;
}
```

`src/storage/am-ss.ts`:

```ts
import { BackendRequestError, type DownloadStream, type FixityResult, type StorageBackend } from './types.js';

export class AmSsBackend implements StorageBackend {
  readonly supportsFixity = true;
  constructor(private cfg: { baseUrl: string; user: string; apiKey: string; pipelineUuid: string }) {}

  private headers(): Record<string, string> {
    return { authorization: `ApiKey ${this.cfg.user}:${this.cfg.apiKey}`, 'user-agent': 'binder-next' };
  }

  private async getJson(path: string): Promise<Record<string, unknown>> {
    const res = await fetch(`${this.cfg.baseUrl}${path}`, { headers: this.headers(), redirect: 'follow' });
    if (!res.ok) throw new BackendRequestError(`SS ${path} -> ${res.status}`, res.status);
    return (await res.json()) as Record<string, unknown>;
  }

  async download(locator: string): Promise<DownloadStream> {
    const info = await this.getJson(`/api/v2/file/${locator}?format=json`);
    const full = String(info.current_full_path ?? locator);
    const filename = `${full.split('/').pop()}.tar`;
    const res = await fetch(`${this.cfg.baseUrl}/api/v2/file/${locator}/download/`, { headers: this.headers(), redirect: 'follow' });
    if (!res.ok || !res.body) throw new BackendRequestError(`SS download ${locator} -> ${res.status}`, res.status);
    const len = res.headers.get('content-length');
    return { stream: res.body, filename, sizeBytes: len ? Number(len) : null };
  }

  async checkFixity(locator: string): Promise<FixityResult> {
    const j = await this.getJson(`/api/v2/file/${locator}/check_fixity/?format=json`);
    return { success: Boolean(j.success), message: String(j.message ?? ''), failures: (j.failures ?? {}) as Record<string, unknown> };
  }

  async requestRecovery(locator: string, opts: { reason: string; userId: number; userEmail: string }): Promise<{ externalEventRef: string }> {
    const res = await fetch(`${this.cfg.baseUrl}/api/v2/file/${locator}/recover_aip/?format=json`, {
      method: 'POST',
      headers: { ...this.headers(), 'content-type': 'application/json' },
      body: JSON.stringify({ event_reason: opts.reason, pipeline: this.cfg.pipelineUuid, user_id: opts.userId, user_email: opts.userEmail }),
    });
    if (!res.ok) throw new BackendRequestError(`SS recover_aip ${locator} -> ${res.status}`, res.status);
    const j = (await res.json()) as { id: number };
    return { externalEventRef: String(j.id) };
  }
}
```

`src/storage/local-fs.ts`:

```ts
import { createReadStream, statSync } from 'node:fs';
import { join, basename } from 'node:path';
import { Readable } from 'node:stream';
import { BackendRequestError, UnsupportedOperationError, type DownloadStream, type FixityResult, type StorageBackend } from './types.js';

export class LocalFsBackend implements StorageBackend {
  readonly supportsFixity = false;
  constructor(private cfg: { root: string }) {}

  async download(locator: string): Promise<DownloadStream> {
    const uuid = locator.replace(/^fs:\/\//, '');
    const path = join(this.cfg.root, `${uuid}.tar`);
    let size: number;
    try { size = statSync(path).size; } catch {
      throw new BackendRequestError(`local aip payload missing: ${basename(path)}`, null);
    }
    const stream = Readable.toWeb(createReadStream(path)) as ReadableStream<Uint8Array>;
    return { stream, filename: basename(path), sizeBytes: size };
  }

  async checkFixity(): Promise<FixityResult> { throw new UnsupportedOperationError('local_fs checkFixity'); }
  async requestRecovery(): Promise<{ externalEventRef: string }> { throw new UnsupportedOperationError('local_fs requestRecovery'); }
}
```

`src/storage/factory.ts`:

```ts
import { AmSsBackend } from './am-ss.js';
import { LocalFsBackend } from './local-fs.js';
import { UnsupportedOperationError, type StorageBackend } from './types.js';

export function getBackendForRow(row: { kind: string; config: Record<string, unknown> }): StorageBackend {
  if (row.kind === 'am_ss') {
    return new AmSsBackend({
      baseUrl: process.env.SS_URL ?? 'http://localhost:8001',
      user: process.env.SS_USER ?? '',
      apiKey: process.env.SS_API_KEY ?? '',
      pipelineUuid: process.env.SS_PIPELINE_UUID ?? '00000000-0000-0000-0000-000000000000',
    });
  }
  if (row.kind === 'local_fs') return new LocalFsBackend({ root: String(row.config.root ?? '') });
  throw new UnsupportedOperationError(`backend kind ${row.kind}`);
}
```

`src/queue/contract.ts`:

```ts
export const QUEUES = { fixity: 'fixity', ingest: 'ingest' } as const;
export type IngestJobPayload = { ingestJobId: string };
```

`src/index.ts` — 전부 배럴 export (`./storage/types.js`, `./storage/am-ss.js`, `./storage/local-fs.js`, `./storage/factory.js`, `./queue/contract.js`).

- [ ] **Step 4: 게이트 + 커밋**

Run: `pnpm --filter @binder/integrations test && pnpm typecheck && pnpm test`
Expected: integrations 테스트 그린, 워크스페이스 전체 그린 (turbo가 신규 패키지 인식).

```bash
git add -A && git commit -m "feat(integrations): storage backend interface with AmSs and LocalFs implementations"
```

---

### Task 4: am_dip METS 파서 (TDD, Phase 1 계약 이식)

**Files:**
- Create: `packages/integrations/src/ingest/parse-mets.ts`
- Create: `packages/integrations/test/fixtures/Demo_ingest-aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001/METS.aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001.xml`
- Create: `packages/integrations/test/fixtures/Demo_ingest-aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001/objects/11111111-2222-3333-4444-555566660001-Demo_video.mov` (더미 바이트)
- Create: `packages/integrations/test/fixtures/Demo_ingest-aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001/objects/11111111-2222-3333-4444-555566660002-Demo_notes.txt`
- Modify: `packages/integrations/src/index.ts`, `packages/integrations/package.json` (fast-xml-parser 의존성)
- Test: `packages/integrations/test/parse-mets.test.ts`

**Interfaces:**
- Produces:
  - `type ParsedDipFile = { uuid: string; originalRelativePath: string; filename: string; sizeBytes: number; formatName: string | null }`
  - `type ParsedDip = { aipUuid: string; dipName: string; createdAt: Date | null; dc: { title: string | null; description: string | null; identifier: string | null }; files: ParsedDipFile[] }`
  - `parseAmDip(dipDir: string): Promise<ParsedDip>` — DIP 디렉토리 경로를 받아 `METS.<uuid>.xml`(정규식 `/^METS\..*\.xml$/`)을 찾아 파싱. METS 미존재/uuid 불일치 시 `class DipParseError extends Error` throw
  - `aipUuid`는 디렉토리명 말미 36자(`Demo_ingest-<uuid36>` → `dipName='Demo_ingest'`)
- 파싱 규칙(Phase 1 역공학 계약):
  - 파일 목록: `fileSec > fileGrp[USE="original"] > file` — `@ADMID`로 amdSec/techMD 매칭 → `premis:objectIdentifier[Type=UUID]`의 값이 파일 uuid; `premis:size` → sizeBytes; `premis:formatDesignation > formatName` → formatName; `FLocat@xlink:href` → originalRelativePath(`objects/...`), basename → filename
  - main dmdSec: `structMap[TYPE="physical"] > div > div@DMDID` → 해당 `dmdSec > mdWrap[MDTYPE="DC"] > xmlData > dublincore`에서 `dc:title`/`dc:description`/`dc:identifier`
  - 네임스페이스 프리픽스는 가변일 수 있으므로 fast-xml-parser에 `removeNSPrefix: true` 옵션 사용

- [ ] **Step 1: 픽스처 METS 작성**

Phase 1 build-fake-dip.sh와 동형의 METS를 픽스처로 작성 (핵심 구조만 — 파일 2개, main dmdSec 1개):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<mets:mets xmlns:mets="http://www.loc.gov/METS/" xmlns:premis="info:lc/xmlns/premis-v2"
  xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"
  xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <mets:metsHdr CREATEDATE="2026-07-01T09:00:00"/>
  <mets:dmdSec ID="dmdSec_1" CREATED="2026-07-01T09:00:00">
    <mets:mdWrap MDTYPE="DC"><mets:xmlData><dcterms:dublincore>
      <dc:title>Demo ingest DIP</dc:title>
      <dc:description>Fake DIP for parser contract tests</dc:description>
    </dcterms:dublincore></mets:xmlData></mets:mdWrap>
  </mets:dmdSec>
  <mets:amdSec ID="amdSec_1"><mets:techMD ID="techMD_1"><mets:mdWrap MDTYPE="PREMIS:OBJECT"><mets:xmlData>
    <premis:object xsi:type="premis:file">
      <premis:objectIdentifier><premis:objectIdentifierType>UUID</premis:objectIdentifierType>
        <premis:objectIdentifierValue>11111111-2222-3333-4444-555566660001</premis:objectIdentifierValue></premis:objectIdentifier>
      <premis:objectCharacteristics><premis:size>1048576</premis:size>
        <premis:format><premis:formatDesignation><premis:formatName>QuickTime</premis:formatName></premis:formatDesignation></premis:format>
      </premis:objectCharacteristics>
      <premis:originalName>%transferDirectory%objects/Demo_video.mov</premis:originalName>
    </premis:object>
  </mets:xmlData></mets:mdWrap></mets:techMD></mets:amdSec>
  <mets:amdSec ID="amdSec_2"><mets:techMD ID="techMD_2"><mets:mdWrap MDTYPE="PREMIS:OBJECT"><mets:xmlData>
    <premis:object xsi:type="premis:file">
      <premis:objectIdentifier><premis:objectIdentifierType>UUID</premis:objectIdentifierType>
        <premis:objectIdentifierValue>11111111-2222-3333-4444-555566660002</premis:objectIdentifierValue></premis:objectIdentifier>
      <premis:objectCharacteristics><premis:size>2048</premis:size>
        <premis:format><premis:formatDesignation><premis:formatName>Plain Text File</premis:formatName></premis:formatDesignation></premis:format>
      </premis:objectCharacteristics>
      <premis:originalName>%transferDirectory%objects/Demo_notes.txt</premis:originalName>
    </premis:object>
  </mets:xmlData></mets:mdWrap></mets:techMD></mets:amdSec>
  <mets:fileSec><mets:fileGrp USE="original">
    <mets:file ID="file-11111111-2222-3333-4444-555566660001" GROUPID="G1" ADMID="amdSec_1">
      <mets:FLocat xlink:href="objects/Demo_video.mov" LOCTYPE="OTHER" OTHERLOCTYPE="SYSTEM"/></mets:file>
    <mets:file ID="file-11111111-2222-3333-4444-555566660002" GROUPID="G2" ADMID="amdSec_2">
      <mets:FLocat xlink:href="objects/Demo_notes.txt" LOCTYPE="OTHER" OTHERLOCTYPE="SYSTEM"/></mets:file>
  </mets:fileGrp></mets:fileSec>
  <mets:structMap TYPE="physical" ID="structMap_1" LABEL="Archivematica default">
    <mets:div TYPE="Directory" LABEL="Demo_ingest-aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001">
      <mets:div TYPE="Directory" LABEL="objects" DMDID="dmdSec_1">
        <mets:div TYPE="Item" LABEL="Demo_video.mov"><mets:fptr FILEID="file-11111111-2222-3333-4444-555566660001"/></mets:div>
        <mets:div TYPE="Item" LABEL="Demo_notes.txt"><mets:fptr FILEID="file-11111111-2222-3333-4444-555566660002"/></mets:div>
      </mets:div>
    </mets:div>
  </mets:structMap>
</mets:mets>
```

objects/ 아래 두 파일은 아무 바이트나(예: `echo dummy >`).

- [ ] **Step 2: 실패 테스트**

`packages/integrations/test/parse-mets.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { parseAmDip, DipParseError } from '../src/ingest/parse-mets.js';

const FIX = join(dirname(fileURLToPath(import.meta.url)), 'fixtures', 'Demo_ingest-aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001');

describe('parseAmDip', () => {
  it('extracts aip uuid + dip name from directory name', async () => {
    const p = await parseAmDip(FIX);
    expect(p.aipUuid).toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001');
    expect(p.dipName).toBe('Demo_ingest');
  });
  it('reads main dmdSec DC via structMap DMDID', async () => {
    const p = await parseAmDip(FIX);
    expect(p.dc.title).toBe('Demo ingest DIP');
    expect(p.dc.description).toContain('parser contract');
    expect(p.dc.identifier).toBeNull();
  });
  it('maps files: uuid via ADMID→premis, size, format, FLocat path', async () => {
    const p = await parseAmDip(FIX);
    expect(p.files).toHaveLength(2);
    const mov = p.files.find((f) => f.filename === 'Demo_video.mov')!;
    expect(mov.uuid).toBe('11111111-2222-3333-4444-555566660001');
    expect(mov.sizeBytes).toBe(1048576);
    expect(mov.formatName).toBe('QuickTime');
    expect(mov.originalRelativePath).toBe('objects/Demo_video.mov');
  });
  it('parses metsHdr CREATEDATE', async () => {
    const p = await parseAmDip(FIX);
    expect(p.createdAt?.getUTCFullYear()).toBe(2026);
  });
  it('throws DipParseError when METS missing', async () => {
    await expect(parseAmDip('/nonexistent-dir')).rejects.toBeInstanceOf(DipParseError);
  });
});
```

Run: `pnpm --filter @binder/integrations test -- parse-mets`
Expected: FAIL (모듈 미존재)

- [ ] **Step 3: 구현**

`pnpm --filter @binder/integrations add fast-xml-parser@^4` 후 `src/ingest/parse-mets.ts`:

```ts
import { readdirSync, readFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { XMLParser } from 'fast-xml-parser';

export class DipParseError extends Error {
  constructor(msg: string) { super(msg); this.name = 'DipParseError'; }
}

export type ParsedDipFile = { uuid: string; originalRelativePath: string; filename: string; sizeBytes: number; formatName: string | null };
export type ParsedDip = {
  aipUuid: string; dipName: string; createdAt: Date | null;
  dc: { title: string | null; description: string | null; identifier: string | null };
  files: ParsedDipFile[];
};

const asArray = <T>(v: T | T[] | undefined): T[] => (v == null ? [] : Array.isArray(v) ? v : [v]);

export async function parseAmDip(dipDir: string): Promise<ParsedDip> {
  const dirName = basename(dipDir);
  const m = dirName.match(/-([0-9a-f-]{36})$/i);
  if (!m) throw new DipParseError(`DIP directory name lacks trailing uuid: ${dirName}`);
  const aipUuid = m[1].toLowerCase();
  const dipName = dirName.slice(0, -37);

  let metsFile: string | undefined;
  try {
    metsFile = readdirSync(dipDir).find((f) => /^METS\..*\.xml$/.test(f));
  } catch {
    throw new DipParseError(`DIP directory unreadable: ${dipDir}`);
  }
  if (!metsFile) throw new DipParseError(`no METS.*.xml in ${dipDir}`);

  const xml = new XMLParser({ ignoreAttributes: false, attributeNamePrefix: '@_', removeNSPrefix: true })
    .parse(readFileSync(join(dipDir, metsFile), 'utf8'));
  const mets = xml.mets;
  if (!mets) throw new DipParseError('root <mets> element missing');

  const createRaw = mets.metsHdr?.['@_CREATEDATE'];
  const createdAt = createRaw ? new Date(String(createRaw)) : null;

  // amdSec ID → { uuid, sizeBytes, formatName }
  const tech = new Map<string, { uuid: string; sizeBytes: number; formatName: string | null }>();
  for (const amd of asArray<any>(mets.amdSec)) {
    const obj = amd?.techMD?.mdWrap?.xmlData?.object;
    if (!obj) continue;
    const ids = asArray<any>(obj.objectIdentifier);
    const uuidEntry = ids.find((i) => String(i?.objectIdentifierType) === 'UUID');
    if (!uuidEntry) continue;
    tech.set(String(amd['@_ID']), {
      uuid: String(uuidEntry.objectIdentifierValue).toLowerCase(),
      sizeBytes: Number(obj.objectCharacteristics?.size ?? 0),
      formatName: obj.objectCharacteristics?.format?.formatDesignation?.formatName != null
        ? String(obj.objectCharacteristics.format.formatDesignation.formatName) : null,
    });
  }

  const files: ParsedDipFile[] = [];
  for (const grp of asArray<any>(mets.fileSec?.fileGrp)) {
    if (String(grp?.['@_USE']) !== 'original') continue;
    for (const f of asArray<any>(grp.file)) {
      const t = tech.get(String(f['@_ADMID']));
      const href = String(f.FLocat?.['@_href'] ?? '');
      if (!t || !href) continue;
      files.push({
        uuid: t.uuid, originalRelativePath: href, filename: href.split('/').pop() ?? href,
        sizeBytes: t.sizeBytes, formatName: t.formatName,
      });
    }
  }

  // main dmdSec via physical structMap div/div@DMDID
  let dc: ParsedDip['dc'] = { title: null, description: null, identifier: null };
  const structMaps = asArray<any>(mets.structMap);
  const physical = structMaps.find((s) => String(s?.['@_TYPE']) === 'physical') ?? structMaps[0];
  const dmdId = asArray<any>(physical?.div?.div)[0]?.['@_DMDID'] ?? physical?.div?.div?.['@_DMDID'];
  if (dmdId) {
    const dmd = asArray<any>(mets.dmdSec).find((d) => String(d['@_ID']) === String(dmdId));
    const core = dmd?.mdWrap?.xmlData?.dublincore;
    if (core) {
      dc = {
        title: core.title != null ? String(core.title) : null,
        description: core.description != null ? String(core.description) : null,
        identifier: core.identifier != null ? String(core.identifier) : null,
      };
    }
  }

  return { aipUuid, dipName, createdAt, dc, files };
}
```

`src/index.ts`에 `export { parseAmDip, DipParseError } from './ingest/parse-mets.js'; export type { ParsedDip, ParsedDipFile } from './ingest/parse-mets.js';` 추가.

- [ ] **Step 4: 게이트 + 커밋**

Run: `pnpm --filter @binder/integrations test && pnpm typecheck`
Expected: 그린.

```bash
git add -A && git commit -m "feat(integrations): am_dip METS parser ported from phase-1 contract"
```

---

### Task 5: 인제스트 오케스트레이션 (db) + POST /api/v1/ingest/am-dip (Bearer-only 게이트 신설)

**Files:**
- Create: `packages/db/src/queries/ingest.ts`
- Modify: `packages/db/src/index.ts`
- Modify: `apps/web/lib/api.ts` (`withBearerAuth` 추가)
- Create: `apps/web/app/api/v1/ingest/am-dip/route.ts`
- Create: `apps/web/lib/queue.ts`
- Modify: `apps/web/package.json` (bullmq), `apps/web/next.config.ts`(필요 시 bullmq server external — Task 내 판단)
- Test: `packages/db/test/ingest.test.ts` (신규)

**Interfaces:**
- Consumes: `parseAmDip`/`ParsedDip`(@binder/integrations, Task 4), `createAip`/`AipAlreadyExistsError`(기존), `ingestJobs.params`(Task 2), `hasPermission`(@binder/core, Task 2)
- Produces:
  - `createIngestJob(db, input: { adapter: 'am_dip'; idempotencyKey: string; packageRef: string; params: { targetNodeId: number; dipPath: string; backendId?: number }; createdBy: number | null }): Promise<{ id: string; created: boolean }>` — idempotency_key 충돌(23505) 시 기존 행 id 반환 + `created: false`
  - `runAmDipIngest(db, jobId: string, parsed: ParsedDip): Promise<{ aipId: number }>` — 상태 전이 `received→parsing→writing→indexed`(각 전이는 UPDATE), 실패 시 `status='failed'` + `error` JSONB `{ name, message }` 기록 후 rethrow. AIP 생성은 `createAip` 재사용: `{ uuid: parsed.aipUuid, nodeId/rootNodeId: params.targetNodeId (rootNodeId는 노드의 root — getSubtree 말고 nodes.root_node_id 컬럼이 없으므로 targetNode의 rootNodeId 결정 방식은 기존 createAip 테스트 픽스처와 동일하게 nodeId의 최상위 조상; 재귀 CTE 1회), origin: 'archivematica', filename: parsed.dipName, sizeBytes: 파일 합, fileCount, metsSha256: METS 파일 sha256, ingestJobId: jobId, files: parsed.files 매핑, copies: params.backendId 있으면 [{ backendId, locator: parsed.aipUuid, isPrimary: true }] }`. 동일 uuid 라이브 AIP 존재(AipAlreadyExistsError) 시 failed 처리.
  - web: `withBearerAuth(handler)` — `Authorization: Bearer <token>`만 수용(쿠키 무시), 검증은 `validateSession` 재사용. 401 `{ error: 'unauthorized' }`, Bearer 아닌 인증 시도도 401.
  - web: `POST /api/v1/ingest/am-dip` body `{ dipPath: string, targetAccession: string, backendId?: number }` → `ingest:write` 권한 필수(403 `{ error: 'forbidden' }`), accession으로 라이브 노드 해석(404), `parseAmDip`로 uuid 선추출(400 on DipParseError), `createIngestJob`(idempotencyKey = `am_dip:${aipUuid}`) → BullMQ `ingest` 큐에 `{ ingestJobId }` enqueue → 202 `{ jobId, created }`. 중복 제출은 202 + `created: false` (재-enqueue 하지 않음).
  - `apps/web/lib/queue.ts`: `getIngestQueue(): Queue` — bullmq Queue 싱글턴, `new Queue(QUEUES.ingest, { connection: { url: process.env.REDIS_URL ?? 'redis://localhost:16379' } })`.

- [ ] **Step 1: 실패 테스트 (db)**

`packages/db/test/ingest.test.ts` — 기존 db 테스트 setup 패턴 재사용. ParsedDip은 리터럴로 구성(integrations 임포트 불요 — 구조 타입):

```ts
const parsed = {
  aipUuid: 'cccccccc-1111-2222-3333-444455556666', dipName: 'Test_ingest', createdAt: new Date('2026-07-01T09:00:00Z'),
  dc: { title: 'Test ingest DIP', description: null, identifier: null },
  files: [
    { uuid: 'dddddddd-1111-2222-3333-444455556666', originalRelativePath: 'objects/a.mov', filename: 'a.mov', sizeBytes: 100, formatName: 'QuickTime' },
    { uuid: 'eeeeeeee-1111-2222-3333-444455556666', originalRelativePath: 'objects/b.txt', filename: 'b.txt', sizeBytes: 50, formatName: null },
  ],
};

it('createIngestJob is idempotent on key', async () => {
  const a = await createIngestJob(db, { adapter: 'am_dip', idempotencyKey: 'am_dip:x1', packageRef: '/tmp/d', params: { targetNodeId: nodeId, dipPath: '/tmp/d' }, createdBy: null });
  const b = await createIngestJob(db, { adapter: 'am_dip', idempotencyKey: 'am_dip:x1', packageRef: '/tmp/d', params: { targetNodeId: nodeId, dipPath: '/tmp/d' }, createdBy: null });
  expect(a.created).toBe(true); expect(b.created).toBe(false); expect(b.id).toBe(a.id);
});

it('runAmDipIngest writes aip+files+rollups and marks indexed', async () => {
  const job = await createIngestJob(db, { adapter: 'am_dip', idempotencyKey: `am_dip:${parsed.aipUuid}`, packageRef: '/tmp/d', params: { targetNodeId: nodeId, dipPath: '/tmp/d' }, createdBy: null });
  const { aipId } = await runAmDipIngest(db, job.id, parsed);
  const [aip] = await db.execute(sql`SELECT uuid, size_bytes, file_count, ingest_job_id FROM aips WHERE id = ${aipId}`) as any;
  expect(aip.uuid).toBe(parsed.aipUuid);
  expect(Number(aip.size_bytes)).toBe(150);
  expect(Number(aip.file_count)).toBe(2);
  expect(aip.ingest_job_id).toBe(job.id);
  const [j] = await db.execute(sql`SELECT status FROM ingest_jobs WHERE id = ${job.id}`) as any;
  expect(j.status).toBe('indexed');
});

it('duplicate live uuid → job failed with error recorded', async () => {
  // 위 테스트가 남긴 라이브 AIP와 동일 uuid로 새 잡 실행
  const job = await createIngestJob(db, { adapter: 'am_dip', idempotencyKey: 'am_dip:dup-run', packageRef: '/tmp/d', params: { targetNodeId: nodeId, dipPath: '/tmp/d' }, createdBy: null });
  await expect(runAmDipIngest(db, job.id, parsed)).rejects.toThrow();
  const [j] = await db.execute(sql`SELECT status, error FROM ingest_jobs WHERE id = ${job.id}`) as any;
  expect(j.status).toBe('failed');
  expect(j.error).toBeTruthy();
});
```

Run: `pnpm --filter @binder/db test -- ingest` → FAIL 확인.

- [ ] **Step 2: db 구현**

`packages/db/src/queries/ingest.ts`:

```ts
import { and, eq, isNull, sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { ingestJobs, nodes } from '../schema/index.js';
import { createAip, AipAlreadyExistsError } from './aips.js';

type ParsedDipLike = {
  aipUuid: string; dipName: string; createdAt: Date | null;
  dc: { title: string | null; description: string | null; identifier: string | null };
  files: Array<{ uuid: string; originalRelativePath: string; filename: string; sizeBytes: number; formatName: string | null }>;
};

export type IngestParams = { targetNodeId: number; dipPath: string; backendId?: number; metsSha256?: string };

export async function createIngestJob(
  db: PostgresJsDatabase,
  input: { adapter: 'am_dip'; idempotencyKey: string; packageRef: string; params: IngestParams; createdBy: number | null },
): Promise<{ id: string; created: boolean }> {
  const inserted = await db.insert(ingestJobs)
    .values({ adapter: input.adapter, idempotencyKey: input.idempotencyKey, packageRef: input.packageRef, params: input.params, createdBy: input.createdBy })
    .onConflictDoNothing({ target: ingestJobs.idempotencyKey })
    .returning({ id: ingestJobs.id });
  if (inserted.length > 0) return { id: inserted[0].id, created: true };
  const [existing] = await db.select({ id: ingestJobs.id }).from(ingestJobs).where(eq(ingestJobs.idempotencyKey, input.idempotencyKey));
  return { id: existing.id, created: false };
}

async function setStatus(db: PostgresJsDatabase, jobId: string, status: 'parsing' | 'writing' | 'indexed'): Promise<void> {
  await db.update(ingestJobs).set({ status }).where(eq(ingestJobs.id, jobId));
}

async function resolveRootId(db: PostgresJsDatabase, nodeId: number): Promise<number> {
  const rows = (await db.execute(sql`
    WITH RECURSIVE up AS (
      SELECT id, parent_id FROM nodes WHERE id = ${nodeId}
      UNION ALL
      SELECT n.id, n.parent_id FROM nodes n JOIN up ON n.id = up.parent_id
    ) SELECT id FROM up WHERE parent_id IS NULL
  `)) as unknown as Array<{ id: number }>;
  return rows.length ? Number(rows[0].id) : nodeId;
}

export async function runAmDipIngest(db: PostgresJsDatabase, jobId: string, parsed: ParsedDipLike): Promise<{ aipId: number }> {
  const [job] = await db.select().from(ingestJobs).where(eq(ingestJobs.id, jobId));
  if (!job) throw new Error(`unknown ingest job: ${jobId}`);
  const params = (job.params ?? {}) as IngestParams;
  try {
    await setStatus(db, jobId, 'parsing');
    const [target] = await db.select({ id: nodes.id }).from(nodes)
      .where(and(eq(nodes.id, params.targetNodeId), isNull(nodes.deletedAt)));
    if (!target) throw new Error(`target node missing or deleted: ${params.targetNodeId}`);
    const rootNodeId = await resolveRootId(db, params.targetNodeId);

    await setStatus(db, jobId, 'writing');
    const aip = await createAip(db, {
      uuid: parsed.aipUuid,
      nodeId: params.targetNodeId,
      rootNodeId,
      origin: 'archivematica',
      filename: parsed.dipName,
      sizeBytes: parsed.files.reduce((s, f) => s + f.sizeBytes, 0),
      // fileCount는 CreateAipInput에 없음 — createAip이 files 배열 길이로 내부 계산한다. 넣지 말 것.
      metsSha256: params.metsSha256,   // optional string — null 금지(?? null은 타입 에러), 없으면 생략
      ingestJobId: jobId,
      copies: params.backendId ? [{ backendId: params.backendId, locator: parsed.aipUuid, isPrimary: true }] : [],
      files: parsed.files.map((f) => ({
        originalRelativePath: f.originalRelativePath, filename: f.filename,
        sizeBytes: f.sizeBytes, formatName: f.formatName ?? undefined,   // FileInput.formatName?: string
      })),
    });
    await db.update(ingestJobs).set({ status: 'indexed', finishedAt: new Date() }).where(eq(ingestJobs.id, jobId));
    return { aipId: aip.id };
  } catch (err) {
    const e = err as Error;
    await db.update(ingestJobs)
      .set({ status: 'failed', finishedAt: new Date(), error: { name: e.name, message: e.message } })
      .where(eq(ingestJobs.id, jobId));
    throw err;
  }
}
```

**주의:** 위 `createAip` 호출부는 실제 `CreateAipInput` 타입(리뷰로 검증됨: `metsSha256?: string` 존재, `fileCount` 없음, copies/files 형태 위와 일치)에 맞춘 것이다. 구현 시 `packages/db/src/queries/aips.ts`를 한 번 열어 대조하고, 캐스트 없이 타입 체크를 통과시킬 것.

`packages/db/src/index.ts`에 export 추가:

```ts
export { createIngestJob, runAmDipIngest } from './queries/ingest.js';
export type { IngestParams } from './queries/ingest.js';
```

- [ ] **Step 3: 테스트 통과 확인**

Run: `pnpm --filter @binder/db test -- ingest` → PASS. `pnpm --filter @binder/db test` 전체 그린.

- [ ] **Step 4: web — withBearerAuth + 라우트 + 큐**

`apps/web/lib/api.ts`에 추가 (기존 withAuth 아래):

```ts
// State-changing /api/v1 routes are Bearer-only: the Authorization header cannot be set by
// cross-origin forms/fetches, which closes CSRF without a token dance. Cookie-authenticated
// mutations happen only via Server Actions (Next.js origin-checks those). Ledger precondition.
export function withBearerAuth(
  handler: (req: NextRequest, user: SessionUser) => Promise<NextResponse>,
): (req: NextRequest) => Promise<NextResponse> {
  return async (req) => {
    const header = req.headers.get('authorization') ?? '';
    if (!/^Bearer\s+/i.test(header)) return NextResponse.json({ error: 'unauthorized' }, { status: 401 });
    const token = header.replace(/^Bearer\s+/i, '');
    const user = await validateSession(getDb(), token);
    if (!user) return NextResponse.json({ error: 'unauthorized' }, { status: 401 });
    return handler(req, user);
  };
}
```

`apps/web/lib/queue.ts`:

```ts
import 'server-only';
import { Queue } from 'bullmq';
import { QUEUES } from '@binder/integrations';

let ingestQueue: Queue | null = null;

export function getIngestQueue(): Queue {
  if (!ingestQueue) {
    ingestQueue = new Queue(QUEUES.ingest, {
      connection: { url: process.env.REDIS_URL ?? 'redis://localhost:16379' },
    });
  }
  return ingestQueue;
}
```

`pnpm --filter web add bullmq@^5` + `pnpm --filter web add @binder/integrations@workspace:*`.

**필수:** `apps/web/next.config.ts`의 `transpilePackages` 배열(현재 `['@binder/db', '@binder/core']`)에 `'@binder/integrations'` 추가 — 워크스페이스 패키지는 TS 소스를 직접 export하므로 이것 없이는 `pnpm --filter web build`가 깨진다. (bullmq가 웹팩 번들에서 문제 일으키면 `serverExternalPackages`에 `'bullmq'` 추가 — Plan 04 native externals 선례 참조.)

`apps/web/app/api/v1/ingest/am-dip/route.ts`:

```ts
import { NextResponse, type NextRequest } from 'next/server';
import { and, eq, isNull } from 'drizzle-orm';
import { schema, createIngestJob } from '@binder/db';
import { parseAmDip, DipParseError } from '@binder/integrations';
import { hasPermission } from '@binder/core';
import { getDb } from '@/lib/db';
import { withBearerAuth } from '@/lib/api';
import { getIngestQueue } from '@/lib/queue';

export const POST = withBearerAuth(async (req: NextRequest, user) => {
  if (!hasPermission(user.permissions, 'ingest:write')) {
    return NextResponse.json({ error: 'forbidden' }, { status: 403 });
  }
  let body: { dipPath?: string; targetAccession?: string; backendId?: number };
  try { body = await req.json(); } catch { return NextResponse.json({ error: 'invalid json' }, { status: 400 }); }
  if (!body.dipPath || !body.targetAccession) {
    return NextResponse.json({ error: 'dipPath and targetAccession are required' }, { status: 400 });
  }

  const db = getDb();
  const { nodes } = schema;
  const [target] = await db.select({ id: nodes.id }).from(nodes)
    .where(and(eq(nodes.accessionNumber, body.targetAccession), isNull(nodes.deletedAt)));
  if (!target) return NextResponse.json({ error: 'target node not found' }, { status: 404 });

  let aipUuid: string;
  try { aipUuid = (await parseAmDip(body.dipPath)).aipUuid; }
  catch (e) {
    if (e instanceof DipParseError) return NextResponse.json({ error: e.message }, { status: 400 });
    throw e;
  }

  const job = await createIngestJob(db, {
    adapter: 'am_dip', idempotencyKey: `am_dip:${aipUuid}`, packageRef: body.dipPath,
    params: { targetNodeId: target.id, dipPath: body.dipPath, backendId: body.backendId },
    createdBy: user.userId,
  });
  if (job.created) await getIngestQueue().add('am-dip', { ingestJobId: job.id });
  return NextResponse.json({ jobId: job.id, created: job.created }, { status: 202 });
});
```

- [ ] **Step 5: 수동 검증 (curl)**

dev 서버 기동 후 (`pnpm dev -p 3200` 등, redis/postgres up):

```bash
# 토큰 발급: node 원라이너로 createSession (또는 기존 db 테스트 헬퍼 스크립트)
TOKEN=$(cd packages/db && pnpm exec tsx -e "import('./src/index.js').then(async m=>{const {db}=m.createDb(process.env.DATABASE_URL??'postgres://binder:binder@localhost:15432/binder');const s=await m.createSession(db,1);console.log(s.token);process.exit(0)})")
# createDb는 { db, sql }을 반환한다 — 구조분해 필수. users가 비어 있으면 먼저 데모 admin 생성:
#   pnpm --filter @binder/db db:create-user demo demo@example.com demo admin
# (id 1 = 처음 생성된 유저. demo가 admin이면 '*' 권한으로 ingest:write 충족)
# 401: 쿠키로는 거부되는지 (쿠키만 보내면 401이어야 Bearer-only가 증명됨)
curl -si -X POST localhost:3200/api/v1/ingest/am-dip -H 'content-type: application/json' --cookie "binder_session=$TOKEN" -d '{}' | head -1   # HTTP/1.1 401
# 202: 픽스처 DIP 경로 + 데모 accession
curl -si -X POST localhost:3200/api/v1/ingest/am-dip -H "authorization: Bearer $TOKEN" -H 'content-type: application/json' \
  -d '{"dipPath":"'$PWD'/packages/integrations/test/fixtures/Demo_ingest-aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001","targetAccession":"1250.2011"}' | head -1  # HTTP/1.1 202
# 동일 재제출 → 202 + created:false
```

(user id 1이 admin이 아니면 실제 admin 유저 id로 조정. 큐에 쌓인 잡은 Task 6 워커가 소비 — 여기서는 202 응답과 ingest_jobs 행 생성까지만 검증.)

- [ ] **Step 6: 게이트 + 커밋**

Run: `pnpm test && pnpm typecheck && pnpm --filter web build` → 그린.

```bash
git add -A && git commit -m "feat(web,db): am_dip ingest job api with bearer-only auth gate"
```

---

### Task 6: apps/worker — BullMQ fixity 크론 + 인제스트 컨슈머

**Files:**
- Create: `apps/worker/package.json`, `apps/worker/tsconfig.json`, `apps/worker/vitest.config.ts`
- Create: `apps/worker/src/index.ts` (부트: 워커 2개 + repeatable 등록 + graceful shutdown)
- Create: `apps/worker/src/jobs/fixity-scan.ts`
- Create: `apps/worker/src/jobs/ingest.ts`
- Create: `apps/worker/src/notify.ts`
- Modify: 루트 `.env.example` (FIXITY_CRON, FIXITY_WEBHOOK_URL, SS_*)
- Test: `apps/worker/test/fixity-scan.test.ts`, `apps/worker/test/queue-roundtrip.test.ts`

**Interfaces:**
- Consumes: `getBackendForRow`/`QUEUES`/`IngestJobPayload`/`UnsupportedOperationError`(@binder/integrations), `registerFixityReport`/`runAmDipIngest`/`parseAmDip`/`createDb`/`schema`(@binder/db·integrations)
- Produces:
  - `runFixityScan(db, opts?: { sessionUuid?: string }): Promise<{ scanned: number; failed: number; skipped: number }>` — 순수 함수형 잡 본체(테스트 대상). 로직: enabled 백엔드 전체 조회 → 각 백엔드 `getBackendForRow`; `supportsFixity === false`면 해당 백엔드의 카피 수만큼 skipped 집계(1줄 로그); 지원 백엔드는 라이브 AIP(aips.deleted_at IS NULL)의 aip_copies를 조인해 각각 `checkFixity(locator)` → `registerFixityReport({ aipUuid, success, message, failures, sessionUuid, startedAt, completedAt })` + `aip_copies.last_verified_at = now`, `status = success ? 'ok' : status` 갱신. 개별 카피 실패(BackendRequestError)는 해당 AIP를 `success: false, message: 'backend unreachable: ...'`로 기록하고 계속(전체 스캔 중단 금지).
  - 스캔 종료 후 실패 > 0이면 `notifyFixityFailures({ sessionUuid, failures: [{ aipUuid, message }] })` 호출 — `FIXITY_WEBHOOK_URL` 설정 시 POST(JSON), 미설정 시 console.warn 1줄.
  - `runIngestJob(db, payload: IngestJobPayload): Promise<void>` — ingest_jobs 행에서 params.dipPath 읽어 `parseAmDip` → `runAmDipIngest`. (파싱을 워커에서 다시 하는 이유: 라우트의 선파싱은 uuid 추출용 경량 검증이고, 워커가 단일 진실 실행 경로 — 주석으로 명시.)
  - `src/index.ts`: `new Worker(QUEUES.fixity, ...)` + `new Worker(QUEUES.ingest, ...)` (connection = REDIS_URL), `Queue(QUEUES.fixity).upsertJobScheduler('fixity-cron', { pattern: process.env.FIXITY_CRON ?? '0 3 * * *' })` (bullmq v5 Job Scheduler API — 구버전 repeat API를 쓰지 말 것), SIGINT/SIGTERM에서 `await worker.close()` ×2 + queue close.
  - package.json scripts: `dev`(tsx watch src/index.ts), `test`(vitest run), `typecheck`(tsc --noEmit) — **`build` 스크립트 없음**(core/db 패턴, 실행은 tsx). name은 `worker`.

- [ ] **Step 1: 실패 테스트 — fixity 스캔 (픽스처 SS 서버 + 실 PG)**

`apps/worker/test/fixity-scan.test.ts` — db 테스트 하네스 재사용 방법 — **주의: 루트 `pnpm test`는 turbo가 패키지 테스트를 병렬 실행한다.** db 패키지의 global-setup은 `binder_test`를 DROP+재생성하므로 그대로 참조하면 병렬 실행 중 db 테스트의 DB를 날릴 수 있다. 반드시 워커 전용 테스트 DB를 쓴다: `apps/worker/test/global-setup.ts`를 새로 만들어 `process.env.DATABASE_URL_TEST = process.env.DATABASE_URL_TEST_WORKER ?? 'postgres://binder:binder@localhost:15432/binder_test_worker';`를 설정한 뒤 `packages/db/test/global-setup.ts`의 setup 함수를 import해 위임 호출(해당 파일이 env를 읽는 시점을 확인해 동작 보장). 워커 테스트의 db 연결 헬퍼도 같은 env를 읽도록 워커 로컬로 작성(`packages/db/test`의 헬퍼를 무변경 import하면 기본 `binder_test`를 가리키므로 금지). 픽스처: 테스트 DB에 storage_backends(am_ss) 1행 + 노드 + AIP 2건(라이브 1, soft-deleted 1) + 카피, Task 3 스타일 node:http SS 목(1건 success:true 응답, 1건은 없어야 함 — deleted 제외 검증). SS_URL env를 목 서버 주소로 지정:

```ts
it('scans live copies on fixity-capable backends, skips deleted aips', async () => {
  process.env.SS_URL = mockBaseUrl; process.env.SS_USER = 'u'; process.env.SS_API_KEY = 'k';
  const r = await runFixityScan(db, { sessionUuid: '99999999-0000-0000-0000-000000000001' });
  expect(r.scanned).toBe(1);          // deleted AIP의 카피는 스캔 안 함
  expect(requestedUuids).toEqual([liveUuid]);  // 목 서버가 받은 요청 기록
  const reports = await db.execute(sql`SELECT success FROM fixity_reports WHERE session_uuid = '99999999-0000-0000-0000-000000000001'`);
  expect(reports).toHaveLength(1);
});

it('local_fs backends are skipped (unsupported fixity)', async () => {
  // demo local_fs 백엔드 + 카피 픽스처 → skipped 집계 확인
});

it('backend failure records success:false and continues', async () => {
  // 목 서버 500 응답 → success:false 리포트 + scanned 계속
});
```

Run → FAIL 확인.

- [ ] **Step 2: 구현 (fixity-scan.ts + notify.ts)**

`src/jobs/fixity-scan.ts`:

```ts
import { randomUUID } from 'node:crypto';
import { sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { registerFixityReport } from '@binder/db';
import { getBackendForRow, UnsupportedOperationError } from '@binder/integrations';
import { notifyFixityFailures } from '../notify.js';

export async function runFixityScan(
  db: PostgresJsDatabase,
  opts: { sessionUuid?: string } = {},
): Promise<{ scanned: number; failed: number; skipped: number }> {
  const sessionUuid = opts.sessionUuid ?? randomUUID();
  let scanned = 0, failed = 0, skipped = 0;
  const failures: Array<{ aipUuid: string; message: string }> = [];

  const backends = (await db.execute(sql`
    SELECT id, kind, name, config FROM storage_backends WHERE enabled = true
  `)) as unknown as Array<{ id: number; kind: string; name: string; config: Record<string, unknown> }>;

  for (const b of backends) {
    const copies = (await db.execute(sql`
      SELECT c.id AS copy_id, c.locator, a.uuid AS aip_uuid
      FROM aip_copies c JOIN aips a ON a.id = c.aip_id AND a.deleted_at IS NULL
      WHERE c.backend_id = ${b.id}
    `)) as unknown as Array<{ copy_id: number; locator: string; aip_uuid: string }>;

    let backend;
    try { backend = getBackendForRow(b); } catch (e) {
      if (e instanceof UnsupportedOperationError) { skipped += copies.length; continue; }
      throw e;
    }
    if (!backend.supportsFixity) { skipped += copies.length; console.warn(`fixity: skipping backend ${b.name} (unsupported)`); continue; }

    for (const c of copies) {
      const startedAt = new Date();
      let success = false, message = '', fx: Record<string, unknown> = {};
      try {
        const r = await backend.checkFixity(c.locator);
        success = r.success; message = r.message; fx = r.failures;
      } catch (e) {
        message = `backend unreachable: ${(e as Error).message}`;
      }
      const completedAt = new Date();
      await registerFixityReport(db, { aipUuid: c.aip_uuid, success, message: message || undefined, failures: fx, sessionUuid, startedAt, completedAt });
      await db.execute(sql`
        UPDATE aip_copies SET last_verified_at = ${completedAt}, status = ${success ? 'ok' : 'lost'}::copy_status
        WHERE id = ${c.copy_id}
      `);
      scanned += 1;
      if (!success) { failed += 1; failures.push({ aipUuid: c.aip_uuid, message }); }
    }
  }

  if (failed > 0) await notifyFixityFailures({ sessionUuid, failures });
  return { scanned, failed, skipped };
}
```

(카피 status 'lost' 전이는 잠정 정책 — 실패=lost가 과격하면 구현 중 'recovering'과 비교해 판단하고 리포트에 근거 기록. 단, 성공 시 'ok' 복귀는 고정.)

`src/notify.ts`:

```ts
export async function notifyFixityFailures(payload: { sessionUuid: string; failures: Array<{ aipUuid: string; message: string }> }): Promise<void> {
  const url = process.env.FIXITY_WEBHOOK_URL;
  if (!url) { console.warn(`fixity failures (${payload.failures.length}) session=${payload.sessionUuid} — no FIXITY_WEBHOOK_URL, log only`); return; }
  try {
    await fetch(url, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(payload) });
  } catch (e) {
    console.error(`fixity webhook failed: ${(e as Error).message}`);
  }
}
```

`src/jobs/ingest.ts`:

```ts
import { sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { runAmDipIngest } from '@binder/db';
import { parseAmDip, type IngestJobPayload } from '@binder/integrations';

// The route pre-parses only to derive the idempotency uuid; the worker re-parse here is the
// single execution path of record (route and worker never share parsed state).
export async function runIngestJob(db: PostgresJsDatabase, payload: IngestJobPayload): Promise<void> {
  const [job] = (await db.execute(sql`SELECT id, params FROM ingest_jobs WHERE id = ${payload.ingestJobId}`)) as unknown as Array<{ id: string; params: { dipPath: string } }>;
  if (!job) throw new Error(`unknown ingest job ${payload.ingestJobId}`);
  const parsed = await parseAmDip(job.params.dipPath);
  await runAmDipIngest(db, job.id, parsed);
}
```

- [ ] **Step 3: 부트스트랩 + 라운드트립 테스트**

`src/index.ts`:

```ts
import { Queue, Worker } from 'bullmq';
import { createDb } from '@binder/db';
import { QUEUES, type IngestJobPayload } from '@binder/integrations';
import { runFixityScan } from './jobs/fixity-scan.js';
import { runIngestJob } from './jobs/ingest.js';

const connection = { url: process.env.REDIS_URL ?? 'redis://localhost:16379' };
const db = createDb(process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder');

const fixityQueue = new Queue(QUEUES.fixity, { connection });
await fixityQueue.upsertJobScheduler('fixity-cron', { pattern: process.env.FIXITY_CRON ?? '0 3 * * *' });

const fixityWorker = new Worker(QUEUES.fixity, async () => {
  const r = await runFixityScan(db);
  console.log(`fixity scan done: scanned=${r.scanned} failed=${r.failed} skipped=${r.skipped}`);
}, { connection });

const ingestWorker = new Worker<IngestJobPayload>(QUEUES.ingest, async (job) => {
  await runIngestJob(db, job.data);
}, { connection });

for (const w of [fixityWorker, ingestWorker]) {
  w.on('failed', (job, err) => console.error(`${w.name} job ${job?.id} failed: ${err.message}`));
}

async function shutdown() {
  await Promise.all([fixityWorker.close(), ingestWorker.close(), fixityQueue.close()]);
  process.exit(0);
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
console.log('binder worker up: queues fixity+ingest');
```

`apps/worker/test/queue-roundtrip.test.ts` — 실 redis(:16379, 테스트는 prefix로 격리) 왕복 1건:

```ts
import { describe, it, expect } from 'vitest';
import { Queue, Worker } from 'bullmq';

const connection = { url: (process.env.REDIS_URL ?? 'redis://localhost:16379') };

describe('bullmq roundtrip', () => {
  it('worker consumes an enqueued job (real redis)', async () => {
    const qname = `test-rt-${Date.now()}`;
    const queue = new Queue(qname, { connection });
    const seen: unknown[] = [];
    const worker = new Worker(qname, async (job) => { seen.push(job.data); }, { connection });
    await queue.add('j', { hello: 'world' });
    await new Promise((r) => setTimeout(r, 1500));
    expect(seen).toEqual([{ hello: 'world' }]);
    await worker.close(); await queue.obliterate({ force: true }); await queue.close();
  }, 15_000);
});
```

(큐 이름에 타임스탬프 — 잔류 키 충돌 방지. obliterate로 정리.)

- [ ] **Step 4: 실동 확인 — Task 5의 잡 소비**

Run: `pnpm --filter worker dev` (백그라운드) → Task 5 Step 5에서 202로 쌓인 잡이 소비되어 `ingest_jobs.status = 'indexed'`가 되는지 psql로 확인:

```bash
docker exec -i $(docker ps -qf name=postgres) psql -U binder -d binder -c "SELECT status FROM ingest_jobs ORDER BY started_at DESC LIMIT 1;"
```

fixity 수동 1회: `pnpm --filter worker exec tsx -e "..."`로 `runFixityScan(db)` 직접 호출 → demo local_fs 백엔드는 skipped, 결과 로그 확인. 워커 종료.

- [ ] **Step 5: 게이트 + 커밋**

Run: `pnpm test && pnpm typecheck` → 전체 그린 (worker 패키지 포함).

```bash
git add -A && git commit -m "feat(worker): bullmq fixity cron and ingest consumer"
```

---

### Task 7: 복구·다운로드 서버측 (라우트 + 서버 액션 + NodeDetail 확장)

**Files:**
- Modify: `packages/db/src/queries/fixity.ts` (`openRecoveriesByAip` 추가, `finishRecoveryByEventRef` 추가, `startRecovery` 추가)
- Modify: `packages/db/src/queries/detail.ts` (aips에 `openRecovery` 필드)
- Modify: `packages/db/src/index.ts`
- Create: `apps/web/lib/actions/recovery.ts` (Server Action)
- Create: `apps/web/app/api/v1/recovery-results/route.ts`
- Create: `apps/web/app/api/v1/aips/[uuid]/download/route.ts`
- Modify: `apps/web/lib/api.ts` (`withBasicAuth`)
- Test: `packages/db/test/fixity-queries.test.ts` (기존 파일에 추가), curl 증거 (Step 5)

**Interfaces:**
- Consumes: `getBackendForRow`(@binder/integrations), `insertDownloadLog`(Task 2), `hasPermission`(Task 2), `openRecoveryExists`(기존)
- Produces:
  - `openRecoveriesByAip(db, aipIds: number[]): Promise<Set<number>>` — `finished_at IS NULL`인 aip_id 집합 (배치, detail용)
  - `startRecovery(db, input: { aipId: number; userId: number; backendId: number; externalEventRef: string }): Promise<number>` — fixity_recoveries INSERT, id 반환. **호출 전 가드는 액션에서** (openRecoveryExists)
  - `finishRecoveryByEventRef(db, input: { externalEventRef: string; success: boolean; message: string | null }): Promise<'ok' | 'not_found' | 'already_finished'>` — `external_event_ref` 매칭; `finished_at IS NOT NULL`이면 'already_finished'; 매칭 시 success/message/finished_at 기록
  - `NodeDetail['aips'][number]`에 `openRecovery: boolean` 추가 (openRecoveriesByAip 배치 조인)
  - Server Action `requestRecoveryAction(aipId: number): Promise<{ ok: true } | { ok: false; error: string }>` — requireUser + `hasPermission(perms,'aips:recover')`(403 상당 에러 반환), openRecoveryExists 가드('이미 진행 중'), aip(라이브)+primary 카피의 am_ss 백엔드 해석(local_fs만 있으면 '복구를 지원하지 않는 저장소' 에러), `backend.requestRecovery(locator, { reason: 'Request from Binder', userId, userEmail })`, `startRecovery(...)`, `writeAudit` (기존 audit 패턴), `revalidatePath('/records/[slug]')` 대신 호출측 revalidate — 액션 내 `revalidatePath(path)`는 인자로 slug 받아 처리
  - `POST /api/v1/recovery-results` — `withBasicAuth`: `Authorization: Basic base64(user:pass)`를 `RECOVERY_CALLBACK_USER/PASS`와 상수시간 비교(`crypto.timingSafeEqual`), env 미설정 시 503 `{ error: 'callback auth not configured' }`. body `{ event_id, success, message }` → `finishRecoveryByEventRef({ externalEventRef: String(event_id), ... })` → 'ok'→200 `{ ok: true }`, 'not_found'→404, 'already_finished'→409
  - `GET /api/v1/aips/[uuid]/download?reason=...` — `withAuth`(쿠키 허용 — 로그 남는 읽기, 주석 필수) + `hasPermission('aips:download')`(403), reason 공백→400, 라이브 aip 조회(404), primary 카피(없으면 첫 카피, 그것도 없으면 404 `{ error: 'no stored copy' }`) → `getBackendForRow` → `backend.download(locator)` → `insertDownloadLog` → `new NextResponse(stream, { headers: { 'content-disposition': attachment; filename*, 'content-type': 'application/x-tar', 'content-length': sizeBytes? } })`. `BackendRequestError` → 502 `{ error }` (status null이면 404)

- [ ] **Step 1: 실패 테스트 (db)**

`packages/db/test/fixity-queries.test.ts`에 추가:

```ts
it('openRecoveriesByAip returns only unfinished recoveries', async () => {
  const r1 = await startRecovery(db, { aipId: aipA, userId, backendId, externalEventRef: 'ev-1' });
  await startRecovery(db, { aipId: aipB, userId, backendId, externalEventRef: 'ev-2' });
  await finishRecoveryByEventRef(db, { externalEventRef: 'ev-1', success: true, message: null });
  const open = await openRecoveriesByAip(db, [aipA, aipB]);
  expect(open.has(aipA)).toBe(false);
  expect(open.has(aipB)).toBe(true);
});

it('finishRecoveryByEventRef: not_found / ok / already_finished', async () => {
  expect(await finishRecoveryByEventRef(db, { externalEventRef: 'nope', success: true, message: null })).toBe('not_found');
  await startRecovery(db, { aipId: aipA, userId, backendId, externalEventRef: 'ev-3' });
  expect(await finishRecoveryByEventRef(db, { externalEventRef: 'ev-3', success: false, message: 'restore failed' })).toBe('ok');
  expect(await finishRecoveryByEventRef(db, { externalEventRef: 'ev-3', success: true, message: null })).toBe('already_finished');
  const [row] = await db.execute(sql`SELECT success, message FROM fixity_recoveries WHERE external_event_ref = 'ev-3'`) as any;
  expect(row.success).toBe(false);
  expect(row.message).toBe('restore failed');
});
```

Run → FAIL 확인.

- [ ] **Step 2: db 구현**

`packages/db/src/queries/fixity.ts`에 추가:

```ts
export async function startRecovery(
  db: PostgresJsDatabase,
  input: { aipId: number; userId: number; backendId: number; externalEventRef: string },
): Promise<number> {
  const [row] = await db.insert(fixityRecoveries).values(input).returning({ id: fixityRecoveries.id });
  return row.id;
}

export async function openRecoveriesByAip(db: PostgresJsDatabase, aipIds: number[]): Promise<Set<number>> {
  if (aipIds.length === 0) return new Set();
  const rows = (await db.execute(sql`
    SELECT DISTINCT aip_id FROM fixity_recoveries
    WHERE finished_at IS NULL AND aip_id IN ${sql`(${sql.join(aipIds.map((i) => sql`${i}`), sql`, `)})`}
  `)) as unknown as Array<{ aip_id: number }>;
  return new Set(rows.map((r) => Number(r.aip_id)));
}

export async function finishRecoveryByEventRef(
  db: PostgresJsDatabase,
  input: { externalEventRef: string; success: boolean; message: string | null },
): Promise<'ok' | 'not_found' | 'already_finished'> {
  const [row] = await db.select({ id: fixityRecoveries.id, finishedAt: fixityRecoveries.finishedAt })
    .from(fixityRecoveries).where(eq(fixityRecoveries.externalEventRef, input.externalEventRef));
  if (!row) return 'not_found';
  if (row.finishedAt) return 'already_finished';
  await db.update(fixityRecoveries)
    .set({ success: input.success, message: input.message, finishedAt: new Date() })
    .where(eq(fixityRecoveries.id, row.id));
  return 'ok';
}
```

`detail.ts`: aips 조립부에 `const openSet = await openRecoveriesByAip(db, aipRows.map((a) => Number(a.id)));` 추가, 매핑에 `openRecovery: openSet.has(Number(a.id)),` 추가, `NodeDetail` 타입 갱신. `index.ts` export 추가 (`startRecovery`, `openRecoveriesByAip`, `finishRecoveryByEventRef`).

Run: db 테스트 그린.

- [ ] **Step 3: web — withBasicAuth + 콜백 라우트**

`apps/web/lib/api.ts`에 추가:

```ts
import { timingSafeEqual } from 'node:crypto';

function safeEq(a: string, b: string): boolean {
  const ab = Buffer.from(a); const bb = Buffer.from(b);
  return ab.length === bb.length && timingSafeEqual(ab, bb);
}

// Machine-to-machine callback auth (SS recovery notifications). Basic credentials come from
// env, never from the users table — this route must work even with zero human users.
export function withBasicAuth(
  handler: (req: NextRequest) => Promise<NextResponse>,
): (req: NextRequest) => Promise<NextResponse> {
  return async (req) => {
    const user = process.env.RECOVERY_CALLBACK_USER ?? '';
    const pass = process.env.RECOVERY_CALLBACK_PASS ?? '';
    if (!user || !pass) return NextResponse.json({ error: 'callback auth not configured' }, { status: 503 });
    const header = req.headers.get('authorization') ?? '';
    const m = header.match(/^Basic\s+(.+)$/i);
    const decoded = m ? Buffer.from(m[1], 'base64').toString('utf8') : '';
    if (!safeEq(decoded, `${user}:${pass}`)) return NextResponse.json({ error: 'unauthorized' }, { status: 401 });
    return handler(req);
  };
}
```

`apps/web/app/api/v1/recovery-results/route.ts`:

```ts
import { NextResponse, type NextRequest } from 'next/server';
import { finishRecoveryByEventRef } from '@binder/db';
import { getDb } from '@/lib/db';
import { withBasicAuth } from '@/lib/api';

export const POST = withBasicAuth(async (req: NextRequest) => {
  let body: { event_id?: number | string; success?: boolean; message?: string };
  try { body = await req.json(); } catch { return NextResponse.json({ error: 'invalid json' }, { status: 400 }); }
  if (body.event_id == null || typeof body.success !== 'boolean') {
    return NextResponse.json({ error: 'event_id and success are required' }, { status: 400 });
  }
  const result = await finishRecoveryByEventRef(getDb(), {
    externalEventRef: String(body.event_id), success: body.success, message: body.message ?? null,
  });
  if (result === 'not_found') return NextResponse.json({ error: 'unknown event_id' }, { status: 404 });
  if (result === 'already_finished') return NextResponse.json({ error: 'recovery already finished' }, { status: 409 });
  return NextResponse.json({ ok: true });
});
```

- [ ] **Step 4: web — 다운로드 라우트 + 복구 액션**

`apps/web/app/api/v1/aips/[uuid]/download/route.ts`:

```ts
import { NextResponse, type NextRequest } from 'next/server';
import { and, eq, isNull } from 'drizzle-orm';
import { schema, insertDownloadLog } from '@binder/db';
import { getBackendForRow, BackendRequestError } from '@binder/integrations';
import { hasPermission } from '@binder/core';
import { getDb } from '@/lib/db';
import { withAuth } from '@/lib/api';

// Download is a logged read (Phase 1 downloadCheck?reason= lineage): cookie auth is allowed
// here because the only write is the append-only download_log row recording the read itself.
// All genuinely state-changing /api/v1 routes are Bearer-only or Basic — see lib/api.ts.
export const GET = withAuth(async (req: NextRequest, user) => {
  if (!hasPermission(user.permissions, 'aips:download')) {
    return NextResponse.json({ error: 'forbidden' }, { status: 403 });
  }
  const reason = req.nextUrl.searchParams.get('reason')?.trim() ?? '';
  if (!reason) return NextResponse.json({ error: 'reason is required' }, { status: 400 });
  const uuid = req.nextUrl.pathname.split('/').at(-2) ?? '';   // /api/v1/aips/[uuid]/download

  const db = getDb();
  const { aips, aipCopies, storageBackends } = schema;
  const [aip] = await db.select({ id: aips.id }).from(aips)
    .where(and(eq(aips.uuid, uuid), isNull(aips.deletedAt)));
  if (!aip) return NextResponse.json({ error: 'not found' }, { status: 404 });

  const copies = await db.select({
    locator: aipCopies.locator, isPrimary: aipCopies.isPrimary,
    kind: storageBackends.kind, config: storageBackends.config,
  }).from(aipCopies)
    .innerJoin(storageBackends, eq(storageBackends.id, aipCopies.backendId))
    .where(eq(aipCopies.aipId, aip.id));
  const copy = copies.find((c) => c.isPrimary) ?? copies[0];
  if (!copy) return NextResponse.json({ error: 'no stored copy' }, { status: 404 });

  try {
    const d = await getBackendForRow(copy).download(copy.locator);
    await insertDownloadLog(db, { aipId: aip.id, userId: user.userId, reason });
    const headers = new Headers({
      'content-type': 'application/x-tar',
      'content-disposition': `attachment; filename*=UTF-8''${encodeURIComponent(d.filename)}`,
    });
    if (d.sizeBytes != null) headers.set('content-length', String(d.sizeBytes));
    return new NextResponse(d.stream as unknown as BodyInit, { headers });
  } catch (e) {
    if (e instanceof BackendRequestError) {
      return NextResponse.json({ error: e.message }, { status: e.status === null ? 404 : 502 });
    }
    throw e;
  }
});
```

(params Promise 방식(`{ params }: { params: Promise<{ uuid: string }> }`)이 Next 15 라우트 핸들러에서 지원되면 pathname 파싱 대신 그것을 사용 — 구현 시 기존 nodes/route.ts와 Next 문서 기준으로 판단, 동작 우선.)

`apps/web/lib/actions/recovery.ts`:

```ts
'use server';

import { revalidatePath } from 'next/cache';
import { and, eq, isNull } from 'drizzle-orm';
import { schema, openRecoveryExists, startRecovery, writeAudit } from '@binder/db';
import { getBackendForRow, UnsupportedOperationError, BackendRequestError } from '@binder/integrations';
import { hasPermission } from '@binder/core';
import { getDb } from '../db.js';
import { requireUser } from '../session.js';

export async function requestRecoveryAction(aipId: number, slug: string): Promise<{ ok: boolean; error?: string }> {
  const user = await requireUser();
  if (!hasPermission(user.permissions, 'aips:recover')) return { ok: false, error: 'forbidden' };

  const db = getDb();
  const { aips, aipCopies, storageBackends, users } = schema;
  const [aip] = await db.select({ id: aips.id }).from(aips).where(and(eq(aips.id, aipId), isNull(aips.deletedAt)));
  if (!aip) return { ok: false, error: 'not-found' };
  if (await openRecoveryExists(db, aipId)) return { ok: false, error: 'already-recovering' };

  const copies = await db.select({
    backendId: aipCopies.backendId, locator: aipCopies.locator, kind: storageBackends.kind, config: storageBackends.config,
  }).from(aipCopies)
    .innerJoin(storageBackends, eq(storageBackends.id, aipCopies.backendId))
    .where(eq(aipCopies.aipId, aipId));
  const ssCopy = copies.find((c) => c.kind === 'am_ss');
  if (!ssCopy) return { ok: false, error: 'unsupported-backend' };

  const [u] = await db.select({ email: users.email }).from(users).where(eq(users.id, user.userId));
  try {
    const { externalEventRef } = await getBackendForRow(ssCopy)
      .requestRecovery(ssCopy.locator, { reason: 'Request from Binder', userId: user.userId, userEmail: u?.email ?? '' });
    const recoveryId = await startRecovery(db, { aipId, userId: user.userId, backendId: ssCopy.backendId, externalEventRef });
    await writeAudit(db, {
      tableName: 'fixity_recoveries', rowId: recoveryId, action: 'insert',
      actorId: user.userId, origin: 'user', jobRef: externalEventRef,
    });
  } catch (e) {
    if (e instanceof UnsupportedOperationError) return { ok: false, error: 'unsupported-backend' };
    if (e instanceof BackendRequestError) return { ok: false, error: 'backend-unreachable' };
    throw e;
  }
  revalidatePath(`/records/${slug}`);
  return { ok: true };
}
```

**참고:** 위 `writeAudit` 호출은 실제 `AuditEntry` 타입(`{ tableName, rowId, action: 'insert'|'update'|'delete'|'restore', actorId, origin: 'user'|'sync'|'ingest', jobRef?, ... }`)에 맞춘 검증된 형태다. `PostgresJsDatabase`는 `DbExecutor`(Pick 타입)에 구조적으로 할당 가능하므로 db 그대로 전달.

- [ ] **Step 5: curl 증거**

dev 서버(:3200) + 시드 상태에서:

```bash
# 콜백: env 미설정 → 503
curl -si -X POST localhost:3200/api/v1/recovery-results -d '{}' | head -1
# env 설정 후 재기동: 401(자격 불일치) / 404(미지의 event_id) / (심어둔 fixity_recoveries 행으로) 200→409 순서 확인
RECOVERY_CALLBACK_USER=ss RECOVERY_CALLBACK_PASS=secret pnpm dev -p 3200 &
curl -si -X POST localhost:3200/api/v1/recovery-results -u wrong:creds -H 'content-type: application/json' -d '{"event_id":1,"success":true}' | head -1   # 401
curl -si -X POST localhost:3200/api/v1/recovery-results -u ss:secret -H 'content-type: application/json' -d '{"event_id":99999,"success":true}' | head -1  # 404
# 다운로드: 쿠키 세션으로 데모 AIP (Task 2에서 심은 demo tar)
curl -si -b "binder_session=$TOKEN" "localhost:3200/api/v1/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/download?reason=%EA%B2%80%EC%88%98" -o /tmp/aip.tar -w '%{http_code} %{size_download}\n'  # 200 + bytes
curl -si -b "binder_session=$TOKEN" "localhost:3200/api/v1/aips/6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52/download" | head -1   # 400 (reason 없음)
docker exec -i $(docker ps -qf name=postgres) psql -U binder -d binder -c "SELECT reason FROM download_log ORDER BY id DESC LIMIT 1;"
```

- [ ] **Step 6: 게이트 + 커밋**

Run: `pnpm test && pnpm typecheck && pnpm --filter web build` → 그린.

```bash
git add -A && git commit -m "feat(web,db): aip download with reason log, recovery request action and ss callback"
```

---

### Task 8: UI (AipList 확장) + E2E + README + 최종 검증

**Files:**
- Modify: `apps/web/components/records/AipList.tsx`
- Modify: `apps/web/app/(app)/records/[slug]/page.tsx` (AipList에 slug/권한 전달 — 아래 계약)
- Create: `apps/web/components/records/RecoveryButton.tsx` (client, useTransition + 액션 호출)
- Modify: `apps/web/messages/ko.json`, `apps/web/messages/en.json`
- Modify: `apps/web/e2e/core-flows.spec.ts`
- Modify: `README.md`
- Modify: 루트 `.env.example` (신규 env 전체 정리)

**Interfaces:**
- Consumes: `NodeDetail['aips'][number].openRecovery`(Task 7), `requestRecoveryAction`(Task 7), download GET 라우트(Task 7)
- Produces:
  - AipList Props 확장: `{ aips, locale, t, formatBytes, slug: string, canRecover: boolean, canDownload: boolean }` — t에 `download`/`downloadReason`/`recover`/`recovering` 키 추가
  - AIP 행마다: ① `canDownload`이면 인라인 다운로드 폼 `<form method="get" action={'/api/v1/aips/' + aip.uuid + '/download'}>` + `<input name="reason" required placeholder={t.downloadReason} data-testid="download-reason">` + submit 버튼(`data-testid="download-submit"`) — 서버 컴포넌트 그대로(클라 JS 불요) ② `aip.fixity?.success === false && canRecover`이면: `aip.openRecovery ? <span data-testid="recovery-pending">{t.recovering}</span> : <RecoveryButton aipId={aip.id} slug={slug} label={t.recover} />`
  - `RecoveryButton`: 'use client', `useTransition`으로 `requestRecoveryAction(aipId, slug)` 호출, 실패 시 `alert(error)` 수준(간결 유지), `data-testid="recovery-request"`
  - 메시지 키(detail.*): ko `download: '다운로드'`, `downloadReason: '사유 (필수)'`, `recover: '복구 요청'`, `recovering: '복구 진행 중'` / en `download: 'Download'`, `downloadReason: 'Reason (required)'`, `recover: 'Request recovery'`, `recovering: 'Recovery in progress'`
  - 페이지에서 `canDownload = hasPermission(user.permissions, 'aips:download')`, `canRecover = hasPermission(user.permissions, 'aips:recover')` — requireUser 반환값 재사용 (**주의**: `hasPermission`은 서버 컴포넌트에서 `@binder/core` 배럴 import 가능 — 서버 전용이므로 argon2 규칙 위배 아님. RecoveryButton(클라이언트)에는 권한 부울만 prop으로)
- E2E 추가 (core-flows.spec.ts, 기존 login 헬퍼 재사용):

```ts
test('aip download with reason writes a real file response', async ({ page }) => {
  await login(page);
  await page.goto('/records/lovers');
  await page.getByTestId('download-reason').first().fill('E2E 검수');
  const downloadPromise = page.waitForEvent('download');
  await page.getByTestId('download-submit').first().click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/\.tar$/);
});
```

(전제: `db:seed-demo`가 demo tar를 만들었고 데모 백엔드 root가 절대경로 — Task 2. reason 미입력 시 required 속성이 폼 제출을 막는 것은 브라우저 동작이라 e2e 불요.)

- [ ] **Step 1: 컴포넌트·메시지 구현** — 위 계약대로. AipList는 서버 컴포넌트 유지(다운로드 폼은 순수 HTML), RecoveryButton만 클라이언트.
- [ ] **Step 2: E2E 실행** — `pnpm e2e` → 6/6 (기존 5 + 다운로드 1). 실패 시 원인 수정 후 재실행.
- [ ] **Step 3: README** — `## 웹 앱` 아래에 `### 통합 (Plan 06)` 소절: 워커 기동(`pnpm --filter worker dev`), env 표(SS_*, REDIS_URL, FIXITY_CRON, FIXITY_WEBHOOK_URL, RECOVERY_CALLBACK_*), ingest API 한 줄 예시(curl Bearer), 다운로드/복구 UI 한 줄. REST API 표에 `POST /api/v1/ingest/am-dip`(Bearer 전용)·`POST /api/v1/recovery-results`(Basic)·`GET /api/v1/aips/:uuid/download`(reason 필수) 행 추가.
- [ ] **Step 4: 루트 .env.example 정리** — Global Constraints의 env 전부 (기본값 포함, 주석 1줄씩).
- [ ] **Step 5: 최종 게이트 + 커밋**

Run: `pnpm test && pnpm typecheck && pnpm --filter web build && pnpm e2e`
Expected: 전부 그린.

```bash
git add -A && git commit -m "feat(web): aip download and recovery ui with e2e coverage and integration docs"
```

---

## Self-Review

1. **Spec coverage (설계서 §4·§5·§8 대비)**: StorageBackend 인터페이스+AmSsBackend=T3, am_dip IngestAdapter(METS 파서+멱등 ingest_jobs 트랜잭션)=T4·T5, 복구 콜백 `/api/v1/recovery-results`(Basic, {event_id,success,message})=T7, fixity 크론(세션 uuid 그룹핑→대시보드 위젯 계약 유지)+알림(웹훅/로그)=T6, AIP 다운로드 스트리밍+download_log(reason NOT NULL)=T2·T7, 계약 테스트(fake DIP 픽스처·SS HTTP 픽스처)=T3·T4, E2E 핵심 플로우 확장=T8. MetadataSource 어댑터(omeka_s 등)는 설계서 구현 순서 6번 — Plan 08 범위로 명시적 제외. 이메일 알림은 웹훅/로그로 대체(확장점) — 설계서의 "이메일/웹훅" 중 웹훅 우선, 근거는 운영 env 미확정.
2. **Placeholder scan**: T5 `createAip` 호출부와 T7 `writeAudit` 호출부는 "실제 타입을 열어 맞춰라"는 명시적 지시와 함께 근사 코드를 제공 — 필드명이 구현 시점에 확정되는 기존-코드 의존이라 브리프에 실명세(정본 파일 경로)를 박아 해소. "적절히 처리" 류 지시문 없음 확인. T6 fixity 카피 status 전이는 잠정 정책+판단 기준 명시(재량 아닌 결정 위임 조건 부여).
3. **Type consistency**: `ParsedDip`(T4) ↔ `ParsedDipLike`(T5 구조 타입) 필드 일치; `IngestJobPayload`(T3) ↔ 큐 add/Worker 제네릭(T5·T6); `QUEUES`(T3) ↔ web queue.ts(T5)·worker(T6); `openRecovery`(T7 detail) ↔ AipList(T8); `hasPermission`(T2) ↔ T5·T7·T8; `UnknownAipError`(T2) — T6 스캔은 라이브만 조회하므로 도달 불가(방어선). `startRecovery`/`finishRecoveryByEventRef`/`openRecoveriesByAip` 시그니처 T7 내 정의·사용 일치.
4. **순서 의존**: T1(독립) → T2(스키마 기반) → T3·T4(순수 패키지, T2와 병렬 가능하나 순차 실행) → T5(T2+T3+T4 소비) → T6(T3+T5) → T7(T2+T3) → T8(T7). 전 태스크가 자체 테스트 사이클 보유.
