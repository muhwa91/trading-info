# DESIGN.md — Stockpit 비주얼 리디자인 설계서

> 대상: `Hachiware/_Project/trading-info/frontend` (Vue 3.5 + Tailwind v4 + daisyUI v5 + lightweight-charts 5.2)
> 범위: 스타일 재설계(정보구조·레이아웃 유지) + 간격/정렬/색 불일치 교정. 실사용자 1인, HTS급 정보밀도.
> 원칙: 모든 값은 토큰에서 파생. 컴포넌트에 raw HEX 금지. KR 시장 관례(상승=빨강·하락=파랑) 불변.
> 작성: ui-ux-designer, 2026-07-10

---

## 1. 컨셉 선언

**방향: "Instrument-grade Terminal" — 계측기 등급의 다크 터미널.**

이 화면의 세계는 소비자용 핀테크 앱이 아니라 **시세 단말기·정밀 계기판**이다. 지금 코드의 지배적 인상은 "검은 배경 + 인디고 하나가 튀는" 구성인데(로고·활성 카드·탭·배지·스피너·버튼·포커스가 전부 indigo), 이는 frontend-design 스킬이 금지하는 대표적 AI 슬롭 패턴("흑배경+형광 단색 액센트 하나만 튀는 구성")과 정확히 일치한다. 트레이딩 단말기에서 **가장 큰 목소리를 내야 하는 것은 크롬(chrome)이 아니라 데이터** — 즉 등락 색(빨강/파랑)이다. 이번 리디자인의 핵심 축은 **인디고를 구조적 역할로 강등하고, 등락 신호색을 화면에서 가장 강한 색으로 승격**하는 것이다.

두 번째 축은 **색의 규율**이다. 현행은 세션 배지가 emerald·amber·pink·cyan·gray 5색을 즉흥적으로 쓰고, indigo 하나가 6가지 의미(브랜드·선택·활성·배지·링크·로딩)를 겸한다 — 무지개 수프다. 재설계는 **한 색 = 한 의미** 원칙으로 7개 색을 각각 단일 의미에 고정한다(상승=빨강, 하락=파랑, 정규장=앰버, 연장세션=틸, 인터랙션=아이리스, 시스템연결=그린, 나머지=중립 슬레이트). 세션은 5색이 아니라 **개장/연장/마감 3계층**으로 압축한다.

**서명 요소(signature) 1개 = "리드아웃 태그(Readout Tag)와 가격 레일".** 이미 차트 우측 거터에 존재하는, 가격축에 물려 있는 계기판형 가격 라벨을 **기계 가공된 계측 태그**로 격상한다 — 좌측에 정밀 포인터 노치(가격선을 정확히 가리키는 삼각 눈금)를 달고, tabular 모노 2줄(가격/등락%)로 통일하며, 현재가 태그(신호색 채움)·평단 태그(아이리스)·세션 태그가 같은 물성의 한 계열을 이룬다. 대담함은 여기 한 곳에만 둔다. 그 주변(카드·헤더·표)은 얇은 hairline과 표면 밝기차로만 층위를 만들어 **조용하게** 둔다. 글래스모피즘(현재 모든 카드 `backdrop-blur-md`)은 헤더 한 곳으로 축소한다 — 계기판은 유리가 아니라 무광 패널이고, backdrop-filter가 fixed 자식 위치를 깨뜨려 지금 3곳에서 Teleport 우회를 강요하는 실비용도 있기 때문이다.

> **비슷한 다른 브리프에도 같은 결과가 나오는가?** 아니다. 이 설계는 (a) 크롬보다 데이터가 큰 목소리, (b) KR 적/청 관례를 시맨틱 신호로 정식화, (c) 가격 레일/리드아웃 태그라는 도메인 고유 서명, (d) 세션 3계층 압축 — 트레이딩 단말기가 아니면 나올 수 없는 결정들이다.

---

## 2. 토큰 시스템 (3층)

### 2-1. 통합 방식 (frontend-engineer 필독)

이 프로젝트는 **daisyUI v5**가 `base-100/200/300`·`base-content`·`primary`를 소유하고, 모든 컴포넌트가 `bg-base-100/45`·`text-base-content/60` 형태로 이미 그 토큰을 참조한다. 따라서 **daisyUI 커스텀 테마의 색값만 교체하면 표면·보더·텍스트가 마크업 변경 거의 없이 전파**된다(가장 게으르고 정확한 경로). 그 위에 신호색·세션색·아이리스·리드아웃 유틸리티를 `@theme`로 추가하고, 즉흥 hue(rose/sky/emerald/pink/cyan/amber/indigo 리터럴)만 새 시맨틱 클래스로 치환한다.

`src/style.css` 상단을 아래로 교체:

```css
@import "tailwindcss";

/* ── daisyUI: 커스텀 단일 다크 테마 "stockpit" (night 대체) ── */
@plugin "daisyui" {
  themes: stockpit --default;
}
@plugin "daisyui/theme" {
  name: "stockpit";
  default: true;
  color-scheme: dark;

  /* 표면 — 밝기차로 elevation 표현 (다크에선 그림자 안 보임) */
  --color-base-300: #0A0E14;   /* 앱 배경 (최하) */
  --color-base-200: #10151E;   /* 입력·hover·구분 표면 */
  --color-base-100: #141B26;   /* 카드·패널 (기준 표면) */
  --color-base-content: #E6EAF0; /* 주 텍스트 */

  /* primary = 인터랙션 아이리스 (강등된 크롬 액센트) */
  --color-primary: #7C83FF;
  --color-primary-content: #0A0E14;

  /* secondary/neutral = 중립 슬레이트 */
  --color-secondary: #1D2632;
  --color-secondary-content: #E6EAF0;
  --color-neutral: #1D2632;
  --color-neutral-content: #C2C9D6;

  /* 상태 — success=시스템연결 / warning=연장세션 / error=위험 */
  --color-success: #3FB950;  --color-success-content: #0A0E14;
  --color-warning: #E8A33D;  --color-warning-content: #0A0E14;
  --color-error:   #FF4D4F;  --color-error-content:   #FFFFFF;
  --color-info:    #3EA6FF;  --color-info-content:    #0A0E14;

  --radius-selector: 9999px; /* 배지·pill */
  --radius-field: 5px;       /* 버튼·입력·탭 */
  --radius-box: 10px;        /* 카드 */
  --border: 1px;
}

@theme {
  /* ═══ 1층: 원시 토큰 ═══ */

  /* 폰트 — 계측 물성: 엔지니어링 산세리프 + 모노 수치 */
  --font-sans: "IBM Plex Sans", "Plus Jakarta Sans", system-ui, sans-serif;
  --font-mono: "JetBrains Mono", "IBM Plex Mono", monospace;

  /* 신호색 (DATA — 화면에서 가장 강한 색) */
  --color-up:        #F6465D;  /* 상승(KR 적) */
  --color-up-bright: #FF5C6C;  /* 상승 강조·플래시 */
  --color-up-weak:   rgba(246, 70, 93, 0.12);   /* 상승 배경 틴트 */
  --color-up-line:   rgba(246, 70, 93, 0.28);   /* 상승 보더 */
  --color-down:        #3EA6FF; /* 하락(KR 청) */
  --color-down-bright: #5CB8FF;
  --color-down-weak:   rgba(62, 166, 255, 0.12);
  --color-down-line:   rgba(62, 166, 255, 0.28);

  /* 세션 3계층 */
  --color-ses-open:   #E8A33D;  /* 정규장(개장) — 유일 warm */
  --color-ses-open-weak: rgba(232, 163, 61, 0.10);
  --color-ses-open-line: rgba(232, 163, 61, 0.22);
  --color-ses-ext:    #4FB6A6;  /* 연장(프리·애프터·주간·야간) */
  --color-ses-ext-weak:  rgba(79, 182, 166, 0.10);
  --color-ses-ext-line:  rgba(79, 182, 166, 0.22);
  /* 마감/휴장은 중립 토큰(muted) 재사용 */

  /* 인터랙션 아이리스 (크롬 — 얇게·작게만) */
  --color-iris:      #7C83FF;
  --color-iris-dim:  #4850A3;
  --color-iris-weak: rgba(124, 131, 255, 0.10);
  --color-iris-line: rgba(124, 131, 255, 0.28);

  /* 시스템 연결(LIVE) */
  --color-live: #3FB950;

  /* 텍스트 계층 */
  --color-text:       #E6EAF0;  /* 주 (AA on base-100) */
  --color-text-muted: #94A3B8;  /* 보조·라벨 (AA on base-100) */
  --color-text-faint: #64707F;  /* 3차·placeholder·비활성 (비필수만) */

  /* hairline (보더 — 밝기차 기반) */
  --color-hairline:        rgba(148, 163, 184, 0.10);
  --color-hairline-strong: rgba(148, 163, 184, 0.18);

  /* radius */
  --radius-xs: 3px;   /* 태그·초소형 배지 */
  --radius-sm: 5px;   /* 버튼·입력·탭·리스트행 */
  --radius-md: 10px;  /* 카드·패널·드롭다운 */
  --radius-lg: 14px;  /* 모달 */

  /* 그림자 — 다크에선 최소, 모달/드롭다운만 */
  --shadow-pop:   0 8px 28px rgba(0, 0, 0, 0.45);
  --shadow-modal: 0 24px 64px rgba(0, 0, 0, 0.60);

  /* 모션 */
  --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
  --dur-fast: 120ms;  /* 색 */
  --dur-tick: 260ms;  /* 가격 플래시(서명 모션) */
  --dur-move: 200ms;  /* transform */

  /* z-index (현행 z-50/60/100/200/9999 혼재 → 5단계로) */
  --z-sticky:  100;
  --z-overlay: 400;   /* 사이드바 플로팅 등 */
  --z-dropdown: 800;
  --z-modal:   1000;
  --z-toast:   1200;
}
```

간격·타이포·radius의 **의미 있는 스케일**(2층/3층에서 참조):

| 카테고리 | 스케일 (Tailwind 클래스) | 비고 |
|---|---|---|
| 간격 | `1`(4) `2`(8) `3`(12) `4`(16) `5`(20) `6`(24) | 4px 기수. **`1.5`/`2.5`/`3.5` 금지** |
| radius | `xs`3 · `sm`5 · `md`10 · `lg`14 · `full` | 현행 5단(md/lg/xl/2xl) 혼재 해소 |
| 텍스트 | `2xs`11 · `xs`12 · `sm`13 · `base`15 · `lg`20 | **`[9px]`·`[10px]` 폐기**, 11px 하한 |
| 굵기 | 400 · 500 · 600 · 700 | **`font-black`(900)·`extrabold`(800) 폐기** |

### 2-2. 2층 — 시맨틱 토큰 (역할 별칭)

| 그룹 | 토큰 | 값 | 용도 |
|---|---|---|---|
| 면 | `surface-app` | base-300 #0A0E14 | 앱 배경 (`bg-base-300`) |
| | `surface` | base-100 #141B26 | 카드·패널 (`bg-base-100`) |
| | `surface-sunken` | base-200 #10151E | 입력·표 헤더·hover |
| | `surface-raised` | #1D2632 | 드롭다운·활성 행·모달 헤더 |
| 텍스트 | `text` / `text-muted` / `text-faint` | 위 원시 | 3계층 |
| 보더 | `hairline` / `hairline-strong` | 위 원시 | 기본선 / hover·활성선 |
| 신호 | `up` / `down` (+weak/line/bright) | 위 원시 | **등락 — 최강 색** |
| 세션 | `ses-open` / `ses-ext` / (마감=muted) | 위 원시 | 3계층 |
| 크롬 | `accent`(=iris) / `accent-weak` / `accent-line` | iris | 선택·포커스·주버튼 |
| 시스템 | `live`(=success) / `danger`(=error) | 위 | 연결 / 위험 |

### 2-3. 3층 — 컴포넌트 토큰

```css
@theme {
  /* 카드/패널 */
  --panel-bg: var(--color-base-100);
  --panel-border: var(--color-hairline);
  --panel-radius: var(--radius-md);
  --panel-pad: 16px;          /* 기본 */
  --panel-pad-compact: 12px;  /* 밀집 */

  /* 배지 (공통 — 5-공통 참조) */
  --badge-h: 22px;
  --badge-pad-x: 8px;
  --badge-radius: var(--radius-xs);
  --badge-text: 11px;

  /* 버튼 */
  --btn-h-sm: 28px;  --btn-h: 34px;   /* HTS 밀도 (기본 40→34) */
  --btn-radius: var(--radius-sm);

  /* 입력 */
  --input-h: 34px;
  --input-bg: var(--color-base-200);
  --input-border: var(--color-hairline);
  --input-focus: var(--color-iris);
  --input-radius: var(--radius-sm);

  /* 표 */
  --row-h: 44px;
  --cell-pad-x: 12px;  --cell-pad-y: 10px;

  /* 리드아웃 태그 (서명) */
  --readout-h: 30px;
  --readout-min-w: 56px;
  --readout-radius: var(--radius-xs); /* 좌측만 */
  --readout-gutter: 10px;

  /* 차트 내부 (lightweight-charts) */
  --chart-grid: rgba(148, 163, 184, 0.07);
  --chart-axis-text: var(--color-text-muted);
  --chart-crosshair: var(--color-iris);
}
```

---

## 3. 간격·정렬 통일 규칙 (현행 불일치 → 수렴 토큰)

| # | 항목 | 현행 (파일:증상) | 수렴 규칙 |
|---|---|---|---|
| 1 | **폰트 크기 난립** | `text-[9px]`(StockChart timeframe·index overlay) `[10px]`(다수 배지·loading) `[11px]`(태그·부가) `text-xs`(12) 혼재 | 11px 하한 = `text-2xs`. 9px 전면 폐기(지수 overlay도 11px). 배지=11, 표/라벨=13(`text-sm`), 이름/주요=15(`text-base`) |
| 2 | **굵기 과잉** | `font-black`(가격·이름·헤딩 다수) `font-extrabold`(라벨·배지·탭 거의 전부) | 숫자 600 · 이름/헤딩 600 · 라벨 500 · 본문 400~500. **900/800 폐기**. 큰 리드아웃만 700 |
| 3 | **radius 혼재** | `rounded`(태그) `rounded-md`(배지) `rounded-lg`(버튼·입력·배지) `rounded-xl`(토글·행) `rounded-2xl`(카드) 병존 | 배지/태그=`rounded-xs`(3) · 버튼/입력/탭/행=`rounded-sm`(5) · 카드/패널/드롭다운=`rounded-md`(10) · 모달=`rounded-lg`(14) · pill=`rounded-full` |
| 4 | **카드 패딩 제각각** | HoldingsPanel `px-4 md:px-5 py-3.5` · StockChart `pt-3.5 pb-3.5 pl-3.5 pr-0` · index quote `px-6 py-5` · skeleton `p-4 md:p-5` | 카드 본문 = `p-4`(16). 밀집 카드 = `p-3`(12). 반응형 `md:px-5` 제거(단일 16). `3.5`(14px) 전면 제거 |
| 5 | **gap/space 난립** | `gap-0.5·1·1.5·2·2.5·3` `space-y-1.5·4·5` 혼재 | 인접 인라인=`gap-1`(4)~`gap-2`(8) · 블록 그룹=`gap-3`(12) · 패널 간 세로=`space-y-4`(16, 현행 5→4 통일) · `1.5`/`2.5` 제거 |
| 6 | **세션 배지 크기 2종** | HoldingsPanel·App 그리드 `px-4 h-8`(32px 큼) · index quote `px-2.5 py-1`(작음) | 공통 배지 토큰 = `h-[22px] px-2`(정규 데이터 밀도). 세션 배지도 동일 규격으로 축소 |
| 7 | **보더 알파 8종** | `/4 /6 /8 /10 /12 /15 /20 /25` 혼재 | 기본선=`hairline`(≈/10) · hover·활성·모달=`hairline-strong`(≈/18). 표 행 구분선=`hairline`. 2단계로 |
| 8 | **표면 불투명 혼재** | 카드 `bg-base-100/45`+`backdrop-blur-md`(대부분) · 사이드바 `bg-base-100`(불투명) · 모달 `bg-base-100` | 본문 카드=**불투명 `bg-base-100`**(글래스 제거) · 헤더만 `bg-base-100/70 backdrop-blur`(스크롤 위 반투명 의미) |
| 9 | **z-index 무체계** | `z-20 z-30 z-50 z-60 z-100 z-200 z-[9999] z-9999` | 5단계 토큰(`--z-sticky/overlay/dropdown/modal/toast`)만 |
| 10 | **모달 4종 제각각** | grid-chart·holdings-chart·holding-form·avgprice·confirm — teleport 여부·radius·패딩·헤더 상이 | 공통 모달 셸 스펙(5-공통)으로 통일. **전부 `<Teleport to="body">`** (backdrop-filter fixed 버그 근절) |
| 11 | **볼륨색 ↔ 캔들색 불일치** | 캔들 up=항상 rose(#f43f5e) / 볼륨 up은 KR=rose·US=emerald(#10b981) | 볼륨도 신호색 통일: up=`--color-up` @22%, down=`--color-down` @22% (시장 무관) |
| 12 | **평단선·MAX·세션이 amber 충돌** | 평단선 #f97316(오렌지) · MAX 배지 amber · 세션 amber(주간) 겹침 | 평단(개인 기준선)=`iris` / 세션 개장=`ses-open`(앰버 단독) / MAX=중립 muted 아웃라인 |

---

## 4. 폰트

### 선택과 근거

| 역할 | 폰트 | 근거 |
|---|---|---|
| UI (이름·라벨·헤딩·버튼) | **IBM Plex Sans** | typography.csv #31 "Financial Trust" — *"IBM Plex conveys trust and professionalism. Excellent for data."* 엔지니어링 태생이라 계측기 무드에 뿌리내림. 현행 Plus Jakarta Sans(친근·기하학)보다 도메인 정합. Google Fonts 1줄 교체, 폴백으로 Plus Jakarta 유지 |
| 수치 (가격·등락·표) | **JetBrains Mono** (유지) | tabular·모노 — 우측정렬 수치 정렬의 핵심. 현행 유지(무결) |

### 로딩 (index.html — 기존 `<link>` 교체)

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
```
수치 정렬 보장 — 전역 유틸(style.css):
```css
.font-mono { font-variant-numeric: tabular-nums; font-feature-settings: "tnum" 1; }
```

### 타입 스케일

| 토큰 | 크기 | 굵기 | 행간 | 자간 | 용도 |
|---|---|---|---|---|---|
| readout | 40px | 700 | 1.0 | -0.02em | 휴장 카드·지수 quote 대형 숫자(서명) |
| num-lg | 20px | 600 | 1.1 | -0.01em | 강조 수치 |
| base | 15px | 600 | 1.25 | -0.01em | 종목명·현재가·헤딩 |
| sm | 13px | 500/600 | 1.35 | 0 | 표 셀·버튼·라벨값 |
| xs | 12px | 500 | 1.4 | 0 | 보조 텍스트·캡션 |
| 2xs | 11px | 500 | 1.3 | +0.04em(uppercase) | 배지·미세 라벨(대문자엔 wide 자간) |

---

## 5. 컴포넌트별 리디자인 스펙

### 공통 요소 (먼저 정의 — 5종이 공유)

**배지 (`.badge-*` 통일)** — 현행 6종 크기·색을 하나로:
- 규격: `h-[22px] px-2 rounded-xs text-2xs font-medium tracking-wide`, 모노. 아이콘 동반 시 `gap-1`.
- 변형: `up`/`down`(신호 weak+line+색), `ses-open`(앰버), `ses-ext`(틸), `muted`(마감·중립), `accent`(iris — 티커·보유·카운트), `danger`(위험, **아이콘 필수**).
- 티커 배지·카운트 배지·"보유"·"휴장" 전부 이 토큰으로. **색 단독 금지** — 세션·위험은 아이콘 또는 텍스트 동반.

**버튼 (states 6종 필수)**:
| 변형 | 기본 | hover | active | disabled |
|---|---|---|---|---|
| primary | `accent`(iris) bg, white | iris 밝게 | iris-dim | opacity .45 + `cursor-not-allowed` |
| ghost | 투명 + hairline | `surface-sunken` + hairline-strong | surface-raised | opacity .45 |
| danger | 투명 + hairline | `up-weak` bg + up 텍스트 + 아이콘 | — | opacity .45 |
- 크기: sm `h-7`(28) · 기본 `h-[34px]`. radius `sm`(5). loading=스피너 대체 + `aria-busy`.
- **hover는 색 변화(120ms)만** — 현행 scale/그림자 리프트 제거.

**카드/패널**: `bg-base-100`(불투명) · `border-hairline` · `rounded-md`(10) · `p-4`. hover 가능 카드 = hover 시 `border-hairline-strong`만(그림자·scale·글로우 제거). 활성 카드 = `border-accent-line` + 상단 2px `bg-accent` 활성바.

**표 행**: 높이 44px · 셀 `px-3 py-2.5` · 헤더 `bg-base-200` `text-muted text-2xs uppercase tracking-wide` · hover 행 `bg-base-200/50` · 구분선 `border-hairline` · **텍스트 좌·숫자 우(tabular)·상태 중앙·액션 우**.

**모달 셸 (5종 통일)**: 전부 `<Teleport to="body">` · 오버레이 `bg-black/70` (blur 제거 — 성능/버그) · 패널 `bg-base-100 border-hairline-strong rounded-lg shadow-modal` · 헤더 `h-12 px-4 border-b-hairline` (티커배지+제목+닫기) · 본문 `p-4` · 푸터 우측정렬 `[취소][확인]`. 폭: 확인 384 · 폼 448 · 차트 max-5xl. `role="dialog" aria-modal` + ESC + `@click.self` 닫기 + 열릴 때 포커스 이동(현행 ConfirmDialog 패턴을 표준으로).

### 5-1. 앱 셸 (App.vue)

| 요소 | 리디자인 |
|---|---|
| 배경 | `bg-base-300`(#0A0E14). 유지 |
| 헤더 | **유일한 글래스**: `bg-base-100/70 backdrop-blur-lg border-b-hairline`, 높이 52px |
| 로고 | indigo 사각 유지하되 색=`bg-accent`(iris), **hover rotate-12·scale-110 제거** → hover `opacity-90`만. 글로우 그림자 제거 |
| 앱명 | "Stockpit" `font-mono` 유지, 굵기 900→700, 색 `text-muted` |
| LIVE 필 | `text-live`(green) — 시스템 연결 단일 의미. pulse 점 1개 유지. 끊김=`text-danger`+아이콘 |
| 지수 접기 헤더 | 버튼 `bg-base-100 border-hairline hover:border-hairline-strong rounded-sm`, indigo hover 테두리 제거. 텍스트 `text-muted` |
| 지수 quote 모드 | 대형 숫자 `readout`(40px/700) — 서명 리드아웃. 티커=accent 배지, 세션=3계층 배지, 등락=신호색 |
| 국내/미국 탭 | `tabs` 컨테이너 `bg-base-200 border-hairline rounded-sm p-0.5`. 활성 탭 = `bg-surface-raised text-text border-hairline-strong` + 하단 2px `bg-accent`. **활성색 indigo→중립+accent 언더라인** |
| 세션 배지(탭 옆) | 5색 분기 → 3계층: 정규장=`ses-open`, (주간·애프터·야간·거래중)=`ses-ext`, 장마감=`muted` |
| 차트 그리드 카드 | 활성 = `border-accent-line` + 상단 `bg-accent` 활성바(색만 토큰화). 비활성 hover = `border-hairline-strong`만. 드롭 대상 = `border-accent ring-1 ring-accent` |
| 빈 상태 | dashed `border-hairline-strong rounded-md`, 아이콘 `text-faint`, CTA 문구 유지 |
| 로딩 스켈레톤 | `bg-base-200` 펄스, radius 토큰 정합(카드 md·배지 xs) |

### 5-2. PortfolioSummaryBar

| 요소 | 리디자인 |
|---|---|
| compact 배지들 | `text-2xs`(11)~`text-sm`(13)로 정합, 굵기 900→600 |
| "미국"/"국내" 라벨 | 시장 구분이지 등락 아님 → 둘 다 `text-muted uppercase tracking-wide`, 시장 구분은 `KR`/`US` accent 배지로 |
| 손익 색 | 이익=`up`(빨강), 손실=`down`(파랑). (KR 관례: 플러스=빨강) |
| 손익률 배지 | 공통 배지 토큰(up-weak/down-weak) |
| 환율 | `font-mono text-muted`. ⓘ 툴팁 유지 |

> ⚠️ "미국/국내"는 **시장 라벨**이지 등락 신호가 아니다. 현행 국내=rose·미국=emerald는 신호색과 혼동 유발 → 시장은 **중립 라벨 + KR/US accent 배지**, 신호색(빨/파)은 **손익 부호에만**.

### 5-3. HoldingsPanel

| 요소 | 리디자인 |
|---|---|
| 패널 | `bg-base-100`(글래스 제거) `border-hairline rounded-md` |
| 섹션 헤더 | 접기 버튼 ghost, 아이콘 `text-accent`→`text-muted`, 카운트=accent 배지 |
| "추가" 버튼 | primary(iris) 버튼 토큰 |
| 세션 배지 열 | **크기 `px-4 h-8`→공통 배지(22px)**, 5색→3계층 |
| 국가 배지(KR/US) | accent 배지(중립)로 — 현행 rose/emerald 제거 |
| 현재가·손익·손익률 | 신호색. **플래시**: 상승=`up-weak` bg, 하락=`down-weak` bg, 260ms |
| 정규장 breakdown 부가줄 | `text-xs text-muted`, 굵기 정합 |
| 관리 버튼($/₩·수정·삭제) | ghost 버튼 토큰. 삭제 hover=`danger`(up-weak+아이콘) |
| 표 | 공통 표 토큰(행 44·헤더 muted·숫자 tabular 우정렬) |
| 드래그 오버 행 | `bg-accent-weak border-accent-line` |
| 폼 모달 | 공통 모달 셸. 입력=input 토큰, 라벨 `text-2xs uppercase tracking-wide text-muted`, 에러=`text-danger`+아이콘+`role=alert`+`aria-invalid` |
| 토스트 | success=live·warn=ses-open·error=danger 배경, 공통 배지 톤 |

### 5-4. UnifiedWatchlist (사이드바)

| 요소 | 리디자인 |
|---|---|
| 컨테이너 | `bg-base-100` |
| 헤더 | "관심 종목" `text-muted uppercase`, 연결점=`bg-live` 단일 pulse, 카운트=accent 배지 |
| 위치/닫기 버튼 | ghost 토큰 |
| KR/US/전체 탭 | App 탭과 동일 규격(중립+accent 언더라인) |
| 검색 입력 | input 토큰. `focus:border-accent` |
| 드롭다운 | `bg-base-100 border-hairline rounded-md shadow-pop` |
| 종목 행 | `bg-base-200/40 rounded-sm border-transparent`. hover=`bg-base-200 border-hairline`. **선택=`bg-accent-weak border-accent-line`+좌측 2px `bg-accent` 바** |
| 시장 배지(KR/US) | **중립 accent 배지**로 통일(신호색 분리) |
| 현재가 | `font-mono text-text`. 플래시=신호색 weak(260ms) |
| 등락률 | 공통 배지(up-weak/down-weak) |
| 삭제 버튼 | ghost, hover=danger+아이콘 |

### 5-5. StockChart (핵심 — 차트 내부 색 토큰 정합)

**차트 헤더**:
| 요소 | 리디자인 |
|---|---|
| 티커 배지 | accent 배지(iris) |
| 종목명 | `text-base`(15) 600, 색 `text-text` |
| 등락% | 신호색(up/down) |
| MAX 배지 | 현행 amber → **중립 muted 아웃라인 배지**(amber는 세션 개장 전용) |
| 실적 배지 | accent 배지(iris) |
| 현재가/등락액 | 신호색 + 플래시(up-weak/down-weak, 260ms) |
| 원화 환산줄 | 동일 신호색 |
| 타임프레임 탭·셀렉트 | 탭 토큰(중립+accent), `text-[9px]`→`text-2xs`(11), 셀렉트 input 토큰 |

**lightweight-charts 내부(initChart/updateChartData/watch 3곳 동기화)** — 하드코딩 HEX를 토큰과 일치:
```
캔들 up:   #F6465D  (--color-up)
캔들 down: #3EA6FF  (--color-down)          // 현행 #38bdf8 → 토큰값
볼륨 up:   rgba(246,70,93,0.22)  (up @22%)   // ★현행 US=emerald 버그 수정 → 신호색 통일
볼륨 down: rgba(62,166,255,0.22) (down @22%) // 시장 무관 동일
grid:      rgba(148,163,184,0.07) (--chart-grid)
축 텍스트: #94A3B8 (--color-text-muted)
크로스헤어: #7C83FF dashed (--color-iris), 라벨 bg=iris-dim
평단선:    #7C83FF dashed (--color-iris)      // ★현행 #f97316 오렌지 → iris
```
> **3곳 동시 수정 필수**: `initChart()`·`props.ticker` watch·`updateChartData()` 볼륨색 계산 — 하나만 고치면 종목 전환 시 옛색 잔존.

**서명 요소 — 리드아웃 태그 (가격 레일)**:
- 공통 형태: `h-[30px] min-w-[56px]`, `rounded-l-xs`(좌측만 3px), 우측 거터 flush, `font-mono` 2줄 중앙정렬(가격 11px/등락% 11px), 상/하/좌 1px 보더.
- **현재가 태그**: 채움 = 신호색 solid(`bg-up`/`bg-down`), 텍스트 white. 화면 최강 UI 요소 = 의도된 것.
- **평단 태그**: 채움 = `bg-iris-weak border-iris-line`, 텍스트 `text-iris` — "평단" 라벨 + 값.
- **정밀 포인터 노치(신규 서명 디테일)**: 태그 좌변 중앙에 6px 삼각(가격선을 정확히 가리킴). CSS border trick — 저비용. 색은 태그 채움색 상속.
- 지수 overlay 태그: `text-[9px]`→11px 승격.

### 5-6. ConfirmDialog
- 공통 모달 셸의 레퍼런스로 채택. 색만 토큰화(`btn-error`→danger, `btn-primary`→accent). radius `2xl`→`lg`(14).

---

## 6. 모션 규칙 (최소 — 오케스트레이션된 한 순간)

| 위치 | 모션 | 값 | 근거 |
|---|---|---|---|
| **가격 틱 플래시(서명 모션)** | 상승=up-weak / 하락=down-weak 배경 점등 후 페이드 | `--dur-tick` 260ms `--ease-out` | StockChart·Holdings·Watchlist **동일 토큰**(현행 250/300/800 제각각 → 260) |
| LIVE 연결점 | 느린 pulse 1개 | 유지 | 시스템 상태. ping 중첩 제거 |
| hover(카드·버튼·행) | 색·보더 전환만 | `--dur-fast` 120ms | 리프트/scale/그림자/글로우 **전면 제거** |
| 모달·드롭다운 | opacity fade | 150ms | 값만 통일 |
| 접기/펼치기 | height/opacity | `--dur-move` 200ms | 유지 |

**제거 대상(산발 효과=슬롭)**: 로고 rotate-12+scale-110, 카드 hover:scale/shadow-xl/글로우, `animate-ping` 남발, scale-105 플래시. `prefers-reduced-motion` 존중 — 플래시/펄스 무효화 미디어쿼리 추가.

---

## 7. 구현 순서 (frontend-engineer용 단계 분해)

1. **토큰 기반 교체** — `src/style.css` daisyUI 커스텀 테마(`stockpit`) + `@theme` 블록 교체, `index.html` 폰트 링크 교체, `data-theme="stockpit"`. 이 단계만으로 표면·텍스트·보더·primary 전파. **1차 육안 검증**(대비·표면 층위).
2. **공통 유틸/컴포넌트** — 배지·버튼·입력·카드·표행·모달 셸 토큰 유틸 확정. 리드아웃 태그(`.readout-tag` + 포인터 노치) 유틸 신설.
3. **즉흥 hue 치환** — `rose-*/sky-*/emerald-*/pink-*/cyan-*/amber-*/indigo-*` 리터럴 grep → 시맨틱 클래스(up/down/ses-open/ses-ext/accent/muted). 세션 5색→3계층 매핑 함수 정리.
4. **StockChart 차트 내부** — lightweight-charts 색 3곳 동기화, 리드아웃 태그 적용.
5. **간격·radius·굵기 정합** — `3.5`/`1.5`/`[9px]`/`font-black` 일괄 치환, 모달 5종 셸 통일(전부 Teleport).
6. **모션 정리** — 플래시 토큰 통일, 리프트/glow/rotate 제거, reduced-motion.
7. **검증** — `npm run test` + 브라우저 HMR 육안(대비 AA·정렬·플래시).

각 단계는 독립 커밋 가능. 1→2 완료 후 나머지 병렬 가능.

---

## 8. 자기검증 — AI 슬롭 판정표

| 금지 패턴 | 이 설계는 어떻게 다른가 |
|---|---|
| 보라→파랑 그라데이션 | 그라데이션 전무. 면은 단색 슬레이트, 층위는 밝기차. 아이리스는 얇은 보더/링에만 |
| **흑배경 + 형광 단색 액센트 하나만 튀기** | ★핵심 교정: indigo 단일 강조를 **강등**(크롬·<5% 면적), 화면 최강색을 **데이터(적/청 등락)**로 승격 |
| 템플릿 히어로(큰 숫자+작은 라벨+그라데이션) | 큰 숫자는 도메인 필연(quote readout), 그라데이션 없음. 서명은 **가격 레일 계측 태그**(트레이딩 고유) |
| 무사고 기본 폰트 조합 | IBM Plex Sans+JetBrains Mono — DB "Financial Trust" 근거 + 문자/수치 역할 분리, tabular 강제 |
| 산발적 애니메이션 남발 | 리프트·scale·rotate·glow·ping **제거**, 모션을 **가격 틱 플래시 한 순간**에 집중 |
| 무지개 색 남용 | 세션 5색→3계층, 한 색=한 의미 7색 고정. indigo 6의미 겸직 해소 |
| "비슷한 브리프면 같은 결과?" | 아니오 — KR 적/청 시맨틱, 세션 3계층, 가격 레일 서명, 크롬<데이터 위계는 시세 단말기 전용 결정 |
| 글래스모피즘 기본값 남용 | 전 카드 글래스 → **헤더 1곳**으로 축소, 본문은 무광 계기판. fixed 버그·성능 동시 해결 |
