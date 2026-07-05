# Plan 10 — P3.1a RiC-O 그래프 코어 (스키마·마이그레이션·감사·TTL)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** binder-next의 계층 데이터 모델을 RiC-O 정렬 그래프 코어(Record↔Instantiation 분리, 관계 1급 객체, 어휘 레지스트리, URI 1급화)로 재기반화하고, 기존 데이터를 트리아지 마이그레이션으로 이관한다. UI는 Plan 11(P3.1b).

**Architecture:** PG가 진실(스펙 D5). `instantiations`·`relation_types`·`entity_relations`·`vocabularies`·`vocab_mappings` 신설, `terms`/`agents`/`events`/`audit_log` 확장. 기존 `relations`/`events.nodeId`는 레거시로 보존(추가 컬럼만, 삭제 없음 — §7(d) 구조 보존 원칙). **신규 이벤트 경로도 events.nodeId를 병기(이중 기입)** — 레거시 화면(detail.ts/reports.ts raw SQL)과 대사식의 경로 중립성을 위해. 마이그레이션은 `runGraphMigration(db)` 함수(+얇은 CLI 래퍼) + JSON 리포트 + 전후 대사 통합 테스트, dev DB 적용은 통합 테스트 통과 후.

**Tech Stack:** Drizzle ORM + drizzle-kit generate(자동 생성 마이그레이션), PostgreSQL 16(:15432), vitest(binder_test), tsx 스크립트.

**스펙:** `docs/superpowers/specs/2026-07-06-phase3-knowledge-graph-hub-design.md` — §2 전체(§2.4의 exactMatch 저장처=entity_identifiers는 P3.2 범위라 이 플랜에서 제외), §3.4 전제(감사 갭 봉합+행위 주체), §7 마이그레이션. §6 UI·시드 확장·시연은 Plan 11.

## Global Constraints

- 모든 import는 '.js' 확장자 ESM. 단 **src/schema/*.ts 내부의 스키마 간 상호 import는 확장자 없이**(drizzle-kit CJS 로더).
- 마이그레이션은 손으로 쓰지 않는다: 스키마 수정 → `pnpm --filter @binder/db db:generate` → **생성 SQL을 눈으로 검토** → `pnpm --filter @binder/db db:migrate`. 현재 마지막 마이그레이션 = 0020.
- 모든 변이 함수는 트랜잭션 안에서 `writeAudit` 호출(`tx as unknown as DbExecutor` 캐스트 패턴, `packages/db/src/queries/nodes.ts:192-236` updateNode 참조). passwordHash는 감사에 절대 미포함.
- 소프트 삭제 = deletedAt/deletedBy + live-only 부분 유니크(WHERE deleted_at IS NULL). 하드 DELETE 금지(vocab_mappings만 예외 — 스키마 데이터, snapshot 감사 동반).
- i18n 값은 jsonb I18nText(`{ko?, en?}`), 신규 택소노미/시드에 ko·en 병기.
- 신규 API 라우트 없음(이 플랜은 DB 계층만) — CSRF 불변식 비접촉.
- 게이트: `pnpm -r typecheck && pnpm -r test` 그린. e2e·dev DB 적용은 Task 9에서만.
- **dev DB(:15432/binder)를 변형하는 작업은 Task 9 이전 금지**(스키마 마이그레이션 적용은 허용, 데이터 변형 스크립트 실행은 금지).
- 커밋 트레일러: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- 작업 디렉터리: `/Users/daejinan/claude/moma-binder/binder-next`

## SKOS 승격 범위 표 (스펙 §2.4 요구 — 이 플랜에서 확정)

| 택소노미 | 구분 | Plan 10 처리 | 계층·외부매칭 노출(Plan 11+/P3.2) |
|---|---|---|---|
| level_of_description, media_type, department, classification, component_type, **instantiation_role**(신설) | **기술 어휘 → SKOS 승격** | uuid+parentTermId 부여(Task 2·4) | 승격 대상 — 관리 UI·발행 노출 |
| relation_type(구 12텀 — 레거시), note_type, aip_type, event_type, rights_basis, rights_act, copyright_status | **시스템 어휘 → 현행 유지** | uuid는 부여되나 계층·발행 비노출 | 비대상 |

aip_type은 **role로 흡수 후 폐기 예정**(스펙 §2.2) — Task 9에서 원장·README에 표기.

---

### Task 1: audit 확장 — 행위 주체·엔터티 uuid 컬럼 + relations 감사 봉합

**Files:**
- Modify: `packages/db/src/schema/audit.ts`
- Modify: `packages/db/src/queries/audit.ts`
- Modify: `packages/db/src/queries/relations.ts` (linkNodes audit화)
- Test: `packages/db/test/audit-actor.test.ts`

**Interfaces:**
- Consumes: 기존 `writeAudit(db, entry)`, `AuditEntry`(queries/audit.ts:5-16)
- Produces: `AuditEntry`에 선택 필드 `actorKind?: 'user' | 'ai' | 'workflow'`(기본 'user'), `actorRef?: string`, `entityUuid?: string` 추가. auditLog 컬럼 `actorKind`, `actorRef`, `entityUuid`. 이후 모든 Task가 신규 엔터티 감사 시 `entityUuid`를 전달한다.
- **주의:** events(setCreationEvent)의 감사는 이 태스크에서 다루지 않는다 — Task 6가 함수를 전면 재구현하며 일임한다(이중 작업 방지). notes 삽입 경로는 현재 seed 전용이면 생략하고 리뷰 노트에 기록.

- [ ] **Step 1: 실패 테스트 작성** — `packages/db/test/audit-actor.test.ts`

```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { and, eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { auditLog } from '../src/schema/index.js';
import { writeAudit } from '../src/queries/audit.js';

const { db, sql } = testDb();
beforeAll(async () => { await truncate(sql, 'audit_log'); });
afterAll(() => sql.end());

describe('audit actor context', () => {
  it('defaults actorKind to user and stores entityUuid', async () => {
    await writeAudit(db, {
      tableName: 'nodes', rowId: 990001, action: 'insert', actorId: null, origin: 'user',
      entityUuid: '11111111-1111-4111-8111-111111111111',
    });
    const [row] = await db.select().from(auditLog)
      .where(and(eq(auditLog.tableName, 'nodes'), eq(auditLog.rowId, 990001)));
    expect(row.actorKind).toBe('user');
    expect(row.entityUuid).toBe('11111111-1111-4111-8111-111111111111');
  });
  it('stores ai actorKind with actorRef', async () => {
    await writeAudit(db, {
      tableName: 'entity_relations', rowId: 990002, action: 'insert', actorId: null, origin: 'ingest',
      actorKind: 'ai', actorRef: 'extract-job:42',
    });
    const [row] = await db.select().from(auditLog).where(eq(auditLog.rowId, 990002));
    expect(row.actorKind).toBe('ai');
    expect(row.actorRef).toBe('extract-job:42');
  });
});
```

- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- audit-actor` / Expected: FAIL (컬럼·필드 없음)
- [ ] **Step 3: 스키마 수정** — `packages/db/src/schema/audit.ts`에 추가:

```ts
export const auditActorKindEnum = pgEnum('audit_actor_kind', ['user', 'ai', 'workflow']);
// auditLog 테이블 정의에 컬럼 3개 추가:
//   actorKind: auditActorKindEnum('actor_kind').notNull().default('user'),
//   actorRef: text('actor_ref'),
//   entityUuid: uuid('entity_uuid'),
```

- [ ] **Step 4: 마이그레이션 생성·검토·적용** — Run: `pnpm --filter @binder/db db:generate` → 생성 SQL(0021)에 enum 생성 + ALTER 3건인지 확인 → `pnpm --filter @binder/db db:migrate` / Expected: 적용 성공
- [ ] **Step 5: writeAudit 확장** — `queries/audit.ts`의 `AuditEntry`에 `actorKind?: 'user' | 'ai' | 'workflow'; actorRef?: string; entityUuid?: string;` 추가, insert 값에 `actorKind: entry.actorKind ?? 'user', actorRef: entry.actorRef ?? null, entityUuid: entry.entityUuid ?? null` 반영
- [ ] **Step 6: 테스트 통과 확인** — Run: `pnpm --filter @binder/db test -- audit-actor` / Expected: PASS
- [ ] **Step 7: linkNodes 감사 봉합** — `queries/relations.ts` `linkNodes`: insert에 `.returning()`을 붙여 실제 행이 생겼을 때만(onConflictDoNothing으로 빈 배열 가능) `writeAudit(db, { tableName: 'relations', rowId: inserted.id, action: 'insert', actorId: null, origin: 'user' })` 호출. 기존 테스트 전체 그린 유지: `pnpm --filter @binder/db test`
- [ ] **Step 8: 커밋** — `git add -A && git commit -m "feat(db): audit actor context + entity uuid + legacy relations audit"`

---

### Task 2: uuid·SKOS 컬럼 확장 + URI·클래스 판정 헬퍼

**Files:**
- Modify: `packages/db/src/schema/agents.ts` (agents.uuid)
- Modify: `packages/db/src/schema/taxonomies.ts` (terms.uuid, terms.parentTermId)
- Create: `packages/core/src/uri.ts`
- Modify: `packages/core/src/index.ts` (export)
- Test: `packages/core/test/uri.test.ts`, `packages/db/test/uuid-backfill.test.ts`

**Interfaces:**
- Produces:
  - `agents.uuid`(notNull unique defaultRandom — 기존 행 자동 백필), `terms.uuid`(동일), `terms.parentTermId`(self-FK set null — SKOS broader; 계층·발행 노출 범위는 상단 SKOS 승격 범위 표를 따름)
  - core `packages/core/src/uri.ts`: `ENTITY_TYPES = ['node','agent','event','term','instantiation'] as const`, `type EntityType`, `buildEntityUri(type, uuid): string` → `/id/{type}/{uuid}`, `parseEntityUri(uri)` — 그리고 **RiC-O 클래스 판정 순수 함수**(스펙 §2.2 매핑표의 정본 구현, P3.2 JSON-LD 발행이 소비):

```ts
export type InternalClass = 'record_set' | 'record';
export function internalClassFor(kind: 'work' | 'component' | 'record' | 'supporting', levelMachine: string | null): InternalClass {
  if (kind === 'record' && (levelMachine === 'fonds' || levelMachine === 'series')) return 'record_set';
  return 'record'; // work·supporting·component(잔존 표현형)·record(file/item 등) 전부 Record
}
```

- [ ] **Step 1: core 실패 테스트** — `packages/core/test/uri.test.ts`

```ts
import { describe, expect, it } from 'vitest';
import { buildEntityUri, internalClassFor, parseEntityUri } from '../src/uri.js';

describe('entity uri', () => {
  it('builds /id/{type}/{uuid}', () => {
    expect(buildEntityUri('node', 'abc-123')).toBe('/id/node/abc-123');
  });
  it('parses round-trip and rejects unknown type', () => {
    expect(parseEntityUri('/id/agent/u1')).toEqual({ type: 'agent', uuid: 'u1' });
    expect(parseEntityUri('/id/bogus/u1')).toBeNull();
    expect(parseEntityUri('not-a-uri')).toBeNull();
  });
});

describe('internalClassFor (spec §2.2 table)', () => {
  it('fonds/series records are record_set', () => {
    expect(internalClassFor('record', 'fonds')).toBe('record_set');
    expect(internalClassFor('record', 'series')).toBe('record_set');
  });
  it('everything else is record', () => {
    expect(internalClassFor('record', 'item')).toBe('record');
    expect(internalClassFor('work', 'artwork_record')).toBe('record');
    expect(internalClassFor('component', 'component')).toBe('record');
    expect(internalClassFor('supporting', null)).toBe('record');
  });
});
```

- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/core test -- uri` / Expected: FAIL
- [ ] **Step 3: 구현** — `packages/core/src/uri.ts`:

```ts
export const ENTITY_TYPES = ['node', 'agent', 'event', 'term', 'instantiation'] as const;
export type EntityType = (typeof ENTITY_TYPES)[number];

export function buildEntityUri(type: EntityType, uuid: string): string {
  return `/id/${type}/${uuid}`;
}

export function parseEntityUri(uri: string): { type: EntityType; uuid: string } | null {
  const m = /^\/id\/([a-z]+)\/([^/]+)$/.exec(uri);
  if (!m) return null;
  if (!(ENTITY_TYPES as readonly string[]).includes(m[1])) return null;
  return { type: m[1] as EntityType, uuid: m[2] };
}

export type InternalClass = 'record_set' | 'record';
export function internalClassFor(
  kind: 'work' | 'component' | 'record' | 'supporting',
  levelMachine: string | null,
): InternalClass {
  if (kind === 'record' && (levelMachine === 'fonds' || levelMachine === 'series')) return 'record_set';
  return 'record';
}
```

`packages/core/src/index.ts`에 `export { ENTITY_TYPES, buildEntityUri, parseEntityUri, internalClassFor } from './uri.js'; export type { EntityType, InternalClass } from './uri.js';`

- [ ] **Step 4: 통과 확인** — Run: `pnpm --filter @binder/core test -- uri` / Expected: PASS
- [ ] **Step 5: DB 백필 실패 테스트** — `packages/db/test/uuid-backfill.test.ts`

```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { isNotNull } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { agents, terms } from '../src/schema/index.js';
import { seedTaxonomies } from '../src/seed/seed-taxonomies.js';

const { db, sql } = testDb();
beforeAll(async () => {
  await truncate(sql, 'agents', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  await db.insert(agents).values({ kind: 'person', name: { ko: '백남준' }, slug: 'test-paik' });
});
afterAll(() => sql.end());

describe('uuid backfill', () => {
  it('agents and terms rows get uuids automatically', async () => {
    const [a] = await db.select().from(agents);
    expect(a.uuid).toMatch(/^[0-9a-f-]{36}$/);
    const someTerms = await db.select().from(terms).where(isNotNull(terms.uuid)).limit(5);
    expect(someTerms.length).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 6: 스키마 수정 + 마이그레이션** — agents에 `uuid: uuid('uuid').notNull().unique().defaultRandom()`, terms에 동일 + `parentTermId: integer('parent_term_id').references((): AnyPgColumn => terms.id, { onDelete: 'set null' })`(자기참조는 기존 reciprocalTermId 패턴). Run: `pnpm --filter @binder/db db:generate`(0022 SQL에서 `ADD COLUMN "uuid" uuid DEFAULT gen_random_uuid() NOT NULL` 형태 확인 — drizzle이 두 문장으로 쪼개면 수동 병합 금지, 생성된 그대로 적용하되 NOT NULL 순서가 틀리면 defaultRandom이 먼저인지 확인) → `pnpm --filter @binder/db db:migrate` / Expected: 적용·자동 백필
- [ ] **Step 7: 통과 확인** — Run: `pnpm --filter @binder/db test -- uuid-backfill` / Expected: PASS
- [ ] **Step 8: 커밋** — `git commit -m "feat(db+core): entity uuids, skos parent, uri + internal-class helpers"`

---

### Task 3: 어휘 레지스트리 (vocabularies + vocab_mappings + 시드)

**Files:**
- Create: `packages/db/src/schema/vocabularies.ts`
- Modify: `packages/db/src/schema/index.ts`
- Create: `packages/db/src/seed/vocabulary-data.ts`
- Create: `packages/db/src/seed/seed-vocabularies.ts`
- Modify: `packages/db/scripts/seed.ts` (seedVocabularies 호출 추가)
- Create: `packages/db/src/queries/vocabularies.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/vocabularies.test.ts`

**Interfaces:**
- Produces:
  - `vocabularies(id, prefix text NOT NULL UNIQUE, namespaceUri text NOT NULL, name jsonb I18nText, description jsonb, createdAt)`
  - `mappingSubjectEnum = pgEnum('mapping_subject', ['class','property','relation_type'])`
  - `vocabMappings(id, subjectKind mappingSubjectEnum NOT NULL, subjectKey text NOT NULL, vocabularyId FK→vocabularies [restrict] NOT NULL, localName text NOT NULL, createdAt)` + `uniqueIndex('vocab_mappings_subject_uq').on(subjectKind, subjectKey, vocabularyId, localName)`
  - 쿼리: `listVocabularies(db)`, `createVocabulary(db, input, actorId)`(audit), `listMappingsFor(db, subjectKind, subjectKey): Promise<Array<{ prefix: string; namespaceUri: string; localName: string }>>`, `listAllMappings(db)`(Task 7이 소비), `addMapping(db, input, actorId)`(audit), `removeMapping(db, id, actorId)`(하드 delete + rowSnapshot 감사)
  - `seedVocabularies(db)` — 멱등(prefix onConflictDoNothing / 매핑 unique onConflictDoNothing)

- [ ] **Step 1: 시드 데이터 작성** — `packages/db/src/seed/vocabulary-data.ts`:

```ts
export const VOCABULARY_SEEDS = [
  { prefix: 'rico', namespaceUri: 'https://www.ica.org/standards/RiC/ontology#', name: { ko: 'RiC-O', en: 'Records in Contexts Ontology' } },
  { prefix: 'la', namespaceUri: 'https://linked.art/ns/terms/', name: { ko: 'Linked Art', en: 'Linked Art' } },
  { prefix: 'skos', namespaceUri: 'http://www.w3.org/2004/02/skos/core#', name: { ko: 'SKOS', en: 'SKOS' } },
  { prefix: 'schema', namespaceUri: 'https://schema.org/', name: { ko: 'Schema.org', en: 'Schema.org' } },
  { prefix: 'foaf', namespaceUri: 'http://xmlns.com/foaf/0.1/', name: { ko: 'FOAF', en: 'FOAF' } },
  { prefix: 'dcterms', namespaceUri: 'http://purl.org/dc/terms/', name: { ko: 'DCMI Terms', en: 'DCMI Metadata Terms' } },
  { prefix: 'prov', namespaceUri: 'http://www.w3.org/ns/prov#', name: { ko: 'PROV-O', en: 'PROV Ontology' } },
] as const;

// 내부 클래스(7종) → 외부 어휘 (스펙 §2.7. 1:N 허용)
export const MAPPING_SEEDS: Array<{ subjectKind: 'class' | 'property' | 'relation_type'; subjectKey: string; prefix: string; localName: string }> = [
  { subjectKind: 'class', subjectKey: 'record_set', prefix: 'rico', localName: 'RecordSet' },
  { subjectKind: 'class', subjectKey: 'record', prefix: 'rico', localName: 'Record' },
  { subjectKind: 'class', subjectKey: 'record', prefix: 'schema', localName: 'CreativeWork' },
  { subjectKind: 'class', subjectKey: 'instantiation', prefix: 'rico', localName: 'Instantiation' },
  { subjectKind: 'class', subjectKey: 'agent_person', prefix: 'rico', localName: 'Person' },
  { subjectKind: 'class', subjectKey: 'agent_person', prefix: 'foaf', localName: 'Person' },
  { subjectKind: 'class', subjectKey: 'agent_person', prefix: 'schema', localName: 'Person' },
  { subjectKind: 'class', subjectKey: 'agent_organization', prefix: 'rico', localName: 'CorporateBody' },
  { subjectKind: 'class', subjectKey: 'agent_organization', prefix: 'foaf', localName: 'Organization' },
  { subjectKind: 'class', subjectKey: 'event', prefix: 'rico', localName: 'Event' },
  { subjectKind: 'class', subjectKey: 'concept', prefix: 'skos', localName: 'Concept' },
  { subjectKind: 'property', subjectKey: 'has_instantiation', prefix: 'rico', localName: 'hasOrHadInstantiation' },
  { subjectKind: 'property', subjectKey: 'is_derived_instantiation_of', prefix: 'rico', localName: 'isDerivedInstantiationOf' },
  { subjectKind: 'relation_type', subjectKey: 'has_part', prefix: 'rico', localName: 'hasOrHadPart' },
  { subjectKind: 'relation_type', subjectKey: 'has_part', prefix: 'dcterms', localName: 'hasPart' },
  { subjectKind: 'relation_type', subjectKey: 'has_format', prefix: 'dcterms', localName: 'hasFormat' },
  { subjectKind: 'relation_type', subjectKey: 'has_version', prefix: 'dcterms', localName: 'hasVersion' },
  { subjectKind: 'relation_type', subjectKey: 'references', prefix: 'dcterms', localName: 'references' },
  { subjectKind: 'relation_type', subjectKey: 'requires', prefix: 'dcterms', localName: 'requires' },
  { subjectKind: 'relation_type', subjectKey: 'replaces', prefix: 'dcterms', localName: 'replaces' },
];
```

- [ ] **Step 2: 실패 테스트** — `packages/db/test/vocabularies.test.ts`

```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { seedVocabularies } from '../src/seed/seed-vocabularies.js';
import { listMappingsFor, listVocabularies } from '../src/queries/vocabularies.js';

const { db, sql } = testDb();
beforeAll(async () => {
  await truncate(sql, 'vocab_mappings', 'vocabularies', 'audit_log');
  await seedVocabularies(db);
  await seedVocabularies(db); // 멱등성
});
afterAll(() => sql.end());

describe('vocabulary registry', () => {
  it('seeds 7 vocabularies idempotently', async () => {
    const vocabs = await listVocabularies(db);
    expect(vocabs.map((v) => v.prefix).sort()).toEqual(['dcterms', 'foaf', 'la', 'prov', 'rico', 'schema', 'skos']);
  });
  it('maps internal record class to rico + schema', async () => {
    const m = await listMappingsFor(db, 'class', 'record');
    expect(m).toEqual(expect.arrayContaining([
      expect.objectContaining({ prefix: 'rico', localName: 'Record' }),
      expect.objectContaining({ prefix: 'schema', localName: 'CreativeWork' }),
    ]));
  });
});
```

- [ ] **Step 3: 실패 확인** — Run: `pnpm --filter @binder/db test -- vocabularies` / Expected: FAIL
- [ ] **Step 4: 스키마 작성 + 마이그레이션** — Interfaces 명세 그대로 + `schema/index.ts` export. Run: `pnpm --filter @binder/db db:generate`(SQL 검토) `&& pnpm --filter @binder/db db:migrate` / Expected: 0023
- [ ] **Step 5: 시드·쿼리 구현** — `seed-vocabularies.ts`: prefix upsert 후 매핑 insert(onConflictDoNothing). `queries/vocabularies.ts`: Interfaces 명세대로, 변이는 writeAudit 동반. `scripts/seed.ts`에 `await seedVocabularies(db)` 추가. `src/index.ts` barrel export.
- [ ] **Step 6: 통과 확인** — Run: `pnpm --filter @binder/db test -- vocabularies` / Expected: PASS
- [ ] **Step 7: 커밋** — `git commit -m "feat(db): vocabulary registry + class/property/relation mappings (D6)"`

---

### Task 4: instantiations 스키마 + role 어휘 + 쿼리

**Files:**
- Create: `packages/db/src/schema/instantiations.ts`
- Modify: `packages/db/src/schema/index.ts`
- Modify: `packages/db/src/seed/taxonomy-data.ts` (instantiation_role 택소노미 추가)
- Create: `packages/db/src/queries/instantiations.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/instantiations.test.ts`

**Interfaces:**
- Produces:
  - `instantiationCarrierEnum = pgEnum('instantiation_carrier', ['physical','digital'])`
  - `instantiations(id serial PK, uuid uuid NOT NULL UNIQUE defaultRandom, recordId int FK→nodes.id [restrict] NOT NULL, roleTermId int FK→terms.id [restrict] NOT NULL, carrier NOT NULL, aipId int FK→aips.id [set null], derivedFromInstantiationId int self-FK [set null], description jsonb I18nText, sourceCulture text default 'ko', migratedFromNodeId int FK→nodes.id [set null], deletedAt, deletedBy FK→users.id, createdAt, updatedAt)`
  - 인덱스: `instantiations_record_ix(recordId)`, `instantiations_derived_ix(derivedFromInstantiationId)`, `instantiations_aip_live_uq` 부분 유니크 `(aipId) WHERE aip_id IS NOT NULL AND deleted_at IS NULL`
  - 택소노미 `instantiation_role` 텀 14종(machine): `original, preservation_master, exhibition_copy, access_copy, thumbnail, duplicating_copy, research_copy, artist_verified_proof, artist_supplied_master, artist_supplied_package, production_proof, supporting_document, microfilm_copy, equipment`
  - 쿼리(전부 tx+audit, entityUuid 전달): `createInstantiation(db, input: CreateInstantiationInput, actorId): Promise<InstantiationRow>`, `updateInstantiation(db, id, patch, actorId)`, `softDeleteInstantiation(db, id, actorId)`, `listInstantiationsForRecord(db, recordId): Promise<InstantiationRow[]>`, `getDerivationChain(db, id): Promise<InstantiationRow[]>`(파생→원본 상향 체인, 방문 집합 사이클 가드 최대 32단)
  - `CreateInstantiationInput = { recordId: number; roleTermMachine: string; carrier: 'physical' | 'digital'; aipId?: number; derivedFromInstantiationId?: number; description?: I18nText; migratedFromNodeId?: number }`
  - `InstantiationRow` = 테이블 select 타입(uuid 포함) — barrel에서 type export

- [ ] **Step 1: 실패 테스트** — `packages/db/test/instantiations.test.ts`

```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { eq } from 'drizzle-orm';
import { testDb, truncate } from './helpers.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { collectionProfiles, auditLog } from '../src/schema/index.js';
import { createProfileVersion } from '../src/queries/profiles.js';
import { createNode } from '../src/queries/nodes.js';
import { createInstantiation, getDerivationChain, listInstantiationsForRecord, softDeleteInstantiation } from '../src/queries/instantiations.js';

const { db, sql } = testDb();
let recordId: number;
beforeAll(async () => {
  await truncate(sql, 'instantiations', 'nodes', 'audit_log', 'terms', 'taxonomies', 'collection_profiles', 'collection_profile_versions');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  await createProfileVersion(db, p.id, {}, null);
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  // createNode의 실제 CreateNodeInput 시그니처를 queries/nodes.ts에서 확인해 필수 필드를 채울 것
  const node = await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, title: { ko: '테스트 작품' }, slug: 'inst-test-work' } as never, null);
  recordId = node.id;
});
afterAll(() => sql.end());

describe('instantiations', () => {
  it('creates master → access → thumbnail derivation chain', async () => {
    const master = await createInstantiation(db, { recordId, roleTermMachine: 'preservation_master', carrier: 'digital' }, null);
    const access = await createInstantiation(db, { recordId, roleTermMachine: 'access_copy', carrier: 'digital', derivedFromInstantiationId: master.id }, null);
    const thumb = await createInstantiation(db, { recordId, roleTermMachine: 'thumbnail', carrier: 'digital', derivedFromInstantiationId: access.id }, null);
    const chain = await getDerivationChain(db, thumb.id);
    expect(chain.map((c) => c.id)).toEqual([thumb.id, access.id, master.id]);
    expect(await listInstantiationsForRecord(db, recordId)).toHaveLength(3);
  });
  it('soft delete hides from list and audits with entityUuid', async () => {
    const [inst] = await listInstantiationsForRecord(db, recordId);
    await softDeleteInstantiation(db, inst.id, null);
    expect(await listInstantiationsForRecord(db, recordId)).toHaveLength(2);
    const audits = await db.select().from(auditLog).where(eq(auditLog.tableName, 'instantiations'));
    expect(audits.some((a) => a.action === 'delete' && a.entityUuid === inst.uuid)).toBe(true);
  });
  it('rejects unknown role term', async () => {
    await expect(createInstantiation(db, { recordId, roleTermMachine: 'nope', carrier: 'digital' }, null)).rejects.toThrow();
  });
});
```

- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- instantiations` / Expected: FAIL
- [ ] **Step 3: taxonomy-data.ts에 instantiation_role 추가** (TAXONOMY_SEEDS 배열 끝):

```ts
{
  machine: 'instantiation_role',
  name: { ko: '구현체 역할', en: 'Instantiation roles' },
  terms: [
    { machine: 'original', name: { ko: '원본', en: 'Original' } },
    { machine: 'preservation_master', name: { ko: '보존용 마스터', en: 'Preservation master' } },
    { machine: 'exhibition_copy', name: { ko: '전시용 사본', en: 'Exhibition copy' } },
    { machine: 'access_copy', name: { ko: '열람용 사본', en: 'Access copy' } },
    { machine: 'thumbnail', name: { ko: '썸네일', en: 'Thumbnail' } },
    { machine: 'duplicating_copy', name: { ko: '복제용 사본', en: 'Duplicating copy' } },
    { machine: 'research_copy', name: { ko: '연구용 사본', en: 'Research copy' } },
    { machine: 'artist_verified_proof', name: { ko: '작가 검수 프루프', en: 'Artist verified proof' } },
    { machine: 'artist_supplied_master', name: { ko: '작가 제공 마스터', en: 'Artist supplied master' } },
    { machine: 'artist_supplied_package', name: { ko: '작가 제공 패키지', en: 'Artist supplied package' } },
    { machine: 'production_proof', name: { ko: '제작 프루프', en: 'Production proof' } },
    { machine: 'supporting_document', name: { ko: '지원 문서', en: 'Supporting document' } },
    { machine: 'microfilm_copy', name: { ko: '마이크로필름 사본', en: 'Microfilm copy' } },
    { machine: 'equipment', name: { ko: '전시 장비', en: 'Equipment' } },
  ],
},
```

- [ ] **Step 4: 스키마 작성 + 마이그레이션** — Interfaces 명세대로. 자기참조 FK는 `(): AnyPgColumn => instantiations.id` 패턴. Run: `pnpm --filter @binder/db db:generate`(SQL 검토) `&& pnpm --filter @binder/db db:migrate` / Expected: 0024
- [ ] **Step 5: 쿼리 구현** — createInstantiation은 tx로 (a) `findTermId(db,'instantiation_role',roleTermMachine)` (b) recordId가 live node인지 확인(아니면 throw) (c) derivedFrom 존재 확인 (d) insert + writeAudit(action 'insert', entityUuid=uuid). update/softDelete는 updateNode 패턴(before 조회→변경→audit). getDerivationChain은 derivedFromInstantiationId 상향 루프.
- [ ] **Step 6: 통과 확인** — Run: `pnpm --filter @binder/db test -- instantiations` && 전체 `pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 7: 커밋** — `git commit -m "feat(db): instantiations table + role vocabulary + queries"`

---

### Task 5: relation_types + entity_relations 스키마·쿼리 + 삭제 연쇄

**Files:**
- Create: `packages/db/src/schema/entity-relations.ts`
- Modify: `packages/db/src/schema/index.ts`
- Create: `packages/db/src/seed/relation-type-data.ts`
- Create: `packages/db/src/seed/seed-relation-types.ts`
- Modify: `packages/db/scripts/seed.ts` (seedRelationTypes 호출 추가)
- Create: `packages/db/src/queries/entity-relations.ts`
- Modify: `packages/db/src/queries/nodes.ts` (softDeleteNode에 관계 연쇄 소프트 삭제 연결)
- Modify: `packages/db/src/queries/agents.ts` (softDeleteAgent 동일)
- Modify: `packages/db/src/queries/instantiations.ts` (softDeleteInstantiation 동일)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/entity-relations.test.ts`

**Interfaces:**
- Produces:
  - `relationOriginEnum = pgEnum('relation_origin', ['user','extraction','import'])`, `entityTypeEnum = pgEnum('entity_type', ['node','agent','event','term','instantiation'])`
  - `relationTypes(id serial PK, uuid uuid NOT NULL UNIQUE defaultRandom` — **스펙 §2.1: 관계유형도 uuid 필수**`, machineName text NOT NULL UNIQUE, labelForward jsonb I18nText NOT NULL, labelInverse jsonb I18nText NOT NULL, sourceKinds jsonb string[] default [], targetKinds jsonb string[] default [], description jsonb, position int default 0, createdAt)` — URI 발행(`/id/relation-type/{uuid}`)은 P3.2에서; ENTITY_TYPES에는 미포함(관계의 끝점이 아니므로)
  - `entityRelations(id serial PK, sourceType entityTypeEnum NOT NULL, sourceId int NOT NULL, targetType entityTypeEnum NOT NULL, targetId int NOT NULL, relationTypeId FK→relationTypes [restrict] NOT NULL, startDate date, endDate date, certainty text, note jsonb I18nText, origin relationOriginEnum NOT NULL default 'user', evidence text, deletedAt, deletedBy, createdAt)`
  - 인덱스: `entity_relations_source_ix(sourceType, sourceId)`, `entity_relations_target_ix(targetType, targetId)`, 부분 유니크 `entity_relations_untimed_uq (sourceType, sourceId, targetType, targetId, relationTypeId) WHERE deleted_at IS NULL AND start_date IS NULL AND end_date IS NULL`
  - 타입·에러(모두 `queries/entity-relations.ts`에서 export, barrel 재수출):

```ts
export type CreateRelationInput = {
  sourceType: EntityType; sourceId: number;
  targetType: EntityType; targetId: number;
  relationTypeMachine: string;           // machineName→id 해석은 createRelation 내부
  startDate?: string; endDate?: string;  // 'YYYY-MM-DD'
  certainty?: string; note?: I18nText; evidence?: string;
};
export type RelationView = {
  id: number; direction: 'outgoing' | 'incoming';
  relationTypeMachine: string; label: I18nText;   // outgoing=labelForward, incoming=labelInverse
  otherType: EntityType; otherId: number;
  startDate: string | null; endDate: string | null; origin: 'user' | 'extraction' | 'import';
};
export class DuplicateRelationError extends Error {}
```

  - 쿼리: `createRelation(db, input, actorId, opts?: { origin?: 'user'|'extraction'|'import'; actorKind?: 'user'|'ai'|'workflow'; actorRef?: string }): Promise<{ row: EntityRelationRow; warnings: string[] }>`(존재 검증 app-level: 엔터티 종류별 live 행 확인; 도메인/레인지 힌트 위반은 저장 허용+warnings; 23505→DuplicateRelationError), `softDeleteRelation(db, id, actorId)`, `softDeleteRelationsForEntity(db, type, id, actorId, opts?: { jobRef?: string }): Promise<number>`(해당 엔터티가 source/target인 live 관계 전부 소프트 삭제+audit — **스펙 §2.3 'cascade 금지' 이행**), `listRelationsForEntity(db, type, id): Promise<{ outgoing: RelationView[]; incoming: RelationView[] }>`, `listRelationTypes(db)`, `createRelationType(db, input, actorId)`, `updateRelationType(db, id, patch, actorId)`(전부 audit)
  - `seedRelationTypes(db): Promise<void>` — 멱등(machineName onConflictDoNothing)
  - **삭제 연쇄 연결**: `softDeleteNode`/`softDeleteAgent`/`softDeleteInstantiation`의 tx 안에서 `softDeleteRelationsForEntity` 호출(각 함수의 기존 시그니처 불변)
  - **LEGACY_RELATION_MAP**(Task 8이 소비): 구 relation_type 텀 12종 → `{ type: string; flip: boolean }`

- [ ] **Step 1: 시드·매핑 데이터** — `relation-type-data.ts`:

```ts
export const RELATION_TYPE_SEEDS = [
  { machine: 'has_part', labelForward: { ko: '~을 포함함', en: 'has part' }, labelInverse: { ko: '~의 일부임', en: 'is part of' } },
  { machine: 'has_format', labelForward: { ko: '~의 포맷을 가짐', en: 'has format' }, labelInverse: { ko: '~의 포맷임', en: 'is format of' } },
  { machine: 'has_version', labelForward: { ko: '~의 버전을 가짐', en: 'has version' }, labelInverse: { ko: '~의 버전임', en: 'is version of' } },
  { machine: 'references', labelForward: { ko: '~을 참조함', en: 'references' }, labelInverse: { ko: '~에 의해 참조됨', en: 'is referenced by' } },
  { machine: 'requires', labelForward: { ko: '~을 필요로 함', en: 'requires' }, labelInverse: { ko: '~에 의해 요구됨', en: 'is required by' } },
  { machine: 'replaces', labelForward: { ko: '~을 대체함', en: 'replaces' }, labelInverse: { ko: '~에 의해 대체됨', en: 'is replaced by' } },
  { machine: 'has_event', labelForward: { ko: '~ 사건이 있음', en: 'has event' }, labelInverse: { ko: '~의 사건임', en: 'is event of' }, sourceKinds: ['node'], targetKinds: ['event'] },
  { machine: 'performed_by', labelForward: { ko: '~이 수행함', en: 'performed by' }, labelInverse: { ko: '~을 수행함', en: 'performs' }, sourceKinds: ['event'], targetKinds: ['agent'] },
] as const;

// 구 relations 12텀 → 신 6유형 (flip=true면 source/target 교환하여 정방향 정규화)
export const LEGACY_RELATION_MAP: Record<string, { type: string; flip: boolean }> = {
  has_part: { type: 'has_part', flip: false },
  is_part_of: { type: 'has_part', flip: true },
  has_format: { type: 'has_format', flip: false },
  is_format_of: { type: 'has_format', flip: true },
  has_version: { type: 'has_version', flip: false },
  is_version_of: { type: 'has_version', flip: true },
  references: { type: 'references', flip: false },
  is_referenced_by: { type: 'references', flip: true },
  requires: { type: 'requires', flip: false },
  is_required_by: { type: 'requires', flip: true },
  replaces: { type: 'replaces', flip: false },
  is_replaced_by: { type: 'replaces', flip: true },
};
```

- [ ] **Step 2: 실패 테스트** — `packages/db/test/entity-relations.test.ts` — 케이스 6개(셋업: truncate 전면 + seedTaxonomies + seedRelationTypes + 노드·에이전트 생성): (a) 시드 멱등 + 8유형 + 각 행 uuid 존재, (b) node→agent 관계 생성·양방향 조회(outgoing.label=labelForward, incoming.label=labelInverse), (c) 무기한 동일 관계 재생성 → DuplicateRelationError; startDate 지정 시 복수 허용, (d) softDeleteRelation 후 목록 제외 + audit 행, (e) 도메인 힌트 위반(performed_by를 node 소스로) → 저장 성공 + warnings 길이 1, (f) **softDeleteNode 하면 그 노드가 낀 live 관계가 함께 소프트 삭제**되고 관계 audit(action delete)이 남는다.
- [ ] **Step 3: 실패 확인** — Run: `pnpm --filter @binder/db test -- entity-relations` / Expected: FAIL
- [ ] **Step 4: 스키마 + 마이그레이션** — Interfaces 명세대로. Run: `pnpm --filter @binder/db db:generate`(SQL 검토) `&& pnpm --filter @binder/db db:migrate` / Expected: 0025
- [ ] **Step 5: 시드·쿼리·삭제 연쇄 구현** — createRelation: tx에서 (a) machineName으로 relationTypes 조회(없으면 throw) (b) source/target 존재 검증(node→nodes live, agent→agents live, event→events live, term→terms, instantiation→instantiations live) (c) 힌트 검사→warnings (d) insert(23505→DuplicateRelationError) (e) writeAudit(origin 매핑: user→'user', extraction/import→'ingest'; actorKind/actorRef/entityUuid 없음 — 관계는 rowId로 충분). softDeleteRelationsForEntity: UPDATE … SET deleted_at WHERE live AND (source= OR target=) RETURNING 후 행별 audit. softDeleteNode/softDeleteAgent/softDeleteInstantiation의 tx 안에 연쇄 호출 추가(기존 동작·시그니처 불변).
- [ ] **Step 6: 통과 확인** — Run: `pnpm --filter @binder/db test -- entity-relations` && 전체 `pnpm --filter @binder/db test` / Expected: PASS
- [ ] **Step 7: 커밋** — `git commit -m "feat(db): relation_types + entity_relations + delete cascade as soft-delete"`

---

### Task 6: events 승격 (1급 엔터티, nodeId 병기)

**Files:**
- Modify: `packages/db/src/schema/agents.ts` (events 테이블 확장 — events가 이 파일에 정의됨)
- Modify: `packages/db/src/queries/events.ts` (setCreationEvent/getCreators 재구현 + audit)
- Modify: `packages/db/test/agents-events.test.ts` (셋업에 seedRelationTypes + 신규 테이블 truncate 추가)
- Modify: `packages/db/test/seed-demo.test.ts` (동일 셋업 보강)
- Modify: `packages/db/src/index.ts` (신규 export 있으면)
- Test: `packages/db/test/events-promotion.test.ts`

**Interfaces:**
- Consumes: Task 5의 `createRelation`, `softDeleteRelationsForEntity`, relation types `has_event`/`performed_by`, `seedRelationTypes`
- Produces:
  - events 컬럼 추가 — `uuid uuid NOT NULL UNIQUE defaultRandom`, `label jsonb I18nText`, `deletedAt`, `deletedBy FK users [set null]`, `updatedAt default now`. `nodeId`·`agentId`는 **유지하고 계속 기입한다(병기)** — 레거시 화면(apps/web raw SQL: detail.ts `e.node_id`, reports.ts `ev.node_id`)과 마이그레이션 대사의 경로 중립성 보장. nullable 완화도 하지 않는다(스키마 변경 최소화).
  - **함수 시그니처 불변**: `setCreationEvent(db, nodeId, input)` — 새 구현: (a) has_event 관계(node→event, event.typeTermId=creation)로 기존 이벤트 탐색 (b) 없으면 events insert(**nodeId 포함**, uuid 자동) + has_event 관계 + agentId 있으면 performed_by 관계 + agentId 병기 (c) 있으면 날짜·agent 갱신(performed_by 관계 교체 + agentId 갱신). 전 분기 writeAudit(테이블 'events', entityUuid=event uuid, jsonbTopLevel: ['dateDisplay']).
  - `getCreators`는 **기존 반환 형태 유지**(구현 전 현재 `queries/events.ts`를 읽고 시그니처·반환 타입 복제): 신규 관계 경로(has_event→performed_by)로 우선 조회, 결과 없으면 레거시 events.nodeId/agentId 직조회 폴백.

- [ ] **Step 1: 실패 테스트** — `packages/db/test/events-promotion.test.ts` — 셋업: 전면 truncate(events, entity_relations, relation_types, nodes, agents, terms, taxonomies, audit_log, collection_profiles, collection_profile_versions) + seedTaxonomies + seedRelationTypes + 노드·에이전트 생성. 케이스 4개: (a) setCreationEvent 신규 → events 행에 uuid·nodeId 둘 다 존재 + has_event/performed_by 관계 각 1행, (b) 다른 agent로 재호출 → 이벤트 행 1개 유지 + performed_by가 새 agent, events.agentId도 갱신, (c) getCreators가 관계 경로로 창작자 반환(기존 반환 형태), (d) 레거시 폴백 — 관계 없이 nodeId/agentId만 채운 이벤트 행을 직접 insert 후 getCreators가 여전히 반환.
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- events-promotion` / Expected: FAIL
- [ ] **Step 3: 스키마 수정 + 마이그레이션** — Run: `pnpm --filter @binder/db db:generate`(SQL 검토: ALTER events ADD uuid/label/deleted_at/deleted_by/updated_at — NOT NULL 변경 없음) `&& pnpm --filter @binder/db db:migrate` / Expected: 0026
- [ ] **Step 4: 쿼리 재구현 + 기존 테스트 셋업 보강** — Interfaces 명세대로 구현. `agents-events.test.ts`·`seed-demo.test.ts` beforeAll에 `seedRelationTypes(db)` 추가 + truncate 목록에 `'entity_relations', 'relation_types'` 추가(seedDemo가 신규 setCreationEvent를 타므로 필수).
- [ ] **Step 5: 통과 확인** — Run: `pnpm --filter @binder/db test` / Expected: 전체 PASS
- [ ] **Step 6: 웹 회귀 확인** — Run: `pnpm --filter @binder/web typecheck && pnpm --filter @binder/web test` / Expected: PASS (getCreators 시그니처 불변 + nodeId 병기라 detail.ts/reports.ts raw SQL도 무변경 동작; 깨지면 쿼리 쪽 위반)
- [ ] **Step 7: 커밋** — `git commit -m "feat(db): promote events to first-class entities (dual-write nodeId + has_event/performed_by)"`

---

### Task 7: 온톨로지 정의서 TTL 발행

**Files:**
- Create: `packages/db/src/queries/ontology.ts`
- Create: `packages/db/scripts/export-ontology.ts`
- Modify: `packages/db/package.json` (script `"export-ontology": "tsx scripts/export-ontology.ts"`)
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/ontology.test.ts`

**Interfaces:**
- Consumes: Task 3 `listVocabularies`/`listAllMappings`, Task 5 `listRelationTypes`, `instantiation_role` 텀
- Produces: `exportOntologyTtl(db, opts?: { baseNs?: string }): Promise<string>` — 기본 네임스페이스 `process.env.BINDER_ONTOLOGY_NS ?? 'https://binder.local/ns#'`. 출력: ① @prefix 선언(binder + 등록 어휘 전부, 각 1회) ② **내부 클래스 7종**(subjectKey→로컬명: record_set→RecordSet, record→Record, instantiation→Instantiation, agent_person→Person, agent_organization→CorporateBody, event→Event, concept→Concept)을 `binder:X a owl:Class ; rdfs:label "…"@ko, "…"@en ; skos:exactMatch <매핑>` 로 ③ relation_types를 `binder:{machine} a owl:ObjectProperty ; rdfs:label(labelForward ko/en) ; skos:exactMatch(매핑)` 로 ④ instantiation_role을 `binder:instantiationRole a skos:ConceptScheme` + 각 텀 `a skos:Concept ; skos:inScheme ; skos:prefLabel @ko/@en` 로 직렬화. 리터럴 이스케이프(따옴표·개행·백슬래시) 포함. 매핑 URI는 같은 prefix면 CURIE, 아니면 `<namespaceUri+localName>`.

- [ ] **Step 1: 실패 테스트** — `packages/db/test/ontology.test.ts` — 셋업: truncate + seedTaxonomies + seedVocabularies + seedRelationTypes. 단언: (a) `@prefix rico:` 와 `@prefix binder:` 포함, (b) `binder:Record a owl:Class` + `skos:exactMatch`에 `rico:Record` 포함, (c) `binder:has_part a owl:ObjectProperty` 포함, (d) `"보존용 마스터"@ko` 포함, (e) 각 prefix 선언 정확히 1회(정규식 카운트).
- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- ontology` / Expected: FAIL
- [ ] **Step 3: 구현** — 문자열 빌더로 충분(외부 RDF 라이브러리 추가 금지 — YAGNI).
- [ ] **Step 4: 통과 확인 + 스크립트** — Run: `pnpm --filter @binder/db test -- ontology && pnpm --filter @binder/db export-ontology > /tmp/binder-ontology.ttl && head -30 /tmp/binder-ontology.ttl` / Expected: PASS + TTL 출력
- [ ] **Step 5: 커밋** — `git commit -m "feat(db): ontology definition TTL export (§2.5)"`

---

### Task 8: 그래프 마이그레이션 — runGraphMigration + 트리아지

**Files:**
- Create: `packages/db/src/migrate-graph/triage.ts` (순수 판정 로직)
- Create: `packages/db/src/migrate-graph/run.ts` (**`runGraphMigration` — Task 9 통합 테스트가 직접 호출하는 계약**)
- Create: `packages/db/scripts/migrate-graph.ts` (얇은 CLI 래퍼: createDb → runGraphMigration → 리포트 stdout+파일 → exit code)
- Modify: `packages/db/package.json` (script `"migrate-graph": "tsx scripts/migrate-graph.ts"`)
- Modify: `packages/db/.gitignore` 또는 리포 .gitignore (`packages/db/migration-reports/`)
- Modify: `packages/db/src/index.ts` (runGraphMigration·Report 타입 export)
- Test: `packages/db/test/graph-triage.test.ts`

**Interfaces:**
- Consumes: Task 4 `createInstantiation`, Task 5 `createRelation`/`DuplicateRelationError`/`LEGACY_RELATION_MAP`, Task 6 events 형태
- Produces — `triage.ts`:

```ts
export const ROLE_LEVEL_TO_ROLE: Record<string, string> = {
  archival_master: 'preservation_master',
  exhibition_format: 'exhibition_copy',
  duplicating_copy: 'duplicating_copy',
  viewing_copy: 'access_copy',
  artist_verified_proof: 'artist_verified_proof',
  research_copy: 'research_copy',
  artist_supplied_master: 'artist_supplied_master',
  artist_supplied_package: 'artist_supplied_package',
  production_proof: 'production_proof',
};
// 명시 잔존(하위 Record 유지) — level 텀 전수 분류(스펙 §2.2 census 요구)
export const KEEP_LEVELS = ['component', 'artwork_record', 'description', 'documentation',
  'miscellaneous', 'supporting_technology_record', 'fonds', 'series', 'file', 'item'] as const;
// 사람 판단 필요 — 유지 + 리포트
export const REVIEW_LEVELS = ['production_materials', 'auxiliary_elements', 'digital_object', 'aip'] as const;
export const PHYSICAL_EQUIPMENT_PARENT_ACCESSIONS = ['211.1993']; // 백남준 — kind=component 자식은 equipment

export type Triage =
  | { action: 'keep' }
  | { action: 'review'; reason: string }
  | { action: 'instantiation'; role: string; carrier: 'physical' | 'digital' };

export function triageComponent(input: { levelMachine: string | null; parentAccession: string | null }): Triage;
// 우선순위: equipment 오버라이드 → ROLE_LEVEL 매핑(carrier 'physical' — DRMC 역할형 컴포넌트는 매체 캐리어)
//          → KEEP_LEVELS → REVIEW_LEVELS → 미열거 텀은 review 폴백(reason: 'unclassified level term')

export function aipRole(origin: 'archivematica' | 'direct', aipTypeMachine: string | null): { role: string; note?: string };
// archivematica → { role: 'preservation_master' }
// direct + supporting_documentation → { role: 'supporting_document' }
// direct + 그 외 → { role: 'preservation_master', note: '직접 업로드 기본값 — 확인 필요' }
```

- Produces — `run.ts`:

```ts
export type GraphMigrationReport = {
  alreadyMigrated: boolean;
  before: { nodesLive: number; nodesByKind: Record<string, number>; aipsLive: number; relationsLive: number; eventsTotal: number };
  after: { nodesLive: number; instantiations: number; importedRelations: number; hasEventRelations: number; performedByRelations: number };
  converted: Array<{ nodeId: number; nodeUuid: string; title: string; role: string; instantiationId: number; instantiationUuid: string }>;
  manualReview: Array<{ kind: string; detail: string; nodeId?: number; aipId?: number }>;
  skippedRelations: Array<{ relationId: number; reason: string }>;
  warnings: string[];
};
export async function runGraphMigration(db: PostgresJsDatabase): Promise<GraphMigrationReport>;
```

- `runGraphMigration` 절차 — **단계 2~6 전체를 `db.transaction` 하나로 감싼다.** 내부에서 createInstantiation/createRelation 등 자체 tx를 여는 함수에는 **바깥 tx를 `tx as unknown as PostgresJsDatabase`로 전달**(drizzle postgres-js는 중첩 transaction을 savepoint로 처리 — 플레인 `db`를 넘기면 별도 커넥션이 열려 원자성 파괴, 절대 금지). 대사 불일치 시 throw → 전체 롤백. 리포트 출력만 커밋 후.
  1. **멱등 가드**: instantiations 행이 1개라도 있으면 `{ alreadyMigrated: true, ... }` 즉시 반환
  2. before 카운트 수집
  3. **components 트리아지**: live component 노드마다 triageComponent(parentAccession은 부모 노드의 accessionNumber) → 'instantiation'이면 createInstantiation({ recordId: node.parentId, role, carrier, migratedFromNodeId: node.id }, null) 후 노드를 **인라인 소프트 삭제**: UPDATE deleted_at + `DELETE FROM search_docs WHERE entity_type='node' AND entity_id=노드id`(softDeleteNode:161 로직 복제 — 검색 유령 방지) + writeAudit(action 'delete', snapshot, jobRef 'graph-migration', entityUuid=node.uuid). 'review'면 유지+manualReview 적재
  4. **AIP → Instantiation**: live aip마다 — 부착 노드가 3에서 전환·삭제됐으면 recordId=그 노드의 parentId + manualReview 적재; 아니면 recordId=aip.nodeId. role=aipRole(aip.origin, aipType의 machineName). createInstantiation({ recordId, roleTermMachine: role, carrier: 'digital', aipId: aip.id }, null). **추가 규칙**: 같은 recordId가 3에서 역할형 component→Instantiation 전환을 받은 경우(예: Lovers — work에 AIP 2건 + archival_master component 공존) manualReview에 `{ kind: 'aip-role-overlap', detail: 'AIP 유래·component 유래 preservation 계열 Instantiation 공존 — 동일 실체 여부 확인' }` 적재
  5. **relations 이관**: live relations 행마다 LEGACY_RELATION_MAP → (type, flip). 참조 노드가 3에서 전환된 경우: 해당 instantiation의 recordId로 재지향하고 manualReview 적재; 재지향 불가(부모 없음)면 skippedRelations 적재 후 건너뜀. createRelation({ sourceType:'node', sourceId, targetType:'node', targetId, relationTypeMachine: type, startDate, endDate, note: description }, null, { origin: 'import' }) — DuplicateRelationError는 skippedRelations로. **구 행은 삭제하지 않음**(레거시 보존)
  6. **events 재표현**: `nodeId가 있고 아직 has_event 관계가 없는` events 행마다(경로 중립 — Task 6 이후 시드는 이미 관계 보유) has_event(node→event) + agentId 있으면 performed_by(event→agent) 생성. DuplicateRelationError는 skip
  7. **대사(같은 tx 안)**: `before.nodesLive === after.nodesLive + converted.length` · `after.instantiations === converted.length + before.aipsLive` · `after.importedRelations === before.relationsLive - skippedRelations.length` · `after.hasEventRelations === creation 이벤트 총수(경로 중립)` · 고아 검사(모든 instantiation.recordId가 live node) — 불일치 시 Error(리포트 요약 포함) throw → 롤백
  8. 커밋 후 리포트 반환. CLI 래퍼는 stdout + `packages/db/migration-reports/graph-migration.json` 기록, 대사 실패 시 exit 1

- [ ] **Step 1: 트리아지 단위 테스트** — `packages/db/test/graph-triage.test.ts`

```ts
import { describe, expect, it } from 'vitest';
import { aipRole, triageComponent } from '../src/migrate-graph/triage.js';

describe('component triage', () => {
  it('maps role-form levels to instantiation with role', () => {
    expect(triageComponent({ levelMachine: 'archival_master', parentAccession: '1250.2011' }))
      .toEqual({ action: 'instantiation', role: 'preservation_master', carrier: 'physical' });
    expect(triageComponent({ levelMachine: 'viewing_copy', parentAccession: null }))
      .toEqual({ action: 'instantiation', role: 'access_copy', carrier: 'physical' });
  });
  it('paik equipment override wins over generic component level', () => {
    expect(triageComponent({ levelMachine: 'component', parentAccession: '211.1993' }))
      .toEqual({ action: 'instantiation', role: 'equipment', carrier: 'physical' });
  });
  it('generic component stays; unlisted terms fall back to review', () => {
    expect(triageComponent({ levelMachine: 'component', parentAccession: '928.2012' })).toEqual({ action: 'keep' });
    expect(triageComponent({ levelMachine: 'production_materials', parentAccession: null }).action).toBe('review');
    expect(triageComponent({ levelMachine: 'never_seen_term', parentAccession: null }).action).toBe('review');
  });
  it('aip role rules', () => {
    expect(aipRole('archivematica', 'artwork_component')).toEqual({ role: 'preservation_master' });
    expect(aipRole('direct', 'supporting_documentation')).toEqual({ role: 'supporting_document' });
    expect(aipRole('direct', null).note).toBeDefined();
  });
});
```

- [ ] **Step 2: 실패 확인** — Run: `pnpm --filter @binder/db test -- graph-triage` / Expected: FAIL
- [ ] **Step 3: triage.ts 구현** — Interfaces 상수·우선순위 그대로.
- [ ] **Step 4: 통과 확인** — Run: `pnpm --filter @binder/db test -- graph-triage` / Expected: PASS
- [ ] **Step 5: run.ts + CLI 래퍼 구현** — Interfaces 절차 1~8 그대로(트랜잭션 경계·savepoint 주의사항 포함). **이 단계에서 dev DB에 실행하지 않는다** — 검증은 Task 9 통합 테스트가 담당.
- [ ] **Step 6: 커밋** — `git commit -m "feat(db): runGraphMigration with component triage + in-tx reconciliation"`

---

### Task 9: 전후 대사 통합 테스트 + dev 적용 + 문서 + 최종 게이트

**Files:**
- Test: `packages/db/test/graph-migration.integration.test.ts`
- Modify: `packages/db/README.md` (마이그레이션 절차 + P3.2 인계 노트)
- Modify: `binder-next/.superpowers/sdd/progress.md` (PLAN 10 COMPLETE 엔트리)

**Interfaces:**
- Consumes: Task 8 `runGraphMigration`/`GraphMigrationReport`, seed 함수 전부

- [ ] **Step 1: 통합 테스트 작성** — `graph-migration.integration.test.ts`. 셋업: **전면 truncate**(seed-demo.test.ts:15 수준 + 신규 테이블: instantiations, entity_relations, relation_types, vocab_mappings, vocabularies, events, audit_log 포함) → seedTaxonomies + seedRoles(필요 시) + seedVocabularies + seedRelationTypes + seedDemo(데모 AIP tar 생성이 파일시스템을 쓰므로 테스트 환경에서 실패하면 seedDemo의 DB 부분만 호출하도록 분리 — 분리 시 seed-demo.ts에 `seedDemoDb(db)` export 추가하고 리뷰 노트에 기록):

```ts
const report = await runGraphMigration(db);
// census 고정값(2026-07-06, seed-demo.ts 기준):
// before: nodes live 31 (work 10, component 17, record 3, supporting 1), aips 17, relations 1
expect(report.before.nodesLive).toBe(31);
expect(report.before.nodesByKind).toEqual({ work: 10, component: 17, record: 3, supporting: 1 });
// 전환: Lovers 3(archival_master/exhibition_format/duplicating_copy) + GF 2(artist_verified_proof/exhibition_format) + Paik 5(equipment) = 10
expect(report.converted).toHaveLength(10);
expect(report.converted.filter((c) => c.role === 'equipment')).toHaveLength(5);
// after: nodes live 21, instantiations = 10 + 17(AIP) = 27
expect(report.after.nodesLive).toBe(21);
expect(report.after.instantiations).toBe(27);
// relations 1건(requires, flip 없음) → import 1건
expect(report.after.importedRelations).toBe(1);
// events: 작품 10 creation — 경로 중립 대사(시드가 이미 관계 생성)
expect(report.after.hasEventRelations).toBe(10);
// Lovers: work 부착 AIP 2건 + archival_master 전환 공존 → aip-role-overlap 수동 검토
expect(report.manualReview.some((m) => m.kind === 'aip-role-overlap')).toBe(true);
// Tetris 3종·Manifestos 4채널은 Record로 잔존
// 멱등: 2회차는 no-op
const second = await runGraphMigration(db);
expect(second.alreadyMigrated).toBe(true);
```

- [ ] **Step 2: 실패 확인 → 조정** — Run: `pnpm --filter @binder/db test -- graph-migration.integration` / Expected: 첫 실행 FAIL 가능 → run.ts를 census와 일치할 때까지 수정. **census 숫자가 틀렸다고 판단되면 임의 조정 금지 — seed-demo.ts를 다시 읽고 근거를 리뷰 노트에 남길 것.**
- [ ] **Step 3: 전체 게이트** — Run: `pnpm -r typecheck && pnpm -r test` / Expected: 전부 그린
- [ ] **Step 4: dev DB 적용(통합 테스트 통과 후에만)** — 순서 고정:

```bash
# 1) 백업 (compose 서비스명은 binder-next/docker/compose.yml에서 확인 — postgres가 아니면 치환)
docker compose -f docker/compose.yml exec postgres pg_dump -U binder binder > /tmp/binder-pre-plan10.sql
# 2) 시드 갱신(instantiation_role·relation_types·vocabularies를 dev에 반영 — 없으면 migrate-graph가 findTermId에서 throw)
pnpm --filter @binder/db db:seed
# 3) e2e 잔재 정리
pnpm --filter @binder/db exec tsx scripts/clean-e2e-records.ts
# 4) 마이그레이션
pnpm --filter @binder/db migrate-graph
# 복원이 필요하면: docker compose -f docker/compose.yml exec -T postgres psql -U binder -d binder < /tmp/binder-pre-plan10.sql
```

Expected: 대사 통과, 리포트 converted 10건(equipment 5) + manualReview에 aip-role-overlap. 리포트를 읽고 이상하면 복원.
- [ ] **Step 5: e2e 회귀** — Run: `pnpm --filter @binder/web test:e2e` / Expected: 13/13 그린. dev DB가 마이그레이션된 상태에서 기존 화면(구 relations 읽는 RelationList, AipList, 상세의 창작자 표시)이 동작해야 함 — 레거시 비삭제+nodeId 병기 원칙 덕분. 실패 시 레거시 파괴 여부부터 의심. **주의**: Lovers 상세에서 소프트 삭제된 role 컴포넌트가 사라져 보이는 것은 정상(전환됨) — e2e가 해당 컴포넌트 텍스트를 단언하고 있으면 그 단언만 Plan 11에서 Instantiation 패널 단언으로 바뀔 예정이므로, 이번엔 해당 단언을 임시 완화하지 말고 **실패 원인을 리뷰 노트에 기록 후 사용자 확인**.
- [ ] **Step 6: 문서 + 원장** — README에 마이그레이션 절차(Step 4의 4단계) + **P3.2 인계 노트**: ① `/id/node/{uuid}` 해석 시 소프트 삭제 + `instantiations.migratedFromNodeId` 역참조가 있으면 해당 instantiation URI로 리다이렉트(스펙 §2.1 URI 영구성), ② aip_type 택소노미는 role로 흡수 — 폐기 예정, ③ RecordSet/Record 판정은 `@binder/core internalClassFor`가 정본. progress.md에 `== PLAN 10 COMPLETE ==` 엔트리(전환 수치·수동검토 항목·백로그).
- [ ] **Step 7: 커밋 + 푸시** — `git add -A && git commit -m "test(db): graph migration reconciliation anchored to census + dev rollout" && git push origin main`

---

## 자기 리뷰 체크 (적대적 리뷰 2렌즈 반영 완료)

- 스펙 커버리지: §2.1(T2 uuid+URI, T5 relation_types.uuid — /id/ 라우트는 P3.2), §2.2(T2 internalClassFor + T4 + T8 트리아지·KEEP/REVIEW 전수), §2.3(T5 — 삭제 연쇄 포함), §2.4(T2 + SKOS 승격 범위 표; exactMatch 저장처는 P3.2), §2.5(T7), §2.6(T6), §2.7(T3), §3.4 전제(T1+T5+T6 — events 감사는 T6 일임), §7(T8+T9). §6 UI·시드 확장 = Plan 11.
- 블로커 해소: events nodeId 병기(이중 기입)로 대사식 경로 중립화 + detail.ts/reports.ts 회귀 원천 차단.
- 실행 계약: runGraphMigration/GraphMigrationReport는 Task 8 산출물(run.ts), CLI는 래퍼. dev DB 변형은 Task 9 Step 4로 일원화(백업·복원 커맨드 명시).
- 트랜잭션: 중첩 tx는 savepoint — 바깥 tx 전달 명시, 대사까지 tx 내부.
- 타입 일관성: CreateRelationInput.relationTypeMachine ↔ Task 8 호출 일치; LEGACY_RELATION_MAP 12키 = census 12텀; instantiation_role 14텀 ⊇ ROLE_LEVEL 9 + equipment + aipRole 2.
