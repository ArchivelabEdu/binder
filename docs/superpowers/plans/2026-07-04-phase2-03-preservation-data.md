# Binder Phase 2 — Plan 03: Preservation Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 보존 데이터 층 — 저장 백엔드/AIP/사본, 파일 특성화(facet 평탄화), fixity 리포트·복구, 인제스트 잡, 교차 엔티티 검색 인덱스, 노드 롤업 유지 — 를 완성한다. Plan 04(UI)와 05(통합)가 소비하는 마지막 스키마 계층.

**Architecture:** Plan 02 관례 그대로: 스키마는 `@binder/db/src/schema`(cross-import 확장자 없음), 쿼리는 `src/queries`(.js), 다중 쓰기는 `db.transaction` + `DbExecutor`. 검색은 `search_docs`(tsvector + pg_trgm) 뒤에 숨긴다.

**Tech Stack:** Plan 02 스택 그대로 (신규 런타임 의존성 없음; devDep `@types/node`만 추가).

**설계 근거:** `binder/docs-spike/phase2-data-model-review.md` §2D(보존)·§2E(ingest_jobs)·§2F(search_docs)·§3.3(쿼리 패턴). Plan 02 레저의 백로그 중 이 계획이 흡수: db tsc 자세(@types/node + moduleResolution), `nodes.profile_version` 복합 FK.

## Global Constraints

- Repo: `/Users/daejinan/claude/moma-binder/binder-next` (main, HEAD c82cac7, 63/63 tests, 마이그레이션 0000–0009).
- Plan 02와 동일: ESM(.js — 단 `src/schema/*.ts` 상호 임포트만 확장자 없음), TS strict, snake_case, timestamptz, soft-delete 패턴, i18n JSONB, 멱등 시드, conventional commits, 적용된 마이그레이션 수정 금지.
- 다중 쓰기 시퀀스는 반드시 `db.transaction` — 내부 헬퍼는 `DbExecutor`(packages/db/src/types.ts) 사용, 중첩 트랜잭션 금지.
- 감사: aips 생성/소프트삭제는 `writeAudit` 필수(테이블명 'aips'). 파일·사본은 AIP 단위 감사에 포함(개별 행 감사 없음 — 인제스트 원자성).
- 새 enum·테이블 이름은 아래 태스크 코드가 정의하는 것이 정본.
- 마이그레이션 번호는 **db:generate가 산출하는 실번호를 따른다**(예상: 0010부터; 어긋나도 내용만 맞으면 됨 — Plan 02에서 두 번 시프트된 전례).

---

### Task 1: 백로그 흡수 — db 타입체크 자세 + profile_version 복합 FK

**Files:**
- Modify: `packages/db/package.json`(@types/node devDep + typecheck 스크립트), `packages/db/tsconfig.json`, `packages/core/package.json`(typecheck 스크립트), `binder-next/package.json`(root typecheck), `binder-next/turbo.json`
- Modify: `packages/db/src/schema/nodes.ts`(복합 FK)
- Test: 기존 스위트 + `pnpm typecheck`

**Interfaces:**
- Consumes: Plan 02 전체.
- Produces: `pnpm typecheck`(root, turbo 경유)가 core+db 모두 클린; `nodes(profile_id, profile_version) → collection_profile_versions(profile_id, version)` 복합 FK(마이그레이션).

- [ ] **Step 1: db 타입체크 클린화**

`packages/db/tsconfig.json` 전체 교체 — 스키마 확장자 없는 임포트(drizzle-kit 제약)와 NodeNext가 충돌하므로 db 패키지만 Bundler 해석으로 전환(런타임은 tsx/vitest가 번들 — 영향 없음):
```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "types": ["node"]
  },
  "include": ["src", "test", "scripts", "drizzle.config.ts"]
}
```

`packages/db/package.json`: devDependencies에 `"@types/node": "^22.0.0"`, scripts에 `"typecheck": "tsc --noEmit"`.
`packages/core/package.json`: scripts에 `"typecheck": "tsc --noEmit"` (+ devDependencies에 `"typescript": "^5.6.0"`가 없다면 추가; db도 동일 확인).
`binder-next/package.json` scripts에 `"typecheck": "turbo run typecheck"`; `turbo.json` tasks에 `"typecheck": { "dependsOn": ["^build"] }` 추가.

Run: `pnpm install && pnpm typecheck`
Expected: core·db 모두 에러 0. (남는 에러는 유형별로 최소 수정 — 수정 내역 보고서에 기록. 단 `verbatimModuleSyntax`와 Bundler 조합 문제 등 tsconfig 수준에서 해결 가능한 것은 tsconfig에서.)

- [ ] **Step 2: profile_version 복합 FK**

`packages/db/src/schema/nodes.ts`의 테이블 3번째 인자 배열에 추가(파일 상단에 `foreignKey` import 추가):
```ts
  foreignKey({
    name: 'nodes_profile_version_fk',
    columns: [t.profileId, t.profileVersion],
    foreignColumns: [collectionProfileVersions.profileId, collectionProfileVersions.version],
  }),
```
(파일 상단 import에 `collectionProfileVersions`를 `./profiles`에서 추가 — 확장자 없음. MATCH SIMPLE이므로 둘 중 하나가 NULL인 기존 행·컴포넌트 행은 영향 없음.)

Run: `pnpm --filter @binder/db db:generate` → 새 마이그레이션(복합 FK) 생성 확인.

**주의**: 기존 데모 시드가 `profileVersion: 1`을 쓰는 노드는 profiles에 버전 1 행이 실제로 존재해야 FK가 성립 — `seedDemo`의 `ensureProfile`은 버전 1을 만들므로 충족. 그러나 **테스트 파일들 중 profiles에 버전 행 없이 `profileVersion: 1`을 넘기는 곳이 있으면 FK 위반으로 실패한다** — 실패하는 테스트는 `createProfileVersion(db, profileId, {...}, null)` 선행 호출을 추가해 고친다(동작 의미는 동일).

- [ ] **Step 3: 전체 그린 + 커밋**

Run: `pnpm --filter @binder/db db:migrate && pnpm test && pnpm typecheck`
Expected: 전부 통과.

```bash
git add -A && git commit -m "chore: clean db typecheck posture and add composite profile-version FK"
```

---

### Task 2: storage_backends + aips + aip_copies 스키마 — TDD

**Files:**
- Create: `packages/db/src/schema/preservation.ts`
- Modify: `packages/db/src/schema/index.ts`
- Test: `packages/db/test/preservation-schema.test.ts`

**Interfaces:**
- Consumes: nodes, terms, users, (ingest_jobs FK는 Task 4에서 추가 — 여기선 컬럼만 nullable text… 아님. **결정: ingest_job_id는 Task 4에서 uuid FK로 추가** — 이 태스크의 aips에는 넣지 않는다).
- Produces:
  - `storageKindEnum('am_ss','local_fs','s3')`, `aipOriginEnum('archivematica','direct')`, `copyStatusEnum('ok','recovering','lost')`
  - 테이블 `storageBackends`(kind, name, config JSONB, enabled bool), `aips`, `aipCopies`(UNIQUE(aip_id, backend_id))
  - aips 컬럼: id serial PK, uuid uuid unique defaultRandom **아님** — 외부(SS/METS)에서 오는 값이므로 `uuid('uuid').notNull().unique()` (기본값 없음), type_term_id →terms SET NULL, node_id →nodes RESTRICT NOT NULL(직접 부착점), root_node_id →nodes RESTRICT NOT NULL(롤업 루트), filename text, size_bytes bigint(mode number) notNull default 0, file_count integer notNull default 0, origin aipOriginEnum notNull, pipeline_uuid uuid NULL, mets_location text, mets_sha256 text, ingested_by →users, created_at timestamptz notNull defaultNow, deleted_at/deleted_by, 인덱스 (created_at desc), (root_node_id)

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/preservation-schema.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { aipCopies, aips, collectionProfiles, storageBackends } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';

const { db, sql } = testDb();
let workId: number; let backendId: number;
const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeffff0001';

beforeAll(async () => {
  await truncate(sql, 'aip_copies', 'aips', 'storage_backends', 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  workId = (await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, title: { en: 'AIP host' } })).id;
  const [b] = await db.insert(storageBackends).values({ kind: 'am_ss', name: 'dev storage service', config: { baseUrl: 'http://ss:8000' } }).returning();
  backendId = b.id;
});
afterAll(() => sql.end());

describe('preservation schema', () => {
  it('creates an aip attached to a node with an external uuid', async () => {
    const type = await findTermId(db, 'aip_type', 'artwork_component');
    const [a] = await db.insert(aips).values({
      uuid: UUID, typeTermId: type, nodeId: workId, rootNodeId: workId,
      filename: 'lovers_archival_master', sizeBytes: 5368709120, fileCount: 4, origin: 'archivematica',
    }).returning();
    expect(a.uuid).toBe(UUID);
    expect(a.deletedAt).toBeNull();
  });

  it('rejects duplicate aip uuids', async () => {
    await expect(db.insert(aips).values({
      uuid: UUID, nodeId: workId, rootNodeId: workId, origin: 'direct',
    })).rejects.toThrow();
  });

  it('enforces one copy row per (aip, backend)', async () => {
    const [a] = await db.select().from(aips).where(eq(aips.uuid, UUID));
    await db.insert(aipCopies).values({ aipId: a.id, backendId, locator: UUID, isPrimary: true });
    await expect(
      db.insert(aipCopies).values({ aipId: a.id, backendId, locator: 'other' }),
    ).rejects.toThrow();
  });

  it('restricts deleting a node that still hosts an aip', async () => {
    await expect(sql`DELETE FROM nodes WHERE id = ${workId}`).rejects.toThrow(/violates|foreign key/i);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL (preservation 모듈 없음).

- [ ] **Step 3: 스키마 구현 + 마이그레이션**

`packages/db/src/schema/preservation.ts`:
```ts
import {
  bigint, boolean, integer, jsonb, pgEnum, pgTable, serial, text, timestamp, uniqueIndex, index, uuid,
} from 'drizzle-orm/pg-core';
import { nodes } from './nodes';
import { terms } from './taxonomies';
import { users } from './users';

export const storageKindEnum = pgEnum('storage_kind', ['am_ss', 'local_fs', 's3']);
export const aipOriginEnum = pgEnum('aip_origin', ['archivematica', 'direct']);
export const copyStatusEnum = pgEnum('copy_status', ['ok', 'recovering', 'lost']);

export const storageBackends = pgTable('storage_backends', {
  id: serial('id').primaryKey(),
  kind: storageKindEnum('kind').notNull(),
  name: text('name').notNull(),
  config: jsonb('config').$type<Record<string, unknown>>().notNull().default({}),
  enabled: boolean('enabled').notNull().default(true),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export const aips = pgTable('aips', {
  id: serial('id').primaryKey(),
  uuid: uuid('uuid').notNull().unique(), // 외부(SS/METS) 부여 — 기본값 없음
  typeTermId: integer('type_term_id').references(() => terms.id, { onDelete: 'set null' }),
  nodeId: integer('node_id').notNull().references(() => nodes.id, { onDelete: 'restrict' }),
  rootNodeId: integer('root_node_id').notNull().references(() => nodes.id, { onDelete: 'restrict' }),
  filename: text('filename'),
  sizeBytes: bigint('size_bytes', { mode: 'number' }).notNull().default(0),
  fileCount: integer('file_count').notNull().default(0),
  origin: aipOriginEnum('origin').notNull(),
  pipelineUuid: uuid('pipeline_uuid'),
  metsLocation: text('mets_location'),
  metsSha256: text('mets_sha256'),
  ingestedBy: integer('ingested_by').references(() => users.id),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  deletedAt: timestamp('deleted_at', { withTimezone: true }),
  deletedBy: integer('deleted_by').references(() => users.id),
}, (t) => [
  index('aips_created_at_ix').on(t.createdAt.desc()),
  index('aips_root_node_ix').on(t.rootNodeId),
]);

export const aipCopies = pgTable('aip_copies', {
  id: serial('id').primaryKey(),
  aipId: integer('aip_id').notNull().references(() => aips.id, { onDelete: 'cascade' }),
  backendId: integer('backend_id').notNull().references(() => storageBackends.id, { onDelete: 'restrict' }),
  locator: text('locator').notNull(),
  isPrimary: boolean('is_primary').notNull().default(false),
  status: copyStatusEnum('status').notNull().default('ok'),
  lastVerifiedAt: timestamp('last_verified_at', { withTimezone: true }),
}, (t) => [uniqueIndex('aip_copies_aip_backend_uq').on(t.aipId, t.backendId)]);
```

`schema/index.ts`에 추가:
```ts
export { storageKindEnum, aipOriginEnum, copyStatusEnum, storageBackends, aips, aipCopies } from './preservation.js';
```

Run: `pnpm --filter @binder/db db:generate` → 새 마이그레이션 확인(enum 3종 + 테이블 3종 + 인덱스).

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): storage backends, aips and per-backend copies"
```

---

### Task 3: files 스키마 (특성화 + facet 평탄화) — TDD

**Files:**
- Create: `packages/db/src/schema/files.ts`
- Modify: `packages/db/src/schema/index.ts`
- Test: `packages/db/test/files-schema.test.ts`

**Interfaces:**
- Consumes: aips, nodes, terms(media_type).
- Produces: 테이블 `files` — id, aip_id →aips CASCADE NOT NULL, root_node_id →nodes RESTRICT NOT NULL(denorm — facet의 COUNT(DISTINCT root_node_id)), original_relative_path text NOT NULL, filename text NOT NULL, puid text, mime_type text, media_type_term_id →terms SET NULL, size_bytes bigint(mode number) default 0, checksum text, checksum_type text, last_modified timestamptz, ingested_at timestamptz defaultNow, **facet 평탄화**: format_name text, video_codecs text[] , audio_codecs text[], resolution integer, bit_depth integer, sample_rate integer, chroma_subsampling text, color_space text, characterization JSONB, derivatives JSONB, deleted_at/deleted_by. 인덱스: (aip_id), (root_node_id), (format_name), GIN(video_codecs), GIN(audio_codecs).

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/files-schema.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { sql as dsql } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { aips, collectionProfiles, files } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';

const { db, sql } = testDb();
let aipId: number; let workId: number;

beforeAll(async () => {
  await truncate(sql, 'files', 'aip_copies', 'aips', 'storage_backends', 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  workId = (await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, title: { en: 'File host' } })).id;
  const [a] = await db.insert(aips).values({
    uuid: 'bbbbbbbb-0000-0000-0000-000000000001', nodeId: workId, rootNodeId: workId, origin: 'archivematica',
  }).returning();
  aipId = a.id;
});
afterAll(() => sql.end());

describe('files', () => {
  it('stores characterization with flattened facet columns', async () => {
    const media = await findTermId(db, 'media_type', 'video');
    const [f] = await db.insert(files).values({
      aipId, rootNodeId: workId,
      originalRelativePath: 'objects/lovers_ch1.mov', filename: 'lovers_ch1.mov',
      puid: 'fmt/199', mimeType: 'video/quicktime', mediaTypeTermId: media,
      sizeBytes: 117000000, checksum: 'abc', checksumType: 'sha256',
      formatName: 'QuickTime', videoCodecs: ['mjpg'], audioCodecs: ['pcm'],
      resolution: 480, characterization: { mediainfo: { videoTracks: 1 } },
    }).returning();
    expect(f.videoCodecs).toEqual(['mjpg']);
  });

  it('facet counting groups by flattened columns with array membership', async () => {
    const rows = (await db.execute(dsql`
      SELECT format_name, count(*)::int AS c FROM files
      WHERE deleted_at IS NULL AND 'mjpg' = ANY(video_codecs)
      GROUP BY format_name
    `)) as unknown as Array<{ format_name: string; c: number }>;
    expect(rows).toEqual([{ format_name: 'QuickTime', c: 1 }]);
  });

  it('cascades file deletion when the aip is hard-deleted', async () => {
    await sql`DELETE FROM aips WHERE id = ${aipId}`;
    const rows = await db.select().from(files);
    expect(rows).toHaveLength(0);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 스키마 구현 + 마이그레이션**

`packages/db/src/schema/files.ts`:
```ts
import {
  bigint, integer, jsonb, pgTable, serial, text, timestamp, index,
} from 'drizzle-orm/pg-core';
import { aips } from './preservation';
import { nodes } from './nodes';
import { terms } from './taxonomies';
import { users } from './users';

export const files = pgTable('files', {
  id: serial('id').primaryKey(),
  aipId: integer('aip_id').notNull().references(() => aips.id, { onDelete: 'cascade' }),
  rootNodeId: integer('root_node_id').notNull().references(() => nodes.id, { onDelete: 'restrict' }),
  originalRelativePath: text('original_relative_path').notNull(),
  filename: text('filename').notNull(),
  puid: text('puid'),
  mimeType: text('mime_type'),
  mediaTypeTermId: integer('media_type_term_id').references(() => terms.id, { onDelete: 'set null' }),
  sizeBytes: bigint('size_bytes', { mode: 'number' }).notNull().default(0),
  checksum: text('checksum'),
  checksumType: text('checksum_type'),
  lastModified: timestamp('last_modified', { withTimezone: true }),
  ingestedAt: timestamp('ingested_at', { withTimezone: true }).notNull().defaultNow(),
  formatName: text('format_name'),
  videoCodecs: text('video_codecs').array(),
  audioCodecs: text('audio_codecs').array(),
  resolution: integer('resolution'),
  bitDepth: integer('bit_depth'),
  sampleRate: integer('sample_rate'),
  chromaSubsampling: text('chroma_subsampling'),
  colorSpace: text('color_space'),
  characterization: jsonb('characterization').$type<Record<string, unknown>>(),
  derivatives: jsonb('derivatives').$type<Record<string, string>>(),
  deletedAt: timestamp('deleted_at', { withTimezone: true }),
  deletedBy: integer('deleted_by').references(() => users.id),
}, (t) => [
  index('files_aip_ix').on(t.aipId),
  index('files_root_node_ix').on(t.rootNodeId),
  index('files_format_ix').on(t.formatName),
  index('files_video_codecs_gin').using('gin', t.videoCodecs),
  index('files_audio_codecs_gin').using('gin', t.audioCodecs),
]);
```

`schema/index.ts`에 추가: `export { files } from './files.js';`

Run: `pnpm --filter @binder/db db:generate` → 마이그레이션 확인(GIN 인덱스 2종 포함).

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): files with characterization jsonb and flattened facet columns"
```

---

### Task 4: fixity_reports + fixity_recoveries + ingest_jobs — TDD

**Files:**
- Create: `packages/db/src/schema/fixity.ts`, `packages/db/src/schema/ingest.ts`
- Modify: `packages/db/src/schema/index.ts`
- Test: `packages/db/test/fixity-ingest-schema.test.ts`

**Interfaces:**
- Consumes: aips, users, storageBackends.
- Produces:
  - `fixityReports`: id, aip_id →aips **RESTRICT** NOT NULL, success bool NOT NULL, message text, failures JSONB, session_uuid uuid, started_at/completed_at timestamptz, 인덱스 (aip_id, completed_at desc), (session_uuid, started_at)
  - `fixityRecoveries`: id, aip_id →aips RESTRICT NOT NULL, user_id →users **RESTRICT NOT NULL**, backend_id →storageBackends RESTRICT NOT NULL, external_event_ref text, started_at notNull defaultNow, finished_at, success bool
  - `ingestAdapterEnum('am_dip','direct_upload')`, `ingestStatusEnum('received','validating','parsing','writing','indexed','failed')`
  - `ingestJobs`: id uuid PK defaultRandom, adapter enum NOT NULL, idempotency_key text NOT NULL UNIQUE, status enum NOT NULL default 'received', attempt integer NOT NULL default 1, error JSONB, package_ref text, created_by →users, started_at defaultNow, finished_at
  - aips에 `ingest_job_id uuid → ingestJobs.id SET NULL` 컬럼 추가(이 태스크에서)

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/fixity-ingest-schema.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { aips, collectionProfiles, fixityReports, ingestJobs } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';

const { db, sql } = testDb();
let aipId: number;

beforeAll(async () => {
  await truncate(sql, 'fixity_recoveries', 'fixity_reports', 'ingest_jobs', 'files', 'aip_copies', 'aips', 'storage_backends', 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  const w = await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, title: { en: 'Fixity host' } });
  const [a] = await db.insert(aips).values({
    uuid: 'cccccccc-0000-0000-0000-000000000001', nodeId: w.id, rootNodeId: w.id, origin: 'archivematica',
  }).returning();
  aipId = a.id;
});
afterAll(() => sql.end());

describe('fixity + ingest schema', () => {
  it('stores fixity reports and blocks hard-deleting the aip (RESTRICT)', async () => {
    await db.insert(fixityReports).values({
      aipId, success: false, message: 'Payload-Oxum mismatch',
      failures: { files: { changed: ['data/README.txt'] } },
      sessionUuid: 'dddddddd-0000-0000-0000-000000000001',
      startedAt: new Date(), completedAt: new Date(),
    });
    await expect(sql`DELETE FROM aips WHERE id = ${aipId}`).rejects.toThrow(/violates|foreign key/i);
  });

  it('enforces idempotency_key uniqueness on ingest jobs', async () => {
    await db.insert(ingestJobs).values({ adapter: 'am_dip', idempotencyKey: 'aip:cccccccc' });
    await expect(
      db.insert(ingestJobs).values({ adapter: 'am_dip', idempotencyKey: 'aip:cccccccc' }),
    ).rejects.toThrow();
  });

  it('links aips to their ingest job', async () => {
    const [job] = await db.select().from(ingestJobs);
    await db.update(aips).set({ ingestJobId: job.id });
    const [a] = await db.select().from(aips);
    expect(a.ingestJobId).toBe(job.id);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현 + 마이그레이션**

`packages/db/src/schema/fixity.ts`:
```ts
import { boolean, integer, jsonb, pgTable, serial, text, timestamp, index, uuid } from 'drizzle-orm/pg-core';
import { aips, storageBackends } from './preservation';
import { users } from './users';

export const fixityReports = pgTable('fixity_reports', {
  id: serial('id').primaryKey(),
  aipId: integer('aip_id').notNull().references(() => aips.id, { onDelete: 'restrict' }),
  success: boolean('success').notNull(),
  message: text('message'),
  failures: jsonb('failures').$type<Record<string, unknown>>(),
  sessionUuid: uuid('session_uuid'),
  startedAt: timestamp('started_at', { withTimezone: true }),
  completedAt: timestamp('completed_at', { withTimezone: true }),
}, (t) => [
  index('fixity_reports_aip_completed_ix').on(t.aipId, t.completedAt.desc()),
  index('fixity_reports_session_ix').on(t.sessionUuid, t.startedAt),
]);

export const fixityRecoveries = pgTable('fixity_recoveries', {
  id: serial('id').primaryKey(),
  aipId: integer('aip_id').notNull().references(() => aips.id, { onDelete: 'restrict' }),
  userId: integer('user_id').notNull().references(() => users.id, { onDelete: 'restrict' }),
  backendId: integer('backend_id').notNull().references(() => storageBackends.id, { onDelete: 'restrict' }),
  externalEventRef: text('external_event_ref'),
  startedAt: timestamp('started_at', { withTimezone: true }).notNull().defaultNow(),
  finishedAt: timestamp('finished_at', { withTimezone: true }),
  success: boolean('success'),
});
```

`packages/db/src/schema/ingest.ts`:
```ts
import { integer, jsonb, pgEnum, pgTable, text, timestamp, uuid } from 'drizzle-orm/pg-core';
import { users } from './users';

export const ingestAdapterEnum = pgEnum('ingest_adapter', ['am_dip', 'direct_upload']);
export const ingestStatusEnum = pgEnum('ingest_status', ['received', 'validating', 'parsing', 'writing', 'indexed', 'failed']);

export const ingestJobs = pgTable('ingest_jobs', {
  id: uuid('id').primaryKey().defaultRandom(),
  adapter: ingestAdapterEnum('adapter').notNull(),
  idempotencyKey: text('idempotency_key').notNull().unique(),
  status: ingestStatusEnum('status').notNull().default('received'),
  attempt: integer('attempt').notNull().default(1),
  error: jsonb('error').$type<Record<string, unknown>>(),
  packageRef: text('package_ref'),
  createdBy: integer('created_by').references(() => users.id),
  startedAt: timestamp('started_at', { withTimezone: true }).notNull().defaultNow(),
  finishedAt: timestamp('finished_at', { withTimezone: true }),
});
```

`packages/db/src/schema/preservation.ts`의 aips에 컬럼 추가(파일 상단 import에 `ingestJobs` — `./ingest`):
```ts
  ingestJobId: uuid('ingest_job_id').references(() => ingestJobs.id, { onDelete: 'set null' }),
```

`schema/index.ts`에 추가:
```ts
export { fixityReports, fixityRecoveries } from './fixity.js';
export { ingestAdapterEnum, ingestStatusEnum, ingestJobs } from './ingest.js';
```

Run: `pnpm --filter @binder/db db:generate` → 마이그레이션 확인.

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): fixity reports/recoveries and idempotent ingest jobs"
```

---

### Task 5: createAip 트랜잭션 + 롤업 유지 — TDD

**Files:**
- Create: `packages/db/src/queries/aips.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/aips-queries.test.ts`

**Interfaces:**
- Consumes: Task 2–4 스키마, `DbExecutor`, `writeAudit`.
- Produces:
  - `createAip(db, input: CreateAipInput): Promise<AipRow>` — 단일 트랜잭션: uuid 중복 시 `AipAlreadyExistsError`(name 필드로 식별 가능한 Error 서브클래스), aips insert + copies insert + files insert + **루트 노드 롤업 증분**(total_size_bytes += size, aip_count += 1, file_count += files.length, last_ingested_at = now) + writeAudit(action 'insert', tableName 'aips', origin 기본 'ingest')
  - `CreateAipInput = { uuid, nodeId, rootNodeId, origin, typeTermId?, filename?, sizeBytes?, pipelineUuid?, metsLocation?, metsSha256?, ingestedBy?, ingestJobId?, actorId?, copies?: Array<{ backendId, locator, isPrimary? }>, files?: Array<FileInput> }` — `FileInput`은 files 테이블 insert 값의 부분집합(originalRelativePath, filename 필수)
  - `softDeleteAip(db, id, userId): Promise<void>` — 트랜잭션: soft delete + 롤업 감분 + writeAudit(delete, snapshot)
  - `recomputeRollups(db, rootNodeId): Promise<void>` — 전량 재계산(증분 훼손 시 복구용)

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/aips-queries.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { collectionProfiles, nodes, storageBackends } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';
import { AipAlreadyExistsError, createAip, recomputeRollups, softDeleteAip } from '../src/queries/aips.js';

const { db, sql } = testDb();
let workId: number; let backendId: number;
const U1 = '11111111-0000-0000-0000-000000000001';

beforeAll(async () => {
  await truncate(sql, 'fixity_recoveries', 'fixity_reports', 'ingest_jobs', 'files', 'aip_copies', 'aips', 'storage_backends', 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  workId = (await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, title: { en: 'Rollup host' } })).id;
  const [b] = await db.insert(storageBackends).values({ kind: 'local_fs', name: 'dev fs' }).returning();
  backendId = b.id;
});
afterAll(() => sql.end());

describe('createAip', () => {
  it('creates aip + copies + files and bumps rollups atomically', async () => {
    await createAip(db, {
      uuid: U1, nodeId: workId, rootNodeId: workId, origin: 'archivematica',
      filename: 'demo_aip', sizeBytes: 1000,
      copies: [{ backendId, locator: 'fs://demo', isPrimary: true }],
      files: [
        { originalRelativePath: 'objects/a.mov', filename: 'a.mov', sizeBytes: 700 },
        { originalRelativePath: 'objects/b.txt', filename: 'b.txt', sizeBytes: 300 },
      ],
    });
    const [n] = await db.select().from(nodes).where(eq(nodes.id, workId));
    expect(n.totalSizeBytes).toBe(1000);
    expect(n.aipCount).toBe(1);
    expect(n.fileCount).toBe(2);
    expect(n.lastIngestedAt).not.toBeNull();
  });

  it('throws AipAlreadyExistsError on duplicate uuid without touching rollups', async () => {
    await expect(createAip(db, { uuid: U1, nodeId: workId, rootNodeId: workId, origin: 'direct' }))
      .rejects.toBeInstanceOf(AipAlreadyExistsError);
    const [n] = await db.select().from(nodes).where(eq(nodes.id, workId));
    expect(n.aipCount).toBe(1);
  });

  it('softDeleteAip reverses the rollups and audits with snapshot', async () => {
    const U2 = '11111111-0000-0000-0000-000000000002';
    const a2 = await createAip(db, { uuid: U2, nodeId: workId, rootNodeId: workId, origin: 'direct', sizeBytes: 500 });
    await softDeleteAip(db, a2.id, null);
    const [n] = await db.select().from(nodes).where(eq(nodes.id, workId));
    expect(n.totalSizeBytes).toBe(1000);
    expect(n.aipCount).toBe(1);
  });

  it('recomputeRollups restores values from scratch', async () => {
    await db.update(nodes).set({ totalSizeBytes: 999999, aipCount: 42 }).where(eq(nodes.id, workId));
    await recomputeRollups(db, workId);
    const [n] = await db.select().from(nodes).where(eq(nodes.id, workId));
    expect(n.totalSizeBytes).toBe(1000);
    expect(n.aipCount).toBe(1);
    expect(n.fileCount).toBe(2);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현**

`packages/db/src/queries/aips.ts`:
```ts
import { and, eq, isNull, sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { aipCopies, aips, files, nodes } from '../schema/index.js';
import type { DbExecutor } from '../types.js';
import { writeAudit } from './audit.js';

export class AipAlreadyExistsError extends Error {
  constructor(uuid: string) {
    super(`aip already exists: ${uuid}`);
    this.name = 'AipAlreadyExistsError';
  }
}

export type AipRow = typeof aips.$inferSelect;

export type FileInput = {
  originalRelativePath: string;
  filename: string;
  sizeBytes?: number;
  puid?: string;
  mimeType?: string;
  mediaTypeTermId?: number;
  checksum?: string;
  checksumType?: string;
  formatName?: string;
  videoCodecs?: string[];
  audioCodecs?: string[];
  resolution?: number;
  bitDepth?: number;
  sampleRate?: number;
  chromaSubsampling?: string;
  colorSpace?: string;
  characterization?: Record<string, unknown>;
};

export type CreateAipInput = {
  uuid: string;
  nodeId: number;
  rootNodeId: number;
  origin: 'archivematica' | 'direct';
  typeTermId?: number;
  filename?: string;
  sizeBytes?: number;
  pipelineUuid?: string;
  metsLocation?: string;
  metsSha256?: string;
  ingestedBy?: number;
  ingestJobId?: string;
  actorId?: number | null;
  copies?: Array<{ backendId: number; locator: string; isPrimary?: boolean }>;
  files?: FileInput[];
};

export async function createAip(db: PostgresJsDatabase, input: CreateAipInput): Promise<AipRow> {
  const [dup] = await db.select({ id: aips.id }).from(aips).where(eq(aips.uuid, input.uuid));
  if (dup) throw new AipAlreadyExistsError(input.uuid);

  return db.transaction(async (rawTx) => {
    const tx = rawTx as unknown as DbExecutor; // localized cast — see DbExecutor note in types.ts
    const [aip] = await tx.insert(aips).values({
      uuid: input.uuid,
      nodeId: input.nodeId,
      rootNodeId: input.rootNodeId,
      origin: input.origin,
      typeTermId: input.typeTermId,
      filename: input.filename,
      sizeBytes: input.sizeBytes ?? 0,
      fileCount: input.files?.length ?? 0,
      pipelineUuid: input.pipelineUuid,
      metsLocation: input.metsLocation,
      metsSha256: input.metsSha256,
      ingestedBy: input.ingestedBy,
      ingestJobId: input.ingestJobId,
    }).returning();

    if (input.copies?.length) {
      await tx.insert(aipCopies).values(input.copies.map((c) => ({
        aipId: aip.id, backendId: c.backendId, locator: c.locator, isPrimary: c.isPrimary ?? false,
      })));
    }
    if (input.files?.length) {
      await tx.insert(files).values(input.files.map((f) => ({
        aipId: aip.id, rootNodeId: input.rootNodeId, ...f, sizeBytes: f.sizeBytes ?? 0,
      })));
    }

    await applyRollupDelta(tx, input.rootNodeId, {
      sizeDelta: aip.sizeBytes, aipDelta: 1, fileDelta: input.files?.length ?? 0, touchIngestedAt: true,
    });

    await writeAudit(tx, {
      tableName: 'aips', rowId: aip.id, action: 'insert',
      actorId: input.actorId ?? input.ingestedBy ?? null, origin: 'ingest',
      jobRef: input.ingestJobId,
    });

    return aip;
  });
}

export async function softDeleteAip(db: PostgresJsDatabase, id: number, userId: number | null): Promise<void> {
  await db.transaction(async (rawTx) => {
    const tx = rawTx as unknown as DbExecutor;
    const [row] = await tx.select().from(aips).where(and(eq(aips.id, id), isNull(aips.deletedAt)));
    if (!row) return;

    const [{ fileCount }] = (await tx.execute(sql`
      SELECT count(*)::int AS "fileCount" FROM files WHERE aip_id = ${id} AND deleted_at IS NULL
    `)) as unknown as Array<{ fileCount: number }>;

    await tx.update(aips).set({ deletedAt: sql`now()`, deletedBy: userId }).where(eq(aips.id, id));
    await tx.update(files).set({ deletedAt: sql`now()`, deletedBy: userId })
      .where(and(eq(files.aipId, id), isNull(files.deletedAt)));

    await applyRollupDelta(tx, row.rootNodeId, {
      sizeDelta: -row.sizeBytes, aipDelta: -1, fileDelta: -fileCount, touchIngestedAt: false,
    });

    await writeAudit(tx, {
      tableName: 'aips', rowId: id, action: 'delete', actorId: userId, origin: 'user',
      snapshot: row as unknown as Record<string, unknown>,
    });
  });
}

async function applyRollupDelta(
  tx: DbExecutor,
  rootNodeId: number,
  d: { sizeDelta: number; aipDelta: number; fileDelta: number; touchIngestedAt: boolean },
): Promise<void> {
  await tx.update(nodes).set({
    totalSizeBytes: sql`total_size_bytes + ${d.sizeDelta}`,
    aipCount: sql`aip_count + ${d.aipDelta}`,
    fileCount: sql`file_count + ${d.fileDelta}`,
    ...(d.touchIngestedAt ? { lastIngestedAt: sql`now()` } : {}),
    updatedAt: sql`now()`,
  }).where(eq(nodes.id, rootNodeId));
}

export async function recomputeRollups(db: PostgresJsDatabase, rootNodeId: number): Promise<void> {
  await db.execute(sql`
    UPDATE nodes SET
      total_size_bytes = COALESCE((SELECT sum(size_bytes) FROM aips WHERE root_node_id = ${rootNodeId} AND deleted_at IS NULL), 0),
      aip_count = COALESCE((SELECT count(*) FROM aips WHERE root_node_id = ${rootNodeId} AND deleted_at IS NULL), 0),
      file_count = COALESCE((SELECT count(*) FROM files WHERE root_node_id = ${rootNodeId} AND deleted_at IS NULL), 0),
      last_ingested_at = (SELECT max(created_at) FROM aips WHERE root_node_id = ${rootNodeId} AND deleted_at IS NULL),
      updated_at = now()
    WHERE id = ${rootNodeId}
  `);
}
```

`src/index.ts`에 추가:
```ts
export { AipAlreadyExistsError, createAip, softDeleteAip, recomputeRollups } from './queries/aips.js';
export type { CreateAipInput, FileInput, AipRow } from './queries/aips.js';
```

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS.

```bash
git add -A && git commit -m "feat(db): transactional aip creation/soft-delete with incremental node rollups"
```

---

### Task 6: fixity 쿼리 — TDD

**Files:**
- Create: `packages/db/src/queries/fixity.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/fixity-queries.test.ts`

**Interfaces:**
- Consumes: fixityReports/fixityRecoveries 스키마, aips.
- Produces:
  - `registerFixityReport(db, input: { aipUuid, success, message?, failures?, sessionUuid?, startedAt, completedAt }): Promise<number>` — aipUuid → aip 해석(미존재 시 Error /aip/i), report id 반환
  - `latestFixityByAip(db, aipIds: number[]): Promise<Map<number, { success: boolean; completedAt: Date | null }>>` — `DISTINCT ON (aip_id) ... ORDER BY aip_id, completed_at DESC` 단일 쿼리
  - `openRecoveryExists(db, aipId): Promise<boolean>` — finished_at IS NULL 복구 존재 여부(Binder의 이중 요청 가드 계승)

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/fixity-queries.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { collectionProfiles, fixityRecoveries, storageBackends, users } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';
import { createAip } from '../src/queries/aips.js';
import { latestFixityByAip, openRecoveryExists, registerFixityReport } from '../src/queries/fixity.js';

const { db, sql } = testDb();
let aipId: number;
const U = '22222222-0000-0000-0000-000000000001';

beforeAll(async () => {
  await truncate(sql, 'fixity_recoveries', 'fixity_reports', 'ingest_jobs', 'files', 'aip_copies', 'aips', 'storage_backends', 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies', 'user_roles', 'users');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  const w = await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, title: { en: 'Fixity Q' } });
  aipId = (await createAip(db, { uuid: U, nodeId: w.id, rootNodeId: w.id, origin: 'direct' })).id;
});
afterAll(() => sql.end());

describe('fixity queries', () => {
  it('registers reports by aip uuid and rejects unknown uuids', async () => {
    const t0 = new Date('2026-07-04T00:00:00Z');
    await registerFixityReport(db, { aipUuid: U, success: true, startedAt: t0, completedAt: t0 });
    await expect(registerFixityReport(db, {
      aipUuid: '99999999-0000-0000-0000-000000000009', success: true, startedAt: t0, completedAt: t0,
    })).rejects.toThrow(/aip/i);
  });

  it('latestFixityByAip returns only the most recent report per aip', async () => {
    const t1 = new Date('2026-07-04T01:00:00Z');
    await registerFixityReport(db, { aipUuid: U, success: false, message: 'corrupt', startedAt: t1, completedAt: t1 });
    const m = await latestFixityByAip(db, [aipId]);
    expect(m.get(aipId)?.success).toBe(false);
  });

  it('openRecoveryExists reflects unfinished recoveries', async () => {
    expect(await openRecoveryExists(db, aipId)).toBe(false);
    const [u] = await db.insert(users).values({ username: 'fix', email: 'fix@example.com', passwordHash: 'x' }).returning();
    const [b] = await db.insert(storageBackends).values({ kind: 'am_ss', name: 'ss' }).returning();
    await db.insert(fixityRecoveries).values({ aipId, userId: u.id, backendId: b.id });
    expect(await openRecoveryExists(db, aipId)).toBe(true);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현**

`packages/db/src/queries/fixity.ts`:
```ts
import { and, eq, inArray, isNull, sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { aips, fixityRecoveries, fixityReports } from '../schema/index.js';

export async function registerFixityReport(
  db: PostgresJsDatabase,
  input: {
    aipUuid: string;
    success: boolean;
    message?: string;
    failures?: Record<string, unknown>;
    sessionUuid?: string;
    startedAt: Date;
    completedAt: Date;
  },
): Promise<number> {
  const [aip] = await db.select({ id: aips.id }).from(aips).where(eq(aips.uuid, input.aipUuid));
  if (!aip) throw new Error(`aip not found: ${input.aipUuid}`);
  const [row] = await db.insert(fixityReports).values({
    aipId: aip.id,
    success: input.success,
    message: input.message,
    failures: input.failures,
    sessionUuid: input.sessionUuid,
    startedAt: input.startedAt,
    completedAt: input.completedAt,
  }).returning({ id: fixityReports.id });
  return row.id;
}

export async function latestFixityByAip(
  db: PostgresJsDatabase,
  aipIds: number[],
): Promise<Map<number, { success: boolean; completedAt: Date | null }>> {
  if (aipIds.length === 0) return new Map();
  const rows = (await db.execute(sql`
    SELECT DISTINCT ON (aip_id) aip_id, success, completed_at
    FROM fixity_reports
    WHERE aip_id IN ${sql`(${sql.join(aipIds.map((i) => sql`${i}`), sql`, `)})`}
    ORDER BY aip_id, completed_at DESC NULLS LAST
  `)) as unknown as Array<{ aip_id: number; success: boolean; completed_at: Date | null }>;
  return new Map(rows.map((r) => [Number(r.aip_id), { success: r.success, completedAt: r.completed_at }]));
}

export async function openRecoveryExists(db: PostgresJsDatabase, aipId: number): Promise<boolean> {
  const [row] = await db.select({ id: fixityRecoveries.id }).from(fixityRecoveries)
    .where(and(eq(fixityRecoveries.aipId, aipId), isNull(fixityRecoveries.finishedAt)))
    .limit(1);
  return !!row;
}
```

`src/index.ts`에 추가:
```ts
export { registerFixityReport, latestFixityByAip, openRecoveryExists } from './queries/fixity.js';
```

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS.

```bash
git add -A && git commit -m "feat(db): fixity report registration and latest-per-aip queries"
```

---

### Task 7: search_docs + pg_trgm — TDD

**Files:**
- Create: `packages/db/src/schema/search.ts`, `packages/db/src/queries/search.ts`
- Modify: `packages/db/src/schema/index.ts`, `packages/db/src/index.ts`
- Custom migration: `pnpm --filter @binder/db exec drizzle-kit generate --custom --name enable_pg_trgm` 으로 빈 마이그레이션 생성 후 그 파일에 `CREATE EXTENSION IF NOT EXISTS pg_trgm;` 작성(적용 전 신규 파일이므로 허용)
- Test: `packages/db/test/search.test.ts`

**Interfaces:**
- Consumes: nodes, agents, events(getCreators), aips, files.
- Produces:
  - 테이블 `searchDocs`: id serial PK, entity_type text NOT NULL, entity_id integer NOT NULL, culture text NOT NULL, text_content text NOT NULL, tsv tsvector(generated 아님 — upsert 시 계산), UNIQUE(entity_type, entity_id, culture), GIN(tsv), GIN(text_content gin_trgm_ops)
  - `upsertSearchDoc(dbx: DbExecutor, doc: { entityType, entityId, culture, textContent }): Promise<void>` — tsv는 `to_tsvector('simple', ...)`로 계산해 저장
  - `buildNodeSearchDocs(db, nodeId): Promise<void>` — 노드의 title(ko/en 각각) + identifier + accession_number + component_number + 제작자 이름을 모아 culture별 2행 upsert
  - `searchEntities(db, query, opts?: { entityType?, limit? }): Promise<Array<{ entityType, entityId, culture, rank }>>` — tsv 매치 OR trgm 유사(부분일치, 한국어 대응) — `WHERE tsv @@ plainto_tsquery('simple', q) OR text_content ILIKE '%'||q||'%'` + similarity 정렬, LIKE 특수문자(% _) 이스케이프

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/search.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { agents, collectionProfiles } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';
import { setCreationEvent } from '../src/queries/events.js';
import { buildNodeSearchDocs, searchEntities } from '../src/queries/search.js';

const { db, sql } = testDb();
let nodeId: number;

beforeAll(async () => {
  await truncate(sql, 'search_docs', 'fixity_recoveries', 'fixity_reports', 'ingest_jobs', 'files', 'aip_copies', 'aips', 'storage_backends', 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  nodeId = (await createNode(db, {
    kind: 'work', levelTermId: lv, profileId: p.id,
    title: { ko: '러버스', en: 'Lovers' }, accessionNumber: '1250.2011',
  })).id;
  const [a] = await db.insert(agents).values({ kind: 'person', name: { ko: '후루하시 테이지', en: 'Teiji Furuhashi' }, slug: 'furuhashi' }).returning();
  await setCreationEvent(db, nodeId, { agentId: a.id });
  await buildNodeSearchDocs(db, nodeId);
});
afterAll(() => sql.end());

describe('search', () => {
  it('finds by english word (tsv path)', async () => {
    const hits = await searchEntities(db, 'lovers');
    expect(hits.some((h) => h.entityId === nodeId && h.entityType === 'node')).toBe(true);
  });

  it('finds by korean partial match (trgm path)', async () => {
    const hits = await searchEntities(db, '러버');
    expect(hits.some((h) => h.entityId === nodeId)).toBe(true);
  });

  it('finds by creator name and accession number', async () => {
    expect((await searchEntities(db, 'Furuhashi')).length).toBeGreaterThan(0);
    expect((await searchEntities(db, '1250.2011')).length).toBeGreaterThan(0);
  });

  it('rebuild is idempotent (unique upsert per entity+culture)', async () => {
    await buildNodeSearchDocs(db, nodeId);
    const rows = (await sql`SELECT count(*)::int AS c FROM search_docs WHERE entity_type = 'node' AND entity_id = ${nodeId}`);
    expect(rows[0].c).toBe(2); // ko + en
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현 + 마이그레이션(2개: pg_trgm 커스텀 + 스키마)**

`packages/db/src/schema/search.ts`:
```ts
import { customType, integer, pgTable, serial, text, uniqueIndex, index } from 'drizzle-orm/pg-core';

const tsvector = customType<{ data: string }>({ dataType: () => 'tsvector' });

export const searchDocs = pgTable('search_docs', {
  id: serial('id').primaryKey(),
  entityType: text('entity_type').notNull(),
  entityId: integer('entity_id').notNull(),
  culture: text('culture').notNull(),
  textContent: text('text_content').notNull(),
  tsv: tsvector('tsv'),
}, (t) => [
  uniqueIndex('search_docs_entity_culture_uq').on(t.entityType, t.entityId, t.culture),
  index('search_docs_tsv_gin').using('gin', t.tsv),
]);
```
(**trgm GIN 인덱스는 opclass 지정이 필요해 drizzle-kit가 표현 못 함** — pg_trgm 커스텀 마이그레이션 파일에 함께 작성:)
```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "search_docs_trgm_gin" ON "search_docs" USING gin ("text_content" gin_trgm_ops);
```
(주의: 커스텀 마이그레이션이 스키마 마이그레이션(search_docs 생성)보다 **뒤에** 오도록 생성 순서를 지킬 것 — 스키마 db:generate 먼저, custom 생성 나중.)

`packages/db/src/queries/search.ts`:
```ts
import { sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import type { DbExecutor } from '../types.js';
import { nodes } from '../schema/index.js';
import { eq } from 'drizzle-orm';
import { getCreators } from './events.js';

export async function upsertSearchDoc(
  dbx: DbExecutor,
  doc: { entityType: string; entityId: number; culture: 'ko' | 'en'; textContent: string },
): Promise<void> {
  await dbx.execute(sql`
    INSERT INTO search_docs (entity_type, entity_id, culture, text_content, tsv)
    VALUES (${doc.entityType}, ${doc.entityId}, ${doc.culture}, ${doc.textContent}, to_tsvector('simple', ${doc.textContent}))
    ON CONFLICT (entity_type, entity_id, culture)
    DO UPDATE SET text_content = EXCLUDED.text_content, tsv = EXCLUDED.tsv
  `);
}

export async function buildNodeSearchDocs(db: PostgresJsDatabase, nodeId: number): Promise<void> {
  const [n] = await db.select().from(nodes).where(eq(nodes.id, nodeId));
  if (!n) return;
  const creators = await getCreators(db, nodeId);
  const idsPart = [n.identifier, n.accessionNumber, n.componentNumber].filter(Boolean).join(' ');
  for (const culture of ['ko', 'en'] as const) {
    const names = creators.map((c) => c.name[culture] ?? c.name.ko ?? c.name.en ?? '').join(' ');
    const title = n.title[culture] ?? '';
    const content = [title, idsPart, names].filter(Boolean).join(' ').trim();
    if (content) await upsertSearchDoc(db as unknown as DbExecutor, {
      entityType: 'node', entityId: nodeId, culture, textContent: content,
    });
  }
}

export async function searchEntities(
  db: PostgresJsDatabase,
  query: string,
  opts: { entityType?: string; limit?: number } = {},
): Promise<Array<{ entityType: string; entityId: number; culture: string; rank: number }>> {
  const q = query.trim();
  if (!q) return [];
  const like = `%${q.replace(/[\\%_]/g, (m) => `\\${m}`)}%`;
  const rows = (await db.execute(sql`
    SELECT entity_type, entity_id, culture,
           GREATEST(
             COALESCE(ts_rank(tsv, plainto_tsquery('simple', ${q})), 0),
             similarity(text_content, ${q})
           ) AS rank
    FROM search_docs
    WHERE (tsv @@ plainto_tsquery('simple', ${q}) OR text_content ILIKE ${like})
      ${opts.entityType ? sql`AND entity_type = ${opts.entityType}` : sql``}
    ORDER BY rank DESC
    LIMIT ${opts.limit ?? 50}
  `)) as unknown as Array<{ entity_type: string; entity_id: number; culture: string; rank: number }>;
  return rows.map((r) => ({
    entityType: r.entity_type, entityId: Number(r.entity_id), culture: r.culture, rank: Number(r.rank),
  }));
}
```

`schema/index.ts`에 추가: `export { searchDocs } from './search.js';`
`src/index.ts`에 추가: `export { upsertSearchDoc, buildNodeSearchDocs, searchEntities } from './queries/search.js';`

Run 순서: `pnpm --filter @binder/db db:generate`(search_docs) → `pnpm --filter @binder/db exec drizzle-kit generate --custom --name enable_pg_trgm`(트리그램 SQL 작성) → `pnpm --filter @binder/db db:migrate`.

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS.

```bash
git add -A && git commit -m "feat(db): cross-entity search docs with tsvector and pg_trgm partial matching"
```

---

### Task 8: 데모 시더 확장(AIP·파일·fixity) + README + 검증

**Files:**
- Modify: `packages/db/src/seed/seed-demo.ts`, `README.md`
- Test: `packages/db/test/seed-demo.test.ts`(확장)

**Interfaces:**
- Consumes: Task 2–7 전부.
- Produces: `seedDemo`가 추가로 — 로컬 fs 백엔드 1행(ensure), Lovers에 AIP 2개(uuid 고정 `6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52`/`9559945a-52e6-4c0e-9d54-83e8e548dd1d`, Phase 1 데모 계승; 첫 AIP에 파일 2개: lovers_ch1.mov/lovers_ch2.mov, video facet 채움), Grosse Fatigue에 AIP 1개(`d3a3f8d6-8a5b-4e6c-9c1a-2f4b7c1d9e0f`), 각 AIP 성공 fixity 리포트 1건(고정 session uuid), 관련 노드 search docs 빌드. **기존 멱등 가드 유지** — AIP 시딩도 가드 안쪽(트랜잭션 내).

- [ ] **Step 1: 실패하는 테스트 확장**

`packages/db/test/seed-demo.test.ts`의 describe에 추가:
```ts
  it('attaches fixed-uuid AIPs with files, fixity reports and search docs', async () => {
    const aipRows = (await sql`SELECT uuid, size_bytes FROM aips WHERE deleted_at IS NULL ORDER BY uuid`);
    expect(aipRows).toHaveLength(3);

    const [lovers] = (await sql`SELECT id, total_size_bytes, aip_count, file_count FROM nodes WHERE accession_number = '1250.2011'`);
    expect(Number(lovers.aip_count)).toBe(2);
    expect(Number(lovers.file_count)).toBe(2);
    expect(Number(lovers.total_size_bytes)).toBeGreaterThan(0);

    const fx = (await sql`SELECT count(*)::int AS c FROM fixity_reports`);
    expect(fx[0].c).toBe(3);

    const { searchEntities } = await import('../src/queries/search.js');
    const hits = await searchEntities(db, '러버');
    expect(hits.length).toBeGreaterThan(0);
  });
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → seed-demo FAIL (신규 단언).

- [ ] **Step 3: 시더 확장 구현**

`seed-demo.ts`에 (트랜잭션 내, 기존 노드 생성 뒤) 추가 — import에 `storageBackends`, `createAip`, `registerFixityReport`, `buildNodeSearchDocs` 등:
```ts
  // ── 보존 데이터: 백엔드 + AIP + 파일 + fixity + 검색 인덱스
  const [backend] = await tx.insert(storageBackends)
    .values({ kind: 'local_fs', name: 'demo local storage', config: { root: '/var/binder/aips' } })
    .returning();

  const SESSION = 'eeeeeeee-0000-0000-0000-00000000cafe';
  const t0 = new Date('2026-07-04T09:00:00Z');

  const mkAip = async (uuid: string, nodeId: number, rootId: number, size: number, fileRows: FileInput[]) => {
    const aip = await createAip(txDb, {
      uuid, nodeId, rootNodeId: rootId, origin: 'archivematica', sizeBytes: size,
      filename: uuid.slice(0, 8), copies: [{ backendId: backend.id, locator: `fs://${uuid}`, isPrimary: true }],
      files: fileRows,
    });
    await registerFixityReport(txDb, { aipUuid: uuid, success: true, sessionUuid: SESSION, startedAt: t0, completedAt: t0 });
    return aip;
  };
```
(**주의 — 트랜잭션 중첩 문제**: `createAip`/`registerFixityReport`는 `PostgresJsDatabase`를 받고 createAip는 내부에서 `db.transaction`을 연다. seedDemo 전체가 이미 트랜잭션이므로 **이 함수들을 tx 안에서 호출할 수 없다**. 해법(택1, 구현자가 결정하고 보고): (a) seedDemo의 트랜잭션 경계를 "노드·권리 시딩"까지로 좁히고, AIP·fixity·검색 시딩은 트랜잭션 밖에서 `db`로 호출 — 멱등 가드가 첫 단계에서 전체를 스킵하므로 부분 실패 시 반쪽 상태 가능성은 재실행으로 해소 불가 → 가드를 마지막 단계(검색 빌드 후) 기준으로 바꾸거나, (b) createAip에 `DbExecutor` 오버로드 추가(트랜잭션 스킵 플래그) — 계획은 (a)+가드 조정(가드 기준을 `aips.uuid = '6f198fa9-…'` 존재로 변경: 최후 산출물 존재 = 완주)을 기본으로 한다.)

Lovers/GF의 노드 id로 `mkAip` 3회 호출(첫 AIP에 파일 2행: `objects/lovers_ch1.mov` 117MB mjpg/pcm 480p QuickTime, `objects/lovers_ch2.mov` 122MB 동일 특성), 마지막에 `buildNodeSearchDocs(db, loversId)` / `(db, gfId)` / 기록물 fonds에도 호출.

README `시작하기` 아래 참고 블록에 검색·보존 데이터가 데모 시더에 포함됨을 한 줄 추가.

- [ ] **Step 4: 그린 + 전체 검증 + 커밋**

Run:
```bash
pnpm --filter @binder/db test && pnpm typecheck
pnpm --filter @binder/db db:migrate && pnpm --filter @binder/db db:seed && pnpm --filter @binder/db db:seed-demo && pnpm --filter @binder/db db:seed-demo
pnpm test
```
Expected: 전부 통과, 데모 시더 2회 실행 멱등.

```bash
git add -A && git commit -m "feat(db): demo seeder gains preservation data, fixity reports and search docs"
```

---

## Self-Review

1. **Spec coverage**: 데이터모델 §2D storage_backends/aip_copies/aips=T2, files=T3, fixity 2종=T4·T6, §2E ingest_jobs=T4, §2F search_docs=T7, §3.3 롤업·DISTINCT ON 패턴=T5·T6, 백로그(db tsc, profile_version FK)=T1, 데모·검증=T8. metadata_sources/node_sources/sync_runs는 **Plan 06**(외부 동기화) 이관 — 의도적.
2. **Placeholder scan**: 전 스텝 실코드. T8의 트랜잭션 중첩 이슈는 해법 (a)를 기본 지시로 명시(구현자 재량 여지는 보고 의무와 함께).
3. **Type consistency**: `DbExecutor`는 Plan 02 정의 재사용; `createAip(db: PostgresJsDatabase)` — seedDemo와의 조합 문제를 T8에서 명시적으로 다룸. `FileInput`/`CreateAipInput`은 T5 Produces↔구현↔T8 사용 일치. `latestFixityByAip` 시그니처 T6 Produces↔구현 일치. 마이그레이션 번호는 Global Constraints대로 실번호 추종.
