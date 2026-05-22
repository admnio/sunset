<script setup>
/**
 * StatusPill — small rounded status indicator with optional leading dot.
 *
 * Status mapping (back-compat preserved for legacy values):
 *   ok | success            → .pill.ok       (green)
 *   warn | warning          → .pill.warn     (amber)
 *   err | error | failed    → .pill.err      (red)
 *   info                    → .pill.info     (violet)
 *   blue                    → .pill.blue     (blue)
 *   neutral                 → .pill.neutral  (muted)
 *
 * The default slot is the label; if absent we fall back to the raw status string.
 */
import { computed } from 'vue';

const props = defineProps({
  status: { type: String, default: 'info' },
  dot:    { type: Boolean, default: true },
});

const VARIANT = {
  ok:      'ok',
  success: 'ok',
  warn:    'warn',
  warning: 'warn',
  err:     'err',
  error:   'err',
  failed:  'err',
  info:    'info',
  blue:    'blue',
  neutral: 'neutral',
};

const variant = computed(() => VARIANT[props.status] || 'info');
</script>

<template>
  <span :class="['pill', variant]">
    <span v-if="dot" class="dot" aria-hidden="true" />
    <slot>{{ status }}</slot>
  </span>
</template>
