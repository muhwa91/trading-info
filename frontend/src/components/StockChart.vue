<template>
  <div class="relative bg-base-100/45 backdrop-blur-md border border-base-content/8 rounded-2xl p-3.5 h-full flex flex-col justify-between overflow-hidden">

    <!-- 차트 헤더 -->
    <div class="flex flex-col gap-2 mb-2.5 select-none">
      <!-- 1행: 티커·종목명·세션·보조 배지 / 우측 현재가 -->
      <div class="flex items-start justify-between gap-2">
        <!-- 좌: 종목 정보 -->
        <div class="flex items-center gap-1.5 flex-1 min-w-0 flex-wrap">
          <!-- 티커 배지 -->
          <span class="px-2 py-0.5 rounded-md text-[12px] font-extrabold font-mono text-indigo-300 bg-indigo-500/12 border border-indigo-500/20 tracking-wider leading-tight shrink-0">
            {{ ticker }}
          </span>
          <!-- 종목명 -->
          <span class="text-sm font-black text-white/90 leading-tight break-all" :title="name">{{ name }}</span>

          <!-- 최고가 배지 -->
          <span
            v-if="!isIndex && maxPrice !== null"
            class="px-1.5 py-0.5 rounded text-[11px] font-extrabold font-mono text-amber-400 bg-amber-500/8 border border-amber-500/20 shrink-0 leading-tight"
          >MAX {{ formattedMaxPrice }}</span>

          <!-- 실적 발표일 배지 -->
          <span
            v-if="earningsDate"
            class="px-1.5 py-0.5 rounded text-[11px] font-extrabold font-mono text-indigo-400 bg-indigo-500/8 border border-indigo-500/20 shrink-0 leading-tight"
          >실적 {{ earningsDate }}</span>

          <div class="flex-1"></div>
        </div>

        <!-- 우: 현재가 + 등락률 -->
        <div class="flex flex-row items-center shrink-0 gap-2">
          <span
            :class="[
              'text-sm font-black font-mono transition-all duration-250 rounded px-1.5 py-0.5 leading-tight',
              priceFlash === 'up'
                ? 'bg-rose-500/18 text-rose-400 scale-105'
                : '',
              priceFlash === 'down'
                ? 'bg-sky-500/18 text-sky-400 scale-105 glow-active'
                : '',
              !priceFlash
                ? (changePercent >= 0 ? 'text-rose-400' : 'text-sky-400')
                : ''
            ]"
          >{{ formattedHeaderPrice }}</span>
          <!-- 등락액(주가 변동) — 주가 우측 표기 -->
          <span
            v-if="changeAmount !== null"
            :class="[
              'text-xs font-bold font-mono leading-tight shrink-0',
              changeAmount >= 0 ? 'text-rose-400' : 'text-sky-400'
            ]"
          >{{ formattedChangeAmount }}</span>
        </div>
      </div>

      <!-- 2행: 타임프레임 선택 -->
      <div class="flex items-center justify-between border-t border-base-content/6 pt-2">
        <span class="text-[9px] text-base-content/35 font-bold uppercase tracking-widest font-mono">Timeframe</span>
        <div class="tabs tabs-boxed bg-base-200/70 p-0.5 rounded-lg border border-base-content/6">
          <button
            v-for="tf in timeframes"
            :key="tf.value"
            @click.stop="changeTimeframe(tf.value)"
            :class="[
              'tab tab-xs rounded-md font-bold transition-all duration-200 cursor-pointer text-[10px]',
              selectedTimeframe === tf.value
                ? 'tab-active bg-indigo-600/12 border border-indigo-500/20 text-indigo-400 shadow-sm'
                : 'text-base-content/35 hover:text-base-content/70 border border-transparent'
            ]"
          >{{ tf.label }}</button>
        </div>
      </div>
    </div>

    <!-- Chart Canvas Wrapper -->
    <div class="flex-1 w-full relative min-h-[170px] flex" ref="chartWrapper">
      <!-- Lightweight Chart container (가격축 포함 전체 폭 사용 — 우측 빈 공간 제거) -->
      <div class="h-full w-full" ref="chartContainer"></div>

      <!-- HTS Style Price Axis Label Overlay (Right 80px) -->
      <!-- 현재가 오버레이 -->
      <div
        v-if="currentPrice !== null && priceCoordinate !== null"
        :class="[
          'absolute right-0 z-30 flex flex-col items-center justify-center font-black pl-2 pr-1 py-1 pointer-events-none select-none text-white font-mono leading-none shadow-xl border-y border-l rounded-l-md',
          changePercent >= 0
            ? 'bg-rose-600 border-rose-500 shadow-rose-900/35'
            : 'bg-sky-600 border-sky-500 shadow-sky-900/35'
        ]"
        :style="{
          top: priceCoordinate + 'px',
          transform: 'translateY(-50%)',
          width: '60px',
          clipPath: 'polygon(7px 0%, 100% 0%, 100% 100%, 7px 100%, 0% 50%)'
        }"
      >
        <div class="text-[12px] font-black mb-1 tracking-tight">{{ formattedPrice }}</div>
        <div class="text-[11px] opacity-95">{{ formattedChangePercent }}</div>
      </div>

      <!-- 평단가 오버레이 -->
      <div
        v-if="!isIndex && avgPrice !== null && avgPriceCoordinate !== null"
        class="absolute right-0 z-20 flex flex-col items-center justify-center font-black pl-2 pr-1 py-1 pointer-events-none select-none text-white font-mono leading-none shadow-xl border-y border-l bg-warning border-warning/60 shadow-warning/20 rounded-l-md"
        :style="{
          top: avgPriceCoordinate + 'px',
          transform: 'translateY(-50%)',
          width: '60px',
          clipPath: 'polygon(7px 0%, 100% 0%, 100% 100%, 7px 100%, 0% 50%)'
        }"
      >
        <div class="text-[11px] font-black mb-1 tracking-tight opacity-90">평단</div>
        <div class="text-[12px] font-black">{{ isKorean ? Math.round(avgPrice).toLocaleString() : avgPrice.toFixed(2) }}</div>
      </div>
    </div>

    <!-- 평단가 설정 모달 -->
    <Transition name="fade">
      <div
        v-if="showModal"
        class="fixed inset-0 z-9999 flex items-center justify-center bg-black/55 backdrop-blur-sm p-4 animate-fade-in"
        @click.self="closeModal"
        role="dialog"
        aria-modal="true"
        :aria-label="`${ticker} 평단가 설정`"
      >
        <div class="bg-base-200 border border-base-content/12 rounded-2xl p-5 w-full max-w-sm shadow-2xl flex flex-col gap-4 font-sans relative">
          <!-- 모달 헤더 -->
          <div class="flex items-center justify-between border-b border-base-content/8 pb-3">
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded-md text-[11px] font-extrabold font-mono text-indigo-300 bg-indigo-500/12 border border-indigo-500/20 tracking-wider">
                {{ ticker }}
              </span>
              <h3 class="text-sm font-black text-white">평단가 설정</h3>
            </div>
            <button
              @click="closeModal"
              class="w-7 h-7 flex items-center justify-center rounded-lg text-base-content/40 hover:text-white hover:bg-base-300/60 transition-all duration-150 cursor-pointer"
              aria-label="모달 닫기"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- 종목명 -->
          <div class="text-xs text-base-content/50 font-semibold">
            종목명: <span class="text-white/90 font-bold">{{ name }}</span>
          </div>

          <!-- 평단가 입력 -->
          <div class="form-control gap-1.5">
            <label class="label py-0 select-none" for="avg-price-input">
              <span class="label-text text-[11px] font-extrabold text-base-content/50 uppercase tracking-widest">평균단가</span>
            </label>
            <div class="join w-full">
              <input
                id="avg-price-input"
                v-model="modalAvgPrice"
                type="number"
                step="0.01"
                :placeholder="isKorean ? '예: 25500' : '예: 180.00'"
                class="input input-sm input-bordered join-item flex-1 font-bold font-mono focus:outline-none focus:border-indigo-500/60 bg-base-300/60 text-sm"
              />
              <span class="join-item flex items-center px-3 bg-base-300/40 border border-base-content/10 border-l-0 text-xs font-bold text-base-content/50 rounded-r-lg">
                {{ isKorean ? '원' : 'USD' }}
              </span>
            </div>
          </div>

          <!-- 액션 버튼 -->
          <div class="flex items-center justify-between border-t border-base-content/8 pt-3 mt-1">
            <button @click="resetModalFields" class="btn btn-xs btn-ghost text-error/70 hover:text-error hover:bg-error/8 cursor-pointer font-bold">
              초기화
            </button>
            <div class="flex gap-2">
              <button @click="closeModal" class="btn btn-xs btn-ghost text-base-content/50 cursor-pointer font-bold">취소</button>
              <button @click="saveModalFields" class="btn btn-xs btn-primary cursor-pointer px-4 font-bold">저장</button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { createChart, CandlestickSeries, HistogramSeries } from 'lightweight-charts';

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
const emit = defineEmits(['timeframe-change']);

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
const hasRenderedData = ref(false);
const lastCandlesCount = ref(0);
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
const isKorean = computed(() => {
  // KRX 코드: .KS/.KQ 접미사, 6자리 숫자, 또는 신형 영숫자 코드(예: 0167A0)
  const t = props.ticker;
  return /(\.KS|\.KQ)$/i.test(t) || /^\d{4}[0-9A-Za-z]{2}$/.test(t) || /^\d+$/.test(t);
});

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

const formattedHeaderPrice = computed(() => {
  if (props.currentPrice === null) return '---';
  // 지수(나스닥100 선물·코스피 등)는 통화가 아니므로 기호 없이 숫자만 표시
  if (isIndex.value) {
    return props.currentPrice.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  if (isKorean.value) {
    return `${Math.round(props.currentPrice).toLocaleString()}원`;
  }
  return `${props.currentPrice.toFixed(2)}$`;
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
  const sign = props.changePercent >= 0 ? '+ ' : '';
  return `${sign}${props.changePercent.toFixed(2)}%`;
});

const formattedChangeAmount = computed(() => {
  if (props.changeAmount === null) return isKorean.value ? '▲0' : '▲0.0000';
  const arrow = props.changeAmount >= 0 ? '▲' : '▼';
  const absAmount = Math.abs(props.changeAmount);
  if (isKorean.value) {
    return `${arrow}${Math.round(absAmount).toLocaleString()}`;
  }
  return `${arrow}${absAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
});

// ── methods ────────────────────────────────────────────────────────────────
function initChart() {
  const container = chartContainer.value;
  if (!container) return;

  chart.value = createChart(container, {
    layout: {
      background: { type: 'solid', color: 'transparent' },
      textColor: '#94a3b8',
      fontSize: 10,
      fontFamily: 'system-ui, -apple-system, sans-serif'
    },
    grid: {
      vertLines: { color: 'rgba(51, 65, 85, 0.15)' },
      horzLines: { color: 'rgba(51, 65, 85, 0.15)' }
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
        color: '#6366f1',
        width: 1,
        style: 3, // dashed
        labelBackgroundColor: '#4f46e5',
      },
      horzLine: {
        color: '#6366f1',
        width: 1,
        style: 3,
        labelBackgroundColor: '#4f46e5',
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
      // 현재가/평단가 오버레이(62px)가 캔들을 가리지 않고 y축 금액 영역 안에 들어가도록 폭을 맞춤
      width: 66,
      scaleMargins: {
        top: 0.1,
        bottom: 0.25
      }
    }
  });

  // 국내·미국 구분 없이 상승=빨강(#f43f5e), 하락=파랑(#38bdf8)으로 통일
  const upColor = '#f43f5e';
  const downColor = '#38bdf8';

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
    const chartWidth = Math.max(0, width); // 래퍼 전체 폭 사용(가격축 포함) — 우측 빈 공간 제거
    requestAnimationFrame(() => {
      if (chart.value) {
        chart.value.resize(chartWidth, height);
        if (chartWidth > 0 && shouldFitContent.value) {
          chart.value.timeScale().fitContent();
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

  const upVolColor = isKorean.value ? 'rgba(244, 63, 94, 0.2)' : 'rgba(16, 185, 129, 0.2)';
  const downVolColor = isKorean.value ? 'rgba(16, 185, 129, 0.2)' : 'rgba(244, 63, 94, 0.2)';

  const chartVolumes = candles.map((c, idx) => ({
    time: c.time,
    value: c.volume,
    color: chartCandles[idx].close >= chartCandles[idx].open ? upVolColor : downVolColor
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
    const len = chartCandles.length;
    // 개별 종목은 60봉(3분봉 기준 3시간), 지수는 100봉 노출
    const showCount = (props.ticker === 'NQ=F' || props.ticker === 'KOSPI_NIGHT' || props.ticker === 'KOSPI200') ? 100 : 60;
    if (len > 0) {
      // from 을 0 미만으로 내려가지 않게 클램프 → 첫 봉 왼쪽 빈 공간 제거.
      // to 는 마지막 봉 바로 뒤(0.5칸)까지만 → 우측 빈 공간 최소화하여 캔들이 꽉 차게.
      chart.value.timeScale().setVisibleLogicalRange({
        from: Math.max(0, len - showCount),
        to: len - 0.5,
      });
    }
    shouldFitContent.value = false;
  }

  nextTick(() => {
    updateCoordinate();
  });
}

function changeTimeframe(value) {
  if (selectedTimeframe.value === value) return;
  selectedTimeframe.value = value;
  shouldFitContent.value = true;
  emit('timeframe-change', value);
}

function updateCoordinate() {
  if (!candlestickSeries.value) {
    priceCoordinate.value = null;
    maxPriceCoordinate.value = null;
    avgPriceCoordinate.value = null;
    return;
  }

  // 현재가 좌표
  if (props.currentPrice !== null) {
    const coordinate = candlestickSeries.value.priceToCoordinate(props.currentPrice);
    if (coordinate === null || coordinate < 0) {
      priceCoordinate.value = null;
    } else {
      priceCoordinate.value = coordinate;
    }
  } else {
    priceCoordinate.value = null;
  }

  // 최고가 및 평단가 좌표 계산
  if (!isIndex.value && chart.value && props.candles && props.candles.length > 0) {
    // 평단가 좌표 계산
    if (avgPrice.value !== null) {
      const avgCoord = candlestickSeries.value.priceToCoordinate(avgPrice.value);
      avgPriceCoordinate.value = (avgCoord !== null && avgCoord >= 0) ? avgCoord : null;
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
      color: '#f97316',
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

  const time = lastCandle.time;
  const open = lastCandle.open;
  let high = lastCandle.high;
  let low = lastCandle.low;
  const close = price;

  if (price > high) high = price;
  if (price < low) low = price;

  candlestickSeries.value.update({
    time,
    open,
    high,
    low,
    close
  });

  if (volumeSeries.value) {
    const upVolColor = isKorean.value ? 'rgba(244, 63, 94, 0.2)' : 'rgba(16, 185, 129, 0.2)';
    const downVolColor = isKorean.value ? 'rgba(16, 185, 129, 0.2)' : 'rgba(244, 63, 94, 0.2)';
    volumeSeries.value.update({
      time,
      value: lastCandle.volume || 0,
      color: close >= open ? upVolColor : downVolColor
    });
  }
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
    isIndex.value = props.ticker === 'NQ=F' || props.ticker === 'KOSPI_NIGHT' || props.ticker === 'KOSPI200';
    loadAvgPrice();
    loadEarningsDate();
    if (candlestickSeries.value) {
      // 국내·미국 구분 없이 상승=빨강(#f43f5e), 하락=파랑(#38bdf8)으로 통일
      const upColor = '#f43f5e';
      const downColor = '#38bdf8';
      candlestickSeries.value.applyOptions({
        upColor: upColor,
        downColor: downColor,
        wickUpColor: upColor,
        wickDownColor: downColor,
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
      }, 800);
    }

    // 실시간으로 수신된 주가 틱을 차트 마지막 캔들에 즉시 강제 갱신 및 동기화 처리
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
.glow-active {
  box-shadow: 0 0 8px rgba(16, 185, 129, 0.2);
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
