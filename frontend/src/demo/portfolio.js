// 데모 포트폴리오 — 전부 지어낸 값.
//
// ⚠️ 실계좌 수량·평단·평가금액은 여기 절대 넣지 않는다(공개 데모 실데이터 금지).
//    수량과 평단은 "그럴듯한 자리"로 임의 지정했고, 평가액·손익은 그로부터 계산된다.
//    실제 앱과 계산식이 같아야 화면이 자연스러우므로 계산만 백엔드와 동일하게 맞췄다.

import { DEMO_STOCKS, DEMO_FX, findStock } from "./data.js";
import { lastClose } from "./candles.js";
import { sessionFor, dashboardSession } from "./session.js";

// 지어낸 보유 — 수량·평단은 임의값.
// 평단을 일부러 현재가 위/아래로 섞었다. 전 종목이 큰 플러스면 화면이 장밋빛으로만 보여
// 손실 색·손익 분리 같은 이 앱의 실제 기능이 드러나지 않는다(첫 시안이 그랬다).
// 평단은 data.js 의 `base` 기준으로 잡는다 — 캔들이 base 근처에서 끝나도록 스케일링되므로
// 여기 값이 곧 "얼마나 먹거나 물렸는가"가 된다.
const POSITIONS = [
  { ticker: "005930", quantity: 120, average_price: 71_000, avg_fx_rate: 1 },      // +8%
  { ticker: "000660", quantity: 18, average_price: 268_000, avg_fx_rate: 1 },      // −12%
  { ticker: "NVDA", quantity: 24, average_price: 152.0, avg_fx_rate: 1412.0 },     // 주가 +, 환율 −
  { ticker: "MU", quantity: 30, average_price: 158.0, avg_fx_rate: 1355.0 },       // −9%
];

const SESSION_BADGE = {
  REGULAR: "REG",
  PRE: "PRE",
  AFTER: "AFT",
  DAY: "DAY",
  CLOSED: "CLS",
};

const nowStamp = () =>
  new Date().toISOString().slice(0, 19).replace("T", " ");

/** 종목 하나의 현재 시세 묶음 — 카드·보유·관심종목이 공통으로 쓴다 */
export function quoteOf(stock, now = new Date()) {
  const price = lastClose(stock, "1d");
  const prev = price / (1 + stock.vol * 0.35); // 전일 종가 자리
  const ses = sessionFor(stock, now);
  return {
    price,
    prevClose: Math.round(prev * 100) / 100,
    changeAmount: Math.round((price - prev) * 100) / 100,
    changePercent: Math.round(((price - prev) / prev) * 10000) / 100,
    session: ses,
  };
}

export function buildDashboard(now = new Date()) {
  const fx = DEMO_FX.USD_KRW;

  const holdings = POSITIONS.map((pos, i) => {
    const stock = findStock(pos.ticker);
    const q = quoteOf(stock, now);
    const krw = stock.currency === "KRW";

    // 평가액·원가 — 외화는 현재 환율/매입 환율로 각각 환산(실제 앱과 동일)
    const marketValueKRW = krw
      ? q.price * pos.quantity
      : q.price * pos.quantity * fx;
    const costKRW = krw
      ? pos.average_price * pos.quantity
      : pos.average_price * pos.quantity * pos.avg_fx_rate;

    // 손익을 주가분·환율분으로 분리 — 이 분리가 이 앱의 특징이라 데모에서도 살린다
    const priceProfitKRW = krw
      ? (q.price - pos.average_price) * pos.quantity
      : (q.price - pos.average_price) * pos.quantity * pos.avg_fx_rate;
    const fxProfitKRW = krw
      ? 0
      : q.price * pos.quantity * (fx - pos.avg_fx_rate);

    return {
      portfolio_id: i + 1,
      stock_id: 1000 + i,
      symbol: stock.ticker,
      name: stock.name,
      market: stock.market,
      currency: stock.currency,
      type: "stock",
      quantity: pos.quantity,
      average_price: pos.average_price,
      avg_fx_rate: pos.avg_fx_rate,
      current_price: q.price,
      regular_close_price: q.prevClose,
      session_badge: SESSION_BADGE[q.session.code],
      live_session: q.session.label,
      price_available: true,
      marketValueKRW: Math.round(marketValueKRW),
      costKRW: Math.round(costKRW),
      profitKRW: Math.round(marketValueKRW - costKRW),
      priceProfitKRW: Math.round(priceProfitKRW),
      fxProfitKRW: Math.round(fxProfitKRW),
      profitRate:
        Math.round(((marketValueKRW - costKRW) / costKRW) * 10000) / 100,
    };
  });

  const sum = (k) => holdings.reduce((a, h) => a + h[k], 0);
  const totalMarketValueKRW = sum("marketValueKRW");
  const totalCostKRW = sum("costKRW");

  return {
    session: dashboardSession(now),
    exchange_rate: {
      USD_KRW: fx,
      recorded_at: nowStamp(),
      source: "Demo",
      prev_close: DEMO_FX.prev_close,
    },
    summary: {
      totalMarketValueKRW,
      totalCostKRW,
      totalProfitKRW: totalMarketValueKRW - totalCostKRW,
      totalPriceProfitKRW: sum("priceProfitKRW"),
      totalFxProfitKRW: sum("fxProfitKRW"),
      totalProfitRate:
        Math.round(
          ((totalMarketValueKRW - totalCostKRW) / totalCostKRW) * 10000,
        ) / 100,
    },
    holdings,
    watchlist: buildWatchlist(now),
  };
}

export function buildWatchlist(now = new Date()) {
  return DEMO_STOCKS.map((stock, i) => {
    const q = quoteOf(stock, now);
    return {
      watchlist_id: i + 1,
      stock_id: 1000 + i,
      symbol: stock.ticker,
      name: stock.name,
      market: stock.market,
      currency: stock.currency,
      type: "stock",
      sort_order: i,
      current_price: q.price,
      change_amount: q.changeAmount,
      change_percent: q.changePercent,
      price_available: true,
    };
  });
}

/** GET /api/prices — stock_id 를 키로 하는 맵(녹화본 실측 형태) */
export function buildPrices(now = new Date()) {
  const prices = {};
  DEMO_STOCKS.forEach((stock, i) => {
    const q = quoteOf(stock, now);
    prices[1000 + i] = {
      symbol: stock.ticker,
      current_price: q.price,
      change_amount: q.changeAmount,
      change_percent: q.changePercent,
      price_available: true,
    };
  });
  return {
    session: dashboardSession(now),
    exchange_rate: { USD_KRW: DEMO_FX.USD_KRW, recorded_at: nowStamp() },
    prices,
  };
}
