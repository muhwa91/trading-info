/**
 * 관심종목 클릭 → 그리드 슬롯 배치 로직 단위 테스트 — utils/gridPlacement.js 실경계 가드
 *
 * App.vue 의 handleUnifiedSelect 와 이 테스트가 **같은** placeTicker 를 import 한다
 * (이웃 gridCols.test.js 와 동일 패턴, 인라인 복제본 없음).
 * 특히 "이미 그리드에 있는 종목 클릭 → 중복 차트 생성" 회귀를 막는다(2026-07-26).
 */

import { describe, it, expect } from 'vitest';
import { placeTicker } from './gridPlacement.js';

const empty = () => ['', '', '', '', '', ''];

describe('placeTicker — 관심종목 클릭 시 슬롯 배치 (utils/gridPlacement.js)', () => {
  it('빈 그리드 → 활성 슬롯(0)에 배치되고 다음 활성은 빈 슬롯(1)', () => {
    const r = placeTicker(empty(), 0, 'TSLA');
    expect(r.tickers[0]).toBe('TSLA');
    expect(r.placedIndex).toBe(0);
    expect(r.activeIndex).toBe(1);
  });

  it('부분만 채워진 그리드 → 다음 활성은 앞쪽 빈 슬롯', () => {
    const r = placeTicker(['AAPL', '', 'MSFT', '', '', ''], 1, 'NVDA');
    expect(r.tickers).toEqual(['AAPL', 'NVDA', 'MSFT', '', '', '']);
    expect(r.placedIndex).toBe(1);
    expect(r.activeIndex).toBe(3); // 남은 첫 빈 슬롯
  });

  it('꽉 찬 그리드 → 활성 슬롯을 교체하고 다음 활성은 시계방향 순환', () => {
    const full = ['A', 'B', 'C', 'D', 'E', 'F'];
    const r = placeTicker(full, 2, 'NEW');
    expect(r.tickers).toEqual(['A', 'B', 'NEW', 'D', 'E', 'F']);
    expect(r.placedIndex).toBe(2);
    expect(r.activeIndex).toBe(3);

    // 마지막 슬롯이면 0으로 되돌아온다
    expect(placeTicker(full, 5, 'NEW').activeIndex).toBe(0);
  });

  it('이미 표시 중인 종목 클릭 → 중복 추가 없이 그 슬롯만 활성화 (회귀 가드)', () => {
    const cur = ['AAPL', '', 'MSFT', '', '', ''];
    const r = placeTicker(cur, 1, 'MSFT');
    expect(r.tickers).toEqual(cur);   // 목록 불변
    expect(r.placedIndex).toBeNull(); // 새로 배치하지 않음
    expect(r.activeIndex).toBe(2);    // 기존 슬롯이 활성
  });

  it('활성 슬롯의 종목을 다시 클릭 → 자기 자신 교체·포커스 이동 없음', () => {
    const cur = ['AAPL', 'MSFT', '', '', '', ''];
    const r = placeTicker(cur, 1, 'MSFT');
    expect(r.placedIndex).toBeNull();
    expect(r.activeIndex).toBe(1); // 그대로 — 옛 코드는 여기서 다음 슬롯으로 넘어갔다
    expect(r.tickers).toEqual(cur);
  });

  it("빈 문자열 클릭 → 빈 슬롯을 '배치됨'으로 오인하지 않는다", () => {
    // ''.indexOf → 첫 빈 슬롯에 매칭되므로 placedIndex 는 null 이어야 한다.
    // (placedIndex 가 숫자로 나오면 App 이 빈 문자열을 차트로 그리고 WS 를 재구독한다)
    const cur = ['AAPL', '', 'MSFT', '', '', ''];
    const r = placeTicker(cur, 0, '');
    expect(r.placedIndex).toBeNull();
    expect(r.tickers).toEqual(cur);
  });

  it('같은 종목이 여러 슬롯에 중복된 상태 → 첫 슬롯을 활성화하고 목록은 그대로', () => {
    const cur = ['AAPL', 'TSLA', 'AAPL', '', '', ''];
    const r = placeTicker(cur, 1, 'AAPL');
    expect(r.activeIndex).toBe(0);
    expect(r.placedIndex).toBeNull();
    expect(r.tickers).toEqual(cur); // 중복을 임의로 정리하지 않는다(그리드 슬롯 수 불변)
  });

  it('슬롯 수를 늘리거나 줄이지 않는다(GRID_SIZE 불변)', () => {
    const cur = ['A', 'B', 'C', 'D', 'E', 'F'];
    expect(placeTicker(cur, 0, 'NEW').tickers).toHaveLength(6);
    expect(placeTicker(empty(), 5, 'NEW').tickers).toHaveLength(6);
    expect(placeTicker(cur, 3, 'C').tickers).toHaveLength(6);
  });

  it('입력 배열을 변형하지 않는다', () => {
    const cur = ['AAPL', '', '', '', '', ''];
    placeTicker(cur, 1, 'TSLA');
    placeTicker(cur, 0, 'AAPL');
    expect(cur).toEqual(['AAPL', '', '', '', '', '']);
  });
});
