/**
 * useConfirm — 전역 커스텀 confirm 다이얼로그 (Promise 기반)
 *
 * 사용법:
 *   import { confirm as confirmDialog } from '@/composables/useConfirm.js';
 *   const ok = await confirmDialog({ message: '정말 삭제할까요?', danger: true, confirmText: '삭제' });
 *   if (!ok) return;
 *
 * ConfirmDialog.vue 가 App.vue 에 단 한 번 마운트되어 이 상태를 구독한다.
 */

import { reactive } from 'vue';

// 모듈 스코프 싱글톤 — 여러 컴포넌트에서 import 해도 동일 상태 공유
const state = reactive({
  show: false,
  title: '',
  message: '',
  confirmText: '확인',
  cancelText: '취소',
  danger: false,
  resolve: null, // Promise resolve 핸들
});

/**
 * 커스텀 confirm 다이얼로그를 띄우고 사용자 응답을 Promise<boolean>으로 반환한다.
 *
 * @param {object} options
 * @param {string} [options.title]        - 모달 제목 (생략 시 미표시)
 * @param {string}  options.message       - 본문 메시지 (\n 은 줄바꿈으로 표시)
 * @param {string} [options.confirmText]  - 확인 버튼 레이블 (기본 '확인')
 * @param {string} [options.cancelText]   - 취소 버튼 레이블 (기본 '취소')
 * @param {boolean} [options.danger]      - true 면 확인 버튼을 btn-error 스타일로
 * @returns {Promise<boolean>}
 */
export function confirm(options = {}) {
  return new Promise((resolve) => {
    state.title = options.title ?? '';
    state.message = options.message ?? '';
    state.confirmText = options.confirmText ?? '확인';
    state.cancelText = options.cancelText ?? '취소';
    state.danger = options.danger ?? false;
    state.resolve = resolve;
    state.show = true;
  });
}

/** ConfirmDialog.vue 에서 사용 — 상태 구독 전용 */
export function useConfirmState() {
  return state;
}

/** 확인 선택 — ConfirmDialog.vue 에서 호출 */
export function resolveConfirm(value) {
  if (typeof state.resolve === 'function') {
    state.resolve(value);
  }
  state.show = false;
  state.resolve = null;
}
