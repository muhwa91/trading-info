<template>
  <!-- isolate: 카드가 자기 쌓임 맥락을 갖게 한다 — 리드아웃 태그(z-30)가 같은 z-30 인 상단 고정
       패널(App.vue «보유 상세» sticky)과 맞붙어, 스크롤로 카드가 패널 밑에 숨어도 태그만 위로 비쳤다(2026-10-07). -->
  <div
    class="chart-card-container isolate relative bg-base-100 border border-hairline rounded-md pt-3 pb-3 pl-3 pr-0 h-full flex flex-col justify-between overflow-hidden"
    :class="{ 'has-badges': hasHeaderBadges }"
  >

    <!-- 차트 헤더 (그리드 순서변경 드래그 핸들 — 여기를 잡았을 때만 드래그 시작) -->
    <div
      class="flex flex-col gap-2 mb-2 select-none pr-3 cursor-grab active:cursor-grabbing"
      @mousedown="emit('header-grab')"
      @mouseup="emit('header-release')"
    >
      <!-- 줄1: 좌(종목 블록) + 우(가격 블록) — 상단 정렬 -->
      <div class="flex items-start justify-between gap-2">

        <!-- 좌: 종목 정보 블록 -->
        <div class="flex flex-col gap-1 min-w-0">
          <!-- 1행: 티커 배지 + 종목명 (같은 줄, baseline 정렬). 지수는 배지 없이 이름만 -->
          <div class="flex items-baseline gap-2 min-w-0">
            <span v-if="!isIndex" class="px-1.5 py-0.5 rounded-xs text-2xs font-medium font-mono text-accent bg-accent-weak border border-accent-line tracking-wider leading-tight shrink-0">
              {{ ticker }}
            </span>
            <span class="text-base font-semibold text-white/90 leading-tight truncate" :title="name">{{ name }}</span>
            <!-- US 연장세션 헤드라인 세션 라벨(토스식 "애프터마켓에서") — 등락률이 어느 세션 기준인지 표기 -->
            <span
              v-if="usSessionHeadlineLabel"
              class="text-2xs font-medium text-base-content/45 tracking-wide leading-tight shrink-0 whitespace-nowrap"
            >{{ usSessionHeadlineLabel }}</span>
            <span
              v-if="formattedChangePercent !== null"
              :class="[
                'text-base font-medium font-mono leading-tight shrink-0',
                changePercent >= 0 ? 'text-up' : 'text-down'
              ]"
            >{{ formattedChangePercent }}</span>
          </div>
          <!-- US 연장세션: '정규장' 등락률 보조 줄(작고 보조적으로) — HoldingsPanel 2줄 표기와 동형 -->
          <span
            v-if="showUSExtChange"
            :class="[
              'text-2xs font-medium font-mono leading-tight opacity-75',
              regularChangePercent >= 0 ? 'text-up' : 'text-down'
            ]"
          >정규장 {{ formattedRegularChangePercent }}</span>
        </div>

        <!-- 우: 현재가 + 등락액 블록 — 4컬럼 grid [가격숫자 | 통화기호 | 화살표 | 등락숫자]. -->
        <!-- $줄·원화줄이 subgrid 로 동일 트랙 공유 → 가격숫자 우측정렬로 끝 맞음, 통화기호·화살표(▲▼)는 좌측정렬로 세로 정렬. 등락숫자는 화살표 뒤 좌측정렬로 밀착. -->
        <div class="grid grid-cols-[auto_auto_auto_auto] items-center shrink-0 gap-x-1 gap-y-1">
          <!-- 달러(또는 국내/지수) 현재가 + 등락액 -->
          <div class="grid grid-cols-subgrid col-span-4 items-center">
            <span
              class="justify-self-end text-base font-semibold font-mono transition-colors duration-260 rounded-xs px-1 py-0.5 leading-tight"
              :class="numCellClass(changePercent)"
            >{{ formattedPrice }}</span>
            <span
              class="justify-self-start text-xs font-medium font-mono leading-tight"
              :class="unitCellClass(changePercent)"
            >{{ priceUnit }}</span>
            <!-- 등락액(주가 변동) — 화살표/숫자 분리, 현재가와 동일한 변동 깜빡임 -->
            <span
              class="justify-self-start ml-2 text-xs font-medium font-mono leading-tight"
              :class="unitCellClass(changeAmount)"
            >{{ changeAmount !== null ? changeArrow : '' }}</span>
            <span
              v-if="changeAmount !== null"
              class="justify-self-start text-xs font-medium font-mono leading-tight transition-colors duration-260 rounded-xs px-1 py-0.5"
              :class="numCellClass(changeAmount)"
            >{{ changeNumOnly }}</span>
          </div>
          <!-- 원화 환산 줄 — 미국 주식이고 환율이 있을 때만 (달러 줄과 동일 효과·색) -->
          <div
            v-if="formattedKrwCurrentPrice !== null"
            class="grid grid-cols-subgrid col-span-4 items-center"
          >
            <span
              class="justify-self-end text-base font-semibold font-mono transition-colors duration-260 rounded-xs px-1 py-0.5 leading-tight"
              :class="numCellClass(changePercent)"
            >{{ krwCurrentPrice.toLocaleString() }}</span>
            <span
              class="justify-self-start text-xs font-medium font-mono leading-tight"
              :class="unitCellClass(changePercent)"
            >원</span>
            <span
              class="justify-self-start ml-2 text-xs font-medium font-mono leading-tight"
              :class="unitCellClass(krwChangeAmount)"
            >{{ krwChangeNumOnly !== null ? krwChangeArrow : '' }}</span>
            <span
              v-if="krwChangeNumOnly !== null"
              class="justify-self-start text-xs font-medium font-mono leading-tight transition-colors duration-260 rounded-xs px-1 py-0.5"
              :class="numCellClass(krwChangeAmount)"
            >{{ krwChangeNumOnly }}</span>
          </div>
        </div>
      </div>

      <!-- 줄2: 보조 배지(MAX·실적) 좌 + 타임프레임 우 (넓으면 버튼 그리드, 좁으면 컴팩트 셀렉트) -->
      <div class="flex items-center justify-between gap-2">
        <!-- 좌: 보조 배지 그룹 (MAX·실적) -->
        <div class="flex items-center gap-1 flex-wrap min-w-0">
          <span
            v-if="!isIndex && maxPrice !== null"
            class="px-1.5 py-0.5 rounded-xs text-2xs font-medium font-mono text-base-content/55 bg-base-200/60 border border-hairline-strong leading-tight shrink-0"
          >MAX {{ formattedMaxPrice }}</span>
          <span
            v-if="earningsDate"
            class="earnings-badge px-1.5 py-0.5 rounded-xs text-2xs font-medium font-mono text-accent bg-accent-weak border border-accent-line leading-tight shrink-0"
          >실적 {{ earningsDate }}</span>
        </div>

        <!-- 우(넓은 폭): 타임프레임 버튼 그리드 -->
        <div class="timeframe-row items-center shrink-0">
          <div class="tabs tabs-boxed bg-base-200 p-0.5 rounded-sm border border-hairline">
            <button
              v-for="tf in timeframes"
              :key="tf.value"
              @click.stop="changeTimeframe(tf.value)"
              @mousedown.stop
              :class="[
                'tab tab-xs rounded-sm font-medium transition-colors duration-120 cursor-pointer text-2xs',
                selectedTimeframe === tf.value
                  ? 'tab-active bg-surface-raised border border-accent-line text-base-content'
                  : 'text-base-content/35 hover:text-base-content/70 border border-transparent'
              ]"
            >{{ tf.label }}</button>
          </div>
        </div>

        <!-- 우(좁은 폭, 400px 미만): 컴팩트 셀렉트 -->
        <div class="timeframe-row4 items-center shrink-0">
          <select
            class="timeframe-select-compact input input-xs bg-base-200 border border-hairline rounded-sm font-medium font-mono text-2xs text-base-content/70 focus:outline-none focus:border-accent cursor-pointer"
            :value="selectedTimeframe"
            @change.stop="changeTimeframe($event.target.value)"
            @mousedown.stop
            aria-label="타임프레임 선택"
          >
            <option
              v-for="tf in timeframes"
              :key="tf.value"
              :value="tf.value"
            >{{ tf.label }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Chart Canvas Wrapper -->
    <!-- min-h-0: flex-1 이 카드 잔여 높이(카드-헤더)에 맞게 축소되도록 허용. 과거 min-h-42.5(170px) 는
         짧은 4차트 카드에서 헤더+170px 가 카드 높이를 넘겨 overflow-hidden 에 하단(시간축)이 잘렸다. -->
    <div class="flex-1 w-full relative min-h-0 flex" ref="chartWrapper">
      <!-- Lightweight Chart container (차트+y축은 래퍼폭 - OVERLAY_GUTTER, 우측 거터에 오버레이 위치) -->
      <div class="h-full w-full" ref="chartContainer"></div>

      <!-- 리드아웃 태그 — 가격 레일 (설계서 서명 요소) -->
      <!-- 현재가 태그: 신호색 채움 + 좌측 포인터 노치 (화면 최강 UI 요소) -->
      <div
        v-if="currentPrice !== null && priceCoordinate !== null"
        :class="[
          'readout-tag absolute z-30 flex flex-col items-center justify-center px-1 pointer-events-none select-none text-white font-mono leading-none border-y border-l rounded-l-xs',
          changePercent >= 0 ? 'bg-up border-up' : 'bg-down border-down'
        ]"
        :style="{
          top: priceCoordinate + 'px',
          right: CHART_GUTTER + 'px',
          transform: 'translateY(-50%)',
          width: priceAxisWidth + 'px',
          height: OVERLAY_HEIGHT + 'px',
          '--readout-notch': changePercent >= 0 ? 'var(--color-up)' : 'var(--color-down)'
        }"
      >
        <div class="text-2xs font-semibold mb-0.5 tracking-tight">{{ formattedPrice }}</div>
        <div class="text-2xs opacity-95 whitespace-nowrap">{{ formattedChangePercent }}</div>
      </div>

      <!-- 평단 태그: 아이리스 (개인 기준선) -->
      <div
        v-if="!isIndex && avgPrice !== null && avgPriceCoordinate !== null"
        class="readout-tag absolute z-20 flex flex-col items-center justify-center px-1 pointer-events-none select-none text-white font-mono leading-none border-y border-l rounded-l-xs bg-accent border-accent"
        :style="{
          top: avgTagCoordinate + 'px',
          right: CHART_GUTTER + 'px',
          transform: 'translateY(-50%)',
          width: priceAxisWidth + 'px',
          height: OVERLAY_HEIGHT + 'px',
          '--readout-notch': 'var(--color-accent)'
        }"
      >
        <div class="text-2xs font-medium mb-0.5 tracking-tight opacity-90">평단</div>
        <div class="text-2xs font-semibold">{{ isKorean ? Math.round(avgPrice).toLocaleString() : avgPrice.toFixed(2) }}</div>
      </div>
    </div>

    <!-- 평단가 설정 모달 -->
    <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="showModal"
        class="fixed inset-0 z-1000 flex items-center justify-center bg-black/70 p-4"
        @click.self="closeModal"
        role="dialog"
        aria-modal="true"
        :aria-label="`${ticker} 평단가 설정`"
      >
        <div class="bg-base-100 border border-hairline-strong rounded-lg p-4 w-full max-w-sm shadow-modal flex flex-col gap-4 font-sans relative">
          <!-- 모달 헤더 -->
          <div class="flex items-center justify-between border-b border-hairline pb-3">
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded-xs text-2xs font-medium font-mono text-accent bg-accent-weak border border-accent-line tracking-wider">
                {{ ticker }}
              </span>
              <h3 class="text-sm font-semibold text-white">평단가 설정</h3>
            </div>
            <button
              @click="closeModal"
              class="w-7 h-7 flex items-center justify-center rounded-sm text-base-content/40 hover:text-white hover:bg-base-200/60 transition-colors duration-150 cursor-pointer"
              aria-label="모달 닫기"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- 종목명 -->
          <div class="text-xs text-base-content/50 font-medium">
            종목명: <span class="text-white/90 font-semibold">{{ name }}</span>
          </div>

          <!-- 평단가 입력 -->
          <div class="form-control gap-2">
            <label class="label py-0 select-none" for="avg-price-input">
              <span class="label-text text-2xs font-medium text-base-content/50 uppercase tracking-widest">평균단가</span>
            </label>
            <div class="join w-full">
              <input
                id="avg-price-input"
                v-model="modalAvgPrice"
                type="number"
                step="0.01"
                :placeholder="isKorean ? '예: 25500' : '예: 180.00'"
                class="input input-sm input-bordered join-item flex-1 font-medium font-mono focus:outline-none focus:border-accent bg-base-200/60 text-sm"
              />
              <span class="join-item flex items-center px-3 bg-base-200/40 border border-hairline border-l-0 text-xs font-medium text-base-content/50 rounded-r-sm">
                {{ isKorean ? '원' : 'USD' }}
              </span>
            </div>
          </div>

          <!-- 액션 버튼 -->
          <div class="flex items-center justify-between border-t border-hairline pt-3 mt-1">
            <button @click="resetModalFields" class="btn btn-xs btn-ghost text-error/70 hover:text-error hover:bg-error/8 cursor-pointer font-medium">
              초기화
            </button>
            <div class="flex gap-2">
              <button @click="closeModal" class="btn btn-xs btn-ghost text-base-content/50 cursor-pointer font-medium">취소</button>
              <button @click="saveModalFields" class="btn btn-xs btn-primary cursor-pointer px-4 font-semibold">저장</button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { createChart, CandlestickSeries, HistogramSeries } from 'lightweight-charts';
import { isCoordinateVisible, resolveAvgTagCoordinate } from '../utils/chartHelpers.js';
import { usExtHeadlineLabel } from '../utils/sessionBadge.js';

// ── props ──────────────────────────────────────────────────────────────────
const props = defineProps({
  ticker: {
    type: String,
    required: true
  },
  name: {
    type: String,
    required: true
  },
  currentPrice: {
    type: Number,
    default: null
  },
  changeAmount: {
    type: Number,
    default: null
  },
  changePercent: {
    type: Number,
    default: null
  },
  // US 연장세션 정규장 등락률(당일 정규장 종가 vs 직전거래일 종가) — 보조 줄용. KR·지수·정규장은 null.
  regularChangePercent: {
    type: Number,
    default: null
  },
  // US 세션 문자열(PRE/REGULAR/AFT/EXT_NIGHT/CLOSED). 연장세션일 때만 정규장 보조 줄을 켠다.
  usSession: {
    type: String,
    default: ''
  },
  candles: {
    type: Array,
    required: true
  },
  session: {
    type: String,
    default: ''
  },
  timeframe: {
    type: String,
    default: '3m'
  },
  usdKrwRate: {
    type: Number,
    default: 1380.00
  },
  // 포트폴리오 대시보드에서 주입하는 보유 평단가 (optional).
  // 값이 있으면 localStorage 대신 이 값을 평단선에 사용한다.
  // 모달에서 수동 수정 시에는 여전히 localStorage에도 저장한다.
  averagePrice: {
    type: Number,
    default: null
  }
});

// ── emits ──────────────────────────────────────────────────────────────────
const emit = defineEmits(['timeframe-change', 'header-grab', 'header-release']);

// ── 레이아웃 상수 ─────────────────────────────────────────────────────────
// 차트 우측 여백(px): 차트는 래퍼폭 - CHART_GUTTER 로 렌더됨(가격축 오른쪽 끝 = 래퍼 우측에서 10px 지점).
// 오버레이는 right:CHART_GUTTER 에 폭 priceAxisWidth(런타임 가격축 폭) 로 놓여 가격축 영역에
// 정확히 겹치고, 캔들 플롯 영역(가격축 왼쪽)은 침범하지 않는다.
const CHART_GUTTER = 10;
// 오버레이/가격축 최소 폭(px). lightweight-charts 가격축 폭은 가격 텍스트 자릿수에 따라 동적이라,
// 짧은 가격(예: 3자리)에서도 2줄 라벨(가격+등락률)이 잘리지 않도록 축 minimumWidth 하한으로 쓴다.
const OVERLAY_MIN_WIDTH = 56;
// 최초 시야 프레이밍에서 마지막 봉 우측에 두는 여백(봉 단위). 마지막 캔들이 우측
// 가격축/라벨에 밀착하지 않도록 한 봉만큼 비운다(지수·개별 종목 공통).
const RIGHT_MARGIN_BARS = 1;
// 가격 오버레이(현재가·평단) 공통 고정 높이(px). 리드아웃 태그 스펙(설계서 §5-5: 30px).
// 동적 폭(priceAxisWidth)은 유지, 높이만 설계서 값으로 통일(내용은 justify-center 세로 중앙).
const OVERLAY_HEIGHT = 30;

// ── 차트 내부 색 토큰 (설계서 §5-5 — initChart·ticker watch·updateChartData 3곳 동기화) ──
// 국내·미국 구분 없이 상승=빨강(up), 하락=파랑(down)으로 통일. 볼륨은 시장 무관 @22% 틴트.
const CHART_UP = '#F6465D';    // --color-up
const CHART_DOWN = '#3EA6FF';  // --color-down
const VOL_UP = 'rgba(246, 70, 93, 0.22)';    // up @22%
const VOL_DOWN = 'rgba(62, 166, 255, 0.22)'; // down @22% (시장 무관)
const CHART_IRIS = '#7C83FF';     // --color-accent (크로스헤어·평단선)
const CHART_IRIS_DIM = '#4850A3'; // --color-accent-dim (크로스헤어 라벨 bg)
const CHART_GRID = 'rgba(148, 163, 184, 0.07)'; // --chart-grid
const CHART_AXIS_TEXT = '#94A3B8'; // --color-muted

// ── 차트 인스턴스 refs (DOM 외 내부 상태) ─────────────────────────────────
const chartWrapper = ref(null);
const chartContainer = ref(null);

const chart = ref(null);
const candlestickSeries = ref(null);
const volumeSeries = ref(null);
const resizeObserver = ref(null);

// ── 반응형 상태 (data) ─────────────────────────────────────────────────────
const selectedTimeframe = ref('3m');
const priceFlash = ref('');
const flashTimeout = ref(null);
const priceLine = ref(null);
const shouldFitContent = ref(true);
const priceCoordinate = ref(null);
// 런타임 가격축 폭(px) — 오버레이 폭을 여기에 동기화해 라벨이 축 안에 정확히 들어가게 한다.
const priceAxisWidth = ref(OVERLAY_MIN_WIDTH);
const hasRenderedData = ref(false);
const lastCandlesCount = ref(0);
// 직전 currentPrice 를 컴포넌트가 직접 추적: Vue watch 는 동일값이면 트리거하지 않아
// WS 가 같은 가격을 중복 수신하면 봉 갱신이 멈춰 보이는 엣지케이스를 방어한다.
const lastTrackedPrice = ref(null);
const isIndex = ref(
  props.ticker === 'NQ=F' || props.ticker === 'KOSPI_NIGHT' || props.ticker === 'KOSPI200'
);
const maxPrice = ref(null);
const maxPriceCoordinate = ref(null);
const avgPrice = ref(null);
const avgPriceCoordinate = ref(null);
const avgPriceLine = ref(null);
const showModal = ref(false);
const modalAvgPrice = ref('');
const earningsDate = ref(null);

const timeframes = [
  { label: '1분', value: '1m' },
  { label: '3분', value: '3m' },
  { label: '5분', value: '5m' },
  { label: '10분', value: '10m' },
  { label: '30분', value: '30m' },
  { label: '1시', value: '1h' },
  { label: '일봉', value: '1d' }
];

// ── computed ───────────────────────────────────────────────────────────────
// 헤더 줄2에 배지(MAX·실적)가 있는 카드인지. 배지 있는 카드는 좁은 폭에서 배지+버튼탭이
// 한 줄에 안 들어가 배지가 2줄로 깨진다 → 더 넓은 폭에서 컴팩트 셀렉트로 전환(아래 @container 참조).
const hasHeaderBadges = computed(
  () => (!isIndex.value && maxPrice.value !== null) || earningsDate.value !== null
);

const isKorean = computed(() => {
  // KRX 코드: .KS/.KQ 접미사, 6자리 숫자, 또는 신형 영숫자 코드(예: 0167A0)
  const t = props.ticker;
  return /(\.KS|\.KQ)$/i.test(t) || /^\d{4}[0-9A-Za-z]{2}$/.test(t) || /^\d+$/.test(t);
});

// 미국 주식일 때 원화 환산 현재가 (환율이 없거나 0이면 null)
const krwCurrentPrice = computed(() => {
  if (isKorean.value || isIndex.value) return null;
  if (!props.currentPrice || !props.usdKrwRate || props.usdKrwRate <= 0) return null;
  return Math.round(props.currentPrice * props.usdKrwRate);
});

// 미국 주식일 때 원화 환산 증감액
const krwChangeAmount = computed(() => {
  if (isKorean.value || isIndex.value) return null;
  if (props.changeAmount === null || !props.usdKrwRate || props.usdKrwRate <= 0) return null;
  return Math.round(props.changeAmount * props.usdKrwRate);
});

// 원화 현재가 포맷 (594,500원)
const formattedKrwCurrentPrice = computed(() => {
  if (krwCurrentPrice.value === null) return null;
  return `${krwCurrentPrice.value.toLocaleString()}원`;
});

// ── 가격/등락 정렬용: 통화기호를 별도 컬럼으로 분리(헤더 우측 4컬럼 grid) ──
// 통화 단위($·원): 미국=$, 국내=원, 지수=없음
const priceUnit = computed(() => (isIndex.value ? '' : isKorean.value ? '원' : '$'));
// 등락 — 화살표·숫자를 별도 셀로 분리(화살표 세로정렬 + 숫자 우측정렬). 단위는 또 별도 셀.
const changeArrow = computed(() => ((props.changeAmount ?? 0) >= 0 ? '▲' : '▼'));
const changeNumOnly = computed(() => {
  if (props.changeAmount === null) return null;
  const abs = Math.abs(props.changeAmount);
  return isKorean.value
    ? Math.round(abs).toLocaleString()
    : abs.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});
const krwChangeArrow = computed(() => ((krwChangeAmount.value ?? 0) >= 0 ? '▲' : '▼'));
const krwChangeNumOnly = computed(() =>
  krwChangeAmount.value === null ? null : Math.abs(krwChangeAmount.value).toLocaleString(),
);
// 숫자 셀 클래스(플래시 틴트 박스 + 신호색). sign=색 기준 부호
function numCellClass(sign) {
  if (priceFlash.value === 'up') return 'bg-up-weak text-up';
  if (priceFlash.value === 'down') return 'bg-down-weak text-down';
  return sign >= 0 ? 'text-up' : 'text-down';
}
// 단위 셀 클래스(박스 없이 신호색만 — 숫자와 색 일치, 플래시 방향 우선)
function unitCellClass(sign) {
  if (priceFlash.value === 'up') return 'text-up';
  if (priceFlash.value === 'down') return 'text-down';
  return sign >= 0 ? 'text-up' : 'text-down';
}

const formattedPrice = computed(() => {
  if (props.currentPrice === null) return '---';
  if (isKorean.value) {
    return props.currentPrice.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }
  return props.currentPrice.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});

const formattedMaxPrice = computed(() => {
  if (maxPrice.value === null) return '---';
  if (isKorean.value) {
    return `${Math.round(maxPrice.value).toLocaleString()}원`;
  }
  return `${maxPrice.value.toFixed(2)}$`;
});

const formattedAvgPrice = computed(() => {
  if (avgPrice.value === null) return '---';
  if (isKorean.value) {
    return `평단 ${avgPrice.value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  }
  return `평단 ${avgPrice.value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
});

const formattedHeaderAvgPrice = computed(() => {
  if (avgPrice.value === null) return '';
  if (isKorean.value) {
    return `평단: ${Math.round(avgPrice.value).toLocaleString()}원`;
  }
  return `평단: ${avgPrice.value.toFixed(2)}$`;
});

const formattedChangePercent = computed(() => {
  if (props.changePercent === null) return '0.00%';
  const sign = props.changePercent >= 0 ? '+' : '';
  return `${sign}${props.changePercent.toFixed(2)}%`;
});

// US 연장세션 헤드라인 등락률 앞 세션 라벨(토스식 "애프터마켓에서"). 헤드라인 등락률(change_percent)이
// 어느 세션 기준인지 표기만 한다 — 숫자 재계산 없음. KR·지수·정규장·장마감은 '' (라벨 없음).
const usSessionHeadlineLabel = computed(() => {
  if (isKorean.value || isIndex.value) return '';
  // AFT/EXT_NIGHT: 헤드라인 base가 실제 당일 종가일 때(=정규장 보조줄이 켜질 때, regularChangePercent non-null)만 라벨.
  //   regular_close cold 폴백 순간엔 숫자가 '통합'이라 "애프터마켓에서" 라벨을 숨겨 라벨↔숫자 불일치 방지.
  // PRE: base가 prevRegular인 게 정상 → 항상 라벨.
  if (props.usSession !== 'PRE' && props.regularChangePercent === null) return '';
  return usExtHeadlineLabel(props.usSession);
});

// US 연장 세션(프리/애프터/야간)에서만 '정규장' 등락률 보조 줄을 노출한다(HoldingsPanel.showUSExtBreakdown 과 동형).
// 정규장·장마감·KR·지수, 또는 regular_change_percent 부재 시엔 1줄 유지(회귀 방지).
const showUSExtChange = computed(() => {
  if (isKorean.value || isIndex.value) return false;
  if (props.regularChangePercent === null) return false;
  return ['PRE', 'AFT', 'EXT_NIGHT'].includes(props.usSession);
});

const formattedRegularChangePercent = computed(() => {
  if (props.regularChangePercent === null) return null;
  const sign = props.regularChangePercent >= 0 ? '+' : '';
  return `${sign}${props.regularChangePercent.toFixed(2)}%`;
});

// 평단 태그 표시 좌표 — 현재가 태그와 겹치면(가격 근접) 밀어내 글씨 겹침 방지.
// 최소 간격 = 태그 높이 + 2px(두 30px 박스가 안 포개지려면 중심 간격 ≥ 30px).
const avgTagCoordinate = computed(() =>
  resolveAvgTagCoordinate(avgPriceCoordinate.value, priceCoordinate.value, OVERLAY_HEIGHT + 2)
);

// ── methods ────────────────────────────────────────────────────────────────
function initChart() {
  const container = chartContainer.value;
  if (!container) return;

  chart.value = createChart(container, {
    layout: {
      background: { type: 'solid', color: 'transparent' },
      textColor: CHART_AXIS_TEXT,
      fontSize: 10,
      fontFamily: '"JetBrains Mono", "IBM Plex Sans", system-ui, sans-serif'
    },
    grid: {
      vertLines: { color: CHART_GRID },
      horzLines: { color: CHART_GRID }
    },
    localization: {
      timeFormatter: (time) => {
        if (typeof time === 'number') {
          const date = new Date(time * 1000);
          return new Intl.DateTimeFormat('ko-KR', {
            timeZone: 'Asia/Seoul',
            hour12: false,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
          }).format(date);
        }
        return time;
      }
    },
    crosshair: {
      mode: 1, // Magnet
      vertLine: {
        color: CHART_IRIS,
        width: 1,
        style: 3, // dashed
        labelBackgroundColor: CHART_IRIS_DIM,
      },
      horzLine: {
        color: CHART_IRIS,
        width: 1,
        style: 3,
        labelBackgroundColor: CHART_IRIS_DIM,
      }
    },
    timeScale: {
      borderVisible: false,
      timeVisible: false,
      // 새 봉이 생기면 우측 끝(실시간)에 있을 때 자동으로 시야를 이동 → 차트가 자연스럽게 따라감
      shiftVisibleRangeOnNewBar: true,
      tickMarkFormatter: (time, tickMarkType, locale) => {
        if (typeof time === 'number') {
          const date = new Date(time * 1000);
          const isDate = tickMarkType <= 2;
          const options = {
            timeZone: 'Asia/Seoul',
            hour12: false,
          };
          if (isDate) {
            options.month = '2-digit';
            options.day = '2-digit';
          } else {
            options.hour = '2-digit';
            options.minute = '2-digit';
          }
          return new Intl.DateTimeFormat('ko-KR', options).format(date);
        }
        return time;
      }
    },
    rightPriceScale: {
      borderVisible: false,
      // 축 폭은 가격 자릿수에 따라 동적. minimumWidth로 하한만 보장하고, 오버레이 폭은
      // 런타임 축 폭(priceAxisWidth)에 동기화한다. (과거 width:54는 PriceScaleOptions에 없는
      // 무효 옵션이라 무시돼, 축이 54px보다 좁은 종목에서 고정 54px 라벨이 플롯을 침범했음)
      minimumWidth: OVERLAY_MIN_WIDTH,
      scaleMargins: {
        top: 0.1,
        bottom: 0.25
      }
    }
  });

  const upColor = CHART_UP;
  const downColor = CHART_DOWN;

  candlestickSeries.value = chart.value.addSeries(CandlestickSeries, {
    upColor: upColor,
    downColor: downColor,
    borderVisible: false,
    wickUpColor: upColor,
    wickDownColor: downColor,
    lastValueVisible: false, // Hide native y-axis price label!
    priceLineVisible: true,  // Show price line natively!
    priceLineStyle: 2,       // Dashed style
    priceLineWidth: 1.5,
    priceFormat: {
      type: 'price',
      precision: isKorean.value ? 0 : 2,
      minMove: isKorean.value ? 1 : 0.01
    }
  });

  volumeSeries.value = chart.value.addSeries(HistogramSeries, {
    priceScaleId: 'volume', // Isolate volume scale to prevent label conflicts
    priceFormat: {
      type: 'volume'
    },
    lastValueVisible: false,
    priceLineVisible: false
  });

  chart.value.priceScale('volume').applyOptions({
    visible: false, // Hide the volume scale
    scaleMargins: {
      top: 0.75, // volume at the bottom 25%
      bottom: 0
    }
  });

  updateChartData(props.candles);

  // Subscribe to visible range changes (zoom/scroll)
  chart.value.timeScale().subscribeVisibleTimeRangeChange(() => {
    updateCoordinate();
  });

  // Handle Resize dynamically (observe the parent wrapper)
  const wrapper = chartWrapper.value;
  resizeObserver.value = new ResizeObserver((entries) => {
    if (entries.length === 0 || !chart.value) return;
    const { width, height } = entries[0].contentRect;
    // 우측 CHART_GUTTER만큼 빼서 차트가 우측으로 더 확장되고, 오버레이(priceAxisWidth 폭)가 위에 겹치게 함
    const chartWidth = Math.max(0, width - CHART_GUTTER);
    requestAnimationFrame(() => {
      if (chart.value) {
        chart.value.resize(chartWidth, height);
        if (chartWidth > 0 && shouldFitContent.value) {
          // fitContent(전체 데이터 꽉 채움 → 마지막 봉 밀착) 대신 공통 프레이밍을 써
          // 개별 종목·지수가 동일한 우측 여백을 갖게 한다(라벨 밑에 캔들이 들어가지 않음).
          frameVisibleRange();
          shouldFitContent.value = false;
        }
        nextTick(() => {
          updateCoordinate();
        });
      }
    });
  });
  resizeObserver.value.observe(wrapper);
}

function updateChartData(candles) {
  if (!candlestickSeries.value || !volumeSeries.value || !candles || candles.length === 0) return;

  // Check if data is intraday (uses UNIX timestamps) and toggle time scale labels dynamically
  const hasTime = typeof candles[0].time === 'number';
  chart.value.timeScale().applyOptions({
    timeVisible: hasTime,
    secondsVisible: false
  });

  const chartCandles = candles.map(c => ({
    time: c.time,
    open: c.open,
    high: c.high,
    low: c.low,
    close: c.close
  }));

  if (chartCandles.length > 0 && props.currentPrice !== null) {
    const lastIdx = chartCandles.length - 1;
    chartCandles[lastIdx].close = props.currentPrice;
    if (props.currentPrice > chartCandles[lastIdx].high) {
      chartCandles[lastIdx].high = props.currentPrice;
    }
    if (props.currentPrice < chartCandles[lastIdx].low) {
      chartCandles[lastIdx].low = props.currentPrice;
    }
  }

  // 볼륨색: 시장 무관 신호색 통일 (up=빨강 @22% / down=파랑 @22%)
  const chartVolumes = candles.map((c, idx) => ({
    time: c.time,
    value: c.volume,
    color: chartCandles[idx].close >= chartCandles[idx].open ? VOL_UP : VOL_DOWN
  }));

  // 최초 렌더·타임프레임/종목 변경·봉 개수 감소(데이터 교체)면 전체 재설정,
  // 그 외(실시간 갱신·새 봉 추가)는 증분 update() 로 처리해 부드럽게 움직이고
  // 새 봉이 생겨도 자동 스크롤(shiftVisibleRangeOnNewBar)로 최신 봉이 화면에 따라온다.
  const isFullReload = !hasRenderedData.value
    || shouldFitContent.value
    || candles.length < lastCandlesCount.value;

  if (isFullReload) {
    candlestickSeries.value.setData(chartCandles);
    volumeSeries.value.setData(chartVolumes);
    hasRenderedData.value = true;
  } else {
    // 직전 마지막 봉부터 끝까지 update(): 마지막 봉 가격 갱신 + 새로 생긴 봉(들) 추가
    const start = Math.max(0, lastCandlesCount.value - 1);
    for (let i = start; i < chartCandles.length; i++) {
      candlestickSeries.value.update(chartCandles[i]);
      volumeSeries.value.update(chartVolumes[i]);
    }
  }
  lastCandlesCount.value = candles.length;

  if (shouldFitContent.value && chartContainer.value && chartContainer.value.clientWidth > 0) {
    frameVisibleRange();
    shouldFitContent.value = false;
  }

  nextTick(() => {
    updateCoordinate();
  });
}

// 최초 1회 시야 프레이밍(지수·개별 종목 공통 경로 — fitContent 분기 제거로 우측 여백 통일).
// 최근 showCount봉을 보여주되, 마지막 봉 우측에 RIGHT_MARGIN_BARS만큼 여백을 둬
// 캔들이 우측 가격축/라벨에 닿지 않게 한다.
function frameVisibleRange() {
  const container = chartContainer.value;
  const len = props.candles ? props.candles.length : 0;
  if (!chart.value || !container || container.clientWidth <= 0 || len === 0) return;
  // 개별 종목은 60봉(3분봉 기준 3시간), 지수는 100봉 노출
  const showCount = isIndex.value ? 100 : 60;
  // from 을 0 미만으로 내려가지 않게 클램프 → 첫 봉 왼쪽 빈 공간 제거.
  // to 는 마지막 봉(len-1) 뒤로 RIGHT_MARGIN_BARS만큼 → 우측 라벨과의 여백 확보.
  chart.value.timeScale().setVisibleLogicalRange({
    from: Math.max(0, len - showCount),
    to: len - 1 + RIGHT_MARGIN_BARS,
  });
}

function changeTimeframe(value) {
  if (selectedTimeframe.value === value) return;
  selectedTimeframe.value = value;
  shouldFitContent.value = true;
  lastTrackedPrice.value = null; // 타임프레임 전환 시 추적 가격 초기화
  emit('timeframe-change', value);
}

function updateCoordinate() {
  // 런타임 가격축 폭을 오버레이 폭에 반영(가격 자릿수 변화·리사이즈 시 갱신). 0이면 무시.
  if (chart.value) {
    const w = chart.value.priceScale('right').width();
    if (w > 0) priceAxisWidth.value = w;
  }

  if (!candlestickSeries.value) {
    priceCoordinate.value = null;
    maxPriceCoordinate.value = null;
    avgPriceCoordinate.value = null;
    return;
  }

  // 차트 캔버스 높이: 상단·하단 클리핑 공통 기준
  const chartHeight = chartWrapper.value ? chartWrapper.value.clientHeight : Infinity;

  // 현재가 좌표 — 상단(< 0)·하단(> chartHeight) 모두 클리핑
  if (props.currentPrice !== null) {
    const coordinate = candlestickSeries.value.priceToCoordinate(props.currentPrice);
    priceCoordinate.value = isCoordinateVisible(coordinate, chartHeight) ? coordinate : null;
  } else {
    priceCoordinate.value = null;
  }

  // 최고가 및 평단가 좌표 계산
  if (!isIndex.value && chart.value && props.candles && props.candles.length > 0) {
    // 평단가 좌표 계산 — 상단·하단 모두 클리핑 (범위 밖이면 오버레이 미노출)
    if (avgPrice.value !== null) {
      const avgCoord = candlestickSeries.value.priceToCoordinate(avgPrice.value);
      avgPriceCoordinate.value = isCoordinateVisible(avgCoord, chartHeight) ? avgCoord : null;
    } else {
      avgPriceCoordinate.value = null;
    }

    // 최고가 계산 및 좌표
    const visibleRange = chart.value.timeScale().getVisibleLogicalRange();
    if (visibleRange) {
      const fromIdx = Math.max(0, Math.floor(visibleRange.from));
      const toIdx = Math.min(props.candles.length - 1, Math.ceil(visibleRange.to));

      let maxVal = -Infinity;
      for (let i = fromIdx; i <= toIdx; i++) {
        const c = props.candles[i];
        if (c && c.high > maxVal) {
          maxVal = c.high;
        }
      }

      if (toIdx === props.candles.length - 1 && props.currentPrice !== null && props.currentPrice > maxVal) {
        maxVal = props.currentPrice;
      }

      if (maxVal !== -Infinity) {
        maxPrice.value = maxVal;
        const maxCoord = candlestickSeries.value.priceToCoordinate(maxVal);
        maxPriceCoordinate.value = (maxCoord !== null && maxCoord >= 0) ? maxCoord : null;
      } else {
        maxPrice.value = null;
        maxPriceCoordinate.value = null;
      }
    } else {
      maxPrice.value = null;
      maxPriceCoordinate.value = null;
    }
  } else {
    maxPrice.value = null;
    maxPriceCoordinate.value = null;
    avgPriceCoordinate.value = null;
  }
}

function loadAvgPrice() {
  if (isIndex.value) {
    avgPrice.value = null;
    if (avgPriceLine.value) {
      candlestickSeries.value.removePriceLine(avgPriceLine.value);
      avgPriceLine.value = null;
    }
    return;
  }

  // averagePrice prop(포트폴리오 주입)이 있으면 우선 사용, 없으면 localStorage 폴백
  if (props.averagePrice !== null && props.averagePrice > 0) {
    avgPrice.value = props.averagePrice;
    modalAvgPrice.value = String(props.averagePrice);
  } else {
    const key = `avg_price_${props.ticker}`;
    const saved = localStorage.getItem(key);
    if (saved !== null) {
      avgPrice.value = parseFloat(saved);
      modalAvgPrice.value = saved;
    } else {
      avgPrice.value = null;
      modalAvgPrice.value = '';
    }
  }

  updateAvgPriceLine();
}

function updateAvgPriceLine() {
  if (!candlestickSeries.value) return;

  if (avgPriceLine.value) {
    candlestickSeries.value.removePriceLine(avgPriceLine.value);
    avgPriceLine.value = null;
  }

  if (avgPrice.value !== null) {
    avgPriceLine.value = candlestickSeries.value.createPriceLine({
      price: avgPrice.value,
      color: CHART_IRIS,
      lineWidth: 1.5,
      lineStyle: 0,
      axisLabelVisible: false,
      title: '',
    });
  }

  updateCoordinate();
}

function openModal() {
  modalAvgPrice.value = avgPrice.value !== null ? avgPrice.value.toString() : '';
  showModal.value = true;
}

function closeModal() {
  showModal.value = false;
}

function saveModalFields() {
  const parsedAvg = parseFloat(modalAvgPrice.value);
  if (!isNaN(parsedAvg) && parsedAvg > 0) {
    avgPrice.value = parsedAvg;
    localStorage.setItem(`avg_price_${props.ticker}`, parsedAvg.toString());
  } else {
    avgPrice.value = null;
    localStorage.removeItem(`avg_price_${props.ticker}`);
  }

  updateAvgPriceLine();
  closeModal();
}

function resetModalFields() {
  modalAvgPrice.value = '';
}

function updateLastCandleDirectly(price) {
  if (!hasRenderedData.value || !props.candles || props.candles.length === 0 || !candlestickSeries.value) return;
  const lastCandle = props.candles[props.candles.length - 1];
  if (!lastCandle) return;

  // 차트에 반영할 가격: 인자로 받은 price 가 null/undefined 이면 마지막 봉 close 로 폴백.
  // 이 함수는 항상 유효한 가격으로 호출되어야 하지만, 방어 코드로 처리.
  const effectivePrice = (price !== null && price !== undefined) ? price : lastCandle.close;

  const time = lastCandle.time;
  const open = lastCandle.open;
  let high = lastCandle.high;
  let low = lastCandle.low;
  const close = effectivePrice;

  if (effectivePrice > high) high = effectivePrice;
  if (effectivePrice < low) low = effectivePrice;

  candlestickSeries.value.update({
    time,
    open,
    high,
    low,
    close
  });

  if (volumeSeries.value) {
    volumeSeries.value.update({
      time,
      value: lastCandle.volume || 0,
      color: close >= open ? VOL_UP : VOL_DOWN
    });
  }

  lastTrackedPrice.value = effectivePrice;
}

function formatNumber(value) {
  if (value === null || value === undefined || isNaN(value)) return '0';
  return Math.round(value).toLocaleString();
}

async function loadEarningsDate() {
  if (isIndex.value) {
    earningsDate.value = null;
    return;
  }
  try {
    const host = window.location.hostname || 'localhost';
    const apiBase = `http://${host}:8000`;
    const res = await fetch(`${apiBase}/api/stocks/${props.ticker}/earnings`);
    if (!res.ok) throw new Error();
    const data = await res.json();
    if (data.success && data.earnings_date) {
      earningsDate.value = data.earnings_date;
    } else {
      earningsDate.value = null;
    }
  } catch (e) {
    earningsDate.value = null;
  }
}

// ── watch ──────────────────────────────────────────────────────────────────
watch(
  () => props.timeframe,
  (newVal) => {
    selectedTimeframe.value = newVal;
  },
  { immediate: true }
);

watch(
  () => props.ticker,
  () => {
    shouldFitContent.value = true;
    hasRenderedData.value = false;
    lastCandlesCount.value = 0;
    lastTrackedPrice.value = null; // 종목 전환 시 추적 가격 초기화
    isIndex.value = props.ticker === 'NQ=F' || props.ticker === 'KOSPI_NIGHT' || props.ticker === 'KOSPI200';
    loadAvgPrice();
    loadEarningsDate();
    if (candlestickSeries.value) {
      candlestickSeries.value.applyOptions({
        upColor: CHART_UP,
        downColor: CHART_DOWN,
        wickUpColor: CHART_UP,
        wickDownColor: CHART_DOWN,
        priceFormat: {
          type: 'price',
          precision: isKorean.value ? 0 : 2,
          minMove: isKorean.value ? 1 : 0.01
        }
      });
    }
  }
);

// averagePrice prop이 바뀌면 평단선 즉시 갱신
watch(
  () => props.averagePrice,
  (newVal) => {
    if (!isIndex.value) {
      if (newVal !== null && newVal > 0) {
        avgPrice.value = newVal;
        modalAvgPrice.value = String(newVal);
      } else {
        // prop이 null로 바뀌면 localStorage 폴백
        const key = `avg_price_${props.ticker}`;
        const saved = localStorage.getItem(key);
        avgPrice.value = saved ? parseFloat(saved) : null;
        modalAvgPrice.value = saved || '';
      }
      updateAvgPriceLine();
    }
  }
);

watch(
  () => props.candles,
  (newCandles) => {
    updateChartData(newCandles);

    // ── 동일값 중복 틱 방어 (WS stall 엣지케이스) ───────────────────────
    // Vue watch(currentPrice) 는 newPrice === oldPrice 이면 fire 하지 않는다.
    // WS 케이던스가 불안정해 candles 만 새로 오고 currentPrice 가 이전과 같은 값이면
    // 차트 마지막 봉이 currentPrice 로 보정되지 않아 "멈춘 것처럼" 보이는 증상이 생긴다.
    // candles 갱신 후 추적 중인 가격과 현재 props.currentPrice 가 같으면 여기서 재적용한다.
    // (가짜 데이터 없음 — 이미 알고 있는 실제 수신 가격을 재적용하는 것이므로 안전)
    const price = props.currentPrice;
    if (price !== null && candlestickSeries.value && chart.value && hasRenderedData.value
        && price === lastTrackedPrice.value) {
      // updateChartData 의 chartCandles[lastIdx].close = props.currentPrice 경로가 있지만
      // full setData 가 아닌 증분 경로에서는 currentPrice 가 봉에 반영되지 않을 수 있으므로
      // 명시적으로 재갱신한다.
      updateLastCandleDirectly(price);
    }
  },
  { deep: true }
);

watch(
  () => props.currentPrice,
  (newPrice, oldPrice) => {
    if (newPrice !== null && oldPrice !== null) {
      if (newPrice > oldPrice) {
        priceFlash.value = 'up';
      } else if (newPrice < oldPrice) {
        priceFlash.value = 'down';
      }
      clearTimeout(flashTimeout.value);
      flashTimeout.value = setTimeout(() => {
        priceFlash.value = '';
      }, 260);  // 설계서 §6: 가격 틱 플래시 260ms 통일
    }

    // 실시간으로 수신된 주가 틱을 차트 마지막 캔들에 즉시 강제 갱신 및 동기화 처리.
    // newPrice === oldPrice 이면 Vue watch 가 여기를 호출하지 않으므로,
    // 동일 가격 중복 틱은 위 candles watch 에서 lastTrackedPrice 비교로 보정한다.
    if (newPrice !== null && candlestickSeries.value && chart.value) {
      updateLastCandleDirectly(newPrice);
    }

    nextTick(() => {
      updateCoordinate();
    });
  }
);

// ── lifecycle ──────────────────────────────────────────────────────────────
onMounted(() => {
  initChart();
  loadAvgPrice();
  loadEarningsDate();
});

onBeforeUnmount(() => {
  if (flashTimeout.value) {
    clearTimeout(flashTimeout.value);
  }
  if (resizeObserver.value) {
    resizeObserver.value.disconnect();
  }
  if (chart.value) {
    chart.value.remove();
  }
});
</script>

<style scoped>
/* 카드 루트에 컨테이너 컨텍스트 부여 — 자신의 폭을 기준으로 @container 쿼리를 적용 */
.chart-card-container {
  container-type: inline-size;
  container-name: chart-card;
}

/* 기본(넓은 폭): 헤더 우측 컴팩트 셀렉트 숨김, 타임프레임 row 표시.
   셀렉트 치수/모양 튜닝은 여기(숨김 상태)에 두고, @container 는 display 만 뒤집는다(중복 제거). */
.timeframe-select-compact {
  display: none;
  flex: 0 0 auto;
  max-width: 5rem;
  width: 5rem;
  /* 텍스트 가운데 정렬 */
  text-align: center;
  text-align-last: center;
  /* 네이티브 화살표가 보이도록 appearance 복원 + 좌우 패딩 균형 */
  appearance: auto;
  -webkit-appearance: auto;
  padding-left: 0.375rem;
  padding-right: 1.25rem;
}
.timeframe-row {
  display: flex;
}
/* 컴팩트 셀렉트 행: 와이드에서는 숨김 (타임프레임 버튼 행 사용) */
.timeframe-row4 {
  display: none;
}

/* 카드 폭 400px 미만: 배지 없는 카드(지수 등)도 버튼 7개가 안 들어가므로 컴팩트 셀렉트로 전환 */
@container chart-card (max-width: 399px) {
  .timeframe-row {
    display: none;
  }
  /* 줄4 wrapper: 컴팩트에서는 무조건 flex 표시(실적 배지 유무 무관) */
  .timeframe-row4 {
    display: flex;
  }
  .timeframe-select-compact {
    display: inline-block;
  }
}

/* 배지(MAX·실적) 있는 카드: 배지+버튼탭 7개가 한 줄에 안 들어가 배지가 2줄로 깨지는 폭(≈640px 미만)에서
   미리 컴팩트 셀렉트로 전환한다. 그리드 카드는 min-w-130(520px) 바닥에서도 이 폭에 걸려 헤더가 깨지지 않는다.
   지수 차트(.has-badges 없음)는 배지가 없어 이 규칙에 걸리지 않으므로 좁아도 버튼 탭을 유지한다. */
@container chart-card (max-width: 640px) {
  .has-badges .timeframe-row {
    display: none;
  }
  .has-badges .timeframe-row4 {
    display: flex;
  }
  .has-badges .timeframe-select-compact {
    display: inline-block;
  }
}

.animate-fadeIn {
  animation: fadeIn 0.15s ease-out forwards;
}
@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}
</style>
