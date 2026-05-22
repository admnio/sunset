<script setup>
/**
 * Kicker — small violet uppercase label with leading icon, used above
 * detail-page titles (e.g. "⚡ JOB CLASS" above the class name).
 *
 *   <Kicker icon="i-zap">Job class</Kicker>
 *   <Kicker icon="i-tag">Monitored tag</Kicker>
 */
defineProps({
  icon: { type: String, default: null },
});
</script>

<template>
  <div class="kicker">
    <svg v-if="icon" aria-hidden="true"><use :href="`#${icon}`"/></svg>
    <slot />
  </div>
</template>
