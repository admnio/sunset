<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import StatusPill from '../components/StatusPill.vue';
import Empty from '../components/Empty.vue';
import FilterBar from '../components/FilterBar.vue';
import SearchInput from '../components/SearchInput.vue';
import RangeGroup from '../components/RangeGroup.vue';
import { useToasts } from '../composables/useToasts.js';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);
const jobs = computed(() => current.value.jobs ?? []);
const total = computed(() => current.value.total ?? jobs.value.length);

const search = ref('');
const range = ref('1h');
const tab = ref('all');
const { push } = useToasts();

const filteredJobs = computed(() => {
  const q = search.value.toLowerCase().trim();
  let rows = jobs.value;
  if (tab.value !== 'all') {
    rows = rows.filter((j) => (j.status ?? '').toLowerCase() === tab.value);
  }
  if (!q) return rows;
  return rows.filter((j) =>
    [j.name, j.display_name, j.type, j.job_class, j.queue, j.status]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  );
});

const counts = computed(() => {
  const all = jobs.value.length;
  const completed = jobs.value.filter((j) => j.status === 'completed').length;
  const failed = jobs.value.filter((j) => j.status === 'failed').length;
  const released = jobs.value.filter((j) => j.status === 'released').length;
  return { all, completed, failed, released };
});

function jobName(row) {
  return row.name || row.display_name || row.type || row.job_class || '—';
}
function statusKind(s) {
  if (!s) return 'info';
  if (s === 'failed') return 'err';
  if (s === 'completed') return 'ok';
  if (s === 'pending' || s === 'reserved') return 'warn';
  return 'info';
}
function exportCsv() {
  push({ kind: 'ok', title: 'Export queued.', sub: 'CSV will download shortly.' });
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Recent jobs</h1>
      <span class="page-sub">last 60 min · {{ total }} entries</span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <FilterBar :count="filteredJobs.length" count-label="shown">
      <template #search>
        <SearchInput v-model="search" placeholder="Filter by class, queue, status…" />
      </template>
      <template #range>
        <RangeGroup v-model="range" :options="['1h', '6h', '24h']" />
      </template>
      <template #right>
        <button class="btn ghost" @click="exportCsv">Export CSV</button>
      </template>
    </FilterBar>

    <div class="tabs">
      <button class="tab" :class="{ active: tab === 'all' }" @click="tab = 'all'">All <span class="count">{{ counts.all }}</span></button>
      <button class="tab" :class="{ active: tab === 'completed' }" @click="tab = 'completed'">Completed <span class="count">{{ counts.completed }}</span></button>
      <button class="tab" :class="{ active: tab === 'failed' }" @click="tab = 'failed'">Failed <span class="count">{{ counts.failed }}</span></button>
      <button class="tab" :class="{ active: tab === 'released' }" @click="tab = 'released'">Released <span class="count">{{ counts.released }}</span></button>
    </div>

    <Empty v-if="filteredJobs.length === 0" message="No jobs match the current filter." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Name', width: '1.5fr', sortable: 'text' },
        { key: 'queue', label: 'Queue', width: '160px' },
        { key: 'runtime_ms', label: 'Runtime', width: '110px', align: 'right', sortable: 'num' },
        { key: 'status', label: 'Status', width: '130px' },
        { key: 'pushed_at', label: 'At', width: '140px', align: 'right' },
      ]"
      :rows="filteredJobs"
      :selectable="false"
    >
      <template #name="{ row }">
        <span class="q-name">{{ jobName(row) }}</span>
      </template>
      <template #queue="{ row }">
        <span class="pill neutral">{{ row.queue ?? '—' }}</span>
      </template>
      <template #runtime_ms="{ row }">
        <span v-if="row.runtime_ms != null && row.runtime_ms !== false && row.runtime_ms !== ''">{{ row.runtime_ms }}ms</span>
        <span v-else style="color: rgb(var(--muted))">—</span>
      </template>
      <template #status="{ row }">
        <StatusPill :status="statusKind(row.status)">{{ row.status || '—' }}</StatusPill>
      </template>
    </DataTable>
  </div>
</template>
