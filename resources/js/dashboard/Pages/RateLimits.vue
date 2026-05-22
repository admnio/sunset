<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import StatusPill from '../components/StatusPill.vue';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const limits = computed(() => current.value.limits ?? []);
const rejects = computed(() => current.value.rejects ?? []);

const rejectsByLimit = computed(() => {
  const map = {};
  for (const r of rejects.value) {
    map[r.limit] = (map[r.limit] ?? 0) + Number(r.count ?? 0);
  }
  return map;
});
const totalRejects = computed(() =>
  Object.values(rejectsByLimit.value).reduce((s, n) => s + n, 0),
);

function strategyKind(s) {
  const v = String(s ?? '').toLowerCase();
  if (v === 'drop') return 'err';
  if (v === 'release-fixed') return 'info';
  return 'warn';
}
function fmtThrottle(t) {
  if (!t) return '—';
  return `${t.max} / ${t.window}s`;
}
function fmtConcurrency(c) {
  if (!c) return '—';
  return `${c.max}`;
}
const rowsWithRejects = computed(() =>
  limits.value.map((l) => ({
    ...l,
    rejects: rejectsByLimit.value[l.name] ?? 0,
  })),
);
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Rate limits</h1>
      <span class="page-sub">{{ limits.length }} registered · {{ totalRejects }} rejects in past hour</span>
      <div class="page-actions">
        <button class="btn primary"><svg><use href="#i-plus"/></svg>Add limit</button>
      </div>
    </div>

    <Empty v-if="limits.length === 0" message="No rate limits declared." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Name', width: '1fr', sortable: 'text' },
        { key: 'target', label: 'Target', width: '1.2fr' },
        { key: 'throttle', label: 'Throttle', width: '130px', align: 'right' },
        { key: 'concurrency', label: 'Concurrency', width: '130px', align: 'right' },
        { key: 'over_limit', label: 'Strategy', width: '180px' },
        { key: 'rejects', label: 'Rejects (1h)', width: '120px', align: 'right', sortable: 'num' },
      ]"
      :rows="rowsWithRejects"
      :selectable="false"
    >
      <template #name="{ row }">
        <span class="q-name">{{ row.name }}</span>
      </template>
      <template #target="{ row }">
        <span class="pill neutral">{{ row.target }}</span>
      </template>
      <template #throttle="{ row }">
        <span>{{ fmtThrottle(row.throttle) }}</span>
      </template>
      <template #concurrency="{ row }">
        <span>{{ fmtConcurrency(row.concurrency) }}</span>
      </template>
      <template #over_limit="{ row }">
        <StatusPill :status="strategyKind(row.over_limit)">{{ row.over_limit || '—' }}</StatusPill>
      </template>
      <template #rejects="{ row }">
        <span :style="row.rejects > 0 ? 'color: rgb(var(--amber))' : ''">{{ row.rejects }}</span>
      </template>
    </DataTable>

    <div class="callout">
      Declare limits fluently in any service provider —
      <code>Sunset::for('geocode')-&gt;throttle(perMinute: 10)-&gt;concurrency(3);</code>
      Zero overhead when no limits are registered.
    </div>
  </div>
</template>
