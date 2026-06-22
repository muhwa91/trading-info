<template>
  <div class="h-screen bg-base-300 text-base-content font-sans flex flex-col selection:bg-indigo-500/30 selection:text-indigo-200 overflow-hidden">

    <!-- ── 상단 고정 영역 (헤더 + 손익 요약 바) ── -->
    <div class="sticky top-0 z-50 shrink-0">
      <!-- Header -->
      <header class="navbar bg-base-100/75 backdrop-blur-lg border-b border-base-content/8 px-4 sm:px-6 min-h-0 py-2 sm:py-2.5 shrink-0 select-none shadow-sm">
        <!-- 로고 -->
        <div class="flex items-center gap-3 min-w-0 shrink-0">
          <!-- 로고 아이콘 (클릭 → 메인으로 이동) -->
          <div
            class="w-9 h-9 bg-indigo-600 hover:rotate-12 hover:scale-110 active:scale-95 transition-all duration-300 ease-out rounded-xl flex items-center justify-center text-white shadow-md shadow-indigo-600/30 cursor-pointer shrink-0"
            role="button"
            tabindex="0"
            title="메인으로"
            aria-label="메인으로 이동"
            @click="goHome"
            @keydown.enter="goHome"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
          </div>

          <!-- 앱 이름 -->
          <span class="hidden sm:block text-base font-black tracking-tight text-base-content/80 shrink-0">Stockpit</span>
        </div>

        <!-- 손익 요약 바 (헤더에 통합 — 환율·미국/국내 손익) -->
        <div class="flex-1 min-w-0 flex items-center overflow-x-auto custom-scrollbar px-2 sm:px-4">
          <PortfolioSummaryBar
            :holdings="liveHoldings"
            :exchange-rate="dashboardData ? dashboardData.exchange_rate : null"
            :compact="true"
          />
        </div>

        <div class="flex-none flex items-center gap-2">
          <!-- 사이드바 좌/우 위치 토글 -->
          <button
            @click="sidebarPosition = sidebarPosition === 'left' ? 'right' : 'left'"
            :title="sidebarPosition === 'left' ? '관심종목 사이드바를 오른쪽으로' : '관심종목 사이드바를 왼쪽으로'"
            aria-label="사이드바 좌우 위치 변경"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-base-content/55 hover:text-white hover:bg-base-200/60 border border-base-content/8 transition-all duration-200 cursor-pointer shrink-0"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l-3 3 3 3M16 9l3 3-3 3M5 12h14" />
            </svg>
          </button>

          <!-- 사이드바 열기/닫기 토글 -->
          <button
            @click="isSidebarCollapsed = !isSidebarCollapsed"
            :title="isSidebarCollapsed ? '관심종목 사이드바 열기' : '관심종목 사이드바 닫기'"
            :aria-label="isSidebarCollapsed ? '사이드바 열기' : '사이드바 닫기'"
            :class="[
              'w-8 h-8 flex items-center justify-center rounded-lg border transition-all duration-200 cursor-pointer shrink-0',
              isSidebarCollapsed
                ? 'bg-indigo-600/90 text-white border-indigo-400/40 ring-2 ring-indigo-500/25 hover:bg-indigo-500 animate-pulse-soft'
                : 'text-base-content/55 hover:text-white hover:bg-base-200/60 border-base-content/8'
            ]"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5A1.5 1.5 0 015.5 4h13A1.5 1.5 0 0120 5.5v13a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5v-13z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 4v16" />
            </svg>
          </button>

          <!-- WebSocket 상태 필 -->
          <div
            :class="[
              'flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-[12px] font-extrabold font-mono transition-all duration-300',
              error
                ? 'bg-error/8 border-error/25 text-error'
                : 'bg-base-200/80 border-base-content/8 text-success'
            ]"
            :title="error ? 'WebSocket 연결 끊김' : 'WebSocket 실시간 연결 중'"
            role="status"
            :aria-label="error ? '연결 끊김' : '실시간 연결 중'"
          >
            <span class="relative flex h-1.5 w-1.5 shrink-0">
              <span :class="['animate-ping absolute inline-flex h-full w-full rounded-full opacity-60', error ? 'bg-error' : 'bg-success']"></span>
              <span :class="['relative inline-flex rounded-full h-1.5 w-1.5', error ? 'bg-error' : 'bg-success']"></span>
            </span>
            <span :class="error ? 'animate-pulse' : ''">
              {{ error ? 'DISCONNECTED' : 'LIVE' }}
            </span>
          </div>
        </div>
      </header>
    </div>

    <!-- ── 스크롤 영역 (사이드바 + 메인) ── -->
    <div :class="['flex-1 flex overflow-hidden overflow-x-hidden min-h-0', sidebarPosition === 'right' ? 'flex-col-reverse md:flex-row-reverse' : 'flex-col md:flex-row']">

      <!-- UnifiedWatchlist 사이드바 -->
      <aside
        :class="[
          'relative transition-all duration-300 shrink-0 flex flex-col bg-base-100',
          isSidebarCollapsed
            ? 'w-full md:w-0 h-0 md:h-full border-0'
            : [
                'w-full md:w-72 lg:w-96 h-64 md:h-full',
                sidebarPosition === 'left'
                  ? 'border-b md:border-b-0 md:border-r border-base-content/10'
                  : 'border-t md:border-t-0 md:border-l border-base-content/10'
              ]
        ]"
      >
        <div :class="['w-full h-full flex flex-col', isSidebarCollapsed ? 'overflow-hidden' : '']">
          <UnifiedWatchlist
            :items="dashboardData ? dashboardData.watchlist : []"
            :prices-map="watchlistDetailsMap"
            :selected-ticker="gridTickers[activeGridIndex]"
            :market-sync="gridMarket"
            :grid-order="gridTickers"
            @select="handleUnifiedSelect"
            @changed="onWatchlistChanged"
            @market-change="onSidebarMarketChange"
          />
        </div>
      </aside>

      <!-- 메인 패널 -->
      <main ref="mainScroll" class="flex-1 flex flex-col min-h-0 min-w-0 p-3 md:p-5 overflow-y-auto overflow-x-hidden space-y-4 md:space-y-5 bg-base-300">

        <!-- WS 로딩 스켈레톤 -->
        <div v-if="loading" class="flex flex-col space-y-4 md:space-y-5 animate-pulse">
          <!-- 보유표 스켈레톤 -->
          <div class="card bg-base-100/45 border border-base-content/8 rounded-2xl overflow-hidden p-4 md:p-5">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2">
                <div class="skeleton h-4 w-4 rounded"></div>
                <div class="skeleton h-4 w-20 rounded"></div>
                <div class="skeleton h-4 w-6 rounded-full"></div>
              </div>
              <div class="skeleton h-6 w-12 rounded-lg"></div>
            </div>
            <div class="space-y-2">
              <div v-for="n in 3" :key="n" class="flex gap-3">
                <div class="skeleton h-10 flex-1 rounded"></div>
                <div class="skeleton h-10 w-16 rounded"></div>
                <div class="skeleton h-10 w-20 rounded"></div>
                <div class="skeleton h-10 w-20 rounded"></div>
                <div class="skeleton h-10 w-24 rounded"></div>
                <div class="skeleton h-10 w-16 rounded"></div>
                <div class="skeleton h-10 w-16 rounded"></div>
              </div>
            </div>
          </div>

          <!-- 지수 카드 스켈레톤 (2열) -->
          <div class="grid gap-4 grid-cols-1 lg:grid-cols-2">
            <div v-for="n in 2" :key="n" class="skeleton h-48 rounded-2xl"></div>
          </div>

          <!-- 종목 그리드 스켈레톤 (최대 6개) -->
          <div class="grid gap-4 grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            <div v-for="n in 6" :key="n" class="skeleton h-72 sm:h-80 rounded-2xl"></div>
          </div>
        </div>

        <div v-else class="flex flex-col space-y-4 md:space-y-5">

          <!-- ① 보유 상세 패널 (최상단, 스크롤해도 상단 고정) -->
          <div class="sticky top-0 z-30">
            <HoldingsPanel
              :holdings="liveHoldings"
              :exchange-rate="dashboardData ? dashboardData.exchange_rate : null"
              @refresh="fetchDashboard"
            />
          </div>

          <!-- ② 지수 영역: NQ=F + 코스피(야간선물 or 종합지수) — 접기/펼치기 -->
          <div class="flex flex-col gap-3">
            <!-- 접기/펼치기 헤더 -->
            <button
              type="button"
              @click="indexCollapsed = !indexCollapsed"
              class="flex items-center gap-2 px-3 py-2 rounded-xl bg-base-100/40 backdrop-blur-md border border-base-content/8 hover:border-indigo-500/25 transition-all duration-200 cursor-pointer select-none"
              :aria-expanded="!indexCollapsed"
              aria-label="지수 영역 접기/펼치기"
            >
              <svg :class="['h-3.5 w-3.5 text-white transition-transform duration-200', indexCollapsed ? '-rotate-90' : '']" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
              <span class="text-xs font-extrabold text-white tracking-wider uppercase">지수 · 나스닥 / 코스피</span>
            </button>
            <!-- visibleIndexTickers: 나스닥 + (정규장=종합지수 / 야간=야간선물 / 그 외 없음) -->
            <div v-show="!indexCollapsed" :class="['grid gap-4 shrink-0 items-start transition-all duration-300', visibleIndexTickers.length === 1 ? 'grid-cols-1' : 'grid-cols-1 lg:grid-cols-2']">
            <div
              v-for="ticker in visibleIndexTickers"
              :key="ticker"
              :class="indexDisplayMode(ticker) === 'chart' ? 'h-72 sm:h-80 lg:h-90' : 'h-60'"
              class="card bg-base-100/45 backdrop-blur-md border border-base-content/8 hover:border-indigo-500/25 hover:shadow-lg hover:shadow-indigo-500/5 transition-all duration-300 p-4 flex flex-col gap-3 rounded-2xl card-hover"
            >
              <div class="flex-1 min-h-0">
                <template v-if="indexStockData[ticker]">
                  <StockChart
                    v-if="indexDisplayMode(ticker) === 'chart'"
                    :key="`${ticker}_${indexTimeframes[ticker]}`"
                    :timeframe="indexTimeframes[ticker]"
                    :ticker="ticker"
                    :name="indexStockData[ticker].name"
                    :current-price="indexStockData[ticker].current_price"
                    :change-amount="indexStockData[ticker].change_amount"
                    :change-percent="indexStockData[ticker].change_percent"
                    :candles="indexStockData[ticker].candles"
                    :session="indexStockData[ticker].session || ''"
                    @timeframe-change="handleIndexTimeframeChange(ticker, $event)"
                  />
                  <div v-else class="h-full flex flex-col items-center justify-center gap-2 select-none px-4 text-center">
                    <span class="text-xs font-bold text-base-content/50 tracking-widest uppercase">{{ indexStockData[ticker].name }}</span>
                    <span class="text-[2.5rem] font-black font-mono tracking-tight leading-none" :class="indexQuoteColor(ticker)">
                      {{ formatIndexValue(indexStockData[ticker].current_price) }}
                    </span>
                    <div class="flex items-center gap-2 text-sm font-bold font-mono" :class="indexQuoteColor(ticker)">
                      <span>{{ indexQuoteIsUp(ticker) ? '▲' : '▼' }}</span>
                      <span>{{ (indexQuoteIsUp(ticker) ? '+' : '') + formatIndexValue(indexStockData[ticker].change_amount) }}</span>
                      <span class="opacity-75">({{ (indexQuoteIsUp(ticker) ? '+' : '') + Number(indexStockData[ticker].change_percent).toFixed(2) }}%)</span>
                    </div>
                    <span class="text-[10px] font-semibold text-base-content/35 uppercase tracking-widest mt-1 px-2 py-0.5 rounded-full bg-base-200/50 border border-base-content/8">{{ indexQuoteLabel(ticker) }}</span>
                  </div>
                </template>
                <div v-else class="h-full flex flex-col items-center justify-center gap-3">
                  <span class="loading loading-spinner text-indigo-500/60 loading-sm"></span>
                  <span class="text-[10px] text-base-content/35 font-mono tracking-widest uppercase">Loading Index...</span>
                </div>
              </div>
            </div>
          </div>
          </div>

          <!-- ③ 종목 차트 그리드 (2×2) — 국내/미국 스왑 -->
          <div class="flex flex-col gap-3">
            <!-- 국내/미국 토글 -->
            <div class="flex items-center gap-2">
              <div class="tabs tabs-boxed bg-base-100/40 backdrop-blur-md p-0.5 rounded-xl border border-base-content/8 gap-0">
                <button
                  v-for="m in [{ v: 'KR', l: '국내' }, { v: 'US', l: '미국' }]"
                  :key="m.v"
                  type="button"
                  @click="setGridMarket(m.v)"
                  :class="[
                    'tab rounded-lg text-xs font-extrabold transition-all duration-200 cursor-pointer px-4 py-1',
                    gridMarket === m.v
                      ? 'tab-active bg-indigo-600/15 border border-indigo-500/25 text-indigo-300'
                      : 'text-base-content/45 hover:text-base-content/70 border border-transparent'
                  ]"
                  :aria-pressed="gridMarket === m.v"
                >{{ m.l }}</button>
              </div>

              <!-- 현재 시장 세션 배지 (토글 옆) -->
              <span
                v-if="gridSessionLabel"
                :class="[
                  'inline-flex items-center justify-center px-4 h-8 rounded-lg text-xs font-extrabold leading-tight shrink-0 border',
                  gridSessionLabel === '주간거래' ? 'text-emerald-400 bg-emerald-500/8 border-emerald-500/20' : '',
                  gridSessionLabel === '프리마켓' ? 'text-amber-400 bg-amber-500/8 border-amber-500/20' : '',
                  gridSessionLabel === '정규장'   ? 'text-pink-400  bg-pink-500/8  border-pink-500/20'  : '',
                  (gridSessionLabel === '애프터마켓' || gridSessionLabel === '야간거래' || gridSessionLabel === '거래중')
                                                  ? 'text-cyan-400  bg-cyan-500/8  border-cyan-500/20'  : '',
                  gridSessionLabel === '장마감'   ? 'text-base-content/40 bg-base-200/40 border-base-content/10' : ''
                ]"
              >{{ gridSessionLabel }}</span>
            </div>
            <div
              :class="[
                'grid gap-4 shrink-0 items-start transition-all duration-300',
                activeGridTickersCount === 1
                  ? 'grid-cols-1'
                  : 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3'
              ]"
            >
            <div
              v-for="(ticker, idx) in gridTickers"
              :key="idx"
              v-show="ticker !== '' || activeGridTickersCount !== 1"
              :draggable="!!ticker"
              @click="activeGridIndex = idx"
              @dragstart="onGridDragStart(idx, $event)"
              @dragend="onGridDragEnd"
              @dragover.prevent="onGridDragOver(idx)"
              @dragleave="onGridDragLeave(idx)"
              @drop="onGridDrop(idx)"
              :class="[
                'group relative card bg-base-100/45 backdrop-blur-md border transition-all duration-250 overflow-hidden rounded-2xl',
                ticker ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                isGridClosed(idx)
                  ? 'h-60'
                  : (activeGridTickersCount === 1 ? 'h-80 sm:h-96 lg:h-120' : 'h-72 sm:h-80 lg:h-110'),
                gridDragOverIdx === idx
                  ? 'border-indigo-400 ring-2 ring-indigo-400/50'
                  : (activeGridIndex === idx
                    ? 'border-indigo-500/60 shadow-xl shadow-indigo-500/8 ring-1 ring-indigo-500/20'
                    : 'border-base-content/8 hover:border-base-content/20 hover:shadow-md'),
                gridDraggingIdx === idx ? 'opacity-50' : ''
              ]"
            >
              <!-- 활성 카드 상단 강조 바 -->
              <div
                v-if="activeGridIndex === idx"
                class="absolute top-0 left-4 right-4 h-0.5 rounded-b-full bg-indigo-500/70"
              ></div>


              <template v-if="ticker && gridStockData[idx]">
                <!-- 휴장/장마감: 종가 숫자 표시 + 차트 보기 버튼 -->
                <div v-if="isGridClosed(idx)" class="h-full flex flex-col items-center justify-center gap-2 select-none px-4 text-center">
                  <span class="text-xs font-bold text-base-content/45 tracking-widest uppercase">{{ gridStockData[idx].name }}</span>
                  <span class="text-[2.25rem] font-black font-mono tracking-tight leading-none" :class="quoteColorClass(ticker, gridStockData[idx].change_amount)">
                    {{ formatStockValue(ticker, gridStockData[idx].current_price) }}
                  </span>
                  <div class="flex items-center gap-2 text-sm font-bold font-mono" :class="quoteColorClass(ticker, gridStockData[idx].change_amount)">
                    <span>{{ (gridStockData[idx].change_amount || 0) >= 0 ? '▲' : '▼' }}</span>
                    <span>{{ ((gridStockData[idx].change_amount || 0) >= 0 ? '+' : '') + formatStockValue(ticker, gridStockData[idx].change_amount) }}</span>
                    <span class="opacity-75">({{ ((gridStockData[idx].change_amount || 0) >= 0 ? '+' : '') + Number(gridStockData[idx].change_percent).toFixed(2) }}%)</span>
                  </div>
                  <span class="text-[10px] font-semibold text-base-content/35 uppercase tracking-widest mt-1 px-2 py-0.5 rounded-full bg-base-200/50 border border-base-content/8">{{ gridStockData[idx].is_trading_day === false ? '휴장 · 전일 마감' : '장마감 · 종가' }}</span>
                  <button
                    @click.stop="openGridChartModal(ticker, idx)"
                    class="mt-1 flex items-center gap-1 px-3 py-1 rounded-full text-[11px] font-bold border border-indigo-500/30 text-indigo-400 bg-indigo-500/8 hover:bg-indigo-500/18 hover:border-indigo-400/50 transition-all duration-200 cursor-pointer"
                    :aria-label="`${gridStockData[idx].name} 차트 보기`"
                    title="프리/애프터마켓 봉 포함 차트 보기"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                    차트 보기
                  </button>
                </div>
                <!-- 거래일: 차트 -->
                <StockChart
                  v-else
                  :key="`${ticker}_${gridTimeframes[idx]}`"
                  :timeframe="gridTimeframes[idx]"
                  :ticker="ticker"
                  :name="gridStockData[idx].name"
                  :current-price="gridStockData[idx].current_price"
                  :change-amount="gridStockData[idx].change_amount"
                  :change-percent="gridStockData[idx].change_percent"
                  :candles="gridStockData[idx].candles"
                  :session="gridStockData[idx].session || ''"
                  :usd-krw-rate="usdKrwRate"
                  :average-price="getAveragePriceForTicker(ticker)"
                  @timeframe-change="handleTimeframeChange(idx, $event)"
                />
              </template>

              <!-- 종목 데이터 로딩 중 -->
              <div v-else-if="ticker" class="h-full flex flex-col items-center justify-center gap-3">
                <span class="loading loading-spinner text-indigo-500/60 loading-sm"></span>
                <span class="text-[10px] text-base-content/35 font-mono tracking-widest uppercase">{{ ticker }} 데이터 수신 중...</span>
              </div>

              <!-- 빈 슬롯 -->
              <div v-else class="h-full flex flex-col items-center justify-center gap-3 text-center p-6 select-none">
                <div class="w-12 h-12 rounded-xl border-2 border-dashed border-base-content/15 flex items-center justify-center">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-base-content/25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                  </svg>
                </div>
                <div class="flex flex-col gap-1">
                  <span class="text-xs font-bold text-base-content/40">슬롯 비어 있음</span>
                  <span class="text-[10px] text-base-content/25 leading-relaxed">왼쪽 관심 종목에서<br>종목을 클릭해 배치하세요</span>
                </div>
              </div>
            </div>
          </div>
          </div>

        </div>
      </main>
    </div>

    <!-- ── 휴장 카드 차트 모달 ── -->
    <Transition name="grid-modal-fade">
      <div
        v-if="gridChartModal.show"
        class="fixed inset-0 z-60 flex flex-col"
        role="dialog"
        aria-modal="true"
        :aria-label="gridChartModal.stockName ? `${gridChartModal.stockName} 차트` : '종목 차트'"
        @keydown.esc="closeGridChartModal"
        tabindex="-1"
        ref="gridChartModalEl"
      >
        <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="closeGridChartModal"></div>

        <div class="relative z-10 m-auto w-full max-w-5xl h-[90vh] sm:h-[80vh] min-h-0 sm:min-h-120 mx-2 sm:mx-auto bg-base-100 border border-base-content/12 rounded-2xl shadow-2xl flex flex-col overflow-hidden">

          <div class="flex items-center justify-between px-5 py-3 border-b border-base-content/8 shrink-0">
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded-md text-[11px] font-extrabold font-mono text-indigo-300 bg-indigo-500/12 border border-indigo-500/20 tracking-wider">
                {{ gridChartModal.ticker }}
              </span>
              <span class="text-sm font-black text-white">{{ gridChartModal.stockName }}</span>
              <span class="px-1.5 py-0.5 rounded text-[10px] font-extrabold font-mono border leading-tight text-amber-400 bg-amber-500/8 border-amber-500/20">
                휴장 · 프리/애프터 봉
              </span>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="gridChartModal.loading" class="loading loading-spinner loading-xs text-indigo-400"></span>
              <span v-if="gridChartModal.error" class="text-[11px] text-error font-bold font-mono">데이터 오류</span>
              <button
                @click="closeGridChartModal"
                class="w-7 h-7 flex items-center justify-center rounded-lg text-base-content/40 hover:text-base-content/80 hover:bg-base-200/60 transition-all cursor-pointer"
                aria-label="차트 닫기"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <div class="flex-1 min-h-0 p-3">
            <div v-if="gridChartModal.loading" class="h-full flex items-center justify-center gap-3">
              <span class="loading loading-ring loading-md text-indigo-500"></span>
              <span class="text-xs font-bold text-base-content/50 font-mono">차트 데이터 불러오는 중...</span>
            </div>
            <div v-else-if="gridChartModal.error" class="h-full flex flex-col items-center justify-center gap-3">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-error/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="text-xs font-bold text-error/70 font-mono">{{ gridChartModal.errorMessage }}</p>
              <button @click="fetchGridChartCandles" class="btn btn-xs btn-outline btn-error font-bold rounded-lg cursor-pointer">재시도</button>
            </div>
            <StockChart
              v-else-if="gridChartModal.candles.length > 0"
              :key="`grid_chart_${gridChartModal.ticker}_${gridChartModal.timeframe}`"
              :ticker="gridChartModal.ticker"
              :name="gridChartModal.stockName"
              :current-price="gridChartModal.currentPrice"
              :change-amount="gridChartModal.changeAmount"
              :change-percent="gridChartModal.changePercent"
              :candles="gridChartModal.candles"
              :session="gridChartModal.session"
              :timeframe="gridChartModal.timeframe"
              :usd-krw-rate="usdKrwRate"
              :average-price="getAveragePriceForTicker(gridChartModal.ticker)"
              @timeframe-change="onGridChartTimeframeChange"
            />
            <div v-else-if="!gridChartModal.loading" class="h-full flex flex-col items-center justify-center gap-3">
              <p class="text-xs font-bold text-base-content/40 font-mono">차트 데이터가 없습니다 (장외 시간 또는 휴장일)</p>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import StockChart from './components/StockChart.vue';
import PortfolioSummaryBar from './components/PortfolioSummaryBar.vue';
import HoldingsPanel from './components/HoldingsPanel.vue';
import UnifiedWatchlist from './components/UnifiedWatchlist.vue';

// ── template refs ──────────────────────────────────────────────
const mainScroll = ref(null);
const gridChartModalEl = ref(null);

// ── WS 관련 상태 ───────────────────────────────────────────────
const GRID_SIZE = 6; // 차트 그리드 슬롯 개수
const gridTickers = ref(Array(GRID_SIZE).fill(''));
const gridStockData = ref(Array(GRID_SIZE).fill(null));
const stockDataCache = ref({}); // 티커별 마지막 WS 데이터 캐시 — 시장 토글 시 즉시 표시용
const gridTimeframes = ref(Array(GRID_SIZE).fill('3m'));
const indexTickers = ref(['NQ=F', 'KOSPI200', 'KOSPI_NIGHT']);
const indexStockData = ref({ 'NQ=F': null, 'KOSPI200': null, 'KOSPI_NIGHT': null });
const indexTimeframes = ref({ 'NQ=F': '3m', 'KOSPI200': '3m', 'KOSPI_NIGHT': '3m' });
const activeGridIndex = ref(0);
const gridDraggingIdx = ref(null); // 드래그 중인 그리드 슬롯
const gridDragOverIdx = ref(null); // 드롭 대상으로 올라온 그리드 슬롯
const loading = ref(true);
const error = ref(false);
const ws = ref(null);
const watchlistDetailsMap = ref({});
const usdKrwRate = ref(1380.00);

// ── 레이아웃 ───────────────────────────────────────────────────
const sidebarPosition = ref(localStorage.getItem('sidebarPosition') || 'left');
const isSidebarCollapsed = ref(localStorage.getItem('isSidebarCollapsed') === 'true');
const indexCollapsed = ref(localStorage.getItem('indexCollapsed') === 'true'); // 지수(나스닥·코스피) 영역 접힘 여부
const gridMarket = ref(localStorage.getItem('gridMarket') === 'US' ? 'US' : 'KR'); // 하단 그리드 시장 필터(국내/미국)
const windowWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1024);

// ── 포트폴리오 대시보드 ────────────────────────────────────────
const dashboardData = ref(null);       // { summary, holdings, watchlist, exchange_rate }
const dashboardLoading = ref(false);
const dashboardError = ref(null);
const dashboardPollTimer = ref(null);
const gridInitialized = ref(false);    // 초기 2×2 배치 완료 여부

// WS 실시간 가격 맵: 보유종목 심볼 → current_price (폴링과 별개로 실시간 덮어쓰기용)
// 키는 normalizeTicker() 기준 소문자 코드 (예: "0167a0", "mu")
const livePrices = ref({});

// ── 휴장 카드 차트 모달 ────────────────────────────────────────
const gridChartModal = reactive({
  show: false,
  ticker: '',
  stockName: '',
  timeframe: '3m',
  candles: [],
  currentPrice: null,
  changeAmount: null,
  changePercent: null,
  session: '',
  loading: false,
  error: false,
  errorMessage: '',
  pollTimer: null,
});

// ── 이벤트 핸들러 레퍼런스 (beforeUnmount 해제용) ─────────────
let _onVisibilityChange = null;
let _gridChartKeyDown = null;
let _onResize = null;
let _flashResetTimer = null;

// ── computed ───────────────────────────────────────────────────

const activeGridTickersCount = computed(() => {
  return gridTickers.value.filter(t => t !== '').length;
});

// 현재 그리드 시장의 세션 라벨(정규장/주간거래/장마감 등). 같은 시장이면 모든 카드가 동일하므로
// 처음 로드된 카드의 session 을 대표로 사용 → 국내/미국 토글 옆 배지로 표시.
const gridSessionLabel = computed(() => {
  const d = gridStockData.value.find(x => x && x.session);
  return d ? d.session : '';
});

// DB 관심종목 심볼 배열
const dbWatchlistSymbols = computed(() => {
  if (!dashboardData.value || !Array.isArray(dashboardData.value.watchlist)) return [];
  return dashboardData.value.watchlist.map(w => w.symbol).filter(s => typeof s === 'string' && s.trim() !== '');
});

// 관심종목을 시장(KR/US)별 심볼 배열로 — 하단 그리드 국내/미국 토글에 사용
const watchlistByMarket = computed(() => {
  const wl = (dashboardData.value && Array.isArray(dashboardData.value.watchlist)) ? dashboardData.value.watchlist : [];
  const pick = (mkt) => wl.filter(w => w.market === mkt).map(w => w.symbol).filter(s => typeof s === 'string' && s.trim() !== '');
  return { KR: pick('KR'), US: pick('US') };
});

/**
 * 보유종목 WS 실시간 가격 반영 computed.
 * - 폴링(10초)으로 받은 dashboardData.holdings 를 기반으로 하되,
 *   livePrices 에 해당 심볼의 실시간 가격이 있으면 current_price 를 교체하고
 *   파생 필드(profitRate, profitKRW, marketValueKRW, costKRW)를 재계산한다.
 * - 라이브 가격이 없으면 원본 holding 데이터를 그대로 사용(폴백).
 * - HoldingsPanel, PortfolioSummaryBar 모두 이 computed 를 받는다.
 */
const liveHoldings = computed(() => {
  if (!dashboardData.value || !Array.isArray(dashboardData.value.holdings)) return [];
  return dashboardData.value.holdings.map(h => {
    const key = normalizeTicker(h.symbol);
    const livePrice = livePrices.value[key];
    // 라이브 가격이 없거나 유효하지 않으면 원본 그대로 반환
    if (livePrice === undefined || livePrice === null || isNaN(Number(livePrice))) return h;
    const cur = Number(livePrice);
    const avg = Number(h.average_price);
    const qty = Number(h.quantity);
    // 평단가나 수량이 올바르지 않으면 원본 반환
    if (isNaN(avg) || isNaN(qty) || avg <= 0 || qty <= 0) return h;
    const profitRate = (cur - avg) / avg;
    if (h.market === 'KR') {
      // 원화 종목: profitKRW, marketValueKRW, costKRW 재계산
      const profitKRW = (cur - avg) * qty;
      const marketValueKRW = cur * qty;
      const costKRW = avg * qty;
      return {
        ...h,
        current_price: cur,
        price_available: true,
        profitRate,
        profitKRW,
        marketValueKRW,
        costKRW,
      };
    } else {
      // 미국 종목: WS 실시간가는 연장 현재가(current_price)에만 반영.
      // regular_close_price 는 대시보드 폴링값(KIS base 기준)을 그대로 유지해
      // 미실현손익 계산 기준이 실시간으로 변하지 않도록 한다.
      // profitRate 는 연장가 포함 총 손익률 (current_price 기준).
      return {
        ...h,
        current_price: cur,
        price_available: true,
        profitRate,
        // regular_close_price 는 h(원본)에서 그대로 상속됨 (...h 스프레드)
      };
    }
  });
});

// 시간대별 지수 노출 규칙 (서로 스왑)
// - NQ=F: 항상
// - 코스피 종합지수: 정규장(장 열렸을 때)에만
// - 코스피 야간선물: 야간거래 시간대에만
// - 정규장도 야간도 아니면(장외·휴장) 코스피 카드 없음 → 나스닥만
const visibleIndexTickers = computed(() => {
  const tickers = ['NQ=F'];
  if (isKospiRegularSession.value) {
    tickers.push('KOSPI200');
  } else if (isKospiNightSession.value) {
    tickers.push('KOSPI_NIGHT');
  }
  return tickers;
});

// 코스피 정규장 여부 (장 열렸을 때만 종합지수 노출)
const isKospiRegularSession = computed(() => {
  const d = indexStockData.value['KOSPI200'];
  if (d && d.is_trading_day === false) return false;
  if (d && d.session) return d.session === '정규장';
  // 데이터 없으면 KST 시간으로 폴백
  const kst = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Seoul' }));
  const t = kst.getHours() * 100 + kst.getMinutes();
  const isWeekday = kst.getDay() >= 1 && kst.getDay() <= 5;
  return isWeekday && t >= 900 && t <= 1530;
});

// 코스피 야간거래 시간대 여부 (거래일 18:00~익일 05:00)
const isKospiNightSession = computed(() => {
  const d = indexStockData.value['KOSPI_NIGHT'];
  if (d && d.session) return d.session === '거래중';
  // 데이터 없으면 KST 시간으로 폴백 (거래일 저녁 18:00~ 또는 거래일 다음 새벽 ~05:00)
  const kst = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Seoul' }));
  const dow = kst.getDay();
  const t = kst.getHours() * 100 + kst.getMinutes();
  const eveningTradingDay = dow >= 1 && dow <= 5 && t >= 1800;
  const morningAfterTradingDay = dow >= 2 && dow <= 6 && t < 500;
  return eveningTradingDay || morningAfterTradingDay;
});

// ── watch ──────────────────────────────────────────────────────

watch(isSidebarCollapsed, (val) => {
  localStorage.setItem('isSidebarCollapsed', val);
});

watch(sidebarPosition, (val) => {
  localStorage.setItem('sidebarPosition', val);
});

watch(indexCollapsed, (val) => {
  localStorage.setItem('indexCollapsed', val);
});

watch(gridMarket, (val) => {
  localStorage.setItem('gridMarket', val);
});

// 그리드는 DB 관심종목(dbWatchlistSymbols)이 유일한 소스다.
// (과거 사용하던 localStorage 'watchlist' 키는 더 이상 읽지 않는다 — 관심종목에서
//  지운 종목이 그 캐시 때문에 그리드에 계속 남는 버그가 있었음. 대시보드 로드 시 채운다.)
localStorage.removeItem('watchlist');

// 그리드를 DB 관심종목(현재 선택된 시장)과 일치시킨다: ① 해당 시장 관심종목에 없는
// 그리드 종목 제거, ② 빈 칸을 아직 표시되지 않은 관심종목으로 앞에서부터 채움(최대 4칸).
// 기존에 표시 중인(여전히 유효한) 종목의 위치·순서는 보존한다.
function reconcileGridWithWatchlist() {
  const wl = gridMarket.value === 'US' ? watchlistByMarket.value.US : watchlistByMarket.value.KR;
  gridTickers.value.forEach((t, i) => {
    if (t && !wl.includes(t)) gridTickers.value.splice(i, 1, '');
  });
  const shown = new Set(gridTickers.value.filter(Boolean));
  const remaining = wl.filter(s => !shown.has(s));
  for (let i = 0; i < GRID_SIZE && remaining.length > 0; i++) {
    if (gridTickers.value[i] === '') {
      gridTickers.value.splice(i, 1, remaining.shift());
    }
  }
}

// 하단 그리드 시장 전환(국내 ↔ 미국) — 그리드를 비우고 선택 시장 관심종목으로 다시 채움
function setGridMarket(market) {
  if (gridMarket.value === market) return;
  gridMarket.value = market;
  gridTickers.value = Array(GRID_SIZE).fill('');
  activeGridIndex.value = 0;
  reconcileGridWithWatchlist();
  // 캐시된 데이터로 즉시 채워 스켈레톤 대기를 없앤다(WS가 곧 최신값으로 갱신)
  gridStockData.value = gridTickers.value.map((t) => (t && stockDataCache.value[t]) ? stockDataCache.value[t] : null);
  subscribeToWebSocket();
}

// ── 그리드 카드 드래그앤드롭(위치 교환) ───────────────────────
function onGridDragStart(idx, e) {
  gridDraggingIdx.value = idx;
  if (e && e.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move';
    // 일부 브라우저는 데이터가 있어야 드래그가 시작됨
    try { e.dataTransfer.setData('text/plain', String(idx)); } catch (_) { /* ignore */ }
  }
}
function onGridDragOver(idx) {
  if (gridDraggingIdx.value === null || gridDraggingIdx.value === idx) return;
  gridDragOverIdx.value = idx;
}
function onGridDragLeave(idx) {
  if (gridDragOverIdx.value === idx) gridDragOverIdx.value = null;
}
function onGridDrop(idx) {
  const from = gridDraggingIdx.value;
  gridDragOverIdx.value = null;
  gridDraggingIdx.value = null;
  if (from === null || from === idx) return;
  // 두 슬롯의 티커·타임프레임·데이터를 함께 교환(재요청 없이 위치만 스왑)
  const swap = (arr) => { const a = [...arr.value]; [a[from], a[idx]] = [a[idx], a[from]]; arr.value = a; };
  swap(gridTickers);
  swap(gridTimeframes);
  swap(gridStockData);
  activeGridIndex.value = idx;
}
function onGridDragEnd() {
  gridDraggingIdx.value = null;
  gridDragOverIdx.value = null;
}

// ── 메서드 ────────────────────────────────────────────────────

// 로고 클릭 → 메인으로 이동 (새로고침 없이: 모달 닫고 맨 위로)
function goHome() {
  if (gridChartModal) gridChartModal.show = false;
  nextTick(() => {
    if (mainScroll.value && mainScroll.value.scrollTo) {
      mainScroll.value.scrollTo({ top: 0, behavior: 'smooth' });
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
}

// ── 포트폴리오 대시보드 폴링 ──────────────────────────────────

async function fetchDashboard() {
  if (dashboardLoading.value) return;
  dashboardLoading.value = true;
  const host = window.location.hostname || 'localhost';
  const apiBase = `http://${host}:8000`;
  try {
    const res = await fetch(
      `${apiBase}/api/portfolio/dashboard?session=regular`,
      { headers: { Accept: 'application/json' } }
    );
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    const prevSymbols = dbWatchlistSymbols.value.join(',');
    const prevGrid = gridTickers.value.join(',');
    dashboardData.value = data;
    const nextSymbols = dbWatchlistSymbols.value.join(',');

    // 그리드를 항상 DB 관심종목과 일치시킨다(관심종목에서 지운 종목은 그리드에서도 사라짐)
    reconcileGridWithWatchlist();
    gridInitialized.value = true;

    // 관심종목 또는 그리드 구성이 바뀌면 WS 재구독
    if (prevSymbols !== nextSymbols || prevGrid !== gridTickers.value.join(',')) {
      subscribeToWebSocket();
    }

    dashboardError.value = null;
  } catch (e) {
    console.error('[fetchDashboard]', e);
    dashboardError.value = e.message;
  } finally {
    dashboardLoading.value = false;
  }
}

function startDashboardPoll() {
  stopDashboardPoll();
  dashboardPollTimer.value = setInterval(() => {
    fetchDashboard();
  }, 10000);
}

function stopDashboardPoll() {
  if (dashboardPollTimer.value) {
    clearInterval(dashboardPollTimer.value);
    dashboardPollTimer.value = null;
  }
}

// ── UnifiedWatchlist 이벤트 핸들러 ───────────────────────────

// @select(ticker): 관심종목 클릭 → 활성 그리드 슬롯에 배치 (시계방향 순환)
function handleUnifiedSelect(ticker) {
  // 선택 종목이 현재 그리드 시장과 다르면, 그리드를 그 시장으로 스왑한 뒤 첫 칸에 배치
  const wl = (dashboardData.value && Array.isArray(dashboardData.value.watchlist)) ? dashboardData.value.watchlist : [];
  const found = wl.find(w => w.symbol === ticker);
  if (found && found.market && found.market !== gridMarket.value) {
    gridMarket.value = found.market;
    gridTickers.value = Array(GRID_SIZE).fill('');
    // 시장 전환 시에도 캐시로 즉시 채움
    gridStockData.value = gridTickers.value.map((t) => (t && stockDataCache.value[t]) ? stockDataCache.value[t] : null);
    activeGridIndex.value = 0;
  }
  const targetIndex = activeGridIndex.value;
  // 선택 종목은 캐시가 있으면 즉시 표시(없으면 로딩)
  gridStockData.value.splice(targetIndex, 1, stockDataCache.value[ticker] || null);
  gridTickers.value.splice(targetIndex, 1, ticker);
  gridTimeframes.value.splice(targetIndex, 1, '3m');
  subscribeToWebSocket();
  // 다음 활성 슬롯: 빈 슬롯이 있으면 그 슬롯 우선(직관적), 없으면 시계방향 순환
  const emptyIdx = gridTickers.value.findIndex(t => t === '');
  if (emptyIdx !== -1) {
    activeGridIndex.value = emptyIdx;
  } else {
    activeGridIndex.value = (targetIndex + 1) % GRID_SIZE;
  }
}

// @changed: 관심종목 추가/삭제/이관 후 → dashboard 재조회 + WS 재구독
function onWatchlistChanged() {
  fetchDashboard().then(() => {
    subscribeToWebSocket();
  });
}

// 사이드바 탭(국내/미국) 클릭 → 하단 차트 그리드도 연동 전환. '전체'는 차트 그대로 둔다.
function onSidebarMarketChange(mode) {
  if (mode === 'kr') setGridMarket('KR');
  else if (mode === 'us') setGridMarket('US');
}

// ── WebSocket ─────────────────────────────────────────────────

function connectWebSocket() {
  const host = window.location.hostname || 'localhost';
  const wsUrl = `ws://${host}:8080`;
  ws.value = new WebSocket(wsUrl);

  ws.value.onopen = () => {
    console.log('Connected to WebSocket Agent');
    error.value = false;
    subscribeToWebSocket();
  };

  ws.value.onmessage = (event) => {
    try {
      const message = JSON.parse(event.data);
      if (message.type === 'update' && message.stocks) {
        handleWebSocketUpdate(message.stocks);
      }
    } catch (e) {
      console.error('Failed to parse WS message', e);
    }
  };

  ws.value.onclose = () => {
    console.log('WebSocket connection closed, retrying in 3 seconds...');
    setTimeout(() => { connectWebSocket(); }, 3000);
  };

  ws.value.onerror = (err) => {
    console.error('WebSocket error:', err);
    error.value = true;
  };
}

function subscribeToWebSocket() {
  if (!ws.value || ws.value.readyState !== WebSocket.OPEN) return;

  const cleanIndexTickers = indexTickers.value.filter(t => typeof t === 'string' && t.trim() !== '');
  const cleanGridTickers = gridTickers.value.filter(t => typeof t === 'string' && t.trim() !== '');
  // DB 관심종목 심볼 (기존 localStorage watchlistTickers 대신)
  const cleanDbWatchlist = dbWatchlistSymbols.value;
  // 보유종목 심볼: WS 구독에 포함해 실시간 가격(livePrices) 수신
  const holdingSymbols = dashboardData.value && Array.isArray(dashboardData.value.holdings)
    ? dashboardData.value.holdings.map(h => h.symbol).filter(s => typeof s === 'string' && s.trim() !== '')
    : [];

  const tickersSet = new Set([
    ...cleanIndexTickers,
    ...cleanGridTickers,
    ...cleanDbWatchlist,
    ...holdingSymbols,
    'USDKRW=X',
  ]);
  const tickers = Array.from(tickersSet);

  const timeframes = {};
  cleanIndexTickers.forEach(t => {
    timeframes[t] = indexTimeframes.value[t] || '3m';
  });
  cleanGridTickers.forEach(t => {
    const origIdx = gridTickers.value.indexOf(t);
    timeframes[t] = origIdx !== -1 ? (gridTimeframes.value[origIdx] || '3m') : '3m';
  });
  cleanDbWatchlist.forEach(t => {
    if (!timeframes[t]) timeframes[t] = '3m';
  });
  // 보유종목은 기본 '3m' 타임프레임으로 구독 (Set 중복 제거로 이미 구독 중이면 영향 없음)
  holdingSymbols.forEach(t => {
    if (!timeframes[t]) timeframes[t] = '3m';
  });
  timeframes['USDKRW=X'] = '1d';

  ws.value.send(JSON.stringify({ type: 'subscribe', tickers, timeframes }));
}

function handleWebSocketUpdate(stocks) {
  if (loading.value) loading.value = false;
  error.value = false;

  // 수신한 모든 종목 데이터를 티커별로 캐시 — 국내/미국 토글 시 캐시에서 즉시 채워
  // 스켈레톤 대기 없이 바로 차트를 보여준다(WS가 곧 최신값으로 갱신).
  Object.keys(stocks).forEach((t) => {
    if (stocks[t]) {
      const s = { ...stocks[t] };
      s.name = getStockDisplayName(t, s.name);
      stockDataCache.value[t] = s;
    }
  });

  // USD/KRW
  if (stocks['USDKRW=X'] && stocks['USDKRW=X'].current_price) {
    usdKrwRate.value = stocks['USDKRW=X'].current_price;
  }

  // 지수
  indexTickers.value.forEach(ticker => {
    if (stocks[ticker]) {
      const stock = { ...stocks[ticker] };
      stock.name = getStockDisplayName(ticker, stock.name);
      indexStockData.value[ticker] = stock;
    }
  });

  // 그리드
  gridTickers.value.forEach((ticker, index) => {
    if (ticker && stocks[ticker]) {
      const stock = { ...stocks[ticker] };
      stock.name = getStockDisplayName(ticker, stock.name);
      gridStockData.value.splice(index, 1, stock);
    }
  });

  // watchlistDetailsMap (DB 관심종목 기반)
  const updatedMap = { ...watchlistDetailsMap.value };
  dbWatchlistSymbols.value.forEach(ticker => {
    if (stocks[ticker]) {
      const stock = stocks[ticker];
      const oldItem = watchlistDetailsMap.value[ticker];
      let flash = '';
      if (oldItem && oldItem.price !== null && stock.current_price !== null) {
        if (stock.current_price > oldItem.price) flash = 'up';
        else if (stock.current_price < oldItem.price) flash = 'down';
      }
      updatedMap[ticker] = {
        ticker,
        name: getStockDisplayName(ticker, stock.name),
        price: stock.current_price,
        changePercent: stock.change_percent,
        flash,
      };
    }
  });
  watchlistDetailsMap.value = updatedMap;

  // 보유종목 실시간 가격 맵(livePrices) 갱신
  // - WS 키는 보통 심볼 그대로(예: "MU", "0167A0.KS") 이지만,
  //   normalizeTicker() 로 정규화해 liveHoldings computed 의 키와 일치시킨다.
  // - WS 메시지에 있는 모든 심볼을 순회: 보유 목록에 해당하는 심볼만 저장한다.
  if (dashboardData.value && Array.isArray(dashboardData.value.holdings) && dashboardData.value.holdings.length > 0) {
    // 보유 심볼의 정규화 키 집합 (빠른 룩업용)
    const holdingKeySet = new Set(
      dashboardData.value.holdings.map(h => normalizeTicker(h.symbol))
    );
    const updatedLivePrices = { ...livePrices.value };
    let changed = false;
    Object.keys(stocks).forEach(wsSymbol => {
      const key = normalizeTicker(wsSymbol);
      if (!holdingKeySet.has(key)) return; // 보유 종목이 아니면 건너뜀
      const price = stocks[wsSymbol]?.current_price;
      if (price !== null && price !== undefined && !isNaN(Number(price))) {
        if (updatedLivePrices[key] !== Number(price)) {
          updatedLivePrices[key] = Number(price);
          changed = true;
        }
      }
    });
    if (changed) livePrices.value = updatedLivePrices;
  }

  // flash 초기화 (단일 타이머로 추적 — 틱마다 중첩 생성 방지 + unmount 시 해제)
  if (_flashResetTimer) clearTimeout(_flashResetTimer);
  _flashResetTimer = setTimeout(() => {
    if (!watchlistDetailsMap.value) return;
    const resetMap = { ...watchlistDetailsMap.value };
    let changed = false;
    dbWatchlistSymbols.value.forEach(ticker => {
      if (resetMap[ticker] && resetMap[ticker].flash) {
        resetMap[ticker] = { ...resetMap[ticker], flash: '' };
        changed = true;
      }
    });
    if (changed) watchlistDetailsMap.value = resetMap;
  }, 800);
}

// ── 그리드 / 타임프레임 ───────────────────────────────────────

function handleTimeframeChange(index, timeframe) {
  gridStockData.value.splice(index, 1, null);
  gridTimeframes.value.splice(index, 1, timeframe);
  subscribeToWebSocket();
}

function handleIndexTimeframeChange(ticker, timeframe) {
  indexStockData.value[ticker] = null;
  indexTimeframes.value[ticker] = timeframe;
  subscribeToWebSocket();
}

// ── 지수 표시 ─────────────────────────────────────────────────

function indexDisplayMode(ticker) {
  if (ticker === 'NQ=F') {
    const d = indexStockData.value['NQ=F'];
    const trading = d && typeof d.is_trading_day === 'boolean' ? d.is_trading_day : true;
    return trading ? 'chart' : 'quote';
  }
  if (ticker === 'KOSPI200') {
    // 정규장 중이면 차트, 그 외(휴장일·장외)면 전일마감 텍스트
    return isKospiRegularSession.value ? 'chart' : 'quote';
  }
  // KOSPI_NIGHT: 야간선물은 항상 숫자값 표시
  return 'quote';
}

function indexQuoteIsUp(ticker) {
  const d = indexStockData.value[ticker];
  return d ? (d.change_amount || 0) >= 0 : true;
}

function indexQuoteColor(ticker) {
  const d = indexStockData.value[ticker];
  if (!d) return 'text-base-content';
  const up = (d.change_amount || 0) >= 0;
  // 국내·미국 구분 없이 상승=빨강(rose-400), 하락=파랑(sky-400)으로 통일
  return up ? 'text-rose-400' : 'text-sky-400';
}

function formatIndexValue(v) {
  if (v === null || v === undefined) return '---';
  return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function indexQuoteLabel(ticker) {
  const d = indexStockData.value[ticker];
  if (!d) return '';
  if (ticker === 'NQ=F') return '전일 마감';
  if (ticker === 'KOSPI200') {
    if (d.is_trading_day === false) return '전일 마감';
    return d.session === '정규장' ? '정규장' : (d.session || '장마감');
  }
  return d.session === '거래중' ? '야간 거래중' : (d.session || '장마감');
}

// ── 보유 평단가 매핑 ──────────────────────────────────────────

/**
 * 티커 문자열을 비교용으로 정규화한다.
 * 한국 종목: ".KS" / ".KQ" 접미사 제거 후 소문자 → 순수 코드만 남김
 * 미국 종목: 대문자 통일
 * 예) "0167A0.KS" → "0167a0", "MU" → "mu"
 */
function normalizeTicker(ticker) {
  if (!ticker) return '';
  return ticker.replace(/\.(KS|KQ)$/i, '').toLowerCase();
}

/**
 * dashboardData.holdings 에서 ticker에 해당하는 보유 평단가를 반환.
 * 보유 종목이 아니거나 평단가가 없으면 null 반환.
 * 한국 종목의 .KS/.KQ 접미사 차이를 정규화해 비교.
 */
function getAveragePriceForTicker(ticker) {
  if (!dashboardData.value || !Array.isArray(dashboardData.value.holdings)) return null;
  if (!ticker) return null;
  const normalized = normalizeTicker(ticker);
  const holding = dashboardData.value.holdings.find(
    h => normalizeTicker(h.symbol) === normalized
  );
  if (!holding) return null;
  const avg = holding.average_price;
  return (avg !== null && avg !== undefined && !isNaN(Number(avg))) ? Number(avg) : null;
}

// ── 개별 종목 ─────────────────────────────────────────────────

function isStockKorean(ticker) {
  return /(\.KS|\.KQ)$/i.test(ticker) || /^\d/.test(ticker);
}

function formatStockValue(ticker, v) {
  if (v === null || v === undefined) return '---';
  if (isStockKorean(ticker)) {
    return Number(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }
  return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// 그리드 카드를 '종가 요약'으로 보여줄지 판단: 휴장일(거래일 아님) 또는 현재 세션이 장마감일 때.
// 장 열려 있을 때(정규장/주간거래/프리/애프터/거래중)만 라이브 차트를 보여준다.
function isGridClosed(idx) {
  const d = gridStockData.value[idx];
  if (!d) return false;
  return d.is_trading_day === false || d.session === '장마감';
}

function quoteColorClass(ticker, changeAmount) {
  const up = (changeAmount || 0) >= 0;
  // 국내·미국 구분 없이 상승=빨강(rose-400), 하락=파랑(sky-400)으로 통일
  return up ? 'text-rose-400' : 'text-sky-400';
}

function getStockDisplayName(ticker, backendName) {
  const knownStocks = {
    'TSLA': '테슬라', 'AAPL': '애플', 'NVDA': '엔비디아', 'MSFT': '마이크로소프트',
    'AMZN': '아마존', 'GOOGL': '구글', 'MU': '마이크론 테크놀로지', 'META': '메타',
    'NFLX': '넷플릭스', 'AMD': '에이엠디', 'INTC': '인텔', 'AVGO': '브로드컴',
    'QCOM': '퀄컴', 'BABA': '알리바바', 'NKE': '나이키', 'SBUX': '스타벅스',
    'DIS': '디즈니', 'TSM': '티에스엠씨', 'COIN': '코인베이스', 'PLTR': '팔란티어',
    'SOXL': '속슬 (반도체 3배)', 'TQQQ': '티큐큐큐 (나스닥 3배)',
    'USDKRW=X': '원/달러 환율', 'NQ=F': '나스닥100 선물',
    'KOSPI200': '코스피 지수', 'KOSPI_NIGHT': '코스피 야간선물',
    '0167A0.KS': 'SOL AI반도체TOP2플러스', '0167A0': 'SOL AI반도체TOP2플러스',
    '0167AO.KS': 'SOL AI반도체TOP2플러스', '0167AO': 'SOL AI반도체TOP2플러스',
    '005930.KS': '삼성전자', '005930': '삼성전자',
    '000660.KS': 'SK하이닉스', '000660': 'SK하이닉스',
    '035420.KS': 'NAVER', '035420': 'NAVER',
    '035720.KS': '카카오', '035720': '카카오',
  };
  const cleanTicker = ticker.toUpperCase();
  if (knownStocks[cleanTicker]) return knownStocks[cleanTicker];
  if (backendName) {
    if (backendName.endsWith(' Inc.')) return backendName.replace(' Inc.', '');
    return backendName;
  }
  return ticker;
}

// ── 휴장 카드 차트 모달 ───────────────────────────────────────

function openGridChartModal(ticker, idx) {
  const stockData = gridStockData.value[idx];
  gridChartModal.ticker = ticker;
  gridChartModal.stockName = (stockData && stockData.name) ? stockData.name : ticker;
  gridChartModal.timeframe = gridTimeframes.value[idx] || '3m';
  gridChartModal.candles = [];
  gridChartModal.currentPrice = stockData ? stockData.current_price : null;
  gridChartModal.changeAmount = stockData ? stockData.change_amount : null;
  gridChartModal.changePercent = stockData ? stockData.change_percent : null;
  gridChartModal.session = (stockData && stockData.session) ? stockData.session : '';
  gridChartModal.loading = false;
  gridChartModal.error = false;
  gridChartModal.errorMessage = '';
  gridChartModal.show = true;
  nextTick(() => {
    if (gridChartModalEl.value) gridChartModalEl.value.focus();
  });
  fetchGridChartCandles();
}

function closeGridChartModal() {
  gridChartModal.show = false;
  stopGridChartPoll();
}

async function fetchGridChartCandles() {
  if (!gridChartModal.ticker) return;
  const host = window.location.hostname || 'localhost';
  const apiBase = `http://${host}:8000`;
  gridChartModal.loading = true;
  gridChartModal.error = false;
  try {
    const res = await fetch(
      `${apiBase}/api/stocks/${encodeURIComponent(gridChartModal.ticker)}?timeframe=${encodeURIComponent(gridChartModal.timeframe)}`,
      { headers: { Accept: 'application/json' } }
    );
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    gridChartModal.candles = Array.isArray(data.candles) ? data.candles : [];
    if (data.current_price !== undefined) gridChartModal.currentPrice = data.current_price ?? gridChartModal.currentPrice;
    if (data.change_amount !== undefined) gridChartModal.changeAmount = data.change_amount ?? gridChartModal.changeAmount;
    if (data.change_percent !== undefined) gridChartModal.changePercent = data.change_percent ?? gridChartModal.changePercent;
    if (data.session !== undefined) gridChartModal.session = data.session ?? gridChartModal.session;
    startGridChartPoll();
  } catch (e) {
    gridChartModal.error = true;
    gridChartModal.errorMessage = e.message && e.message.includes('404')
      ? `${gridChartModal.ticker} 데이터를 찾을 수 없습니다`
      : `차트 로드 실패: ${e.message || '알 수 없는 오류'}`;
    console.error('[fetchGridChartCandles]', e);
  } finally {
    gridChartModal.loading = false;
  }
}

function onGridChartTimeframeChange(tf) {
  if (gridChartModal.timeframe === tf) return;
  gridChartModal.timeframe = tf;
  gridChartModal.candles = [];
  stopGridChartPoll();
  fetchGridChartCandles();
}

function startGridChartPoll() {
  stopGridChartPoll();
  gridChartModal.pollTimer = setInterval(() => {
    if (gridChartModal.show && !gridChartModal.loading) {
      fetchGridChartCandles();
    }
  }, 30000);
}

function stopGridChartPoll() {
  if (gridChartModal.pollTimer) {
    clearInterval(gridChartModal.pollTimer);
    gridChartModal.pollTimer = null;
  }
}

// ── 라이프사이클 ──────────────────────────────────────────────

onMounted(() => {
  connectWebSocket();
  fetchDashboard();
  startDashboardPoll();

  // visibilitychange: 백그라운드 이탈 시 폴링 정지, 복귀 시 즉시 재조회 + 재시작
  _onVisibilityChange = () => {
    if (document.hidden) {
      stopDashboardPoll();
    } else {
      fetchDashboard();
      startDashboardPoll();
    }
  };
  document.addEventListener('visibilitychange', _onVisibilityChange);

  _gridChartKeyDown = (e) => {
    if (e.key === 'Escape' && gridChartModal.show) {
      closeGridChartModal();
    }
  };
  document.addEventListener('keydown', _gridChartKeyDown);

  // 반응형 windowWidth 추적
  _onResize = () => {
    windowWidth.value = window.innerWidth;
  };
  window.addEventListener('resize', _onResize);
});

onBeforeUnmount(() => {
  if (ws.value) ws.value.close();
  stopDashboardPoll();
  stopGridChartPoll();
  document.removeEventListener('visibilitychange', _onVisibilityChange);
  document.removeEventListener('keydown', _gridChartKeyDown);
  window.removeEventListener('resize', _onResize);
  if (_flashResetTimer) clearTimeout(_flashResetTimer);
});
</script>

<style scoped>
/* 휴장 카드 차트 모달 페이드 */
.grid-modal-fade-enter-active,
.grid-modal-fade-leave-active {
  transition: opacity 0.2s ease;
}
.grid-modal-fade-enter-from,
.grid-modal-fade-leave-to {
  opacity: 0;
}
</style>
