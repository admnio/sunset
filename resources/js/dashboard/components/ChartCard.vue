<script setup>
/**
 * ChartCard — full-width chart wrapper with optional axis tick labels.
 *
 * Usage:
 *   <ChartCard :axis="['03:22', '03:42', '04:02', '04:22 now']">
 *     <svg viewBox="0 0 600 160" class="full-spark">…</svg>
 *   </ChartCard>
 *
 * The default slot should contain an `<svg class="full-spark">` (or any
 * element styled to width:100% / height ~160px) — the surrounding
 * `.chart-card` class provides the padded card chrome.
 */
defineProps({
  axis: { type: Array, default: () => [] }, // array of tick labels, evenly spaced
});
</script>

<template>
  <div class="chart-card">
    <slot />
    <div v-if="axis.length" class="chart-axis">
      <span v-for="(tick, i) in axis" :key="i">{{ tick }}</span>
    </div>
  </div>
</template>
