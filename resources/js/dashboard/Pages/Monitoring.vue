<script setup>
import { computed, ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import StatusPill from '../components/StatusPill.vue';
import Empty from '../components/Empty.vue';
import FilterBar from '../components/FilterBar.vue';
import SearchInput from '../components/SearchInput.vue';
import { useToasts } from '../composables/useToasts.js';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const pinned = computed(() => current.value.pinned ?? []);
const counts = computed(() => current.value.counts ?? {});

const search = ref('');
const newTag = ref('');
const busy = ref(false);
const { push } = useToasts();

// Surface ALL known tags (pinned plus any with recorded counts)
const allTags = computed(() => {
  const set = new Set(pinned.value);
  for (const k of Object.keys(counts.value)) set.add(k);
  return [...set].map((tag) => ({
    tag,
    pinned: pinned.value.includes(tag),
    count: counts.value[tag] ?? 0,
  }));
});
const filteredTags = computed(() => {
  const q = search.value.toLowerCase().trim();
  if (!q) return allTags.value;
  return allTags.value.filter((r) => r.tag.toLowerCase().includes(q));
});

async function pinTag(tag) {
  if (busy.value) return;
  busy.value = true;
  try {
    await axios.post(`/sunset/monitoring/${encodeURIComponent(tag)}/pin`);
    push({ kind: 'ok', title: 'Tag pinned.', sub: 'Survives the trim window.' });
    router.reload({ only: ['pinned', 'counts'] });
  } catch (e) {
    push({ kind: 'err', title: 'Pin failed.', sub: e?.message });
  } finally {
    busy.value = false;
  }
}
async function unpinTag(tag) {
  if (busy.value) return;
  busy.value = true;
  try {
    await axios.post(`/sunset/monitoring/${encodeURIComponent(tag)}/unpin`);
    push({ kind: 'info', title: 'Tag unpinned.', sub: 'Will be trimmed at next sweep.' });
    router.reload({ only: ['pinned', 'counts'] });
  } catch (e) {
    push({ kind: 'err', title: 'Unpin failed.', sub: e?.message });
  } finally {
    busy.value = false;
  }
}
async function pinNew() {
  const t = newTag.value.trim();
  if (!t) return;
  await pinTag(t);
  newTag.value = '';
}
function onRowClick(row) {
  router.visit(`/sunset/monitoring/tags/${encodeURIComponent(row.tag)}`);
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Monitoring</h1>
      <span class="page-sub">monitored tags · trace anything tagged</span>
      <div class="page-actions">
        <input
          v-model="newTag"
          @keydown.enter="pinNew"
          placeholder="Pin a tag…"
          style="background: rgb(var(--bg-3)); border: 1px solid rgb(var(--border-soft)); border-radius: 7px; padding: 6px 12px; font-size: 13px; color: rgb(var(--text)); font-family: 'Geist Mono', monospace; width: 200px;"
        />
        <button class="btn primary" :disabled="busy || !newTag.trim()" @click="pinNew">
          <svg><use href="#i-pin"/></svg>Pin
        </button>
      </div>
    </div>

    <FilterBar>
      <template #search>
        <SearchInput v-model="search" placeholder="Filter tags…" />
      </template>
    </FilterBar>

    <Empty v-if="filteredTags.length === 0" message="No tags monitored." />
    <DataTable
      v-else
      :columns="[
        { key: 'tag', label: 'Tag', width: '1fr', sortable: 'text' },
        { key: 'pinned', label: 'Pinned', width: '110px' },
        { key: 'count', label: 'Total seen', width: '120px', align: 'right', sortable: 'num' },
        { key: 'actions', label: '', width: '110px' },
      ]"
      :rows="filteredTags"
      :clickable="true"
      :selectable="false"
      @row-click="onRowClick"
    >
      <template #tag="{ row }">
        <span class="q-name">{{ row.tag }}</span>
      </template>
      <template #pinned="{ row }">
        <StatusPill v-if="row.pinned" status="ok">Pinned</StatusPill>
        <span v-else style="color: rgb(var(--muted))">No</span>
      </template>
      <template #actions="{ row }">
        <div class="flex justify-end" @click.stop>
          <button
            v-if="row.pinned"
            class="btn sm"
            :disabled="busy"
            @click.stop="unpinTag(row.tag)"
          >
            <svg><use href="#i-pin"/></svg>Unpin
          </button>
          <button
            v-else
            class="btn sm"
            :disabled="busy"
            @click.stop="pinTag(row.tag)"
          >
            <svg><use href="#i-pin"/></svg>Pin
          </button>
        </div>
      </template>
    </DataTable>

    <div class="callout">
      Tags are arbitrary strings you attach to jobs —
      <code>public function tags() { return ['tenant:' . $this-&gt;tenantId]; }</code>.
      Pinned tags survive the trim window so you can audit one tenant's flow indefinitely.
    </div>
  </div>
</template>
