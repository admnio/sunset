<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';
import StatusPill from '../components/StatusPill.vue';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const versions   = computed(() => current.value.versions ?? {});
const transports = computed(() => current.value.transports ?? []);
const redis      = computed(() => current.value.redis ?? {});
const rateLimits = computed(() => current.value.rate_limits ?? {});
const schedule   = computed(() => current.value.schedule ?? []);
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-base font-bold">Health</h1>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Versions</h2>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
        <div class="border border-sunset-border rounded p-3 bg-sunset-card">
          <div class="text-[10px] uppercase text-sunset-muted">Sunset</div>
          <div class="font-bold text-sm">{{ versions.sunset || 'dev' }}</div>
        </div>
        <div class="border border-sunset-border rounded p-3 bg-sunset-card">
          <div class="text-[10px] uppercase text-sunset-muted">Laravel</div>
          <div class="font-bold text-sm">{{ versions.laravel }}</div>
        </div>
        <div class="border border-sunset-border rounded p-3 bg-sunset-card">
          <div class="text-[10px] uppercase text-sunset-muted">PHP</div>
          <div class="font-bold text-sm">{{ versions.php }}</div>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Transports</h2>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
        <div v-for="t in transports" :key="t.name" class="border border-sunset-border rounded p-3 bg-sunset-card">
          <div class="flex items-center justify-between mb-1">
            <span class="font-bold">{{ t.name }}</span>
            <StatusPill v-if="t.configured && t.reachable === true" status="ok">reachable</StatusPill>
            <StatusPill v-else-if="t.configured && t.reachable === false" status="failed">unreachable</StatusPill>
            <StatusPill v-else status="warn">unconfigured</StatusPill>
          </div>
          <div v-if="t.driver" class="text-sunset-muted text-[10px]">driver: {{ t.driver }}</div>
          <div v-if="t.reachable === true" class="text-sunset-muted text-[10px]">{{ t.latency_ms }}ms</div>
          <div v-if="t.error" class="text-status-error text-[10px] mt-1 truncate" :title="t.error">{{ t.error }}</div>
        </div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Redis (Sunset state)</h2>
      <div class="border border-sunset-border rounded p-3 bg-sunset-card text-xs">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div>
            <div class="text-[10px] uppercase text-sunset-muted">Connection</div>
            <div>{{ redis.connection || '—' }}</div>
          </div>
          <div>
            <div class="text-[10px] uppercase text-sunset-muted">Status</div>
            <div>
              <StatusPill v-if="redis.reachable" status="ok">reachable</StatusPill>
              <StatusPill v-else status="failed">unreachable</StatusPill>
            </div>
          </div>
          <div>
            <div class="text-[10px] uppercase text-sunset-muted">Latency</div>
            <div>{{ redis.latency_ms ?? 0 }}ms</div>
          </div>
          <div>
            <div class="text-[10px] uppercase text-sunset-muted">Key prefix</div>
            <div class="truncate" :title="redis.prefix">{{ redis.prefix || '(none)' }}</div>
          </div>
        </div>
        <div v-if="redis.error" class="text-status-error text-[10px] mt-2">{{ redis.error }}</div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Rate limits</h2>
      <div class="border border-sunset-border rounded p-3 bg-sunset-card text-xs">
        <div v-if="rateLimits.has_limits">
          <span class="font-bold text-sm">{{ rateLimits.count }}</span>
          <span class="text-sunset-muted ml-1">registered limit{{ rateLimits.count === 1 ? '' : 's' }}</span>
        </div>
        <div v-else class="text-sunset-muted">No rate limits declared. Use Sunset::for('queue')->throttle(...) in a service provider.</div>
      </div>
    </section>

    <section>
      <h2 class="text-xs uppercase text-sunset-muted mb-2">Scheduled commands</h2>
      <div class="border border-sunset-border rounded divide-y divide-sunset-border">
        <div v-for="cmd in schedule" :key="cmd.command" class="px-3 py-2 grid grid-cols-[1.5fr_1fr_2fr] gap-3 text-xs">
          <div class="font-mono text-sunset-text">{{ cmd.command }}</div>
          <div class="text-sunset-muted">{{ cmd.cadence }}</div>
          <div class="text-sunset-muted">{{ cmd.purpose }}</div>
        </div>
      </div>
    </section>
  </div>
</template>
