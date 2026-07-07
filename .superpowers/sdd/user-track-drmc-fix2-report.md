# DRMC Fix 2 Report

Date: 2026-07-07  
Branch: spike/docker-revival

---

## 문제 1 — 레코드 브라우즈 썸네일

### 원인 규명 (필드 파이프라인 추적)

필드 미스매치는 없었음. 파이프라인이 올바르게 연결돼 있음을 코드로 입증:

1. **Seeder** (`arDrmcSeedDrmcPdfTask.class.php:926`) → `addOrUpdateProperty($artwork, 'Thumbnail', '/uploads/r/drmc-thumbs/...')`
2. **ES 인덱서** (`arElasticSearchInformationObjectPdo.class.php:1213-1215`) → property `Thumbnail` 읽어 `tmsObject.thumbnail` 필드에 저장
3. **API** (`informationobjectsWorksBrowseAction.class.php:243`) → `$doc['tmsObject']['thumbnail']` → JSON `thumbnail`
4. **Angular** (`works.search.html:46-54`) → `work.thumbnail != undefined` 분기 → `<img ng-src="{{ work.thumbnail }}">`

ES 확인: 8작품 모두 `tmsObject.thumbnail` 색인 완료.  
nginx 확인: `/uploads/r/drmc-thumbs/*.png` 모두 HTTP 200.

### 실제 문제

파이프라인 자체는 정상. 문제는 스텁 썸네일 이미지의 품질 — `spaceinvaders_thumb.png`가 433바이트 2-bit colormap PNG (사실상 빈 플레이스홀더)였음.

### 실사 이미지 적용

**binder-next objectId → DRMC 식별자 매핑** (binder-next postgres `nodes` 테이블 + `moma-fixtures.json` 참조):

| real-thumbs 파일 | MoMA objectId | 작품명          | DRMC 식별자 | 대상 파일명                      |
|-----------------|---------------|----------------|------------|--------------------------------|
| 175258.jpg      | 175258        | Manifestos 2   | 175258     | manifestos2_thumb.png          |
| 81362.jpg       | 81362         | Lovers         | 81362      | lovers_thumb.png               |
| 175938.jpg      | 175938        | Grosse Fatigue | 175938     | grossefatigue_thumb.png        |
| 169996.jpg      | 169996        | Space Invaders | 102345     | spaceinvaders_thumb.png        |
| 143751.jpg      | 143751        | Official Welcome | 103456   | officialwelcome_thumb.png      |

**미매칭 (binder-next fixtures에 없는 DRMC 전용 스텁):**
- Sher-e-Bangla Nagar (Louis I. Kahn) → 기존 생성 썸네일 유지
- Acorde (Pedro Reyes) → 기존 생성 썸네일 유지
- As You See (Fischli and Weiss) → 기존 생성 썸네일 유지

### 작업 내용

`magick` (ImageMagick) 으로 JPG → PNG 변환 (400x300 crop):

```
manifestos2_thumb.png: 109,068 bytes (이전: 5,149 bytes)
lovers_thumb.png:       67,261 bytes (이전: 21,612 bytes)
grossefatigue_thumb.png: 143,483 bytes (이전: 99,050 bytes)  
spaceinvaders_thumb.png:  59,217 bytes (이전: 433 bytes 플레이스홀더)
officialwelcome_thumb.png: 136,647 bytes (이전: 9,233 bytes)
```

재적용 스크립트: `scripts/apply-real-thumbs.sh` (커밋됨)

### 검증

```
curl http://localhost:8090/uploads/r/drmc-thumbs/manifestos2_thumb.png -w '%{http_code}' → 200
curl http://localhost:8090/uploads/r/drmc-thumbs/spaceinvaders_thumb.png -w '%{http_code}' → 200 (59217 bytes)
curl http://localhost:8090/uploads/r/drmc-thumbs/grossefatigue_thumb.png -w '%{http_code}' → 200
ES 쿼리: 8작품 모두 tmsObject.thumbnail 존재 확인
nginx 로그(2026-07-07): GET /api/informationobjects/works HTTP 200, 이어서 각 thumb.png HTTP 200 — Angular가 실제로 썸네일 로드 중
```

ES 재색인은 불필요 — 이미 thumbnails이 인덱스에 있고 파일만 교체함.

---

## 문제 2 — 컨텍스트 브라우저 전체 화면(Fullscreen) 버튼

### 원인 규명

**파일**: `plugins/arDrmcPlugin/frontend/app/scripts/services/FullscreenService.js`  
(빌드 산출물: `plugins/arDrmcPlugin/frontend/dist/Binder.app.js`)

**버그**: 구식 비표준 Fullscreen API 사용.

```javascript
// 구 코드 (작동 안 됨 — 현대 브라우저에서)
element.requestFullScreen()         // 실제 API: requestFullscreen (소문자 s)
document.cancelFullScreen()         // 실제 API: exitFullscreen
document.webkitCancelFullScreen()   // 실제 API: webkitExitFullscreen
```

현대 브라우저(Chrome 71+, Firefox 64+, Safari 16.4+)는 `requestFullscreen` (소문자 s) 사용.  
`requestFullScreen`은 인식되지 않아 if 분기 전부 실패 → 아무 것도 실행 안 됨.

### 수정 내용

**FullscreenService.js** (소스) + **Binder.app.js** (빌드 산출물, gitignored 직접 패치):

```javascript
// enable()
if (element.requestFullscreen) {          // W3C 표준 (Chrome/FF/Edge/Safari)
  element.requestFullscreen();
} else if (element.webkitRequestFullscreen) {  // Safari 구버전
  element.webkitRequestFullscreen();
} else if (element.mozRequestFullScreen) { // FF 구버전
  element.mozRequestFullScreen();
} else if (element.webkitRequestFullScreen) { // 아주 구버전 webkit
  element.webkitRequestFullScreen();
}

// cancel()
if (document.exitFullscreen) {            // W3C 표준
  document.exitFullscreen();
} else if (document.webkitExitFullscreen) { // Safari
  document.webkitExitFullscreen();
} else if (document.mozCancelFullScreen) { // FF 구버전
  document.mozCancelFullScreen();
} else if (document.cancelFullScreen) {
  document.cancelFullScreen();
} else if (document.webkitCancelFullScreen) {
  document.webkitCancelFullScreen();
}
```

빌드 파이프라인(node:8 + grunt-cli) 없이 `Binder.app.js` 직접 패치 (nginx가 이미 서빙 중인 파일).  
소스 파일도 동일하게 수정하여 다음 빌드 시 올바른 코드가 나오도록 함.

### 검증 방법

브라우저 콘솔에서 `http://localhost:8090/drmc/search/works` → 작품 클릭 → Context Browser → Fullscreen 버튼 클릭 → 전체화면 진입/종료 정상 작동 확인.

---

## 커밋 내역

- `FullscreenService.js` 수정 + `scripts/apply-real-thumbs.sh` 추가 (커밋됨)
- `Binder.app.js` 직접 패치 (gitignored, 컨테이너 볼륨 마운트로 즉시 반영)
- `uploads/r/drmc-thumbs/*.png` 실사 이미지 교체 (gitignored, 컨테이너 볼륨 마운트로 즉시 반영)

---

## 추가 요청 1 — Context browser 범례 색상 다양화

### 배색 로직 실측

- 범례 스와치: `.drmc-color-{level}:before { background-color }` (`drmc-main.less` → 컴파일 `main.css`)
- SVG 노드: `renderer.js:97`이 노드에 `level-{level}` 클래스 부여 → `.level-{level} .content { fill }` CSS가 배색 (JS 하드코딩 색 없음 — CSS만 수정하면 됨)
- 색 변수 원천: `plugins/arDominionPlugin/css/less/drmc-variables.less`
- `main.css`는 **git 추적 파일** — LESS 빌드 없이 소스+컴파일 산출물 동시 수정 (nginx 전례와 동일)

### 변경 전 중복 현황 → 변경 후

| Level | 이전 | 이후 | 비고 |
|---|---|---|---|
| Artwork record | #000000 | #000000 | 검정 유지 |
| Description | #c0c0c0 | #c0c0c0 | 회색 유지 |
| Component | #ffffff | **#f0f0f0** | 연회색 + 스와치 테두리(전 항목 1px rgba(0,0,0,.25) 추가), SVG 노드는 기존 #333 stroke |
| Artist supplied master | #ff0000 | **#e41a1c** | 빨강 |
| Archival master | #ff0000 (중복) | **#9c27b0** | 자주 — 빨강과 명확 구분 |
| Artist verified proof | #ffaa37 | **#ff8c00** | 주황 |
| Exhibition format | #00ff00 | **#2ca02c** | 초록 |
| Documentation | #00ff00 (중복) | **#8c564b** | 갈색 |
| Miscellaneous | #00ff00 (중복) | **#808000** | 올리브 |
| AIP | #28cfff | **#1f77b4** | 파랑 |
| Duplicating copy | #ffaa37 (중복) | **#f48fb1** | 분홍 |
| Research copy | #00ff00 (중복) | **#17becf** | 시안 |
| Production proof | #ffaa37 (중복) | **#e6c229** | 골드 |
| Viewing copy | #00ff00 (중복) | **#b2df8a** | 연녹색 |
| Artist supplied package | #ff0000 (중복) | **#8b0000** | 진홍(red 계열 유지, 명도 구분) |
| Production materials | #ff0000 (중복) | **#00695c** | 다크 틸 |
| Auxiliary elements | #00ff00 (중복) | **#607d8b** | 청회색 |
| Supporting technology record | #af164f | #af164f | 아트웍 범례 외 — 유지 |
| File | #ff8800 | #ff8800 | 범례 외 — 유지 |

인지 대비: 상(hue) 분산(빨강/자주/주황/골드/초록/연녹/시안/파랑/갈색/올리브/분홍/틸/청회색), 동일 계열 내(ASM↔ASP, EF↔VC)는 명도 차 구분. 밝은 스와치 가시성은 테두리로 확보.

### 검증 (DOM/CSS 값 덤프 — 스크린샷 대체)

서빙 중인 `http://localhost:8090/plugins/arDominionPlugin/css/main.css` 실측: 19개 `.drmc-color-*:before` 전부 위 표와 일치, `.level-* .content` fill 동일, 범례 스와치 border 적용 확인. 수정 파일: `drmc-variables.less`(소스), `drmc-context-browser.less`(테두리), `main.css`(직접 패치).

---

## 추가 요청 2 — 파일 썸네일 갤러리 (PDF p.19 재현)

### 실측: 리스트/그리드 토글은 이미 구현돼 있었음

- `context-browser.sidebar-files.html`: `fileListViewMode` 토글(list/thumbnails) + 그리드 마크업 원본에 존재
- `DigitalObjectThumbnailDirective`: `thumbnail_path` 있으면 썸네일, 없으면 mime별 아이콘(image→picture, video→film, xml→code, 기타→desktop/question) — dist 번들 포함 확인
- Angular 템플릿은 **런타임 로드**(`viewsPath=/plugins/arDrmcPlugin/frontend/app/views`) — 재빌드 불필요

### 발견·수정한 실제 결함 2건

**(a) mime_type 부재 → 전부 물음표 아이콘.** 시드 파일 IO에 METS 데이터가 없어 API(`informationobjectsFilesAction.class.php`)가 `metsData.mimeType`을 못 채움. **수정**: 파일명 확장자 기반 `QubitDigitalObject::deriveMimeType()` 폴백 추가 (PHP, 즉시 반영).

검증 (Lovers io 763, 인증 API 실호출):
```
METS...xml                → mime=application/xml   (icon-code)
furuhashi_331.1998(1).JPG → mime=image/jpeg        (icon-picture)
lovers_ch1.mov            → mime=video/quicktime   (icon-film)
lovers_manual_v1.pdf      → mime=application/pdf   (icon-desktop)
```

**(b) 디지털 오브젝트 썸네일 URL 500.** ES `digitalObject.thumbnailPath`는 `/uploads/r/{dir}/` 경로인데 nginx가 drmc-thumbs 외 전부를 PHP 핸들러로 보내 500 (qa/0.9.x 라우트 부재 — a9b6f24와 동일 원인). **수정**: `docker/app/nginx.conf` 정적 서빙 예외를 `/uploads/r/` 전체로 확장(.php는 중첩 location으로 계속 차단). docker cp + `nginx -t` + reload 완료.

검증: `/uploads/r/nginx-test/x.txt → 200`, drmc-thumbs `→ 200` 유지, `/uploads/r/evil.php → 404`.

### 자산 대기 규칙 구현

실행 시점 `/app/uploads/r/drmc-assets/` **미존재**(수집 에이전트 자산 미도착) → 메커니즘+아이콘 폴백 완료, 자산 연결은 재실행형으로 준비:

- **`lib/task/binder/arDrmcAttachAssetsTask.class.php`** (신규, php 5.6 · qubit ORM): `uploads/r/drmc-assets/{identifier}/*.jpg|jpeg|png` 스캔 → 작품 매칭(81362·175938·175258 우선) → DO 없는 이미지 제목 파일 IO 우선 연결, 부족 시 첫 AIP IO 아래 새 파일 IO 생성 → `QubitDigitalObject`(usage MASTER) 연결, 썸네일 파생 자동 생성(컨테이너 ImageMagick 6.9.7 확인). 멱등(동일 파일명 DO 존재 시 skip). 무자산 시 no-op (실행으로 확인).
- **`scripts/apply-drmc-assets.sh`** (신규): attach task → `search:populate` → 파생 썸네일 URL 200 검증 일괄. 자산 도착 후 1회 실행이면 갤러리 노출.

### 자산 도착 → 실제 연결 완료 (세션 중 자산 도착)

작업 중 `uploads/r/drmc-assets/` 에 10개 objectId 폴더 도착 → 파이프라인 실행.

1차 실행에서 결함 발견: 이미지 제목이 아닌 파일 IO(METS.xml·manifest-md5.txt·.mov)에도 이미지가 연결됨. **수정**: (a) 연결 후보를 이미지 확장자 제목 IO로 한정, (b) 후보 부족 시 `installation_view_NN.jpg` 제목의 새 파일 IO 생성, (c) MoMA objectId ↔ DRMC 식별자 별칭 추가(169996→102345 Space Invaders, 143751→103456 Official Welcome). 오연결 4건은 일회성 임시 task로 삭제 후 재실행(임시 task 파일은 실행 후 제거, 미커밋).

최종 연결 결과 (8 DO):

| 작품 | 자산 | 연결 대상 |
|---|---|---|
| Lovers (81362) | 1장 | 기존 `furuhashi_331.1998(1).JPG` 파일 IO (멱등 skip 확인) |
| Grosse Fatigue (175938) | 3장 | `installation_view_01~03.jpg` 신규 IO |
| Manifestos 2 (175258) | 1장 | `installation_view_01.jpg` 신규 IO |
| Official Welcome (103456←143751) | 1장 | `installation_view_01.jpg` 신규 IO |
| Space Invaders (102345←169996) | 2장 | `installation_view_01~02.jpg` 신규 IO |
| Tetris·SimCity·Sims·10 Days·Paik | — | DRMC 작품 없음 — WARNING 후 skip (정상) |

`search:populate` 재색인 완료.

### 최종 검증 (인증 API 실호출 + 썸네일 URL 로드)

```
Lovers io 763:   furuhashi_331.1998(1).JPG   thumb=/uploads/r/null/.../1_142.jpg HTTP:200
GF io 850:       installation_view_01~03.jpg thumb HTTP:200 x3
M2 io 670:       installation_view_01.jpg    thumb HTTP:200
비이미지: .mov→video/quicktime(film icon), .xml→application/xml(code icon),
         .pdf→application/pdf, .txt→text/plain, .aif→audio/x-aiff — mime 폴백 정상
```

갤러리 그리드: 이미지 → 실사 썸네일 렌더 + 로드 200, 비이미지 → 타입 아이콘. PDF p.19 기능 재현 완료.

---

## 커밋 내역 (2차)

- `drmc-variables.less` + `drmc-context-browser.less` + `main.css` — 범례/노드 재배색
- `informationobjectsFilesAction.class.php` — mime 추론 폴백
- `docker/app/nginx.conf` — uploads/r 정적 서빙 확장 (컨테이너 반영 완료)
- `arDrmcAttachAssetsTask.class.php` + `scripts/apply-drmc-assets.sh` — 자산 연결 파이프라인
