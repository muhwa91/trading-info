<template>
  <div class="h-screen bg-base-300 text-base-content font-sans flex flex-col selection:bg-accent/30 selection:text-white overflow-hidden">

    <!-- ── 상단 고정 영역 (헤더 + 손익 요약 바) ── -->
    <div class="sticky top-0 z-100 shrink-0">
      <!-- Header (유일한 글래스) -->
      <header class="navbar bg-base-100/70 backdrop-blur-lg border-b border-hairline px-4 sm:px-6 min-h-0 py-2 shrink-0 select-none">
        <!-- 로고 -->
        <div class="flex items-center gap-3 min-w-0 shrink-0">
          <!-- 로고 아이콘 (클릭 → 메인으로 이동) -->
          <div
            class="w-9 h-9 bg-accent hover:opacity-90 transition-opacity duration-120 rounded-sm flex items-center justify-center text-white cursor-pointer shrink-0"
            role="button"
            tabindex="0"
            aria-label="메인으로 이동"
            @click="goHome"
            @keydown.enter="goHome"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
          </div>
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
          <!-- WebSocket 상태 필 (LIVE=시스템 연결 단일 의미) -->
          <div
            :class="[
              'flex items-center gap-2 px-3 py-1 rounded-full border text-xs font-medium font-mono transition-colors duration-120',
              error
                ? 'bg-error/8 border-error/25 text-error'
                : 'bg-base-200/80 border-hairline text-success'
            ]"
            :title="error ? 'WebSocket 연결 끊김' : 'WebSocket 실시간 연결 중'"
            role="status"
            :aria-label="error ? '연결 끊김' : '실시간 연결 중'"
          >
            <!-- 연결점: 단일 pulse (ping 중첩 제거) -->
            <span :class="['inline-flex rounded-full h-1.5 w-1.5 shrink-0 animate-pulse', error ? 'bg-error' : 'bg-success']"></span>
            <span>
              {{ error ? 'DISCONNECTED' : 'LIVE' }}
            </span>
          </div>
        </div>
      </header>
    </div>

    <!-- ── 스크롤 영역 (사이드바 + 메인) ── -->
    <div :class="['relative flex-1 flex overflow-hidden overflow-x-hidden min-h-0', sidebarPosition === 'right' ? 'flex-col-reverse md:flex-row-reverse' : 'flex-col md:flex-row']">

      <!-- 사이드바 접힘 상태 플로팅 열기 버튼 -->
      <button
        v-if="isSidebarCollapsed"
        @click="isSidebarCollapsed = false"
        aria-label="관심종목 사이드바 열기"
        :class="[
          'absolute top-1/2 -translate-y-1/2 z-30',
          'flex flex-col items-center justify-center gap-1',
          'w-6 py-8',
          'bg-accent/90 text-white border border-accent-line',
          'hover:bg-accent hover:w-7 transition-colors duration-120 cursor-pointer',
          sidebarPosition === 'left'
            ? 'left-0 rounded-r-md'
            : 'right-0 rounded-l-md'
        ]"
      >
        <!-- left 사이드바: 오른쪽으로 펼치는 방향 (chevrons-right) -->
        <svg v-if="sidebarPosition === 'left'" class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
        </svg>
        <!-- right 사이드바: 왼쪽으로 펼치는 방향 (chevrons-left) -->
        <svg v-else class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7M19 19l-7-7 7-7" />
        </svg>
      </button>

      <!-- UnifiedWatchlist 사이드바 -->
      <aside
        :class="[
          'relative shrink-0 flex flex-col bg-base-100',
          'transition-all duration-300',
          isSidebarCollapsed
            ? 'w-full md:w-0 h-0 md:h-full border-0'
            : [
                'w-full md:w-72 lg:w-96 h-64 md:h-full',
                sidebarPosition === 'left'
                  ? 'border-b md:border-b-0 md:border-r border-hairline'
                  : 'border-t md:border-t-0 md:border-l border-hairline'
              ]
        ]"
      >
        <div :class="['w-full h-full flex flex-col', isSidebarCollapsed ? 'overflow-hidden' : '']">
          <UnifiedWatchlist
            :items="dashboardData ? dashboardData.watchlist : []"
            :prices-map="watchlistDetailsMap"
            :selected-ticker="lastSelectedTicker"
            :market-sync="gridMarket"
            :sidebar-position="sidebarPosition"
            @select="handleUnifiedSelect"
            @changed="onWatchlistChanged"
            @market-change="onSidebarMarketChange"
            @collapse="isSidebarCollapsed = true"
            @toggle-position="sidebarPosition = sidebarPosition === 'left' ? 'right' : 'left'"
          />
        </div>
      </aside>

      <!-- 메인 패널 -->
      <!-- scrollbar-gutter:stable — md(768px) 경계에서 세로 스크롤바 유무에 따라 콘텐츠 폭이
           통째로 시프트(우측 여백 급변)하던 것을 막는다. 스크롤바 자리를 항상 예약해 767/768
           여백을 동일하게 유지. 스크롤바 스타일은 전역 ::-webkit-scrollbar(4px)를 그대로 사용.
           패딩·세로간격은 상수 p-5/space-y-5 로 고정 — 예전 p-3 md:p-5(12↔20)·space-y-4 md:space-y-5
           (16↔20)가 767/768 경계에서 콘텐츠(차트)를 8px 밀고 가용폭을 16px 급변시키던 점프 제거.
           데스크톱 앱(주 사용 폭이 md 이상)이므로 넓은 폭 값(20)을 모든 폭 상수로 채택 → 넓은 폭 감성 불변. -->
      <main ref="mainScroll" class="flex-1 flex flex-col min-h-0 min-w-0 p-5 overflow-y-auto overflow-x-hidden scrollbar-gutter-stable space-y-5 bg-base-300">

        <!-- WS 로딩 스켈레톤 -->
        <div v-if="loading" class="flex flex-col space-y-5 animate-pulse">
          <!-- 보유표 스켈레톤 -->
          <div class="card bg-base-100 border border-hairline rounded-md overflow-hidden p-4">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2">
                <div class="skeleton h-4 w-4 rounded-xs"></div>
                <div class="skeleton h-4 w-20 rounded-xs"></div>
                <div class="skeleton h-4 w-6 rounded-full"></div>
              </div>
              <div class="skeleton h-6 w-12 rounded-sm"></div>
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
            <div v-for="n in 2" :key="n" class="skeleton h-48 rounded-md"></div>
          </div>

          <!-- 종목 그리드 스켈레톤 (최대 6개) -->
          <div class="grid gap-4 grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            <div v-for="n in 6" :key="n" class="skeleton h-72 sm:h-80 rounded-md"></div>
          </div>
        </div>

        <div v-else class="flex flex-col space-y-5">

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
              class="flex items-center gap-2 px-3 py-2 rounded-sm bg-base-100 border border-hairline hover:border-hairline-strong transition-colors duration-120 cursor-pointer select-none"
              :aria-expanded="!indexCollapsed"
              aria-label="지수 영역 접기/펼치기"
            >
              <svg :class="['h-3.5 w-3.5 text-base-content/60 transition-transform duration-200', indexCollapsed ? '-rotate-90' : '']" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
              <span class="text-xs font-semibold text-base-content/60 tracking-wider uppercase">지수</span>

              <!-- 지수 헤더 인라인 시세. 나스닥100/코스피 종합지수는 접힘일 때만(펼치면 카드 존재),
                   코스피 야간선물은 차트 카드가 없으므로 야간 세션이면 접힘/펼침 무관 항상 노출(headerInlineQuotes). -->
              <span
                v-if="headerInlineQuotes.length"
                class="ml-auto flex items-center gap-3 min-w-0 overflow-hidden"
              >
                <span
                  v-for="q in headerInlineQuotes"
                  :key="q.ticker"
                  class="flex items-center gap-2 font-mono leading-none whitespace-nowrap transition-colors duration-260 rounded-xs px-1"
                  :class="indexFlash[q.ticker] === 'up'
                    ? 'bg-up-weak text-up'
                    : indexFlash[q.ticker] === 'down'
                      ? 'bg-down-weak text-down'
                      : indexQuoteColor(q.ticker)"
                >
                  <span class="text-2xs font-medium text-base-content/40 tracking-wider shrink-0">{{ q.label }}</span>
                  <span class="text-sm font-semibold">{{ formatIndexValue(indexStockData[q.ticker].current_price) }}</span>
                  <span class="text-2xs font-medium">
                    {{ indexQuoteIsUp(q.ticker) ? '▲' : '▼' }}
                    {{ (indexQuoteIsUp(q.ticker) ? '+' : '') + formatIndexValue(indexStockData[q.ticker].change_amount) }}
                    ({{ (indexQuoteIsUp(q.ticker) ? '+' : '') + Number(indexStockData[q.ticker].change_percent).toFixed(2) }}%)
                  </span>
                </span>
              </span>
            </button>
            <!-- visibleIndexTickers: 나스닥 + (정규장=종합지수 / 야간=야간선물 / 그 외 없음) -->
            <div v-show="!indexCollapsed" :class="['grid gap-4 shrink-0 items-stretch transition-all duration-300', visibleIndexTickers.length === 1 ? 'grid-cols-1' : 'grid-cols-1 lg:grid-cols-2']">
            <div
              v-for="ticker in visibleIndexTickers"
              :key="ticker"
              :class="[
                'h-72 sm:h-80 lg:h-90 rounded-md transition-colors duration-120',
                indexDisplayMode(ticker) === 'chart'
                  ? ''
                  : 'overflow-hidden bg-base-100 border border-hairline hover:border-hairline-strong card-hover'
              ]"
            >
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
                <!-- quote 모드 (코스피 야간선물 / NQ 휴장 / 코스피 장마감 등) -->
                <div v-else class="h-full flex flex-col items-center justify-center gap-0 select-none px-6 py-5">
                  <!-- 상단: 종목명 (지수는 티커 배지 없이 이름만) -->
                  <div class="flex items-center justify-center mb-4">
                    <span class="text-xs font-medium text-base-content/55 tracking-widest">{{ indexStockData[ticker].name }}</span>
                  </div>
                  <!-- 중앙: 큰 가격 숫자 (서명 리드아웃) -->
                  <span
                    class="text-readout font-bold font-mono tracking-tight leading-none mb-3"
                    :class="indexQuoteColor(ticker)"
                  >
                    {{ formatIndexValue(indexStockData[ticker].current_price) }}
                  </span>
                  <!-- 등락액 + 퍼센트 -->
                  <div class="flex items-center gap-2 text-sm font-semibold font-mono mb-4" :class="indexQuoteColor(ticker)">
                    <span class="text-base leading-none">{{ indexQuoteIsUp(ticker) ? '▲' : '▼' }}</span>
                    <span>{{ (indexQuoteIsUp(ticker) ? '+' : '') + formatIndexValue(indexStockData[ticker].change_amount) }}</span>
                    <span class="opacity-70 text-xs">({{ (indexQuoteIsUp(ticker) ? '+' : '') + Number(indexStockData[ticker].change_percent).toFixed(2) }}%)</span>
                  </div>
                  <!-- 하단: 세션 상태 배지 (3계층) -->
                  <span
                    :class="[SESSION_BADGE_BASE, sessionBadgeTone(indexQuoteLabel(ticker))]"
                  >{{ indexQuoteLabel(ticker) }}</span>
                </div>
              </template>
              <!-- 로딩 -->
              <div v-else class="h-full flex flex-col items-center justify-center gap-3">
                <span class="loading loading-spinner text-accent/60 loading-sm"></span>
                <span class="text-2xs text-base-content/35 font-mono tracking-widest uppercase">Loading Index...</span>
              </div>
            </div>
          </div>
          </div>

          <!-- ③ 종목 차트 그리드 (2×2) — 국내/미국 스왑 -->
          <!-- @container: 아래 그리드의 @min-[1056px]:grid-cols-2 컨테이너 쿼리 기준(뷰포트 아님 — 좌측 레일 감안한 실제 가용 폭) -->
          <div class="@container flex flex-col gap-3">
            <!-- 국내/미국 토글 -->
            <div class="flex items-center gap-2">
              <div class="tabs tabs-boxed bg-base-200 p-0.5 rounded-sm border border-hairline gap-0">
                <button
                  v-for="m in [{ v: 'KR', l: '국내' }, { v: 'US', l: '미국' }]"
                  :key="m.v"
                  type="button"
                  @click="setGridMarket(m.v)"
                  :class="[
                    'tab rounded-sm text-xs font-semibold transition-colors duration-120 cursor-pointer px-4 py-1',
                    gridMarket === m.v
                      ? 'tab-active bg-surface-raised border border-accent-line text-base-content'
                      : 'text-base-content/45 hover:text-base-content/70 border border-transparent'
                  ]"
                  :aria-pressed="gridMarket === m.v"
                >{{ m.l }}</button>
              </div>

              <!-- 현재 시장 세션 배지 (토글 옆 — 3계층: 정규장=open / 연장=ext / 마감=muted) -->
              <span
                v-if="gridSessionLabel"
                :class="[SESSION_BADGE_BASE, 'shrink-0', sessionBadgeTone(gridSessionLabel)]"
              >{{ gridSessionLabel }}</span>

              <!-- 차트 열 수 토글(1차트=세로 1열/크게 · 4차트=2×2). 반응형 자동 배치 대신 명시 선택 -->
              <div class="tabs tabs-boxed bg-base-200 p-0.5 rounded-sm border border-hairline gap-0 ml-auto">
                <button
                  v-for="c in [{ v: 1, label: '1차트씩 크게 보기' }, { v: 2, label: '4차트씩 보기' }]"
                  :key="c.v"
                  type="button"
                  @click="gridCols = c.v"
                  :class="[
                    'tab rounded-sm transition-colors duration-120 cursor-pointer px-3 py-1 flex items-center justify-center',
                    gridCols === c.v
                      ? 'tab-active bg-surface-raised border border-accent-line text-base-content'
                      : 'text-base-content/45 hover:text-base-content/70 border border-transparent'
                  ]"
                  :aria-pressed="gridCols === c.v"
                  :aria-label="c.label"
                  :title="c.label"
                >
                  <!-- 1차트: 단일 큰 사각형 -->
                  <svg v-if="c.v === 1" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="4" width="16" height="16" rx="2" />
                  </svg>
                  <!-- 4차트: 2×2 그리드 -->
                  <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="4" width="7" height="7" rx="1" />
                    <rect x="13" y="4" width="7" height="7" rx="1" />
                    <rect x="4" y="13" width="7" height="7" rx="1" />
                    <rect x="13" y="13" width="7" height="7" rx="1" />
                  </svg>
                </button>
              </div>
            </div>
            <div
              ref="gridAnimateRef"
              @mouseup="gridDragHandleIdx = null"
              @mouseleave="gridDragHandleIdx = null"
              :class="[
                'grid gap-4 shrink-0 items-start transition-all duration-300 overflow-x-auto custom-scrollbar',
                gridColsClass
              ]"
            >
            <!-- 채워진 슬롯만 렌더 (빈 슬롯은 DOM에 넣지 않아 CSS grid가 자동 컴팩트) -->
            <template
              v-for="(ticker, idx) in gridTickers"
              :key="idx"
            >
            <div
              v-if="ticker"
              :draggable="gridDragHandleIdx === idx"
              @click="activeGridIndex = idx; lastSelectedTicker = ticker"
              @dragstart="onGridDragStart(idx, $event)"
              @dragend="onGridDragEnd"
              @dragover.prevent="onGridDragOver(idx)"
              @dragleave="onGridDragLeave(idx)"
              @drop="onGridDrop(idx)"
              :class="[
                'group relative card bg-base-100 border transition-colors duration-120 overflow-hidden rounded-md min-w-130',
                isGridClosed(idx)
                  ? 'h-60'
                  : (gridCols === 1 ? 'h-80 sm:h-96 lg:h-120' : 'h-72 sm:h-80 lg:h-110'),
                gridDragOverIdx === idx
                  ? 'border-accent ring-1 ring-accent'
                  : (activeGridIndex === idx
                    ? 'border-accent-line'
                    : 'border-hairline hover:border-hairline-strong'),
                gridDraggingIdx === idx ? 'opacity-50' : ''
              ]"
            >
              <!-- 활성 카드 상단 강조 바 (2px bg-accent) -->
              <div
                v-if="activeGridIndex === idx"
                class="absolute top-0 left-4 right-4 h-0.5 rounded-b-full bg-accent"
              ></div>

              <template v-if="ticker && gridStockData[idx]">
                <!-- 휴장/장마감: 종가 숫자 표시 + 차트 보기 버튼 -->
                <div v-if="isGridClosed(idx)" class="h-full flex flex-col items-center justify-center gap-2 select-none px-4 text-center">
                  <!-- 차트가 없는 장마감/휴장 카드: 종목명~라벨 블록을 드래그 핸들로(차트 카드 헤더와 일관) -->
                  <div
                    class="flex flex-col items-center gap-2 cursor-grab active:cursor-grabbing"
                    @mousedown="gridDragHandleIdx = idx"
                    @mouseup="gridDragHandleIdx = null"
                  >
                    <span class="text-xs font-medium text-base-content/45 tracking-widest uppercase">{{ gridStockData[idx].name }}</span>
                    <span class="text-readout font-bold font-mono tracking-tight leading-none" :class="quoteColorClass(ticker, gridStockData[idx].change_amount)">
                      {{ formatStockValue(ticker, gridStockData[idx].current_price) }}
                    </span>
                    <div class="flex items-center gap-2 text-sm font-semibold font-mono" :class="quoteColorClass(ticker, gridStockData[idx].change_amount)">
                      <span>{{ (gridStockData[idx].change_amount || 0) >= 0 ? '▲' : '▼' }}</span>
                      <span>{{ ((gridStockData[idx].change_amount || 0) >= 0 ? '+' : '') + formatStockValue(ticker, gridStockData[idx].change_amount) }}</span>
                      <span class="opacity-75">({{ ((gridStockData[idx].change_amount || 0) >= 0 ? '+' : '') + Number(gridStockData[idx].change_percent).toFixed(2) }}%)</span>
                    </div>
                    <span class="text-2xs font-medium text-base-content/35 uppercase tracking-widest mt-1 px-2 py-0.5 rounded-full bg-base-200/50 border border-hairline">{{ gridStockData[idx].is_trading_day === false ? '휴장 · 전일 마감' : '장마감 · 종가' }}</span>
                  </div>
                  <button
                    @click.stop="openGridChartModal(ticker, idx)"
                    class="mt-1 flex items-center gap-1 px-3 py-1 rounded-full text-2xs font-medium border border-accent-line text-accent bg-accent-weak hover:border-accent transition-colors duration-120 cursor-pointer"
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
                  :regular-change-percent="gridStockData[idx].regular_change_percent ?? null"
                  :us-session="gridStockData[idx].us_session || ''"
                  :candles="gridStockData[idx].candles"
                  :session="gridStockData[idx].session || ''"
                  :usd-krw-rate="chartUsdKrwRate"
                  :average-price="getAveragePriceForTicker(ticker)"
                  @timeframe-change="handleTimeframeChange(idx, $event)"
                  @header-grab="gridDragHandleIdx = idx"
                  @header-release="gridDragHandleIdx = null"
                />
              </template>

              <!-- 종목 데이터 로딩 중 -->
              <div v-else class="h-full flex flex-col items-center justify-center gap-3">
                <span class="loading loading-spinner text-accent/60 loading-sm"></span>
                <span class="text-2xs text-base-content/35 font-mono tracking-widest uppercase">{{ ticker }} 데이터 수신 중...</span>
              </div>
            </div>
            </template>

            <!-- 전체 빈 상태 안내 (차트가 하나도 없을 때만) -->
            <div
              v-if="activeGridTickersCount === 0"
              class="col-span-full flex flex-col items-center justify-center gap-3 text-center p-10 select-none h-60 card bg-base-100 border border-dashed border-hairline-strong rounded-md"
            >
              <div class="w-12 h-12 rounded-md border-2 border-dashed border-hairline-strong flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-base-content/25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
              </div>
              <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-base-content/40">표시할 차트가 없습니다</span>
                <span class="text-2xs text-base-content/25 leading-relaxed">왼쪽 관심 종목에서<br>종목을 클릭해 배치하세요</span>
              </div>
            </div>
          </div>
          </div>

        </div>
      </main>
    </div>

    <!-- ── 휴장 카드 차트 모달 ── -->
    <Teleport to="body">
    <Transition name="grid-modal-fade">
      <div
        v-if="gridChartModal.show"
        class="fixed inset-0 z-1000 flex flex-col"
        role="dialog"
        aria-modal="true"
        :aria-label="gridChartModal.stockName ? `${gridChartModal.stockName} 차트` : '종목 차트'"
        @keydown.esc="closeGridChartModal"
        tabindex="-1"
        ref="gridChartModalEl"
      >
        <div class="absolute inset-0 bg-black/70" @click="closeGridChartModal"></div>

        <div class="relative z-10 m-auto w-full max-w-5xl h-[90vh] sm:h-[80vh] min-h-0 sm:min-h-120 mx-2 sm:mx-auto bg-base-100 border border-hairline-strong rounded-lg shadow-modal flex flex-col overflow-hidden">

          <div class="flex items-center justify-between px-4 h-12 border-b border-hairline shrink-0">
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded-xs text-2xs font-medium font-mono text-accent bg-accent-weak border border-accent-line tracking-wider">
                {{ gridChartModal.ticker }}
              </span>
              <span class="text-sm font-semibold text-white">{{ gridChartModal.stockName }}</span>
              <span class="px-1.5 py-0.5 rounded-xs text-2xs font-medium font-mono border leading-tight text-base-content/55 bg-base-200/60 border-hairline">
                휴장 · 프리/애프터 봉
              </span>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="gridChartModal.loading" class="loading loading-spinner loading-xs text-accent"></span>
              <span v-if="gridChartModal.error" class="text-2xs text-error font-medium font-mono">데이터 오류</span>
              <button
                @click="closeGridChartModal"
                class="w-7 h-7 flex items-center justify-center rounded-sm text-base-content/40 hover:text-base-content/80 hover:bg-base-200/60 transition-colors cursor-pointer"
                aria-label="차트 닫기"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <div class="flex-1 min-h-0 p-4">
            <div v-if="gridChartModal.loading" class="h-full flex items-center justify-center gap-3">
              <span class="loading loading-ring loading-md text-accent"></span>
              <span class="text-xs font-medium text-base-content/50 font-mono">차트 데이터 불러오는 중...</span>
            </div>
            <div v-else-if="gridChartModal.error" class="h-full flex flex-col items-center justify-center gap-3">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-error/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="text-xs font-medium text-error/70 font-mono">{{ gridChartModal.errorMessage }}</p>
              <button @click="fetchGridChartCandles" class="btn btn-xs btn-outline btn-error font-medium rounded-sm cursor-pointer">재시도</button>
            </div>
            <StockChart
              v-else-if="gridChartModal.candles.length > 0"
              :key="`grid_chart_${gridChartModal.ticker}_${gridChartModal.timeframe}`"
              :ticker="gridChartModal.ticker"
              :name="gridChartModal.stockName"
              :current-price="gridChartModal.currentPrice"
              :change-amount="gridChartModal.changeAmount"
              :change-percent="gridChartModal.changePercent"
              :regular-change-percent="gridChartModal.regularChangePercent"
              :us-session="gridChartModal.usSession"
              :candles="gridChartModal.candles"
              :session="gridChartModal.session"
              :timeframe="gridChartModal.timeframe"
              :usd-krw-rate="chartUsdKrwRate"
              :average-price="getAveragePriceForTicker(gridChartModal.ticker)"
              @timeframe-change="onGridChartTimeframeChange"
            />
            <div v-else-if="!gridChartModal.loading" class="h-full flex flex-col items-center justify-center gap-3">
              <p class="text-xs font-medium text-base-content/40 font-mono">차트 데이터가 없습니다 (장외 시간 또는 휴장일)</p>
            </div>
          </div>
        </div>
      </div>
    </Transition>
    </Teleport>

    <!-- ── 전역 커스텀 confirm 모달 (단 한 번 마운트) ── -->
    <ConfirmDialog />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick, provide } from 'vue';
import { isNqTradingByEtClock } from './utils/nqSession.js';
import { gridColsClass as computeGridColsClass } from './utils/gridCols.js';
import { placeTicker } from './utils/gridPlacement.js';
import { SESSION_BADGE_BASE, sessionBadgeTone } from './utils/sessionBadge.js';
import { useAutoAnimate } from '@formkit/auto-animate/vue';
import StockChart from './components/StockChart.vue';
import PortfolioSummaryBar from './components/PortfolioSummaryBar.vue';
import HoldingsPanel from './components/HoldingsPanel.vue';
import UnifiedWatchlist from './components/UnifiedWatchlist.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';

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
const lastSelectedTicker = ref(''); // 사이드바 하이라이트 전용 — 활성 슬롯(activeGridIndex)과 분리

const gridDraggingIdx = ref(null); // 드래그 중인 그리드 슬롯
const gridDragOverIdx = ref(null); // 드롭 대상으로 올라온 그리드 슬롯
const gridDragHandleIdx = ref(null); // 드래그 핸들(차트 헤더)을 잡은 슬롯 — 이 슬롯만 draggable 활성
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
const gridCols = ref(localStorage.getItem('gridCols') === '2' ? 2 : 1); // 하단 그리드 열 수(1=세로 1열/차트 크게, 2=2×2). 기본 1.
// 창 리사이즈 중에는 모든 transition 을 끈다 — md(768px) 브레이크포인트 경계에서
// 사이드바/그리드의 width·height 클래스(w-full↔md:w-72, h-64↔md:h-full 등)가 바뀔 때
// transition-all 이 그 점프를 300ms 애니메이션해 생기는 흔들림/깜빡임(특히 767~768px 대역
// 미세조정 시 매 픽셀 재flip)을 막는다. 리사이즈가 멎으면(150ms) 다시 켜 collapse 애니는 유지.
// documentElement 클래스는 '동기' 적용이라 Vue 렌더 지연 없이 브라우저가 transition 을
// 시작하기 전에 확실히 반영되고, 사이드바뿐 아니라 그리드(md:grid-cols-2 등)까지 함께 덮는다.
let _resizeSettleTimer = null;
let _onWindowResize = null;
// auto-animate 리사이즈 게이팅 — 창 리사이즈 동안 FLIP(Web Animations API) 재생을 끈다.
// auto-animate 는 리스트 추가/삭제/재정렬용인데, 전역 ResizeObserver 가 매 리사이즈 틱마다
// 등록 부모의 자식을 FLIP 재생해 md(768px) 경계에서 레이아웃이 흔들린다. WAAPI 는 CSS
// transition:none(win-resizing)으로 못 막으므로 setEnabled 로 직접 끈다. 자식(관심종목·보유표)은
// 이 ref 를 inject 해 각자 setEnabled 를 토글한다(중복 리스너 없이 App 단일 리사이즈 핸들러로 제어).
const animateEnabled = ref(true);
provide('animateEnabled', animateEnabled);
const [gridAnimateRef, setGridAnimate] = useAutoAnimate({ duration: 200, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' });
watch(animateEnabled, (v) => setGridAnimate(v));
let _animateReenableTimer = null;
// ── 포트폴리오 대시보드 ────────────────────────────────────────
const dashboardData = ref(null);       // { summary, holdings, watchlist, exchange_rate }
// 차트 원화 환산 환율: 헤더·보유종목표와 동일한 토스(대시보드) 환율로 통일.
// 대시보드 미로드 초기에만 Yahoo usdKrwRate 로 폴백(빈 화면 방지).
const chartUsdKrwRate = computed(() =>
  dashboardData.value?.exchange_rate?.USD_KRW ?? usdKrwRate.value
);
const dashboardLoading = ref(false);
const dashboardPollTimer = ref(null);

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
  regularChangePercent: null,
  usSession: '',
  session: '',
  loading: false,
  error: false,
  errorMessage: '',
  pollTimer: null,
});

// ── 이벤트 핸들러 레퍼런스 (beforeUnmount 해제용) ─────────────
let _onVisibilityChange = null;
let _gridChartKeyDown = null;
let _flashResetTimer = null;

// ── computed ───────────────────────────────────────────────────

const activeGridTickersCount = computed(() => {
  return gridTickers.value.filter(t => t !== '').length;
});

// 열 수 → 그리드 클래스. 순수 로직은 테스트 가능하도록 utils/gridCols.js 로 추출(선례: nqSession.js).
const gridColsClass = computed(() => computeGridColsClass(gridCols.value));

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
// - NQ=F: 항상 (차트 카드)
// - 코스피 종합지수: 정규장(장 열렸을 때)에만 (차트 카드)
// - 코스피 야간선물: 차트 카드 없음 → 야간 세션에 한해 지수 헤더 인라인 시세로만 노출(headerInlineQuotes)
// - 정규장도 아니면(장외·휴장) 코스피 카드 없음 → 나스닥만
const visibleIndexTickers = computed(() => {
  const tickers = ['NQ=F'];
  if (isKospiRegularSession.value) {
    tickers.push('KOSPI200');
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

// 나스닥100 선물(NQ=F) CME Globex 거래시간 여부 — 지수 헤더 인라인 NQ 시세 노출 게이트.
// isKospiNightSession 과 같은 패턴 — NQ=F session 필드 있으면 신뢰, 없으면 ET 시계로 폴백.
// 휴장창 경계·ET 기준 근거는 utils/nqSession.js 참조(테스트 가능하도록 추출).
const isNqFuturesTrading = computed(() => {
  const nq = indexStockData.value['NQ=F'];
  if (!nq || nq.current_price === null || nq.current_price === undefined) return false;
  if (nq.session) return nq.session === '거래중';
  return isNqTradingByEtClock(); // 데이터/필드 없으면 ET 시계로 폴백
});

// 접힘 헤더 인라인 틱 플래시 — 지수별 독립('up'/'down' 260ms 후 해제). StockChart priceFlash 패턴 동일.
// prefers-reduced-motion 은 전역 CSS(style.css)가 transition-duration 을 무효화해 자연 존중.
const indexFlash = ref({});
const indexFlashTimers = {};
function triggerIndexFlash(ticker, oldP, newP) {
  if (oldP == null || newP == null || newP === oldP) return;
  indexFlash.value = { ...indexFlash.value, [ticker]: newP > oldP ? 'up' : 'down' };
  clearTimeout(indexFlashTimers[ticker]);
  indexFlashTimers[ticker] = setTimeout(() => {
    indexFlash.value = { ...indexFlash.value, [ticker]: '' };
  }, 260); // 설계서 §6: 가격 틱 플래시 260ms 통일
}

// 지수 헤더 우측 인라인 시세 목록 — 스토어 데이터 재사용(새 호출 없음).
// - 나스닥100·코스피 종합지수: 접힘일 때만(펼치면 차트 카드가 같은 값을 보여줌).
// - 코스피 야간선물: 차트 카드가 없으므로 야간 세션이면 접힘/펼침 무관 항상 헤더에 노출. 낮(장마감)엔 미노출.
const headerInlineQuotes = computed(() => {
  const out = [];
  if (indexCollapsed.value && isNqFuturesTrading.value && indexStockData.value['NQ=F']) {
    out.push({ ticker: 'NQ=F', label: '나스닥100' });
  }
  // 코스피: 정규장이면 종합지수(접힘일 때만), 아니면 야간선물(야간 세션이면 항상). visibleIndexTickers 스왑 규칙과 정합.
  if (indexCollapsed.value && isKospiRegularSession.value && indexStockData.value['KOSPI200']) {
    out.push({ ticker: 'KOSPI200', label: '코스피' });
  } else if (isKospiNightSession.value && indexStockData.value['KOSPI_NIGHT']) {
    out.push({ ticker: 'KOSPI_NIGHT', label: '코스피 야간선물' });
  }
  return out;
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

watch(gridCols, (val) => {
  localStorage.setItem('gridCols', val);
});

// 그리드는 DB 관심종목(dbWatchlistSymbols)이 유일한 소스다.
// (과거 사용하던 localStorage 'watchlist' 키는 더 이상 읽지 않는다 — 관심종목에서
//  지운 종목이 그 캐시 때문에 그리드에 계속 남는 버그가 있었음. 대시보드 로드 시 채운다.)
localStorage.removeItem('watchlist');

// 그리드를 DB 관심종목(현재 선택된 시장)과 일치시킨다: ① 해당 시장 관심종목에 없는
// 그리드 종목 제거, ② 빈 칸을 아직 표시되지 않은 관심종목으로 앞에서부터 채움(최대 4칸).
// 기존에 표시 중인(여전히 유효한) 종목의 위치·순서는 보존한다.
// fill=false 면 ①(제거)만 하고 빈 칸 자동 채움은 건너뛴다 — 10초 폴링이 사용자가 비운
// 슬롯을 매번 다시 채워 그리드/포커스가 무입력으로 움직이던 문제 때문(2026-07-26).
function reconcileGridWithWatchlist(fill = true) {
  const wl = gridMarket.value === 'US' ? watchlistByMarket.value.US : watchlistByMarket.value.KR;
  gridTickers.value.forEach((t, i) => {
    if (t && !wl.includes(t)) gridTickers.value.splice(i, 1, '');
  });
  if (!fill) return;
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
  gridDragHandleIdx.value = null; // 드래그 종료 시 핸들 잠금 복귀(헤더 mouseup이 누락돼도 안전)
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

    // 그리드를 항상 DB 관심종목과 일치시킨다(관심종목에서 지운 종목은 그리드에서도 사라짐).
    // 빈 칸 자동 채움은 관심종목 구성이 실제로 바뀐 폴링에서만(무입력 변화 방지).
    reconcileGridWithWatchlist(prevSymbols !== nextSymbols);

    // 관심종목 또는 그리드 구성이 바뀌면 WS 재구독
    if (prevSymbols !== nextSymbols || prevGrid !== gridTickers.value.join(',')) {
      subscribeToWebSocket();
    }

  } catch (e) {
    console.error('[fetchDashboard]', e);
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
  // 선택 종목이 현재 그리드 시장과 다르면 시장을 스왑한다 — 탭 클릭과 **같은 경로**(setGridMarket)로.
  // 여기서 그리드를 직접 비우면 안 된다: 비우기만 하고 채우지 않으면 빈 슬롯이 영구히 남는다
  // (폴링 채움이 관심종목 심볼 변화에만 걸려 있어 자가치유되지 않고, "국내↔미국" 왕복 외엔
  //  회복 수단도 없다 → 그 상태에서 다음 클릭이 빈 슬롯을 놔두고 기존 차트를 덮는다. 2026-07-26 실측)
  const wl = (dashboardData.value && Array.isArray(dashboardData.value.watchlist)) ? dashboardData.value.watchlist : [];
  const found = wl.find(w => w.symbol === ticker);
  if (found && found.market && found.market !== gridMarket.value) setGridMarket(found.market);
  const { tickers, activeIndex, placedIndex } = placeTicker(gridTickers.value, activeGridIndex.value, ticker);
  lastSelectedTicker.value = ticker;
  activeGridIndex.value = activeIndex;
  if (placedIndex === null) return;   // 이미 그리드에 있음 — 중복 추가 대신 그 슬롯만 활성화
  // 선택 종목은 캐시가 있으면 즉시 표시(없으면 로딩)
  gridStockData.value.splice(placedIndex, 1, stockDataCache.value[ticker] || null);
  gridTimeframes.value.splice(placedIndex, 1, '3m');
  gridTickers.value = tickers;
  subscribeToWebSocket();
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
      // 접힘 헤더 인라인 틱 플래시: 이전가 대비 상승/하락 시 260ms 색 점등 (StockChart priceFlash 패턴 재사용)
      const prev = indexStockData.value[ticker]?.current_price;
      triggerIndexFlash(ticker, prev, stock.current_price);
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
  }, 260);  // 설계서 §6: 가격 틱 플래시 260ms 통일
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
  // 그 외(휴장일·장외 등): 숫자값(quote) 표시
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
  // 국내·미국 구분 없이 상승=빨강(up), 하락=파랑(down)으로 통일
  return up ? 'text-up' : 'text-down';
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
  // 국내·미국 구분 없이 상승=빨강(up), 하락=파랑(down)으로 통일
  return up ? 'text-up' : 'text-down';
}

function getStockDisplayName(ticker, backendName) {
  const knownStocks = {
    'TSLA': '테슬라', 'AAPL': '애플', 'NVDA': '엔비디아', 'MSFT': '마이크로소프트',
    'AMZN': '아마존', 'GOOGL': '구글', 'MU': '마이크론 테크놀로지', 'META': '메타',
    'NFLX': '넷플릭스', 'AMD': '에이엠디', 'INTC': '인텔', 'AVGO': '브로드컴',
    'QCOM': '퀄컴', 'BABA': '알리바바', 'NKE': '나이키', 'SBUX': '스타벅스',
    'DIS': '디즈니', 'TSM': '티에스엠씨', 'COIN': '코인베이스', 'PLTR': '팔란티어',
    'SOXL': '속슬 (반도체 3배)', 'TQQQ': '티큐큐큐 (나스닥 3배)',
    'USDKRW=X': '원/달러 환율', 'NQ=F': '나스닥100',
    'KOSPI200': '코스피', 'KOSPI_NIGHT': '코스피 야간선물',
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
  gridChartModal.regularChangePercent = stockData ? (stockData.regular_change_percent ?? null) : null;
  gridChartModal.usSession = (stockData && stockData.us_session) ? stockData.us_session : '';
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
    if (data.regular_change_percent !== undefined) gridChartModal.regularChangePercent = data.regular_change_percent ?? gridChartModal.regularChangePercent;
    if (data.us_session !== undefined) gridChartModal.usSession = data.us_session ?? gridChartModal.usSession;
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

  // 리사이즈 중 transition off (디바운스 150ms) — 브레이크포인트 경계 튐/깜빡임 방지.
  // documentElement 클래스 토글은 동기라 리사이즈 프레임의 style-recalc 전에 반영된다.
  _onWindowResize = () => {
    document.documentElement.classList.add('win-resizing');
    animateEnabled.value = false; // 리사이즈 시작 → auto-animate FLIP 억제(3곳 동시)
    clearTimeout(_resizeSettleTimer);
    _resizeSettleTimer = setTimeout(() => {
      document.documentElement.classList.remove('win-resizing');
    }, 150);
    // auto-animate 재활성은 마지막 FLIP(200ms)과 겹쳐 튀지 않도록 리사이즈 종료 후 250ms 뒤.
    clearTimeout(_animateReenableTimer);
    _animateReenableTimer = setTimeout(() => {
      animateEnabled.value = true;
    }, 250);
  };
  window.addEventListener('resize', _onWindowResize);
});

onBeforeUnmount(() => {
  if (ws.value) ws.value.close();
  stopDashboardPoll();
  stopGridChartPoll();
  document.removeEventListener('visibilitychange', _onVisibilityChange);
  document.removeEventListener('keydown', _gridChartKeyDown);
  if (_flashResetTimer) clearTimeout(_flashResetTimer);
  if (_onWindowResize) window.removeEventListener('resize', _onWindowResize);
  clearTimeout(_resizeSettleTimer);
  clearTimeout(_animateReenableTimer);
  document.documentElement.classList.remove('win-resizing');
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
