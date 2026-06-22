/**
 * chartHelpers.js 단위 테스트
 *
 * 커버: isCoordinateVisible(coord, chartHeight)
 *
 * 규칙:
 *   - coord === null || coord === undefined → false
 *   - 0 <= coord <= chartHeight → true
 *   - 그 외(음수, chartHeight 초과) → false
 *
 * 핵심 시나리오: "차트를 줄였을 때(chartHeight 감소) 평단 좌표가
 * 범위를 벗어나면 false → 오버레이 미노출"이 정확히 동작하는지 검증.
 */

import { describe, it, expect } from 'vitest';
import { isCoordinateVisible } from './chartHelpers.js';

// ─────────────────────────────────────────────
// null / undefined — 항상 false
// ─────────────────────────────────────────────

describe('isCoordinateVisible — null/undefined 좌표', () => {
  it('coord가 null이면 false', () => {
    expect(isCoordinateVisible(null, 300)).toBe(false);
  });

  it('coord가 undefined이면 false', () => {
    expect(isCoordinateVisible(undefined, 300)).toBe(false);
  });

  it('coord가 null이고 chartHeight도 0이면 false', () => {
    expect(isCoordinateVisible(null, 0)).toBe(false);
  });
});

// ─────────────────────────────────────────────
// 상단 벗어남 — 음수 좌표
// ─────────────────────────────────────────────

describe('isCoordinateVisible — 음수(상단 벗어남)', () => {
  it('coord -1 → false (상단 경계 바로 위)', () => {
    expect(isCoordinateVisible(-1, 300)).toBe(false);
  });

  it('coord -100 → false (상단에서 크게 벗어남)', () => {
    expect(isCoordinateVisible(-100, 300)).toBe(false);
  });

  it('coord -0.1 → false (소수 음수)', () => {
    expect(isCoordinateVisible(-0.1, 300)).toBe(false);
  });
});

// ─────────────────────────────────────────────
// 상단 경계값 — coord === 0
// ─────────────────────────────────────────────

describe('isCoordinateVisible — 상단 경계(coord 0)', () => {
  it('coord 0, chartHeight 300 → true', () => {
    expect(isCoordinateVisible(0, 300)).toBe(true);
  });

  it('coord 0, chartHeight 0 → true (0<=0<=0 성립)', () => {
    // chartHeight=0인 극소 차트에서 정확히 0이면 경계상 visible
    expect(isCoordinateVisible(0, 0)).toBe(true);
  });
});

// ─────────────────────────────────────────────
// 가시 범위 내 — 중간값
// ─────────────────────────────────────────────

describe('isCoordinateVisible — 가시 범위 내(중간값)', () => {
  it('coord 150, chartHeight 300 → true', () => {
    expect(isCoordinateVisible(150, 300)).toBe(true);
  });

  it('coord 1, chartHeight 300 → true (상단 바로 안쪽)', () => {
    expect(isCoordinateVisible(1, 300)).toBe(true);
  });

  it('coord 299, chartHeight 300 → true (하단 바로 안쪽)', () => {
    expect(isCoordinateVisible(299, 300)).toBe(true);
  });

  it('coord 50, chartHeight 100 → true', () => {
    expect(isCoordinateVisible(50, 100)).toBe(true);
  });
});

// ─────────────────────────────────────────────
// 하단 경계값 — coord === chartHeight
// ─────────────────────────────────────────────

describe('isCoordinateVisible — 하단 경계(coord === chartHeight)', () => {
  it('coord 300, chartHeight 300 → true (경계 포함)', () => {
    expect(isCoordinateVisible(300, 300)).toBe(true);
  });

  it('coord 100, chartHeight 100 → true', () => {
    expect(isCoordinateVisible(100, 100)).toBe(true);
  });

  it('coord 1, chartHeight 1 → true', () => {
    expect(isCoordinateVisible(1, 1)).toBe(true);
  });
});

// ─────────────────────────────────────────────
// 하단 벗어남 — coord > chartHeight
// ─────────────────────────────────────────────

describe('isCoordinateVisible — 하단 벗어남(coord > chartHeight)', () => {
  it('coord 301, chartHeight 300 → false (하단 경계 바로 아래)', () => {
    expect(isCoordinateVisible(301, 300)).toBe(false);
  });

  it('coord 400, chartHeight 300 → false (하단에서 크게 벗어남)', () => {
    expect(isCoordinateVisible(400, 300)).toBe(false);
  });

  it('coord 300.1, chartHeight 300 → false (소수 초과)', () => {
    expect(isCoordinateVisible(300.1, 300)).toBe(false);
  });

  it('coord 1, chartHeight 0 → false (chartHeight 0인 차트에서 1은 초과)', () => {
    expect(isCoordinateVisible(1, 0)).toBe(false);
  });
});

// ─────────────────────────────────────────────
// 핵심: 차트 축소 시나리오
// chartHeight가 줄어들면 같은 평단 좌표도 범위를 벗어날 수 있다
// "차트를 줄였을 때 평단 오버레이가 안 보여야 한다"는 핵심 요구사항 검증
// ─────────────────────────────────────────────

describe('isCoordinateVisible — 차트 축소 시나리오 (핵심)', () => {
  it('평단 좌표 250 / 차트 높이 300(원래) → true (축소 전 보임)', () => {
    // 차트가 클 때: 평단가가 차트 y축 범위 안에 들어옴
    expect(isCoordinateVisible(250, 300)).toBe(true);
  });

  it('평단 좌표 250 / 차트 높이 200(축소) → false (축소 후 벗어남)', () => {
    // 차트를 200px로 줄이면 250px 위치는 범위 밖 → 오버레이 미노출
    expect(isCoordinateVisible(250, 200)).toBe(false);
  });

  it('평단 좌표 200 / 차트 높이 200(축소, 경계) → true (정확히 경계)', () => {
    // 경계값은 포함 → 오버레이 노출
    expect(isCoordinateVisible(200, 200)).toBe(true);
  });

  it('평단 좌표 201 / 차트 높이 200(축소) → false (1px 초과)', () => {
    // 1px만 벗어나도 오버레이 미노출
    expect(isCoordinateVisible(201, 200)).toBe(false);
  });

  it('평단 좌표 50 / 차트 높이 100(대폭 축소) → true (축소돼도 범위 내)', () => {
    // 차트를 줄여도 평단가가 범위 안에 있으면 그대로 노출
    expect(isCoordinateVisible(50, 100)).toBe(true);
  });

  it('평단 좌표 null / 차트 높이 50(극소) → false (좌표 없음)', () => {
    // priceToCoordinate 가 null 반환(종목 미선택 등) → 미노출
    expect(isCoordinateVisible(null, 50)).toBe(false);
  });

  it('현재가 좌표 0 / 차트 높이 150(축소) → true (상단 경계, 보임)', () => {
    // 현재가가 y축 최상단에 맞닿은 경우 — 경계값이므로 노출
    expect(isCoordinateVisible(0, 150)).toBe(true);
  });

  it('현재가 좌표 -5 / 차트 높이 150(축소) → false (상단 초과, 미노출)', () => {
    // 현재가가 y축 위로 밀려난 경우
    expect(isCoordinateVisible(-5, 150)).toBe(false);
  });
});

// ─────────────────────────────────────────────
// 극단값 / 엣지케이스
// ─────────────────────────────────────────────

describe('isCoordinateVisible — 극단값·엣지케이스', () => {
  it('chartHeight가 매우 큰 값(10000) — 중간 좌표는 true', () => {
    expect(isCoordinateVisible(5000, 10000)).toBe(true);
  });

  it('chartHeight가 매우 큰 값(10000) — 초과 좌표는 false', () => {
    expect(isCoordinateVisible(10001, 10000)).toBe(false);
  });

  it('coord와 chartHeight 모두 0 → true (0<=0<=0)', () => {
    expect(isCoordinateVisible(0, 0)).toBe(true);
  });

  it('coord가 소수(예: 150.5), chartHeight 300 → true', () => {
    // priceToCoordinate는 소수를 반환할 수 있음
    expect(isCoordinateVisible(150.5, 300)).toBe(true);
  });

  it('coord가 소수(예: 300.5), chartHeight 300 → false', () => {
    expect(isCoordinateVisible(300.5, 300)).toBe(false);
  });
});
