<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
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
const { push } = useToasts();

const filteredJobs = computed(() => {
  const q = search.value.toLowerCase().trim();
  if (!q) return jobs.value;
  return jobs.value.filter((j) =>
    [j.name, j.display_name, j.type, j.job_class, j.queue]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  );
});

function jobName(row) {
  return row.name || row.display_name || row.type || row.job_class || '—';
}
function exportCsv() {
  push({ kind: 'ok', title: 'Export queued.', sub: 'CSV will download shortly.' });
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Completed jobs</h1>
      <span class="page-sub">last 60 min · {{ total }} successful</span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <FilterBar>
      <template #search>
        <SearchInput v-model="search" placeholder="Filter by class or queue…" />
      </template>
      <template #range>
        <RangeGroup v-model="range" :options="['1h', '6h', '24h']" />
      </template>
      <template #right>
        <button class="btn ghost" @click="exportCsv">Export CSV</button>
      </template>
    </FilterBar>

    <Empty v-if="filteredJobs.length === 0" message="No completed jobs yet." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Name', width: '1.5fr', sortable: 'text' },
        { key: 'queue', label: 'Queue', width: '160px' },
        { key: 'runtime_ms', label: 'Runtime', width: '110px', align: 'right', sortable: 'num' },
        { key: 'mem_peak', label: 'Mem peak', width: '120px', align: 'right', sortable: 'num' },
        { key: 'completed_at', label: 'At', width: '140px', align: 'right' },
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
      <template #mem_peak="{ row }">
        <span v-if="row.mem_peak">{{ row.mem_peak }}</span>
        <span v-else style="color: rgb(var(--muted))">—</span>
      </template>
      <template #completed_at="{ row }">
        <span>{{ row.completed_at || row.completed || row.pushed_at || '—' }}</span>
      </template>
    </DataTable>
  </div>
</template>
