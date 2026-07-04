# Binder Phase 2 — 현대화 재설계 설계서

2026-07-04 확정. Phase 1(원본 Binder 부활·전 기능 실동 검증)의 후속으로, Binder의 철학과
핵심 개념을 TypeScript 스택으로 재구현한다.

## 1. 목표와 요구사항

| 항목 | 결정 |
|---|---|
| 용도 | **실제 기관 운영용** — 안정성·권한·감사·장기 유지보수 중심 |
| 컬렉션 | **혼합** (시간기반 미디어아트 + 일반 기록물) — 서술수준·메타데이터 프로파일 설정 가능 |
| 스택 | **TypeScript 풀스택** (Next.js 15 + PostgreSQL 16) |
| 보존처리 분업 | **Archivematica + Storage Service 연동 기본** (Binder 원 철학 계승), 어댑터로 하이브리드 여지 |
| 운영 환경 | 미정/혼합 가능성 → **환경 독립적 docker compose** |
| 메타데이터 원본 | **자체 입력 기본** + 외부 시스템(Omeka S / AtoM / 상용 REST) 동기화 어댑터 |
| 언어 | 한/영 이중 언어 (UI + 메타데이터) |
| 규모 전제 | 작품 수백~수천 건, 파일 10^5, AIP 수 TB — 모든 성능 결정의 기준 |

**계승하는 Binder 핵심 개념** (Phase 1에서 실동 검증):
FRBR형 계층 기술 모델, context browser(관계 그래프), 보존 코어(fixity 감사·AIP
다운로드/복구), METS DIP 인제스트, 설정형 택소노미, 리포트.

**Binder 사후분석에서 얻은 교훈** (설계에 반영):
- 4개 언어 스택(PHP+Python+Java+AngularJS)의 유지보수 부담 → 단일 TS 모노레포
- nested set 비정규화가 깨지면 UI 전체가 죽음 → parent FK + 재귀 CTE + position
- ES 인덱스가 사실상 시스템 오브 레코드였음(소실 시 재파생 불가 데이터 존재) →
  DB+블롭이 진실원, 검색 인덱스는 재구축 가능한 프로젝션
- 인제스트 중간 실패 시 고아 행 + UUID 영구 차단 → ingest_jobs 멱등 트랜잭션
- property 테이블의 매직 문자열(childComponents 직렬화 등) → 정형 컬럼/FK

## 2. 아키텍처

**A안: 경계 있는 모노레포** (B안 분리형과 비교 후 확정 — B 전환 비용 최소화 조건 포함).

```
binder-next/  (pnpm workspaces + Turborepo)
├── apps/
│   ├── web/        Next.js 15 (App Router) — UI + REST Route Handlers(/api/v1)
│   └── worker/     BullMQ 워커 — fixity 크론, 인제스트, 동기화, SS 폴링
├── packages/
│   ├── core/       도메인 로직 (순수 TS, 프레임워크 무관) ← B 전환 시 그대로 이식
│   ├── db/         Drizzle 스키마 + 마이그레이션 (PostgreSQL 16)
│   └── integrations/  StorageBackend·IngestAdapter·MetadataSource 구현체
└── docker/         compose: web, worker, postgres, redis, (storage-service)
```

| 선택 | 근거 |
|---|---|
| Drizzle ORM | SQL 투명성 — 보존 시스템은 데이터가 자산, 마이그레이션 명시성 필수 |
| BullMQ + Redis | gearman의 현대 대체; 웹 요청과 분리된 장기 실행 잡 |
| PostgreSQL FTS + pg_trgm | 규모상 검색엔진 별도 운영 불요(Phase 1 ES OOM 경험 반영); `search_docs` 뒤에 숨겨 교체 가능 |
| React Flow | context browser의 현대 대체 (d3 수제 구현 → 선언적 그래프) |
| next-intl | 한/영 이중 언어 |

`packages/core`는 Next.js를 모른다 — API 소비자가 급증하면 core를 들고 NestJS 앱만
추가하는 것으로 B(분리형) 전환이 완성된다.

## 3. 데이터 모델

**상세 설계: [docs-spike/phase2-data-model-review.md](../../../docs-spike/phase2-data-model-review.md)**
(4렌즈 적대적 리뷰 42건 반영, 테이블별 컬럼·FK·인덱스 전수 명시). 총 30개 테이블. 요약:

- **`nodes` 단일 자기참조 스파인** — 작품·컴포넌트·일반 기록물 다계층(fonds→series→file→item)·
  지원 레코드를 모두 표현. `kind` + `level_term_id`(설정형 택소노미, Binder 19종 시드) 판별.
  `position`(형제 순서), `slug`(퍼머링크), 롤업 컬럼(total_size_bytes 등 — write-time denorm).
- **프로파일**: `collection_profiles` + **불변 버전**(`collection_profile_versions`) —
  descriptive JSONB의 JSON Schema + 표준 crosswalk(dc/ead/ric) 키. 혼합 컬렉션의 필드
  가변성은 JSONB가, 구조 가변성은 단일 스파인이 흡수.
- **보존**: `aips`(node_id+root_node_id 이중 FK, 원본 METS는 블롭+해시로 보관) /
  `storage_backends`+`aip_copies`(위치 분리 — SS는 백엔드 1행, 복제본·자체 인제스트 확장) /
  `files`(특성화 정형+facet 평탄화 컬럼+characterization JSONB) /
  `fixity_reports`·`fixity_recoveries`(Binder 계승 + SS 이벤트 참조 일반화).
- **기술·맥락**: `taxonomies`/`terms`(i18n, reciprocal), `node_terms`(분류 N:M) +
  부서·컴포넌트유형은 nodes 단일 FK 승격(핫패스), `agents`, `events`(날짜·제작자의 단일
  진실원), `relations`, `rights`/`rights_acts`/`node_rights`(PREMIS 권리), `notes`.
- **운영**: `ingest_jobs`(멱등키), `metadata_sources`/`node_sources`/`sync_runs`(필드
  소유권·충돌 상태·증분 커서), `search_docs`(교차 엔티티 FTS), `saved_queries`,
  `download_log`(사유 필수), `audit_log`(필드 단위 diff), `users`/`roles`/`user_roles`.
- **횡단 규칙**: i18n = JSONB {ko,en} + source_culture + 폴백; soft delete(+fixity 이력
  ON DELETE RESTRICT — 관리 연속성); 발행 상태는 의도적 제거.

확정된 결정 3건: 한국어 검색 = **pg_trgm** / 인제스트·수확 잡 테이블 = **분리 유지** /
부서·컴포넌트유형 = **단일 FK 승격**(복수값 마이그레이션분은 node_terms 보존).

## 4. 통합 레이어 (`packages/integrations`)

- **StorageBackend 인터페이스**: `download(locator)` / `checkFixity(locator)` /
  `requestRecovery(...)`. 구현: `AmSsBackend`(Phase 1 검증 v2 API — `api/v2/pipeline`,
  `api/v2/file/<uuid>/download|check_fixity|recover_aip`, ApiKey 인증), 추후 `LocalFsBackend`.
  클라이언트에 백엔드 URL을 노출하던 Binder의 프록시 패턴 제거 — 서버 측 locator 해석.
- **IngestAdapter 인터페이스**: `am_dip`(METS 파서 — Phase 1에서 역공학·검증한 계약을
  TS로 이식: DIP 디렉토리명 `-<uuid36>`, fileSec UUID prefix 글롭, DC dmdSec, 파일별
  dmdSec 매칭) / 추후 `direct_upload`. 인제스트 = `ingest_jobs` 단위 단일 트랜잭션.
- **MetadataSource 어댑터**: `omeka_s`(REST) / `atom`(OAI-PMH) / `rest`(범용) —
  `sync_runs` 증분 커서, 필드 소유권은 `node_sources` 규칙(로컬 생성 노드 = 행 없음 =
  로컬 우선; remote 소유 필드의 로컬 편집 후 원격 변경 = conflict 상태 → UI 수동 해소).
- **복구 콜백**: `POST /api/v1/recovery-results` — SS의 `recover_request_notification_*`
  설정과 계약 유지(Phase 1 검증: `{event_id, success, message}` + Basic 인증).

## 5. 보존 기능 (`apps/worker`)

- **Fixity 크론**(BullMQ repeatable): 백엔드별 전체 스캔 → `fixity_reports` → 실패 시
  알림(이메일/웹훅). 세션 UUID로 리포지토리 단위 감사 그룹핑(대시보드 위젯 계약 유지).
- **복구 플로우**: UI 요청(실패 리포트에서) → backend.requestRecovery → 외부 승인 →
  콜백 → `fixity_recoveries` 완결. Phase 1에서 손상→감지→복구→재검증 전 사이클 검증됨.
- **AIP 다운로드**: 서버 스트리밍 프록시 + `download_log`(reason NOT NULL) 기록.

## 6. UI (Next.js App Router)

| 화면 | 내용 |
|---|---|
| 대시보드 | fixity 위젯, 최근 인제스트/다운로드, 저장소 통계(Recharts), 컬렉션 합계 |
| 브라우즈·검색 | 엔티티 탭(레코드/AIP/파일/에이전트) + facet 사이드바 — 서버 컴포넌트, URL 상태 |
| 레코드 상세 | 메타데이터 패널(프로파일 기반) + **context browser**(React Flow: 트리+관계+AIP 오버레이) + AIP 목록·파일 브라우저 |
| 리포트 | Binder 8종 이식(서버 쿼리 + CSV) — 누적합은 window function |
| 메타데이터 편집 | 프로파일 스키마 기반 동적 폼(react-hook-form + Zod) — 자체 입력 요건 |
| 관리 | 택소노미·프로파일(버전)·유저/역할·저장 백엔드·메타데이터 소스 CRUD |

브라우즈/facet 쿼리 표준: `WITH filtered AS (...)` + `COUNT(*) FILTER` 패턴 — 데이터
모델의 롤업·평탄화 컬럼 전제.

## 7. 인증·권한

자체 이메일 + Argon2 + DB 세션 쿠키. RBAC 시드 3역할(admin/editor/viewer),
`roles.permissions` JSONB 어휘는 구현 계획에서 확정. 모든 변경은 `audit_log`에 actor
연결. SSO/LDAP은 인터페이스 확장점만 정의(구현 보류).

## 8. 테스트·배포

- **단위**: vitest — `core`(도메인 규칙: 롤업 갱신, 소유권 병합, 멱등성) 집중
- **계약**: METS 파서 ← Phase 1 fake DIP 픽스처; `AmSsBackend` ← 기록된 HTTP 픽스처
  (Phase 1에서 채집한 실제 SS 응답)
- **E2E**: Playwright — 로그인→브라우즈→상세→다운로드(사유)→fixity 위젯 핵심 플로우
- **배포**: docker compose 단일 파일(web/worker/postgres/redis/[storage-service]) —
  온프레미스·클라우드 동일. 마이그레이션은 배포 시 명시 실행(자동 아님).
- **데모 데이터**: Phase 1의 binder:seed-demo 대응 TS 시더(개발·시연·E2E 공용).

## 9. 범위 제외 (명시)

- OAI-PMH **제공** 엔드포인트(기반은 완비 — uuid/updated_at/tombstone/crosswalk), 발행
  상태 워크플로, LDAP/SSO 구현, 파생본 생성(ffmpeg 파이프라인), 대용량 직접 업로드
  (tus), Elasticsearch — 각각 요구 확정 시 확장점에 추가.
- Phase 1 데이터 마이그레이션 도구: 현재 Phase 1은 데모 데이터뿐이므로 불요. 실데이터가
  쌓이기 전에 Phase 2로 전환하는 것을 전제.

## 10. 구현 순서 (개요 — 상세는 구현 계획 문서)

1. 모노레포 스캐폴드 + db 스키마 + 시더 (수직 슬라이스 기반)
2. 코어 CRUD + 브라우즈/검색 + 상세(context browser)
3. 통합: AmSsBackend + fixity 크론 + 다운로드/복구
4. 인제스트: am_dip 어댑터 (fake DIP 픽스처로 TDD)
5. 리포트 + 관리 화면 + 메타데이터 편집 폼
6. 외부 메타데이터 어댑터(1종 — Omeka S 우선) + 동기화
