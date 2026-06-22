/**
 * useConfirm 단위 테스트
 *
 * 커버:
 *   - confirm(): Promise<boolean> 반환, 옵션 기본값 적용
 *   - confirm() → resolveConfirm(true): Promise가 true로 resolve
 *   - confirm() → resolveConfirm(false): Promise가 false로 resolve
 *   - resolveConfirm 후 state.show = false, state.resolve = null (상태 정리)
 *   - confirm() 중 state.show = true (모달 열림)
 *   - 옵션 개별 지정: title, message, confirmText, cancelText, danger
 *   - resolveConfirm을 두 번 호출해도 두 번째는 무시(resolve가 null이므로)
 *   - 연속 confirm() 호출 시 마지막 옵션으로 덮어쓰기
 *
 * 참고: lightweight-charts·DOM·@vue/test-utils 의존 없음.
 *   Vue reactive는 순수 JS 모듈이라 Node/vitest 환경에서 직접 import 가능.
 *   단, 모듈이 싱글톤 state를 가지므로 각 테스트 후 resolveConfirm(false)로
 *   state를 정리한다.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { confirm, useConfirmState, resolveConfirm } from './useConfirm.js';

// 각 테스트 후 싱글톤 state 정리 (모달이 열린 채 남아있으면 다음 테스트에 영향)
afterEach(() => {
  const state = useConfirmState();
  if (state.show) {
    resolveConfirm(false);
  }
});

// ──────────────────────────────────────────────────────────────────
// confirm() — Promise 반환 및 상태 변화
// ──────────────────────────────────────────────────────────────────

describe('confirm() — Promise 반환 및 상태', () => {
  it('Promise<boolean>을 반환한다', () => {
    const result = confirm({ message: '테스트' });
    expect(result).toBeInstanceOf(Promise);
    // 열린 모달 정리
    resolveConfirm(false);
  });

  it('호출 즉시 state.show가 true로 바뀐다', () => {
    const state = useConfirmState();
    expect(state.show).toBe(false);
    confirm({ message: '확인?' });
    expect(state.show).toBe(true);
    resolveConfirm(false);
  });

  it('state.resolve가 함수로 설정된다', () => {
    const state = useConfirmState();
    confirm({ message: '확인?' });
    expect(typeof state.resolve).toBe('function');
    resolveConfirm(false);
  });
});

// ──────────────────────────────────────────────────────────────────
// confirm() — 옵션 기본값
// ──────────────────────────────────────────────────────────────────

describe('confirm() — 옵션 기본값', () => {
  it('빈 옵션으로 호출하면 기본값이 적용된다', () => {
    const state = useConfirmState();
    confirm();
    expect(state.title).toBe('');
    expect(state.message).toBe('');
    expect(state.confirmText).toBe('확인');
    expect(state.cancelText).toBe('취소');
    expect(state.danger).toBe(false);
    resolveConfirm(false);
  });

  it('옵션을 지정하면 state에 반영된다', () => {
    const state = useConfirmState();
    confirm({
      title: '삭제 확인',
      message: '정말 삭제할까요?',
      confirmText: '삭제',
      cancelText: '아니오',
      danger: true,
    });
    expect(state.title).toBe('삭제 확인');
    expect(state.message).toBe('정말 삭제할까요?');
    expect(state.confirmText).toBe('삭제');
    expect(state.cancelText).toBe('아니오');
    expect(state.danger).toBe(true);
    resolveConfirm(false);
  });

  it('일부 옵션만 지정하면 나머지는 기본값', () => {
    const state = useConfirmState();
    confirm({ message: '부분 옵션', danger: true });
    expect(state.title).toBe('');
    expect(state.message).toBe('부분 옵션');
    expect(state.confirmText).toBe('확인');
    expect(state.cancelText).toBe('취소');
    expect(state.danger).toBe(true);
    resolveConfirm(false);
  });
});

// ──────────────────────────────────────────────────────────────────
// resolveConfirm(true) — 확인 선택
// ──────────────────────────────────────────────────────────────────

describe('resolveConfirm(true) — 확인 선택', () => {
  it('Promise가 true로 resolve된다', async () => {
    const p = confirm({ message: '확인?' });
    resolveConfirm(true);
    expect(await p).toBe(true);
  });

  it('resolve 후 state.show가 false로 정리된다', async () => {
    const state = useConfirmState();
    confirm({ message: '확인?' });
    expect(state.show).toBe(true);
    resolveConfirm(true);
    expect(state.show).toBe(false);
  });

  it('resolve 후 state.resolve가 null로 정리된다', async () => {
    const state = useConfirmState();
    confirm({ message: '확인?' });
    resolveConfirm(true);
    expect(state.resolve).toBeNull();
  });
});

// ──────────────────────────────────────────────────────────────────
// resolveConfirm(false) — 취소 선택
// ──────────────────────────────────────────────────────────────────

describe('resolveConfirm(false) — 취소 선택', () => {
  it('Promise가 false로 resolve된다', async () => {
    const p = confirm({ message: '취소?' });
    resolveConfirm(false);
    expect(await p).toBe(false);
  });

  it('취소 후 state.show가 false로 정리된다', () => {
    const state = useConfirmState();
    confirm({ message: '취소?' });
    resolveConfirm(false);
    expect(state.show).toBe(false);
  });

  it('취소 후 state.resolve가 null로 정리된다', () => {
    const state = useConfirmState();
    confirm({ message: '취소?' });
    resolveConfirm(false);
    expect(state.resolve).toBeNull();
  });
});

// ──────────────────────────────────────────────────────────────────
// resolveConfirm 중복 호출 방어
// ──────────────────────────────────────────────────────────────────

describe('resolveConfirm — 중복 호출 방어', () => {
  it('resolveConfirm을 두 번 호출해도 두 번째는 무시된다 (크래시 없음)', async () => {
    const p = confirm({ message: '중복?' });
    resolveConfirm(true);
    // 두 번째 호출 — state.resolve가 null이므로 조용히 무시
    expect(() => resolveConfirm(false)).not.toThrow();
    expect(await p).toBe(true); // 첫 번째 결과 유지
  });
});

// ──────────────────────────────────────────────────────────────────
// confirm() 연속 호출 — 싱글톤 상태 덮어쓰기
// ──────────────────────────────────────────────────────────────────

describe('confirm() 연속 호출', () => {
  it('두 번째 confirm()이 옵션을 덮어쓴다', () => {
    const state = useConfirmState();
    // 첫 번째 confirm (resolve 하지 않고)
    confirm({ message: '첫 번째', confirmText: '예' });
    expect(state.message).toBe('첫 번째');

    // 두 번째 confirm — 이전 Promise의 resolve가 덮어써짐
    // (실제 사용에서는 동시 호출이 일어나지 않아야 하지만 방어적 검증)
    confirm({ message: '두 번째', confirmText: '확인' });
    expect(state.message).toBe('두 번째');
    expect(state.confirmText).toBe('확인');
    resolveConfirm(false);
  });
});
