<template>
  <Transition name="fade">
    <div
      v-if="state.show"
      class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/55 backdrop-blur-sm p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="state.title || '확인'"
      @click.self="onCancel"
      @keydown.esc="onCancel"
      tabindex="-1"
      ref="dialogEl"
    >
      <div class="bg-base-200 border border-base-content/12 rounded-2xl p-5 w-full max-w-sm shadow-2xl flex flex-col gap-4 font-sans relative">

        <!-- 헤더 (제목이 있을 때만 표시) -->
        <div
          v-if="state.title"
          class="flex items-center justify-between border-b border-base-content/8 pb-3"
        >
          <h3 class="text-sm font-black text-white">{{ state.title }}</h3>
          <button
            @click="onCancel"
            class="w-7 h-7 flex items-center justify-center rounded-lg text-base-content/40 hover:text-white hover:bg-base-300/60 transition-all duration-150 cursor-pointer"
            aria-label="모달 닫기"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- 본문 메시지 (\n 줄바꿈 유지) -->
        <p class="text-sm text-base-content/80 font-semibold whitespace-pre-line leading-relaxed">
          {{ state.message }}
        </p>

        <!-- 액션 버튼 (우측 하단: 취소 | 확인) -->
        <div class="flex items-center justify-end gap-2 border-t border-base-content/8 pt-3 mt-1">
          <button
            @click="onCancel"
            class="btn btn-xs btn-ghost text-base-content/50 cursor-pointer font-bold"
          >{{ state.cancelText }}</button>
          <button
            @click="onConfirm"
            :class="[
              'btn btn-xs cursor-pointer px-4 font-bold',
              state.danger ? 'btn-error' : 'btn-primary'
            ]"
            ref="confirmBtn"
          >{{ state.confirmText }}</button>
        </div>

      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';
import { useConfirmState, resolveConfirm } from '../composables/useConfirm.js';

const state = useConfirmState();
const dialogEl = ref(null);
const confirmBtn = ref(null);

// 모달이 열릴 때 포커스를 확인 버튼으로 이동 (키보드 접근성)
watch(
  () => state.show,
  async (val) => {
    if (val) {
      await nextTick();
      confirmBtn.value?.focus();
    }
  }
);

function onConfirm() {
  resolveConfirm(true);
}

function onCancel() {
  resolveConfirm(false);
}
</script>
