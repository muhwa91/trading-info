// 캔들 생성 — 시드 고정 랜덤워크.
//
// 왜 녹화본이 아니라 생성인가:
//   - 차트 기간이 7종(1m·3m·5m·10m·30m·1h·1d)이라 녹화하면 종목×기간만큼 픽스처가 필요하다.
//     생성하면 어떤 기간이든 즉석에서 나온다.
//   - 녹화본은 시간이 지날수록 "옛날 시세"가 되어 오히려 거짓이 된다.
//
// 시드를 티커로 고정하는 이유: 방문할 때마다 차트 모양이 달라지면 어수선하고,
// 스크린샷·문서와도 어긋난다. 같은 종목은 언제 봐도 같은 그래프를 그린다.

/** mulberry32 — 짧고 분포가 고른 시드 PRNG */
function rng(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function seedOf(str) {
  let h = 2166136261;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

/** 기간별 봉 간격(분). '1d' 는 일봉이라 별도 취급 */
const STEP_MIN = { "1m": 1, "3m": 3, "5m": 5, "10m": 10, "30m": 30, "1h": 60 };

/** 가격 자릿수 — 원화는 정수, 달러는 소수 2자리 */
const round = (v, krw) => (krw ? Math.round(v) : Math.round(v * 100) / 100);

/**
 * 봉 개수는 반드시 한 곳에서만 정한다.
 * 랜덤워크라 개수가 다르면 **같은 종목인데 마지막 종가가 달라진다** —
 * 실제로 카드 현재가(240봉)와 차트 끝값(510봉)이 어긋나는 버그가 났다.
 */
export const CANDLE_COUNT = (timeframe) => (timeframe === "1d" ? 510 : 240);

/**
 * @param {{ticker:string, base:number, vol:number, currency:string}} stock
 * @param {string} timeframe '1m'|'3m'|'5m'|'10m'|'30m'|'1h'|'1d'
 * @param {number} count 봉 개수
 * @returns {Array<{time:string|number, open:number, high:number, low:number, close:number, volume:number}>}
 */
export function makeCandles(stock, timeframe = "1d", count = CANDLE_COUNT(timeframe)) {
  const krw = stock.currency === "KRW";
  const daily = timeframe === "1d";
  const rand = rng(seedOf(stock.ticker + timeframe));

  // 분봉은 하루 안에서 움직이므로 변동폭을 줄인다(일봉 변동성을 그대로 쓰면 널뛴다)
  const vol = daily ? stock.vol : stock.vol / 6;

  // 랜덤워크를 만든 뒤 **마지막 종가가 base 근처가 되도록 전체를 스케일링**한다.
  // 드리프트를 그냥 누적시키면 봉 개수에 따라 끝값이 크게 달라져(510봉에서 +46%)
  // 삼성전자가 12만원이 되는 식으로 현실과 동떨어진다 — 실측하고 잡은 문제.
  let price = 1;
  const raw = [];
  for (let i = 0; i < count; i++) {
    const shock = (rand() - 0.5) * 2 * vol;
    const open = price;
    const close = open * (1 + 0.0006 + shock);
    const wick = Math.abs(shock) * (0.4 + rand() * 0.9);
    raw.push({
      open,
      close,
      high: Math.max(open, close) * (1 + wick * 0.5),
      low: Math.min(open, close) * (1 - wick * 0.5),
      volume: Math.round((0.6 + rand()) * (krw ? 12_000_000 : 40_000_000)),
    });
    price = close;
  }

  // 종목마다 끝값이 딱 떨어지면 어색하므로 시드로 ±3% 흔든다
  const target = stock.base * (0.97 + rng(seedOf(stock.ticker))() * 0.06);
  const scale = target / raw[raw.length - 1].close;

  const now = Date.now();
  const stepMs = daily ? 86400000 : STEP_MIN[timeframe] * 60000;

  return raw.map((c, i) => {
    const ts = now - (count - 1 - i) * stepMs;
    return {
      // 일봉은 'YYYY-MM-DD', 분봉은 epoch 초 — 녹화본 실측 형식
      time: daily
        ? new Date(ts).toISOString().slice(0, 10)
        : Math.floor(ts / 1000),
      open: round(c.open * scale, krw),
      high: round(c.high * scale, krw),
      low: round(c.low * scale, krw),
      close: round(c.close * scale, krw),
      volume: c.volume,
    };
  });
}

/** 그 종목의 "현재가" — 캔들 마지막 종가와 반드시 일치시켜야 차트와 카드가 어긋나지 않는다.
 *  count 를 넘기지 않아 CANDLE_COUNT 를 그대로 쓴다(차트와 동일 조건). */
export function lastClose(stock, timeframe = "1d") {
  const c = makeCandles(stock, timeframe);
  return c[c.length - 1].close;
}
