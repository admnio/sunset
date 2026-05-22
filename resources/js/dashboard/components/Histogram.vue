<script setup>
/**
 * Histogram — horizontal-bar bucket renderer.
 *
 * Buckets shape: [{ label, count, pct, danger? }, ...]
 *   label  string  — e.g. '0–50ms'
 *   count  number  — absolute count (rendered right-aligned)
 *   pct    number  — bar width in %, 0–100
 *   danger bool    — when true, uses the red gradient
 *
 * Bars with `pct > 0 && pct < 2` are clamped to a 4px minimum so very
 * small buckets still appear visible.
 */
defineProps({
  buckets: { type: Array, required: true },
  total:   { type: Number, default: null },
});

function barStyle(pct) {
  if (pct <= 0) return { width: '0%' };
  if (pct < 2) return { width: '4px' };
  return { width: pct + '%' };
}
</script>

<template>
  <div class="hist">
    <div v-for="(b, i) in buckets" :key="b.label || i" class="hist-row">
      <div class="bucket">{{ b.label }}</div>
      <div class="bar-track">
        <div :class="['bar-fill', b.danger ? 'danger' : '']" :style="barStyle(b.pct)" />
      </div>
      <div class="count">
        {{ b.count?.toLocaleString?.() ?? b.count }}<span class="pct">{{ Math.round(b.pct) }}%</span>
      </div>
    </div>
    <div v-if="buckets.length === 0" class="text-center text-muted text-xs py-4">No buckets</div>
  </div>
</template>
