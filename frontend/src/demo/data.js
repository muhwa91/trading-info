// 데모 종목 메타.
//
// ⚠️ 실제 보유 종목·수량·평단은 이 파일에도, 데모 어디에도 들어가지 않는다.
//    여기 있는 건 "어떤 종목을 화면에 띄울지"와 기준가뿐이며 전부 지어낸 값이다.
//    (공개 데모에 실계좌 데이터 금지 — 프로젝트 CLAUDE.md)

/**
 * base = 캔들 생성의 시작 가격. 실제 시세가 아니라 "그럴듯한 자리"일 뿐이다.
 * 실시세를 박아두면 시간이 지나며 현실과 어긋나 오히려 거짓말이 되므로 일부러 라운드 값으로 둔다.
 */
export const DEMO_STOCKS = [
  // ── 국내 (KRW) ──
  { ticker: "005930", name: "삼성전자", market: "KR", currency: "KRW", base: 78000, vol: 0.014 },
  { ticker: "000660", name: "SK하이닉스", market: "KR", currency: "KRW", base: 235000, vol: 0.022 },
  { ticker: "009150", name: "삼성전기", market: "KR", currency: "KRW", base: 152000, vol: 0.019 },
  { ticker: "402340", name: "SK스퀘어", market: "KR", currency: "KRW", base: 96000, vol: 0.024 },

  // ── 미국 (USD) ──
  { ticker: "NVDA", name: "엔비디아", market: "US", currency: "USD", base: 178, vol: 0.023 },
  { ticker: "AVGO", name: "브로드컴", market: "US", currency: "USD", base: 342, vol: 0.021 },
  { ticker: "MU", name: "마이크론 테크놀로지", market: "US", currency: "USD", base: 145, vol: 0.028 },
  { ticker: "SNDK", name: "샌디스크", market: "US", currency: "USD", base: 62, vol: 0.031 },
];

/**
 * 지수·환율 — 상단 헤더와 "지수" 섹션이 구독하는 특수 티커.
 * 이걸 빼면 지수 카드가 `LOADING INDEX...` 에서 영원히 멈춘다(실측).
 * 종목 목록(DEMO_STOCKS)과 분리한 이유: 검색·관심종목에 노출되면 안 되기 때문.
 */
export const DEMO_INDICES = [
  { ticker: "NQ=F", name: "나스닥 선물", market: "US", currency: "USD", base: 24_800, vol: 0.011 },
  { ticker: "KOSPI200", name: "코스피200", market: "KR", currency: "KRW", base: 448, vol: 0.012 },
  { ticker: "KOSPI_NIGHT", name: "코스피200 야간", market: "KR", currency: "KRW", base: 449, vol: 0.010 },
  { ticker: "USDKRW=X", name: "원/달러", market: "US", currency: "KRW", base: 1380, vol: 0.004 },
];

export const DEMO_FX = { USD_KRW: 1380.5, prev_close: 1376.2 };

/** 티커로 메타 조회(종목 + 지수) — 모르는 티커는 null(mock 이 404 로 응답) */
export function findStock(ticker) {
  return (
    DEMO_STOCKS.find((s) => s.ticker === ticker) ??
    DEMO_INDICES.find((s) => s.ticker === ticker) ??
    null
  );
}
