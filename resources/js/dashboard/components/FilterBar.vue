<script setup>
/**
 * FilterBar — wraps a row of filter controls above a table or list.
 *
 *   <FilterBar :count="5" count-label="of 37 shown">
 *     <template #search>
 *       <SearchInput v-model="q" placeholder="Filter by class…" />
 *     </template>
 *     <template #range>
 *       <RangeGroup v-model="range" :options="['1h','6h','24h','7d']" />
 *     </template>
 *     <template #right>
 *       <button class="btn sm">Export CSV</button>
 *     </template>
 *   </FilterBar>
 *
 * Slots
 *   #search   — left edge (typically SearchInput)
 *   #range    — middle-left (typically RangeGroup)
 *   default   — between range and the count/right cluster
 *   #right    — pushed to the far right
 *
 * When `count` is non-null, a small monospaced pill shows it. `countLabel`
 * is an optional descriptive suffix (e.g. "of 37 shown").
 */
defineProps({
  count:      { type: [Number, null], default: null },
  countLabel: { type: String, default: '' },
});

import { useSlots, computed } from 'vue';
const slots = useSlots();
const hasRight = computed(() => !!slots.right);
</script>

<template>
  <div class="filter-bar">
    <slot name="search" />
    <slot name="range" />
    <slot />
    <span v-if="count !== null && count !== undefined" class="filter-count">
      {{ count }}<template v-if="countLabel"> {{ countLabel }}</template>
    </span>
    <span v-if="hasRight" class="ml-auto inline-flex items-center gap-2">
      <slot name="right" />
    </span>
  </div>
</template>
