<script setup>
/**
 * DataTable — v2 generic table renderer.
 *
 * Column descriptor:
 *   {
 *     key:       string         — row property + slot name
 *     label:     string         — header text
 *     width?:    string         — CSS width (e.g. '90px', '1fr', '20%')
 *     align?:    'left'|'right' — defaults to left; 'right' uses tabular nums
 *     sortable?: 'num'|'text'   — when present, header is clickable
 *   }
 *
 * Props
 *   columns    array  — see above
 *   rows       array  — data rows
 *   selectable bool   — emits `select` on row click (legacy MasterDetail flow)
 *   clickable  bool   — emits `row-click` on row click; adds drill-down chevron
 *                        + violet hover-border-left (mutually exclusive concept
 *                        from `selectable`, but the row-click event fires either
 *                        way for whichever opt-in is enabled).
 *
 * Emits
 *   select     row    — preserved for back-compat with MasterDetail / Workload.
 *   row-click  row    — new v2 emit for drill-down pages.
 *
 * Slots
 *   <col.key>          — per-column cell renderer; default falls back to row[col.key].
 *   header-<col.key>   — per-column header renderer (rare; used for custom labels).
 *   empty              — replacement empty-state when rows.length === 0.
 */
import { ref, computed } from 'vue';

const props = defineProps({
  columns:    { type: Array, required: true },
  rows:       { type: Array, required: true },
  selectable: { type: Boolean, default: true },
  clickable:  { type: Boolean, default: false },
});
const emit = defineEmits(['select', 'row-click']);

const sortKey = ref(null);
const sortDir = ref('asc'); // 'asc' | 'desc'

function toggleSort(col) {
  if (! col.sortable) return;
  if (sortKey.value === col.key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortKey.value = col.key;
    sortDir.value = 'asc';
  }
}

const sortedRows = computed(() => {
  if (! sortKey.value) return props.rows;
  const col = props.columns.find((c) => c.key === sortKey.value);
  if (! col || ! col.sortable) return props.rows;
  const dir = sortDir.value === 'asc' ? 1 : -1;
  const isNum = col.sortable === 'num';
  return [...props.rows].sort((a, b) => {
    const va = a?.[col.key];
    const vb = b?.[col.key];
    if (va === vb) return 0;
    if (va == null) return 1;
    if (vb == null) return -1;
    if (isNum) return (Number(va) - Number(vb)) * dir;
    return String(va).localeCompare(String(vb)) * dir;
  });
});

function headerClass(col) {
  const cls = [];
  if (col.align === 'right') cls.push('r');
  if (col.sortable) {
    cls.push('sortable');
    if (sortKey.value === col.key) cls.push(sortDir.value);
  }
  return cls;
}

function cellClass(col) {
  return col.align === 'right' ? 'r' : '';
}

function onRowClick(row) {
  if (props.clickable) emit('row-click', row);
  if (props.selectable) emit('select', row);
}

const interactive = computed(() => props.clickable || props.selectable);
</script>

<template>
  <div class="table">
    <table>
      <thead>
        <tr>
          <th
            v-for="col in columns"
            :key="col.key"
            :class="headerClass(col)"
            :style="col.width ? { width: col.width } : null"
            @click="toggleSort(col)"
          >
            <slot :name="`header-${col.key}`" :col="col">{{ col.label }}</slot>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="sortedRows.length === 0">
          <td :colspan="columns.length" class="text-center text-muted text-[13px] py-8">
            <slot name="empty">No rows</slot>
          </td>
        </tr>
        <tr
          v-for="(row, i) in sortedRows"
          v-else
          :key="row.id ?? i"
          :class="clickable ? 'clickable' : ''"
          @click="interactive ? onRowClick(row) : null"
        >
          <td v-for="col in columns" :key="col.key" :class="cellClass(col)">
            <slot :name="col.key" :row="row">{{ row[col.key] }}</slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
