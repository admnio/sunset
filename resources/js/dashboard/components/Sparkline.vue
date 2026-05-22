<script setup>
/**
 * Sparkline — inline mini line chart.
 *
 * Props:
 *   points  number[]   — values; normalized 0..1 by default, or auto-scaled
 *                        when any value exceeds 1.
 *   area    boolean    — fill below the line with currentColor at 0.15 alpha.
 *   color   string     — color name: violet | green | red | amber | blue | muted.
 *                        Resolves through Tailwind `text-{color}` so currentColor
 *                        is theme-aware.
 *   axis    boolean    — when true, render start/end labels under the chart
 *                        via the `axis-start` / `axis-end` slots.
 *   tall    boolean    — render at 28px height instead of 18px.
 *   width   number     — viewBox width  (default 72)
 *   height  number     — viewBox height (default 18)
 */
import { computed } from 'vue';

const props = defineProps({
  points: { type: Array, default: () => [] },
  area:   { type: Boolean, default: false },
  color:  { type: String, default: 'violet' },
  axis:   { type: Boolean, default: false },
  tall:   { type: Boolean, default: false },
  width:  { type: Number, default: 72 },
  height: { type: Number, default: 18 },
});

const COLOR_CLASS = {
  violet: 'text-violet',
  green:  'text-green',
  red:    'text-red',
  amber:  'text-amber',
  blue:   'text-blue',
  muted:  'text-muted',
};

const W = computed(() => props.width);
const H = computed(() => (props.tall ? 28 : props.height));

// Auto-detect whether to normalize: if max > 1 we scale to the data range.
const scaled = computed(() => {
  const pts = props.points;
  if (! pts.length) return [];
  const max = Math.max(...pts);
  const min = Math.min(...pts);
  if (max <= 1 && min >= 0) return pts.slice();
  const span = max - min || 1;
  return pts.map((p) => (p - min) / span);
});

const linePoints = computed(() => {
  const pts = scaled.value;
  if (! pts.length) return '';
  return pts
    .map((p, i) => {
      const x = (i / Math.max(1, pts.length - 1)) * W.value;
      const y = H.value - (p * H.value);
      return `${x.toFixed(2)},${y.toFixed(2)}`;
    })
    .join(' ');
});

const areaPath = computed(() => {
  const pts = scaled.value;
  if (! pts.length) return '';
  const seg = pts
    .map((p, i) => {
      const x = (i / Math.max(1, pts.length - 1)) * W.value;
      const y = H.value - (p * H.value);
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`;
    })
    .join(' ');
  return `${seg} L${W.value},${H.value} L0,${H.value} Z`;
});

const colorClass = computed(() => COLOR_CLASS[props.color] || COLOR_CLASS.violet);
</script>

<template>
  <div :class="['inline-block align-middle', axis ? 'w-full' : '']">
    <svg
      :class="['spark', colorClass, tall ? 'spark-tall' : 'spark-mini', axis ? 'w-full' : '']"
      :viewBox="`0 0 ${W} ${H}`"
      preserveAspectRatio="none"
      :style="axis ? { width: '100%', height: H + 'px' } : null"
    >
      <path v-if="area && areaPath" class="area" :d="areaPath" />
      <polyline v-if="linePoints" class="line" :points="linePoints" />
    </svg>
    <div v-if="axis" class="flex justify-between font-mono text-[10.5px] text-dim mt-1 tracking-wide">
      <span><slot name="axis-start" /></span>
      <span><slot name="axis-end" /></span>
    </div>
  </div>
</template>
