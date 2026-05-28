<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const currentPath = computed(() =>
  page.url || (typeof window !== 'undefined' ? window.location.pathname : '')
);

// Sunset dashboard base path (shared by SetSunsetInertiaRoot middleware).
const basePath = computed(() => {
  const p = page.props?.sunset?.path ?? 'sunset';
  return '/' + String(p).replace(/^\/+/, '');
});

// Real failed-job backlog, shared by the middleware. Drives the Failed badge.
const failedCount = computed(() => Number(page.props?.sunset?.failedCount ?? 0));

const groups = computed(() => {
  const base = basePath.value;
  const failed = failedCount.value;
  return [
    {
      label: 'Overview',
      items: [
        { href: base,                 label: 'Home',     icon: '#i-home' },
        { href: `${base}/activity`,   label: 'Activity', icon: '#i-activity' },
        { href: `${base}/workload`,   label: 'Workload', icon: '#i-list' },
        { href: `${base}/metrics`,    label: 'Metrics',  icon: '#i-chart' },
      ],
    },
    {
      label: 'Jobs',
      items: [
        { href: `${base}/jobs/recent`,    label: 'Recent',    icon: '#i-play' },
        { href: `${base}/jobs/failed`,    label: 'Failed',    icon: '#i-alert', badge: failed || null, alert: failed > 0 },
        { href: `${base}/jobs/pending`,   label: 'Pending',   icon: '#i-clock' },
        { href: `${base}/jobs/completed`, label: 'Completed', icon: '#i-check' },
        { href: `${base}/batches`,        label: 'Batches',   icon: '#i-layers' },
      ],
    },
    {
      label: 'Operations',
      items: [
        { href: `${base}/supervisors`, label: 'Supervisors', icon: '#i-server' },
        { href: `${base}/rate-limits`, label: 'Rate limits', icon: '#i-gauge' },
        { href: `${base}/monitoring`,  label: 'Monitoring',  icon: '#i-hash' },
        { href: `${base}/health`,      label: 'Health',      icon: '#i-heart' },
      ],
    },
  ];
});

function isActive(href) {
  const path = (currentPath.value || '').replace(/\/+$/, '') || '/';
  const target = href.replace(/\/+$/, '') || '/';
  return path === target;
}

// Real installed package version, shared by SetSunsetInertiaRoot.
const version = computed(() => {
  const v = page.props?.sunset?.version;
  return v ? `Sunset ${v}` : 'Sunset';
});
</script>

<template>
  <nav
    aria-label="Dashboard navigation"
    class="sunset-rail w-[232px] sticky top-[95px] self-start overflow-y-auto border-r border-border-soft"
    style="padding: 16px 12px; max-height: calc(100vh - 95px);"
  >
    <div v-for="group in groups" :key="group.label" class="mb-[18px]">
      <div
        class="font-mono uppercase font-medium"
        style="font-size: 10.5px; color: var(--dim); letter-spacing: 0.06em; padding: 0 12px 8px;"
      >{{ group.label }}</div>

      <Link
        v-for="item in group.items"
        :key="item.href"
        :href="item.href"
        :aria-current="isActive(item.href) ? 'page' : undefined"
        :class="[
          'sunset-rail-link relative flex items-center gap-2.5 rounded-md transition-colors',
          isActive(item.href) ? 'is-active text-text' : 'text-muted hover:text-text',
          item.alert ? 'is-alert' : '',
        ]"
        :style="isActive(item.href) ? 'background: var(--violet-soft);' : ''"
      >
        <svg class="w-[15px] h-[15px] shrink-0 transition-colors"><use :href="item.icon"/></svg>
        <span>{{ item.label }}</span>
        <span
          v-if="item.badge != null"
          class="sunset-rail-badge ml-auto font-mono font-medium"
          :class="item.alert ? 'is-alert' : ''"
        >{{ item.badge }}</span>
      </Link>
    </div>

    <div class="mt-6 pt-3 border-t border-border-soft" style="padding-left: 12px; padding-right: 12px;">
      <div class="font-mono flex items-center gap-1.5" style="font-size: 11px; color: var(--muted);">
        <span class="w-[5px] h-[5px] rounded-full" style="background: var(--green);"></span>
        {{ version }}
      </div>
    </div>
  </nav>
</template>

<style scoped>
.sunset-rail::-webkit-scrollbar { width: 6px; }
.sunset-rail::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

.sunset-rail-link {
  padding: 6px 12px;
  font-size: 13.5px;
  cursor: pointer;
  margin-bottom: 1px;
}
.sunset-rail-link svg { color: var(--dim); }
.sunset-rail-link:hover { background: var(--bg-3); }
.sunset-rail-link:hover svg { color: var(--muted); }

.sunset-rail-link.is-active svg { color: var(--violet); }
.sunset-rail-link.is-active::before {
  content: '';
  position: absolute;
  left: -12px;
  top: 50%;
  transform: translateY(-50%);
  width: 3px;
  height: 16px;
  background: var(--violet);
  border-radius: 0 2px 2px 0;
}

.sunset-rail-badge {
  font-size: 10.5px;
  padding: 1px 7px;
  background: var(--bg-3);
  color: var(--muted);
  border-radius: 10px;
  letter-spacing: 0.02em;
}
.sunset-rail-badge.is-alert {
  background: var(--red-soft);
  color: var(--red);
}
</style>
