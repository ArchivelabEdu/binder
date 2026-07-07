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
