<template>
  <div class="bg-base-100 text-base-content flex flex-col h-full w-full">

    <!-- 토스트 알림 -->
    <Transition name="toast-slide">
      <div
        v-if="toast.show"
        class="fixed top-4 right-4 z-[200] flex items-center gap-2.5 px-4 py-3 rounded-xl border shadow-lg text-xs font-bold font-mono transition-all duration-300"
        :class="toast.type === 'success'
          ? 'bg-emerald-900/90 border-emerald-500/30 text-emerald-300'
          : toast.type === 'warn'
            ? 'bg-amber-900/90 border-amber-500/30 text-amber-300'
            : 'bg-error/15 border-error/30 text-error'"
        role="alert"
        aria-live="polite"
      >
        <span v-if="toast.type === 'success'">✓</span>
        <span v-else-if="toast.type === 'warn'">!</span>
        <span v-else>✕</span>
        {{ toast.message }}
      </div>
    </Transition>

    <!-- 사이드바 헤더 -->
    <div class="px-4 pt-4 pb-3 border-b border-base-content/8 select-none relative z-30 bg-base-100/60 backdrop-blur-sm shrink-0">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-xs font-extrabold tracking-widest text-base-content/60 uppercase flex items-center gap-2">
          <!-- 연결 상태 점 -->
          <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse shadow-sm shadow-emerald-500/60 shrink-0"></span>
          관심 종목
        </h2>
        <div class="flex items-center gap-1.5">
          <!-- localStorage 이관 버튼 -->
          <button
            @click="migrateLocalStorage"
            :disabled="migrating || actionLoading"
            class="flex items-center gap-1 px-2 py-1 rounded-lg text-amber-400/60 hover:text-amber-300 hover:bg-amber-500/8 border border-amber-500/20 hover:border-amber-500/40 text-xs font-bold transition-all duration-200 cursor-pointer disabled:opacity-40"
            title="기존 모니터링 관심종목(브라우저 저장)을 DB로 가져오기"
            aria-label="기존 관심종목 가져오기"
          >
            <!-- lucide: Upload -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            <span class="hidden sm:inline">{{ migrating ? '이관 중...' : '기존 가져오기' }}</span>
          </button>
          <!-- 종목 카운트 배지 (현재 탭 필터 기준) -->
          <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold font-mono text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 select-none">
            {{ filteredItems.length }}
          </span>
        </div>
      </div>

      <!-- KR / US / 전체 탭 -->
      <div class="tabs tabs-boxed bg-base-200/70 p-0.5 rounded-lg border border-base-content/6 select-none w-full mb-2.5">
        <button
          v-for="m in SEARCH_MODE_OPTIONS"
          :key="m.value"
          type="button"
          @click="searchMode = m.value; apiSearchResults = []; emit('market-change', m.value)"
          :class="[
            'tab flex-1 rounded-md text-xs font-extrabold transition-all duration-200 cursor-pointer',
            searchMode === m.value
              ? 'tab-active bg-indigo-600/12 border border-indigo-500/20 text-indigo-400 shadow-sm'
              : 'text-base-content/40 hover:text-base-content/70 border border-transparent'
          ]"
        >{{ m.label }}</button>
      </div>

      <!-- 검색 폼 -->
      <div class="relative" ref="searchContainer">
        <div class="join w-full">
          <input
            v-model="searchQuery"
            @input="onSearchInput"
            @focus="showDropdown = true"
            @keyup.enter="addBestMatch"
            type="text"
            :placeholder="searchMode === 'kr' ? '종목명 / 초성 / 티커' : searchMode === 'us' ? '티커 / 종목명' : '종목명 / 티커 검색'"
            class="input input-sm input-bordered join-item flex-1 font-semibold text-sm focus:outline-none focus:border-indigo-500/60 placeholder:text-base-content/25 uppercase bg-base-200/50"
            aria-label="종목 검색"
            autocomplete="off"
          />
          <button
            v-if="searchQuery"
            @click="clearSearch"
            type="button"
            class="btn btn-sm btn-ghost join-item border border-base-content/15 text-base-content/30 hover:text-base-content/60 px-2"
            aria-label="검색어 지우기"
          >
            <!-- lucide: X -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- 자동완성 드롭다운 -->
        <Transition name="fade-slide">
          <div
            v-if="showDropdown && mergedSearchResults.length > 0"
            class="absolute left-0 right-0 top-full mt-1.5 border border-base-content/10 rounded-xl shadow-2xl z-[100] max-h-52 sm:max-h-72 overflow-y-auto backdrop-blur-xl bg-base-100/97 custom-scrollbar"
          >
            <!-- 드롭다운 헤더 -->
            <div
              class="sticky top-0 px-3 py-1.5 text-[10px] font-extrabold bg-base-200/80 border-b border-base-content/6 tracking-widest select-none uppercase backdrop-blur-sm"
              :class="searchMode === 'kr'
                ? 'text-rose-400/80'
                : searchMode === 'us'
                  ? 'text-emerald-400/80'
                  : 'text-indigo-400/80'"
            >
              검색 결과 ({{ mergedSearchResults.length }})
            </div>
            <div
              v-for="stock in mergedSearchResults"
              :key="stock.ticker"
              class="flex items-center justify-between px-3 py-2 cursor-pointer hover:bg-indigo-500/6 transition-colors border-b border-base-content/4 last:border-b-0 group"
            >
              <div class="flex flex-col min-w-0 flex-1 mr-2">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="text-white font-bold text-xs group-hover:text-indigo-300 transition-colors" :title="stock.name">{{ stock.name }}</span>
                  <span class="px-1 py-0.5 rounded text-[10px] font-bold font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/15">{{ stock.ticker }}</span>
                  <span
                    class="text-[10px] font-bold font-mono px-1 py-0.5 rounded"
                    :class="stock.isKorean ? 'text-rose-400/70 bg-rose-500/6' : 'text-emerald-400/70 bg-emerald-500/6'"
                  >{{ stock.isKorean ? 'KR' : 'US' }}</span>
                </div>
                <span v-if="stock.subName" class="text-[11px] text-base-content/35 mt-0.5 truncate" :title="stock.subName">{{ stock.subName }}</span>
              </div>
              <button
                @click.stop="addItem(stock)"
                :disabled="actionLoading"
                class="shrink-0 btn btn-xs bg-indigo-600/70 hover:bg-indigo-500 text-white border-0 font-bold gap-1 rounded-lg cursor-pointer transition-all duration-150 disabled:opacity-40"
                :aria-label="`${stock.name} 관심 추가`"
              >
                <!-- lucide: Plus -->
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                추가
              </button>
            </div>
          </div>
        </Transition>
      </div>

      <!-- 액션 에러 메시지 -->
      <p v-if="errorMsg" class="flex items-center gap-1 text-[10px] text-error mt-1.5 font-bold font-mono" role="alert">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        {{ errorMsg }}
      </p>
    </div>

    <!-- 종목 목록 -->
    <div class="flex-1 overflow-y-auto p-2 space-y-1.5 custom-scrollbar" role="list" aria-label="통합 관심 종목 목록">

      <!-- 초기 로딩 스켈레톤 (items 아직 없을 때) -->
      <div v-if="actionLoading && items.length === 0" class="space-y-1.5 animate-pulse p-1">
        <div v-for="n in 6" :key="n" class="skeleton h-14 rounded-xl"></div>
      </div>

      <!-- 빈 상태 -->
      <div v-else-if="items.length === 0" class="flex flex-col items-center justify-center py-12 gap-3 select-none">
        <div class="w-10 h-10 rounded-xl border-2 border-dashed border-base-content/15 flex items-center justify-center">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-base-content/25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
          </svg>
        </div>
        <div class="text-center">
          <p class="text-[11px] font-bold text-base-content/35">관심 종목 없음</p>
          <p class="text-[11px] text-base-content/22 mt-0.5">위 검색창에서 종목을 추가하세요</p>
        </div>
      </div>

      <!-- 시장 필터 결과 없음 (전체엔 있으나 선택 시장엔 없음) -->
      <div v-else-if="filteredItems.length === 0" class="flex flex-col items-center justify-center py-12 gap-2 select-none">
        <p class="text-[11px] font-bold text-base-content/35">{{ searchMode === 'kr' ? '국내' : '미국' }} 관심 종목이 없습니다</p>
        <p class="text-[11px] text-base-content/22">'전체'에서 모두 보거나 종목을 추가하세요</p>
      </div>

      <!-- 종목 행 목록 -->
      <template v-else>
        <div
          v-for="item in filteredItems"
          :key="item.watchlist_id"
          @click="emit('select', item.displayTicker)"
          role="listitem"
          :aria-label="`${item.displayName} 선택`"
          :class="[
            'relative flex items-center justify-between px-3 py-2.5 pr-8 rounded-xl cursor-pointer border transition-all duration-200 select-none',
            selectedTicker === item.displayTicker
              ? 'bg-indigo-600/8 border-indigo-500/35 text-white shadow-md shadow-indigo-600/5'
              : 'bg-base-200/25 hover:bg-base-200/60 border-transparent hover:border-base-content/10 text-base-content/85'
          ]"
        >
          <!-- 활성 선택 좌측 바 -->
          <div
            v-if="selectedTicker === item.displayTicker"
            class="absolute left-0 top-2 bottom-2 w-0.5 rounded-r-full bg-indigo-500/70"
          ></div>

          <div class="flex flex-col flex-1 min-w-0 mr-2">
            <!-- 한글명 우선 표시 -->
            <div
              class="font-black text-sm text-white tracking-tight leading-tight truncate"
              :title="item.displayName"
            >{{ item.displayName }}</div>
            <!-- 티커 배지 + 시장 구분 -->
            <div class="flex items-center gap-1 mt-0.5">
              <span class="text-[11px] text-base-content/45 font-mono font-semibold tracking-wider leading-tight">
                {{ item.hasPrice ? item.displayTicker : '시세 대기 중' }}
              </span>
              <span
                class="text-[10px] font-bold font-mono px-1 py-0 rounded leading-tight"
                :class="item.market === 'KR'
                  ? 'text-rose-400/70 bg-rose-500/6'
                  : 'text-emerald-400/70 bg-emerald-500/6'"
              >{{ item.market }}</span>
            </div>
          </div>

          <div class="flex flex-col items-end shrink-0 gap-0.5">
            <!-- 현재가 (flash 애니메이션) -->
            <span
              v-if="item.hasPrice"
              :class="[
                'font-extrabold text-sm font-mono transition-all duration-250 rounded px-1 py-0.5 leading-tight',
                item.flash === 'up'
                  ? 'bg-rose-500/15 text-rose-400 scale-105'
                  : '',
                item.flash === 'down'
                  ? 'bg-sky-500/15 text-sky-400 scale-105'
                  : '',
                !item.flash ? 'text-white/90' : ''
              ]"
            >{{ formatPrice(item.market, item.price) }}</span>
            <span v-else class="text-xs font-mono text-base-content/25">시세 대기 중</span>

            <!-- 등락률 -->
            <span
              v-if="item.hasPrice && item.changePercent !== null"
              :class="[
                'text-[10px] font-extrabold font-mono px-1 py-0.5 rounded leading-tight',
                item.changePercent >= 0
                  ? 'text-rose-400 bg-rose-500/8'
                  : 'text-sky-400 bg-sky-500/8'
              ]"
            >{{ item.changePercent >= 0 ? '+' : '' }}{{ item.changePercent.toFixed(2) }}%</span>
            <span v-else-if="item.hasPrice" class="text-[10px] text-base-content/25 font-mono">---</span>
          </div>

          <!-- 삭제 버튼 -->
          <button
            @click.stop="removeItem(item)"
            :disabled="actionLoading"
            class="absolute top-1.5 right-1.5 w-5 h-5 flex items-center justify-center text-base-content/30 hover:text-error hover:bg-error/10 rounded-md transition-all duration-150 cursor-pointer disabled:opacity-40"
            :title="`${item.displayName} 삭제`"
            :aria-label="`${item.displayName} 삭제`"
          >
            <!-- lucide: X -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { localSearch, normalizeKrTicker, SEARCHABLE_STOCKS } from '../stocksKnown.js';

// ── 모듈 스코프 헬퍼 / 상수 ─────────────────────────────────────

/**
 * stocksKnown 에서 ticker 로 한글명을 역조회.
 * KR 종목은 접미사(.KS/.KQ) 포함/미포함 모두 매칭.
 * @param {string} ticker  — DB symbol (KR은 접미사 없음, 예: '005930') 또는 US (예: 'TSLA')
 * @param {string} market  — 'KR' | 'US'
 * @returns {string|null}
 */
function lookupKoName(ticker, market) {
  if (!ticker) return null;
  const upper = String(ticker).toUpperCase();

  const found = SEARCHABLE_STOCKS.find(s => {
    if (market === 'KR') {
      // DB symbol(접미사 없음) ↔ stocksKnown ticker(.KS/.KQ) 매칭
      const stripped = s.ticker.replace(/(\.KS|\.KQ)$/i, '').toUpperCase();
      return stripped === upper;
    }
    return s.ticker.toUpperCase() === upper;
  });

  return found ? found.koName : null;
}

/**
 * localStorage 티커 문자열 → { symbol, market } 변환.
 * @param {string} raw
 * @returns {{ symbol: string, market: string }|null}
 */
function parseLocalStorageTicker(raw) {
  const t = String(raw || '').trim();
  if (!t) return null;
  if (/(\.KS|\.KQ)$/i.test(t)) {
    return { symbol: t.replace(/(\.KS|\.KQ)$/i, ''), market: 'KR' };
  }
  if (/^\d+$/.test(t)) {
    return { symbol: t, market: 'KR' };
  }
  return { symbol: t.toUpperCase(), market: 'US' };
}

const SEARCH_MODE_OPTIONS = [
  { value: 'kr',  label: '국내' },
  { value: 'us',  label: '미국' },
  { value: 'all', label: '전체' },
];

// ── Props / Emits ────────────────────────────────────────────────

const props = defineProps({
  /**
   * GET /api/portfolio/dashboard 의 watchlist 배열.
   * 각 요소: { watchlist_id, stock_id, symbol, name, market, ... }
   * DB가 진실 원천 — 상위(App.vue)가 내려준다.
   */
  items: {
    type: Array,
    default: () => [],
  },

  /**
   * WebSocket으로 받은 실시간 시세 맵.
   * { [ticker]: { price, changePercent, name, flash } }
   * ticker 키는 US는 'TSLA' 형태, KR은 '005930' 또는 '005930.KS' 형태로
   * 상위에서 오는 값 그대로 사용 (isKoreanTicker 로 판별 후 매칭).
   */
  pricesMap: {
    type: Object,
    default: () => ({}),
  },

  /**
   * 현재 선택된 종목 티커 (2×2 그리드 활성 종목).
   * 행 강조용.
   */
  selectedTicker: {
    type: String,
    default: '',
  },

  /**
   * 차트 영역의 국내/미국 토글 값('KR' | 'US').
   * 이 값이 바뀌면 사이드바 탭(한국/미국)도 연동해 따라간다.
   */
  marketSync: {
    type: String,
    default: '',
  },

  /**
   * 하단 차트 그리드의 종목 순서(심볼 배열).
   * 차트를 드래그로 재배치하면 사이드바 목록도 이 순서를 따라 정렬된다.
   */
  gridOrder: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits([
  /**
   * 종목 행 클릭 시. payload: ticker 문자열.
   * KR은 'symbol' (접미사 없음, 예: '005930'),
   * US는 'symbol' (예: 'TSLA').
   */
  'select',

  /**
   * 추가/삭제/이관 성공 후 발행.
   * 상위(App.vue)가 GET /api/portfolio/dashboard 를 재조회해 items 를 갱신한다.
   */
  'changed',

  /**
   * 탭(국내/미국/전체) 클릭 시. payload: 'kr' | 'us' | 'all'.
   * 상위(App.vue)가 하단 차트 그리드 시장을 연동 전환한다('all'은 무시).
   */
  'market-change',
]);

// ── 템플릿 ref ───────────────────────────────────────────────────

const searchContainer = ref(null);

// ── 반응형 상태 (data) ───────────────────────────────────────────

// 검색
const searchQuery = ref('');
const searchMode = ref('all');

// 차트 영역의 국내/미국 토글과 사이드바 탭 연동:
// gridMarket(KR/US)이 바뀌면 사이드바 탭도 한국/미국으로 따라간다.
watch(() => props.marketSync, (val) => {
  if (val === 'KR') searchMode.value = 'kr';
  else if (val === 'US') searchMode.value = 'us';
}, { immediate: true });
const apiSearchResults = ref([]);
const showDropdown = ref(false);
const searchDebounce = ref(null);

// 액션 상태
const actionLoading = ref(false);
const migrating = ref(false);

// 인라인 에러 (검색 입력 아래)
const errorMsg = ref('');

// 토스트
const toast = ref({ show: false, type: 'success', message: '' });
const toastTimer = ref(null);

// ── Computed ─────────────────────────────────────────────────────

/**
 * items 배열을 enriched 형태로 변환.
 * - displayName: 한글명 우선 (stocksKnown 역조회 → item.name → symbol)
 * - displayTicker: selectedTicker 비교·emit용 키 (symbol 그대로)
 * - pricesMap 에서 실시간 시세 머지
 * - market 기준 색상 규칙 적용
 */
const enrichedItems = computed(() => {
  return props.items.map(item => {
    const symbol = item.symbol || '';
    const market = item.market || 'US';

    // 한글명 우선: stocksKnown 역조회 → item.name
    const koName = lookupKoName(symbol, market);
    const displayName = koName || item.name || symbol;

    // displayTicker: select emit 키 (symbol 그대로)
    const displayTicker = symbol;

    // pricesMap 키 탐색:
    // 1) symbol 그대로 ('TSLA', '005930')
    // 2) KR이면 symbol + '.KS' 시도
    // 3) KR이면 symbol + '.KQ' 시도
    let priceEntry = props.pricesMap[symbol]
      || (market === 'KR' ? props.pricesMap[symbol + '.KS'] : null)
      || (market === 'KR' ? props.pricesMap[symbol + '.KQ'] : null)
      || null;

    const hasPrice = !!priceEntry && priceEntry.price !== null && priceEntry.price !== undefined;

    return {
      watchlist_id: item.watchlist_id,
      stock_id: item.stock_id,
      symbol,
      market,
      displayName,
      displayTicker,
      hasPrice,
      price: hasPrice ? priceEntry.price : null,
      changePercent: hasPrice && priceEntry.changePercent !== undefined ? priceEntry.changePercent : null,
      flash: hasPrice ? (priceEntry.flash || '') : '',
    };
  });
});

// 탭(한국/미국/전체)에 따라 표시 목록을 분리하고, 하단 차트 그리드 순서에 맞춰 정렬한다.
// (차트를 드래그로 재배치하면 gridOrder 가 바뀌어 사이드바 목록 순서도 연동된다.)
const filteredItems = computed(() => {
  let items = enrichedItems.value;
  if (searchMode.value === 'kr') items = items.filter(it => it.market === 'KR');
  else if (searchMode.value === 'us') items = items.filter(it => it.market === 'US');

  const order = (props.gridOrder || []).filter(s => typeof s === 'string' && s !== '');
  if (order.length === 0) return items;

  // 그리드에 있는 종목은 그리드 순서대로 앞에, 나머지는 기존 순서대로 뒤에(안정 정렬).
  const idxOf = (sym) => {
    const i = order.indexOf(sym);
    return i === -1 ? Infinity : i;
  };
  return [...items].sort((a, b) => idxOf(a.symbol) - idxOf(b.symbol));
});

/**
 * localSearch 즉시 결과 + apiSearchResults 를 ticker 기준 중복 제거 후 병합.
 * 최대 10건.
 */
const mergedSearchResults = computed(() => {
  const q = searchQuery.value.trim();
  if (!q) return [];

  const qLower = q.toLowerCase();
  const hasHangulSyllable = /[가-힣]/.test(q);

  const localMatches = localSearch(q, searchMode.value);
  const seenTickers = new Set(localMatches.map(m => m.ticker));

  let apiMatches = apiSearchResults.value
    .filter(s => !seenTickers.has(s.ticker))
    .map(s => ({
      ticker: s.ticker,
      name: s.name,
      subName: s.exchange ? `${s.exchange} | ${s.ticker}` : s.ticker,
      isKorean: s.isKorean,
    }));

  // 한글(완성형) 검색어인데 종목명/티커에 그 검색어가 실제로 들어있지 않은
  // API 느슨한 매칭은 제거한다. 예: '마이크론' 검색에 '마이크로소프트' 같은 비일치 항목 제외.
  if (hasHangulSyllable) {
    apiMatches = apiMatches.filter(s =>
      String(s.name).toLowerCase().includes(qLower) ||
      String(s.ticker).toLowerCase().includes(qLower)
    );
  }

  // 관련도 점수: 정확일치 > 접두 일치 > 부분 포함 > 그 외(초성·영문명 등) 순으로 정렬.
  // 이로써 '마이크론' → '마이크론 테크놀로지(MU)'가 최상단에 오고, Enter 시에도 올바른 종목이 추가된다.
  const scoreOf = (item) => {
    const name = String(item.name || '').toLowerCase();
    const ticker = String(item.ticker || '').toLowerCase();
    if (ticker === qLower) return 100;
    if (name === qLower) return 96;
    if (ticker.startsWith(qLower)) return 92;
    if (name.startsWith(qLower)) return 88;
    if (name.includes(qLower)) return 72;
    if (ticker.includes(qLower)) return 64;
    return 40;
  };

  return [...localMatches, ...apiMatches]
    .map((item) => ({ item, s: scoreOf(item) }))
    .sort((a, b) => b.s - a.s)
    .map((x) => x.item)
    .slice(0, 10);
});

// ── 라이프사이클 ─────────────────────────────────────────────────

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside);
  if (searchDebounce.value) clearTimeout(searchDebounce.value);
  if (toastTimer.value) clearTimeout(toastTimer.value);
});

// ── 메서드 ───────────────────────────────────────────────────────

// ── API base ─────────────────────────────────────────────────
function apiBase() {
  return `http://${window.location.hostname || 'localhost'}:8000`;
}

// ── 토스트 ──────────────────────────────────────────────────
function showToast(message, type = 'success') {
  if (toastTimer.value) clearTimeout(toastTimer.value);
  toast.value = { show: true, type, message };
  toastTimer.value = setTimeout(() => {
    toast.value = { show: false, type: 'success', message: '' };
  }, 3500);
}

// ── 검색 ───────────────────────────────────────────────────
function onSearchInput() {
  errorMsg.value = '';
  if (searchDebounce.value) clearTimeout(searchDebounce.value);

  const q = searchQuery.value.trim();
  if (!q) {
    apiSearchResults.value = [];
    return;
  }

  // 드롭다운 즉시 열기 (localSearch 결과는 computed 에서 이미 계산)
  showDropdown.value = true;

  // API 검색은 디바운스 후
  searchDebounce.value = setTimeout(() => {
    fetchSearchApi(q);
  }, 300);
}

async function fetchSearchApi(query) {
  if (!query) return;
  try {
    const res = await fetch(
      `${apiBase()}/api/stocks/search?q=${encodeURIComponent(query)}&type=${searchMode.value}`
    );
    if (res.ok) {
      apiSearchResults.value = await res.json();
    }
  } catch (e) {
    console.error('[UnifiedWatchlist] fetchSearchApi error:', e);
  }
}

function clearSearch() {
  searchQuery.value = '';
  apiSearchResults.value = [];
  showDropdown.value = false;
  errorMsg.value = '';
}

// 검색창에서 Enter → 검색어와 가장 잘 맞는 종목을 바로 관심 추가
// (티커/종목명이 정확히 일치하면 그것을, 아니면 최상위 검색 결과를 추가)
function addBestMatch() {
  const results = mergedSearchResults.value;
  if (!results || results.length === 0) return;
  const q = searchQuery.value.trim().toLowerCase();
  const exact = results.find(s =>
    String(s.ticker).toLowerCase() === q ||
    normalizeKrTicker(String(s.ticker)).toLowerCase() === q ||
    String(s.name).toLowerCase() === q
  );
  addItem(exact || results[0]);
}

function handleClickOutside(e) {
  if (searchContainer.value && !searchContainer.value.contains(e.target)) {
    showDropdown.value = false;
  }
}

// ── 종목 추가 ───────────────────────────────────────────────
async function addItem(stock) {
  if (actionLoading.value) return;
  actionLoading.value = true;
  errorMsg.value = '';

  try {
    // KR 종목은 .KS/.KQ 접미사 제거 후 DB symbol 형태로 전송
    const symbol = stock.isKorean ? normalizeKrTicker(stock.ticker) : stock.ticker;
    const market = stock.isKorean ? 'KR' : 'US';

    await axios.post(
      `${apiBase()}/api/watchlist`,
      { symbol, market },
      { headers: { Accept: 'application/json' } }
    );

    showToast(`${stock.name}(${symbol}) 관심 추가 완료`, 'success');
    clearSearch();
    emit('changed');
  } catch (e) {
    if (e.response?.status === 409) {
      showToast('이미 관심 목록에 있는 종목입니다', 'warn');
      errorMsg.value = '이미 관심 목록에 있는 종목입니다';
    } else {
      const msg = e.response?.data?.message || '관심 추가에 실패했습니다';
      showToast(msg, 'error');
      errorMsg.value = msg;
    }
    console.error('[UnifiedWatchlist] addItem error:', e);
  } finally {
    actionLoading.value = false;
  }
}

// ── 종목 삭제 ───────────────────────────────────────────────
async function removeItem(item) {
  if (actionLoading.value) return;
  if (!confirm(`'${item.displayName}'을(를) 관심 목록에서 삭제할까요?`)) return;

  actionLoading.value = true;
  try {
    await axios.delete(
      `${apiBase()}/api/watchlist/${item.watchlist_id}`,
      { headers: { Accept: 'application/json' } }
    );
    showToast(`${item.symbol} 삭제 완료`, 'success');
    emit('changed');
  } catch (e) {
    const msg = e.response?.data?.message || '삭제에 실패했습니다';
    showToast(msg, 'error');
    console.error('[UnifiedWatchlist] removeItem error:', e);
  } finally {
    actionLoading.value = false;
  }
}

// ── localStorage 이관 (일회성) ──────────────────────────────
async function migrateLocalStorage() {
  const raw = localStorage.getItem('watchlist');
  if (!raw) {
    showToast('기존 관심종목(모니터링)이 없습니다', 'warn');
    return;
  }

  let list;
  try {
    list = JSON.parse(raw);
  } catch {
    showToast('기존 관심종목 데이터가 올바르지 않습니다', 'error');
    return;
  }

  if (!Array.isArray(list) || list.length === 0) {
    showToast('기존 관심종목이 비어 있습니다', 'warn');
    return;
  }

  // 현재 DB 관심종목의 symbol 목록 (중복 방지 사전 필터)
  const dbSymbols = new Set(
    (props.items || []).map(w => String(w.symbol).toUpperCase())
  );

  const parsed = list.map(t => parseLocalStorageTicker(t)).filter(Boolean);
  const toAdd = parsed.filter(p => !dbSymbols.has(p.symbol.toUpperCase()));
  const skipCount = parsed.length - toAdd.length;

  if (toAdd.length === 0) {
    showToast(`전부 이미 DB에 있습니다 (${skipCount}건 skip)`, 'warn');
    return;
  }

  if (!confirm(
    `기존 관심종목 ${parsed.length}건 중 ${toAdd.length}건을 DB로 가져올까요?\n(${skipCount}건은 이미 등록됨, skip)`
  )) return;

  migrating.value = true;
  let added = 0;
  let skipped = skipCount;
  const errors = [];

  for (const item of toAdd) {
    try {
      await axios.post(
        `${apiBase()}/api/watchlist`,
        { symbol: item.symbol, market: item.market },
        { headers: { Accept: 'application/json' } }
      );
      added++;
    } catch (e) {
      if (e.response?.status === 409) {
        skipped++;
      } else {
        errors.push(item.symbol);
        console.error('[UnifiedWatchlist] migrateLocalStorage error for', item.symbol, e);
      }
    }
  }

  migrating.value = false;

  const resultMsg = errors.length > 0
    ? `이관 완료: ${added}건 추가, ${skipped}건 skip, ${errors.length}건 실패(${errors.join(', ')})`
    : `이관 완료: ${added}건 추가, ${skipped}건 skip`;

  showToast(resultMsg, errors.length > 0 ? 'warn' : 'success');
  emit('changed');
  // localStorage는 모니터링이 계속 사용 → 삭제하지 않음
}

// ── 가격 포맷 ───────────────────────────────────────────────
function formatPrice(market, price) {
  if (price === null || price === undefined) return '---';
  if (market === 'KR') {
    // 한국 주식은 통화기호(₩) 대신 '원' 접미사로 표기 (앱 전체 표기와 일치)
    return `${Math.round(price).toLocaleString()}원`;
  }
  return `${Number(price).toFixed(2)}$`;
}
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 99px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #6366f1;
}

/* 드롭다운 페이드 슬라이드 */
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.fade-slide-enter-from,
.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

/* 토스트 슬라이드 */
.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.toast-slide-enter-from,
.toast-slide-leave-to {
  opacity: 0;
  transform: translateX(16px);
}
</style>
