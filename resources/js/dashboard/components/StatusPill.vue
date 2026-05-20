<script setup>
defineProps({
  status: { type: String, default: 'info' },  // 'failed' | 'ok' | 'warn' | 'info'
});

const cls = {
  failed: 'pill-error',
  ok:     'pill-ok',
  warn:   'pill-warn',
  info:   'pill-info',
};
</script>

<template>
  <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-mono uppercase', cls[status] || cls.info]">
    <slot>{{ status }}</slot>
  </span>
</template>
