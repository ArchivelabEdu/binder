# Binder Phase 2 — Plan 02: Description Spine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 기술(記述) 스파인을 세운다 — `nodes` 단일 자기참조 계층, 컬렉션 프로파일(버전), 행위자/이벤트/관계/노트/권리, 감사 파이프라인, 혼합 컬렉션 데모 시더까지. Plan 03(보존 테이블)과 Plan 04(UI)가 딛고 설 데이터 층.

**Architecture:** 모든 스키마는 `@binder/db`(Drizzle, 마이그레이션 0003+), 순수 규칙·검증은 `@binder/core`, DB를 실제로 치는 쿼리 함수는 `@binder/db/src/queries/*`. 테스트는 Plan 01의 실PG 하네스(binder_test 재생성) 그대로.

**Tech Stack:** Plan 01 스택 + `ajv`/`ajv-formats`(프로파일 JSON Schema 검증, @binder/core).

**설계 근거:** `binder/docs-spike/phase2-data-model-review.md` §2A(스파인·프로파일)·§2B(택소노미 잔여)·§2C(행위자·이벤트·관계·권리·노트)·§2G(audit_log)·§3(횡단 규칙). 확정 결정 반영: 부서·컴포넌트유형 = nodes 단일 FK 승격(결정 3).

## Global Constraints

- Repo: `/Users/daejinan/claude/moma-binder/binder-next` (main). Plan 01 완료 상태(HEAD 9c89b1c, 29/29 tests, 마이그레이션 0000–0002).
- Node >=22, pnpm 9, ESM(.js import 확장자), TS strict. snake_case 컬럼, timestamptz. conventional commits.
- i18n 텍스트 = JSONB `{ko?,en?}` + `source_culture`. 검증·폴백은 `@binder/core`의 `i18nTextSchema`/`pickI18n` 사용.
- **Soft delete 패턴**: `deleted_at timestamptz NULL` + `deleted_by → users.id`; 유일성은 partial unique index `WHERE deleted_at IS NULL`; 기본 조회는 `deleted_at IS NULL` 필터.
- 시드·시더는 멱등. 시드 텀 조회는 Plan 01의 `findTermId(db, taxonomy, term)`.
- 마이그레이션은 `pnpm --filter @binder/db db:generate`로 생성(0003부터 순차), 적용된 마이그레이션 수정 금지.
- DB CHECK 제약이 drizzle-kit로 표현 안 되는 경우: **앱 레벨(쿼리 함수) 강제 + 테스트로 고정**하고 코드 주석에 명시(과도한 raw SQL 마이그레이션 편집 회피).
- 감사 규칙: 변경은 필드 단위 diff(`diffFields`), `descriptive`는 jsonbTopLevel, delete 시에만 row_snapshot.

---

### Task 1: Plan 01 이연 정리

**Files:**
- Modify: `packages/db/scripts/migrate.ts`, `packages/db/test/global-setup.ts`

**Interfaces:**
- Consumes: Plan 01 산출물 전부.
- Produces: 정리된 마이그레이션 러너(저널 가드 제거, `fileURLToPath`, 정확한 로그) — 이후 태스크들이 신뢰하는 기반.

- [ ] **Step 1: migrate.ts 정리**

`packages/db/scripts/migrate.ts` 전체 교체:
```ts
import { fileURLToPath } from 'node:url';
import { drizzle } from 'drizzle-orm/postgres-js';
import { migrate } from 'drizzle-orm/postgres-js/migrator';
import postgres from 'postgres';

const url = process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder';
const sql = postgres(url, { max: 1, onnotice: () => {} });
await migrate(drizzle(sql), { migrationsFolder: fileURLToPath(new URL('../migrations', import.meta.url)) });
await sql.end();
console.log('migrated:', url.replace(/:[^:@]+@/, ':***@'));
```
(저널 가드 제거 — `migrations/meta/_journal.json`은 0000부터 커밋되어 있어 죽은 분기. 로그는 이제 실제 마이그레이션 후에만 출력.)

- [ ] **Step 2: global-setup.ts 정리**

`packages/db/test/global-setup.ts` 전체 교체:
```ts
import { fileURLToPath } from 'node:url';
import { drizzle } from 'drizzle-orm/postgres-js';
import { migrate } from 'drizzle-orm/postgres-js/migrator';
import postgres from 'postgres';

const TEST_URL = process.env.DATABASE_URL_TEST ?? 'postgres://binder:binder@localhost:15432/binder_test';
// admin 접속은 TEST_URL에서 DB명만 교체 — 하드코딩 이름과 env 불일치 방지
const adminUrl = TEST_URL.replace(/\/[^/]+$/, '/binder');
const testDbName = TEST_URL.slice(TEST_URL.lastIndexOf('/') + 1);

export default async function setup(): Promise<void> {
  const admin = postgres(adminUrl, { max: 1, onnotice: () => {} });
  await admin.unsafe(`DROP DATABASE IF EXISTS ${testDbName} WITH (FORCE)`);
  await admin.unsafe(`CREATE DATABASE ${testDbName}`);
  await admin.end();

  const sql = postgres(TEST_URL, { max: 1, onnotice: () => {} });
  await migrate(drizzle(sql), { migrationsFolder: fileURLToPath(new URL('../migrations', import.meta.url)) });
  await sql.end();
}
```

- [ ] **Step 3: 테스트 그린 확인**

Run: `cd /Users/daejinan/claude/moma-binder/binder-next && pnpm --filter @binder/db test`
Expected: PASS (9 tests — 기존과 동일).

- [ ] **Step 4: 커밋**

```bash
git add -A && git commit -m "chore(db): remove dead journal guards, use fileURLToPath, align test-db name with env"
```

---

### Task 2: 컬렉션 프로파일(버전) + descriptive 검증기 — TDD

**Files:**
- Create: `packages/db/src/schema/profiles.ts`, `packages/core/src/profile.ts`
- Modify: `packages/db/src/schema/index.ts`, `packages/core/src/index.ts`, `packages/core/package.json`(ajv 의존성)
- Test: `packages/core/test/profile.test.ts`, `packages/db/test/profiles.test.ts`

**Interfaces:**
- Consumes: Plan 01 하네스.
- Produces:
  - 테이블 `collectionProfiles`, `collectionProfileVersions` (UNIQUE(profile_id, version))
  - `validateDescriptive(fieldSchema: object, payload: unknown): { valid: boolean; errors: string[] }` (@binder/core — Ajv 래핑)
  - `createProfileVersion(db, profileId, fieldSchema, createdBy): Promise<number>` — 불변 버전 추가(version = max+1), `@binder/db/src/queries/profiles.ts`

- [ ] **Step 1: 실패하는 테스트 작성 (core: 검증기)**

`packages/core/test/profile.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { validateDescriptive } from '../src/profile.js';

const schema = {
  type: 'object',
  properties: {
    medium: { type: 'string' },
    duration_min: { type: 'number', minimum: 0 },
  },
  required: ['medium'],
  additionalProperties: false,
};

describe('validateDescriptive', () => {
  it('accepts a conforming payload', () => {
    expect(validateDescriptive(schema, { medium: 'video', duration_min: 13 })).toEqual({ valid: true, errors: [] });
  });

  it('rejects missing required and unknown fields with readable errors', () => {
    const r1 = validateDescriptive(schema, { duration_min: 5 });
    expect(r1.valid).toBe(false);
    expect(r1.errors.join(' ')).toMatch(/medium/);

    const r2 = validateDescriptive(schema, { medium: 'video', extra: 1 });
    expect(r2.valid).toBe(false);
    expect(r2.errors.join(' ')).toMatch(/extra|additional/i);
  });

  it('rejects a payload that is not an object', () => {
    expect(validateDescriptive(schema, 'nope').valid).toBe(false);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/core test`
Expected: FAIL — `Cannot find module '../src/profile.js'`.

- [ ] **Step 3: 구현 (core)**

```bash
pnpm --filter @binder/core add ajv@^8.17.0 ajv-formats@^3.0.0
```

`packages/core/src/profile.ts`:
```ts
import { Ajv } from 'ajv';
import addFormats from 'ajv-formats';

const ajv = new Ajv({ allErrors: true, strict: false });
addFormats(ajv);

export function validateDescriptive(fieldSchema: object, payload: unknown): { valid: boolean; errors: string[] } {
  const validate = ajv.compile(fieldSchema);
  const valid = validate(payload) as boolean;
  const errors = (validate.errors ?? []).map((e) => `${e.instancePath || '(root)'} ${e.message ?? ''}`.trim());
  return { valid, errors };
}
```

`packages/core/src/index.ts`에 추가: `export { validateDescriptive } from './profile.js';`

Run: `pnpm --filter @binder/core test` → PASS.

- [ ] **Step 4: 실패하는 테스트 작성 (db: 프로파일 버전)**

`packages/db/test/profiles.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { collectionProfiles, collectionProfileVersions } from '../src/schema/index.js';
import { createProfileVersion } from '../src/queries/profiles.js';

const { db, sql } = testDb();
let profileId: number;

beforeAll(async () => {
  await truncate(sql, 'collection_profile_versions', 'collection_profiles');
  const [p] = await db.insert(collectionProfiles)
    .values({ name: { ko: '미디어아트', en: 'Media art' } }).returning();
  profileId = p.id;
});
afterAll(() => sql.end());

describe('collection profile versions', () => {
  it('assigns sequential immutable versions starting at 1', async () => {
    const v1 = await createProfileVersion(db, profileId, { type: 'object' }, null);
    const v2 = await createProfileVersion(db, profileId, { type: 'object', required: [] }, null);
    expect(v1).toBe(1);
    expect(v2).toBe(2);
  });

  it('rejects duplicate (profile, version) at the DB level', async () => {
    await expect(
      db.insert(collectionProfileVersions).values({ profileId, version: 1, fieldSchema: {} }),
    ).rejects.toThrow();
  });

  it('keeps versions readable per profile', async () => {
    const rows = await db.select().from(collectionProfileVersions)
      .where(eq(collectionProfileVersions.profileId, profileId));
    expect(rows.map((r) => r.version).sort()).toEqual([1, 2]);
  });
});
```

- [ ] **Step 5: 실패 확인**

Run: `pnpm --filter @binder/db test`
Expected: FAIL — schema/queries 모듈 없음.

- [ ] **Step 6: 스키마·쿼리 구현 + 마이그레이션**

`packages/db/src/schema/profiles.ts`:
```ts
import { integer, jsonb, pgTable, serial, text, timestamp, uniqueIndex } from 'drizzle-orm/pg-core';
import type { I18nText } from '../types.js';
import { users } from './users.js';

export const collectionProfiles = pgTable('collection_profiles', {
  id: serial('id').primaryKey(),
  name: jsonb('name').$type<I18nText>().notNull(),
  description: jsonb('description').$type<I18nText>(),
  sourceCulture: text('source_culture').notNull().default('ko'),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export const collectionProfileVersions = pgTable('collection_profile_versions', {
  id: serial('id').primaryKey(),
  profileId: integer('profile_id').notNull().references(() => collectionProfiles.id, { onDelete: 'restrict' }),
  version: integer('version').notNull(),
  // descriptive JSONB 페이로드의 JSON Schema(필드별 crosswalk 키 map:{dc,ead,ric} 포함 가능)
  fieldSchema: jsonb('field_schema').$type<Record<string, unknown>>().notNull(),
  createdBy: integer('created_by').references(() => users.id),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [uniqueIndex('profile_versions_profile_version_uq').on(t.profileId, t.version)]);
```

`packages/db/src/queries/profiles.ts`:
```ts
import { desc, eq } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { collectionProfileVersions } from '../schema/index.js';

/** 불변 버전 추가: version = 현재 최대 + 1. 기존 버전 행은 절대 수정하지 않는다. */
export async function createProfileVersion(
  db: PostgresJsDatabase,
  profileId: number,
  fieldSchema: Record<string, unknown>,
  createdBy: number | null,
): Promise<number> {
  const [latest] = await db.select({ version: collectionProfileVersions.version })
    .from(collectionProfileVersions)
    .where(eq(collectionProfileVersions.profileId, profileId))
    .orderBy(desc(collectionProfileVersions.version))
    .limit(1);
  const version = (latest?.version ?? 0) + 1;
  await db.insert(collectionProfileVersions).values({ profileId, version, fieldSchema, createdBy });
  return version;
}
```

`packages/db/src/schema/index.ts`에 추가:
```ts
export { collectionProfiles, collectionProfileVersions } from './profiles.js';
```

Run: `pnpm --filter @binder/db db:generate` → migration 0003 생성 확인.

- [ ] **Step 7: 테스트 그린 + 커밋**

Run: `pnpm --filter @binder/db test` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat: collection profiles with immutable versioned field schemas and ajv validator"
```

---

### Task 3: nodes 스파인 + node_terms 스키마 — TDD

**Files:**
- Create: `packages/db/src/schema/nodes.ts`
- Modify: `packages/db/src/schema/index.ts`
- Test: `packages/db/test/nodes-schema.test.ts`

**Interfaces:**
- Consumes: Task 2의 profiles, Plan 01의 terms/users.
- Produces: 테이블 `nodes`(전체 컬럼 아래 코드가 정의 — Plan 03의 aips.node_id/root_node_id, Plan 04 UI가 의존), `nodeTerms`. `nodeKindEnum` pg enum ('work','component','record','supporting').
  - 결정 3 반영: `departmentTermId`, `componentTypeTermId`는 nodes의 단일 FK 컬럼; 분류(classification)는 nodeTerms N:M.

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/nodes-schema.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { collectionProfiles, nodes } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';

const { db, sql } = testDb();
let profileId: number;
let workLevel: number;

beforeAll(async () => {
  await truncate(sql, 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  workLevel = await findTermId(db, 'level_of_description', 'artwork_record');
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본', en: 'Default' } }).returning();
  profileId = p.id;
});
afterAll(() => sql.end());

describe('nodes table', () => {
  it('inserts a root node with defaults (uuid generated, position 0, kind work)', async () => {
    const [n] = await db.insert(nodes).values({
      kind: 'work', levelTermId: workLevel, profileId, profileVersion: 1,
      title: { ko: '러버스', en: 'Lovers' }, slug: 'lovers', identifier: '100001',
    }).returning();
    expect(n.uuid).toMatch(/^[0-9a-f-]{36}$/);
    expect(n.position).toBe(0);
    expect(n.deletedAt).toBeNull();
  });

  it('enforces slug uniqueness only among live rows (partial index)', async () => {
    await expect(db.insert(nodes).values({
      kind: 'work', levelTermId: workLevel, profileId, profileVersion: 1,
      title: { en: 'Dup' }, slug: 'lovers',
    })).rejects.toThrow();

    // soft-delete 후 동일 slug 재사용 가능
    await sql`UPDATE nodes SET deleted_at = now() WHERE slug = 'lovers'`;
    const [again] = await db.insert(nodes).values({
      kind: 'work', levelTermId: workLevel, profileId, profileVersion: 1,
      title: { en: 'Lovers again' }, slug: 'lovers',
    }).returning();
    expect(again.id).toBeGreaterThan(0);
  });

  it('restricts deleting a parent that still has children', async () => {
    const [parent] = await db.insert(nodes).values({
      kind: 'work', levelTermId: workLevel, profileId, profileVersion: 1,
      title: { en: 'P' }, slug: 'p-1',
    }).returning();
    await db.insert(nodes).values({
      kind: 'component', levelTermId: workLevel, parentId: parent.id,
      title: { en: 'C' }, slug: 'c-1',
    });
    await expect(sql`DELETE FROM nodes WHERE id = ${parent.id}`).rejects.toThrow(/foreign key|violates/i);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL (nodes 없음).

- [ ] **Step 3: 스키마 구현 + 마이그레이션**

`packages/db/src/schema/nodes.ts`:
```ts
import {
  bigint, date, integer, jsonb, pgEnum, pgTable, primaryKey, serial, text, timestamp, uniqueIndex, index, uuid,
} from 'drizzle-orm/pg-core';
import { sql } from 'drizzle-orm';
import type { I18nText } from '../types.js';
import { terms } from './taxonomies.js';
import { collectionProfiles } from './profiles.js';
import { users } from './users.js';

export const nodeKindEnum = pgEnum('node_kind', ['work', 'component', 'record', 'supporting']);

export const nodes = pgTable('nodes', {
  id: serial('id').primaryKey(),
  uuid: uuid('uuid').notNull().unique().defaultRandom(),
  parentId: integer('parent_id').references((): any => nodes.id, { onDelete: 'restrict' }),
  position: integer('position').notNull().default(0),
  kind: nodeKindEnum('kind').notNull(),
  levelTermId: integer('level_term_id').notNull().references(() => terms.id, { onDelete: 'restrict' }),
  // 루트는 profile 필수, 하위는 상속 — 앱 레벨(createNode) 강제 (drizzle-kit CHECK 미지원 회피, Global Constraints 참조)
  profileId: integer('profile_id').references(() => collectionProfiles.id, { onDelete: 'restrict' }),
  profileVersion: integer('profile_version'),
  identifier: text('identifier'),
  accessionNumber: text('accession_number'),
  componentNumber: text('component_number'),
  slug: text('slug').notNull(),
  title: jsonb('title').$type<I18nText>().notNull(),
  sourceCulture: text('source_culture').notNull().default('ko'),
  descriptive: jsonb('descriptive').$type<Record<string, unknown>>(),
  // TMS 잡속성: 순서 보존 [{label, value}] 배열, 중복 라벨 허용
  sourceAttributes: jsonb('source_attributes').$type<Array<{ label: string; value: string }>>(),
  departmentTermId: integer('department_term_id').references(() => terms.id, { onDelete: 'set null' }),
  componentTypeTermId: integer('component_type_term_id').references(() => terms.id, { onDelete: 'set null' }),
  dateCreated: date('date_created'),
  dateCollected: date('date_collected'),
  accessionedAt: date('accessioned_at'),
  thumbnailUrl: text('thumbnail_url'),
  fullImageUrl: text('full_image_url'),
  // 롤업(write-time denorm) — 유지 로직은 Plan 03(aips/files)에서; 여기선 기본값 0
  totalSizeBytes: bigint('total_size_bytes', { mode: 'number' }).notNull().default(0),
  aipCount: integer('aip_count').notNull().default(0),
  fileCount: integer('file_count').notNull().default(0),
  lastIngestedAt: timestamp('last_ingested_at', { withTimezone: true }),
  deletedAt: timestamp('deleted_at', { withTimezone: true }),
  deletedBy: integer('deleted_by').references(() => users.id),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [
  uniqueIndex('nodes_slug_live_uq').on(t.slug).where(sql`deleted_at IS NULL`),
  uniqueIndex('nodes_accession_live_uq').on(t.accessionNumber).where(sql`accession_number IS NOT NULL AND deleted_at IS NULL`),
  uniqueIndex('nodes_component_number_live_uq').on(t.componentNumber).where(sql`component_number IS NOT NULL AND deleted_at IS NULL`),
  index('nodes_parent_position_ix').on(t.parentId, t.position),
  index('nodes_updated_at_ix').on(t.updatedAt),
]);

export const nodeTerms = pgTable('node_terms', {
  nodeId: integer('node_id').notNull().references(() => nodes.id, { onDelete: 'cascade' }),
  termId: integer('term_id').notNull().references(() => terms.id, { onDelete: 'restrict' }),
}, (t) => [primaryKey({ columns: [t.nodeId, t.termId] })]);
```

`schema/index.ts`에 추가:
```ts
export { nodeKindEnum, nodes, nodeTerms } from './nodes.js';
```

Run: `pnpm --filter @binder/db db:generate` → migration 0004 생성 (enum + nodes + node_terms + partial 인덱스 3종 확인).

- [ ] **Step 4: 테스트 그린 + 커밋**

Run: `pnpm --filter @binder/db test` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): nodes description spine with soft-delete partial uniques and node_terms"
```

---

### Task 4: 노드 쿼리 모듈 (생성·트리·소프트삭제) — TDD

**Files:**
- Create: `packages/db/src/queries/nodes.ts`, `packages/core/src/slug.ts`
- Modify: `packages/core/src/index.ts`, `packages/db/src/index.ts`(queries re-export)
- Test: `packages/core/test/slug.test.ts`, `packages/db/test/nodes-queries.test.ts`

**Interfaces:**
- Consumes: Task 3 nodes 스키마, Task 2 profiles.
- Produces:
  - `slugify(input: string): string` (@binder/core — 한글 유지, 공백→하이픈, 소문자, 특수문자 제거)
  - `createNode(db, input: CreateNodeInput): Promise<NodeRow>` — position 자동(부모 내 max+1), slug 자동(제목 기반, 충돌 시 `-2`,`-3`… 접미사), 루트 profile 필수 강제, 부모 존재·미삭제 검증
  - `getSubtree(db, rootId): Promise<TreeRow[]>` — 재귀 CTE, (depth, position) 정렬. `TreeRow = { id, parentId, depth, position, kind, levelTermId, title, slug }`
  - `softDeleteNode(db, id, userId): Promise<void>` — 자식(미삭제) 존재 시 에러
  - `CreateNodeInput = { kind, levelTermId, title, parentId?, profileId?, profileVersion?, identifier?, accessionNumber?, componentNumber?, slug?, descriptive?, departmentTermId?, componentTypeTermId?, sourceCulture? }`

- [ ] **Step 1: 실패하는 테스트 작성 (core: slugify)**

`packages/core/test/slug.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { slugify } from '../src/slug.js';

describe('slugify', () => {
  it('lowercases, hyphenates spaces, strips punctuation', () => {
    expect(slugify('Grosse Fatigue (2013)')).toBe('grosse-fatigue-2013');
  });
  it('keeps hangul', () => {
    expect(slugify('러버스 전시 포맷')).toBe('러버스-전시-포맷');
  });
  it('collapses repeats and trims hyphens', () => {
    expect(slugify('--A   B--')).toBe('a-b');
  });
  it('falls back to "node" for empty result', () => {
    expect(slugify('!!!')).toBe('node');
  });
});
```

- [ ] **Step 2: 실패 확인 후 구현 (core)**

Run: `pnpm --filter @binder/core test` → FAIL.

`packages/core/src/slug.ts`:
```ts
export function slugify(input: string): string {
  const s = input
    .toLowerCase()
    .normalize('NFC')
    .replace(/[^\p{L}\p{N}]+/gu, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
  return s || 'node';
}
```

`packages/core/src/index.ts`에 추가: `export { slugify } from './slug.js';`

Run: `pnpm --filter @binder/core test` → PASS.

- [ ] **Step 3: 실패하는 테스트 작성 (db: 쿼리)**

`packages/db/test/nodes-queries.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { collectionProfiles } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode, getSubtree, softDeleteNode } from '../src/queries/nodes.js';

const { db, sql } = testDb();
let profileId: number;
let work: number; let comp: number;

beforeAll(async () => {
  await truncate(sql, 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  work = await findTermId(db, 'level_of_description', 'artwork_record');
  comp = await findTermId(db, 'level_of_description', 'component');
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  profileId = p.id;
});
afterAll(() => sql.end());

describe('createNode', () => {
  it('rejects a root node without a profile', async () => {
    await expect(createNode(db, { kind: 'work', levelTermId: work, title: { en: 'No profile' } }))
      .rejects.toThrow(/profile/i);
  });

  it('auto-generates slug from title and dedupes with numeric suffix', async () => {
    const a = await createNode(db, { kind: 'work', levelTermId: work, profileId, profileVersion: 1, title: { en: 'Lovers' } });
    const b = await createNode(db, { kind: 'work', levelTermId: work, profileId, profileVersion: 1, title: { en: 'Lovers' } });
    expect(a.slug).toBe('lovers');
    expect(b.slug).toBe('lovers-2');
  });

  it('assigns sibling positions sequentially under a parent', async () => {
    const root = await createNode(db, { kind: 'work', levelTermId: work, profileId, profileVersion: 1, title: { en: 'Tree root' } });
    const c1 = await createNode(db, { kind: 'component', levelTermId: comp, parentId: root.id, title: { en: 'c1' } });
    const c2 = await createNode(db, { kind: 'component', levelTermId: comp, parentId: root.id, title: { en: 'c2' } });
    expect(c1.position).toBe(0);
    expect(c2.position).toBe(1);
  });

  it('rejects a child under a soft-deleted parent', async () => {
    const root = await createNode(db, { kind: 'work', levelTermId: work, profileId, profileVersion: 1, title: { en: 'Doomed' } });
    await softDeleteNode(db, root.id, null);
    await expect(createNode(db, { kind: 'component', levelTermId: comp, parentId: root.id, title: { en: 'x' } }))
      .rejects.toThrow(/parent/i);
  });
});

describe('getSubtree', () => {
  it('returns depth-ordered rows with sibling position order', async () => {
    const root = await createNode(db, { kind: 'record', levelTermId: await findTermId(db, 'level_of_description', 'fonds'), profileId, profileVersion: 1, title: { ko: '퐁' } });
    const s1 = await createNode(db, { kind: 'record', levelTermId: await findTermId(db, 'level_of_description', 'series'), parentId: root.id, title: { ko: '시리즈1' } });
    await createNode(db, { kind: 'record', levelTermId: await findTermId(db, 'level_of_description', 'item'), parentId: s1.id, title: { ko: '건1' } });
    await createNode(db, { kind: 'record', levelTermId: await findTermId(db, 'level_of_description', 'series'), parentId: root.id, title: { ko: '시리즈2' } });

    const tree = await getSubtree(db, root.id);
    expect(tree).toHaveLength(4);
    expect(tree[0].depth).toBe(0);
    expect(tree.map((r) => r.title.ko ?? '')).toEqual(['퐁', '시리즈1', '건1', '시리즈2']);
  });

  it('excludes soft-deleted descendants', async () => {
    const root = await createNode(db, { kind: 'work', levelTermId: work, profileId, profileVersion: 1, title: { en: 'SD root' } });
    const child = await createNode(db, { kind: 'component', levelTermId: comp, parentId: root.id, title: { en: 'gone' } });
    await softDeleteNode(db, child.id, null);
    const tree = await getSubtree(db, root.id);
    expect(tree).toHaveLength(1);
  });
});

describe('softDeleteNode', () => {
  it('refuses when live children exist', async () => {
    const root = await createNode(db, { kind: 'work', levelTermId: work, profileId, profileVersion: 1, title: { en: 'Busy' } });
    await createNode(db, { kind: 'component', levelTermId: comp, parentId: root.id, title: { en: 'kid' } });
    await expect(softDeleteNode(db, root.id, null)).rejects.toThrow(/children/i);
  });
});
```

- [ ] **Step 4: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL (queries/nodes 없음).

- [ ] **Step 5: 구현**

`packages/db/src/queries/nodes.ts`:
```ts
import { and, eq, isNull, sql as dsql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { sql } from 'drizzle-orm';
import { slugify } from '@binder/core';
import { nodes } from '../schema/index.js';
import type { I18nText } from '../types.js';

export type NodeRow = typeof nodes.$inferSelect;

export type CreateNodeInput = {
  kind: 'work' | 'component' | 'record' | 'supporting';
  levelTermId: number;
  title: I18nText;
  parentId?: number;
  profileId?: number;
  profileVersion?: number;
  identifier?: string;
  accessionNumber?: string;
  componentNumber?: string;
  slug?: string;
  descriptive?: Record<string, unknown>;
  departmentTermId?: number;
  componentTypeTermId?: number;
  sourceCulture?: 'ko' | 'en';
};

export async function createNode(db: PostgresJsDatabase, input: CreateNodeInput): Promise<NodeRow> {
  if (input.parentId == null && input.profileId == null) {
    throw new Error('root nodes require a profile (profileId/profileVersion)');
  }

  if (input.parentId != null) {
    const [parent] = await db.select({ id: nodes.id, deletedAt: nodes.deletedAt })
      .from(nodes).where(eq(nodes.id, input.parentId));
    if (!parent || parent.deletedAt !== null) throw new Error('parent not found or deleted');
  }

  const base = input.slug ?? slugify(input.title.ko ?? input.title.en ?? 'node');
  const slug = await dedupeSlug(db, base);

  const [{ next }] = (await db.execute(sql`
    SELECT COALESCE(MAX(position) + 1, 0) AS next FROM nodes
    WHERE parent_id IS NOT DISTINCT FROM ${input.parentId ?? null} AND deleted_at IS NULL
  `)) as unknown as Array<{ next: number }>;

  const [row] = await db.insert(nodes).values({
    kind: input.kind,
    levelTermId: input.levelTermId,
    title: input.title,
    parentId: input.parentId,
    profileId: input.profileId,
    profileVersion: input.profileVersion,
    identifier: input.identifier,
    accessionNumber: input.accessionNumber,
    componentNumber: input.componentNumber,
    slug,
    position: Number(next),
    descriptive: input.descriptive,
    departmentTermId: input.departmentTermId,
    componentTypeTermId: input.componentTypeTermId,
    sourceCulture: input.sourceCulture ?? 'ko',
  }).returning();
  return row;
}

async function dedupeSlug(db: PostgresJsDatabase, base: string): Promise<string> {
  const rows = (await db.execute(sql`
    SELECT slug FROM nodes WHERE deleted_at IS NULL AND (slug = ${base} OR slug LIKE ${base + '-%'})
  `)) as unknown as Array<{ slug: string }>;
  if (!rows.some((r) => r.slug === base)) return base;
  let n = 2;
  const taken = new Set(rows.map((r) => r.slug));
  while (taken.has(`${base}-${n}`)) n++;
  return `${base}-${n}`;
}

export type TreeRow = {
  id: number; parentId: number | null; depth: number; position: number;
  kind: string; levelTermId: number; title: I18nText; slug: string;
};

export async function getSubtree(db: PostgresJsDatabase, rootId: number): Promise<TreeRow[]> {
  const rows = (await db.execute(sql`
    WITH RECURSIVE subtree AS (
      SELECT id, parent_id, 0 AS depth, position, kind, level_term_id, title, slug,
             ARRAY[position] AS path
      FROM nodes WHERE id = ${rootId} AND deleted_at IS NULL
      UNION ALL
      SELECT n.id, n.parent_id, s.depth + 1, n.position, n.kind, n.level_term_id, n.title, n.slug,
             s.path || n.position
      FROM nodes n JOIN subtree s ON n.parent_id = s.id
      WHERE n.deleted_at IS NULL
    )
    SELECT id, parent_id, depth, position, kind, level_term_id, title, slug
    FROM subtree ORDER BY path
  `)) as unknown as Array<Record<string, unknown>>;

  return rows.map((r) => ({
    id: Number(r.id), parentId: r.parent_id === null ? null : Number(r.parent_id),
    depth: Number(r.depth), position: Number(r.position), kind: String(r.kind),
    levelTermId: Number(r.level_term_id), title: r.title as I18nText, slug: String(r.slug),
  }));
}

export async function softDeleteNode(db: PostgresJsDatabase, id: number, userId: number | null): Promise<void> {
  const [child] = await db.select({ id: nodes.id }).from(nodes)
    .where(and(eq(nodes.parentId, id), isNull(nodes.deletedAt))).limit(1);
  if (child) throw new Error('cannot delete: live children exist');
  await db.update(nodes)
    .set({ deletedAt: dsql`now()`, deletedBy: userId, updatedAt: dsql`now()` })
    .where(and(eq(nodes.id, id), isNull(nodes.deletedAt)));
}
```

`packages/db/package.json` dependencies에 추가: `"@binder/core": "workspace:*"`
`packages/db/src/index.ts`에 추가:
```ts
export { createNode, getSubtree, softDeleteNode } from './queries/nodes.js';
export type { CreateNodeInput, NodeRow, TreeRow } from './queries/nodes.js';
export { createProfileVersion } from './queries/profiles.js';
```

- [ ] **Step 6: 테스트 그린 + 커밋**

Run: `pnpm install && pnpm --filter @binder/db test` → PASS (nodes-queries 7 + 기존 전부).

```bash
git add -A && git commit -m "feat(db): node create/tree/soft-delete queries with slug dedupe and position ordering"
```

---

### Task 5: agents + events 스키마 & 제작자 파생 — TDD

**Files:**
- Create: `packages/db/src/schema/agents.ts`, `packages/db/src/queries/events.ts`
- Modify: `packages/db/src/schema/index.ts`, `packages/db/src/index.ts`
- Test: `packages/db/test/agents-events.test.ts`

**Interfaces:**
- Consumes: nodes(Task 3), terms(event_type 시드: creation/collection).
- Produces:
  - 테이블 `agents`(kind enum person/organization, name/history JSONB, slug partial unique), `events`(node_id, type_term_id, agent_id?, start_date/end_date, date_display JSONB)
  - `setCreationEvent(db, nodeId, { agentId, dateDisplay, startDate?, endDate? }): Promise<void>` — 노드당 creation 이벤트 1개 upsert(단일 진실원)
  - `getCreators(db, nodeId): Promise<Array<{ agentId: number; name: I18nText }>>` — creation 이벤트에서 파생

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/agents-events.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { agents, collectionProfiles, events } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';
import { getCreators, setCreationEvent } from '../src/queries/events.js';

const { db, sql } = testDb();
let nodeId: number; let agentA: number; let agentB: number;

beforeAll(async () => {
  await truncate(sql, 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const work = await findTermId(db, 'level_of_description', 'artwork_record');
  nodeId = (await createNode(db, { kind: 'work', levelTermId: work, profileId: p.id, profileVersion: 1, title: { en: 'Lovers' } })).id;
  const [a] = await db.insert(agents).values({ kind: 'person', name: { ko: '후루하시 테이지', en: 'Teiji Furuhashi' }, slug: 'teiji-furuhashi' }).returning();
  const [b] = await db.insert(agents).values({ kind: 'person', name: { en: 'Someone Else' }, slug: 'someone-else' }).returning();
  agentA = a.id; agentB = b.id;
});
afterAll(() => sql.end());

describe('creation event as single source of truth', () => {
  it('sets a creation event and derives the creator', async () => {
    await setCreationEvent(db, nodeId, { agentId: agentA, dateDisplay: { ko: '1994', en: '1994' }, startDate: '1994-01-01' });
    const creators = await getCreators(db, nodeId);
    expect(creators).toHaveLength(1);
    expect(creators[0].name.en).toBe('Teiji Furuhashi');
  });

  it('upserts (one creation event per node), replacing the agent', async () => {
    await setCreationEvent(db, nodeId, { agentId: agentB, dateDisplay: { en: 'c. 1994' } });
    const rows = await db.select().from(events).where(eq(events.nodeId, nodeId));
    expect(rows).toHaveLength(1);
    const creators = await getCreators(db, nodeId);
    expect(creators[0].name.en).toBe('Someone Else');
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현 + 마이그레이션**

`packages/db/src/schema/agents.ts`:
```ts
import { date, integer, jsonb, pgEnum, pgTable, serial, text, timestamp, uniqueIndex, index } from 'drizzle-orm/pg-core';
import { sql } from 'drizzle-orm';
import type { I18nText } from '../types.js';
import { nodes } from './nodes.js';
import { terms } from './taxonomies.js';

export const agentKindEnum = pgEnum('agent_kind', ['person', 'organization']);

export const agents = pgTable('agents', {
  id: serial('id').primaryKey(),
  kind: agentKindEnum('kind').notNull(),
  name: jsonb('name').$type<I18nText>().notNull(),
  slug: text('slug').notNull(),
  history: jsonb('history').$type<I18nText>(),
  sourceCulture: text('source_culture').notNull().default('ko'),
  deletedAt: timestamp('deleted_at', { withTimezone: true }),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [uniqueIndex('agents_slug_live_uq').on(t.slug).where(sql`deleted_at IS NULL`)]);

export const events = pgTable('events', {
  id: serial('id').primaryKey(),
  nodeId: integer('node_id').notNull().references(() => nodes.id, { onDelete: 'cascade' }),
  typeTermId: integer('type_term_id').notNull().references(() => terms.id, { onDelete: 'restrict' }),
  agentId: integer('agent_id').references(() => agents.id, { onDelete: 'set null' }),
  startDate: date('start_date'),
  endDate: date('end_date'),
  dateDisplay: jsonb('date_display').$type<I18nText>(),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [index('events_node_type_ix').on(t.nodeId, t.typeTermId)]);
```

`packages/db/src/queries/events.ts`:
```ts
import { and, eq } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { agents, events } from '../schema/index.js';
import { findTermId } from '../seed/seed-taxonomies.js';
import type { I18nText } from '../types.js';

export async function setCreationEvent(
  db: PostgresJsDatabase,
  nodeId: number,
  input: { agentId?: number; dateDisplay?: I18nText; startDate?: string; endDate?: string },
): Promise<void> {
  const creation = await findTermId(db, 'event_type', 'creation');
  const [existing] = await db.select({ id: events.id }).from(events)
    .where(and(eq(events.nodeId, nodeId), eq(events.typeTermId, creation)));
  if (existing) {
    await db.update(events).set({
      agentId: input.agentId, dateDisplay: input.dateDisplay,
      startDate: input.startDate, endDate: input.endDate,
    }).where(eq(events.id, existing.id));
  } else {
    await db.insert(events).values({
      nodeId, typeTermId: creation, agentId: input.agentId,
      dateDisplay: input.dateDisplay, startDate: input.startDate, endDate: input.endDate,
    });
  }
}

export async function getCreators(
  db: PostgresJsDatabase,
  nodeId: number,
): Promise<Array<{ agentId: number; name: I18nText }>> {
  const creation = await findTermId(db, 'event_type', 'creation');
  const rows = await db.select({ agentId: agents.id, name: agents.name })
    .from(events)
    .innerJoin(agents, eq(events.agentId, agents.id))
    .where(and(eq(events.nodeId, nodeId), eq(events.typeTermId, creation)));
  return rows;
}
```

`schema/index.ts`에 추가: `export { agentKindEnum, agents, events } from './agents.js';`
`src/index.ts`에 추가: `export { setCreationEvent, getCreators } from './queries/events.js';`

Run: `pnpm --filter @binder/db db:generate` → migration 0005.

- [ ] **Step 4: 테스트 그린 + 커밋**

Run: `pnpm --filter @binder/db test` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): agents and events with creation-event-as-source-of-truth creator derivation"
```

---

### Task 6: relations + notes 스키마 — TDD

**Files:**
- Create: `packages/db/src/schema/relations.ts`, `packages/db/src/queries/relations.ts`
- Modify: `packages/db/src/schema/index.ts`, `packages/db/src/index.ts`
- Test: `packages/db/test/relations-notes.test.ts`

**Interfaces:**
- Consumes: nodes, terms(relation_type·note_type 시드), users.
- Produces:
  - 테이블 `relations`(subject_node_id, object_node_id, type_term_id, description JSONB, start/end date), `notes`(node_id, type_term_id, content JSONB, user_id)
  - `linkNodes(db, subjectId, objectId, relationTypeMachine): Promise<void>` — 중복(동일 3튜플) 시 무시
  - `getRelationCounts(db, nodeIds: number[]): Promise<Map<number, number>>` — subject 기준 카운트(트리 배지용)

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/relations-notes.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { collectionProfiles, notes, relations } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';
import { getRelationCounts, linkNodes } from '../src/queries/relations.js';

const { db, sql } = testDb();
let artwork: number; let tech: number;

beforeAll(async () => {
  await truncate(sql, 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const workLv = await findTermId(db, 'level_of_description', 'artwork_record');
  const techLv = await findTermId(db, 'level_of_description', 'supporting_technology_record');
  artwork = (await createNode(db, { kind: 'work', levelTermId: workLv, profileId: p.id, profileVersion: 1, title: { en: 'Lovers' } })).id;
  tech = (await createNode(db, { kind: 'supporting', levelTermId: techLv, profileId: p.id, profileVersion: 1, title: { en: 'LD-V8000' } })).id;
});
afterAll(() => sql.end());

describe('relations', () => {
  it('links subject→object with a typed relation, idempotently', async () => {
    await linkNodes(db, artwork, tech, 'requires');
    await linkNodes(db, artwork, tech, 'requires');
    const rows = await db.select().from(relations).where(eq(relations.subjectNodeId, artwork));
    expect(rows).toHaveLength(1);
  });

  it('counts relations per subject for tree badges', async () => {
    const counts = await getRelationCounts(db, [artwork, tech]);
    expect(counts.get(artwork)).toBe(1);
    expect(counts.get(tech) ?? 0).toBe(0);
  });
});

describe('notes', () => {
  it('stores a typed bilingual note', async () => {
    const noteType = await findTermId(db, 'note_type', 'install_comments');
    await db.insert(notes).values({ nodeId: artwork, typeTermId: noteType, content: { ko: '설치 시 5채널 확인', en: 'Verify 5 channels on install' } });
    const rows = await db.select().from(notes).where(eq(notes.nodeId, artwork));
    expect(rows[0].content.ko).toMatch(/5채널/);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현 + 마이그레이션**

`packages/db/src/schema/relations.ts`:
```ts
import { date, integer, jsonb, pgTable, serial, text, timestamp, uniqueIndex, index } from 'drizzle-orm/pg-core';
import type { I18nText } from '../types.js';
import { nodes } from './nodes.js';
import { terms } from './taxonomies.js';
import { users } from './users.js';

export const relations = pgTable('relations', {
  id: serial('id').primaryKey(),
  subjectNodeId: integer('subject_node_id').notNull().references(() => nodes.id, { onDelete: 'cascade' }),
  objectNodeId: integer('object_node_id').notNull().references(() => nodes.id, { onDelete: 'cascade' }),
  typeTermId: integer('type_term_id').notNull().references(() => terms.id, { onDelete: 'restrict' }),
  description: jsonb('description').$type<I18nText>(),
  startDate: date('start_date'),
  endDate: date('end_date'),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [
  uniqueIndex('relations_subject_object_type_uq').on(t.subjectNodeId, t.objectNodeId, t.typeTermId),
  index('relations_subject_ix').on(t.subjectNodeId),
  index('relations_object_ix').on(t.objectNodeId),
]);

export const notes = pgTable('notes', {
  id: serial('id').primaryKey(),
  nodeId: integer('node_id').notNull().references(() => nodes.id, { onDelete: 'cascade' }),
  typeTermId: integer('type_term_id').notNull().references(() => terms.id, { onDelete: 'restrict' }),
  content: jsonb('content').$type<I18nText>().notNull(),
  sourceCulture: text('source_culture').notNull().default('ko'),
  userId: integer('user_id').references(() => users.id),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});
```

`packages/db/src/queries/relations.ts`:
```ts
import { inArray, sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { relations } from '../schema/index.js';
import { findTermId } from '../seed/seed-taxonomies.js';

export async function linkNodes(
  db: PostgresJsDatabase,
  subjectId: number,
  objectId: number,
  relationTypeMachine: string,
): Promise<void> {
  const typeId = await findTermId(db, 'relation_type', relationTypeMachine);
  await db.insert(relations)
    .values({ subjectNodeId: subjectId, objectNodeId: objectId, typeTermId: typeId })
    .onConflictDoNothing();
}

export async function getRelationCounts(
  db: PostgresJsDatabase,
  nodeIds: number[],
): Promise<Map<number, number>> {
  if (nodeIds.length === 0) return new Map();
  const rows = await db.select({
    subjectNodeId: relations.subjectNodeId,
    count: sql<number>`count(*)::int`,
  }).from(relations)
    .where(inArray(relations.subjectNodeId, nodeIds))
    .groupBy(relations.subjectNodeId);
  return new Map(rows.map((r) => [r.subjectNodeId, r.count]));
}
```

`schema/index.ts`에 추가: `export { relations, notes } from './relations.js';`
`src/index.ts`에 추가: `export { linkNodes, getRelationCounts } from './queries/relations.js';`

Run: `pnpm --filter @binder/db db:generate` → migration 0006.

- [ ] **Step 4: 테스트 그린 + 커밋**

Run: `pnpm --filter @binder/db test` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): typed node relations with idempotent linking and typed bilingual notes"
```

---

### Task 7: rights / rights_acts / node_rights 스키마 — TDD

**Files:**
- Create: `packages/db/src/schema/rights.ts`
- Modify: `packages/db/src/schema/index.ts`
- Test: `packages/db/test/rights.test.ts`

**Interfaces:**
- Consumes: nodes, agents, terms(rights_basis/rights_act/copyright_status 시드).
- Produces: 테이블 `rights`(basis_term_id, rights_holder_agent_id?, start/end date, copyright_* 컬럼, license_identifier, notes JSONB), `rightsActs`(rights_id CASCADE, act_term_id, restriction enum allow/disallow/conditional, start/end, notes JSONB), `nodeRights`(복합 PK) — Plan 04+ 접근 판단 UI가 소비.

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/rights.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { agents, collectionProfiles, nodeRights, rights, rightsActs } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';

const { db, sql } = testDb();
let nodeId: number; let holderId: number;

beforeAll(async () => {
  await truncate(sql, 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  nodeId = (await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, profileVersion: 1, title: { en: 'Rights holder test' } })).id;
  const [h] = await db.insert(agents).values({ kind: 'person', name: { en: 'Estate of X' }, slug: 'estate-x' }).returning();
  holderId = h.id;
});
afterAll(() => sql.end());

describe('PREMIS rights', () => {
  it('creates a copyright right with multiple acts (granted-right pattern)', async () => {
    const basis = await findTermId(db, 'rights_basis', 'copyright');
    const display = await findTermId(db, 'rights_act', 'display');
    const replicate = await findTermId(db, 'rights_act', 'replicate');

    const [r] = await db.insert(rights).values({
      basisTermId: basis, rightsHolderAgentId: holderId,
      copyrightJurisdiction: 'KR', notes: { ko: '기증 계약 3조' },
    }).returning();
    await db.insert(rightsActs).values([
      { rightsId: r.id, actTermId: display, restriction: 'allow' },
      { rightsId: r.id, actTermId: replicate, restriction: 'conditional', notes: { ko: '보존 목적에 한함' } },
    ]);
    await db.insert(nodeRights).values({ nodeId, rightsId: r.id });

    const acts = await db.select().from(rightsActs).where(eq(rightsActs.rightsId, r.id));
    expect(acts).toHaveLength(2);
  });

  it('cascades act deletion when the right is deleted', async () => {
    const [r] = await db.select().from(rights).limit(1);
    await db.delete(nodeRights).where(eq(nodeRights.rightsId, r.id));
    await db.delete(rights).where(eq(rights.id, r.id));
    const acts = await db.select().from(rightsActs).where(eq(rightsActs.rightsId, r.id));
    expect(acts).toHaveLength(0);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현 + 마이그레이션**

`packages/db/src/schema/rights.ts`:
```ts
import { date, integer, jsonb, pgEnum, pgTable, primaryKey, serial, text, timestamp } from 'drizzle-orm/pg-core';
import type { I18nText } from '../types.js';
import { agents } from './agents.js';
import { nodes } from './nodes.js';
import { terms } from './taxonomies.js';

export const rightsRestrictionEnum = pgEnum('rights_restriction', ['allow', 'disallow', 'conditional']);

export const rights = pgTable('rights', {
  id: serial('id').primaryKey(),
  basisTermId: integer('basis_term_id').notNull().references(() => terms.id, { onDelete: 'restrict' }),
  rightsHolderAgentId: integer('rights_holder_agent_id').references(() => agents.id, { onDelete: 'set null' }),
  startDate: date('start_date'),
  endDate: date('end_date'),
  copyrightStatusTermId: integer('copyright_status_term_id').references(() => terms.id, { onDelete: 'set null' }),
  copyrightJurisdiction: text('copyright_jurisdiction'),
  copyrightStatusDate: date('copyright_status_date'),
  statuteCitation: text('statute_citation'),
  licenseIdentifier: text('license_identifier'),
  notes: jsonb('notes').$type<I18nText>(),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export const rightsActs = pgTable('rights_acts', {
  id: serial('id').primaryKey(),
  rightsId: integer('rights_id').notNull().references(() => rights.id, { onDelete: 'cascade' }),
  actTermId: integer('act_term_id').notNull().references(() => terms.id, { onDelete: 'restrict' }),
  restriction: rightsRestrictionEnum('restriction').notNull(),
  startDate: date('start_date'),
  endDate: date('end_date'),
  notes: jsonb('notes').$type<I18nText>(),
});

export const nodeRights = pgTable('node_rights', {
  nodeId: integer('node_id').notNull().references(() => nodes.id, { onDelete: 'cascade' }),
  rightsId: integer('rights_id').notNull().references(() => rights.id, { onDelete: 'restrict' }),
}, (t) => [primaryKey({ columns: [t.nodeId, t.rightsId] })]);
```

`schema/index.ts`에 추가: `export { rightsRestrictionEnum, rights, rightsActs, nodeRights } from './rights.js';`

Run: `pnpm --filter @binder/db db:generate` → migration 0007.

- [ ] **Step 4: 테스트 그린 + 커밋**

Run: `pnpm --filter @binder/db test` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): PREMIS rights with per-act grants and node attachment"
```

---

### Task 8: audit_log + 감사 파이프라인 — TDD

**Files:**
- Create: `packages/db/src/schema/audit.ts`, `packages/db/src/queries/audit.ts`
- Modify: `packages/db/src/schema/index.ts`, `packages/db/src/index.ts`, `packages/db/src/queries/nodes.ts`(softDeleteNode에 감사 연결)
- Test: `packages/db/test/audit.test.ts`

**Interfaces:**
- Consumes: `diffFields`(@binder/core), users, nodes.
- Produces:
  - 테이블 `auditLog`(table_name, row_id, action enum insert/update/delete/restore, actor_id?, origin enum user/sync/ingest, job_ref?, at, changes JSONB, row_snapshot JSONB)
  - `writeAudit(db, entry: { tableName, rowId, action, actorId?, origin, jobRef?, before?, after?, jsonbTopLevel?, snapshot? }): Promise<void>` — update면 diffFields로 changes 계산, 변경 0이면 기록 생략; delete면 snapshot 필수
  - `softDeleteNode`가 `writeAudit(action:'delete', snapshot)` 호출하도록 확장(시그니처에 `origin` 옵션 추가: `softDeleteNode(db, id, userId, origin?)`)

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/audit.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq, and } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { auditLog, collectionProfiles } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode, softDeleteNode } from '../src/queries/nodes.js';
import { writeAudit } from '../src/queries/audit.js';

const { db, sql } = testDb();
let lv: number; let profileId: number;

beforeAll(async () => {
  await truncate(sql, 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  lv = await findTermId(db, 'level_of_description', 'artwork_record');
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  profileId = p.id;
});
afterAll(() => sql.end());

describe('writeAudit', () => {
  it('records field-level changes for updates and skips no-op updates', async () => {
    await writeAudit(db, {
      tableName: 'nodes', rowId: 1, action: 'update', origin: 'user', actorId: null,
      before: { title: { en: 'A' }, descriptive: { medium: 'video' } },
      after: { title: { en: 'B' }, descriptive: { medium: 'video' } },
      jsonbTopLevel: ['descriptive'],
    });
    const rows = await db.select().from(auditLog).where(eq(auditLog.tableName, 'nodes'));
    expect(rows).toHaveLength(1);
    expect(rows[0].changes).toEqual({ title: [{ en: 'A' }, { en: 'B' }] });

    await writeAudit(db, {
      tableName: 'nodes', rowId: 1, action: 'update', origin: 'user', actorId: null,
      before: { title: { en: 'B' } }, after: { title: { en: 'B' } },
    });
    const after = await db.select().from(auditLog).where(eq(auditLog.tableName, 'nodes'));
    expect(after).toHaveLength(1); // no-op은 기록 생략
  });

  it('requires a snapshot for deletes', async () => {
    await expect(writeAudit(db, {
      tableName: 'nodes', rowId: 2, action: 'delete', origin: 'user', actorId: null,
    })).rejects.toThrow(/snapshot/i);
  });
});

describe('softDeleteNode audit wiring', () => {
  it('writes a delete audit entry with row snapshot', async () => {
    const n = await createNode(db, { kind: 'work', levelTermId: lv, profileId, profileVersion: 1, title: { en: 'Bye' } });
    await softDeleteNode(db, n.id, null);
    const rows = await db.select().from(auditLog)
      .where(and(eq(auditLog.tableName, 'nodes'), eq(auditLog.rowId, n.id), eq(auditLog.action, 'delete')));
    expect(rows).toHaveLength(1);
    expect((rows[0].rowSnapshot as Record<string, unknown>).slug).toBe('bye');
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현 + 마이그레이션**

`packages/db/src/schema/audit.ts`:
```ts
import { bigserial, integer, jsonb, pgEnum, pgTable, text, timestamp, index } from 'drizzle-orm/pg-core';
import { users } from './users.js';

export const auditActionEnum = pgEnum('audit_action', ['insert', 'update', 'delete', 'restore']);
export const auditOriginEnum = pgEnum('audit_origin', ['user', 'sync', 'ingest']);

export const auditLog = pgTable('audit_log', {
  id: bigserial('id', { mode: 'number' }).primaryKey(),
  tableName: text('table_name').notNull(),
  rowId: integer('row_id').notNull(),
  action: auditActionEnum('action').notNull(),
  actorId: integer('actor_id').references(() => users.id),
  origin: auditOriginEnum('origin').notNull(),
  jobRef: text('job_ref'),
  at: timestamp('at', { withTimezone: true }).notNull().defaultNow(),
  changes: jsonb('changes').$type<Record<string, [unknown, unknown]>>(),
  rowSnapshot: jsonb('row_snapshot').$type<Record<string, unknown>>(),
}, (t) => [index('audit_row_ix').on(t.tableName, t.rowId, t.at)]);
```

`packages/db/src/queries/audit.ts`:
```ts
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { diffFields } from '@binder/core';
import { auditLog } from '../schema/index.js';

export type AuditEntry = {
  tableName: string;
  rowId: number;
  action: 'insert' | 'update' | 'delete' | 'restore';
  actorId: number | null;
  origin: 'user' | 'sync' | 'ingest';
  jobRef?: string;
  before?: Record<string, unknown>;
  after?: Record<string, unknown>;
  jsonbTopLevel?: string[];
  snapshot?: Record<string, unknown>;
};

export async function writeAudit(db: PostgresJsDatabase, entry: AuditEntry): Promise<void> {
  let changes: Record<string, [unknown, unknown]> | undefined;

  if (entry.action === 'update') {
    changes = diffFields(entry.before ?? {}, entry.after ?? {}, { jsonbTopLevel: entry.jsonbTopLevel });
    if (Object.keys(changes).length === 0) return; // no-op 생략
  }
  if (entry.action === 'delete' && !entry.snapshot) {
    throw new Error('delete audit entries require a row snapshot');
  }

  await db.insert(auditLog).values({
    tableName: entry.tableName,
    rowId: entry.rowId,
    action: entry.action,
    actorId: entry.actorId,
    origin: entry.origin,
    jobRef: entry.jobRef,
    changes,
    rowSnapshot: entry.action === 'delete' ? entry.snapshot : undefined,
  });
}
```

`packages/db/src/queries/nodes.ts`의 `softDeleteNode` 전체 교체:
```ts
export async function softDeleteNode(
  db: PostgresJsDatabase,
  id: number,
  userId: number | null,
  origin: 'user' | 'sync' | 'ingest' = 'user',
): Promise<void> {
  const [child] = await db.select({ id: nodes.id }).from(nodes)
    .where(and(eq(nodes.parentId, id), isNull(nodes.deletedAt))).limit(1);
  if (child) throw new Error('cannot delete: live children exist');

  const [row] = await db.select().from(nodes).where(and(eq(nodes.id, id), isNull(nodes.deletedAt)));
  if (!row) return;

  await db.update(nodes)
    .set({ deletedAt: dsql`now()`, deletedBy: userId, updatedAt: dsql`now()` })
    .where(eq(nodes.id, id));

  const { writeAudit } = await import('./audit.js'); // 순환 import 회피(런타임 지연 로드)
  await writeAudit(db, {
    tableName: 'nodes', rowId: id, action: 'delete', actorId: userId, origin,
    snapshot: row as unknown as Record<string, unknown>,
  });
}
```

`schema/index.ts`에 추가: `export { auditActionEnum, auditOriginEnum, auditLog } from './audit.js';`
`src/index.ts`에 추가: `export { writeAudit } from './queries/audit.js'; export type { AuditEntry } from './queries/audit.js';`

Run: `pnpm --filter @binder/db db:generate` → migration 0008.

- [ ] **Step 4: 테스트 그린 + 커밋**

Run: `pnpm --filter @binder/db test` → PASS (기존 softDeleteNode 테스트 포함 전부). `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): audit log with field-level diffs wired into node soft delete"
```

---

### Task 9: 혼합 컬렉션 데모 시더 + README 갱신 + 전체 검증

**Files:**
- Create: `packages/db/src/seed/seed-demo.ts`, `packages/db/scripts/seed-demo.ts`
- Modify: `packages/db/package.json`(스크립트), `README.md`
- Test: `packages/db/test/seed-demo.test.ts`

**Interfaces:**
- Consumes: Task 2–8 전부.
- Produces:
  - `seedDemo(db): Promise<void>` — 멱등. 내용: 프로파일 2종(미디어아트: descriptive 스키마 {medium, duration_min, channels}; 일반기록물: {scope_note, extent}) 각 버전1 + **미디어아트 작품 2점**(Lovers/후루하시 테이지 1994 — 컴포넌트 3, requires→지원기술 1, 설치노트 1, 저작권 rights 1(acts: display allow, replicate conditional); Grosse Fatigue/카미유 앙로 2013 — 컴포넌트 2) + **일반기록물 트리 1식**(퐁→시리즈→건 3계층, 수집 이벤트) — 전부 이중 언어 제목
  - 명령: `pnpm --filter @binder/db db:seed-demo`
  - README 갱신: 새 명령·패키지 구조 반영

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/seed-demo.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { isNull } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { nodes } from '../src/schema/index.js';
import { seedTaxonomies } from '../src/seed/seed-taxonomies.js';
import { seedDemo } from '../src/seed/seed-demo.js';
import { getSubtree } from '../src/queries/nodes.js';
import { getCreators } from '../src/queries/events.js';
import { getRelationCounts } from '../src/queries/relations.js';

const { db, sql } = testDb();

beforeAll(async () => {
  await truncate(sql, 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  await seedDemo(db);
});
afterAll(() => sql.end());

describe('demo seed', () => {
  it('is idempotent', async () => {
    const before = (await db.select({ id: nodes.id }).from(nodes).where(isNull(nodes.deletedAt))).length;
    await seedDemo(db);
    const after = (await db.select({ id: nodes.id }).from(nodes).where(isNull(nodes.deletedAt))).length;
    expect(after).toBe(before);
  });

  it('creates the Lovers artwork with 3 components, a creator, a relation and rights', async () => {
    const [lovers] = await db.select().from(nodes).where(isNull(nodes.deletedAt))
      .then((rows) => rows.filter((r) => r.slug === 'lovers'));
    expect(lovers).toBeDefined();
    const tree = await getSubtree(db, lovers.id);
    expect(tree.filter((t) => t.depth === 1)).toHaveLength(3);
    const creators = await getCreators(db, lovers.id);
    expect(creators[0].name.en).toBe('Teiji Furuhashi');
    const counts = await getRelationCounts(db, [lovers.id]);
    expect(counts.get(lovers.id)).toBe(1);
  });

  it('creates a 3-level archival tree (fonds→series→item)', async () => {
    const [fonds] = await db.select().from(nodes).where(isNull(nodes.deletedAt))
      .then((rows) => rows.filter((r) => r.kind === 'record' && r.parentId === null));
    expect(fonds).toBeDefined();
    const tree = await getSubtree(db, fonds.id);
    expect(Math.max(...tree.map((t) => t.depth))).toBe(2);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 시더 구현**

`packages/db/src/seed/seed-demo.ts`:
```ts
import { eq } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { agents, collectionProfiles, nodeRights, notes, rights, rightsActs } from '../schema/index.js';
import { findTermId } from './seed-taxonomies.js';
import { createProfileVersion } from '../queries/profiles.js';
import { createNode } from '../queries/nodes.js';
import { setCreationEvent } from '../queries/events.js';
import { linkNodes } from '../queries/relations.js';
import type { I18nText } from '../types.js';

async function ensureProfile(db: PostgresJsDatabase, name: I18nText, fieldSchema: Record<string, unknown>): Promise<number> {
  const all = await db.select().from(collectionProfiles);
  const found = all.find((p) => p.name.en === name.en);
  if (found) return found.id;
  const [p] = await db.insert(collectionProfiles).values({ name }).returning();
  await createProfileVersion(db, p.id, fieldSchema, null);
  return p.id;
}

async function ensureAgent(db: PostgresJsDatabase, slug: string, kind: 'person' | 'organization', name: I18nText): Promise<number> {
  const found = await db.select().from(agents).where(eq(agents.slug, slug));
  if (found.length > 0) return found[0].id;
  const [a] = await db.insert(agents).values({ kind, name, slug }).returning();
  return a.id;
}

export async function seedDemo(db: PostgresJsDatabase): Promise<void> {
  // 멱등 가드: lovers가 있으면 이미 시딩됨
  const existing = (await db.execute(
    (await import('drizzle-orm')).sql`SELECT id FROM nodes WHERE slug = 'lovers' AND deleted_at IS NULL`,
  )) as unknown as Array<{ id: number }>;
  if (existing.length > 0) return;

  const mediaArt = await ensureProfile(db, { ko: '미디어아트', en: 'Media art' }, {
    type: 'object',
    properties: { medium: { type: 'string' }, duration_min: { type: 'number' }, channels: { type: 'integer' } },
    additionalProperties: false,
  });
  const archive = await ensureProfile(db, { ko: '일반 기록물', en: 'General archives' }, {
    type: 'object',
    properties: { scope_note: { type: 'string' }, extent: { type: 'string' } },
    additionalProperties: false,
  });

  const lv = async (m: string) => findTermId(db, 'level_of_description', m);
  const furuhashi = await ensureAgent(db, 'teiji-furuhashi', 'person', { ko: '후루하시 테이지', en: 'Teiji Furuhashi' });
  const henrot = await ensureAgent(db, 'camille-henrot', 'person', { ko: '카미유 앙로', en: 'Camille Henrot' });

  // ── 미디어아트 작품 1: Lovers
  const lovers = await createNode(db, {
    kind: 'work', levelTermId: await lv('artwork_record'), profileId: mediaArt, profileVersion: 1,
    title: { ko: '러버스', en: 'Lovers' }, identifier: '100001', accessionNumber: '1250.2011',
    descriptive: { medium: 'Laser disc installation', channels: 5 }, sourceCulture: 'ko',
  });
  const loversComponents: Array<[string, I18nText, string]> = [
    ['archival_master', { ko: '보존용 마스터', en: 'Archival master' }, '1250.2011.x1'],
    ['exhibition_format', { ko: '전시용 포맷', en: 'Exhibition format' }, '1250.2011.x2'],
    ['duplicating_copy', { ko: '복제용 사본', en: 'Duplicating copy' }, '1250.2011.x3'],
  ];
  for (const [level, title, compNo] of loversComponents) {
    await createNode(db, { kind: 'component', levelTermId: await lv(level), parentId: lovers.id, title, componentNumber: compNo });
  }
  await setCreationEvent(db, lovers.id, { agentId: furuhashi, dateDisplay: { ko: '1994', en: '1994' }, startDate: '1994-01-01' });

  const tech = await createNode(db, {
    kind: 'supporting', levelTermId: await lv('supporting_technology_record'), profileId: mediaArt, profileVersion: 1,
    title: { ko: '파이오니어 LD-V8000 레이저디스크 플레이어', en: 'Pioneer LD-V8000 laserdisc player' },
  });
  await linkNodes(db, lovers.id, tech.id, 'requires');

  await db.insert(notes).values({
    nodeId: lovers.id, typeTermId: await findTermId(db, 'note_type', 'install_comments'),
    content: { ko: '5채널 동기화 필수', en: 'Five-channel sync required' },
  });

  const [right] = await db.insert(rights).values({
    basisTermId: await findTermId(db, 'rights_basis', 'copyright'),
    rightsHolderAgentId: furuhashi, copyrightJurisdiction: 'JP',
    copyrightStatusTermId: await findTermId(db, 'copyright_status', 'copyrighted'),
  }).returning();
  await db.insert(rightsActs).values([
    { rightsId: right.id, actTermId: await findTermId(db, 'rights_act', 'display'), restriction: 'allow' },
    { rightsId: right.id, actTermId: await findTermId(db, 'rights_act', 'replicate'), restriction: 'conditional', notes: { ko: '보존 목적에 한함', en: 'Preservation only' } },
  ]);
  await db.insert(nodeRights).values({ nodeId: lovers.id, rightsId: right.id });

  // ── 미디어아트 작품 2: Grosse Fatigue
  const gf = await createNode(db, {
    kind: 'work', levelTermId: await lv('artwork_record'), profileId: mediaArt, profileVersion: 1,
    title: { ko: '그로스 파티그', en: 'Grosse Fatigue' }, identifier: '100002', accessionNumber: '232.2014',
    descriptive: { medium: 'Video (color, sound)', duration_min: 13 },
  });
  await createNode(db, { kind: 'component', levelTermId: await lv('artist_verified_proof'), parentId: gf.id, title: { ko: '작가 검수 프루프', en: 'Artist verified proof' }, componentNumber: '232.2014.x1' });
  await createNode(db, { kind: 'component', levelTermId: await lv('exhibition_format'), parentId: gf.id, title: { ko: '전시용 포맷', en: 'Exhibition format' }, componentNumber: '232.2014.x2' });
  await setCreationEvent(db, gf.id, { agentId: henrot, dateDisplay: { ko: '2013', en: '2013' }, startDate: '2013-01-01' });

  // ── 일반 기록물 트리: 퐁 → 시리즈 → 건
  const fonds = await createNode(db, {
    kind: 'record', levelTermId: await lv('fonds'), profileId: archive, profileVersion: 1,
    title: { ko: '기관 운영 기록', en: 'Institutional records' },
    descriptive: { scope_note: '기관 설립 이후 운영 전반', extent: '3 boxes' },
  });
  const series = await createNode(db, {
    kind: 'record', levelTermId: await lv('series'), parentId: fonds.id,
    title: { ko: '전시 기획 문서', en: 'Exhibition planning documents' },
  });
  await createNode(db, {
    kind: 'record', levelTermId: await lv('item'), parentId: series.id,
    title: { ko: '1994년 전시 도록 초안', en: '1994 exhibition catalogue draft' },
  });
}
```

`packages/db/scripts/seed-demo.ts`:
```ts
import { createDb } from '../src/client.js';
import { seedDemo } from '../src/seed/seed-demo.js';

const url = process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder';
const { db, sql } = createDb(url);
await seedDemo(db);
await sql.end();
console.log('seeded demo collection');
```

`packages/db/package.json` scripts에 추가: `"db:seed-demo": "tsx scripts/seed-demo.ts"`

- [ ] **Step 4: 테스트 그린 확인**

Run: `pnpm --filter @binder/db test` → PASS (seed-demo 3 + 전체).

- [ ] **Step 5: README 갱신 + 전체 검증 + 커밋**

`README.md`의 시작하기 블록에 `pnpm --filter @binder/db db:seed-demo    # 데모 컬렉션 (멱등)` 줄 추가, 구조 섹션에 `packages/db — … + queries(노드 트리·이벤트·관계·감사)` 반영.

Run:
```bash
pnpm --filter @binder/db db:migrate && pnpm --filter @binder/db db:seed && pnpm --filter @binder/db db:seed-demo && pnpm --filter @binder/db db:seed-demo
pnpm test
```
Expected: 시드·데모시더 각 재실행 무오류(멱등), 전체 테스트 PASS.

```bash
git add -A && git commit -m "feat(db): idempotent mixed-collection demo seeder with bilingual fixtures"
```

---

## Self-Review

1. **Spec coverage (이 계획 범위)**: 데이터모델 §2A nodes/profiles=T2·T3, §2B 잔여(node_terms+결정3 단일FK)=T3, §2C agents/events/relations/rights/notes=T5·T6·T7, §2G audit_log=T8, §3.2 soft delete=T3·T4·T8, §3.3 트리 쿼리=T4, 데모=T9, Plan01 이연 정리=T1. 롤업 유지 로직·search_docs·보존 테이블은 Plan 03 명시 이관(§Architecture, T3 주석).
2. **Placeholder scan**: 전 스텝 실코드. "TBD" 없음. CHECK 제약의 앱 레벨 대체는 Global Constraints에 근거 명시.
3. **Type consistency**: `createNode`/`getSubtree`/`softDeleteNode` 시그니처가 T4 Produces↔구현↔T8 확장(origin 파라미터, T8에서 교체 코드 전체 제시)과 일치. `findTermId`는 Plan 01 시그니처 그대로. `I18nText` import 경로 일관(db는 ../types.js). T8 softDeleteNode의 지연 import는 순환(queries/nodes ↔ queries/audit) 회피 근거 주석 포함.
