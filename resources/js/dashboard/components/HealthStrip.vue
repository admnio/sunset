<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

// `sunset.health` is shared by SetSunsetInertiaRoot on every dashboard
// request. The middleware degrades to an empty payload on Redis errors, so
// the optional chaining below is a defence-in-depth fallback only.
const health = computed(() => page.props?.sunset?.health ?? {});

const probes = computed(() => health.value?.probes ?? []);
const workerWarning = computed(() => health.value?.workerWarning ?? null);
const workers = computed(() => health.value?.workers ?? 0);
const pending = computed(() => health.value?.pending ?? 0);
const throughput = computed(() => health.value?.throughput ?? '0');
const failed = computed(() => health.value?.failed ?? 0);

const pendingDisplay = computed(() => {
  const v = pending.value;
  return typeof v === 'number' ? v.toLocaleString() : v;
});

function pillClass(status) {
  if (status === 'warn') return 'is-warn';
  if (status === 'err' || status === 'error' || status === 'down') return 'is-err';
  return 'is-ok';
}
</script>

<template>
  <div
    class="sunset-health-strip sticky z-[49] flex items-center gap-3 border-b border-border-soft overflow-x-auto whitespace-nowrap"
    style="top: 56px; padding: 7px 20px; background: var(--topbar-bg); backdrop-filter: blur(12px) saturate(1.4); -webkit-backdrop-filter: blur(12px) saturate(1.4); font-size: 12px;"
  >
    <template v-if="probes.length > 0">
      <span
        class="font-mono uppercase"
        style="font-size: 10.5px; color: var(--dim); letter-spacing: 0.04em;"
      >Probes</span>

      <span
        v-for="probe in probes"
        :key="probe.name"
        class="sunset-health-pill"
        :class="pillClass(probe.status)"
      >
        <span class="sunset-health-dot"></span>
        {{ probe.name }}
        <span v-if="probe.latency" class="sunset-health-latency font-mono">{{ probe.latency }}</span>
      </span>
    </template>

    <span v-if="workerWarning" class="sunset-health-pill is-warn">
      <span class="sunset-health-dot"></span>
      {{ workerWarning.name }}
      <span v-if="workerWarning.detail" class="sunset-health-latency font-mono">{{ workerWarning.detail }}</span>
    </span>

    <span
      class="ml-auto font-mono"
      style="color: var(--muted); font-size: 11px;"
    >
      <strong class="sunset-health-strong">{{ workers }}</strong> workers ·
      <strong class="sunset-health-strong">{{ pendingDisplay }}</strong> pending ·
      <strong class="sunset-health-strong">{{ throughput }}</strong>/min ·
      <strong class="sunset-health-strong" style="color: var(--red);">{{ failed }}</strong> failed (1h)
    </span>
  </div>
</template>

<style scoped>
.sunset-health-strip::-webkit-scrollbar { display: none; }

.sunset-health-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  background: var(--bg-3);
  border: 1px solid var(--border-soft);
  border-radius: 999px;
  color: var(--text-2);
  font-size: 12px;
  font-weight: 500;
  transition: border-color 0.12s, background 0.12s;
}
.sunset-health-pill:hover {
  background: var(--card-hover);
  border-color: var(--border);
}

.sunset-health-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--green);
  box-shadow: 0 0 0 3px var(--green-soft);
}
.sunset-health-pill.is-warn .sunset-health-dot {
  background: var(--amber);
  box-shadow: 0 0 0 3px var(--amber-soft);
}
.sunset-health-pill.is-err .sunset-health-dot {
  background: var(--red);
  box-shadow: 0 0 0 3px var(--red-soft);
}

.sunset-health-latency {
  color: var(--dim);
  font-size: 11px;
  margin-left: 2px;
}

.sunset-health-strong {
  color: var(--text);
  font-weight: 600;
}
</style>
