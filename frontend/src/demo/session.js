// 데모 세션 판정 — 방문 시각 기준으로 계산한다.
//
// 녹화본을 쓰면 세션이 녹화 시점에 고정돼 "새벽에 봐도 정규장" 같은 거짓이 되지만,
// 계산하면 실제 앱처럼 시간대에 따라 개장전/정규장/애프터/장마감이 바뀐다.
//
// 백엔드 로직의 간이판이다 — 공휴일 캘린더는 없고 주말만 본다.
// 데모 목적상 그 정도면 충분하고, 정확한 거래일 판정은 서버(토스 캘린더)의 몫이다.

const KR_LABEL = { REGULAR: "정규장", CLOSED: "장마감" };
const US_LABEL = {
  PRE: "프리마켓",
  REGULAR: "정규장",
  AFTER: "애프터마켓",
  DAY: "주간거래",
  CLOSED: "장마감",
};

/** 특정 타임존의 '분 단위 시각'과 요일을 얻는다(Intl 로 DST 자동 처리) */
function zoned(now, timeZone) {
  const p = new Intl.DateTimeFormat("en-US", {
    timeZone,
    weekday: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(now);
  const get = (t) => p.find((x) => x.type === t)?.value ?? "";
  const hour = Number(get("hour")) % 24;
  const minutes = hour * 60 + Number(get("minute"));
  const weekend = ["Sat", "Sun"].includes(get("weekday"));
  return { minutes, weekend };
}

/** 국내: 평일 09:00–15:30 KST */
export function krSession(now = new Date()) {
  const { minutes, weekend } = zoned(now, "Asia/Seoul");
  const open = !weekend && minutes >= 9 * 60 && minutes < 15 * 60 + 30;
  const code = open ? "REGULAR" : "CLOSED";
  return { code, label: KR_LABEL[code], isTradingDay: !weekend };
}

/**
 * 미국: ET 기준. 경계는 실측값 —
 * 프리 04:00, 정규 09:30–16:00, 애프터 종료 19:50(토스 정합).
 */
export function usSession(now = new Date()) {
  const { minutes, weekend } = zoned(now, "America/New_York");
  let code = "CLOSED";
  if (!weekend) {
    if (minutes >= 4 * 60 && minutes < 9 * 60 + 30) code = "PRE";
    else if (minutes >= 9 * 60 + 30 && minutes < 16 * 60) code = "REGULAR";
    else if (minutes >= 16 * 60 && minutes < 19 * 60 + 50) code = "AFTER";
  }
  return { code, label: US_LABEL[code], isTradingDay: !weekend };
}

/** 종목 메타 → 그 종목의 세션 */
export function sessionFor(stock, now = new Date()) {
  return stock.market === "KR" ? krSession(now) : usSession(now);
}

/** 대시보드 최상위 `session` 은 영문 코드(소문자)를 쓴다 — 녹화본 실측: "regular" */
export function dashboardSession(now = new Date()) {
  return krSession(now).code === "REGULAR" ? "regular" : "closed";
}
