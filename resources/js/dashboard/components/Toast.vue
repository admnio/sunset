<script setup>
defineProps({
  message: { type: String, required: true },
  type:    { type: String, default: 'info' },  // 'info' | 'error' | 'success'
});

const classes = {
  info:    'toast-info',
  error:   'toast-error',
  success: 'toast-success',
};
</script>

<template>
  <div
    role="status"
    aria-live="polite"
    :class="['fixed bottom-4 right-4 px-3 py-2 rounded text-xs font-mono shadow-lg z-50', classes[type] || classes.info]"
  >
    {{ message }}
  </div>
</template>
