<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePolling } from '../composables/usePolling.js';

const page = usePage();
const initial = page.props;
const { data } = usePolling(page.url);
const current = computed(() => data.value ?? initial);

const versions   = computed(() => current.value.versions ?? {});
const transports = computed(() => current.value.transports ?? []);
const redis      = computed(() => current.value.redis ?? {});
const rateLimits = computed(() => current.value.rate_limits ?? {});
const schedule   = computed(() => current.value.schedule ?? []);

function probeKind(t) {
  if (!t.configured) return 'warn';
  if (t.reachable === true) return 'ok';
  if (t.reachable === false) return 'err';
  return 'warn';
}
</script>

<template>
  <div>
    <div class="page-head">
      <h1 class="page-title">Health</h1>
      <span class="page-sub">runtime probe</span>
      <div class="page-actions">
        <button class="btn"><svg><use href="#i-refresh"/></svg>Re-probe</button>
      </div>
    </div>

    <div class="section">
      <div class="section-head"><h2>Versions</h2></div>
      <div class="grid" style="grid-template-columns: repeat(3, 1fr); gap: 14px;">
        <div class="card">
          <div class="card-label">Sunset</div>
          <div class="card-value">{{ versions.sunset || 'dev' }}</div>
          <div class="card-foot">queue management</div>
        </div>
        <div class="card">
          <div class="card-label">Laravel</div>
          <div class="card-value">{{ versions.laravel || '—' }}</div>
        </div>
        <div class="card">
          <div class="card-label">PHP</div>
          <div class="card-value">{{ versions.php || '—' }}</div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="section-head"><h2>Transports</h2><span class="meta">connection probes</span></div>
      <div class="grid" style="grid-template-columns: repeat(3, 1fr); gap: 14px;">
        <div
          v-for="t in transports"
          :key="t.name"
          class="probe"
          :class="probeKind(t)"
        >
          <div class="probe-dot"></div>
          <div class="probe-body">
            <div class="probe-name">{{ t.name }}</div>
            <div class="probe-meta">
              <span v-if="t.driver">driver: {{ t.driver }}</span>
              <span v-if="t.error" style="color: rgb(var(--red));"> · {{ t.error }}</span>
              <span v-if="! t.configured" style="color: rgb(var(--amber));"> · unconfigured</span>
            </div>
          </div>
          <div class="probe-stat" v-if="t.reachable === true">
            <span class="v">{{ t.latency_ms ?? 0 }}</span><span class="u">ms</span>
          </div>
          <div class="probe-stat" v-else>
            <span class="u">{{ t.reachable === false ? 'unreachable' : 'not configured' }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="section-head"><h2>Redis keyspace</h2><span class="meta">Sunset state</span></div>
      <div class="card">
        <div class="grid" style="grid-template-columns: repeat(4, 1fr); gap: 24px;">
          <div>
            <div class="card-label">Connection</div>
            <div style="font-size: 15px; font-weight: 500; margin-top: 4px;">{{ redis.connection || '—' }}</div>
          </div>
          <div>
            <div class="card-label">Status</div>
            <div style="font-size: 15px; font-weight: 500; margin-top: 4px;">
              <span :style="redis.reachable ? 'color: rgb(var(--green))' : 'color: rgb(var(--red))'">
                {{ redis.reachable ? 'reachable' : 'unreachable' }}
              </span>
            </div>
          </div>
          <div>
            <div class="card-label">Latency</div>
            <div style="font-size: 15px; font-weight: 500; margin-top: 4px;">{{ redis.latency_ms ?? 0 }}ms</div>
          </div>
          <div>
            <div class="card-label">Key prefix</div>
            <div style="font-size: 15px; font-weight: 500; margin-top: 4px; color: rgb(var(--violet)); font-family: 'Geist Mono', monospace;">
              {{ redis.prefix || '(none)' }}
            </div>
          </div>
        </div>
        <div v-if="redis.error" style="color: rgb(var(--red)); font-size: 11px; margin-top: 12px;">{{ redis.error }}</div>
      </div>
    </div>

    <div class="section">
      <div class="section-head"><h2>Rate limits</h2></div>
      <div class="card">
        <div v-if="rateLimits.has_limits">
          <span style="font-size: 22px; font-weight: 600; letter-spacing: -0.02em;">{{ rateLimits.count }}</span>
          <span style="color: rgb(var(--muted)); margin-left: 6px;">
            registered limit{{ rateLimits.count === 1 ? '' : 's' }}
          </span>
        </div>
        <div v-else style="color: rgb(var(--muted)); font-size: 13px;">
          No rate limits declared. Use <code style="font-family: 'Geist Mono', monospace; padding: 1px 6px; background: rgb(var(--bg-3)); border-radius: 4px; color: rgb(var(--violet));">Sunset::for('queue')-&gt;throttle(...)</code> in a service provider.
        </div>
      </div>
    </div>

    <div class="section">
      <div class="section-head"><h2>Scheduled commands</h2></div>
      <div class="schedule">
        <div v-for="cmd in schedule" :key="cmd.command" class="row">
          <div class="cmd">{{ cmd.command }}</div>
          <div class="cad">
            <svg><use href="#i-clock"/></svg>{{ cmd.cadence }}
          </div>
          <div class="desc">{{ cmd.purpose }}</div>
        </div>
      </div>
    </div>

    <div class="callout">
      Run <code>php artisan schedule:run</code> in your cron once per minute to keep these alive.
      Sunset auto-registers them via the package service provider.
    </div>
  </div>
</template>
