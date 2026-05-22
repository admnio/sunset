<script setup>
import { computed, ref, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import Empty from '../components/Empty.vue';
import FilterBar from '../components/FilterBar.vue';
import SearchInput from '../components/SearchInput.vue';
import RangeGroup from '../components/RangeGroup.vue';
import BulkActionBar from '../components/BulkActionBar.vue';
import Kicker from '../components/Kicker.vue';
import { useToasts } from '../composables/useToasts.js';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const failures = computed(() => current.value.failures ?? []);
const total = computed(() => current.value.total ?? failures.value.length);
const recentlyFailed = computed(() => current.value.recent ?? 0);

const search = ref('');
const range = ref('1h');
const selectedId = ref(failures.value[0]?.id ?? null);
const selectedIds = ref(new Set());     // bulk selection
const { push } = useToasts();

watch(failures, (list) => {
  if (!list.find((f) => f.id === selectedId.value)) {
    selectedId.value = list[0]?.id ?? null;
  }
  // prune bulk selection to ids still present
  const present = new Set(list.map((f) => f.id));
  selectedIds.value = new Set([...selectedIds.value].filter((id) => present.has(id)));
});

const filteredFailures = computed(() => {
  const q = search.value.toLowerCase().trim();
  if (!q) return failures.value;
  return failures.value.filter((f) =>
    [f.name, f.display_name, f.job_class, f.queue, f.exception]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  );
});

const selected = computed(() => failures.value.find((f) => f.id === selectedId.value) ?? null);

function firstLine(s) { return (s || '').split('\n')[0]; }
function displayName(item) {
  return item.name || item.display_name || item.type || item.job_class || 'job';
}
function isSelected(f) { return selectedIds.value.has(f.id); }
function toggle(f, e) {
  e?.stopPropagation();
  const next = new Set(selectedIds.value);
  if (next.has(f.id)) next.delete(f.id);
  else next.add(f.id);
  selectedIds.value = next;
}
function toggleAll(e) {
  e?.stopPropagation();
  if (selectedIds.value.size === filteredFailures.value.length) selectedIds.value = new Set();
  else selectedIds.value = new Set(filteredFailures.value.map((f) => f.id));
}
const allSelected = computed(() =>
  filteredFailures.value.length > 0 && selectedIds.value.size === filteredFailures.value.length,
);
function clearSelection() { selectedIds.value = new Set(); }

async function retry() {
  if (!selected.value) return;
  try {
    await axios.post(`/sunset/jobs/failed/${selected.value.id}/retry`);
    push({ kind: 'ok', title: 'Retry queued.', sub: 'Job re-enqueued.' });
    router.reload({ only: ['failures', 'total', 'recent'] });
  } catch (e) {
    push({ kind: 'err', title: 'Retry failed.', sub: e?.message });
  }
}
async function deleteOne() {
  if (!selected.value) return;
  try {
    await axios.post(`/sunset/jobs/failed/${selected.value.id}/delete`);
    push({ kind: 'warn', title: 'Job deleted.', undo: true });
    router.reload({ only: ['failures', 'total', 'recent'] });
  } catch (e) {
    push({ kind: 'err', title: 'Delete failed.', sub: e?.message });
  }
}
async function retrySelected() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  try {
    await axios.post('/sunset/jobs/failed/retry', { ids });
    push({ kind: 'ok', title: 'Retry queued.', sub: `${ids.length} jobs re-enqueued.` });
    clearSelection();
    router.reload({ only: ['failures', 'total', 'recent'] });
  } catch (e) {
    push({ kind: 'err', title: 'Bulk retry failed.', sub: e?.message });
  }
}
async function deleteSelected() {
  const ids = [...selectedIds.value];
  if (!ids.length) return;
  try {
    await axios.post('/sunset/jobs/failed/delete', { ids });
    push({ kind: 'warn', title: `Deleted ${ids.length} failed jobs.`, undo: true });
    clearSelection();
    router.reload({ only: ['failures', 'total', 'recent'] });
  } catch (e) {
    push({ kind: 'err', title: 'Bulk delete failed.', sub: e?.message });
  }
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Failed jobs</h1>
      <span class="page-sub">
        {{ total }} total<template v-if="recentlyFailed"> · <span style="color: rgb(var(--red))">{{ recentlyFailed }} in last hour</span></template>
      </span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
        <button class="btn primary" @click="retrySelected" :disabled="!selectedIds.size">
          <svg><use href="#i-retry"/></svg>Retry selected
        </button>
      </div>
    </div>

    <FilterBar :count="filteredFailures.length" count-label="shown">
      <template #search>
        <SearchInput v-model="search" placeholder="Filter by class, exception, queue…" />
      </template>
      <template #range>
        <RangeGroup v-model="range" :options="['1h', '24h', '7d', 'All']" />
      </template>
    </FilterBar>

    <Empty v-if="filteredFailures.length === 0" message="No failed jobs. Nice." />

    <div v-else class="split">
      <div class="split-master">
        <div class="mh">
          <span class="checkbox" :class="{ checked: allSelected }" @click="toggleAll"></span>
          <span>{{ filteredFailures.length }} in last hour</span>
          <span class="ml-auto" style="font-family: 'Geist Mono', monospace;">Sort: newest</span>
        </div>
        <div class="master-list">
          <div
            v-for="f in filteredFailures"
            :key="f.id"
            class="master-item"
            :class="{ active: f.id === selectedId }"
            @click="selectedId = f.id"
          >
            <div class="top-row">
              <span class="checkbox" :class="{ checked: isSelected(f) }" @click="toggle(f, $event)"></span>
              <div>
                <div class="mt-who">{{ displayName(f) }}</div>
              </div>
            </div>
            <div class="mt-what">{{ firstLine(f.exception) }}</div>
            <div class="mt-meta">
              {{ f.queue }} · {{ f.failed_at }} · {{ f.attempts ?? '?' }} attempts
            </div>
          </div>
        </div>
      </div>

      <div class="split-detail">
        <template v-if="selected">
          <div class="detail-head">
            <div class="title-block">
              <Kicker icon="i-alert">Case file</Kicker>
              <h3>{{ displayName(selected) }}</h3>
            </div>
            <div class="actions">
              <button class="btn" @click="retry"><svg><use href="#i-retry"/></svg>Retry</button>
              <button class="btn danger" @click="deleteOne"><svg><use href="#i-trash"/></svg>Delete</button>
            </div>
          </div>

          <div class="detail-meta">
            <div class="item"><span class="k">conn:</span><span class="v">{{ selected.connection ?? '—' }}</span></div>
            <div class="item"><span class="k">queue:</span><span class="v">{{ selected.queue ?? '—' }}</span></div>
            <div class="item"><span class="k">failed at:</span><span class="v">{{ selected.failed_at ?? '—' }}</span></div>
            <div class="item"><span class="k">attempts:</span><span class="v">{{ selected.attempts ?? '—' }}</span></div>
          </div>

          <pre class="trace">{{ selected.exception || '(no exception detail)' }}</pre>
        </template>
        <div v-else style="color: rgb(var(--muted)); font-size: 13px;">
          Select a failure on the left to inspect.
        </div>
      </div>
    </div>

    <BulkActionBar v-if="selectedIds.size > 0" :count="selectedIds.size" @clear="clearSelection">
      <button class="btn sm" @click="retrySelected"><svg><use href="#i-retry"/></svg>Retry selected</button>
      <button class="btn sm danger" @click="deleteSelected"><svg><use href="#i-trash"/></svg>Delete</button>
    </BulkActionBar>
  </div>
</template>
