<script setup>
import { computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import DataTable from '../components/DataTable.vue';
import StatusPill from '../components/StatusPill.vue';
import ChartCard from '../components/ChartCard.vue';
import BackLink from '../components/BackLink.vue';
import Kicker from '../components/Kicker.vue';
import Empty from '../components/Empty.vue';

const page = usePage();
const tag = computed(() => page.props.tag);
const stats = computed(() => page.props.stats ?? {});
const classes = computed(() => page.props.classes ?? []);
const recentRuns = computed(() => page.props.recent_runs ?? []);
const activitySeries = computed(() => page.props.activity_series ?? []);

const chartPath = computed(() => {
  const points = activitySeries.value;
  if (points.length === 0) return { line: '', area: '' };
  const W = 800, H = 160, PAD_T = 8, PAD_B = 12;
  const max = Math.max(1, ...points.map((p) => Number(p.value) || 0));
  const xs = points.map((_, i) => (i / Math.max(1, points.length - 1)) * W);
  const ys = points.map((p) => H - PAD_B - ((Number(p.value) || 0) / max) * (H - PAD_T - PAD_B));
  const linePts = xs.map((x, i) => `${x},${ys[i]}`).join(' L ');
  const areaPts = `${linePts} L ${xs[xs.length - 1]},${H} L ${xs[0]},${H} Z`;
  return { line: `M ${linePts}`, area: `M ${areaPts}` };
});

function relTime(sec) {
  if (!sec) return 'never';
  const diff = Math.max(0, Math.floor(Date.now() / 1000 - sec));
  if (diff < 60) return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}
function statusKind(s) {
  if (s === 'completed') return 'ok';
  if (s === 'failed') return 'err';
  if (s === 'released') return 'info';
  return 'neutral';
}
function onClassRowClick(row) {
  router.visit(`/sunset/metrics/jobs/${encodeURIComponent(row.class)}/detail`);
}
</script>

<template>
  <div>
    <BackLink to="/sunset/monitoring">Back to Monitoring</BackLink>

    <div class="page-head">
      <div>
        <Kicker icon="i-tag">Monitored tag</Kicker>
        <h1 class="class-name">{{ tag }}</h1>
      </div>
      <span class="page-sub" style="margin-left: auto;">
        <span v-if="stats.pinned">pinned · </span>{{ stats.total_seen ?? 0 }} total jobs
      </span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-pin"/></svg>{{ stats.pinned ? 'Unpin' : 'Pin' }}</button>
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <div class="stats cols-6">
      <div class="stat">
        <div class="stat-label">Total seen</div>
        <div class="stat-value">{{ stats.total_seen ?? 0 }}</div>
      </div>
      <div class="stat">
        <div class="stat-label">In last hour</div>
        <div class="stat-value">{{ stats.in_last_hour ?? 0 }}</div>
      </div>
      <div class="stat">
        <div class="stat-label">Last seen</div>
        <div class="stat-value" style="font-size: 22px;">{{ relTime(stats.last_seen_at) }}</div>
      </div>
      <div class="stat">
        <div class="stat-label">Classes</div>
        <div class="stat-value">{{ stats.classes_count ?? 0 }}</div>
      </div>
      <div class="stat">
        <div class="stat-label">Failed</div>
        <div class="stat-value" :class="{ red: (stats.failed ?? 0) > 0 }">{{ stats.failed ?? 0 }}</div>
      </div>
      <div class="stat">
        <div class="stat-label">Pinned</div>
        <div class="stat-value" style="font-size: 17px;">
          <StatusPill v-if="stats.pinned" status="ok">Pinned</StatusPill>
          <span v-else style="color: rgb(var(--muted)); font-size: 13px;">Not pinned</span>
        </div>
      </div>
    </div>

    <div class="section" v-if="activitySeries.length">
      <div class="section-head">
        <h2>Activity over time</h2>
        <span class="meta">jobs/min · last 24 h</span>
      </div>
      <ChartCard>
        <svg class="full-spark" viewBox="0 0 800 160" preserveAspectRatio="none">
          <defs>
            <linearGradient id="td-fade" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0" stop-color="#a78bfa" stop-opacity="0.35"/>
              <stop offset="1" stop-color="#a78bfa" stop-opacity="0"/>
            </linearGradient>
          </defs>
          <g stroke="currentColor" stroke-width="0.5" opacity="0.08">
            <line x1="0" x2="800" y1="40" y2="40"/>
            <line x1="0" x2="800" y1="80" y2="80"/>
            <line x1="0" x2="800" y1="120" y2="120"/>
          </g>
          <path v-if="chartPath.area" :d="chartPath.area" fill="url(#td-fade)"/>
          <path v-if="chartPath.line" :d="chartPath.line" fill="none" stroke="#a78bfa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </ChartCard>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Job classes seen with this tag</h2>
        <span class="meta">{{ classes.length }} class{{ classes.length === 1 ? '' : 'es' }}</span>
      </div>
      <Empty v-if="classes.length === 0" message="No classes recorded for this tag yet." />
      <DataTable
        v-else
        :columns="[
          { key: 'class', label: 'Job class', width: '1.4fr', sortable: 'text' },
          { key: 'queue', label: 'Queue', width: '160px' },
          { key: 'count', label: 'Count', width: '110px', align: 'right', sortable: 'num' },
          { key: 'failed', label: 'Failed', width: '110px', align: 'right', sortable: 'num' },
          { key: 'last_seen', label: 'Last seen', width: '160px', align: 'right' },
        ]"
        :rows="classes"
        :clickable="true"
        :selectable="false"
        @row-click="onClassRowClick"
      >
        <template #class="{ row }">
          <span class="q-name">{{ row.class }}</span>
        </template>
        <template #queue="{ row }">
          <span class="pill neutral">{{ row.queue ?? '—' }}</span>
        </template>
        <template #failed="{ row }">
          <span :style="row.failed > 0 ? 'color: rgb(var(--red))' : ''">{{ row.failed }}</span>
        </template>
      </DataTable>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Recent runs</h2>
        <span class="meta">last {{ recentRuns.length }} with this tag</span>
      </div>
      <Empty v-if="recentRuns.length === 0" message="No recent runs." />
      <DataTable
        v-else
        :columns="[
          { key: 'at', label: 'At', width: '140px', align: 'right' },
          { key: 'name', label: 'Class', width: '1.4fr' },
          { key: 'queue', label: 'Queue', width: '160px' },
          { key: 'runtime_ms', label: 'Runtime', width: '110px', align: 'right' },
          { key: 'status', label: 'Status', width: '120px' },
        ]"
        :rows="recentRuns"
        :selectable="false"
      >
        <template #at="{ row }">{{ row.completed_at ?? row.pushed_at ?? row.failed_at ?? '—' }}</template>
        <template #name="{ row }">
          <span class="q-name">{{ row.name ?? row.display_name ?? row.type ?? '—' }}</span>
        </template>
        <template #queue="{ row }">
          <span class="pill neutral">{{ row.queue ?? '—' }}</span>
        </template>
        <template #runtime_ms="{ row }">
          <span v-if="row.runtime_ms != null">{{ row.runtime_ms }}ms</span>
          <span v-else style="color: rgb(var(--muted))">—</span>
        </template>
        <template #status="{ row }">
          <StatusPill :status="statusKind(row.status)">{{ row.status || '—' }}</StatusPill>
        </template>
      </DataTable>
    </div>

    <div class="callout">
      Pinned tags persist past the normal trim window so you can audit one tenant's flow indefinitely.
      The audit data lives under <code>sunset:tags:{{ '{tag}' }}</code> Redis sorted sets — pin-protected from the periodic sweep.
    </div>
  </div>
</template>
