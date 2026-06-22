<template>
  <div :class="compact ? 'flex flex-wrap gap-1.5 text-xs' : 'flex flex-col sm:flex-row gap-3 px-4 py-3 bg-base-100/60 backdrop-blur-md border border-base-content/8 rounded-2xl'">

    <!-- 환율 정보 -->
    <div :class="compact ? 'flex items-center gap-1.5 shrink-0' : 'flex items-center gap-2 shrink-0 sm:border-r sm:border-base-content/8 sm:pr-3'">
      <span :class="compact ? 'text-xs font-bold font-mono text-white/80' : 'text-sm font-bold font-mono text-white/80'">
        환율 {{ exchangeRate ? Number(exchangeRate.USD_KRW).toFixed(2) : '—' }}
      </span>
      <span v-if="exchangeRate && !compact" class="text-xs font-mono text-base-content/30">
        {{ fxSourceLabel }} · {{ formatRecordedAt(exchangeRate.recorded_at) }}
      </span>
      <span
        v-if="exchangeRate && !compact"
        class="text-xs text-base-content/30 cursor-help select-none"
        title="증권사(KIS)가 쓰는 KIS 고시환율 기준입니다. 미국 장중에는 증권사 평가손익과 거의 일치하고, 미국 휴장(주말·공휴일) 중에는 증권사가 마지막 마감 시점 환율로 평가를 고정해 보여주므로 기준 시각 차이로 평가손익이 미세하게 다를 수 있습니다."
      >ⓘ</span>
    </div>

    <!-- 보유 없음 -->
    <div
      v-if="!usSummary.hasHoldings && !krSummary.hasHoldings"
      class="flex items-center"
    >
      <span class="text-sm text-base-content/30 font-mono">보유 종목 없음</span>
    </div>

    <!-- 미국주식 손익 -->
    <div
      v-if="usSummary.hasHoldings"
      :class="compact ? 'flex items-center gap-1.5' : 'flex items-center gap-3'"
    >
      <span class="text-xs font-extrabold text-emerald-400/70 tracking-widest uppercase shrink-0">미국</span>
      <span :class="compact ? 'text-xs font-black font-mono text-white/80' : 'text-sm font-black font-mono text-white/80'">
        {{ usSummary.marketValueUSD.toFixed(2) }}$
      </span>
      <span
        :class="[compact ? 'text-xs font-black font-mono' : 'text-sm font-black font-mono', profitColorClass(usSummary.profitUSD, 'us')]"
      >{{ formatProfitUSD(usSummary.profitUSD) }}</span>
      <span
        class="text-xs font-extrabold font-mono px-2 py-0.5 rounded-lg"
        :class="profitBadgeClass(usSummary.profitUSD, 'us')"
      >{{ formatProfitRate(usSummary.profitRate) }}</span>
    </div>

    <!-- 구분선 (둘 다 있을 때, compact 시 숨김) -->
    <div
      v-if="usSummary.hasHoldings && krSummary.hasHoldings && !compact"
      class="hidden sm:block w-px bg-base-content/8 self-stretch shrink-0"
    ></div>

    <!-- 국내주식 손익 -->
    <div
      v-if="krSummary.hasHoldings"
      :class="compact ? 'flex items-center gap-1.5' : 'flex items-center gap-3'"
    >
      <span class="text-xs font-extrabold text-rose-400/70 tracking-widest uppercase shrink-0">국내</span>
      <span :class="compact ? 'text-xs font-black font-mono text-white/80' : 'text-sm font-black font-mono text-white/80'">
        {{ formatWon(krSummary.marketValueKRW) }}
      </span>
      <span
        :class="[compact ? 'text-xs font-black font-mono' : 'text-sm font-black font-mono', profitColorClass(krSummary.profitKRW, 'kr')]"
      >{{ formatProfitWon(krSummary.profitKRW) }}</span>
      <span
        class="text-xs font-extrabold font-mono px-2 py-0.5 rounded-lg"
        :class="profitBadgeClass(krSummary.profitKRW, 'kr')"
      >{{ formatProfitRate(krSummary.profitRate) }}</span>
    </div>

  </div>
</template>

<script setup>
import { computed } from 'vue';
import {
  formatWon,
  formatProfitWon,
  formatProfitUSD,
  formatProfitRate,
  formatRecordedAt,
  profitColorClass,
  profitBadgeClass,
} from '../utils/format.js';

const props = defineProps({
  holdings: {
    type: Array,
    default: () => [],
  },
  exchangeRate: {
    // { USD_KRW: number, recorded_at: string, source: string } | null
    type: Object,
    default: null,
  },
  compact: {
    type: Boolean,
    default: false,
  },
});

// ── computed ──────────────────────────────────────────────────

const fxSourceLabel = computed(() => {
  const src = props.exchangeRate?.source || '';
  if (src.includes('KIS')) return 'KIS 고시환율';
  if (src.toLowerCase().includes('yahoo')) return 'Yahoo 환율';
  if (src.includes('db_fallback')) return '최근 환율';
  return '환율';
});

// 미국주식 합계 (달러 기준) — PortfolioDashboard.computed.usSummary 와 동일 로직
const usSummary = computed(() => {
  const usHoldings = props.holdings.filter(h => h.market === 'US');
  if (usHoldings.length === 0) {
    return { hasHoldings: false, profitUSD: 0, marketValueUSD: 0, costUSD: 0, profitRate: 0 };
  }
  let profitUSD = 0;
  let marketValueUSD = 0;
  let costUSD = 0;
  let hasPrice = false;
  usHoldings.forEach(h => {
    if (h.price_available && h.current_price !== null && h.average_price !== null) {
      const qty = Number(h.quantity);
      const cur = Number(h.current_price);
      const avg = Number(h.average_price);
      const mv = cur * qty;
      const cv = avg * qty;
      marketValueUSD += mv;
      costUSD += cv;
      profitUSD += mv - cv;
      hasPrice = true;
    } else {
      const qty = Number(h.quantity);
      const avg = Number(h.average_price);
      costUSD += avg * qty;
    }
  });
  const profitRate = costUSD > 0 ? (profitUSD / costUSD) : 0;
  return {
    hasHoldings: true,
    profitUSD: hasPrice ? profitUSD : null,
    marketValueUSD,
    costUSD,
    profitRate: hasPrice ? profitRate : null,
  };
});

// 국내주식 합계 (원화 기준) — PortfolioDashboard.computed.krSummary 와 동일 로직
const krSummary = computed(() => {
  const krHoldings = props.holdings.filter(h => h.market === 'KR');
  if (krHoldings.length === 0) {
    return { hasHoldings: false, profitKRW: 0, marketValueKRW: 0, costKRW: 0, profitRate: 0 };
  }
  let profitKRW = 0;
  let marketValueKRW = 0;
  let costKRW = 0;
  let hasPrice = false;
  krHoldings.forEach(h => {
    if (h.price_available && h.profitKRW !== null) {
      profitKRW += Number(h.profitKRW);
      marketValueKRW += Number(h.marketValueKRW || 0);
      costKRW += Number(h.costKRW || 0);
      hasPrice = true;
    } else {
      costKRW += Number(h.costKRW || 0);
    }
  });
  const profitRate = costKRW > 0 ? (profitKRW / costKRW) : 0;
  return {
    hasHoldings: true,
    profitKRW: hasPrice ? profitKRW : null,
    marketValueKRW,
    costKRW,
    profitRate: hasPrice ? profitRate : null,
  };
});

</script>
