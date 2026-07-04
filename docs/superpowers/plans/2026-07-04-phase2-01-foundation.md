# Binder Phase 2 — Plan 01: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** binder-next 모노레포를 생성하고 DB 인프라·i18n 규칙·택소노미(시드 포함)·사용자/역할·감사 diff 헬퍼까지 — 이후 모든 계획이 딛고 설 기반을 테스트 그린 상태로 만든다.

**Architecture:** pnpm workspaces + Turborepo 모노레포. `packages/core`(순수 TS 도메인 헬퍼, 프레임워크·DB 무지), `packages/db`(Drizzle 스키마 + 마이그레이션 + 시드). DB는 docker compose의 PostgreSQL 16. 테스트는 vitest — core는 순수 단위, db는 실제 PostgreSQL(binder_test DB, globalSetup에서 재생성+마이그레이션).

**Tech Stack:** Node 22, pnpm 9, TypeScript 5(strict, ESM), Turborepo 2, vitest 2, drizzle-orm + drizzle-kit + postgres(postgres-js), zod 3, @node-rs/argon2.

**설계 근거 문서:** `binder/docs/superpowers/specs/2026-07-04-binder-phase2-design.md`(설계서), `binder/docs-spike/phase2-data-model-review.md`(데이터 모델 상세 — 30테이블). 이 계획은 그중 기반 테이블(taxonomies/terms, users/roles/user_roles)과 횡단 규칙(i18n, 감사 diff)만 다룬다. nodes 스파인·보존 테이블은 Plan 02·03.

## Global Constraints

- 코드 저장소: **새 디렉토리 `/Users/daejinan/claude/moma-binder/binder-next`** (새 git repo). 이 계획 문서 자체는 binder 포크에 있음.
- Node `>=22`, pnpm `9.x`. 모든 패키지 ESM(`"type": "module"`), TypeScript `strict: true`.
- 패키지명: `@binder/core`, `@binder/db`.
- DB: PostgreSQL 16 (docker, 호스트 포트 **15432**), Redis 7 (호스트 포트 **16379** — 이 계획에선 컨테이너만 준비). 개발 DB `binder`, 테스트 DB `binder_test`.
- 테이블·컬럼 snake_case. 모든 timestamp는 `timestamptz`. PK는 `serial`(id).
- i18n 텍스트 = JSONB `{ko?, en?}`(최소 1개 로케일) + 동반 `source_culture` 컬럼('ko'|'en'). 폴백: 요청 로케일 → source_culture.
- 커밋 메시지: conventional commits (`feat:`, `chore:`, `test:` …). 각 태스크 말미 커밋.
- 시드는 **멱등**(재실행 시 중복 생성 없음). 시드 텀은 `machine_name`으로 조회(안정 키 — 데이터 모델 문서에 없던 컬럼이나 시드 조회를 위해 추가, terms에 (taxonomy_id, machine_name) partial unique).

---

### Task 1: 모노레포 스캐폴드

**Files:**
- Create: `binder-next/package.json`, `binder-next/pnpm-workspace.yaml`, `binder-next/turbo.json`, `binder-next/tsconfig.base.json`, `binder-next/.gitignore`, `binder-next/.nvmrc`
- Create: `binder-next/packages/core/package.json`, `binder-next/packages/core/tsconfig.json`, `binder-next/packages/core/vitest.config.ts`, `binder-next/packages/core/src/index.ts`, `binder-next/packages/core/test/smoke.test.ts`

**Interfaces:**
- Consumes: 없음 (첫 태스크)
- Produces: 워크스페이스 명령 규약 — `pnpm test`(루트, turbo 경유)가 전 패키지 vitest 실행; `@binder/core`가 `src/index.ts`에서 re-export하는 패턴.

- [ ] **Step 1: 디렉토리·git·워크스페이스 파일 생성**

```bash
mkdir -p /Users/daejinan/claude/moma-binder/binder-next/packages/core/src /Users/daejinan/claude/moma-binder/binder-next/packages/core/test
cd /Users/daejinan/claude/moma-binder/binder-next && git init -b main
```

`binder-next/package.json`:
```json
{
  "name": "binder-next",
  "private": true,
  "type": "module",
  "engines": { "node": ">=22" },
  "packageManager": "pnpm@9.15.0",
  "scripts": {
    "test": "turbo run test",
    "build": "turbo run build",
    "db:up": "docker compose -f docker/docker-compose.yml up -d",
    "db:down": "docker compose -f docker/docker-compose.yml down"
  },
  "devDependencies": {
    "turbo": "^2.3.0",
    "typescript": "^5.6.0"
  }
}
```

`binder-next/pnpm-workspace.yaml`:
```yaml
packages:
  - "apps/*"
  - "packages/*"
```

`binder-next/turbo.json`:
```json
{
  "$schema": "https://turbo.build/schema.json",
  "tasks": {
    "build": { "dependsOn": ["^build"], "outputs": ["dist/**"] },
    "test": { "dependsOn": ["^build"] }
  }
}
```

`binder-next/tsconfig.base.json`:
```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "NodeNext",
    "moduleResolution": "NodeNext",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "declaration": true,
    "verbatimModuleSyntax": true
  }
}
```

`binder-next/.gitignore`:
```
node_modules/
dist/
.turbo/
.env
*.log
```

`binder-next/.nvmrc`:
```
22
```

- [ ] **Step 2: core 패키지 뼈대 + 스모크 테스트 작성**

`binder-next/packages/core/package.json`:
```json
{
  "name": "@binder/core",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "exports": { ".": "./src/index.ts" },
  "scripts": { "test": "vitest run" },
  "devDependencies": { "vitest": "^2.1.0" },
  "dependencies": { "zod": "^3.23.0" }
}
```

`binder-next/packages/core/tsconfig.json`:
```json
{ "extends": "../../tsconfig.base.json", "include": ["src", "test"] }
```

`binder-next/packages/core/vitest.config.ts`:
```ts
import { defineConfig } from 'vitest/config';
export default defineConfig({ test: { include: ['test/**/*.test.ts'] } });
```

`binder-next/packages/core/src/index.ts`:
```ts
export const CORE_VERSION = '0.1.0';
```

`binder-next/packages/core/test/smoke.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { CORE_VERSION } from '../src/index.js';

describe('workspace smoke', () => {
  it('imports the core package', () => {
    expect(CORE_VERSION).toBe('0.1.0');
  });
});
```

- [ ] **Step 3: 설치 후 테스트 실행 — 그린 확인**

Run: `cd /Users/daejinan/claude/moma-binder/binder-next && pnpm install && pnpm test`
Expected: turbo가 `@binder/core#test` 실행, `1 passed`.

- [ ] **Step 4: 커밋**

```bash
cd /Users/daejinan/claude/moma-binder/binder-next
git add -A && git commit -m "chore: scaffold pnpm+turborepo monorepo with core package"
```

---

### Task 2: docker compose + db 패키지(Drizzle) 인프라

**Files:**
- Create: `binder-next/docker/docker-compose.yml`, `binder-next/.env.example`
- Create: `binder-next/packages/db/package.json`, `binder-next/packages/db/tsconfig.json`, `binder-next/packages/db/drizzle.config.ts`, `binder-next/packages/db/vitest.config.ts`
- Create: `binder-next/packages/db/src/client.ts`, `binder-next/packages/db/src/index.ts`, `binder-next/packages/db/src/types.ts`
- Create: `binder-next/packages/db/scripts/migrate.ts`, `binder-next/packages/db/test/global-setup.ts`, `binder-next/packages/db/test/helpers.ts`, `binder-next/packages/db/test/connection.test.ts`

**Interfaces:**
- Consumes: Task 1 워크스페이스.
- Produces:
  - `createDb(url: string): { db: PostgresJsDatabase; sql: postgres.Sql }` — `@binder/db`의 클라이언트 팩토리
  - `I18nText = { ko?: string; en?: string }` 타입 (`@binder/db`의 `types.ts`)
  - 명령: `pnpm --filter @binder/db db:generate`(마이그레이션 생성), `db:migrate`(적용), `test`(binder_test 재생성+마이그레이션 후 vitest)
  - 테스트 헬퍼: `testDb()` — binder_test에 연결된 db/sql, `truncate(sql, ...tables)`

- [ ] **Step 1: compose·env 파일 작성**

`binder-next/docker/docker-compose.yml`:
```yaml
name: binder-next
services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: binder
      POSTGRES_PASSWORD: binder
      POSTGRES_DB: binder
    ports: ["15432:5432"]
    volumes: ["pg-data:/var/lib/postgresql/data"]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U binder"]
      interval: 3s
      timeout: 3s
      retries: 20
  redis:
    image: redis:7-alpine
    ports: ["16379:6379"]
volumes:
  pg-data:
```

`binder-next/.env.example`:
```
DATABASE_URL=postgres://binder:binder@localhost:15432/binder
DATABASE_URL_TEST=postgres://binder:binder@localhost:15432/binder_test
REDIS_URL=redis://localhost:16379
```

- [ ] **Step 2: db 패키지 구성**

`binder-next/packages/db/package.json`:
```json
{
  "name": "@binder/db",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "exports": { ".": "./src/index.ts" },
  "scripts": {
    "db:generate": "drizzle-kit generate",
    "db:migrate": "tsx scripts/migrate.ts",
    "test": "vitest run"
  },
  "dependencies": {
    "drizzle-orm": "^0.36.0",
    "postgres": "^3.4.0"
  },
  "devDependencies": {
    "drizzle-kit": "^0.28.0",
    "tsx": "^4.19.0",
    "vitest": "^2.1.0"
  }
}
```

`binder-next/packages/db/tsconfig.json`:
```json
{ "extends": "../../tsconfig.base.json", "include": ["src", "test", "scripts", "drizzle.config.ts"] }
```

`binder-next/packages/db/drizzle.config.ts`:
```ts
import { defineConfig } from 'drizzle-kit';

export default defineConfig({
  dialect: 'postgresql',
  schema: './src/schema',
  out: './migrations',
  dbCredentials: {
    url: process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder',
  },
});
```

`binder-next/packages/db/src/types.ts`:
```ts
/** JSONB {ko,en} — 최소 1개 로케일. 런타임 검증은 @binder/core의 i18nTextSchema가 담당. */
export type I18nText = { ko?: string; en?: string };
export type Culture = 'ko' | 'en';
```

`binder-next/packages/db/src/client.ts`:
```ts
import { drizzle, type PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import postgres from 'postgres';

export function createDb(url: string): { db: PostgresJsDatabase; sql: postgres.Sql } {
  const sql = postgres(url, { max: 10, onnotice: () => {} });
  const db = drizzle(sql);
  return { db, sql };
}
```

`binder-next/packages/db/src/index.ts`:
```ts
export { createDb } from './client.js';
export type { I18nText, Culture } from './types.js';
```

- [ ] **Step 3: 마이그레이션 스크립트 + 테스트 하네스 작성**

`binder-next/packages/db/scripts/migrate.ts`:
```ts
import { drizzle } from 'drizzle-orm/postgres-js';
import { migrate } from 'drizzle-orm/postgres-js/migrator';
import postgres from 'postgres';

const url = process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder';
const sql = postgres(url, { max: 1, onnotice: () => {} });
await migrate(drizzle(sql), { migrationsFolder: new URL('../migrations', import.meta.url).pathname });
await sql.end();
console.log('migrated:', url.replace(/:[^:@]+@/, ':***@'));
```

`binder-next/packages/db/test/global-setup.ts`:
```ts
import { drizzle } from 'drizzle-orm/postgres-js';
import { migrate } from 'drizzle-orm/postgres-js/migrator';
import postgres from 'postgres';

const ADMIN_URL = 'postgres://binder:binder@localhost:15432/binder';
const TEST_URL = process.env.DATABASE_URL_TEST ?? 'postgres://binder:binder@localhost:15432/binder_test';

export default async function setup(): Promise<void> {
  const admin = postgres(ADMIN_URL, { max: 1, onnotice: () => {} });
  await admin.unsafe('DROP DATABASE IF EXISTS binder_test');
  await admin.unsafe('CREATE DATABASE binder_test');
  await admin.end();

  const sql = postgres(TEST_URL, { max: 1, onnotice: () => {} });
  await migrate(drizzle(sql), { migrationsFolder: new URL('../migrations', import.meta.url).pathname });
  await sql.end();
}
```

`binder-next/packages/db/test/helpers.ts`:
```ts
import postgres from 'postgres';
import { drizzle, type PostgresJsDatabase } from 'drizzle-orm/postgres-js';

const TEST_URL = process.env.DATABASE_URL_TEST ?? 'postgres://binder:binder@localhost:15432/binder_test';

export function testDb(): { db: PostgresJsDatabase; sql: postgres.Sql } {
  const sql = postgres(TEST_URL, { max: 2, onnotice: () => {} });
  return { db: drizzle(sql), sql };
}

export async function truncate(sql: postgres.Sql, ...tables: string[]): Promise<void> {
  if (tables.length === 0) return;
  await sql.unsafe(`TRUNCATE ${tables.map((t) => `"${t}"`).join(', ')} RESTART IDENTITY CASCADE`);
}
```

`binder-next/packages/db/vitest.config.ts`:
```ts
import { defineConfig } from 'vitest/config';
export default defineConfig({
  test: {
    include: ['test/**/*.test.ts'],
    globalSetup: ['./test/global-setup.ts'],
    fileParallelism: false,
    pool: 'forks',
  },
});
```

`binder-next/packages/db/test/connection.test.ts` (실패 확인용 최초 테스트):
```ts
import { afterAll, describe, expect, it } from 'vitest';
import { testDb } from './helpers.js';

const { sql } = testDb();
afterAll(() => sql.end());

describe('db infrastructure', () => {
  it('connects to binder_test and answers SELECT 1', async () => {
    const rows = await sql`SELECT 1 AS one`;
    expect(rows[0].one).toBe(1);
  });
});
```

- [ ] **Step 4: DB 기동 + 빈 마이그레이션 상태로 테스트 실행**

Run:
```bash
cd /Users/daejinan/claude/moma-binder/binder-next
pnpm install
pnpm db:up
mkdir -p packages/db/migrations   # 마이그레이션 0개도 migrator가 통과하도록 폴더만 생성
pnpm --filter @binder/db test
```
Expected: global-setup이 binder_test 재생성, `connection.test.ts` PASS (1 passed).
(참고: postgres가 뜨기 전이면 재시도 — `docker compose -f docker/docker-compose.yml ps`로 healthy 확인.)

- [ ] **Step 5: 커밋**

```bash
git add -A && git commit -m "chore: add docker compose (pg16/redis7) and drizzle db package with test harness"
```

---

### Task 3: i18n 헬퍼 (`@binder/core`) — TDD

**Files:**
- Create: `binder-next/packages/core/src/i18n.ts`
- Modify: `binder-next/packages/core/src/index.ts`
- Test: `binder-next/packages/core/test/i18n.test.ts`

**Interfaces:**
- Consumes: 없음 (순수 모듈).
- Produces:
  - `i18nTextSchema: z.ZodType<I18nText>` — `{ko?, en?}` 최소 1개 로케일 강제
  - `type I18nText = { ko?: string; en?: string }`, `type Culture = 'ko' | 'en'`
  - `pickI18n(text: I18nText, culture: Culture, sourceCulture: Culture): string` — 폴백 규칙의 유일 구현

- [ ] **Step 1: 실패하는 테스트 작성**

`binder-next/packages/core/test/i18n.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { i18nTextSchema, pickI18n } from '../src/i18n.js';

describe('i18nTextSchema', () => {
  it('accepts ko-only, en-only, and both', () => {
    expect(i18nTextSchema.parse({ ko: '러버스' })).toEqual({ ko: '러버스' });
    expect(i18nTextSchema.parse({ en: 'Lovers' })).toEqual({ en: 'Lovers' });
    expect(i18nTextSchema.parse({ ko: '러버스', en: 'Lovers' })).toEqual({ ko: '러버스', en: 'Lovers' });
  });

  it('rejects an empty object and unknown locales', () => {
    expect(() => i18nTextSchema.parse({})).toThrow();
    expect(() => i18nTextSchema.parse({ fr: 'Amants' })).toThrow();
  });
});

describe('pickI18n fallback', () => {
  it('returns the requested culture when present', () => {
    expect(pickI18n({ ko: '러버스', en: 'Lovers' }, 'en', 'ko')).toBe('Lovers');
  });

  it('falls back to source culture when requested culture is missing', () => {
    expect(pickI18n({ ko: '러버스' }, 'en', 'ko')).toBe('러버스');
  });

  it('falls back to any available locale when even source culture is missing', () => {
    expect(pickI18n({ en: 'Lovers' }, 'ko', 'ko')).toBe('Lovers');
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/core test`
Expected: FAIL — `Cannot find module '../src/i18n.js'`.

- [ ] **Step 3: 최소 구현**

`binder-next/packages/core/src/i18n.ts`:
```ts
import { z } from 'zod';

export type Culture = 'ko' | 'en';
export type I18nText = { ko?: string; en?: string };

export const i18nTextSchema: z.ZodType<I18nText> = z
  .object({ ko: z.string().min(1).optional(), en: z.string().min(1).optional() })
  .strict()
  .refine((v) => v.ko !== undefined || v.en !== undefined, { message: 'at least one locale required' });

/** 폴백 규칙: 요청 로케일 → source_culture → 남은 로케일. */
export function pickI18n(text: I18nText, culture: Culture, sourceCulture: Culture): string {
  return text[culture] ?? text[sourceCulture] ?? text.ko ?? text.en ?? '';
}
```

`binder-next/packages/core/src/index.ts` (전체 교체):
```ts
export const CORE_VERSION = '0.1.0';
export { i18nTextSchema, pickI18n } from './i18n.js';
export type { I18nText, Culture } from './i18n.js';
```

- [ ] **Step 4: 테스트 그린 확인**

Run: `pnpm --filter @binder/core test`
Expected: PASS (스모크 포함 6 passed).

- [ ] **Step 5: 커밋**

```bash
git add -A && git commit -m "feat(core): i18n text schema and fallback picker"
```

---

### Task 4: 택소노미·텀 스키마 + 시드 — TDD

**Files:**
- Create: `binder-next/packages/db/src/schema/taxonomies.ts`, `binder-next/packages/db/src/schema/index.ts`
- Create: `binder-next/packages/db/src/seed/taxonomy-data.ts`, `binder-next/packages/db/src/seed/seed-taxonomies.ts`, `binder-next/packages/db/scripts/seed.ts`
- Modify: `binder-next/packages/db/src/index.ts`, `binder-next/packages/db/package.json`(스크립트 추가)
- Test: `binder-next/packages/db/test/taxonomies.test.ts`

**Interfaces:**
- Consumes: Task 2의 `createDb`/`testDb`/`truncate`, Task 3 의미 규칙(name JSONB는 {ko,en}).
- Produces:
  - Drizzle 테이블 `taxonomies`, `terms` (컬럼은 Step 3 코드가 정의 — 이후 계획들의 FK 대상)
  - `seedTaxonomies(db): Promise<void>` — 멱등 시드
  - `findTermId(db, taxonomyMachineName, termMachineName): Promise<number>` — 시드 텀 조회(이후 계획에서 LOD/타입 FK를 얻는 표준 방법)
  - 명령: `pnpm --filter @binder/db db:seed`
  - 시드 내용: 택소노미 12종 — `level_of_description`(23텀: Binder 19 + fonds/series/file/item), `classification`(빈), `department`(빈), `component_type`(빈), `relation_type`(12텀, reciprocal 쌍), `aip_type`(4), `note_type`(4), `media_type`(6), `event_type`(2: creation/collection), `rights_basis`(5), `rights_act`(5), `copyright_status`(3)

- [ ] **Step 1: 실패하는 테스트 작성**

`binder-next/packages/db/test/taxonomies.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { taxonomies, terms } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';

const { db, sql } = testDb();

beforeAll(async () => {
  await truncate(sql, 'terms', 'taxonomies');
  await seedTaxonomies(db);
});
afterAll(() => sql.end());

describe('taxonomy seeds', () => {
  it('creates 12 taxonomies', async () => {
    const rows = await db.select().from(taxonomies);
    expect(rows).toHaveLength(12);
  });

  it('seeds 23 levels of description including archival levels', async () => {
    const lodId = (await db.select().from(taxonomies).where(eq(taxonomies.machineName, 'level_of_description')))[0].id;
    const lods = await db.select().from(terms).where(eq(terms.taxonomyId, lodId));
    expect(lods).toHaveLength(23);
    const names = lods.map((t) => t.machineName);
    for (const required of ['artwork_record', 'component', 'aip', 'digital_object', 'fonds', 'series', 'file', 'item']) {
      expect(names).toContain(required);
    }
  });

  it('links reciprocal relation-type terms both ways', async () => {
    const isPartOf = await findTermId(db, 'relation_type', 'is_part_of');
    const hasPart = await findTermId(db, 'relation_type', 'has_part');
    const isPartOfRow = (await db.select().from(terms).where(eq(terms.id, isPartOf)))[0];
    const hasPartRow = (await db.select().from(terms).where(eq(terms.id, hasPart)))[0];
    expect(isPartOfRow.reciprocalTermId).toBe(hasPart);
    expect(hasPartRow.reciprocalTermId).toBe(isPartOf);
  });

  it('is idempotent — reseeding creates no duplicates', async () => {
    await seedTaxonomies(db);
    const rows = await db.select().from(terms);
    const again = await db.select().from(terms);
    expect(again.length).toBe(rows.length);
  });

  it('findTermId throws for unknown terms', async () => {
    await expect(findTermId(db, 'aip_type', 'nope')).rejects.toThrow(/not found/i);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test`
Expected: FAIL — `Cannot find module '../src/schema/index.js'`.

- [ ] **Step 3: 스키마 구현 + 마이그레이션 생성**

`binder-next/packages/db/src/schema/taxonomies.ts`:
```ts
import {
  integer, jsonb, pgTable, serial, text, timestamp, uniqueIndex,
} from 'drizzle-orm/pg-core';
import type { I18nText } from '../types.js';

export const taxonomies = pgTable('taxonomies', {
  id: serial('id').primaryKey(),
  machineName: text('machine_name').notNull().unique(),
  name: jsonb('name').$type<I18nText>().notNull(),
  sourceCulture: text('source_culture').notNull().default('ko'),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export const terms = pgTable('terms', {
  id: serial('id').primaryKey(),
  taxonomyId: integer('taxonomy_id').notNull().references(() => taxonomies.id, { onDelete: 'restrict' }),
  machineName: text('machine_name'),
  name: jsonb('name').$type<I18nText>().notNull(),
  sourceCulture: text('source_culture').notNull().default('ko'),
  reciprocalTermId: integer('reciprocal_term_id'),
  position: integer('position').notNull().default(0),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [
  uniqueIndex('terms_taxonomy_machine_name_uq').on(t.taxonomyId, t.machineName),
]);
```

`binder-next/packages/db/src/schema/index.ts`:
```ts
export { taxonomies, terms } from './taxonomies.js';
```

`binder-next/packages/db/src/index.ts`에 추가:
```ts
export { createDb } from './client.js';
export type { I18nText, Culture } from './types.js';
export * as schema from './schema/index.js';
```

Run: `pnpm --filter @binder/db db:generate`
Expected: `migrations/0000_*.sql` 생성 (taxonomies, terms).

- [ ] **Step 4: 시드 데이터·시더 구현**

`binder-next/packages/db/src/seed/taxonomy-data.ts`:
```ts
import type { I18nText } from '../types.js';

type TermSeed = { machine: string; name: I18nText; reciprocal?: string };
type TaxonomySeed = { machine: string; name: I18nText; terms: TermSeed[] };

const lod = (machine: string, ko: string, en: string): TermSeed => ({ machine, name: { ko, en } });

export const TAXONOMY_SEEDS: TaxonomySeed[] = [
  {
    machine: 'level_of_description',
    name: { ko: '서술 수준', en: 'Levels of description' },
    terms: [
      // Binder 19종 (Phase 1 arDrmcBootstrapTask 순서 유지)
      lod('artwork_record', '작품 레코드', 'Artwork record'),
      lod('description', '기술', 'Description'),
      lod('component', '컴포넌트', 'Component'),
      lod('artist_supplied_master', '작가 제공 마스터', 'Artist supplied master'),
      lod('artist_verified_proof', '작가 검수 프루프', 'Artist verified proof'),
      lod('archival_master', '보존용 마스터', 'Archival master'),
      lod('exhibition_format', '전시용 포맷', 'Exhibition format'),
      lod('documentation', '도큐멘테이션', 'Documentation'),
      lod('miscellaneous', '기타', 'Miscellaneous'),
      lod('supporting_technology_record', '지원 기술 레코드', 'Supporting technology record'),
      lod('aip', 'AIP', 'AIP'),
      lod('digital_object', '디지털 객체', 'Digital object'),
      lod('duplicating_copy', '복제용 사본', 'Duplicating copy'),
      lod('research_copy', '연구용 사본', 'Research copy'),
      lod('production_proof', '제작 프루프', 'Production proof'),
      lod('viewing_copy', '열람용 사본', 'Viewing copy'),
      lod('artist_supplied_package', '작가 제공 패키지', 'Artist supplied package'),
      lod('production_materials', '제작 자료', 'Production materials'),
      lod('auxiliary_elements', '보조 요소', 'Auxiliary elements'),
      // 일반 기록물 계층 (ISAD(G))
      lod('fonds', '퐁', 'Fonds'),
      lod('series', '시리즈', 'Series'),
      lod('file', '철', 'File'),
      lod('item', '건', 'Item'),
    ],
  },
  { machine: 'classification', name: { ko: '분류', en: 'Classifications' }, terms: [] },
  { machine: 'department', name: { ko: '부서', en: 'Departments' }, terms: [] },
  { machine: 'component_type', name: { ko: '컴포넌트 유형', en: 'Component types' }, terms: [] },
  {
    machine: 'relation_type',
    name: { ko: '관계 유형', en: 'Relation types' },
    terms: [
      { machine: 'is_part_of', name: { ko: '~의 일부임', en: 'isPartOf' }, reciprocal: 'has_part' },
      { machine: 'has_part', name: { ko: '~을 포함함', en: 'hasPart' }, reciprocal: 'is_part_of' },
      { machine: 'is_format_of', name: { ko: '~의 포맷임', en: 'isFormatOf' }, reciprocal: 'has_format' },
      { machine: 'has_format', name: { ko: '~의 포맷을 가짐', en: 'hasFormat' }, reciprocal: 'is_format_of' },
      { machine: 'is_version_of', name: { ko: '~의 버전임', en: 'isVersionOf' }, reciprocal: 'has_version' },
      { machine: 'has_version', name: { ko: '~의 버전을 가짐', en: 'hasVersion' }, reciprocal: 'is_version_of' },
      { machine: 'references', name: { ko: '~을 참조함', en: 'references' }, reciprocal: 'is_referenced_by' },
      { machine: 'is_referenced_by', name: { ko: '~에 의해 참조됨', en: 'isReferencedBy' }, reciprocal: 'references' },
      { machine: 'requires', name: { ko: '~을 필요로 함', en: 'requires' }, reciprocal: 'is_required_by' },
      { machine: 'is_required_by', name: { ko: '~에 의해 요구됨', en: 'isRequiredBy' }, reciprocal: 'requires' },
      { machine: 'replaces', name: { ko: '~을 대체함', en: 'replaces' }, reciprocal: 'is_replaced_by' },
      { machine: 'is_replaced_by', name: { ko: '~에 의해 대체됨', en: 'isReplacedBy' }, reciprocal: 'replaces' },
    ],
  },
  {
    machine: 'aip_type',
    name: { ko: 'AIP 종류', en: 'AIP types' },
    terms: [
      { machine: 'artwork_component', name: { ko: '작품 컴포넌트', en: 'Artwork component' } },
      { machine: 'artwork_material', name: { ko: '작품 자료', en: 'Artwork material' } },
      { machine: 'supporting_documentation', name: { ko: '지원 문서', en: 'Supporting documentation' } },
      { machine: 'supporting_technology', name: { ko: '지원 기술', en: 'Supporting technology' } },
    ],
  },
  {
    machine: 'note_type',
    name: { ko: '노트 유형', en: 'Note types' },
    terms: [
      { machine: 'install_comments', name: { ko: '설치 코멘트', en: 'Install comments' } },
      { machine: 'prep_comments', name: { ko: '준비 코멘트', en: 'Prep comments' } },
      { machine: 'storage_comments', name: { ko: '보관 코멘트', en: 'Storage comments' } },
      { machine: 'general', name: { ko: '일반', en: 'General' } },
    ],
  },
  {
    machine: 'media_type',
    name: { ko: '미디어 유형', en: 'Media types' },
    terms: [
      { machine: 'video', name: { ko: '비디오', en: 'Video' } },
      { machine: 'audio', name: { ko: '오디오', en: 'Audio' } },
      { machine: 'image', name: { ko: '이미지', en: 'Image' } },
      { machine: 'text', name: { ko: '텍스트', en: 'Text' } },
      { machine: 'software', name: { ko: '소프트웨어', en: 'Software' } },
      { machine: 'other', name: { ko: '기타', en: 'Other' } },
    ],
  },
  {
    machine: 'event_type',
    name: { ko: '이벤트 유형', en: 'Event types' },
    terms: [
      { machine: 'creation', name: { ko: '제작', en: 'Creation' } },
      { machine: 'collection', name: { ko: '수집', en: 'Collection' } },
    ],
  },
  {
    machine: 'rights_basis',
    name: { ko: '권리 근거', en: 'Rights basis' },
    terms: [
      { machine: 'copyright', name: { ko: '저작권', en: 'Copyright' } },
      { machine: 'license', name: { ko: '라이선스', en: 'License' } },
      { machine: 'statute', name: { ko: '법령', en: 'Statute' } },
      { machine: 'policy', name: { ko: '정책', en: 'Policy' } },
      { machine: 'donor', name: { ko: '기증 계약', en: 'Donor agreement' } },
    ],
  },
  {
    machine: 'rights_act',
    name: { ko: '권리 행위', en: 'Rights acts' },
    terms: [
      { machine: 'display', name: { ko: '전시', en: 'Display' } },
      { machine: 'replicate', name: { ko: '복제', en: 'Replicate' } },
      { machine: 'migrate', name: { ko: '마이그레이션', en: 'Migrate' } },
      { machine: 'disseminate', name: { ko: '제공', en: 'Disseminate' } },
      { machine: 'delete', name: { ko: '삭제', en: 'Delete' } },
    ],
  },
  {
    machine: 'copyright_status',
    name: { ko: '저작권 상태', en: 'Copyright status' },
    terms: [
      { machine: 'copyrighted', name: { ko: '저작권 보호', en: 'Copyrighted' } },
      { machine: 'public_domain', name: { ko: '퍼블릭 도메인', en: 'Public domain' } },
      { machine: 'unknown', name: { ko: '미상', en: 'Unknown' } },
    ],
  },
];
```

`binder-next/packages/db/src/seed/seed-taxonomies.ts`:
```ts
import { and, eq } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { taxonomies, terms } from '../schema/index.js';
import { TAXONOMY_SEEDS } from './taxonomy-data.js';

export async function seedTaxonomies(db: PostgresJsDatabase): Promise<void> {
  for (const tax of TAXONOMY_SEEDS) {
    const [taxRow] = await db
      .insert(taxonomies)
      .values({ machineName: tax.machine, name: tax.name })
      .onConflictDoUpdate({ target: taxonomies.machineName, set: { name: tax.name } })
      .returning();

    for (const [i, term] of tax.terms.entries()) {
      await db
        .insert(terms)
        .values({ taxonomyId: taxRow.id, machineName: term.machine, name: term.name, position: i })
        .onConflictDoUpdate({
          target: [terms.taxonomyId, terms.machineName],
          set: { name: term.name, position: i },
        });
    }

    // 2차 패스: reciprocal 연결 (양쪽 텀이 모두 존재한 뒤)
    for (const term of tax.terms) {
      if (!term.reciprocal) continue;
      const self = await findTermId(db, tax.machine, term.machine);
      const other = await findTermId(db, tax.machine, term.reciprocal);
      await db.update(terms).set({ reciprocalTermId: other }).where(eq(terms.id, self));
    }
  }
}

export async function findTermId(
  db: PostgresJsDatabase,
  taxonomyMachineName: string,
  termMachineName: string,
): Promise<number> {
  const rows = await db
    .select({ id: terms.id })
    .from(terms)
    .innerJoin(taxonomies, eq(terms.taxonomyId, taxonomies.id))
    .where(and(eq(taxonomies.machineName, taxonomyMachineName), eq(terms.machineName, termMachineName)));
  if (rows.length === 0) {
    throw new Error(`term not found: ${taxonomyMachineName}/${termMachineName}`);
  }
  return rows[0].id;
}
```

`binder-next/packages/db/scripts/seed.ts`:
```ts
import { createDb } from '../src/client.js';
import { seedTaxonomies } from '../src/seed/seed-taxonomies.js';

const url = process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder';
const { db, sql } = createDb(url);
await seedTaxonomies(db);
await sql.end();
console.log('seeded taxonomies');
```

`packages/db/package.json`의 scripts에 추가:
```json
"db:seed": "tsx scripts/seed.ts"
```

- [ ] **Step 5: 테스트 그린 확인**

Run: `pnpm --filter @binder/db test`
Expected: PASS — taxonomies 5개 테스트 + connection 테스트 모두 그린.

- [ ] **Step 6: 개발 DB에도 적용 + 커밋**

```bash
pnpm --filter @binder/db db:migrate && pnpm --filter @binder/db db:seed
git add -A && git commit -m "feat(db): taxonomies/terms schema with idempotent bilingual seeds"
```

---

### Task 5: users/roles 스키마 + 패스워드 헬퍼 — TDD

**Files:**
- Create: `binder-next/packages/db/src/schema/users.ts`, `binder-next/packages/db/src/seed/seed-roles.ts`
- Create: `binder-next/packages/core/src/password.ts`
- Modify: `binder-next/packages/db/src/schema/index.ts`, `binder-next/packages/db/scripts/seed.ts`, `binder-next/packages/core/src/index.ts`, `binder-next/packages/core/package.json`(의존성 추가)
- Test: `binder-next/packages/core/test/password.test.ts`, `binder-next/packages/db/test/users.test.ts`

**Interfaces:**
- Consumes: Task 2 하네스, Task 4 시드 패턴.
- Produces:
  - Drizzle 테이블 `users`, `roles`, `userRoles`
  - `hashPassword(plain: string): Promise<string>`, `verifyPassword(hash: string, plain: string): Promise<boolean>` (`@binder/core`)
  - `seedRoles(db): Promise<void>` — admin/editor/viewer (permissions: string[])
  - 권한 어휘 초기값: `'*'`(admin), `'records:read' | 'records:write' | 'aips:read' | 'aips:download'`(editor), `'records:read' | 'aips:read'`(viewer)

- [ ] **Step 1: 실패하는 테스트 작성 (core: password)**

`binder-next/packages/core/test/password.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { hashPassword, verifyPassword } from '../src/password.js';

describe('password hashing', () => {
  it('hashes and verifies a password', async () => {
    const hash = await hashPassword('correct horse battery staple');
    expect(hash).toMatch(/^\$argon2/);
    expect(await verifyPassword(hash, 'correct horse battery staple')).toBe(true);
  });

  it('rejects a wrong password', async () => {
    const hash = await hashPassword('secret-1');
    expect(await verifyPassword(hash, 'secret-2')).toBe(false);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/core test`
Expected: FAIL — `Cannot find module '../src/password.js'`.

- [ ] **Step 3: 구현 (core)**

```bash
pnpm --filter @binder/core add @node-rs/argon2@^2.0.0
```

`binder-next/packages/core/src/password.ts`:
```ts
import { hash, verify } from '@node-rs/argon2';

export function hashPassword(plain: string): Promise<string> {
  return hash(plain, { memoryCost: 19456, timeCost: 2, parallelism: 1 });
}

export async function verifyPassword(hashed: string, plain: string): Promise<boolean> {
  try {
    return await verify(hashed, plain);
  } catch {
    return false;
  }
}
```

`packages/core/src/index.ts`에 추가:
```ts
export { hashPassword, verifyPassword } from './password.js';
```

Run: `pnpm --filter @binder/core test` → PASS.

- [ ] **Step 4: 실패하는 테스트 작성 (db: users/roles)**

`binder-next/packages/db/test/users.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { roles, userRoles, users } from '../src/schema/index.js';
import { seedRoles } from '../src/seed/seed-roles.js';

const { db, sql } = testDb();

beforeAll(async () => {
  await truncate(sql, 'user_roles', 'users', 'roles');
  await seedRoles(db);
});
afterAll(() => sql.end());

describe('users and roles', () => {
  it('seeds admin/editor/viewer roles idempotently', async () => {
    await seedRoles(db);
    const rows = await db.select().from(roles);
    expect(rows.map((r) => r.name).sort()).toEqual(['admin', 'editor', 'viewer']);
    expect(rows.find((r) => r.name === 'admin')?.permissions).toEqual(['*']);
  });

  it('creates a user and assigns a role', async () => {
    const [user] = await db
      .insert(users)
      .values({ username: 'demo', email: 'demo@example.com', passwordHash: 'x' })
      .returning();
    const [admin] = await db.select().from(roles).where(eq(roles.name, 'admin'));
    await db.insert(userRoles).values({ userId: user.id, roleId: admin.id });
    const assigned = await db.select().from(userRoles).where(eq(userRoles.userId, user.id));
    expect(assigned).toHaveLength(1);
  });

  it('rejects duplicate emails', async () => {
    await expect(
      db.insert(users).values({ username: 'demo2', email: 'demo@example.com', passwordHash: 'x' }),
    ).rejects.toThrow();
  });
});
```

- [ ] **Step 5: 실패 확인**

Run: `pnpm --filter @binder/db test`
Expected: FAIL — schema에 `users` 없음.

- [ ] **Step 6: 스키마·시드 구현 + 마이그레이션**

`binder-next/packages/db/src/schema/users.ts`:
```ts
import {
  boolean, integer, jsonb, pgTable, primaryKey, serial, text, timestamp,
} from 'drizzle-orm/pg-core';

export const users = pgTable('users', {
  id: serial('id').primaryKey(),
  username: text('username').notNull().unique(),
  email: text('email').notNull().unique(),
  passwordHash: text('password_hash').notNull(),
  active: boolean('active').notNull().default(true),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
});

export const roles = pgTable('roles', {
  id: serial('id').primaryKey(),
  name: text('name').notNull().unique(),
  permissions: jsonb('permissions').$type<string[]>().notNull().default([]),
});

export const userRoles = pgTable('user_roles', {
  userId: integer('user_id').notNull().references(() => users.id, { onDelete: 'cascade' }),
  roleId: integer('role_id').notNull().references(() => roles.id, { onDelete: 'cascade' }),
}, (t) => [primaryKey({ columns: [t.userId, t.roleId] })]);
```

`schema/index.ts`에 추가:
```ts
export { taxonomies, terms } from './taxonomies.js';
export { users, roles, userRoles } from './users.js';
```

`binder-next/packages/db/src/seed/seed-roles.ts`:
```ts
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { roles } from '../schema/index.js';

const ROLE_SEEDS: Array<{ name: string; permissions: string[] }> = [
  { name: 'admin', permissions: ['*'] },
  { name: 'editor', permissions: ['records:read', 'records:write', 'aips:read', 'aips:download'] },
  { name: 'viewer', permissions: ['records:read', 'aips:read'] },
];

export async function seedRoles(db: PostgresJsDatabase): Promise<void> {
  for (const role of ROLE_SEEDS) {
    await db
      .insert(roles)
      .values(role)
      .onConflictDoUpdate({ target: roles.name, set: { permissions: role.permissions } });
  }
}
```

`scripts/seed.ts` 전체 교체:
```ts
import { createDb } from '../src/client.js';
import { seedTaxonomies } from '../src/seed/seed-taxonomies.js';
import { seedRoles } from '../src/seed/seed-roles.js';

const url = process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder';
const { db, sql } = createDb(url);
await seedTaxonomies(db);
await seedRoles(db);
await sql.end();
console.log('seeded taxonomies + roles');
```

Run: `pnpm --filter @binder/db db:generate`
Expected: `migrations/0001_*.sql` 생성.

- [ ] **Step 7: 테스트 그린 확인 + 커밋**

Run: `pnpm --filter @binder/db test` → PASS (users 3 + taxonomies 5 + connection 1).

```bash
pnpm --filter @binder/db db:migrate && pnpm --filter @binder/db db:seed
git add -A && git commit -m "feat: users/roles schema with RBAC seeds and argon2 password helpers"
```

---

### Task 6: 감사 diff 헬퍼 (`@binder/core`) — TDD

**Files:**
- Create: `binder-next/packages/core/src/audit.ts`
- Modify: `binder-next/packages/core/src/index.ts`
- Test: `binder-next/packages/core/test/audit.test.ts`

**Interfaces:**
- Consumes: 없음 (순수 모듈).
- Produces:
  - `diffFields(before, after, opts?): AuditChanges` — `{ [field]: [old, new] }`; 변경 없으면 빈 객체
  - `type AuditChanges = Record<string, [unknown, unknown]>`
  - `opts.jsonbTopLevel: string[]` — 해당 필드는 톱레벨 키 단위로 diff (`field.key` 표기). 데이터 모델 §audit_log 규칙("descriptive JSONB는 톱레벨 키 단위 diff — 전체 블롭 저장 금지")의 구현. Plan 02+의 저장 파이프라인이 이 함수로 audit_log.changes를 만든다.

- [ ] **Step 1: 실패하는 테스트 작성**

`binder-next/packages/core/test/audit.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { diffFields } from '../src/audit.js';

describe('diffFields', () => {
  it('captures scalar changes as [old, new]', () => {
    expect(diffFields({ title: 'a', size: 1 }, { title: 'b', size: 1 })).toEqual({ title: ['a', 'b'] });
  });

  it('returns an empty object when nothing changed', () => {
    expect(diffFields({ a: 1 }, { a: 1 })).toEqual({});
  });

  it('captures added and removed fields', () => {
    expect(diffFields({ a: 1 }, { a: 1, b: 2 })).toEqual({ b: [undefined, 2] });
    expect(diffFields({ a: 1, b: 2 }, { a: 1 })).toEqual({ b: [2, undefined] });
  });

  it('diffs jsonb fields by top-level key, not as whole blobs', () => {
    const before = { descriptive: { medium: 'video', duration: '13min' } };
    const after = { descriptive: { medium: 'video', duration: '14min' } };
    expect(diffFields(before, after, { jsonbTopLevel: ['descriptive'] })).toEqual({
      'descriptive.duration': ['13min', '14min'],
    });
  });

  it('compares non-jsonbTopLevel objects by deep equality', () => {
    expect(diffFields({ title: { ko: '가' } }, { title: { ko: '가' } })).toEqual({});
    expect(diffFields({ title: { ko: '가' } }, { title: { ko: '나' } })).toEqual({
      title: [{ ko: '가' }, { ko: '나' }],
    });
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/core test`
Expected: FAIL — `Cannot find module '../src/audit.js'`.

- [ ] **Step 3: 최소 구현**

`binder-next/packages/core/src/audit.ts`:
```ts
export type AuditChanges = Record<string, [unknown, unknown]>;

function deepEqual(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

export function diffFields(
  before: Record<string, unknown>,
  after: Record<string, unknown>,
  opts: { jsonbTopLevel?: string[] } = {},
): AuditChanges {
  const jsonbFields = new Set(opts.jsonbTopLevel ?? []);
  const changes: AuditChanges = {};
  const fields = new Set([...Object.keys(before), ...Object.keys(after)]);

  for (const field of fields) {
    const oldValue = before[field];
    const newValue = after[field];
    if (deepEqual(oldValue, newValue)) continue;

    if (jsonbFields.has(field) && isPlainObject(oldValue) && isPlainObject(newValue)) {
      const keys = new Set([...Object.keys(oldValue), ...Object.keys(newValue)]);
      for (const key of keys) {
        if (!deepEqual(oldValue[key], newValue[key])) {
          changes[`${field}.${key}`] = [oldValue[key], newValue[key]];
        }
      }
    } else {
      changes[field] = [oldValue, newValue];
    }
  }
  return changes;
}

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}
```

`packages/core/src/index.ts`에 추가:
```ts
export { diffFields } from './audit.js';
export type { AuditChanges } from './audit.js';
```

- [ ] **Step 4: 테스트 그린 확인**

Run: `pnpm --filter @binder/core test`
Expected: PASS (i18n 6 + password 2 + audit 5 + 스모크 1).

- [ ] **Step 5: 커밋**

```bash
git add -A && git commit -m "feat(core): field-level audit diff with jsonb top-key granularity"
```

---

### Task 7: README + 전체 파이프라인 검증

**Files:**
- Create: `binder-next/README.md`
- Test: 전체 `pnpm test`

**Interfaces:**
- Consumes: Task 1–6 전부.
- Produces: 신규 개발자 온보딩 문서(기동·마이그레이션·시드·테스트 명령), 그린 파이프라인.

- [ ] **Step 1: README 작성**

`binder-next/README.md`:
````markdown
# binder-next

Binder(MoMA DRMC)의 철학을 계승한 디지털 보존 관리 시스템 — Phase 2 현대화 구현.
설계서: [binder 저장소의 specs](../binder/docs/superpowers/specs/2026-07-04-binder-phase2-design.md).

## 요구 사항
- Node >= 22, pnpm 9, Docker

## 시작하기
```bash
pnpm install
pnpm db:up                          # PostgreSQL 16 (:15432) + Redis 7 (:16379)
pnpm --filter @binder/db db:migrate # 마이그레이션 적용
pnpm --filter @binder/db db:seed    # 택소노미·역할 시드 (멱등)
pnpm test                           # 전체 테스트 (binder_test DB 자동 재생성)
```

## 구조
- `packages/core` — 순수 도메인 헬퍼 (i18n, password, audit diff)
- `packages/db` — Drizzle 스키마·마이그레이션·시드
- `apps/*` — (Plan 04+) web, worker
````

- [ ] **Step 2: 클린 체크아웃 시나리오 검증**

Run:
```bash
cd /Users/daejinan/claude/moma-binder/binder-next
pnpm db:down && pnpm db:up
sleep 5
pnpm --filter @binder/db db:migrate && pnpm --filter @binder/db db:seed && pnpm --filter @binder/db db:seed
pnpm test
```
Expected: 시드 2회 실행에도 오류 없음(멱등), 전체 테스트 PASS.

- [ ] **Step 3: 커밋**

```bash
git add -A && git commit -m "docs: onboarding README with full pipeline verification"
```

---

## Self-Review

1. **Spec coverage (이 계획의 범위 내)**: 모노레포 스캐폴드(§2)=T1·T7, DB 인프라·docker(§8)=T2, i18n 횡단 규칙(§3)=T3, 택소노미+시드(§3 terms)=T4, users/roles RBAC+권한 어휘 확정(§7)=T5, audit diff 규칙(§3 audit_log)=T6. nodes 스파인·보존 테이블·UI·통합은 의도적으로 Plan 02+ — 계획 서두의 시리즈 표에 명시.
2. **Placeholder scan**: 전 스텝 실코드 포함 확인. "TBD" 없음. 권한 어휘는 스펙이 계획으로 미룬 것을 T5에서 확정값으로 명시.
3. **Type consistency**: `I18nText`는 db(types.ts)와 core(i18n.ts)에 구조 동일하게 정의(의도적 1줄 중복 — 패키지 순환 의존 회피, T2/T3 주석에 명시). `findTermId` 시그니처는 T4 Produces와 구현 일치. `truncate(sql, ...tables)`는 T2 정의를 T4/T5가 동일 시그니처로 사용.
