<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import StatusPill from '../components/StatusPill.vue';
import Empty from '../components/Empty.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const batches = computed(() => current.value.batches ?? []);
const configured = computed(() => current.value.configured ?? true);

function pct(b) {
  const total = Number(b.total_jobs ?? 0);
  if (!total) return { done: 0, failed: 0 };
  const failed = Number(b.failed_jobs ?? 0);
  const pending = Number(b.pending_jobs ?? 0);
  const done = total - pending - failed;
  return {
    done: (done / total) * 100,
    failed: (failed / total) * 100,
    pending: (pending / total) * 100,
  };
}
function statusOf(b) {
  if (b.cancelled_at) return { kind: 'err', label: 'Cancelled' };
  if (b.finished_at && Number(b.failed_jobs ?? 0) > 0) return { kind: 'warn', label: `${b.failed_jobs} failures` };
  if (b.finished_at) return { kind: 'ok', label: 'Complete' };
  if (Number(b.failed_jobs ?? 0) > 0) return { kind: 'err', label: `${b.failed_jobs} failures` };
  return { kind: 'info', label: 'Processing' };
}
const failingCount = computed(() =>
  batches.value.filter((b) => Number(b.failed_jobs ?? 0) > 0).length,
);
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Batches</h1>
      <span class="page-sub">
        {{ batches.length }} batches
        <template v-if="failingCount"> · {{ failingCount }} with failures</template>
      </span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <div v-if="!configured" class="banner-warn" style="border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;">
      Laravel batches are not configured on this installation. Publish the
      <code>queue:batches-table</code> migration to enable them.
    </div>

    <Empty v-if="configured && batches.length === 0" message="No batches recorded." />

    <div v-else-if="batches.length > 0" class="grid" style="grid-template-columns: repeat(2, 1fr); gap: 14px;">
      <div
        v-for="b in batches"
        :key="b.id"
        class="batch-card"
        :class="{ failing: Number(b.failed_jobs ?? 0) > 0 }"
      >
        <div class="batch-head">
          <div class="batch-name">
            {{ b.name || b.id || 'Unnamed batch' }}
            <span class="sub">{{ b.id }}{{ b.created_at ? ' · started ' + b.created_at : '' }}</span>
          </div>
          <StatusPill :status="statusOf(b).kind">{{ statusOf(b).label }}</StatusPill>
        </div>

        <div class="batch-counts">
          <div class="item"><span class="l">Total</span><span class="v">{{ b.total_jobs ?? 0 }}</span></div>
          <div class="item"><span class="l">Pending</span><span class="v">{{ b.pending_jobs ?? 0 }}</span></div>
          <div class="item fail" v-if="Number(b.failed_jobs ?? 0) > 0">
            <span class="l">Failed</span><span class="v">{{ b.failed_jobs }}</span>
          </div>
          <div class="item done">
            <span class="l">Done</span>
            <span class="v">{{ Math.max(0, Number(b.total_jobs ?? 0) - Number(b.pending_jobs ?? 0) - Number(b.failed_jobs ?? 0)) }}</span>
          </div>
        </div>

        <div class="batch-progress">
          <div class="bar ok"   :style="{ width: pct(b).done + '%' }"></div>
          <div class="bar fail" :style="{ width: pct(b).failed + '%' }"></div>
        </div>

        <div class="batch-foot">
          <span v-if="b.finished_at">finished {{ b.finished_at }}</span>
          <span v-else-if="b.created_at">started {{ b.created_at }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
