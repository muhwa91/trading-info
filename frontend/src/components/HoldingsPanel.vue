<template>
  <div class="card bg-base-100/45 backdrop-blur-md border border-base-content/8 rounded-2xl overflow-hidden">

    <!-- 토스트 알림 -->
    <Transition name="toast-slide">
      <div
        v-if="toast.show"
        class="fixed top-16 right-4 z-50 flex items-center gap-2.5 px-4 py-3 rounded-xl border shadow-lg text-xs font-bold font-mono transition-all duration-300"
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

    <!-- 섹션 헤더 -->
    <div class="flex items-center justify-between px-4 md:px-5 py-3.5 border-b border-base-content/8">
      <div class="flex items-center gap-2">
        <!-- 접기/펼치기 토글 -->
        <button
          @click="holdingsCollapsed = !holdingsCollapsed"
          class="w-6 h-6 flex items-center justify-center rounded-md text-base-content/50 hover:text-white hover:bg-base-200/60 transition-all duration-200 cursor-pointer shrink-0"
          :aria-expanded="!holdingsCollapsed"
          aria-label="보유 종목 접기/펼치기"
        >
          <svg :class="['h-3.5 w-3.5 transition-transform duration-200', holdingsCollapsed ? '-rotate-90' : '']" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </svg>
        <h2 class="text-sm font-black text-white tracking-tight leading-none">보유 종목</h2>
        <span class="px-2 py-0.5 rounded-full text-xs font-extrabold font-mono text-indigo-400 bg-indigo-500/10 border border-indigo-500/20">
          {{ holdings.length }}
        </span>
      </div>
      <!-- 보유 추가 버튼 -->
      <button
        @click="openHoldingModal()"
        class="btn btn-xs bg-indigo-600/80 hover:bg-indigo-500 border-indigo-500/30 text-white font-bold gap-1 rounded-lg cursor-pointer transition-all duration-200"
        aria-label="보유 종목 추가"
      >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        추가
      </button>
    </div>

    <!-- 빈 상태 -->
    <div v-if="holdings.length === 0" v-show="!holdingsCollapsed" class="flex flex-col items-center justify-center py-14 gap-3 select-none">
      <div class="w-12 h-12 rounded-xl border-2 border-dashed border-base-content/12 flex items-center justify-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
      </div>
      <div class="text-center">
        <p class="text-sm font-bold text-base-content/35">보유 종목을 추가하세요</p>
        <p class="text-xs text-base-content/22 mt-0.5">우측 상단 '추가' 버튼으로 종목을 등록하세요</p>
      </div>
    </div>

    <!-- 보유 종목 테이블 -->
    <div v-else v-show="!holdingsCollapsed" class="overflow-x-auto custom-scrollbar">
      <table class="w-full min-w-240" role="table" aria-label="보유 종목 목록">
        <thead>
          <tr class="text-xs font-extrabold text-base-content/35 tracking-wider uppercase border-b border-base-content/6">
            <!-- 드래그 핸들 열 -->
            <th class="w-8 px-2 py-3"></th>
            <th class="text-left px-4 md:px-5 py-3 font-extrabold">종목</th>
            <!-- 세션 배지 열 헤더 (빈 헤더, 수량 앞) -->
            <th class="px-1 py-3 w-20"></th>
            <th class="text-right px-3 py-3 font-extrabold">수량</th>
            <th class="text-right px-3 py-3 font-extrabold">평단가</th>
            <th class="text-right px-3 py-3 font-extrabold">현재가</th>
            <th class="text-right px-3 py-3 font-extrabold">미실현손익</th>
            <th class="text-right px-3 py-3 font-extrabold">손익률</th>
            <th class="text-right px-3 py-3 font-extrabold">평가금액</th>
            <th class="text-center px-4 md:px-5 py-3 font-extrabold">관리</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in orderedHoldings"
            :key="item.portfolio_id"
            :draggable="true"
            :class="[
              'border-b border-base-content/4 last:border-b-0 hover:bg-base-200/20 transition-colors duration-150 cursor-pointer',
              dragOverId === item.portfolio_id ? 'bg-indigo-500/10 border-indigo-500/30' : ''
            ]"
            role="row"
            :title="`${item.name || item.symbol} 차트 보기`"
            @click.stop="onRowClick(item)"
            @dragstart="onDragStart($event, item.portfolio_id)"
            @dragover.prevent="onDragOver($event, item.portfolio_id)"
            @dragleave="onDragLeave"
            @drop.prevent="onDrop($event, item.portfolio_id)"
            @dragend="onDragEnd"
          >
            <!-- 드래그 핸들 -->
            <td
              class="w-8 px-2 py-3.5 text-center"
              @click.stop
              title="드래그하여 순서 변경"
              aria-label="드래그 핸들"
            >
              <span class="text-base-content/20 hover:text-base-content/50 cursor-grab active:cursor-grabbing select-none text-base leading-none">⠿</span>
            </td>

            <!-- 종목명·심볼·마켓 -->
            <td class="px-4 md:px-5 py-3.5">
              <div class="flex flex-col gap-0.5">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="text-sm font-black text-white leading-tight">{{ displayName(item) }}</span>
                  <span class="px-1.5 py-0.5 rounded text-xs font-bold font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/15 leading-tight">{{ item.symbol }}</span>
                </div>
                <span
                  class="text-xs font-bold font-mono px-1.5 py-0.5 rounded self-start"
                  :class="item.market === 'KR'
                    ? 'text-rose-400/70 bg-rose-500/6'
                    : 'text-emerald-400/70 bg-emerald-500/6'"
                >{{ item.market }}</span>
              </div>
            </td>

            <!-- 세션 배지 셀 -->
            <!-- live_session(백엔드, 공휴일 정확)이 있으면 우선 사용; 없으면 클라이언트 계산값 폴백 -->
            <td class="px-2 py-3.5 text-center" @click.stop>
              <span
                :class="[
                  'inline-flex items-center justify-center px-4 h-8 rounded-lg border text-xs font-extrabold leading-tight whitespace-nowrap',
                  sessionBadgeStyle(itemSessionCode(item))
                ]"
              >
                {{ sessionLabel(itemSessionCode(item)) }}
              </span>
            </td>

            <!-- 수량 -->
            <td class="px-3 py-3.5 text-right">
              <span class="text-sm font-bold font-mono text-white/80">{{ formatQuantity(item.quantity) }}</span>
            </td>

            <!-- 평단가 -->
            <td class="px-3 py-3.5 text-right">
              <span class="text-sm font-bold font-mono text-base-content/60">
                <template v-if="item.market === 'US'">{{ fmtUSAvg(item) }}</template>
                <template v-else>{{ formatPrice(item.currency, item.average_price) }}</template>
              </span>
            </td>

            <!-- 현재가 -->
            <td class="px-3 py-3.5 text-right">
              <span
                v-if="item.price_available && item.current_price !== null"
                class="text-sm font-extrabold font-mono inline-block px-1 rounded transition-colors duration-300"
                :class="[item.market === 'US'
                  ? profitColorClass(calcUSDProfit(item), 'us')
                  : profitColorClass(item.profitKRW, 'kr'), flashCellClass(item)]"
              >
                <template v-if="item.market === 'US'">{{ fmtUSCurrentPrice(item) }}</template>
                <template v-else>{{ formatPrice(item.currency, item.current_price) }}</template>
              </span>
              <span v-else class="text-sm font-mono text-base-content/20">—</span>
            </td>

            <!-- 미실현손익 (US: 정규장 종가 기준 + 장전 손익 / KR: 서버 계산값) -->
            <td class="px-3 py-3.5 text-right">
              <template v-if="item.market === 'US'">
                <template v-if="item.price_available && item.current_price !== null && item.average_price !== null">
                  <!-- 미실현손익: 애프터/주간 포함 총 손익 (현재가 − 평단) × 수량 — 증권사 '애프터/주간 ON' 과 동일 -->
                  <span
                    class="text-sm font-black font-mono inline-block px-1 rounded transition-colors duration-300"
                    :class="[profitColorClass(calcUSDProfit(item), 'us'), flashCellClass(item)]"
                  >{{ fmtUSProfit(item) }}</span>
                  <!-- 정규장(종가 기준) 손익: (정규장 종가 − 평단) × 수량. 연장 차이 없으면 숨김 -->
                  <span
                    v-if="calcUSExtHoursProfit(item) !== null && calcUSExtHoursProfit(item) !== 0"
                    class="text-xs font-bold font-mono block mt-0.5 opacity-75"
                    :class="profitColorClass(calcUSUnrealizedProfit(item), 'us')"
                    title="정규장 종가 기준 손익(증권사 애프터/주간 OFF 와 동일)"
                  >정규장 {{ formatProfitUSD(calcUSUnrealizedProfit(item)) }}</span>
                </template>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
              <template v-else>
                <span
                  v-if="item.price_available && item.profitKRW !== null"
                  class="text-sm font-black font-mono inline-block px-1 rounded transition-colors duration-300"
                  :class="[profitColorClass(item.profitKRW, 'kr'), flashCellClass(item)]"
                >{{ formatProfitWon(item.profitKRW) }}</span>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
            </td>

            <!-- 손익률 -->
            <td class="px-3 py-3.5 text-right">
              <template v-if="item.market === 'US'">
                <template v-if="item.price_available && item.current_price !== null && item.average_price !== null">
                  <!-- 손익률: 애프터/주간 포함 총 손익률 (현재가 기준, 금액과 일치) -->
                  <span
                    class="text-sm font-extrabold font-mono inline-block px-1 rounded transition-colors duration-300"
                    :class="[profitColorClass(calcUSDProfit(item), 'us'), flashCellClass(item)]"
                  >{{ formatProfitRate(calcUSDProfitRate(item)) }}</span>
                  <!-- 정규장(종가 기준) 손익률 -->
                  <span
                    v-if="calcUSExtHoursProfit(item) !== null && calcUSExtHoursProfit(item) !== 0"
                    class="text-xs font-bold font-mono block mt-0.5 opacity-75"
                    :class="profitColorClass(calcUSUnrealizedProfit(item), 'us')"
                    title="정규장 종가 기준 손익률(증권사 애프터/주간 OFF 와 동일)"
                  >정규장 {{ formatProfitRate(calcUSUnrealizedProfitRate(item)) }}</span>
                </template>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
              <template v-else>
                <span
                  v-if="item.price_available && item.profitKRW !== null"
                  class="text-sm font-extrabold font-mono inline-block px-1 rounded transition-colors duration-300"
                  :class="[profitColorClass(item.profitKRW, 'kr'), flashCellClass(item)]"
                >{{ formatProfitRate(item.profitRate) }}</span>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
            </td>

            <!-- 평가금액 (US: 총=연장 현재가 기준 + 정규장=종가 기준, 정규장 중엔 정규장 줄 숨김) -->
            <td class="px-3 py-3.5 text-right">
              <template v-if="fmtMarketValue(item) !== null">
                <span class="text-sm font-bold font-mono text-white/70 inline-block px-1 rounded transition-colors duration-300" :class="flashCellClass(item)">{{ fmtMarketValue(item) }}</span>
                <span
                  v-if="item.market === 'US' && calcUSExtHoursProfit(item) !== null && calcUSExtHoursProfit(item) !== 0 && fmtRegularMarketValue(item) !== null"
                  class="text-xs font-mono text-base-content/45 block mt-0.5"
                  title="정규장 종가 기준 평가금액"
                >정규장 {{ fmtRegularMarketValue(item) }}</span>
              </template>
              <span v-else class="text-sm font-mono text-base-content/20">—</span>
            </td>

            <!-- 관리 버튼 -->
            <td class="px-4 md:px-5 py-3.5 text-center">
              <div class="flex items-center justify-center gap-1.5">
                <!-- $ / ₩ 토글 (US 종목만) -->
                <button
                  v-if="item.market === 'US'"
                  @click.stop="toggleCurrency(item.portfolio_id)"
                  :disabled="!props.exchangeRate?.USD_KRW"
                  :title="getCurrencyMode(item.portfolio_id) === 'USD' ? '원화로 전환' : '달러로 전환'"
                  :aria-label="`${item.symbol} 통화 전환`"
                  class="btn btn-xs btn-ghost border border-base-content/10 hover:border-emerald-500/40 hover:bg-emerald-500/8 text-base-content/40 hover:text-emerald-400 rounded-lg cursor-pointer transition-all duration-150 disabled:opacity-30 font-mono text-[11px]"
                >{{ getCurrencyMode(item.portfolio_id) === 'USD' ? '₩' : '$' }}</button>
                <!-- 수정 버튼 -->
                <button
                  @click.stop="openHoldingModal(item)"
                  class="btn btn-xs btn-ghost border border-base-content/10 hover:border-indigo-500/40 hover:bg-indigo-500/8 text-base-content/40 hover:text-indigo-400 rounded-lg cursor-pointer transition-all duration-150"
                  :aria-label="`${item.symbol} 수정`"
                  title="수정"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </button>
                <button
                  @click.stop="deleteHolding(item)"
                  :disabled="actionLoading"
                  class="btn btn-xs btn-ghost border border-base-content/10 hover:border-error/40 hover:bg-error/8 text-base-content/40 hover:text-error rounded-lg cursor-pointer transition-all duration-150 disabled:opacity-40"
                  :aria-label="`${item.symbol} 삭제`"
                  title="삭제"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ══════════════════════════════════════════════════════
         차트 모달 (행 클릭 시)
         ※ Teleport: 조상의 backdrop-blur(filter)로 인해 fixed 기준이
           패널로 한정되는 문제를 막기 위해 body 직속으로 렌더한다.
    ══════════════════════════════════════════════════════ -->
    <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="showChartModal"
        class="fixed inset-0 z-60 flex flex-col"
        role="dialog"
        aria-modal="true"
        :aria-label="chartModalItem ? `${chartModalItem.name || chartModalItem.symbol} 차트` : '종목 차트'"
      >
        <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="closeChartModal"></div>

        <div class="relative z-10 m-auto w-full max-w-5xl h-[90vh] sm:h-[80vh] min-h-0 sm:min-h-120 mx-2 sm:mx-auto bg-base-100 border border-base-content/12 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
          <!-- 모달 헤더 -->
          <div class="flex items-center justify-between px-5 py-3 border-b border-base-content/8 shrink-0">
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded-md text-xs font-extrabold font-mono text-indigo-300 bg-indigo-500/12 border border-indigo-500/20 tracking-wider">
                {{ chartModalItem ? chartModalItem.symbol : '' }}
              </span>
              <span class="text-sm font-black text-white">{{ chartModalItem ? (chartModalItem.name || chartModalItem.symbol) : '' }}</span>
              <span class="px-1.5 py-0.5 rounded text-xs font-extrabold font-mono border leading-tight text-indigo-400 bg-indigo-500/10 border-indigo-500/20">보유</span>
            </div>

            <div class="flex items-center gap-2">
              <span v-if="chartLoading" class="loading loading-spinner loading-xs text-indigo-400"></span>
              <span v-if="chartError" class="text-xs text-error font-bold font-mono">데이터 오류</span>

              <button
                @click="closeChartModal"
                class="w-7 h-7 flex items-center justify-center rounded-lg text-base-content/40 hover:text-base-content/80 hover:bg-base-200/60 transition-all cursor-pointer"
                aria-label="차트 닫기"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <!-- 차트 본체 -->
          <div class="flex-1 min-h-0 p-3">
            <div v-if="chartLoading" class="h-full flex items-center justify-center gap-3">
              <span class="loading loading-ring loading-md text-indigo-500"></span>
              <span class="text-xs font-bold text-base-content/50 font-mono">차트 데이터 불러오는 중...</span>
            </div>

            <div v-else-if="chartError" class="h-full flex flex-col items-center justify-center gap-3">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-error/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="text-xs font-bold text-error/70 font-mono">{{ chartErrorMessage }}</p>
              <button @click="fetchChartCandles" class="btn btn-xs btn-outline btn-error font-bold rounded-lg cursor-pointer">재시도</button>
            </div>

            <StockChart
              v-else-if="chartCandles.length > 0 && chartModalItem"
              :key="`holdings_chart_${chartModalItem.symbol}_${chartSelectedTimeframe}`"
              :ticker="chartModalItem.symbol"
              :name="chartModalItem.name || chartModalItem.symbol"
              :current-price="chartModalItem.current_price ?? null"
              :change-amount="chartModalItem.change_amount ?? null"
              :change-percent="chartModalItem.change_percent ?? null"
              :candles="chartCandles"
              :session="chartModalItem.session_badge ? sessionBadgeToLabel(chartModalItem.session_badge) : ''"
              :timeframe="chartSelectedTimeframe"
              :average-price="chartModalAveragePrice"
              @timeframe-change="onChartTimeframeChange"
            />

            <div v-else-if="!chartLoading" class="h-full flex flex-col items-center justify-center gap-3">
              <p class="text-xs font-bold text-base-content/40 font-mono">차트 데이터가 없습니다 (장외 시간 또는 휴장일)</p>
            </div>
          </div>
        </div>
      </div>
    </Transition>
    </Teleport>

    <!-- ══════════════════════════════════════════════════════
         보유 종목 추가/수정 모달
         ※ Teleport: 조상의 backdrop-blur(filter)로 인해 fixed 기준이
           패널로 한정되는 문제를 막기 위해 body 직속으로 렌더한다.
    ══════════════════════════════════════════════════════ -->
    <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="showHoldingModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="editingHolding ? '보유 종목 수정' : '보유 종목 추가'"
        @click.self="closeHoldingModal"
      >
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeHoldingModal"></div>

        <div class="relative z-10 w-full max-w-md bg-base-100 border border-base-content/12 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
          <!-- 모달 헤더 -->
          <div class="flex items-center justify-between px-5 py-4 border-b border-base-content/8 shrink-0">
            <h3 class="text-sm font-black text-white tracking-tight">
              {{ editingHolding ? '보유 종목 수정' : '보유 종목 추가' }}
            </h3>
            <button
              @click="closeHoldingModal"
              class="w-7 h-7 flex items-center justify-center rounded-lg text-base-content/40 hover:text-base-content/80 hover:bg-base-200/60 transition-all cursor-pointer"
              aria-label="닫기"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- 모달 폼 -->
          <form @submit.prevent="submitHoldingForm" class="p-4 sm:p-5 space-y-4 overflow-y-auto custom-scrollbar">

            <!-- 종목 선택 (추가 모드만) -->
            <div v-if="!editingHolding" class="space-y-1.5" ref="holdingSearchContainer">
              <label class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">종목 선택</label>

              <!-- 선택된 종목 표시 -->
              <div v-if="holdingForm.symbol" class="flex items-center justify-between px-3 py-2.5 rounded-xl bg-indigo-500/8 border border-indigo-500/25">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-black text-white">{{ holdingForm.symbol }}</span>
                  <span
                    class="text-xs font-bold font-mono px-1.5 py-0.5 rounded"
                    :class="holdingForm.market === 'KR' ? 'text-rose-400/70 bg-rose-500/6' : 'text-emerald-400/70 bg-emerald-500/6'"
                  >{{ holdingForm.market }}</span>
                  <span v-if="holdingForm.stockName" class="text-xs text-base-content/50 truncate max-w-32">{{ holdingForm.stockName }}</span>
                </div>
                <button
                  type="button"
                  @click="clearHoldingStock"
                  class="text-base-content/30 hover:text-base-content/60 cursor-pointer"
                  aria-label="종목 선택 취소"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <!-- 검색 입력 (종목 미선택 시) -->
              <div v-else class="relative">
                <div class="flex items-center gap-1.5">
                  <!-- 시장 선택 -->
                  <div class="tabs tabs-boxed bg-base-200/70 p-0.5 rounded-lg border border-base-content/6 gap-0 shrink-0">
                    <button
                      v-for="m in searchModeOptions"
                      :key="m.value"
                      type="button"
                      @click="holdingSearchMode = m.value; holdingSearchResults = []"
                      :class="[
                        'tab rounded-md text-xs font-extrabold transition-all duration-200 cursor-pointer px-2 py-1',
                        holdingSearchMode === m.value
                          ? 'tab-active bg-indigo-600/15 border border-indigo-500/25 text-indigo-400 shadow-sm'
                          : 'text-base-content/40 hover:text-base-content/70 border border-transparent'
                      ]"
                    >{{ m.label }}</button>
                  </div>
                  <input
                    v-model="holdingQuery"
                    @input="onHoldingSearchInput"
                    @focus="showHoldingSearchDropdown = true"
                    type="text"
                    placeholder="종목명 / 티커 검색..."
                    class="input input-sm input-bordered flex-1 font-semibold text-xs focus:outline-none focus:border-indigo-500/60 placeholder:text-base-content/25 bg-base-200/50 rounded-lg"
                    aria-label="보유 종목 검색"
                    autocomplete="off"
                  />
                </div>

                <!-- 검색 결과 드롭다운 -->
                <Transition name="fade-slide">
                  <div
                    v-if="showHoldingSearchDropdown && holdingSearchResults.length > 0"
                    class="absolute left-0 right-0 top-full mt-1 border border-base-content/10 rounded-xl shadow-2xl z-50 max-h-52 overflow-y-auto backdrop-blur-xl bg-base-100/97 custom-scrollbar"
                  >
                    <div
                      v-for="stock in holdingSearchResults"
                      :key="stock.ticker"
                      @click.stop="selectHoldingStock(stock)"
                      class="flex items-center justify-between px-3 py-2.5 cursor-pointer hover:bg-indigo-500/6 transition-colors border-b border-base-content/4 last:border-b-0 group"
                    >
                      <div class="flex flex-col min-w-0 flex-1 mr-2">
                        <div class="flex items-center gap-1.5 flex-wrap">
                          <span class="text-white font-bold text-sm group-hover:text-indigo-300 transition-colors">{{ stock.name }}</span>
                          <span class="px-1 py-0.5 rounded text-xs font-bold font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/15">{{ stock.ticker }}</span>
                          <span
                            class="text-xs font-bold font-mono px-1 py-0.5 rounded"
                            :class="stock.isKorean ? 'text-rose-400/70 bg-rose-500/6' : 'text-emerald-400/70 bg-emerald-500/6'"
                          >{{ stock.isKorean ? 'KR' : 'US' }}</span>
                        </div>
                        <span v-if="stock.subName" class="text-xs text-base-content/35 mt-0.5 truncate">{{ stock.subName }}</span>
                      </div>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-base-content/20 group-hover:text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                      </svg>
                    </div>
                  </div>
                </Transition>
              </div>
            </div>

            <!-- 수정 모드: 종목 정보 (읽기 전용) -->
            <div v-if="editingHolding" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-base-200/40 border border-base-content/8">
              <span class="text-sm font-black text-white">{{ editingHolding.symbol }}</span>
              <span
                class="text-xs font-bold font-mono px-1.5 py-0.5 rounded"
                :class="editingHolding.market === 'KR' ? 'text-rose-400/70 bg-rose-500/6' : 'text-emerald-400/70 bg-emerald-500/6'"
              >{{ editingHolding.market }}</span>
              <span class="text-xs text-base-content/40 truncate">{{ editingHolding.name }}</span>
            </div>

            <!-- 수량 -->
            <div class="space-y-1.5">
              <label for="hp-qty" class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">수량</label>
              <input
                id="hp-qty"
                v-model="holdingForm.quantity"
                type="number"
                min="1"
                step="1"
                inputmode="numeric"
                placeholder="보유 수량"
                class="input input-sm input-bordered w-full font-mono text-sm focus:outline-none focus:border-indigo-500/60 bg-base-200/50 rounded-lg"
                :class="formErrors.quantity ? 'border-error/60' : ''"
                required
              />
              <p v-if="formErrors.quantity" class="text-xs text-error font-bold font-mono mt-0.5">{{ formErrors.quantity }}</p>
            </div>

            <!-- 평단가 -->
            <div class="space-y-1.5">
              <label for="hp-avg" class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">
                평단가
                <span class="font-normal text-base-content/35 ml-1 normal-case">
                  ({{ holdingFormMarket === 'KR' ? '원화' : 'USD $' }})
                </span>
              </label>
              <input
                id="hp-avg"
                v-model="holdingForm.average_price"
                type="number"
                :min="holdingFormMarket === 'KR' ? '1' : '0.0001'"
                :step="holdingFormMarket === 'KR' ? '1' : 'any'"
                :inputmode="holdingFormMarket === 'KR' ? 'numeric' : 'decimal'"
                :placeholder="holdingFormMarket === 'KR' ? '매입 평단 (원)' : '매입 평단 (USD)'"
                class="input input-sm input-bordered w-full font-mono text-sm focus:outline-none focus:border-indigo-500/60 bg-base-200/50 rounded-lg"
                :class="formErrors.average_price ? 'border-error/60' : ''"
                required
              />
              <p v-if="formErrors.average_price" class="text-xs text-error font-bold font-mono mt-0.5">{{ formErrors.average_price }}</p>
            </div>

            <!-- 매입환율 (US 종목만) -->
            <div v-if="holdingFormMarket === 'US'" class="space-y-1.5">
              <label for="hp-fx" class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">
                매입환율
                <span class="font-normal text-base-content/35 ml-1 normal-case">USD/KRW — 매입 당시 환율</span>
              </label>
              <input
                id="hp-fx"
                v-model="holdingForm.avg_fx_rate"
                type="number"
                min="1"
                step="0.01"
                placeholder="예: 1350.00"
                class="input input-sm input-bordered w-full font-mono text-sm focus:outline-none focus:border-indigo-500/60 bg-base-200/50 rounded-lg"
                :class="formErrors.avg_fx_rate ? 'border-error/60' : ''"
                required
              />
              <p v-if="formErrors.avg_fx_rate" class="text-xs text-error font-bold font-mono mt-0.5">{{ formErrors.avg_fx_rate }}</p>
            </div>

            <!-- 폼 레벨 에러 -->
            <div
              v-if="formErrors._form"
              class="flex items-start gap-2 px-3 py-2.5 rounded-lg bg-error/8 border border-error/20"
              role="alert"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-error/70 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-xs text-error/80 font-bold font-mono">{{ formErrors._form }}</span>
            </div>

            <!-- 버튼 영역 -->
            <div class="flex gap-2 pt-1">
              <button
                type="button"
                @click="closeHoldingModal"
                class="btn btn-sm btn-ghost flex-1 font-bold border border-base-content/10 hover:bg-base-200/40 rounded-xl cursor-pointer"
              >취소</button>
              <button
                type="submit"
                :disabled="actionLoading || (!editingHolding && !holdingForm.symbol)"
                class="btn btn-sm flex-1 font-bold rounded-xl cursor-pointer bg-indigo-600 hover:bg-indigo-500 text-white border-0 disabled:opacity-40"
              >
                <span v-if="actionLoading" class="loading loading-spinner loading-xs"></span>
                {{ editingHolding ? '저장' : '추가' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import StockChart from './StockChart.vue';
import { localSearch, normalizeKrTicker, SEARCHABLE_STOCKS } from '../stocksKnown.js';
import {
  formatWon,
  formatProfitWon,
  formatProfitUSD,
  formatProfitRate,
  formatQuantity,
  formatPrice,
  profitColorClass,
  profitBadgeClass,
  displayName as _displayName,
} from '../utils/format.js';

const SEARCH_MODE_OPTIONS = [
  { value: 'kr',  label: 'KR' },
  { value: 'us',  label: 'US' },
  { value: 'all', label: '전체' },
];

// ── props / emits ──────────────────────────────────────────────
const props = defineProps({
  holdings: {
    type: Array,
    default: () => [],
  },
  exchangeRate: {
    type: Object,
    default: null,  // { USD_KRW: number, ... } | null
  },
});

const emit = defineEmits(['refresh']);

// ── 상수 ────────────────────────────────────────────────────────
const searchModeOptions = SEARCH_MODE_OPTIONS;

// ── 보유 종목 모달 ────────────────────────────────────────────
const showHoldingModal = ref(false);
const editingHolding = ref(null);
const holdingForm = ref({
  symbol: '',
  market: '',
  stockName: '',
  quantity: '',
  average_price: '',
  avg_fx_rate: '',
});
const holdingQuery = ref('');
const holdingSearchMode = ref('all');
const holdingSearchResults = ref([]);
const showHoldingSearchDropdown = ref(false);
let holdingSearchDebounce = null;
const formErrors = ref({});
const actionLoading = ref(false);

// ── 접기/펼치기 (보유종목 패널) ───────────────────────────────
const holdingsCollapsed = ref(localStorage.getItem('holdingsCollapsed') === 'true');
watch(holdingsCollapsed, (val) => {
  localStorage.setItem('holdingsCollapsed', val);
});

// ── 값 변동 플래시 (현재가/미실현손익/손익률/평가금액 블록 색 변화) ──
// 현재가가 바뀌면 해당 행을 잠깐 빨강(상승)/파랑(하락)으로 깜빡인다(사이드바와 동일 톤).
const flashMap = ref({});   // portfolio_id -> 'up' | 'down'
const _prevPrices = {};     // portfolio_id -> 직전 current_price
const _flashTimers = {};
watch(
  () => props.holdings,
  (list) => {
    for (const item of (list || [])) {
      const id = item.portfolio_id;
      const cur = item.current_price;
      const prev = _prevPrices[id];
      if (prev != null && cur != null && cur !== prev) {
        flashMap.value = { ...flashMap.value, [id]: cur > prev ? 'up' : 'down' };
        clearTimeout(_flashTimers[id]);
        _flashTimers[id] = setTimeout(() => {
          const m = { ...flashMap.value };
          delete m[id];
          flashMap.value = m;
        }, 700);
      }
      if (cur != null) _prevPrices[id] = cur;
    }
  },
  { deep: true }
);

// 셀 플래시 배경 클래스 (상승=빨강, 하락=파랑)
function flashCellClass(item) {
  const f = flashMap.value[item.portfolio_id];
  if (f === 'up') return 'bg-rose-500/15';
  if (f === 'down') return 'bg-sky-500/15';
  return '';
}

// ── 토스트 ────────────────────────────────────────────────────
const toast = ref({ show: false, type: 'success', message: '' });
let toastTimer = null;

// ── 차트 모달 ─────────────────────────────────────────────────
const showChartModal = ref(false);
const chartModalItem = ref(null);
const chartCandles = ref([]);
const chartSelectedTimeframe = ref('3m');
const chartLoading = ref(false);
const chartError = ref(false);
const chartErrorMessage = ref('');
let chartPollTimer = null;

// ── 템플릿 ref ────────────────────────────────────────────────
const holdingSearchContainer = ref(null);

// ── 세션 배지: 타임존 기반 실시간 30초 갱신 ──────────────────

function getMarketSession(market) {
  const now = new Date();

  if (market === 'KR') {
    const fmt = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Seoul',
      weekday: 'short', hour: 'numeric', minute: 'numeric', hour12: false
    });
    const parts = Object.fromEntries(fmt.formatToParts(now).map(p => [p.type, p.value]));
    const dow = parts.weekday;
    const h = parseInt(parts.hour, 10);
    const m = parseInt(parts.minute, 10);
    const timeVal = h * 100 + m;
    const isWeekday = !['Sat','Sun'].includes(dow);
    if (isWeekday && timeVal >= 900 && timeVal <= 1530) return 'REG_KR';
    return 'CLOSED';
  }

  if (market === 'US') {
    const fmt = new Intl.DateTimeFormat('en-US', {
      timeZone: 'America/New_York',
      weekday: 'short', hour: 'numeric', minute: 'numeric', hour12: false
    });
    const parts = Object.fromEntries(fmt.formatToParts(now).map(p => [p.type, p.value]));
    const dow = parts.weekday;
    const h = parseInt(parts.hour, 10);
    const m = parseInt(parts.minute, 10);
    const timeVal = h * 100 + m;

    if (dow === 'Sat') return 'CLOSED';
    if (dow === 'Sun' && timeVal < 2000) return 'CLOSED';
    if (dow === 'Fri' && timeVal >= 2000) return 'CLOSED';

    if (timeVal >= 2000 || timeVal < 330) return 'EXT_NIGHT';
    if (timeVal >= 400 && timeVal < 930) return 'PRE';
    if (timeVal >= 930 && timeVal < 1600) return 'REG_US';
    if (timeVal >= 1600 && timeVal < 1930) return 'AFT';
    return 'CLOSED';
  }

  return 'CLOSED';
}

function sessionLabel(code) {
  switch(code) {
    case 'REG_KR': return '정규장';
    case 'REG_US': return '정규장';
    case 'PRE': return '프리마켓';
    case 'AFT': return '애프터마켓';
    case 'EXT_NIGHT': return '주간거래';
    case 'CLOSED': return '장마감';
    default: return '장마감';
  }
}

function sessionBadgeStyle(code) {
  switch(code) {
    case 'EXT_NIGHT': return 'text-emerald-400 bg-emerald-500/8 border-emerald-500/20';
    case 'PRE': return 'text-amber-400 bg-amber-500/8 border-amber-500/20';
    case 'REG_KR':
    case 'REG_US': return 'text-pink-400 bg-pink-500/8 border-pink-500/20';
    case 'AFT': return 'text-cyan-400 bg-cyan-500/8 border-cyan-500/20';
    case 'CLOSED':
    default: return 'text-base-content/40 bg-base-200/40 border-base-content/10';
  }
}

const sessionNow = ref(new Date());
let sessionTimer = null;

const sessionCodeKR = computed(() => {
  void sessionNow.value;
  return getMarketSession('KR');
});
const sessionCodeUS = computed(() => {
  void sessionNow.value;
  return getMarketSession('US');
});

/**
 * 백엔드에서 내려온 한글 세션 라벨(live_session)을 내부 코드로 변환한다.
 * 백엔드 응답 예: '정규장', '프리마켓', '애프터마켓', '주간거래', '장마감'
 */
function liveSessionToCode(liveSession, market) {
  switch (liveSession) {
    case '정규장':     return market === 'KR' ? 'REG_KR' : 'REG_US';
    case '프리마켓':   return 'PRE';
    case '애프터마켓': return 'AFT';
    case '주간거래':   return 'EXT_NIGHT';
    case '장마감':     return 'CLOSED';
    default:           return 'CLOSED';
  }
}

/**
 * 종목별 세션 코드 결정.
 * item.live_session(백엔드, 공휴일 정확)이 있으면 우선 사용하고,
 * 없으면 클라이언트 시간 계산 값으로 폴백한다.
 */
function itemSessionCode(item) {
  if (item.live_session) {
    return liveSessionToCode(item.live_session, item.market);
  }
  // 폴백: 클라이언트 계산 (공휴일 불인식)
  return item.market === 'KR' ? sessionCodeKR.value : sessionCodeUS.value;
}

// ── 통화 토글 (US 종목 달러/원화) ────────────────────────────
const HOLDINGS_CURRENCY_KEY = 'holdings_currency';

function loadCurrencyMap() {
  try {
    const raw = localStorage.getItem(HOLDINGS_CURRENCY_KEY);
    if (!raw) return {};
    const parsed = JSON.parse(raw);
    return typeof parsed === 'object' && parsed !== null ? parsed : {};
  } catch { return {}; }
}

function saveCurrencyMap(map) {
  try { localStorage.setItem(HOLDINGS_CURRENCY_KEY, JSON.stringify(map)); } catch {}
}

const currencyMap = ref(loadCurrencyMap());

function getCurrencyMode(portfolioId) {
  return currencyMap.value[portfolioId] || 'USD';
}

function toggleCurrency(portfolioId) {
  const current = getCurrencyMode(portfolioId);
  const next = current === 'USD' ? 'KRW' : 'USD';
  const updated = { ...currencyMap.value, [portfolioId]: next };
  currencyMap.value = updated;
  saveCurrencyMap(updated);
}

function usdToKrw(usdValue) {
  const rate = props.exchangeRate?.USD_KRW;
  if (!rate || rate <= 0 || usdValue === null || usdValue === undefined) return null;
  return usdValue * rate;
}

function fmtUSAvg(item) {
  const mode = getCurrencyMode(item.portfolio_id);
  if (mode === 'KRW') {
    const won = usdToKrw(Number(item.average_price));
    return won !== null ? formatWon(won) : formatPrice(item.currency, item.average_price);
  }
  return formatPrice(item.currency, item.average_price);
}

function fmtUSCurrentPrice(item) {
  const mode = getCurrencyMode(item.portfolio_id);
  if (mode === 'KRW') {
    const won = usdToKrw(Number(item.current_price));
    return won !== null ? formatWon(won) : formatPrice(item.currency, item.current_price);
  }
  return formatPrice(item.currency, item.current_price);
}

function fmtUSProfit(item) {
  const profit = calcUSDProfit(item);
  const mode = getCurrencyMode(item.portfolio_id);
  if (mode === 'KRW') {
    const won = usdToKrw(profit);
    return won !== null ? formatProfitWon(won) : formatProfitUSD(profit);
  }
  return formatProfitUSD(profit);
}

function fmtMarketValue(item) {
  if (!item.price_available || item.current_price === null) return null;
  const cur = Number(item.current_price);
  const qty = Number(item.quantity);
  if (isNaN(cur) || isNaN(qty)) return null;
  const value = cur * qty;

  if (item.market === 'KR') {
    return formatWon(value);
  }
  const mode = getCurrencyMode(item.portfolio_id);
  if (mode === 'KRW') {
    const won = usdToKrw(value);
    return won !== null ? formatWon(won) : `${value.toFixed(2)}$`;
  }
  return `${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}$`;
}

// US 정규장 평가금액 = 정규장 종가 × 수량 (통화 모드 반영). regular_close_price 없으면 null.
function fmtRegularMarketValue(item) {
  if (item.market !== 'US' || item.regular_close_price == null) return null;
  const reg = Number(item.regular_close_price);
  const qty = Number(item.quantity);
  if (isNaN(reg) || isNaN(qty)) return null;
  const value = reg * qty;
  const mode = getCurrencyMode(item.portfolio_id);
  if (mode === 'KRW') {
    const won = usdToKrw(value);
    return won !== null ? formatWon(won) : `${value.toFixed(2)}$`;
  }
  return `${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}$`;
}

// ── 드래그앤드롭: 보유 종목 순서 관리 ─────────────────────────
const HOLDINGS_ORDER_KEY = 'holdings_order';

// localStorage에서 저장된 portfolio_id 순서 배열을 로드
function loadOrder() {
  try {
    const raw = localStorage.getItem(HOLDINGS_ORDER_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

// portfolio_id 배열을 localStorage에 저장
function saveOrder(idList) {
  try {
    localStorage.setItem(HOLDINGS_ORDER_KEY, JSON.stringify(idList));
  } catch {
    // 저장 실패 시 무시
  }
}

// 표시 순서: 저장된 id 순서대로 정렬, 새 종목은 끝에 추가, 사라진 id는 무시
const orderedHoldings = computed(() => {
  // orderVersion을 참조해 드롭 후 computed가 재평가되도록 의존성 등록
  void orderVersion.value;
  const items = props.holdings;
  if (!items || items.length === 0) return [];
  const savedOrder = loadOrder();
  const idSet = new Set(items.map(h => h.portfolio_id));
  // 저장 순서 중 실제로 존재하는 id만 남김
  const validOrder = savedOrder.filter(id => idSet.has(id));
  // 아직 순서에 없는 새 종목은 뒤에 추가
  const orderedIds = validOrder.slice();
  items.forEach(h => {
    if (!orderedIds.includes(h.portfolio_id)) {
      orderedIds.push(h.portfolio_id);
    }
  });
  // id 순서대로 items를 정렬
  const idxMap = new Map(orderedIds.map((id, i) => [id, i]));
  return items.slice().sort((a, b) => {
    const ia = idxMap.has(a.portfolio_id) ? idxMap.get(a.portfolio_id) : 9999;
    const ib = idxMap.has(b.portfolio_id) ? idxMap.get(b.portfolio_id) : 9999;
    return ia - ib;
  });
});

// props.holdings가 바뀔 때 순서 배열을 현행화(없어진 id 제거, 새 id 추가)
watch(
  () => props.holdings,
  (newHoldings) => {
    if (!newHoldings || newHoldings.length === 0) return;
    const savedOrder = loadOrder();
    const idSet = new Set(newHoldings.map(h => h.portfolio_id));
    const updated = savedOrder.filter(id => idSet.has(id));
    newHoldings.forEach(h => {
      if (!updated.includes(h.portfolio_id)) updated.push(h.portfolio_id);
    });
    saveOrder(updated);
  },
  { immediate: true }
);

// 드래그 상태
const draggingId = ref(null);
const dragOverId = ref(null);

function onDragStart(event, portfolioId) {
  draggingId.value = portfolioId;
  // 투명도 전환 효과를 위해 DataTransfer에 id를 담아둠
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', String(portfolioId));
}

function onDragOver(event, portfolioId) {
  if (portfolioId === draggingId.value) return;
  dragOverId.value = portfolioId;
}

function onDragLeave() {
  dragOverId.value = null;
}

function onDrop(event, targetId) {
  if (!draggingId.value || draggingId.value === targetId) {
    dragOverId.value = null;
    return;
  }
  // 현재 순서 배열에서 dragging → target 위치로 이동
  const currentOrder = orderedHoldings.value.map(h => h.portfolio_id);
  const fromIdx = currentOrder.indexOf(draggingId.value);
  const toIdx = currentOrder.indexOf(targetId);
  if (fromIdx === -1 || toIdx === -1) {
    dragOverId.value = null;
    return;
  }
  const newOrder = currentOrder.slice();
  const [moved] = newOrder.splice(fromIdx, 1);
  newOrder.splice(toIdx, 0, moved);
  saveOrder(newOrder);
  // 강제 반응성 갱신을 위해 localStorage 변경 후 orderedHoldings computed가 재평가되도록
  // Vue의 computed는 deps 기반이므로 props를 건드리지 않고도 localStorage를 트리거로 쓰려면
  // 별도 반응형 키가 필요 → orderVersion으로 트리거
  orderVersion.value++;
  dragOverId.value = null;
}

function onDragEnd() {
  _dragEndedAt = Date.now();
  draggingId.value = null;
  dragOverId.value = null;
}

// 드래그 후에 발생하는 spurious click 무시
// HTML5 DnD: dragend 직후 click이 발생하는 브라우저 동작 대응
let _dragEndedAt = 0;
function onRowClick(item) {
  // dragend로부터 200ms 이내의 click은 드래그 결과로 판단해 무시
  if (Date.now() - _dragEndedAt < 200) return;
  openChartModal(item);
}

// computed 재평가 트리거 (localStorage는 반응형이 아니므로 버전 카운터 사용)
const orderVersion = ref(0);

// ── computed ──────────────────────────────────────────────────
const holdingFormMarket = computed(() => {
  if (editingHolding.value) return editingHolding.value.market;
  return holdingForm.value.market;
});

const chartModalAveragePrice = computed(() => {
  if (!chartModalItem.value) return null;
  const avg = chartModalItem.value.average_price;
  return (avg !== null && avg !== undefined) ? Number(avg) : null;
});

// ── API base ──────────────────────────────────────────────────
function apiBase() {
  const host = window.location.hostname || 'localhost';
  return `http://${host}:8000`;
}

// ── 토스트 ───────────────────────────────────────────────────
function showToast(message, type = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  toast.value = { show: true, type, message };
  toastTimer = setTimeout(() => {
    toast.value = { show: false, type: 'success', message: '' };
  }, 3500);
}

// ── 보유 종목 모달 ────────────────────────────────────────────
function openHoldingModal(item = null) {
  editingHolding.value = item || null;
  formErrors.value = {};
  if (item) {
    const isKR = item.market === 'KR';
    // 수량은 정수, 원화(KR) 평단가도 정수로 고정. 그 외 후행 0 제거.
    const qtyNum = Math.round(Number(item.quantity));
    const avgNum = isKR ? Math.round(Number(item.average_price)) : Number(item.average_price);
    holdingForm.value = {
      symbol: item.symbol,
      market: item.market,
      stockName: item.name || '',
      quantity: String(qtyNum),
      average_price: String(avgNum),
      avg_fx_rate: item.avg_fx_rate ? String(Number(item.avg_fx_rate)) : '',
    };
  } else {
    holdingForm.value = {
      symbol: '',
      market: '',
      stockName: '',
      quantity: '',
      average_price: '',
      avg_fx_rate: '',
    };
    holdingQuery.value = '';
    holdingSearchResults.value = [];
    showHoldingSearchDropdown.value = false;
    holdingSearchMode.value = 'all';
  }
  showHoldingModal.value = true;
}

function closeHoldingModal() {
  showHoldingModal.value = false;
  editingHolding.value = null;
  holdingQuery.value = '';
  holdingSearchResults.value = [];
  showHoldingSearchDropdown.value = false;
  formErrors.value = {};
}

function clearHoldingStock() {
  holdingForm.value.symbol = '';
  holdingForm.value.market = '';
  holdingForm.value.stockName = '';
  holdingQuery.value = '';
  holdingSearchResults.value = [];
}

// 검색 입력 처리
function onHoldingSearchInput() {
  if (holdingSearchDebounce) clearTimeout(holdingSearchDebounce);
  const q = holdingQuery.value.trim();
  if (!q) {
    holdingSearchResults.value = [];
    return;
  }

  // 로컬 즉시 표시
  const localResults = localSearch(q, holdingSearchMode.value);
  holdingSearchResults.value = localResults;
  if (localResults.length > 0) showHoldingSearchDropdown.value = true;

  // 백엔드 디바운스 머지
  holdingSearchDebounce = setTimeout(async () => {
    const apiResults = await fetchStockSearchApi(q, holdingSearchMode.value);
    holdingSearchResults.value = mergeSearchResults(
      localSearch(q, holdingSearchMode.value),
      apiResults,
      q
    );
    showHoldingSearchDropdown.value = true;
  }, 300);
}

function selectHoldingStock(stock) {
  holdingForm.value.symbol = stock.isKorean ? normalizeKrTicker(stock.ticker) : stock.ticker;
  holdingForm.value.market = stock.isKorean ? 'KR' : 'US';
  holdingForm.value.stockName = stock.name || '';
  holdingQuery.value = '';
  holdingSearchResults.value = [];
  showHoldingSearchDropdown.value = false;
  if (holdingForm.value.market === 'KR') {
    holdingForm.value.avg_fx_rate = '';
  }
}

// 폼 제출 (추가 or 수정)
async function submitHoldingForm() {
  formErrors.value = {};

  const market = holdingFormMarket.value;
  // 수량은 정수, 원화(KR) 평단가도 정수로 고정.
  const qty = Math.round(parseFloat(holdingForm.value.quantity));
  const avgRaw = parseFloat(holdingForm.value.average_price);
  const avg = market === 'KR' ? Math.round(avgRaw) : avgRaw;
  const fxRate = parseFloat(holdingForm.value.avg_fx_rate);

  if (!editingHolding.value && !holdingForm.value.symbol) {
    formErrors.value._form = '종목을 먼저 검색해서 선택해주세요';
    return;
  }
  if (isNaN(qty) || qty <= 0) {
    formErrors.value.quantity = '수량은 1 이상의 정수여야 합니다';
    return;
  }
  if (isNaN(avg) || avg <= 0) {
    formErrors.value.average_price = '평단가는 0보다 커야 합니다';
    return;
  }
  if (market === 'US') {
    if (!holdingForm.value.avg_fx_rate || isNaN(fxRate) || fxRate <= 0) {
      formErrors.value.avg_fx_rate = 'USD 종목은 매입환율(USD/KRW)이 필수입니다';
      return;
    }
  }

  if (actionLoading.value) return;
  actionLoading.value = true;

  try {
    if (editingHolding.value) {
      const body = { quantity: qty, average_price: avg };
      if (market === 'US') body.avg_fx_rate = fxRate;
      await axios.patch(
        `${apiBase()}/api/portfolio/${editingHolding.value.portfolio_id}`,
        body,
        { headers: { Accept: 'application/json' } }
      );
      showToast(`${editingHolding.value.symbol} 수정 완료`, 'success');
    } else {
      const body = {
        symbol: holdingForm.value.symbol,
        market,
        quantity: qty,
        average_price: avg,
        source: 'manual',
      };
      if (market === 'US') body.avg_fx_rate = fxRate;
      await axios.post(
        `${apiBase()}/api/portfolio`,
        body,
        { headers: { Accept: 'application/json' } }
      );
      showToast(`${holdingForm.value.symbol} 보유 추가 완료`, 'success');
    }
    closeHoldingModal();
    emit('refresh');
  } catch (e) {
    if (e.response?.status === 422) {
      const backendErrors = e.response.data?.errors || {};
      const backendMsg = e.response.data?.message || '';
      if (Object.keys(backendErrors).length > 0) {
        if (backendErrors.quantity) formErrors.value.quantity = backendErrors.quantity[0];
        if (backendErrors.average_price) formErrors.value.average_price = backendErrors.average_price[0];
        if (backendErrors.avg_fx_rate) formErrors.value.avg_fx_rate = backendErrors.avg_fx_rate[0];
        if (!Object.keys(formErrors.value).length) {
          formErrors.value._form = backendMsg || '입력 값을 확인해주세요';
        }
      } else {
        formErrors.value._form = backendMsg || '입력 값을 확인해주세요';
      }
    } else {
      const msg = e.response?.data?.message || e.message || '요청에 실패했습니다';
      formErrors.value._form = `오류: ${msg}`;
      console.error('[HoldingsPanel] submitHoldingForm', e);
    }
  } finally {
    actionLoading.value = false;
  }
}

// 보유 종목 삭제
async function deleteHolding(item) {
  if (actionLoading.value) return;
  if (!confirm(`'${item.name || item.symbol}'을(를) 보유 목록에서 삭제할까요?`)) return;
  actionLoading.value = true;
  try {
    await axios.delete(
      `${apiBase()}/api/portfolio/${item.portfolio_id}`,
      { headers: { Accept: 'application/json' } }
    );
    showToast(`${item.symbol} 삭제 완료`, 'success');
    emit('refresh');
  } catch (e) {
    const msg = e.response?.data?.message || '삭제에 실패했습니다';
    showToast(msg, 'error');
    console.error('[HoldingsPanel] deleteHolding', e);
  } finally {
    actionLoading.value = false;
  }
}

// ── 종목 검색 API ─────────────────────────────────────────────
async function fetchStockSearchApi(q, mode) {
  if (!q) return [];
  try {
    const res = await axios.get(`${apiBase()}/api/stocks/search`, {
      params: { q, type: mode },
      headers: { Accept: 'application/json' },
    });
    return (res.data || []).map(s => ({
      ticker: s.ticker || s.symbol,
      name: s.name || s.ticker || s.symbol,
      subName: s.exchange ? `${s.exchange} | ${s.ticker || s.symbol}` : '',
      isKorean: s.isKorean ?? (/(\.KS|\.KQ)$/i.test(s.ticker || '') || /^\d/.test(s.ticker || '')),
    }));
  } catch (e) {
    console.error('[HoldingsPanel] fetchStockSearchApi', e);
    return [];
  }
}

function mergeSearchResults(localResults, apiResults, query) {
  const q = String(query || '').trim();
  const qLower = q.toLowerCase();
  const hasHangulSyllable = /[가-힣]/.test(q);

  const seen = new Set(localResults.map(r => r.ticker));
  let api = apiResults.filter(item => !seen.has(item.ticker));

  // 한글(완성형) 검색어인데 종목명/티커에 실제로 들어있지 않은 API 느슨한 매칭 제거
  if (hasHangulSyllable) {
    api = api.filter(s =>
      String(s.name || '').toLowerCase().includes(qLower) ||
      String(s.ticker || '').toLowerCase().includes(qLower)
    );
  }

  // 관련도 점수 정렬(정확일치 > 접두 > 부분 포함 > 그 외)
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

  return [...localResults, ...api]
    .map((item) => ({ item, s: scoreOf(item) }))
    .sort((a, b) => b.s - a.s)
    .map((x) => x.item)
    .slice(0, 12);
}

// ── 드롭다운 외부 클릭 / ESC ──────────────────────────────────
function handleDocumentClick(e) {
  if (holdingSearchContainer.value && !holdingSearchContainer.value.contains(e.target)) {
    showHoldingSearchDropdown.value = false;
  }
}

function handleKeyDown(e) {
  if (e.key !== 'Escape') return;
  if (showChartModal.value) {
    closeChartModal();
  } else if (showHoldingModal.value) {
    closeHoldingModal();
  }
}

// ── 차트 모달 ─────────────────────────────────────────────────
function openChartModal(item) {
  chartModalItem.value = item;
  chartSelectedTimeframe.value = '3m';
  chartCandles.value = [];
  chartError.value = false;
  chartErrorMessage.value = '';
  showChartModal.value = true;
  fetchChartCandles();
  startChartPoll();
}

function closeChartModal() {
  showChartModal.value = false;
  stopChartPoll();
}

async function fetchChartCandles() {
  if (!chartModalItem.value) return;
  const symbol = chartModalItem.value.symbol;
  const tf = chartSelectedTimeframe.value;
  chartLoading.value = true;
  chartError.value = false;
  try {
    const res = await axios.get(
      `${apiBase()}/api/stocks/${encodeURIComponent(symbol)}`,
      {
        params: { timeframe: tf },
        headers: { Accept: 'application/json' },
      }
    );
    const data = res.data;
    chartCandles.value = Array.isArray(data.candles) ? data.candles : [];

    if (data.current_price !== undefined) {
      chartModalItem.value = {
        ...chartModalItem.value,
        current_price: data.current_price ?? chartModalItem.value.current_price,
        change_amount: data.change_amount ?? chartModalItem.value.change_amount,
        change_percent: data.change_percent ?? chartModalItem.value.change_percent,
        session: data.session ?? chartModalItem.value.session,
      };
    }
  } catch (e) {
    chartError.value = true;
    if (e.response?.status === 404) {
      chartErrorMessage.value = `${symbol} 데이터를 찾을 수 없습니다`;
    } else if (e.request) {
      chartErrorMessage.value = '백엔드 서버에 연결할 수 없습니다';
    } else {
      chartErrorMessage.value = `차트 로드 실패: ${e.message}`;
    }
    console.error('[HoldingsPanel] fetchChartCandles', e);
  } finally {
    chartLoading.value = false;
  }
}

function onChartTimeframeChange(tf) {
  if (chartSelectedTimeframe.value === tf) return;
  chartSelectedTimeframe.value = tf;
  chartCandles.value = [];
  fetchChartCandles();
}

function startChartPoll() {
  stopChartPoll();
  chartPollTimer = setInterval(() => {
    if (showChartModal.value && !chartLoading.value) {
      fetchChartCandles();
    }
  }, 30000);
}

function stopChartPoll() {
  if (chartPollTimer) {
    clearInterval(chartPollTimer);
    chartPollTimer = null;
  }
}

function sessionBadgeToLabel(badge) {
  switch (badge) {
    case 'REG': return '정규장';
    case 'PRE': return '프리마켓';
    case 'AFT': return '애프터마켓';
    default:    return '';
  }
}

// ── 로컬 유틸 ────────────────────────────────────────────────

/**
 * US 종목 총 손익 (현재가 — 연장 포함)
 * = (current_price − average_price) × quantity
 */
function calcUSDProfit(item) {
  const cur = Number(item.current_price);
  const avg = Number(item.average_price);
  const qty = Number(item.quantity);
  if (isNaN(cur) || isNaN(avg) || isNaN(qty)) return null;
  return (cur - avg) * qty;
}

/**
 * US 종목 미실현손익 (정규장 종가 기준)
 * = (regular_close_price − average_price) × quantity
 * regular_close_price 가 없으면 current_price 로 폴백 (정규장 중과 동일)
 */
function calcUSUnrealizedProfit(item) {
  const regClose = item.regular_close_price != null
    ? Number(item.regular_close_price)
    : Number(item.current_price);
  const avg = Number(item.average_price);
  const qty = Number(item.quantity);
  if (isNaN(regClose) || isNaN(avg) || isNaN(qty)) return null;
  return (regClose - avg) * qty;
}

/**
 * US 종목 미실현손익률 (정규장 종가 기준)
 * = (regular_close_price − average_price) / average_price
 */
function calcUSUnrealizedProfitRate(item) {
  const regClose = item.regular_close_price != null
    ? Number(item.regular_close_price)
    : Number(item.current_price);
  const avg = Number(item.average_price);
  if (isNaN(regClose) || isNaN(avg) || avg === 0) return null;
  return (regClose - avg) / avg;
}

/**
 * US 종목 장전(연장) 손익
 * = (current_price − regular_close_price) × quantity
 * regular_close_price 가 없으면 null 반환 (표시 안 함)
 * 정규장 중(둘이 같거나 regular_close_price 없음)이면 0 또는 null
 */
function calcUSExtHoursProfit(item) {
  if (item.regular_close_price == null) return null;
  const cur      = Number(item.current_price);
  const regClose = Number(item.regular_close_price);
  const qty      = Number(item.quantity);
  if (isNaN(cur) || isNaN(regClose) || isNaN(qty)) return null;
  return (cur - regClose) * qty;
}

/**
 * US 종목 총 손익률(애프터/주간 포함) = (current_price − average_price) / average_price
 * 백엔드 profitRate 는 정규장 종가 기준이라, 총액 헤드라인과 일치시키려 현재가 기준으로 직접 계산.
 */
function calcUSDProfitRate(item) {
  const cur = Number(item.current_price);
  const avg = Number(item.average_price);
  if (isNaN(cur) || isNaN(avg) || avg <= 0) return 0;
  return (cur - avg) / avg;
}

/**
 * US 종목 장전(연장) 손익률 = (current_price − regular_close_price) / average_price
 * 평단을 분모로 써, 정규장 손익률 + 장전 손익률 = 총 손익률 로 깔끔히 합산된다.
 * regular_close_price 가 없으면 null.
 */
function calcUSExtHoursProfitRate(item) {
  if (item.regular_close_price == null) return null;
  const cur      = Number(item.current_price);
  const regClose = Number(item.regular_close_price);
  const avg      = Number(item.average_price);
  if (isNaN(cur) || isNaN(regClose) || isNaN(avg) || avg <= 0) return null;
  return (cur - regClose) / avg;
}

// displayName: format.js 의 공용 함수에 SEARCHABLE_STOCKS 를 바인딩해 사용
function displayName(item) {
  return _displayName(item, SEARCHABLE_STOCKS);
}

function sessionBadgeClass(badge) {
  switch (badge) {
    case 'REG': return 'bg-indigo-500/12 text-indigo-400 border-indigo-500/25';
    case 'PRE': return 'bg-amber-500/12 text-amber-400 border-amber-500/25';
    case 'AFT': return 'bg-purple-500/12 text-purple-400 border-purple-500/25';
    default:    return 'bg-base-200/40 text-base-content/25 border-base-content/8';
  }
}

function sessionBadgeKo(badge) {
  switch (badge) {
    case 'REG': return '정규장';
    case 'PRE': return '프리';
    case 'AFT': return '애프터';
    default:    return '—';
  }
}

// ── 라이프사이클 ──────────────────────────────────────────────
onMounted(() => {
  document.addEventListener('click', handleDocumentClick);
  document.addEventListener('keydown', handleKeyDown);
  sessionTimer = setInterval(() => { sessionNow.value = new Date(); }, 30000);
});

onBeforeUnmount(() => {
  stopChartPoll();
  document.removeEventListener('click', handleDocumentClick);
  document.removeEventListener('keydown', handleKeyDown);
  if (holdingSearchDebounce) clearTimeout(holdingSearchDebounce);
  if (toastTimer) clearTimeout(toastTimer);
  if (sessionTimer) clearInterval(sessionTimer);
});
</script>

<style scoped>
.overflow-x-auto {
  -webkit-overflow-scrolling: touch;
}

.custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 99px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(99,102,241,0.4); }

.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.25s ease;
}
.toast-slide-enter-from,
.toast-slide-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.15s ease;
}
.fade-slide-enter-from,
.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: all 0.2s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}
</style>
