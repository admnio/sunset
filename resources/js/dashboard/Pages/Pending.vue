<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import StatusPill from '../components/StatusPill.vue';
import Empty from '../components/Empty.vue';
import FilterBar from '../components/FilterBar.vue';
import SearchInput from '../components/SearchInput.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);
const jobs = computed(() => current.value.jobs ?? []);
const total = computed(() => current.value.total ?? jobs.value.length);

const search = ref('');
const tab = ref('all');

const filteredJobs = computed(() => {
  const q = search.value.toLowerCase().trim();
  let rows = jobs.value;
  if (tab.value !== 'all') {
    rows = rows.filter((j) => (j.state ?? j.status ?? '').toLowerCase() === tab.value);
  }
  if (!q) return rows;
  return rows.filter((j) =>
    [j.name, j.display_name, j.type, j.job_class, j.queue, j.state, j.status]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  );
});

const counts = computed(() => {
  const all = jobs.value.length;
  const reserved = jobs.value.filter((j) => (j.state ?? j.status) === 'reserved').length;
  const delayed = jobs.value.filter((j) => (j.state ?? j.status) === 'delayed').length;
  const scheduled = jobs.value.filter((j) => (j.state ?? j.status) === 'scheduled').length;
  return { all, reserved, delayed, scheduled };
});

function jobName(row) {
  return row.name || row.display_name || row.type || row.job_class || '—';
}
function stateKind(s) {
  const v = String(s ?? '').toLowerCase();
  if (v === 'reserved') return 'info';
  if (v === 'delayed' || v === 'scheduled') return 'warn';
  return 'neutral';
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Pending jobs</h1>
      <span class="page-sub">{{ total }} total · {{ counts.delayed + counts.scheduled }} delayed</span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <FilterBar>
      <template #search>
        <SearchInput v-model="search" placeholder="Filter by class, queue, state…" />
      </template>
    </FilterBar>

    <div class="tabs">
      <button class="tab" :class="{ active: tab === 'all' }" @click="tab = 'all'">All <span class="count">{{ counts.all }}</span></button>
      <button class="tab" :class="{ active: tab === 'reserved' }" @click="tab = 'reserved'">Reserved <span class="count">{{ counts.reserved }}</span></button>
      <button class="tab" :class="{ active: tab === 'delayed' }" @click="tab = 'delayed'">Delayed <span class="count">{{ counts.delayed }}</span></button>
      <button class="tab" :class="{ active: tab === 'scheduled' }" @click="tab = 'scheduled'">Scheduled <span class="count">{{ counts.scheduled }}</span></button>
    </div>

    <Empty v-if="filteredJobs.length === 0" message="No pending jobs." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Name', width: '1.5fr', sortable: 'text' },
        { key: 'queue', label: 'Queue', width: '160px' },
        { key: 'reserved_at', label: 'Reserved at', width: '160px', align: 'right' },
        { key: 'attempts', label: 'Attempts', width: '110px', align: 'right', sortable: 'num' },
        { key: 'state', label: 'State', width: '180px' },
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
      <template #reserved_at="{ row }">
        <span v-if="row.reserved_at">{{ row.reserved_at }}</span>
        <span v-else style="color: rgb(var(--muted))">—</span>
      </template>
      <template #state="{ row }">
        <StatusPill :status="stateKind(row.state ?? row.status)">{{ row.state || row.status || '—' }}</StatusPill>
      </template>
    </DataTable>
  </div>
</template>
