# Binder Phase 1 — 원본 Binder 부활·전 기능 실동 검증 설계서 (Revival Spike)

2026-07-04 스파이크 실행. *(이 스펙 문서는 2026-07-11 회고로 정식화 — 1차 기록
[SPIKE.md](../../../SPIKE.md)를 Phase 2·3 스펙과 동일 형식으로 정리한 것.)* Phase 2([현대화
재설계](./2026-07-04-binder-phase2-design.md))의 전제가 된 타당성 검증 단계로, 2015년 DRMC
Binder 원본이 실제로 어디까지 가동되는지 측정하고 **go/no-go + 다음 단계 방향**을 도출한다.

## 1. 목표와 요구사항

| 항목 | 결정 |
|---|---|
| 용도 | **타당성 검증 (go/no-go)** — 원본 Binder가 컨테이너에서 실제로 어디까지 부팅·동작하는지 측정 |
| 대상 코드 | fork `ArchivelabEdu/binder`, 브랜치 `qa/0.9.x` (Artefactual + MoMA, deprecated) |
| 스택 | **원본 유지** — PHP/Symfony 1.x/Propel · MySQL · Elasticsearch · gearman·memcached·nginx |
| 접근 | **Archivematica 디커플 + 컨테이너화** — Binder 앱과 직접 데이터 의존만 docker compose, 시드/목업 |
| 로컬 환경 | macOS arm64 → 레거시 amd64 이미지 `platform: linux/amd64` 에뮬레이션 |
| 시간 상자 | go/no-go가 명확해지면 중단. 마일스톤 = 각기 기록된 발견(finding) 단위 |
| 성공 기준 | 로그인 → 대시보드 → 브라우즈/검색(facet) → REST API → AIP 다운로드·fixity·복구 → DIP 인제스트가 **실제로** 동작 |
| 산출물 | go/no-go 판정 + 공수 추정 + 다음 단계(충실 부활 vs 현대 재구축) 권고 |

## 2. 배경 — "dev에서도 안 돈다"

원본 README·설치 gist는 Binder가 **"개발 환경에서조차 동작하려면 추가 개발이 필요"**하다고
명시한다. gist의 모든 apt PPA(elasticsearch.org·webupd8 java·chris-lea node·archivematica
1.3.x)는 현재 전부 소멸해 문서 그대로는 재현 불가. 원 저자가 남긴 ES 1.x 비호환 이슈 목록
(`script.disable_dynamic`, Groovy facet, `setFields` i18n, `arElasticSearchInformationObjectPdo`)
도 있음. **핵심 질문: 이 경고가 지금도 유효한가, 아니면 이후 현대화로 낡았는가.**

## 3. 시스템 맵 (원본 스택)

원 배포 = **Ubuntu 14.04 VM 2대**.

- **`binder` VM (가시 앱, 이 저장소)**: AtoM fork — PHP 5.3.10+ / Symfony 1.x / Propel +
  DRMC 플러그인 `arDrmcPlugin`(AngularJS 프론트) · `arRestApiPlugin` · `arElasticSearchPlugin`.
  데이터/인프라: MySQL 5.5, Elasticsearch 1.5, memcached, gearman, nginx, php5-fpm, Java 8.
  미디어: imagemagick·ghostscript·poppler·ffmpeg. 인증: LDAP(선택).
- **`archivematica` VM (인제스트→AIP)**: Archivematica 1.3.x + Storage Service +
  Elasticsearch 0.90 + ClamAV + FITS + Gearman.
- **프론트엔드** (`plugins/arDrmcPlugin/frontend/`): AngularJS 1.2.28, d3 3.5.5, jquery 2.1,
  rickshaw, grunt 0.4.5 (`npm install && grunt build`).
- **앱 초기화**: `tools:purge` → `binder:bootstrap` → `search:populate`.

## 4. 스파이크 접근 — 디커플·컨테이너화

**결정:** 타당성 검증에서는 **Archivematica를 건너뛴다.** Binder PHP 앱 + 직접 의존(MySQL·
Elasticsearch·memcached)만 docker compose로 세우고 실 인제스트 대신 시드/목업. **근거:** UI
(작품 레코드·context browser·대시보드·검색)는 AtoM/PHP 앱이 서빙하므로 무거운
ClamAV/FITS/SS/MCP 파이프라인 없이도 검증 가능 — 공수당 정보량 최대화.

| # | 마일스톤 | 상태 | 발견 |
|---|---|---|---|
| 0 | 정찰: 스택 맵 + 블로커 문서화 | ✅ | 본 문서·SPIKE.md |
| 1 | PHP 앱 이미지 빌드 (php5.6 + nginx + symfony1 deps) | ✅ | #1 |
| 2 | 컨테이너 부팅; 앱에서 MySQL+ES 도달 | ✅ | #2·#5 |
| 3 | 스키마 로드(`tools:purge`) + `binder:bootstrap` DRMC 시드 | ✅ | #3·#4 |
| 4 | nginx+fpm 서빙; 로그인 + 대시보드 렌더 | ✅ | #6·#7 |
| 5 | 프론트 빌드(node 8 + grunt) → `Binder.{vendor,app}.js` | ✅ | #6 |
| 6 | `search:populate` + 브라우즈 UI (ES) | ✅ | **ES 5.6** #5 |

## 5. 핵심 발견 (findings)

- **#1** `php:5.6-fpm` 베이스는 Debian 9 stretch(≠jessie) → apt를 `archive.debian.org … stretch`로, 패키지명 stretch 기준 정정.
- **#2** ES 1.7의 script 설정 충돌(초기 compose 크래시) — #5 이후 무의미해짐.
- **#3** `ARCHIVEMATICA_SS_*`·`ATOM_DRMC_TMS_URL`이 부트스트랩에서 **하드 필수**
  (`qubitConfiguration.class.php:141` throw) — AM/TMS 부재여도 더미 값으로 통과. php-fpm `clear_env = no` 필요.
- **#4** localhost 서비스 기대 → memcached(`app.yml`)·ES(`search.yml`) override를 엔트리포인트가 생성(둘 다 upstream gitignore).
- **#5 (KEY)** **`qa/0.9.x`는 2014 스택이 아니다.** 이 브랜치는 2019년까지 AtoM 2.5.x대
  코드를 추적 — ES 매핑이 `keyword`/`text`(ES 5+ 타입), 벤더 Elastica도 현대(`Reindex.php`).
  ES 1.7은 매핑 거부(`No handler for type [keyword]`), **ES 5.6.16은 전부 수용 →
  `search:populate`가 262 docs 인덱싱, 0 에러.** 2014 설치 gist(Ubuntu 14.04, ES 0.90/1.5)는
  구세대(`qa/0.8.x`) 문서로 이 브랜치엔 obsolete. 잔여 pre-5.x 호출부 감사 = [es56-audit.md](../../../docs-spike/es56-audit.md).
- **#6** 프론트는 node 8 + `npm install --ignore-scripts`(죽은 `contextify` 우회) + 로컬 `grunt-cli@1.2.0`로 빌드; lint 생략하고 `build-js build-css`.
- **#7** 로그인은 **email 컬럼**으로 인증(`QubitUser::checkCredentials`) → `demo@example.com`으로 로그인. font-awesome 미동봉 → 아이콘 ☒(후속 반입).

## 6. 실동 검증 범위 (전 기능)

**6-1. 앱 코어(디커플 상태).** 로그인 → 대시보드(차트) → 브라우즈/facet 검색 → REST API 인증 →
데모 시드([seed-demo-spec.md](../../../docs-spike/seed-demo-spec.md): 작품 3 + 컴포넌트 + 기술
레코드 + fake AIP) → 작품 뷰 TMS 패널 · context browser 그래프 · AIP 상세/파일 목록 렌더.
전체 UI 워크 + GET API 스윕([api-sweep.md](../../../docs-spike/api-sweep.md)): **59개 GET →
51 OK, 실 코드 버그 0** (8건은 필수 파라미터 검증 또는 데모 데이터 부재 = 실 AM 인제스트
후에만 존재하는 METS/premis·저장 쿼리).

**6-2. 보존 코어(실 Storage Service 연동).** 실제 Archivematica SS v0.24.0을 `ss` compose
서비스로 기동, `ss-bootstrap.sh`가 사용자/API 키·파이프라인 UUID·FS space·transfer/AIP
location·시드 AIP 3종(uncompressed BagIt)을 등록. 검증: Binder `downloadCheck`→`available:true`;
전체 AIP 다운로드 프록시(tar); `fixity-scan.sh`가 SS 실 BagIt 검증→`/api/fixity/<uuid>` 보고
→ 대시보드 fixity 위젯·AIP 페이지 리포트에 라이브 반영. **AIP 복구 end-to-end**: 저장 bag
손상 → SS fixity가 Payload-Oxum으로 탐지 → Binder `POST /api/recover/<report-id>` → SS
RECOVER_REQ → SS 웹 UI 승인 → AR location에서 복원 → `/api/recover/results` 콜백 → 복구
레코드 완료 → 재검증 Success. (AIP 페이지에 Success→Failed×2→복구(APPROVE)→Success 전체 표시.)

**6-3. 인제스트(DIP 업로드, AM 없이).** compose에 `gearmand` + `worker`(`tools:gearman-worker`)
추가. `qtPackageExtractorMETSArchivematicaDIP`에서 역공학한 계약으로 fake DIP 생성
(`build-fake-dip.sh`) → SWORD 예치(`deposit-fake-dip.sh`, AM `upload_qubit.py`와 바이트 동일
헤더) → 302 → gearman 잡 → METS 파싱 → AIP·파일 객체 생성·`ar:100002`(Grosse Fatigue) 부착 →
작품 페이지 AIP 개요·디지털 오브젝트 브라우저에 표시.

## 7. 산출물·아티팩트

- **docs-spike/**: [es56-audit.md](../../../docs-spike/es56-audit.md)(ES5 감사·수정목록) ·
  [seed-demo-spec.md](../../../docs-spike/seed-demo-spec.md)(데모 시더 사양) ·
  [api-sweep.md](../../../docs-spike/api-sweep.md)(GET API 전수) ·
  [phase2-data-model-review.md](../../../docs-spike/phase2-data-model-review.md)(Phase 2 데이터 모델 적대적 리뷰).
- **docker compose**: `app`·`mysql`·`es`·`memcached`·`gearmand`·`worker`·`ss`. 구동: `docker compose up -d --build` → `tools:purge`/`binder:bootstrap`/`search:populate` → `http://localhost:8090/drmc` (demo@example.com / demo).
- **스크립트**: `ss-bootstrap.sh`·`fixity-scan.sh`·`build-fake-dip.sh`·`deposit-fake-dip.sh`.
- **코드 수정**: Elastica 5.x API 4건 픽스·Font Awesome 3.2.1 반입·`binder:seed-demo` 태스크·legacy AtoM 라우트 nginx 404(default-deny; `/drmc`·`/api`·assets만 노출).

## 8. 판정 — **GO**

앱은 Archivematica 디커플 상태에서 **end-to-end로 부팅**: 로그인 → 대시보드 → facet 브라우즈/
검색, REST API 인증, ES 5.6에 262 docs 무오류 인덱싱. 보존 코어(실 SS 연동·fixity·복구)와
인제스트(DIP) 계약까지 실동 검증. 2015년의 "dev에서도 안 돈다" 경고는 대체로 이 브랜치의
2015–2019 현대화 이전(구세대 `qa/0.8.x`) 기준이며 **obsolete**.

## 9. 결론 — Phase 2 전환 결정

충실한 부활(실 Archivematica 포함)은 **가능**함이 입증됐다. 그럼에도 다음 이유로 **현대
재구축(Phase 2)로 피벗**한다 — 4개 언어 스택(PHP+Python+Java+AngularJS)의 장기 유지보수 부담,
그리고 스파이크에서 드러난 구조적 취약(nested set 비정규화 의존, ES가 사실상 system-of-record,
인제스트 중간 실패 시 고아 행, property 매직 문자열).

- **계승할 핵심 개념**(Phase 1에서 실동 검증): FRBR형 계층 기술 모델, context browser(관계 그래프),
  보존 코어(fixity 감사·AIP 다운로드/복구), METS DIP 인제스트, 설정형 택소노미, 리포트.
- **교훈 → Phase 2 반영**: 단일 TS 모노레포 · parent FK+재귀 CTE(nested set 대체) ·
  DB+블롭=진실원/검색 인덱스=재구축 가능한 프로젝션 · `ingest_jobs` 멱등 트랜잭션 · 정형 컬럼/FK.

## 10. 범위 제외 / 잔여

- **실 Archivematica end-to-end**: 계약 양측(SWORD 예치·SS 복구)이 이미 검증돼 **선택**. AM 1.18
  hack compose가 동일 `upload_qubit.py`를 실어 이 검증된 엔드포인트로 연결하면 됨(~2–3.5일).
- **LDAP 실연동**: email 로그인이 이미 동작하므로 보류.
- **legacy AtoM 서버렌더 브라우즈 페이지**: nginx 404 처리(DRMC Angular UI는 미사용). 집계로 포팅은 선택.
- **실데이터 마이그레이션**: 현재 Phase 1은 데모 데이터뿐 → 실데이터 축적 전에 Phase 2로 전환 전제.
