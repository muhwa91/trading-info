/**
 * GRID_COLS_MAP / gridColsClass 매핑 단위 테스트
 *
 * App.vue 의 GRID_COLS_MAP 상수와 gridColsClass 로직을 인라인 복제해
 * 컴포넌트 마운트 없이 순수 함수로 검증한다.
 *
 * 커버:
 *   - 1→grid-cols-1 (1열)
 *   - 2→grid-cols-1 md:grid-cols-2 (2열)
 *   - 3→grid-cols-1 md:grid-cols-2 lg:grid-cols-3 (3열)
 *   - 4→grid-cols-1 md:grid-cols-2  ← 핵심: 4개일 때 2열(2×2)
 *   - 5→grid-cols-1 md:grid-cols-2 lg:grid-cols-3 (3열)
 *   - 6→grid-cols-1 md:grid-cols-2 lg:grid-cols-3 (3열)
 *   - 0·7이상·undefined → 폴백(3열)
 */

import { describe, it, expect } from 'vitest';

// ── App.vue 와 동일한 상수·로직 복제 ────────────────────────────────
const GRID_COLS_MAP = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 md:grid-cols-2',
  3: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
  4: 'grid-cols-1 md:grid-cols-2',
  5: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
  6: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
};
const FALLBACK = 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3';

function gridColsClass(count) {
  return GRID_COLS_MAP[count] ?? FALLBACK;
}
// ────────────────────────────────────────────────────────────────────

describe('GRID_COLS_MAP — 등록 개수별 Tailwind 열 클래스', () => {
  it('1개 → 1열 (grid-cols-1)', () => {
    expect(gridColsClass(1)).toBe('grid-cols-1');
  });

  it('2개 → 2열 (md:grid-cols-2)', () => {
    expect(gridColsClass(2)).toBe('grid-cols-1 md:grid-cols-2');
  });

  it('3개 → 3열 (lg:grid-cols-3)', () => {
    expect(gridColsClass(3)).toBe('grid-cols-1 md:grid-cols-2 lg:grid-cols-3');
  });

  it('[핵심] 4개 → 2열 (2×2 그리드, lg:grid-cols-3 아님)', () => {
    const cls = gridColsClass(4);
    expect(cls).toBe('grid-cols-1 md:grid-cols-2');
    // lg:grid-cols-3 가 포함되면 안 됨
    expect(cls).not.toContain('lg:grid-cols-3');
  });

  it('5개 → 3열 (lg:grid-cols-3)', () => {
    expect(gridColsClass(5)).toBe('grid-cols-1 md:grid-cols-2 lg:grid-cols-3');
  });

  it('6개 → 3열 (lg:grid-cols-3)', () => {
    expect(gridColsClass(6)).toBe('grid-cols-1 md:grid-cols-2 lg:grid-cols-3');
  });

  it('0개 → 폴백(3열)', () => {
    expect(gridColsClass(0)).toBe(FALLBACK);
  });

  it('7개 이상(7) → 폴백(3열)', () => {
    expect(gridColsClass(7)).toBe(FALLBACK);
  });

  it('undefined → 폴백(3열)', () => {
    expect(gridColsClass(undefined)).toBe(FALLBACK);
  });

  it('null → 폴백(3열)', () => {
    expect(gridColsClass(null)).toBe(FALLBACK);
  });
});

describe('GRID_COLS_MAP — 4개만 2열, 나머지 규칙 일관성', () => {
  it('1·4는 lg:grid-cols-3 을 포함하지 않는다', () => {
    expect(gridColsClass(1)).not.toContain('lg:grid-cols-3');
    expect(gridColsClass(4)).not.toContain('lg:grid-cols-3');
  });

  it('3·5·6 및 폴백은 lg:grid-cols-3 을 포함한다', () => {
    expect(gridColsClass(3)).toContain('lg:grid-cols-3');
    expect(gridColsClass(5)).toContain('lg:grid-cols-3');
    expect(gridColsClass(6)).toContain('lg:grid-cols-3');
    expect(gridColsClass(0)).toContain('lg:grid-cols-3');
  });

  it('2·4는 md:grid-cols-2 를 포함하고 lg:grid-cols-3 은 없다', () => {
    [2, 4].forEach(n => {
      const cls = gridColsClass(n);
      expect(cls).toContain('md:grid-cols-2');
      expect(cls).not.toContain('lg:grid-cols-3');
    });
  });

  it('모든 케이스가 grid-cols-1 으로 시작한다 (모바일 기본 1열)', () => {
    [1, 2, 3, 4, 5, 6].forEach(n => {
      expect(gridColsClass(n)).toMatch(/^grid-cols-1/);
    });
    expect(FALLBACK).toMatch(/^grid-cols-1/);
  });
});
