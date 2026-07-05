# Phase 3 설계 — 지식그래프 관리 계층 + Identity Manager

- 날짜: 2026-07-06 (적대적 검토 3렌즈 반영 개정)
- 상태: 사용자 설계 승인 완료(대화), 문서 리뷰 대기
- 선행: Phase 2 완료 (`2026-07-04-binder-phase2-design.md`, binder-next 구현 완료)
- 참고문서: `uploads/getty/` (GRI 회의록·Linked Data in Production 등 8건), `uploads/국회기록원/` (ISMP·시맨틱 검색·Omeka S 한계·RiC-O 실습·아카이브시스템101·OG-RAG/L1_L2 도식 7건), `uploads/현대자동차/` (이한열 RiC-O 모델링·완료보고서·메타데이터 통합안·화면설계서 8건)
- 표기: 하위 프로젝트는 **P3.1~P3.4**, 문서 절은 §번호. 상호참조는 항상 이 표기를 쓴다.

## 0. 확정 결정 (사용자 승인 완료)

| # | 결정 | 근거 |
|---|---|---|
| D1 | Binder의 역할 = **RiC-O 지식그래프 관리 계층 + Identity Manager 모듈 호스팅**. 보존=Archivematica, 카탈로깅=AtoM, 전시·발행=Omeka S와 표준 인터페이스로 느슨 결합 | Getty의 L1(소스 캐시)/Identity Manager/L2(LOD) 3역할 분리(251020 GRI 회의록 p.8)와 국회 ISMP의 동일 구도 차용(p.1–2)을 바탕으로, **본 설계가 "그래프 편집·관리"를 별도 층으로 추가 분리한 합성 구도**다. 이 슬롯에 기성품이 없다는 근거가 「Omeka S의 한계」(추론기·카디널리티 제약 부재 등) |
| D2 | 지적 메타데이터 **추출은 Binder 워커**가 오케스트레이션(Archivematica는 기술적 처리만), **OG-RAG 검색은 별도 L2 서비스**(Omeka S는 전시·발행 전담) | 그라운딩이 의존하는 마스터 전거·스키마가 Binder에 있음(국회 붙임1 도식); Getty의 저장·식별·발행 분리 사상 |
| D3 | 파일럿 도메인 = **현 MoMA 데모 확장** (데이터 모델은 도메인 중립) | 기존 자산(10작품·실파일) 재사용, 계층→그래프 마이그레이션 자체가 시연 거리 |
| D4 | 진행 경로 = **접근법 A: 그래프 스파인 우선** (P3.1 RiC-O 코어 → P3.2 IM → P3.3 AI 추출 → P3.4 L2 검색) | 의존 순서와 일치, 단계별 시연 가능, 현대차 로드맵 2단 전략·Getty 점진론 부합 |
| D5 | 저장 기술 = **PG가 진실, 그래프는 파생 발행** (Getty LOD 캐시 방식). L2 저장소 v1 = **동일 PG 인스턴스의 전용 스키마**(binder_search), 전용 그래프 DB·트리플 스토어 도입은 후순위 | Getty가 상용 그래프 DB 대신 LOD 캐시 채택(LOD Gateway); 기존 binder-next 스키마·감사 파이프라인 보존 |
| D6 | 어휘 전략 = **내부 모델은 RiC-O 정렬 단일 스키마, 외부 표현은 다중 어휘 매핑**. RiC-O 외에 Linked Art·SKOS·schema.org·FOAF·dcterms 등으로 확장 가능해야 하며, 클래스·속성의 외부 어휘 매핑은 하드코딩이 아닌 데이터(어휘 레지스트리, §2.7)다 (사용자 지시 2026-07-06) | 사전질문지(260626) Q4의 계층적 조합 구상(RiC-O+CIDOC CRM+Linked Art+Schema.org); Getty 실무 — 내부 정합 그래프 모델 + 외부 노출 프로파일(Linked Art Profile) 계층화 |

## 1. 포지셔닝과 아키텍처

```
[대량 이기종 입수 SIP]
        ↓
[Archivematica]  포맷 식별·정규화·AIP (기술 메타데이터만)     [AtoM/소스 시스템] (범위 외 — 자리만)
        ↓ DIP/METS
┌─────────────────────── Binder — 지식그래프 관리 계층 ───────────────────────┐
│  Identity Manager        AI 추출 파이프라인         RiC-O 그래프 편집        │
│  PID·전거·정합 검수       LLM 추출→그라운딩→검수     기술·관계·SKOS 어휘      │
└──────────────────────────────────────────────────────────────────────────┘
        ↓ 발행(JSON-LD·변경 피드)                  ┈┈> Omeka S 접근 사본 발행 (P3.5 후보, 범위 외)
[L2 지식 저장소 → OG-RAG 검색 서비스 → 이용자 검색 UI]
```

- Binder는 **편집·식별의 진실 공급원**(system of record for description & identity)이고, 검색·전시는 소비자다.
- Getty와의 대응: Getty가 분리한 저장(L1)/식별(IM)/발행(L2) 중 IM은 Binder 안에 **아키텍처상 분리된 모듈**로 호스팅하고, L2는 별도 서비스로 둔다.
- Archivematica의 인제스트 체인(Siegfried·JHOVE·정규화·BagIt)은 "이 비트가 무엇이고 어떻게 살려둘 것인가"에만 답한다. "누가 등장하고 어떤 사건과 연결되는가"는 P3.3 추출 파이프라인의 몫이다.
- Omeka S에는 추론기·카디널리티/도메인·레인지 강제가 없고(「Omeka S의 한계」 p.1–2), SPARQL 질의는 별도 Graph DB로 내보내야 가능하다(같은 문서 p.4). 검색은 L2 서비스, 전시는 Omeka S, 그래프 편집은 Binder — 세 곳이 겹치지 않는다.

## 2. 데이터 모델 (P3.1의 핵심)

### 2.1 URI 1급화

- 전 엔터티(기록·구현체·전거·사건·개념·관계유형)에 해석 가능한 URL형 식별자: `/id/{type}/{uuid}`.
- uuid 현황: **승계 대상은 nodes·aips뿐**이다. 전거(agents)·개념(terms)·사건(events)·관계유형에는 uuid 컬럼이 없으므로 **신설 + 백필**한다(§7 체크리스트 포함). Instantiation의 uuid는 aips.uuid 승계가 아닌 **자체 발급**(aips.uuid는 외부 부여·삭제분 중복 허용이므로 URI 영구성과 충돌).
- ARK/DOI 등 외부 PID 체계는 범위 외(Newbury: "신뢰 기관 간에는 URL로 충분").
- URI는 영구 불변. 병합 시에도 구 URI는 리다이렉트로 영구 유지(§3.3).

### 2.2 Record ↔ Instantiation 분리

**지적 개체 계열(기존 nodes 유지)과 구현체(`instantiations` 신설)를 분리한다.** "소속"(Record→Instantiation 플랫 연결)과 "계보"(Instantiation 간 파생 엣지)의 직교 — 이한열 상세 모델(rico:hasOrHadInstantiation + rico:isDerivedInstantiationOf, 파생→원본 방향, role 명기)과 동형.

`instantiations` 테이블: `record_id`(소속), `role`(SKOS 통제어휘), `carrier`(physical | digital), `derived_from_instantiation_id`(파생→원본), 물리 구현체는 보관 위치·매체 기술, 디지털 구현체는 AIP/파일 연결, uuid(자체 발급)·감사·소프트 삭제 표준 적용.

**매핑 키는 kind 단독이 아니라 (kind, level 텀) 조합이다.** kind enum은 work|component|record|supporting 4종이고 fonds/series/file/item은 level_of_description 텀이다(nodes.ts:10, taxonomy-data.ts):

| 현행 | RiC-O 매핑 |
|---|---|
| kind=record & level∈{fonds, series} | RecordSet |
| kind=record & level∈{file, item 등 그 외} | Record |
| kind=work | Record (하위 표현은 hasOrHadPart로 연결) |
| kind=supporting | Record |
| kind=component | **트리아지** (아래) |

**component 트리아지 — level 텀 기반 3분류.** 시드 실태 조사 결과 component는 단일 종이 아니다:

1. **표현형** (Tetris 플랫폼별 expression, Manifestos 채널 등) → **하위 Record** (hasOrHadPart)
2. **역할형** (archival_master, exhibition_format, duplicating_copy, viewing_copy, artist_verified_proof, research_copy, artist_supplied_master 등 — Instantiation role 어휘와 사실상 동일) → **Instantiation** (role 매핑: archival_master→preservation-master, exhibition_format→exhibition-copy, viewing_copy→access-copy, 나머지는 동명 role 신설 — role은 SKOS라 확장 가능)
3. **물리 장비형** (백남준 자동 피아노·TV×15 등) → **physical Instantiation** (carrier=physical). §8의 백남준 시연은 이 마이그레이션 산물로 달성한다(별도 이중 등록 없음).

level 텀 전수 → 분류표 확정은 P3.1 플랜의 첫 작업(텀 census)으로 명시한다. **AIP 부착 위치와 역할형 component가 중복되는 경우**(예: Lovers는 AIP 2건이 work에 부착되어 있고 archival_master component가 별도 존재): 자동 병합하지 않고 **마이그레이션 리포트의 수동 검토 큐**로 적재한다(인간 게이트 원칙).

- AIP → Instantiation(digital) 변환의 role은 일괄 preservation-master가 아니라 **aips.origin × aip_type 규칙**으로 결정한다(예: archivematica 유래 artwork_component → preservation-master, direct 유래 supporting_documentation → supporting-document role). aip_type 텀은 role로 흡수 후 폐기 예정으로 표기.
- **기존 파생물의 마이그레이션은 없다**: files.derivatives는 휴면 컬럼(쓰기 코드 부재)이고 썸네일은 노드 레벨 정적 URL이라 구조화된 파생 데이터가 현행에 존재하지 않는다. 파생 Instantiation은 **신규 등록 전용**이며, 시연용 파생 계보(마스터→열람용→썸네일)는 **시더에서 신규 생성**한다. 파일 단위 계보(file→file)는 범위 외 — 계보는 Instantiation 단위로만.
- "component" 용어의 제거 범위: **DB kind 값은 유지**하되(마이그레이션 후에도 이력 호환), RiC-O 매핑·JSON-LD·UI 라벨·온톨로지 정의서 표면에서는 Record/Instantiation으로만 표기한다(이한열 문서 권고).

### 2.3 관계 1급 객체

- `relation_types`: 정방향·역방향 라벨 쌍(i18n), 외부 어휘 속성 매핑(복수 — §2.7 레지스트리 참조; 예: rico:hasOrHadPart + dcterms:hasPart), 도메인/레인지 힌트(엔터티 종류 목록), 설명. 관리자 CRUD(현대차 PB-24 설계 승계).
- `relations`: Source(엔터티 종류+id) – Type – Target(엔터티 종류+id), 시간 한정자(시작/종료), 확실성(certainty, 선택), 주기(注記, note, 선택), `origin`(user | extraction | import), 근거 출처(추출분 필수). 임의 엔터티 간 연결.
- **기존 relations 테이블 이관 규칙** (현행: nodes 전용 int FK + cascade, (subject,object,type) 유니크, 방향별 12개 관계 텀 + reciprocal_term_id):
  - 참조는 (entity_type, entity_id) 폴리모픽 컬럼 + 앱 레벨 정합 검증으로 전환. **cascade 삭제 금지** — 감사·피드에 기록되는 명시적 삭제로 전환(엔터티 삭제 시 관계를 소프트 삭제하고 audit 기록).
  - 유니크 제약은 시간 한정자를 포함해 재설계(동일 유형·다른 기간의 복수 관계 허용).
  - 방향별 12텀 → relation_types 6행으로 접기(병합 매핑표는 P3.1 플랜에서 확정), 기존 행의 방향 정규화 규칙 포함.
  - 기존 행 origin 백필 = 'user'(현행 유일 쓰기 경로가 UI의 linkNodes), 단 linkNodes가 감사 미기록이었음을 마이그레이션 노트에 명시.
- 도메인/레인지는 **경고 수준 소프트 검증**: 저장은 허용하되 UI에서 경고 + 입력 통제. OWL 추론기·하드 제약은 범위 외. 원문 근거를 정확히 적으면: 「Omeka S의 한계」는 모델 논리 검증을 Protégé 시뮬레이션에, **데이터 정확성 관리를 온톨로지 정의서 + 인적 관리에** 배정한다(p.4) — 본 설계는 후자를 채택하고, 전자는 §2.5의 TTL 발행으로 수동 Protégé 검증이 가능하도록 한다(§9 참조).

### 2.4 SKOS 어휘

- 현행 terms는 flat(parent 없음)·uuid 없음이므로 "승격"의 실체는 **SKOS 개념 구조 신설 + 이관**이다: uuid/URI, prefLabel(i18n), broader/narrower, 외부 `exactMatch`. 외부 매칭의 저장처는 §3.2 `entity_identifiers`로 **일원화**한다(중복 저장 금지).
- 승격 범위: **기술(記述) 어휘**(level, 매체, Instantiation role, 관계 한정어 등)가 대상. 시스템 어휘(note_type 등 내부 enum성 택소노미)는 현행 유지 — 범위 표는 P3.1 플랜에서 택소노미별로 확정.
- 통제값은 리터럴이 아닌 **URI 연결**(Custom Vocab의 "자기 시스템 안에서만 작동하는 그래프" 함정 회피). machine_name 없는 데이터 유래 텀의 URI는 uuid 기반으로 생성.

### 2.5 온톨로지 정의서 발행

- Binder 스키마(클래스·관계유형·어휘)를 **TTL + 사람용 문서**로 자동 발행. 스키마 변경마다 갱신. "현재 단계에서는 온톨로지 정의서를 통해 사람이 데이터의 정확성을 철저히 관리하는 것이 중요"(「Omeka S의 한계」 결론)의 구현이며, 발행된 TTL은 Protégé에서 수동 논리 검증에 사용할 수 있다.

### 2.6 사건(Event) 엔터티 승격

- 현행 events는 uuid 없는 node 종속 부속행이다. P3.1에서 **1급 엔터티로 승격**: uuid/URI, 유형(SKOS), 일자, i18n 라벨, 독립 행 + 관계(relations)로 기록·전거와 연결(현대차 '연표' 전거의 사건-허브 패턴). 기존 node 종속 행은 관계로 재표현해 이관.

### 2.7 어휘 레지스트리와 다중 어휘 매핑 (D6)

- **내부 모델은 하나**(Record/RecordSet/Instantiation/Agent/Event/Concept + 관계 1급 객체, RiC-O 정렬)이고, **외부 어휘 표현은 여럿**이다. 이를 위해:
  - `vocabularies`: 등록된 외부 어휘(prefix, 네임스페이스 URI, 설명). 시드: rico, la(Linked Art), skos, schema, foaf, dcterms — 관리자 등록으로 확장(CIDOC CRM 등).
  - `class_mappings` / 속성 매핑: 내부 클래스·속성·관계유형 → 외부 어휘 URI, **1:N 허용**(예: 내부 Record → rico:Record + schema:CreativeWork; 내부 Agent(인물) → rico:Person + foaf:Person + schema:Person).
- **직렬화기는 매핑-드리븐**: JSON-LD 발행(§3.4)이 매핑 테이블을 읽어 프로파일별 출력을 생성한다. 새 어휘 지원 = 코드 수정이 아니라 어휘 등록 + 매핑 입력.
- v1 구현 범위: RiC-O가 **기본(primary) 프로파일**(완전 매핑), schema.org를 **두 번째 프로파일**로 매핑해 다중 어휘 동작을 증명한다(§8 P3.2 시연). Linked Art·CIDOC CRM은 레지스트리에 등록만 하고 완전 매핑은 후속(Linked Art는 이벤트 중심 구조 변환이 필요해 단순 속성 매핑 이상의 변환 계층이 요구됨 — 그 변환 계층 설계는 매핑 완성 시점의 플랜에서).
- SKOS는 발행 프로파일이 아니라 **개념 구조 자체**(§2.4)로 이미 내장, FOAF는 Agent 매핑으로 흡수.

## 3. Identity Manager 모듈 (P3.2)

### 3.1 식별자 레지스트리

- §2.1의 URI 위에 얹는 조회·해석 계층: `/id/...` 요청 → 엔터티 화면 또는 JSON-LD(협상은 단순 쿼리 파라미터로 시작).

### 3.2 외부 전거 상호링크

- `entity_identifiers`: 엔터티별 외부 URI(ULAN·AAT·Wikidata·VIAF·기관 자체), scheme, 검증 상태, 등록 주체. SKOS exactMatch의 저장처를 겸한다(§2.4).
- 편집 UI에서 외부 전거 검색·제안(Value Suggest 상당) — v1은 Wikidata·Getty 공개 API 조회.

### 3.3 Reconciliation 워크벤치

- **v1 병합 대상은 전거(agents)와 개념(terms)으로 한정**한다. 기록 병합은 범위 외.
- 중복 후보 배치 탐지(워커): 이름 정규화·연대·외부 식별자 일치 기반 유사도. 자동 병합 금지 — 후보는 전량 **인간 승인 큐**로(Getty: "가치판단 개입으로 완전 자동화 불가").
- 병합 실행: 승자 선택 → 패자의 **관계(relations)·외부 링크(entity_identifiers)·참조 FK 전부** 이전 → 패자 URI는 승자로 **영구 리다이렉트** + sameAs 기록 → 병합 이벤트를 감사·변경 피드에 기록(병합 전 스냅샷 포함). 병합 취소는 감사 스냅샷 기반 수동 복구(자동 언두는 범위 외).

### 3.4 발행 기반

- 엔터티별 **JSON-LD** 직렬화 — **매핑-드리븐 다중 프로파일**(§2.7): 기본 RiC-O 컨텍스트, `?profile=` 파라미터로 등록 어휘 프로파일(v1: schema.org) 선택. (벌크 RDF 덤프는 범위 외 — P3.4는 엔터티별 JSON-LD와 변경 피드만 소비한다.)
- **변경 피드**: 시간순 변경 스트림 API. 사양을 명시한다:
  - **대상 = 엔터티 + 관계**(relations 변경 포함 — L2 그래프 인덱스의 증분 갱신에 필수).
  - **이벤트 종류 = created | updated | deleted | merged**(merged는 승자·패자 URI 쌍 포함; L2는 수신 시 패자 제거 + 엣지 재지향).
  - **식별 = /id/{type}/{uuid}** 언어. 이를 위해 audit_log에 엔터티 uuid(또는 해석 계층)를 추가한다.
  - **커서 = (at, id) 복합 + 지연 커밋 안전 마진**(bigserial 단독 커서의 커밋 순서 역전 누락 방지).
  - **전제 조건(P3.1로 당김)**: 현행 감사 커버리지 갭 봉합 — relations(linkNodes)·events·notes 쓰기 경로의 audit화, cascade 삭제의 감사되는 명시 삭제 전환. §7의 "모든 변이에 audit"은 이 봉합 후에 참이 된다.

## 4. AI 추출 파이프라인 (P3.3)

Binder 워커(BullMQ 확장)의 EXTRACT 큐. 국회 붙임1 도식의 검증-회귀 루프를 **변형 계승**한다 — 원본은 그라운딩 실패 시 재추출로 회귀하는 단일 루프이고, 본 설계는 회귀를 스키마 검증 단계로 옮기고(Self-Correction), 그라운딩 실패는 회귀 대신 미해결 보존으로, 커밋 전 인간 검수 게이트를 추가한다.

1. **입력(v1)**: 기술문(내용 필드)·CSV·수동 업로드 텍스트. *DIP 텍스트·OCR 산출물은 현행 시스템이 보관하지 않으므로*(am-dip는 메타데이터만 저장) **DIP 콘텐츠 보관 정책과 함께 후속 단계로 이연**한다.
2. **영속 구조**: `extract_jobs`(ingest_jobs 패턴 승계) + `extract_candidates`(트리플 후보, 검수 상태 포함). BullMQ job은 휘발성이므로 검수 큐의 저장처는 DB다.
3. **스키마 주입 프롬프트**: relation_types + SKOS 어휘 + 전거 목록 요약을 동적으로 구성(하드코딩 금지).
4. **LLM 추출**(Claude API, env 설정): 트리플 후보 JSON. 제약: 스키마 내 관계만, 날짜 표준형, 값이 불확실하면 **uncertain-value 태깅**.
5. **스키마 검증**: 구조·어휘 검사. 실패 시 Self-Correction 재시도(기본 2회, env 설정) 후 job 실패 처리.
6. **IM 그라운딩**: 개체명을 전거·기록에 퍼지 매칭. 매칭 실패 = **unmatched-entity 후보**로 extract_candidates에 보존(폐기 아님). 검수 큐에서 승인 시 신규 전거 생성 또는 기존 전거 연결로 승격.
7. **인간 검수 큐**: 트리플 단위 승인/수정/반려(사유 기록).
8. **커밋**: 승인분만 relations(origin='extraction', 근거 출처 필수)로 저장. 감사 로그 표준 적용.

- 원칙: **그래프에는 검수 통과분만 들어간다.**
- AI 생성 메타데이터 표시: origin 필드 + 근거 출처로 구현(AAT의 AI-generated 어휘 채택은 범위 외).

## 5. L2 검색 서비스 (P3.4)

- 모노레포 내 **별도 앱**(apps/search 상당): 독립 배포, 저장소는 동일 PG 인스턴스의 **전용 스키마**(D5). Binder와는 변경 피드·JSON-LD로만 결합.
- **색인**: 변경 피드 구독 → 그래프 인덱스(엔터티·관계 사영) + 벡터 인덱스(pgvector, 기술문·내용 임베딩) 증분 갱신. merged 이벤트 수신 시 패자 엔터티 제거 + 엣지 재지향. 피드 커서 기반 재개 + 전체 재색인 명령 제공.
- **질의 파이프라인**(OG-RAG): 자연어 질의 → 엔터티/의도 매핑 → 하이브리드 검색(그래프 탐색 + 벡터 검색) → re-rank → LLM 근거 기반 답변(색인 데이터만 사용, 문장별 출처 인용) → 근거는 Binder 원본 화면으로 점프.
- **UI**: 자연어 검색창 + 답변 + 그래프 시각화 + 근거 기록 리스트(국회 Semantic Insight View 3단 구성의 축약).
- SPARQL 엔드포인트는 범위 외. Getty 실측("문서형 REST가 트래픽 대부분, SPARQL은 저빈도") 반영.

## 6. UI (단계 귀속 명시)

기존 DRMC 디자인 토큰·컴포넌트 유지.

| UI | 단계 |
|---|---|
| 관계 그래프 뷰 — 노드-엣지 시각화, 클릭 내비. **Record·Instantiation 노드와 파생 엣지를 포함해 렌더링**(관계 + 소속 + 계보) | P3.1 |
| Instantiation 패널 — role 뱃지 + 파생 계보 목록 | P3.1 |
| 관계 편집 — 관계유형 선택 + 대상 검색 + 방향별 라벨 | P3.1 |
| 관계유형 관리, SKOS 어휘 관리 | P3.1 |
| reconciliation 큐(후보 검토·병합) | P3.2 |
| 추출 검수 큐(트리플 승인/수정/반려) | P3.3 |
| 자연어 검색 UI | P3.4 |

## 7. 마이그레이션·테스트·오류 처리

- Phase 2 컨벤션 전면 승계: TDD, CSRF 불변식(Server Actions), i18n 중첩 키, e2e(Playwright), '.js' ESM 임포트. 감사 불변식("모든 변이에 audit")은 §3.4의 커버리지 갭 봉합(P3.1)으로 완성한다.
- **P3.1 마이그레이션이 최대 리스크**. 절차:
  - (a) **사전 정리**: e2e 잔재(clean-e2e-records) 실행 → 시드·개발 DB 스냅샷 백업.
  - (b) 단방향 마이그레이션 스크립트: (kind, level) 매핑 + component 트리아지 + AIP→Instantiation(origin×aip_type role 규칙) + relations 이관(방향 정규화·origin 백필) + uuid 백필(agents·terms·events) + events 승격.
  - (c) **전후 대사 검증**(counts reconciliation — 역변환 아님): live/deleted **분리 카운트**로 엔터티 수·연결 수 대사, 고아 0건, 소프트 삭제 행은 이관하되 Instantiation 미생성(삭제 상태 유지) 원칙. 수동 검토 큐(AIP-component 중복 등) 리포트 산출.
  - (d) 검증 통과 전 구 구조 보존(뷰 또는 컬럼 유지). e2e 게이트는 **대사 완료 후** 실행(e2e가 새 레코드를 만들므로 순서 고정).
  - (e) sync 소유권(node_sources.fieldOwnership)은 노드 필드 단위 현행 유지 — 관계·Instantiation은 v1에서 sync 충돌 검출 대상 아님을 명시(원격 소유 필드가 아님). 노드 레벨 썸네일/롤업 denorm 컬럼은 파생 Instantiation 도입 후 일치 검증 항목에 추가하고 폐기 여부는 P3.1 종료 시 결정.
- 추출 파이프라인: LLM 호출 실패는 BullMQ 재시도 정책, 스키마 검증 실패는 Self-Correction(기본 2회) 후 job 실패로 가시화. 검수 큐 적체는 대시보드 위젯으로 노출.

## 8. 단계 분해와 시연 기준 (MoMA 데모)

각 단계의 시연에 필요한 **시드 확장은 해당 단계 범위에 포함**한다.

| 단계 | 범위 | 완료 시연 |
|---|---|---|
| **P3.1 RiC-O 코어** | §2 전체(사건 승격·어휘 레지스트리 §2.7 구조 포함) + §3.4 전제(감사 갭 봉합) + §6의 P3.1 UI 4종 + §7 마이그레이션. 시드 확장: 파생 계보(마스터→열람용→썸네일) 신규 생성 | Tetris 3종(표현형→하위 Record)과 백남준 장비(물리 Instantiation)가 트리아지대로 이관되고, 그래프 뷰에 Record/Instantiation + 파생 계보 표시; 온톨로지 정의서 TTL 발행(등록 어휘 포함) |
| **P3.2 Identity Manager** | §3 전체 + §2.7 매핑-드리븐 직렬화(schema.org 프로파일). 시드 확장: 중복 전거 시드(병합 시연용) | 백남준 전거를 ULAN URI에 링크; 중복 전거 병합(구 URI 리다이렉트 확인); JSON-LD·변경 피드 응답(관계 변경·merged 이벤트 포함); **동일 엔터티를 RiC-O·schema.org 두 프로파일 JSON-LD로 발행** |
| **P3.3 AI 추출** | §4 전체 + 검수 큐 UI | MoMA 기술문·CSV에서 트리플 추출 → 그라운딩 → 검수 승인 → 그래프 반영(origin=extraction 표시), unmatched-entity의 전거 승격 1건 |
| **P3.4 L2 검색** | §5 전체. 시드 확장: 매체 SKOS 개념(CRT 모니터 등) + 작품-매체 관계(P3.1 어휘 구조 사용) | "백남준 작품 중 CRT 모니터를 쓰는 것은?" 자연어 질의 → 근거 인용 답변 + 그래프 시각화 + 원본 점프 |

각 단계는 별도 구현 플랜(writing-plans → 서브에이전트 드리븐)으로 JIT 작성한다. P3.1 플랜의 첫 작업은 level 텀 census(트리아지 분류표·relation 텀 12→6 매핑표 확정)다.

## 9. 범위 제외 (YAGNI)

- OWL 추론기, Protégé 연동 자동화(TTL 정의서 발행까지만 — 발행물의 수동 Protégé 검증은 가능), 하드 도메인/레인지 강제
- Binder→Omeka S 접근 사본 발행 + 파생본(ffmpeg) 파이프라인 — Phase 3 후속(P3.5) 후보
- OAI-PMH 제공 엔드포인트, SPARQL 엔드포인트, 벌크 RDF 덤프
- DIP 콘텐츠(텍스트) 보관 정책·OCR 파이프라인 — P3.3 입력은 기술문·CSV·수동 텍스트로 시작
- tus 대용량 업로드, AtoM 어댑터 구현(설계상 자리만 확보)
- 실서버 배포, LDAP/SSO, ARK/DOI 등록
- Getty 어휘 기여(contribution) 워크플로, 멀티 도메인 시드
- 기록(Record) 병합, 병합 자동 언두, AI 자동 병합·자동 커밋(전 구간 인간 게이트 유지), 파일 단위 파생 계보
- Linked Art·CIDOC CRM **완전 매핑**(레지스트리 등록 + 구조는 v1 포함, 이벤트 중심 변환 계층은 후속 — §2.7)

## 10. 참고 근거 (요지)

- **Getty**: L1(소스 스냅샷 캐시)/L2(LOD) 분리와 Identity Manager 정의(251020 GRI 출장 회의록 p.8), Linked Data in Production 스택 — systems of record → LOD 캐시 → Everything API, Activity Streams·Memento, "컴퓨터가 할 일만 구조화", "링크가 어려우면 식별자만이라도"(260512·260522).
- **국회기록원**: L1/Middle(IM)/L2 매핑과 OG-RAG 파이프라인(ISMP·붙임1~3), Omeka S의 온톨로지 한계(추론기·카디널리티·도메인/레인지 부재, Object Property의 URI 요구, Custom Vocab의 리터럴 함정), 온톨로지 정의서 중심 데이터 정확성 관리 + Protégé 모델 검증(「Omeka S의 한계」 p.4).
- **현대자동차**: 이한열 RiC-O 모델링(Record/Instantiation 분리, role, isDerivedInstantiationOf 파생→원본)과 MoReq2010 컴포넌트 한계 진단, 관계 1급 객체 화면설계(PB-24/25), 메타데이터세트·전거 다중 역할, 로드맵 1단계의 RiC-O·OWL·RDF 명시.
- 본 문서의 4단 구도(§1)는 위 문서들의 합성이며(D1 근거란 참조), 각 문서가 동일 도식을 직접 제시한 것은 아니다.
