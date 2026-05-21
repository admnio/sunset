<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import DataTable from '../components/DataTable.vue';
import Empty from '../components/Empty.vue';
import StatusPill from '../components/StatusPill.vue';
import ConfirmAction from '../components/ConfirmAction.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);
const queues = computed(() => current.value.queues ?? []);
const pausedQueues = computed(() => current.value.paused_queues ?? []);

// v1.3.0 — per-queue pause/resume state. The repository identifies a pause by
// the (connection, queue) pair; the workload repository tags each row with its
// source transport name as `connection`. When the row's connection is null
// (no transport claimed the queue), we fall back to matching on queue name
// only — better to surface SOMETHING than to silently hide a paused queue.
function isPaused(row) {
  const paused = pausedQueues.value;
  if (row.connection) {
    return paused.some(
      (p) => p.connection === row.connection && p.queue === row.name,
    );
  }
  return paused.some((p) => p.queue === row.name);
}

// Find the (connection, queue) we'd need to POST against for resume. When the
// row carries a connection, use it; otherwise fall back to the paused entry
// matching by queue name. Returns null if neither path resolves — the button
// won't render in that case.
function pauseTarget(row) {
  if (row.connection) {
    return { connection: row.connection, queue: row.name };
  }
  const match = pausedQueues.value.find((p) => p.queue === row.name);
  return match ? { connection: match.connection, queue: match.queue } : null;
}

function togglePause(row) {
  const target = pauseTarget(row);
  if (! target) return;

  const action = isPaused(row) ? 'resume' : 'pause';
  const url = `/sunset/workload/${encodeURIComponent(target.connection)}/${encodeURIComponent(target.queue)}/${action}`;

  router.post(url, {}, { preserveScroll: true });
}
</script>

<template>
  <div class="space-y-3">
    <h1 class="text-base font-bold">Workload</h1>
    <Empty v-if="queues.length === 0" message="No queues configured." />
    <DataTable
      v-else
      :columns="[
        { key: 'name', label: 'Queue', width: '1fr' },
        { key: 'length', label: 'Length', width: '100px' },
        { key: 'processes', label: 'Processes', width: '110px' },
        { key: 'wait', label: 'Wait (s)', width: '110px' },
        { key: 'actions', label: '', width: '140px' },
      ]"
      :rows="queues"
      :selectable="false"
    >
      <template #name="{ row }">
        <div class="flex items-center gap-2 min-w-0">
          <span class="truncate">{{ row.name }}</span>
          <StatusPill v-if="isPaused(row)" status="warn">paused</StatusPill>
        </div>
      </template>
      <template #actions="{ row }">
        <div v-if="pauseTarget(row)" class="flex justify-end">
          <ConfirmAction
            v-if="isPaused(row)"
            label="resume"
            confirm-label="confirm resume"
            @confirm="togglePause(row)"
          />
          <ConfirmAction
            v-else
            label="pause"
            confirm-label="confirm pause"
            @confirm="togglePause(row)"
          />
        </div>
      </template>
    </DataTable>
  </div>
</template>
