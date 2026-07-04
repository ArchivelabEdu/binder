# Binder Phase 2 데이터 모델 — 리뷰 반영 수정안

PostgreSQL + Drizzle. 4개 렌즈(실제 Binder 충실도 / 혼합 컬렉션 기록 구조 / 쿼리 패턴 / 진화 가능성) 리뷰를 반영했다.

**규모 전제(과설계 경계):** 실기관 운영, 작품 수백~수천 건, 파일 10^5 수준. 모든 denormalization·인덱스 결정은 이 규모에서 정당화되는 것만 채택했다. must-fix는 전부 반영, should-fix는 YAGNI 경계 안에서 반영, consider는 §4(결정 필요) 또는 §6(향후 확장 노트)로 분류했다.

---

## 1. 변경 요약

| # | 영역 | 원안 | 수정 | 근거 렌즈 |
|---|------|------|------|-----------|
| 1 | 스파인 | works / components / supporting_records 3개 테이블 분리 | **`nodes` 단일 자기참조 스파인** (kind + level_term 판별자). 일반 기록물의 다계층 기술(fonds→series→file→item)은 모든 계층이 완전한 기술 단위여야 하는데 2-테이블 분리로는 표현 불가. relations/aips/rights의 다형 FK 문제도 함께 해소 | 기록구조, 쿼리 |
| 2 | 트리 정렬 | nested set 제거(부모 FK+재귀 CTE)만 언급 | 유지하되 **`nodes.position`** 추가. nested set의 lft가 곧 형제 순서 저장소였으므로 제거 시 대체 필수. TMS childComponents 가짜 property도 이것으로 대체 | 충실도 |
| 3 | AIP 연결 | "work/component 연결" (단일 FK 뉘앙스) | **`aips.node_id`(직접 부착점) + `aips.root_node_id`(롤업 루트)** 이중 FK. Binder가 인제스트 시 이중 relation으로 하던 write-time denormalization을 컬럼으로 계승 — browse의 totalSize 파셋/필터가 이 위에 섬 | 충실도, 쿼리 |
| 4 | AIP 분류 | 없음 | **`aips.type_term_id`** (AIP 종류 4종 택소노미: Artwork component/material, Supporting documentation/technology). 재분류 UI의 근거 | 충실도 |
| 5 | AIP 저장 위치 | `aips.ss_location` (SS 참조 컬럼) | **`storage_backends` + `aip_copies`** 분리. aips 자체는 위치 무지 — SS 부재 = am_ss 사본 0행. 복제본·자체 인제스트가 마이그레이션 없이 확장. 클라이언트 URL 프록시(SSRF 패턴) 제거 | 진화 |
| 6 | AIP 메타 | uuid, size만 | + filename, file_count, ingested_by, created_at(METS 기준), **origin/pipeline_uuid/ingest_job_id/mets_location/mets_sha256**. 원본 METS는 블롭 스토리지에 보관하고 DB가 위치·해시를 소유 — ES 소실 시 재파생 불가 문제 해소 | 충실도, 진화 |
| 7 | 파일 특성화 | "AIP 내 파일"만 | 정형 컬럼(puid, mime_type, size, checksum 등) + **파셋 8종 평탄화 컬럼**(format/codec/resolution 등, 인제스트 시 추출) + characterization JSONB(mediainfo/FITS/PREMIS 원본 프로젝션). JSONB 배열 lateral unnest로는 파셋 카운팅 불가 | 충실도, 쿼리 |
| 8 | 권리 | 없음 | **`rights` + `rights_acts` + `node_rights`**. PREMIS rights 계승하되 Binder의 단일 act_id 약점은 계승하지 않음(granted_right 패턴). 실기관 접근·복제·전시 판단의 전제 | 기록구조 |
| 9 | 노트 | 없음 | **`notes`** (타입드: Install/Prep/Storage 코멘트 + 일반, i18n) | 충실도 |
| 10 | 저장 검색/리포트 | 없음 | **`saved_queries`** (kind: search/report, params JSONB). 리포트 8종은 서버측 쿼리 API로 이식(§5.3) | 충실도 |
| 11 | 열람 감사 | audit_log에 암묵 포함 | **`download_log`** 분리 (reason NOT NULL). 다운로드는 읽기이지 변경이 아님 — 대시보드·리포트 2종이 소비 | 충실도, 쿼리 |
| 12 | 변경 감사 | "변경 주체·시점" 행 이벤트 | **필드 단위 diff** (changes JSONB {field:[old,new]}, delete 시에만 전체 스냅샷) + origin(user/sync/ingest) 판별. 외부 동기화 충돌 감지의 근거 데이터 | 진화 |
| 13 | 삭제 시맨틱 | 미정 | **soft delete** (nodes/aips/files에 deleted_at/deleted_by) + fixity·감사 FK는 ON DELETE RESTRICT. 보존 기관에서 픽시티 이력·관리 연속성(chain of custody)은 논리 삭제를 넘어 존속. OAI tombstone 요건도 충족 | 진화, 기록구조 |
| 14 | 프로파일 | collection_profiles 단일 가변 행 | **`collection_profile_versions`** 버전화 + `nodes.profile_version`. 필드 정의에 표준 crosswalk 키(dc/ead/ric) 포함 → 범용 OAI-PMH/DC 익스포터 1개로 충분 | 진화, 기록구조 |
| 15 | 외부 연동 | "어댑터" 의도만, 스키마 없음 | **`metadata_sources` + `node_sources`(+`sync_runs`)**. source_record_id, payload hash, 필드 소유권, 충돌 상태. keymap식 단방향 매핑은 재수확 시 중복·덮어쓰기 입증됨 | 진화 |
| 16 | 인제스트 | AIP 행 직접 생성 | **`ingest_jobs`** + idempotency_key. Binder는 중간 실패 시 고아 행 + 해당 UUID 영구 재인제스트 불가 — 시도당 단일 트랜잭션, 실패 시 재시도, 성공 중복 시 기존 aip_id 반환 | 진화 |
| 17 | 이벤트/제작자 | "creator 연결" 코어 필드 + events 별도 | **creation 이벤트가 단일 진실원(SoT)** — creator는 파생. 날짜는 3형(start/end DATE + date_display i18n) | 충실도 |
| 18 | 객체–텀 연결 | relations(객체-객체)만 | **`node_terms`** 조인 테이블 (분류/부서/컴포넌트유형). 단일값 vs N:M은 §4 결정 필요 3 | 충실도 |
| 19 | TMS 정형 필드 | descriptive JSONB에 암묵 포함 | **정형 컬럼 승격**: accession_number, component_number(인제스트 매칭 키, unique), accessioned_at, date_created/date_collected, thumbnail_url 등. JSONB 경로 의존 매칭·집계 회피 | 충실도, 쿼리 |
| 20 | 검색 | "PostgreSQL FTS" 선언만 | **`search_docs`** (교차 엔티티 텍스트: 제작자명, 부모 작품 제목 포함 — generated tsvector는 자기 행만 참조 가능) + 롤업 컬럼. 한국어 분석기는 §4 결정 필요 1 | 쿼리, 기록구조 |
| 21 | 픽시티 | "Binder 모델 계승" 선언만 | 필드 명기(failures JSONB, session_uuid, started/completed) + **backend_id/external_event_ref로 SS 이벤트 ID 일반화** + N+1 제거 인덱스 2종 | 충실도, 진화, 쿼리 |
| 22 | 퍼머링크 | 없음 | `nodes.slug`, `agents.slug` unique 컬럼 | 충실도 |
| 23 | i18n | "title i18n" 등 단편 언급 | 공통 패턴 확정: JSONB {ko,en} + source_culture 컬럼 + 폴백 규칙, Drizzle 커스텀 타입 1곳 집중. 대상 컬럼 전수 명시(§5.1) | 충실도, 기록구조 |
| 24 | 발행 상태 | 미정 | **의도적 제거**로 기록 (§6) — Binder에서 사실상 항상 Published | 충실도 |

---

## 2. 전체 테이블 목록

총 30개 테이블. `↗`는 FK.

### A. 스파인·프로파일

**`nodes`** — 단일 기술 스파인. 작품·컴포넌트·일반 기록물 계층(fonds/series/file/item)·지원 기술/문서 레코드를 모두 표현. 모든 계층이 완전한 기술 단위.
- `id` PK, `uuid` UNIQUE(OAI 식별자·퍼머링크 기반), `parent_id` ↗nodes (ON DELETE RESTRICT), `position` INT — 인덱스 (parent_id, position)
- `kind` CHECK IN ('work','component','record','supporting') — 거친 판별자, `level_term_id` ↗terms NOT NULL — 서술수준(19종 시드 + 편집 가능)
- `profile_id` ↗collection_profiles + `profile_version` INT (↗collection_profile_versions 쌍) — CHECK (parent_id IS NOT NULL OR profile_id IS NOT NULL): 루트 필수, 하위 상속
- `identifier`, `accession_number`(TMS ObjectNumber — partial UNIQUE WHERE deleted_at IS NULL), `component_number`(AIP 인제스트 매칭 키 — partial UNIQUE), `slug` UNIQUE
- `title` JSONB {ko,en}, `source_culture`, `descriptive` JSONB(프로파일 버전 스키마로 검증), `source_attributes` JSONB(순서 보존 [{label,value}] 배열 — TMS 잡속성, 중복 라벨 허용)
- 날짜: `date_created` DATE, `date_collected` DATE(browse 파셋용 — events에서 유지/파생), `accessioned_at` DATE; 이미지: `thumbnail_url`, `full_image_url`
- 롤업(write-time denorm — 인제스트/AIP 삭제/재분류 시 트랜잭션 내 갱신 + 재계산 커맨드): `total_size_bytes`, `aip_count`, `file_count`, `last_ingested_at`
- `deleted_at`, `deleted_by` ↗users, `created_at`, `updated_at`(인덱스 — OAI selective harvest)
- 프로파일별 허용 자식 레벨 규칙(예: 미디어아트 work→component만, 기록물 fonds→series→file→item)은 profile_versions에 정의, 앱 검증(+선택적 트리거)
- 편의 뷰: `CREATE VIEW works AS SELECT * FROM nodes WHERE parent_id IS NULL AND deleted_at IS NULL`; Drizzle에서 kind/level 기반 discriminated union 헬퍼

**`collection_profiles`** — 프로파일 정체성만 (id, `name` JSONB, `description` JSONB, timestamps). 필드 정의는 버전 테이블로.

**`collection_profile_versions`** — 프로파일 스키마의 불변 버전. `profile_id` ↗collection_profiles, `version` INT — UNIQUE(profile_id, version), `field_schema` JSONB(descriptive 페이로드의 JSON Schema; 필드별 선택 crosswalk 키 `map: {dc, ead, ric}`; i18n 필드 형태 포함), `created_by` ↗users, `created_at`. 프로파일 변경 = 새 버전 insert + 명시적 백필 잡(audit_log 발행) — 기존 데이터 아래 스키마의 in-place 변형 금지. 저장 시 DC 필수 커버리지가 정형 컬럼에서 나오는지 검증.

### B. 택소노미

**`taxonomies`** — 분류·부서·컴포넌트유형·관계유형·AIP 종류·노트타입·미디어타입·서술수준 등. `id`, `machine_name` UNIQUE, `name` JSONB, `source_culture`. 관리 UI에서 편집.

**`terms`** — `id`, `taxonomy_id` ↗taxonomies, `name` JSONB {ko,en}, `source_culture`, `reciprocal_term_id` ↗terms(자기참조, 관계유형 택소노미 한정 — isPartOf↔hasPart 등 역방향 표시용). 시드: 서술수준 19종, AIP 종류 4종(ko 번역 fixture 존재), 노트타입 3종, 관계유형 11종(상호쌍), 분류/부서/컴포넌트유형, 미디어타입.

**`node_terms`** — 객체–텀 N:M 연결(분류·부서·컴포넌트유형·DC 타입). `node_id` ↗nodes, `term_id` ↗terms, PK(node_id, term_id). 부서·컴포넌트유형의 단일값 승격 여부는 §4 결정 필요 3.

### C. 행위자·이벤트·관계·권리·노트

**`agents`** — 작가·기관·권리자(별도 rights_holder 테이블 없이 재사용). `id`, `kind`('person','organization'), `name` JSONB(전거형), `slug` UNIQUE, `history` JSONB, `source_culture`, timestamps.

**`events`** — 제작/수집 등 이벤트, 날짜의 단일 진실원. `id`, `node_id` ↗nodes, `type_term_id` ↗terms, `agent_id` ↗agents NULL, `start_date` DATE NULL, `end_date` DATE NULL, `date_display` JSONB {ko,en}(문화권별 표시 문자열 — 기계값과 병행). **규칙: 제작자(creator)는 type=creation 이벤트에서 파생** — nodes에 creator FK를 두지 않아 이중 저장 배제. 모든 날짜 파셋/히스토그램은 start_date 경유; browse 핫패스만 nodes.date_created/date_collected 유지 컬럼 사용.

**`relations`** — 명시적 객체–객체 관계(지원기술 연결 등). `id`, `subject_node_id` ↗nodes, `object_node_id` ↗nodes, `type_term_id` ↗terms, `description` JSONB, `start_date`/`end_date` NULL. 단일 스파인 덕에 다형 FK 불필요 — 트리의 supporting_tech_count는 subject 인덱스 카운트. 역방향 표시는 terms.reciprocal_term_id 또는 양방향 UNION.

**`rights`** — PREMIS 권리 기술. `id`, `basis_term_id` ↗terms(copyright/license/statute/policy/donor 기증계약), `rights_holder_agent_id` ↗agents, `start_date`, `end_date`, `copyright_status_term_id` ↗terms, `copyright_jurisdiction`, `copyright_status_date`, `statute_citation`, `license_identifier`(CC/KOGL 유형), `notes` JSONB.

**`rights_acts`** — 권리별 허용/불허 행위(granted_right 패턴 — Binder의 행위당 권리 레코드 중복 강제 약점 미계승). `id`, `rights_id` ↗rights (ON DELETE CASCADE), `act_term_id` ↗terms(display/replicate/migrate/disseminate/delete), `restriction` CHECK('allow','disallow','conditional'), `start_date`, `end_date`, `notes` JSONB.

**`node_rights`** — 권리 부착. `node_id` ↗nodes, `rights_id` ↗rights, PK(node_id, rights_id). 접근 제어 체크에 인덱스 친화적인 전용 링크 테이블(relations 재사용보다 단순).

**`notes`** — 타입드 노트. `id`, `node_id` ↗nodes, `type_term_id` ↗terms(노트타입: InstallComments/PrepComments/StorageComments/일반 텍스트 — 3종+1 시드), `content` JSONB {ko,en}, `source_culture`, `user_id` ↗users, `created_at`.

### D. 보존 (AIP·파일·저장·픽시티)

**`aips`** — 보존 패키지의 지적 레코드(위치 무지 — 위치는 aip_copies).
- `id`, `uuid` UNIQUE, `type_term_id` ↗terms NULL (ON DELETE SET NULL — AIP 종류 4종, 재분류 UI)
- `node_id` ↗nodes(직접 부착점: 컴포넌트·작품·지원레코드), `root_node_id` ↗nodes(롤업 루트: 작품/지원레코드 — Binder의 이중 relation을 컬럼으로 대체; totalSize 파셋·부서 파생은 이 컬럼 경유 단일 조인)
- `filename`(전송명), `size_bytes`, `file_count`
- 출처: `origin` CHECK('archivematica','direct') NOT NULL, `pipeline_uuid` NULL, `ingest_job_id` ↗ingest_jobs
- 원본 METS: `mets_location`(블롭 스토리지 키/파일 경로 — 수 MB XML을 행에 넣지 않음), `mets_sha256`. **DB+블롭 스토어가 시스템 오브 레코드; 파싱 결과(files.characterization 등)는 재구축 가능한 프로젝션**
- `ingested_by` ↗users, `created_at`(METS 생성일 기준) — 인덱스 (created_at DESC)(최근 인제스트 대시보드), `deleted_at`, `deleted_by`
- 제거 명시: attachedTo/aipPartOfDepartment 류 비정규화 문자열 — node_id/root_node_id 조인 파생

**`storage_backends`** — 저장 백엔드 레지스트리. `id`, `kind` CHECK('am_ss','local_fs','s3'), `name`, `config` JSONB(base_url, pipeline_uuid, 자격증명 ref), `enabled`. SS는 전역 설정이 아니라 1행.

**`aip_copies`** — AIP 물리 사본. `aip_id` ↗aips, `backend_id` ↗storage_backends, `locator`(SS file UUID / fs 경로 / s3 키), `is_primary` BOOL, `status` CHECK('ok','recovering','lost'), `last_verified_at` — UNIQUE(aip_id, backend_id). 다운로드/복구는 서버측에서 backend 어댑터+locator 해석(클라이언트 URL 수신 금지 — Binder의 자격증명 포워딩 SSRF 패턴 제거). 복제본 = 행 추가, 마이그레이션 불요.

**`files`** — AIP 내 디지털 객체(METS.xml 자체도 1행으로 표현).
- `id`, `aip_id` ↗aips, `root_node_id` ↗nodes(denorm — 파셋의 COUNT(DISTINCT root_node_id), 다운로드 리포트의 부서 그룹핑)
- `original_relative_path`, `filename`, `puid`, `mime_type`, `media_type_term_id` ↗terms, `size_bytes`, `checksum`, `checksum_type`, `last_modified`, `ingested_at`
- 파셋 평탄화 컬럼(인제스트 시 mediainfo 트랙 배열에서 추출 — write-once): `format_name`, `video_codecs` TEXT[], `audio_codecs` TEXT[], `resolution` INT, `bit_depth` INT, `sample_rate` INT, `chroma_subsampling`, `color_space` — btree/GIN 인덱스. 파셋 카운트 = 인덱스드 GROUP BY
- `characterization` JSONB — mediainfo(general/video/audio 트랙 전체)/FITS/PREMIS 이벤트·에이전트 원본 프로젝션. 상세 뷰·videoCharacteristics 리포트(트랙 필드 ~15종 표시)는 여기서
- `derivatives` JSONB — usage별 로컬 경로({master, thumbnail}). DIP 미포함 파일은 파생본 없이 레코드만 존재
- `deleted_at`, `deleted_by`

**`fixity_reports`** — 픽시티 점검 결과(Binder 계승, 필드 명기). `id`, `aip_id` ↗aips (**ON DELETE RESTRICT** — 이력은 삭제를 견딤), `success` BOOL, `message`, `failures` JSONB, `session_uuid` UUID(컬렉션 점검 그룹핑 — 유지 필수), `started_at`, `completed_at`. 인덱스: (aip_id, completed_at DESC), (session_uuid, started_at) — 'AIP별 최근 점검'이 DISTINCT ON 단일 쿼리가 되어 Binder 최악의 N+1 3곳 제거(마이그레이션의 실질 이득으로 설계 문서에 명시).

**`fixity_recoveries`** — 복구 시도. `id`, `aip_id` ↗aips (RESTRICT), `user_id` ↗users NOT NULL, `backend_id` ↗storage_backends NOT NULL, `external_event_ref` TEXT NULL(kind='am_ss'면 SS 복구 이벤트 id; 내부 잡 백엔드는 NULL + 잡 id 링크) — 불투명 SS 정수 하드와이어 일반화, `started_at`, `finished_at`, `success`/`status`.

### E. 인제스트·외부 연동

**`ingest_jobs`** — 인제스트 실행 단위 + 멱등성. `id` UUID PK, `adapter` CHECK('am_dip','direct_upload'), `idempotency_key` TEXT UNIQUE(AM: AIP UUID / 직접 업로드: 콘텐츠 SHA-256), `status` CHECK('received','validating','parsing','writing','indexed','failed'), `attempt` INT, `error` JSONB, `package_ref`, `created_by` ↗users, `started_at`, `finished_at`. 시맨틱: 시도당 모든 DB 쓰기 단일 트랜잭션; 중복 키 + indexed → 기존 aip_id 반환(멱등 성공); + failed → attempt+1 재시도 허용(Binder의 '고아 행 + UUID 영구 차단' 재현 방지).

**`metadata_sources`** — 외부 메타데이터 소스 레지스트리. `id`, `kind` CHECK('omeka_s','atom','rest'), `base_url`, `auth_config_ref`, `default_ownership` CHECK('remote_wins','local_wins','manual'), `sync_cursor`(마지막 성공 high-water mark), `enabled`, timestamps.

**`node_sources`** — 노드별 소스 링크·동기화 상태. `node_id` ↗nodes, `source_id` ↗metadata_sources, `source_record_id`, `source_record_url`, `remote_version`/etag, `remote_payload_hash`, `raw_payload` JSONB(최근 fetch), `last_synced_at`, `sync_status` CHECK('ok','conflict','error','detached'), `field_ownership` JSONB(필드 경로 → 'local'|'remote'), `conflicts` JSONB({field, local, remote} 쌍 — UI 수동 해소) — UNIQUE(source_id, source_record_id), UNIQUE(node_id, source_id). 규칙: 어댑터는 'remote' 소유 필드만 쓰기 가능; remote 소유 필드의 로컬 편집(audit_log로 감지) 후 원격 변경 → sync_status='conflict'. **로컬 생성 노드는 행이 없음 — 로컬 우선이 기본.**

**`sync_runs`** — 수확 실행 이력·커서(AtoM=OAI-PMH from/until, Omeka S=modified-since 페이징의 증분 동기화 전제). `id`, `source_id` ↗metadata_sources, `kind` CHECK('full','incremental'), `cursor`(resumptionToken/타임스탬프/페이지 커서), `stats` JSONB(fetched/created/updated/conflicted), `status` CHECK('running','ok','failed'), `started_at`, `finished_at`, `error` JSONB. ingest_jobs와의 통합 여부는 §4 결정 필요 2.

### F. 검색·저장 쿼리

**`search_docs`** — 교차 엔티티 통합 검색 인덱스(ES 인덱싱이 하던 write-time denorm의 재배치). `entity_type`, `entity_id`, `culture`, `text_content` TEXT, `tsv` tsvector. 인덱스: GIN(tsv)(영문 'english' config), GIN(text_content gin_trgm_ops)(한국어 부분일치·자동완성 — §4 결정 필요 1). 포함 필드: nodes(title, identifier, accession_number, component_number, 제작자명, 컴포넌트는 부모 작품 제목), agents(전거형), aips(uuid, filename), files(original_relative_path, filename) — 자동완성이 AIP UUID·파일 경로까지 커버(Binder 동작 일치). **갱신 트리거 명세 필수**: 노드 저장, 에이전트 개명 → 해당 에이전트 작품 팬아웃, 컴포넌트 재부모화.

**`saved_queries`** — 저장 검색·리포트(Binder saved_query 계승). `id`, `user_id` ↗users, `kind` CHECK('search','report')(택소노미 대신 enum — 2종 고정), `scope`(대상 엔티티: nodes/aips/files), `name`, `description`, `params` JSONB(직렬화 PHP 대신 구조화), timestamps.

### G. 로그·계정

**`download_log`** — 열람(다운로드) 감사 — audit_log와 별도(성격·보존정책 상이). `id`, `target_kind` CHECK('aip','file'), `aip_id` ↗aips NULL, `file_id` ↗files NULL(CHECK: 둘 중 하나 NOT NULL), `user_id` ↗users, `reason` TEXT NOT NULL(최소 10자는 앱 검증), `created_at` — 인덱스 (created_at DESC). 대상 행이 root_node_id를 지니므로 부서/사용자별 리포트가 단일 JOIN+GROUP BY(COUNT(DISTINCT root_node_id), SUM(size)) — Binder의 행당 ES getDocument N+1 제거.

**`audit_log`** — 변경 감사(필드 단위). `id` BIGSERIAL, `table_name`, `row_id`, `action` CHECK('insert','update','delete','restore'), `actor_id` ↗users NULL(NULL=시스템), `origin` CHECK('user','sync','ingest'), `job_ref`(ingest_job/sync_run id), `at`, `changes` JSONB(얕은 diff {field:[old,new]}; descriptive JSONB는 톱레벨 키 단위 diff — 전체 블롭 저장 금지), `row_snapshot` JSONB(action='delete'만) — 인덱스 (table_name, row_id, at DESC). diff는 앱 레이어(Drizzle 래퍼) 계산. node_sources 충돌 감지의 근거.

**`users`** — `id`, `username` UNIQUE, `email` UNIQUE, `password_hash`, `active`, timestamps.
**`roles`** — `id`, `name`, `permissions` JSONB. **`user_roles`** — `user_id` ↗users, `role_id` ↗roles, PK쌍. (RBAC — 원안 유지, Phase 2 인가 설계에서 permissions 어휘 확정.)

---

## 3. 횡단 규칙 (스키마 외 설계 문서 명시 항목)

### 3.1 i18n 공통 패턴
- 형태: JSONB `{ko, en}` + 동반 `source_culture` 컬럼(원문 언어). 키 ⊆ {ko,en} — Zod 가드(+선택적 CHECK).
- 폴백: 요청 언어 → source_culture (Binder의 sourceCulture 우선 조회 계승).
- 대상 전수: nodes.title/descriptive 내 텍스트 필드, taxonomies.name, terms.name, notes.content, events.date_display, relations.description, agents.name/history, rights.notes, collection_profiles.name.
- Drizzle 커스텀 타입/헬퍼 1곳 집중 — 제3 로케일 등장 시 i18n 테이블 마이그레이션이 정의 1개 수정.

### 3.2 소프트 삭제
- 대상: nodes, aips, files (+ 필요 시 agents). 기본 읽기 경로는 `deleted_at IS NULL` 필터; 유일성은 partial unique index.
- 앱의 DELETE = deleted_at UPDATE + audit_log(row_snapshot 포함). 하드 퍼지는 권한 게이트 별도 관리 잡, 자체 감사.
- fixity_reports/fixity_recoveries/audit_log → aips FK는 ON DELETE RESTRICT: 픽시티 이력은 논리 삭제 후에도 존속, 물리 퍼지를 차단.
- OAI-PMH: nodes.uuid + deleted_at이 곧 tombstone(status=deleted) — 별도 테이블 불요.

### 3.3 쿼리 패턴 표준
- **Browse(결과+파셋 12~14종 단일 응답)**: `WITH filtered AS (모든 활성 필터)` + `COUNT(*) FILTER (WHERE ...)`/GROUPING SETS 집계 1~3개 + 페이지드 SELECT. 롤업·평탄화 컬럼 덕에 전부 인덱스드 스칼라 집계 — 6개 browse/report 엔드포인트 공통 패턴으로 문서화.
- **트리 엔드포인트**: 단일 SQL 함수 — 재귀 CTE(nodes.parent_id, position 정렬) + LEFT JOIN LATERAL relation 카운트(supporting_tech_count) + aips/files UNION ALL. 반환 shape: (node_type, id, parent_ref, title, level_term_id, supporting_tech_count, component_number).
- **리포트 8종 이식**(granularIngest/highLevelIngest/fixity/fixityError/generalDownload/amountDownloaded/componentLevel/videoCharacteristics): 서버측 쿼리 엔드포인트 — Phase 2 API 설계 항목. 누적합류(summaryArtworkByDate 등)는 `SUM(...) OVER (ORDER BY ...)` — ES raw-query 핵 대비 순수 개선.
- **AIP 인제스트 매칭**: METS 컴포넌트 번호 → nodes.component_number unique 인덱스 조회(property 검색 대체).

---

## 4. 결정 필요

### 결정 1 — 한국어 전문검색 분석기
PostgreSQL에 내장 한국어 text-search config가 없음('simple'은 공백 토큰화뿐 — 조사/어미·무공백 복합어에 부실). i18n 저장 방식과 무관하게 반드시 정해야 하며, 인덱스 타입과 확장 의존성을 결정함.
- **옵션 A: pg_trgm** — search_docs.text_content에 GIN(gin_trgm_ops). 표준 contrib 확장, 운영 부담 최소, 부분일치 시맨틱(카탈로그 검색에 충분), 자동완성과 인덱스 공유.
- **옵션 B: PGroonga(또는 mecab 기반 textsearch_ko)** — 언어학적 관련도 우수, 운영 의존성 무거움(별도 빌드/패키지, 백업·업그레이드 경로 복잡).
- **권고: A.** 수백~수천 건 규모에서 trigram 부분일치로 충분. 분석기는 search_docs 한 곳 뒤에 숨겨 교체 가능하게 유지 — 검색 품질 요구가 커지면 B로 전환.

### 결정 2 — ingest_jobs와 sync_runs의 통합 여부
둘 다 "외부에서 데이터를 들여오는 실행 단위"이나 필드가 다름(멱등키 vs 수확 커서). Phase 2 코드 작성 전에 확정 필요.
- **옵션 A: 분리 유지(현 수정안)** — 각 테이블 필드가 자명, CHECK/UNIQUE 제약이 단순. 워커/큐 공유는 앱 레벨에서 가능.
- **옵션 B: 단일 `jobs` 테이블 + type 판별자** — 단일 큐·단일 모니터링 UI·단일 재시도 정책. 대신 nullable 필드 다수와 타입별 부분 인덱스 필요.
- **권고: A.** 두 도메인의 상태 기계가 다르고(인제스트: 멱등 재시도 / 수확: 커서 전진) 규모상 통합 큐의 이득이 작음. 잡 모니터링 화면은 UNION 뷰로 충분.

### 결정 3 — 부서·분류·컴포넌트유형 연결: N:M vs 단일값 컬럼
Binder는 object_term_relation N:M이지만 실사용(파셋·리포트·TMS 패널)은 첫 값만 사용.
- **옵션 A: 전부 node_terms N:M** — Binder 데이터 마이그레이션 무손실, 택소노미 추가 시 스키마 불변, UI에서 단일값 강제 가능. 파셋은 조인 1회 추가.
- **옵션 B: 부서·컴포넌트유형은 nodes에 단일 FK 컬럼(department_term_id, component_type_term_id), 분류만 node_terms** — 최다 빈도 쿼리(부서별 집계 5곳+)가 조인 없는 GROUP BY, 제약이 실사용과 일치. 대신 택소노미별 취급이 비대칭.
- **권고: B.** 부서·컴포넌트유형은 사실상 단일값이고 대시보드·리포트의 핫패스. 마이그레이션 시 복수값 발견분은 첫 값 채택 + 나머지 node_terms 보존.

---

## 5. 원안에서 유지된 결정과 이유

1. **PostgreSQL + Drizzle, ES 제거(PostgreSQL 검색)** — 유지. search_docs·롤업·평탄화 컬럼 등 write-time denormalization은 ES 인덱싱 파이프라인이 이미 하던 일의 재배치이지 신규 비용이 아님. 픽시티 N+1, 누적합 등은 SQL이 오히려 단순·정확. 실기관 규모(10^3~10^4 작품, 10^5 파일)에서 인터랙티브 성능 충분.
2. **nested set 제거 → parent FK + 재귀 CTE** — 유지. 트리 깊이가 얕고(작품→컴포넌트→하위; 지원기술이 조금 더 깊은 정도) 쓰기 시 리밸런싱 부재가 이득. 부작용(형제 정렬 소실)만 position 컬럼으로 보완.
3. **'Components'/'AIP' 가짜 중간 노드 제거** — 유지. FK 직접 표현 + UI 그룹핑. Binder 트리 액션도 이미 클라이언트에서 유사 노드를 걸러내고 있어 동작 손실 없음.
4. **JSONB {ko,en} i18n(별도 i18n 테이블 배제)** — 유지. 로케일이 ko/en 2개 고정인 전제에서 조인 제거·원자적 갱신·Drizzle/Zod 타이핑 이득이 명확. Binder의 per-culture 테이블은 ~20개 개방형 문화권 지원용이었음. source_culture 컬럼과 폴백 규칙만 보강(§3.1).
5. **collection_profiles(프로파일별 확장 메타데이터)** — 유지. JSONB descriptive가 필드 가변성을 흡수한다는 원안 의도는 유효 — 구조 가변성(계층)은 흡수 못 하므로 스파인만 통합(nodes)하고, 스키마 표류만 버전화로 보강.
6. **taxonomies/terms 설정 가능 택소노미 + 관리 UI + i18n** — 유지. 시드 목록만 확장(서술수준 19종 외 AIP 종류·노트타입·관계유형 11종 등)하고 reciprocal_term_id 1컬럼 추가.
7. **AM+SS 연동 기본 + 어댑터 하이브리드 여지** — 유지. 단, "여지"를 스키마로 구체화(storage_backends/aip_copies/ingest_jobs.adapter) — 위치·출처가 코드 설정이 아니라 데이터가 되도록.
8. **자체 메타데이터 입력 기본 + 외부(Omeka S/AtoM/REST) 어댑터** — 유지. 로컬 생성 노드는 node_sources 행이 없는 것으로 로컬 우선이 자연 표현됨.
9. **fixity_reports/fixity_recoveries Binder 계승** — 유지. 구체 필드(failures JSONB, session_uuid, storage_service_event_id의 일반화)와 인덱스만 명기.
10. **users/roles RBAC, audit_log 존재 자체** — 유지. audit_log는 형태만 필드 diff로 강화.

---

## 6. 향후 확장 노트 (consider 분류분)

- **발행 상태(publication status): 의도적 제거.** Binder에서 사실상 항상 'Published' — 기능 비중 없음. 초안/발행 워크플로 요구가 생기면 `nodes.publication_status` enum 1컬럼 추가로 충분(설계 문서에 '의도적 제거'로 기록).
- **파셋 카운트 materialized view**: 혼합 컬렉션이 10배 성장하면 §3.3 패턴의 폴백으로 인제스트 시 refresh하는 파셋 카운트 MV 도입 — 쓰기가 배치형이라 저렴.
- **slug 리다이렉트 이력**: URL 변경이 잦아지면 `slug_redirects(old_slug, node_id)` 추가. 현 규모에선 unique 컬럼으로 충분.
- **file_derivatives 테이블 승격**: 파생본 usage 종류가 master/thumbnail을 넘어 늘면 files.derivatives JSONB를 테이블로 승격.
- **OAI-PMH 서빙**: nodes.uuid + updated_at 인덱스 + soft delete tombstone + profile crosswalk로 기반은 완비 — 실제 리포지토리 엔드포인트(oai_repository/oai_harvest 대응물)는 수확 '제공' 요구가 확정될 때 추가.
- **pg_jsonschema CHECK**: descriptive 검증은 앱 레벨(ajv/zod)이 기본; DB 레벨 이중 검증이 필요해지면 확장 도입.
