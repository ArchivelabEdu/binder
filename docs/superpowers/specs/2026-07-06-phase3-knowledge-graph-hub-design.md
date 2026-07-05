# Phase 3 설계 — 지식그래프 관리 계층 + Identity Manager

- 날짜: 2026-07-06
- 상태: 사용자 설계 승인 완료(대화), 문서 리뷰 대기
- 선행: Phase 2 완료 (`2026-07-04-binder-phase2-design.md`, binder-next 구현 완료)
- 참고문서: `uploads/getty/` (GRI 회의록·Linked Data in Production 등 8건), `uploads/국회기록원/` (ISMP·시맨틱 검색·Omeka S 한계·RiC-O 실습·아카이브시스템101·OG-RAG/L1_L2 도식 7건), `uploads/현대자동차/` (이한열 RiC-O 모델링·완료보고서·메타데이터 통합안·화면설계서 8건)

## 0. 확정 결정 (사용자 승인 완료)

| # | 결정 | 근거 |
|---|---|---|
| D1 | Binder의 역할 = **RiC-O 지식그래프 관리 계층 + Identity Manager 모듈 호스팅**. 보존=Archivematica, 카탈로깅=AtoM, 전시·발행=Omeka S와 표준 인터페이스로 느슨 결합 | 세 문서군이 공통으로 가리키는 4단 스택(보존 L1 — IM — 그래프 관리 — 서비스 L2)에서 Omeka S가 한계를 드러낸 슬롯(「Omeka S의 한계」) |
| D2 | 지적 메타데이터 **추출은 Binder 워커**가 오케스트레이션(Archivematica는 기술적 처리만), **OG-RAG 검색은 별도 L2 서비스**(Omeka S는 전시·발행 전담) | 그라운딩이 의존하는 마스터 전거·스키마가 Binder에 있음(국회 붙임1 도식); Getty의 저장·식별·발행 분리 사상 |
| D3 | 파일럿 도메인 = **현 MoMA 데모 확장** (데이터 모델은 도메인 중립) | 기존 자산(10작품·실파일·스크린샷) 재사용, 계층→그래프 마이그레이션 자체가 시연 거리 |
| D4 | 진행 경로 = **접근법 A: 그래프 스파인 우선** (3.1 RiC-O 코어 → 3.2 IM → 3.3 AI 추출 → 3.4 L2 검색) | 의존 순서와 일치, 단계별 시연 가능, 현대차 로드맵 2단 전략·Getty 점진론 부합 |
| D5 | 저장 기술 = **PG가 진실, 그래프는 파생 발행** (Getty LOD 캐시 방식). 트리플 스토어/전용 그래프 DB는 L2에만 등장, v1은 PG로 충분 | Getty가 상용 그래프 DB 대신 LOD 캐시 채택(LOD Gateway); 기존 binder-next 스키마·감사 파이프라인 보존 |

## 1. 포지셔닝과 아키텍처

```
[대량 이기종 입수 SIP]
        ↓
[Archivematica]  포맷 식별·정규화·AIP (기술 메타데이터만)     [AtoM/소스 시스템] 카탈로깅
        ↓ DIP/METS                                          ↓ 수확
┌─────────────────────── Binder — 지식그래프 관리 계층 ───────────────────────┐
│  Identity Manager        AI 추출 파이프라인         RiC-O 그래프 편집        │
│  PID·전거·정합 검수       LLM 추출→그라운딩→검수     기술·관계·SKOS 어휘      │
└──────────────────────────────────────────────────────────────────────────┘
        ↓ 발행(JSON-LD·변경 피드)                  ↓ 발행(메타데이터·접근 사본)
[L2 지식 저장소 → OG-RAG 검색 서비스 → 이용자 검색 UI]      [Omeka S 전시·발행]
```

- Binder는 **편집·식별의 진실 공급원**(system of record for description & identity)이고, 검색·전시는 소비자다.
- Getty와의 대응: Binder ≒ 지식그래프 관리 + Identity Manager. Getty가 분리한 세 역할(저장 L1 / 식별 IM / 발행 L2) 중 IM은 Binder 안에 **아키텍처상 분리된 모듈**로 호스팅하고, L2는 별도 서비스로 둔다.
- Archivematica의 인제스트 체인(Siegfried·JHOVE·정규화·BagIt)은 "이 비트가 무엇이고 어떻게 살려둘 것인가"에만 답한다. "누가 등장하고 어떤 사건과 연결되는가"는 3.3 추출 파이프라인의 몫이다.
- Omeka S는 SPARQL·추론·카디널리티 강제가 불가능하다(「Omeka S의 한계」). 검색은 L2 서비스, 전시는 Omeka S, 그래프 편집은 Binder — 세 곳이 겹치지 않는다.

## 2. 데이터 모델 (하위 프로젝트 3.1)

### 2.1 URI 1급화

- 전 엔터티(기록·구현체·전거·사건·개념·관계유형)에 해석 가능한 URL형 식별자: `/id/{type}/{uuid}`.
- UUID는 기존 스키마의 uuid 컬럼을 승계·보강. ARK/DOI 등 외부 PID 체계는 범위 외(Newbury: "신뢰 기관 간에는 URL로 충분").
- URI는 영구 불변. 병합 시에도 구 URI는 리다이렉트로 영구 유지(§3.3).

### 2.2 Record ↔ Instantiation 분리

- 기존 `nodes`는 **지적 개체 계열로 유지**: fonds → RecordSet, work/record/supporting → Record, component → 하위 Record(표현·부분; `hasOrHadPart` 관계로 연결). MoMA의 component(예: Tetris 3개 expression)는 지적 표현이므로 Record 계열이 맞다. "component" 용어는 온톨로지 표면에서 제거한다(이한열 문서 권고: MoReq식 component는 파일 객체 자리로 격하).
- **`instantiations` 테이블 신설** — 구현체의 1급 엔터티:
  - `record_id`(소속 Record, rico:hasOrHadInstantiation), `role`(SKOS 통제어휘: original / preservation-master / access-copy / thumbnail / exhibition-copy / microfilm-copy 등 — 확장 가능), `carrier`(physical | digital), `derived_from_instantiation_id`(rico:isDerivedInstantiationOf, **파생→원본 방향**), 물리 구현체는 보관 위치·매체 기술, 디지털 구현체는 AIP/파일 연결.
  - "소속"(Record→Instantiation 플랫 연결)과 "계보"(Instantiation 간 파생 엣지)의 **직교** — 이한열 상세 모델(7개 구현체: 인화 원본·전시용 복제본·스캔 마스터 TIFF·열람용 JPG·썸네일 2종·마이크로필름)과 동형.
- 마이그레이션 매핑: AIP → Instantiation(digital, role=preservation-master, 기존 aips 행과 1:1 연결 유지), 프록시/썸네일 파생물 → 파생 Instantiation(derived_from=마스터), 파일 상세(files)는 Instantiation 하위에 그대로(PREMIS object 상당). 물리 원본이 기록된 작품은 physical Instantiation 추가 등록 가능(시더에서 백남준·사진류 시연).

### 2.3 관계 1급 객체

- `relation_types`: 정방향·역방향 라벨 쌍(i18n), RiC-O 속성 URI 매핑(예: rico:hasOrHadPart), 도메인/레인지 힌트(엔터티 종류 목록), 설명. 관리자 CRUD(현대차 PB-24 설계 승계).
- `relations`: Source(엔터티 종류+id) – Type – Target(엔터티 종류+id), 시간 한정자(시작/종료), 확실성/주기(선택), `origin`(user | extraction | import), 근거 출처(선택, 추출분은 필수). 임의 엔터티 간 연결(기록↔기록, 기록↔전거, 전거↔사건 …). 기존 Phase 2 relations 테이블은 이 모델로 확장·이관한다.
- 도메인/레인지는 **경고 수준 소프트 검증**: 저장은 허용하되 UI에서 경고 + 입력 통제(검색 필터가 힌트에 맞는 대상만 우선 제시). OWL 추론기·하드 제약은 범위 외 — Omeka S 한계 문서의 결론("논리 검증은 온톨로지 정의서와 인적 관리로") 반영.

### 2.4 SKOS 어휘

- 기존 택소노미를 SKOS 개념으로 승격: URI, prefLabel(i18n), broader/narrower, 외부 `exactMatch`(AAT 등). 통제값은 리터럴이 아닌 **URI 연결**(Custom Vocab의 "우리 시스템 안에서만 작동하는 그래프" 함정 회피).
- Instantiation role, 관계 한정어 등 신규 통제어휘도 SKOS로.

### 2.5 온톨로지 정의서 발행

- Binder 스키마(클래스·관계유형·어휘)를 **TTL + 사람용 문서**로 자동 발행(export). 스키마 변경마다 갱신. "온톨로지 정의서를 통한 인적 관리가 현 단계 핵심"(국회 문서 결론)의 구현.

## 3. Identity Manager 모듈 (하위 프로젝트 3.2)

### 3.1 식별자 레지스트리

- §2.1의 URI 위에 얹는 조회·해석 계층: `/id/...` 요청 → 엔터티 화면 또는 JSON-LD(콘텐츠 협상은 단순 쿼리 파라미터로 시작).

### 3.2 외부 전거 상호링크

- `entity_identifiers`: 엔터티(주로 전거·개념)별 외부 URI(ULAN·AAT·Wikidata·VIAF·기관 자체), scheme, 검증 상태, 등록 주체. Getty 패턴("Vocabularies URI를 접착제로")의 로컬 구현.
- 편집 UI에서 외부 전거 검색·제안(Value Suggest 상당) — v1은 Wikidata·Getty 공개 API 조회.

### 3.3 Reconciliation 워크벤치

- 중복 후보 배치 탐지(워커): 이름 정규화·연대·외부 식별자 일치 기반 유사도. 자동 병합 금지 — 후보는 전량 **인간 승인 큐**로(Getty: "가치판단 개입으로 완전 자동화 불가").
- 병합 실행: 승자 선택 → 패자의 관계·링크 이전 → 패자 URI는 승자로 **영구 리다이렉트** + `sameAs` 기록 → 감사 이력(병합 전 스냅샷). 병합 취소는 감사 스냅샷 기반 수동 복구(자동 언두는 범위 외).

### 3.4 발행 기반

- 엔터티별 **JSON-LD**(RiC-O 컨텍스트) 직렬화.
- **변경 피드**: 기존 audit_log에서 파생하는 시간순 변경 스트림 API(Activity Streams 패턴의 단순화 — 페이지네이션된 "엔터티 X가 t에 생성/수정/삭제됨"). L2·Omeka S 등 소비자가 증분 구독.

## 4. AI 추출 파이프라인 (하위 프로젝트 3.3)

Binder 워커(BullMQ 확장)의 EXTRACT 큐. 국회 붙임1 도식의 검증-회귀 루프를 그대로 내장한다.

1. **입력**: DIP 텍스트·OCR 산출물·기술문(내용 필드)·CSV. 입력 단위별 job.
2. **스키마 주입 프롬프트**: relation_types + SKOS 어휘 + 전거 목록 요약을 동적으로 프롬프트에 구성(하드코딩 금지 — 스키마가 바뀌면 프롬프트도 따라감).
3. **LLM 추출**(Claude API, env 설정): 트리플 후보 JSON. 제약: 스키마 내 관계만, 날짜 표준형, 불확실은 Unresolved 태깅(Garbage In 방지).
4. **스키마 검증**: 구조·어휘 검사. 실패 시 재시도(Self-Correction) N회 후 job 실패 처리.
5. **IM 그라운딩**: 개체명을 전거·기록에 퍼지 매칭. 매칭 실패 = Unresolved 엔터티 후보로 보존(폐기 아님).
6. **인간 검수 큐**: 트리플 단위 승인/수정/반려. 반려 사유 기록.
7. **커밋**: 승인분만 relations(origin='extraction', 근거 출처 필수)로 저장. 감사 로그 표준 적용.

- 원칙: **그래프에는 검수 통과분만 들어간다.** 추출 실패·미해결은 회귀·태깅으로 남기고 자동 폐기하지 않는다.
- AI 생성 메타데이터 표시: origin 필드 + 근거 출처로 구현(AAT의 AI-generated 어휘 채택은 범위 외, 필드로 충분).

## 5. L2 검색 서비스 (하위 프로젝트 3.4)

- 모노레포 내 **별도 앱**(apps/search 상당): 독립 배포·독립 저장소(전용 PG 스키마 또는 DB). Binder와는 변경 피드·JSON-LD로만 결합.
- **색인**: 변경 피드 구독 → 그래프 인덱스(엔터티·관계 사영) + 벡터 인덱스(pgvector, 기술문·내용 임베딩) 증분 갱신.
- **질의 파이프라인**(OG-RAG): 자연어 질의 → 엔터티/의도 매핑 → 하이브리드 검색(그래프 탐색 + 벡터 검색) → re-rank → LLM 근거 기반 답변(색인된 데이터만 사용, 문장별 출처 인용) → 근거는 Binder 원본 화면으로 점프.
- **UI**: 자연어 검색창 + 답변 + 그래프 시각화 + 근거 기록 리스트(국회 Semantic Insight View 3단 구성의 축약).
- v1은 PG 기반 그래프 탐색으로 충분(파일럿 규모). SPARQL 엔드포인트는 범위 외, JSON-LD/RDF 덤프는 Binder(3.2)가 제공. Getty 실측("문서형 REST가 트래픽 대부분, SPARQL은 저빈도") 반영.

## 6. UI (3.1·3.2·3.3에 분산)

- 기존 DRMC 디자인 토큰·컴포넌트 유지.
- 기록 상세: **관계 그래프 뷰**(노드-엣지 시각화, 클릭 내비 — 현대차 화면설계 요구), Instantiation 패널(role 뱃지 + 파생 계보), 관계 편집(관계유형 선택 + 대상 검색 + 방향별 라벨 표시).
- 관리: 관계유형 관리, SKOS 어휘 관리, reconciliation 큐, 추출 검수 큐.

## 7. 마이그레이션·테스트·오류 처리

- Phase 2 컨벤션 전면 승계: TDD, 감사 불변식(모든 변이에 audit), CSRF 불변식(Server Actions), i18n 중첩 키, e2e(Playwright), '.js' ESM 임포트.
- **3.1 마이그레이션이 최대 리스크**: (a) 시드·개발 DB 스냅샷 백업 → (b) 단방향 마이그레이션 스크립트(구 AIP/파생물 → Instantiation, 구 relations → 신 모델) → (c) 왕복 대사 검증(변환 전후 엔터티 수·연결 수·고아 0건) → (d) 검증 통과 전 구 구조 보존(뷰 또는 컬럼 유지). e2e 13종이 회귀 게이트.
- 추출 파이프라인: LLM 호출 실패는 BullMQ 재시도 정책, 스키마 검증 실패는 Self-Correction 후 job 실패로 가시화. 검수 큐 적체는 대시보드 위젯으로 노출.
- L2 색인: 피드 커서 기반 재개 가능(중단 후 이어받기), 전체 재색인 명령 제공.

## 8. 단계 분해와 시연 기준 (MoMA 데모)

| 단계 | 범위 | 완료 시연 |
|---|---|---|
| **3.1 RiC-O 코어** | §2 전체 + §6 그래프 뷰·관계 편집 + 마이그레이션 | Tetris 3종·백남준 컴포넌트가 Record/Instantiation + 파생 계보로 그래프 뷰에 표시; 온톨로지 정의서 TTL 발행 |
| **3.2 Identity Manager** | §3 전체 | 백남준 전거를 ULAN URI에 링크; 중복 전거 병합(리다이렉트 확인); JSON-LD·변경 피드 응답 |
| **3.3 AI 추출** | §4 전체 + 검수 큐 UI | MoMA 기술문·CSV에서 트리플 추출 → 그라운딩 → 검수 승인 → 그래프 반영(origin=extraction 표시) |
| **3.4 L2 검색** | §5 전체 | "백남준 작품 중 CRT 모니터를 쓰는 것은?" 자연어 질의 → 근거 인용 답변 + 그래프 시각화 + 원본 점프 |

각 단계는 별도 구현 플랜(writing-plans → 서브에이전트 드리븐)으로 JIT 작성한다.

## 9. 범위 제외 (YAGNI)

- OWL 추론기, Protégé 통합(TTL 정의서 발행까지만), 하드 도메인/레인지 강제
- Binder→Omeka S 접근 사본 발행 + 파생본(ffmpeg) 파이프라인 — Phase 3 후속(3.5) 후보
- OAI-PMH 제공 엔드포인트, SPARQL 엔드포인트
- tus 대용량 업로드, AtoM 어댑터 구현(설계상 자리만 확보)
- 실서버 배포, LDAP/SSO, ARK/DOI 등록
- Getty 어휘 기여(contribution) 워크플로, 멀티 도메인 시드
- 병합 자동 언두, AI 자동 병합·자동 커밋(전 구간 인간 게이트 유지)

## 10. 참고 근거 (요지)

- **Getty**: L1(원본 캐시)/L2(LOD) 분리와 Identity Manager 정의(251020 GRI 출장 회의록), Linked Data in Production 스택 — systems of record → LOD 캐시 → Everything API, Activity Streams·Memento, "컴퓨터가 할 일만 구조화", "링크가 어려우면 식별자만이라도"(260512·260522).
- **국회기록원**: L1/IM/L2 매핑과 OG-RAG 파이프라인(ISMP·붙임1~3), Omeka S의 온톨로지 한계(range/domain·추론기 부재, Object Property의 URI 요구), 온톨로지 정의서 중심 인적 관리.
- **현대자동차**: 이한열 RiC-O 모델링(Record/Instantiation 분리, role, isDerivedInstantiationOf)과 MoReq2010 컴포넌트 한계 진단, 관계 1급 객체 화면설계(PB-24/25), 메타데이터세트·전거 다중 역할, 로드맵 1단계의 RiC-O·OWL·RDF 명시.
