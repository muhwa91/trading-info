/**
 * format.js 단위 테스트
 *
 * 커버:
 *   formatWon, formatProfitWon, formatProfitUSD,
 *   formatProfitRate (×100 회귀 방지),
 *   formatQuantity, formatPrice,
 *   profitColorClass, profitBadgeClass,
 *   displayName
 *
 * 외부 의존 없음 — 순수 함수만 테스트.
 */

import { describe, it, expect } from 'vitest';
import {
  formatWon,
  formatProfitWon,
  formatProfitUSD,
  formatProfitRate,
  formatQuantity,
  formatPrice,
  profitColorClass,
  profitBadgeClass,
  displayName,
} from './format.js';

// displayName 테스트용 최소 SEARCHABLE_STOCKS 스텁
const STUB_STOCKS = [
  { ticker: 'AAPL',      koName: '애플',              enName: 'Apple Inc.',     chosung: 'ㅇㅍ' },
  { ticker: 'MU',        koName: '마이크론 테크놀로지', enName: 'Micron Technology', chosung: 'ㅁㅇㅋㄹㅌㅋㄴㄹㅈ' },
  { ticker: 'TSLA',      koName: '테슬라',            enName: 'Tesla, Inc.',    chosung: 'ㅌㅅㄹ' },
  { ticker: '005930.KS', koName: '삼성전자',          enName: 'Samsung Electronics', chosung: 'ㅅㅅㅈㅈ' },
];

// ──────────────────────────────────────────────────────────────────
// formatWon
// ──────────────────────────────────────────────────────────────────

describe('formatWon', () => {
  it('숫자 → 천단위 콤마 + 원 접미사', () => {
    expect(formatWon(1234567)).toBe('1,234,567원');
  });

  it('0 → "0원"', () => {
    expect(formatWon(0)).toBe('0원');
  });

  it('소수 → 반올림 정수 + 원', () => {
    expect(formatWon(100.7)).toBe('101원');
    expect(formatWon(100.4)).toBe('100원');
  });

  it('null → "—"', () => {
    expect(formatWon(null)).toBe('—');
  });

  it('undefined → "—"', () => {
    expect(formatWon(undefined)).toBe('—');
  });

  it('문자열 숫자 → 정상 변환', () => {
    expect(formatWon('50000')).toBe('50,000원');
  });

  it('음수 → 음수 표기 + 원', () => {
    // 음수는 toLocaleString이 -1,000원 형식으로 반환
    expect(formatWon(-1000)).toBe('-1,000원');
  });
});

// ──────────────────────────────────────────────────────────────────
// formatProfitWon
// ──────────────────────────────────────────────────────────────────

describe('formatProfitWon', () => {
  it('양수 → "+N,NNN원"', () => {
    expect(formatProfitWon(30000)).toBe('+30,000원');
  });

  it('음수 → "-N,NNN원"', () => {
    expect(formatProfitWon(-5000)).toBe('-5,000원');
  });

  it('0 → "+0원" (양수 부호)', () => {
    expect(formatProfitWon(0)).toBe('+0원');
  });

  it('null → "—"', () => {
    expect(formatProfitWon(null)).toBe('—');
  });

  it('undefined → "—"', () => {
    expect(formatProfitWon(undefined)).toBe('—');
  });

  it('소수 → 반올림 후 부호', () => {
    expect(formatProfitWon(1234.6)).toBe('+1,235원');
  });
});

// ──────────────────────────────────────────────────────────────────
// formatProfitUSD
// ──────────────────────────────────────────────────────────────────

describe('formatProfitUSD', () => {
  it('양수 → "+N.NN$"', () => {
    expect(formatProfitUSD(12.5)).toBe('+12.50$');
  });

  it('음수 → "-N.NN$"', () => {
    expect(formatProfitUSD(-3.99)).toBe('-3.99$');
  });

  it('0 → "+0.00$"', () => {
    expect(formatProfitUSD(0)).toBe('+0.00$');
  });

  it('null → "—"', () => {
    expect(formatProfitUSD(null)).toBe('—');
  });

  it('undefined → "—"', () => {
    expect(formatProfitUSD(undefined)).toBe('—');
  });

  it('소수점 2자리 고정', () => {
    expect(formatProfitUSD(100)).toBe('+100.00$');
    expect(formatProfitUSD(-0.1)).toBe('-0.10$');
  });
});

// ──────────────────────────────────────────────────────────────────
// formatProfitRate  ← 핵심 회귀 방지 케이스
// ──────────────────────────────────────────────────────────────────

describe('formatProfitRate', () => {
  it('[회귀] 0.0309 → "+3.09%" (×100 처리)', () => {
    // 과거 버그: 0.0309를 그대로 "%"로 붙여 "+0.03%"로 출력했음
    // 현재 구현은 ×100 후 toFixed(2) 적용
    expect(formatProfitRate(0.0309)).toBe('+3.09%');
  });

  it('[회귀] 0.1 → "+10.00%"', () => {
    expect(formatProfitRate(0.1)).toBe('+10.00%');
  });

  it('양수 비율 → "+N.NN%"', () => {
    expect(formatProfitRate(0.05)).toBe('+5.00%');
  });

  it('음수 비율 → "-N.NN%"', () => {
    expect(formatProfitRate(-0.03)).toBe('-3.00%');
  });

  it('0 → "+0.00%"', () => {
    expect(formatProfitRate(0)).toBe('+0.00%');
  });

  it('null → "—"', () => {
    expect(formatProfitRate(null)).toBe('—');
  });

  it('undefined → "—"', () => {
    expect(formatProfitRate(undefined)).toBe('—');
  });

  it('-0.1234 → "-12.34%"', () => {
    expect(formatProfitRate(-0.1234)).toBe('-12.34%');
  });

  it('대형 양수 1.0 → "+100.00%"', () => {
    expect(formatProfitRate(1.0)).toBe('+100.00%');
  });
});

// ──────────────────────────────────────────────────────────────────
// formatQuantity
// ──────────────────────────────────────────────────────────────────

describe('formatQuantity', () => {
  it('정수 → 그대로', () => {
    expect(formatQuantity(10)).toBe('10');
  });

  it('소수 → 반올림 정수', () => {
    expect(formatQuantity(10.7)).toBe('11');
    expect(formatQuantity(10.4)).toBe('10');
  });

  it('대형 수 → 천단위 콤마', () => {
    expect(formatQuantity(1000)).toBe('1,000');
  });

  it('null → "—"', () => {
    expect(formatQuantity(null)).toBe('—');
  });

  it('undefined → "—"', () => {
    expect(formatQuantity(undefined)).toBe('—');
  });

  it('0 → "0"', () => {
    expect(formatQuantity(0)).toBe('0');
  });
});

// ──────────────────────────────────────────────────────────────────
// formatPrice
// ──────────────────────────────────────────────────────────────────

describe('formatPrice', () => {
  it('KRW → "N,NNN원"', () => {
    expect(formatPrice('KRW', 75000)).toBe('75,000원');
  });

  it('USD → "N.NN$"', () => {
    expect(formatPrice('USD', 210.5)).toBe('210.50$');
  });

  it('null value → "—" (KRW)', () => {
    expect(formatPrice('KRW', null)).toBe('—');
  });

  it('null value → "—" (USD)', () => {
    expect(formatPrice('USD', null)).toBe('—');
  });

  it('undefined value → "—"', () => {
    expect(formatPrice('USD', undefined)).toBe('—');
  });

  it('USD 정수도 소수점 2자리', () => {
    expect(formatPrice('USD', 100)).toBe('100.00$');
  });

  it('KRW 반올림', () => {
    expect(formatPrice('KRW', 75000.6)).toBe('75,001원');
  });

  it('KRW 0 → "0원"', () => {
    expect(formatPrice('KRW', 0)).toBe('0원');
  });
});

// ──────────────────────────────────────────────────────────────────
// profitColorClass
// ──────────────────────────────────────────────────────────────────

describe('profitColorClass', () => {
  // 국내·미국 구분 없이 상승=rose-400, 하락=sky-400 으로 통일
  it('KR 상승 → text-rose-400', () => {
    expect(profitColorClass(1000, 'kr')).toBe('text-rose-400');
  });

  it('KR 하락 → text-sky-400', () => {
    expect(profitColorClass(-1000, 'kr')).toBe('text-sky-400');
  });

  it('KR 0 → text-rose-400 (0은 상승 처리)', () => {
    expect(profitColorClass(0, 'kr')).toBe('text-rose-400');
  });

  // US 시장도 동일: 상승=rose, 하락=sky (market 인자 무시)
  it('US 상승 → text-rose-400 (통일)', () => {
    expect(profitColorClass(500, 'us')).toBe('text-rose-400');
  });

  it('US 하락 → text-sky-400 (통일)', () => {
    expect(profitColorClass(-500, 'us')).toBe('text-sky-400');
  });

  it('US 0 → text-rose-400 (0은 상승 처리, 통일)', () => {
    expect(profitColorClass(0, 'us')).toBe('text-rose-400');
  });

  // NaN: Number(null)=0 이므로 상승 처리됨. 실제 NaN은 Number('abc') 등.
  it('null → Number(null)=0 → 상승 처리(text-rose-400)', () => {
    // null은 Number()로 0이 되어 isNaN(0)=false, 0>=0 → 상승 색
    expect(profitColorClass(null, 'kr')).toBe('text-rose-400');
  });

  it('문자열(비숫자) → NaN → text-base-content/60', () => {
    expect(profitColorClass('abc', 'kr')).toBe('text-base-content/60');
  });

  it('undefined → NaN → text-base-content/60', () => {
    expect(profitColorClass(undefined, 'us')).toBe('text-base-content/60');
  });
});

// ──────────────────────────────────────────────────────────────────
// profitBadgeClass
// ──────────────────────────────────────────────────────────────────

describe('profitBadgeClass', () => {
  // 국내·미국 구분 없이 상승=badge-kr-up(빨강), 하락=badge-kr-down(파랑)으로 통일
  it('KR 상승 → badge-kr-up', () => {
    expect(profitBadgeClass(1000, 'kr')).toBe('badge-kr-up');
  });

  it('KR 하락 → badge-kr-down', () => {
    expect(profitBadgeClass(-1000, 'kr')).toBe('badge-kr-down');
  });

  it('US 상승 → badge-kr-up (통일)', () => {
    expect(profitBadgeClass(1000, 'us')).toBe('badge-kr-up');
  });

  it('US 하락 → badge-kr-down (통일)', () => {
    expect(profitBadgeClass(-1000, 'us')).toBe('badge-kr-down');
  });

  it('null → Number(null)=0 → 상승 badge-kr-up (0은 상승 처리)', () => {
    // Number(null)=0, isNaN(0)=false → badge-kr-up
    expect(profitBadgeClass(null, 'kr')).toBe('badge-kr-up');
  });

  it('문자열(비숫자) → NaN → 비활성 클래스', () => {
    expect(profitBadgeClass('abc', 'kr')).toBe('text-base-content/30 bg-base-200/40');
  });

  it('KR 0 → badge-kr-up (0은 상승)', () => {
    expect(profitBadgeClass(0, 'kr')).toBe('badge-kr-up');
  });
});

// ──────────────────────────────────────────────────────────────────
// displayName
// ──────────────────────────────────────────────────────────────────

describe('displayName', () => {
  it('US 종목 + stocks 배열 있으면 한글명 역조회', () => {
    const item = { symbol: 'AAPL', market: 'US', name: 'Apple Inc.' };
    expect(displayName(item, STUB_STOCKS)).toBe('애플');
  });

  it('US 종목 소문자 심볼도 대소문자 무관 매칭', () => {
    const item = { symbol: 'aapl', market: 'US', name: 'Apple Inc.' };
    expect(displayName(item, STUB_STOCKS)).toBe('애플');
  });

  it('US 종목 stocks 배열에 없으면 item.name 폴백', () => {
    const item = { symbol: 'XYZ', market: 'US', name: '알수없는회사' };
    expect(displayName(item, STUB_STOCKS)).toBe('알수없는회사');
  });

  it('US 종목 name도 없으면 symbol 폴백', () => {
    const item = { symbol: 'XYZ', market: 'US', name: undefined };
    expect(displayName(item, STUB_STOCKS)).toBe('XYZ');
  });

  it('KR 종목은 stocks 배열 무시하고 item.name 반환', () => {
    // KR 종목은 역조회 안 함
    const item = { symbol: '005930', market: 'KR', name: '삼성전자' };
    expect(displayName(item, STUB_STOCKS)).toBe('삼성전자');
  });

  it('KR 종목 name 없으면 symbol 폴백', () => {
    const item = { symbol: '005930', market: 'KR', name: null };
    expect(displayName(item, STUB_STOCKS)).toBe('005930');
  });

  it('stocks 배열 null이어도 크래시 없음 (US)', () => {
    const item = { symbol: 'TSLA', market: 'US', name: 'Tesla' };
    expect(displayName(item, null)).toBe('Tesla');
  });

  it('item 자체가 null → 빈 문자열', () => {
    expect(displayName(null, STUB_STOCKS)).toBe('');
  });

  it('MU → 마이크론 테크놀로지 역조회', () => {
    const item = { symbol: 'MU', market: 'US', name: 'Micron Technology, Inc.' };
    expect(displayName(item, STUB_STOCKS)).toBe('마이크론 테크놀로지');
  });
});
