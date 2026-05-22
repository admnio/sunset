<!--
  ToastContainer — renders the `.toasts` fixed stack. Consumes the
  singleton `useToasts()` composable; mount once in Layout.vue.

  Clicking the "Undo" button on a destructive toast emits an `undo`
  event with the toast id and then dismisses the card. Parents that
  want to hook into undo must subscribe via the composable's id
  (returned by `push()`), not via this component, since toasts are
  fired from many disparate call sites.
-->
<script setup>
import { useToasts } from '../composables/useToasts.js';

const { toasts, dismiss } = useToasts();

// Mockup icon mapping — keep aligned with toast variants.
function iconFor(kind) {
  if (kind === 'ok')   return '#i-check';
  if (kind === 'warn') return '#i-alert';
  if (kind === 'err')  return '#i-alert';
  return '#i-info';
}

const emit = defineEmits(['undo']);

function onUndo(t) {
  emit('undo', t.id);
  dismiss(t.id);
}
</script>

<template>
  <div class="toasts" role="region" aria-live="polite" aria-label="Notifications">
    <div
      v-for="t in toasts"
      :key="t.id"
      :class="['toast', t.kind]"
      role="status"
    >
      <svg class="icon" aria-hidden="true"><use :href="iconFor(t.kind)"/></svg>
      <div class="body">
        <div class="t-title">{{ t.title }}</div>
        <div v-if="t.sub" class="t-sub">{{ t.sub }}</div>
      </div>
      <button
        v-if="t.undo"
        type="button"
        class="undo"
        @click="onUndo(t)"
      >Undo</button>
    </div>
  </div>
</template>
