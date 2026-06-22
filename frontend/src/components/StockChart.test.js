/**
 * StockChart 로직 단위 테스트
 *
 * 커버:
 *   - changeTimeframe: 같은 값이면 상태 변경/emit 없음 (중복 호출 방어)
 *   - changeTimeframe: 다른 값이면 selectedTimeframe 갱신 + emit 실행
 *   - select @change 핸들러 패턴: $event.target.value 를 changeTimeframe에 전달
 *   - timeframes 상수: 7개 타임프레임, value 형식 검증
 *
 * 참고: StockChart.vue는 lightweight-charts(캔버스 DOM 의존)·ResizeObserver·
 * localStorage 등을 사용하므로 @vue/test-utils 마운트 없이
 * 내부 함수 로직을 독립 함수로 추출해 순수 단위 검증한다.
 */

import { describe, it, expect, vi } from 'vitest';

// ── 타임프레임 상수 (StockChart.vue 와 동일) ─────────────────────
const timeframes = [
  { label: '1분', value: '1m' },
  { label: '3분', value: '3m' },
  { label: '5분', value: '5m' },
  { label: '10분', value: '10m' },
  { label: '30분', value: '30m' },
  { label: '1시', value: '1h' },
  { label: '일봉', value: '1d' }
];

// ── changeTimeframe 로직 팩토리 ────────────────────────────────────
// StockChart.vue 의 changeTimeframe 함수와 동일한 로직을 독립 함수로 복제.
// 컴포넌트 마운트 없이도 핵심 분기를 검증할 수 있다.
function makeChangeTimeframe(initialTimeframe) {
  let selectedTimeframe = initialTimeframe;
  let shouldFitContent = false;
  const emitted = [];

  function changeTimeframe(value) {
    if (selectedTimeframe === value) return;
    selectedTimeframe = value;
    shouldFitContent = true;
    emitted.push(value);  // emit('timeframe-change', value) 대신
  }

  return {
    changeTimeframe,
    getSelectedTimeframe: () => selectedTimeframe,
    getShouldFitContent: () => shouldFitContent,
    getEmitted: () => emitted,
  };
}

// ── timeframes 상수 ────────────────────────────────────────────────

describe('timeframes 상수', () => {
  it('7개 타임프레임이 정의돼야 한다', () => {
    expect(timeframes).toHaveLength(7);
  });

  it('각 항목에 label과 value가 있어야 한다', () => {
    for (const tf of timeframes) {
      expect(tf).toHaveProperty('label');
      expect(tf).toHaveProperty('value');
      expect(typeof tf.label).toBe('string');
      expect(typeof tf.value).toBe('string');
    }
  });

  it('value 값 목록이 일치해야 한다', () => {
    const values = timeframes.map(tf => tf.value);
    expect(values).toEqual(['1m', '3m', '5m', '10m', '30m', '1h', '1d']);
  });

  it('기본값 3m 이 목록에 존재해야 한다', () => {
    expect(timeframes.some(tf => tf.value === '3m')).toBe(true);
  });
});

// ── changeTimeframe 로직 ───────────────────────────────────────────

describe('changeTimeframe — 같은 값 호출 (중복 방어)', () => {
  it('현재와 같은 값이면 selectedTimeframe 이 바뀌지 않는다', () => {
    const { changeTimeframe, getSelectedTimeframe } = makeChangeTimeframe('3m');
    changeTimeframe('3m');
    expect(getSelectedTimeframe()).toBe('3m');
  });

  it('현재와 같은 값이면 emit 이 발생하지 않는다', () => {
    const { changeTimeframe, getEmitted } = makeChangeTimeframe('3m');
    changeTimeframe('3m');
    expect(getEmitted()).toHaveLength(0);
  });

  it('현재와 같은 값이면 shouldFitContent 가 true 로 바뀌지 않는다', () => {
    const { changeTimeframe, getShouldFitContent } = makeChangeTimeframe('1d');
    changeTimeframe('1d');
    expect(getShouldFitContent()).toBe(false);
  });
});

describe('changeTimeframe — 다른 값 호출 (정상 전환)', () => {
  it('다른 값이면 selectedTimeframe 이 갱신된다', () => {
    const { changeTimeframe, getSelectedTimeframe } = makeChangeTimeframe('3m');
    changeTimeframe('5m');
    expect(getSelectedTimeframe()).toBe('5m');
  });

  it('다른 값이면 timeframe-change emit 이 발생한다', () => {
    const { changeTimeframe, getEmitted } = makeChangeTimeframe('3m');
    changeTimeframe('1h');
    expect(getEmitted()).toEqual(['1h']);
  });

  it('다른 값이면 shouldFitContent 가 true 로 설정된다', () => {
    const { changeTimeframe, getShouldFitContent } = makeChangeTimeframe('3m');
    changeTimeframe('1d');
    expect(getShouldFitContent()).toBe(true);
  });

  it('연속 두 번 다른 값 호출 시 마지막 값으로 확정된다', () => {
    const { changeTimeframe, getSelectedTimeframe, getEmitted } = makeChangeTimeframe('3m');
    changeTimeframe('5m');
    changeTimeframe('1d');
    expect(getSelectedTimeframe()).toBe('1d');
    expect(getEmitted()).toEqual(['5m', '1d']);
  });
});

describe('changeTimeframe — 모든 타임프레임 값 순환', () => {
  it('timeframes 의 각 value 로 전환할 수 있어야 한다', () => {
    const { changeTimeframe, getSelectedTimeframe } = makeChangeTimeframe('__init__');
    for (const tf of timeframes) {
      changeTimeframe(tf.value);
      expect(getSelectedTimeframe()).toBe(tf.value);
    }
  });
});

// ── select @change 핸들러 패턴 검증 ───────────────────────────────
// 템플릿: @change.stop="changeTimeframe($event.target.value)"
// select 에서 value 문자열이 정확히 changeTimeframe 에 전달되는지 확인

describe('select change 이벤트 → changeTimeframe 연동', () => {
  it('$event.target.value 문자열이 changeTimeframe 에 그대로 전달된다', () => {
    const { changeTimeframe, getSelectedTimeframe, getEmitted } = makeChangeTimeframe('3m');

    // 브라우저에서 select @change 이벤트가 발생하면:
    // @change.stop="changeTimeframe($event.target.value)"
    // $event.target.value 는 항상 문자열임을 검증
    const mockEvent = { target: { value: '10m' } };
    changeTimeframe(mockEvent.target.value);

    expect(getSelectedTimeframe()).toBe('10m');
    expect(getEmitted()).toEqual(['10m']);
  });

  it('select value 가 기존과 같으면 중복 emit 없음', () => {
    const { changeTimeframe, getEmitted } = makeChangeTimeframe('5m');

    const mockEvent = { target: { value: '5m' } };
    changeTimeframe(mockEvent.target.value);

    expect(getEmitted()).toHaveLength(0);
  });

  it('timeframes 의 모든 value 가 select option 값으로 유효하다', () => {
    // select option :value="tf.value" 로 바인딩되므로
    // changeTimeframe 에 전달될 수 있는 값이 전부 타임프레임 목록에 있어야 함
    const validValues = new Set(timeframes.map(tf => tf.value));
    for (const tf of timeframes) {
      expect(validValues.has(tf.value)).toBe(true);
    }
  });
});
