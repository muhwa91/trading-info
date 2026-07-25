// 데모 모드 네트워크 가로채기 — 서버 없이 프론트만으로 돌린다.
//
// 왜 호출부를 안 고치고 여기서 가로채는가:
//   API 호출 지점이 25곳이고 베이스 URL 이 7곳 이상 하드코딩돼 있다.
//   전부 고치면 실제 앱 코드가 데모 때문에 흔들린다. 경계 3곳만 막으면 본 코드는 무손상이다.
//
// ⚠️ `fetch` 만 막으면 화면 절반이 죽는다 — 보유·관심종목·검색은 `axios`(XHR)를 쓴다.
//    그래서 `axios.defaults.adapter` 까지 교체한다. WebSocket 은 서버가 없으니 통째로 스텁.

import axios from "axios";
import { DEMO_STOCKS, findStock } from "./data.js";
import { makeCandles } from "./candles.js";
import { sessionFor } from "./session.js";
import { buildDashboard, buildPrices, buildWatchlist, quoteOf } from "./portfolio.js";

// ── 라우팅 ────────────────────────────────────────────────
// 실제 라우트(artisan route:list 실측): dashboard·prices·stocks/search·stocks/{t}·stocks/{t}/earnings
// + 쓰기 5개. 쓰기는 서버가 없으므로 성공만 돌려주고 화면은 로컬 상태로 굴러간다.

function stockPayload(ticker, timeframe = "1d") {
  const stock = findStock(ticker);
  if (!stock) return null;

  const candles = makeCandles(stock, timeframe);
  const q = quoteOf(stock);
  const ses = sessionFor(stock);

  return {
    ticker: stock.ticker,
    name: stock.name,
    current_price: candles[candles.length - 1].close, // 차트 끝값과 반드시 일치
    change_amount: q.changeAmount,
    change_percent: q.changePercent,
    candles,
    source: "Demo",
    regular_change_amount: q.changeAmount,
    regular_change_percent: q.changePercent,
    regular_close: q.prevClose,
    session: ses.label,
    us_session: stock.market === "US" ? ses.code : undefined,
    is_trading_day: ses.isTradingDay,
  };
}

/** @returns {{status:number, body:any}|null} — null 이면 데모가 모르는 경로(실제로 통과시킨다) */
function route(method, pathname, query) {
  const m = method.toUpperCase();

  if (m === "GET") {
    if (pathname === "/api/portfolio/dashboard") return { status: 200, body: buildDashboard() };
    if (pathname === "/api/prices") return { status: 200, body: buildPrices() };

    if (pathname === "/api/stocks/search") {
      const q = (query.get("q") || "").trim().toLowerCase();
      if (!q) return { status: 200, body: [] };
      const hits = DEMO_STOCKS.filter(
        (s) => s.name.toLowerCase().includes(q) || s.ticker.toLowerCase().includes(q),
      ).map((s) => ({
        ticker: s.ticker,
        name: s.name,
        isKorean: s.market === "KR",
        exchange: s.market === "KR" ? "KRX" : "NASDAQ",
      }));
      return { status: 200, body: hits };
    }

    const earnings = pathname.match(/^\/api\/stocks\/(.+)\/earnings$/);
    if (earnings) {
      return { status: 200, body: { success: false, earnings_date: null, raw: null } };
    }

    const detail = pathname.match(/^\/api\/stocks\/([^/]+)$/);
    if (detail) {
      const body = stockPayload(decodeURIComponent(detail[1]), query.get("timeframe") || "1d");
      return body ? { status: 200, body } : { status: 404, body: { message: "not found" } };
    }
  }

  // 쓰기 — 서버가 없다. 성공만 돌려주고 실제 반영은 화면 로컬 상태가 한다.
  // (새로고침하면 초기 상태로 돌아간다. 데모라 그게 오히려 안전하다.)
  if (/^\/api\/(portfolio|watchlist)(\/|$)/.test(pathname) && m !== "GET") {
    return { status: 200, body: { success: true, demo: true } };
  }

  return null;
}

function resolve(url) {
  const u = new URL(url, window.location.origin);
  return { pathname: u.pathname, query: u.searchParams };
}

// ── fetch ────────────────────────────────────────────────
function installFetch() {
  const original = window.fetch.bind(window);
  window.fetch = async (input, init = {}) => {
    const url = typeof input === "string" ? input : input.url;
    const method = init.method || (typeof input === "object" && input.method) || "GET";
    const { pathname, query } = resolve(url);
    const hit = route(method, pathname, query);
    if (!hit) return original(input, init);

    await delay();
    return new Response(JSON.stringify(hit.body), {
      status: hit.status,
      headers: { "Content-Type": "application/json" },
    });
  };
}

// ── axios ────────────────────────────────────────────────
function installAxios() {
  const original = axios.defaults.adapter;
  axios.defaults.adapter = async (config) => {
    const url = (config.baseURL || "") + (config.url || "");
    const { pathname, query } = resolve(url);
    // axios 는 쿼리를 config.params 로도 넘긴다(검색이 이 방식)
    for (const [k, v] of Object.entries(config.params || {})) {
      if (v != null) query.set(k, String(v));
    }
    const hit = route(config.method || "get", pathname, query);
    if (!hit) return original(config);

    await delay();
    return { data: hit.body, status: hit.status, statusText: "OK", headers: {}, config };
  };
}

// ── WebSocket ────────────────────────────────────────────
// 서버가 없으므로 통째로 스텁. 구독한 종목을 3초 주기로 순환 전송해
// 실제 앱(20초에 36프레임 실측)과 같은 리듬을 만든다.
function installWebSocket() {
  const RealWebSocket = window.WebSocket;

  class DemoWebSocket extends EventTarget {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSING = 2;
    static CLOSED = 3;

    constructor(url) {
      super();
      this.url = url;
      this.readyState = DemoWebSocket.CONNECTING;
      this.onopen = this.onmessage = this.onclose = this.onerror = null;
      this._tickers = [];
      this._timer = null;
      setTimeout(() => {
        this.readyState = DemoWebSocket.OPEN;
        this.onopen?.({ type: "open" });
      }, 120);
    }

    send(raw) {
      let msg;
      try {
        msg = JSON.parse(raw);
      } catch {
        return;
      }
      if (msg.type !== "subscribe") return;
      this._tickers = (msg.tickers || []).filter((t) => findStock(t));
      this._start(msg.timeframes || {});
    }

    _start(timeframes) {
      clearInterval(this._timer);
      let i = 0;
      const push = () => {
        if (this.readyState !== DemoWebSocket.OPEN || !this._tickers.length) return;
        const ticker = this._tickers[i++ % this._tickers.length];
        const body = stockPayload(ticker, timeframes[ticker] || "1d");
        if (!body) return;
        jitter(body); // 고정 화면으로 보이지 않게 마지막 값만 미세하게 흔든다
        this.onmessage?.({
          data: JSON.stringify({ type: "update", stocks: { [ticker]: body } }),
        });
      };
      push();
      this._timer = setInterval(push, 500); // 종목당 약 3초 주기(6종목 기준)
    }

    close() {
      clearInterval(this._timer);
      this.readyState = DemoWebSocket.CLOSED;
      this.onclose?.({ type: "close" });
    }
  }

  window.WebSocket = new Proxy(RealWebSocket, {
    construct(target, args) {
      const url = String(args[0] || "");
      // 데모 대상은 이 앱의 시세 소켓(8080)뿐 — 그 외는 진짜 WebSocket 으로 통과
      return /:8080(\/|$)/.test(url) ? new DemoWebSocket(url) : new target(...args);
    },
  });
}

/** 마지막 봉만 ±0.15% 흔들어 "살아 있는 화면"을 만든다. 캔들 이력은 건드리지 않는다. */
function jitter(body) {
  const k = 1 + (Math.random() - 0.5) * 0.003;
  const last = body.candles[body.candles.length - 1];
  const dec = Number.isInteger(last.close) ? 0 : 2;
  const round = (v) => (dec ? Math.round(v * 100) / 100 : Math.round(v));
  last.close = round(last.close * k);
  last.high = Math.max(last.high, last.close);
  last.low = Math.min(last.low, last.close);
  body.current_price = last.close;
}

/** 네트워크 왕복 느낌 — 즉시 응답하면 스켈레톤이 안 보여 오히려 어색하다 */
const delay = () => new Promise((r) => setTimeout(r, 60 + Math.random() * 120));

export function installDemoMock() {
  installFetch();
  installAxios();
  installWebSocket();
  // eslint-disable-next-line no-console
  console.info("[demo] 더미 데이터 모드 — 서버·실계좌 연결 없음");
}
