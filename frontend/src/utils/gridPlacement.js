/**
 * 관심종목 클릭 → 그리드 슬롯 배치 결정 (순수 함수).
 * 이미 그리드에 있는 종목은 중복 추가하지 않고 그 슬롯을 활성화만 한다.
 *
 * @param {string[]} tickers      현재 그리드 슬롯 배열(빈 슬롯은 '')
 * @param {number}   activeIndex  현재 활성 슬롯
 * @param {string}   ticker       클릭한 종목 심볼
 * @returns {{tickers: string[], activeIndex: number, placedIndex: number|null}}
 *          placedIndex === null 이면 새로 배치하지 않았다는 뜻(이미 표시 중).
 */
export function placeTicker(tickers, activeIndex, ticker) {
  const existing = tickers.indexOf(ticker);
  if (existing !== -1) {
    return { tickers: [...tickers], activeIndex: existing, placedIndex: null };
  }

  const next = [...tickers];
  next[activeIndex] = ticker;

  // 다음 활성 슬롯: 빈 슬롯이 있으면 그 슬롯 우선(직관적), 없으면 시계방향 순환
  const emptyIdx = next.indexOf('');
  return {
    tickers: next,
    activeIndex: emptyIdx !== -1 ? emptyIdx : (activeIndex + 1) % next.length,
    placedIndex: activeIndex,
  };
}
