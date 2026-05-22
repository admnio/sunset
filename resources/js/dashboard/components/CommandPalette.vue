<!--
  CommandPalette — ⌘K-triggered global launcher.

  Categorized search (pages / queues / job classes / actions). Pages
  navigate via Inertia `router.visit()`. Queues + classes are decorative
  placeholders for now (Phase 5+ will wire them to real data). Actions
  fire a confirmation toast and close the palette.

  Keyboard within the palette:
    ↑ / ↓ — move focus through the flattened list of visible items
    ↵    — activate the focused item
    Esc  — handled globally by useKeyboard()
-->
<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePaletteStore } from '../stores/paletteStore.js';
import { useToasts } from '../composables/useToasts.js';

const palette = usePaletteStore();
const toasts = useToasts();

const query = ref('');
const inputEl = ref(null);
const focused = ref(0);

// ─── Catalog ────────────────────────────────────────────────────────
// Pages — all 13 dashboard routes. `meta` is the G-prefix hint.

const pages = [
  { label: 'Overview',          href: '/sunset',                 icon: '#i-home',     meta: 'G O' },
  { label: 'Activity stream',   href: '/sunset/activity',        icon: '#i-activity', meta: 'G A' },
  { label: 'Workload',          href: '/sunset/workload',        icon: '#i-list',     meta: 'G W' },
  { label: 'Metrics',           href: '/sunset/metrics',         icon: '#i-chart',    meta: 'G M' },
  { label: 'Failed jobs',       href: '/sunset/jobs/failed',     icon: '#i-alert',    meta: 'G F' },
  { label: 'Supervisors',       href: '/sunset/supervisors',     icon: '#i-server',   meta: 'G S' },
  { label: 'Health',            href: '/sunset/health',          icon: '#i-heart',    meta: 'G H' },
  { label: 'Pending jobs',      href: '/sunset/jobs/pending',    icon: '#i-clock',    meta: 'G P' },
  { label: 'Completed jobs',    href: '/sunset/jobs/completed',  icon: '#i-check',    meta: 'G C' },
  { label: 'Recent jobs',       href: '/sunset/jobs/recent',     icon: '#i-list',     meta: 'G R' },
  { label: 'Rate limits',       href: '/sunset/rate-limits',     icon: '#i-gauge',    meta: 'G L' },
  { label: 'Batches',           href: '/sunset/batches',         icon: '#i-layers',   meta: 'G B' },
  { label: 'Monitoring (tags)', href: '/sunset/monitoring',      icon: '#i-tag',      meta: 'G T' },
];

// Queues — decorative samples; wire to real data in Phase 5+.
const queues = [
  { label: 'emails',    meta: 'redis · 128 pending',   icon: '#i-list' },
  { label: 'webhooks',  meta: 'sqs · 2,431 pending',   icon: '#i-list' },
  { label: 'indexing',  meta: 'redis · backlog 48s',   icon: '#i-list' },
  { label: 'geocode',   meta: 'redis · paused',        icon: '#i-list' },
];

// Job classes — decorative placeholders. Navigation target lands on the
// Phase-7 ClassDetail route — page itself doesn't exist yet but the URL
// is fine to point at for the mockup-stage build.
const classes = [
  { label: 'App\\Jobs\\NotifySlack',        meta: '405/min',           icon: '#i-zap' },
  { label: 'App\\Jobs\\IndexProduct',       meta: '196/min · 2 failed', icon: '#i-zap' },
  { label: 'App\\Jobs\\SendWelcomeEmail',   meta: '218/min',           icon: '#i-zap' },
];

// Actions — fire a confirmation toast and close.
const actions = [
  { label: 'Pause queue…',         meta: 'action',    icon: '#i-pause',
    toast: { kind: 'ok',   title: 'Queue paused.', sub: 'Workers will stop popping on next loop.', undo: true } },
  { label: 'Retry all failed',     meta: 'action · ⌘⏎', icon: '#i-retry',
    toast: { kind: 'ok',   title: 'Retry queued.', sub: '2 jobs re-enqueued.' } },
  { label: 'Re-probe transports',  meta: 'action',    icon: '#i-refresh',
    toast: { kind: 'info', title: 'Refreshed.' } },
];

// ─── Filtering ──────────────────────────────────────────────────────
function matches(item, q) {
  if (!q) return true;
  const hay = (item.label + ' ' + (item.meta ?? '')).toLowerCase();
  return hay.includes(q);
}

const groups = computed(() => {
  const q = query.value.trim().toLowerCase();
  return [
    { key: 'pages',   title: 'Pages',       kind: 'page',   items: pages.filter((i) => matches(i, q)) },
    { key: 'queues',  title: 'Queues',      kind: 'queue',  items: queues.filter((i) => matches(i, q)) },
    { key: 'classes', title: 'Job classes', kind: 'class',  items: classes.filter((i) => matches(i, q)) },
    { key: 'actions', title: 'Actions',     kind: 'action', items: actions.filter((i) => matches(i, q)) },
  ].filter((g) => g.items.length > 0);
});

// Flattened list with metadata, so ↑↓ ↵ work seamlessly across groups.
const flat = computed(() => {
  const out = [];
  for (const g of groups.value) {
    for (const item of g.items) {
      out.push({ kind: g.kind, item });
    }
  }
  return out;
});

// ─── Open / close / focus management ────────────────────────────────
watch(() => palette.isOpen, async (isOpen) => {
  if (isOpen) {
    query.value = '';
    focused.value = 0;
    await nextTick();
    inputEl.value?.focus();
  }
});

// Reset focus to top when the result set changes shape.
watch(flat, () => { focused.value = 0; });

// ─── Activation ─────────────────────────────────────────────────────
function activate(idx) {
  const entry = flat.value[idx];
  if (!entry) return;
  const { kind, item } = entry;

  if (kind === 'page') {
    palette.close();
    router.visit(item.href);
  } else if (kind === 'queue') {
    // Mockup-stage: queues are decorative; just toast for now.
    palette.close();
    toasts.push({ kind: 'info', title: `Queue: ${item.label}`, sub: 'Queue drill-down lands in a later phase.' });
  } else if (kind === 'class') {
    palette.close();
    router.visit(`/sunset/metrics/jobs/${encodeURIComponent(item.label)}`);
  } else if (kind === 'action') {
    palette.close();
    toasts.push(item.toast ?? { kind: 'ok', title: item.label, sub: 'Action queued.' });
  }
}

// ─── Local keyboard (↑ ↓ ↵) — Esc is handled globally ──────────────
function onKey(e) {
  if (!palette.isOpen) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    focused.value = Math.min(focused.value + 1, flat.value.length - 1);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    focused.value = Math.max(focused.value - 1, 0);
  } else if (e.key === 'Enter') {
    e.preventDefault();
    activate(focused.value);
  }
}

// Compute the flat index of an item within a group, given the rendered
// (group, item) pair. Used to drive `.active` class + click handler.
function flatIndex(groupKind, itemIndex) {
  let i = 0;
  for (const g of groups.value) {
    if (g.kind === groupKind) return i + itemIndex;
    i += g.items.length;
  }
  return -1;
}
</script>

<template>
  <div
    :class="['palette-backdrop', { open: palette.isOpen }]"
    @click.self="palette.close"
    role="dialog"
    aria-modal="true"
    aria-label="Command palette"
  >
    <div class="palette" @click.stop @keydown="onKey">
      <div class="palette-input">
        <svg aria-hidden="true"><use href="#i-search"/></svg>
        <input
          ref="inputEl"
          v-model="query"
          type="text"
          autocomplete="off"
          spellcheck="false"
          placeholder="Jump to anything — pages, queues, job classes, supervisors…"
          aria-label="Search palette"
        />
        <span class="esc">esc</span>
      </div>

      <div class="palette-results" role="listbox">
        <div
          v-for="g in groups"
          :key="g.key"
          class="palette-group"
        >
          <div class="gh">{{ g.title }}</div>
          <button
            v-for="(item, i) in g.items"
            :key="g.key + ':' + item.label"
            type="button"
            :class="['palette-item', { active: flatIndex(g.kind, i) === focused }]"
            role="option"
            :aria-selected="flatIndex(g.kind, i) === focused"
            @click="activate(flatIndex(g.kind, i))"
            @mouseenter="focused = flatIndex(g.kind, i)"
          >
            <svg class="ico" aria-hidden="true"><use :href="item.icon"/></svg>
            <span class="label">{{ item.label }}</span>
            <span class="meta">{{ item.meta }}</span>
          </button>
        </div>
        <div
          v-if="flat.length === 0"
          class="palette-group"
          role="status"
        >
          <div class="gh">No matches</div>
        </div>
      </div>

      <div class="palette-foot">
        <span class="pf-grp"><span class="kbd">↑</span><span class="kbd">↓</span> navigate</span>
        <span class="pf-grp"><span class="kbd">↵</span> open</span>
        <span class="pf-grp"><span class="kbd">esc</span> close</span>
        <span class="right">Search across 13 pages, {{ queues.length }} queues, {{ classes.length }} classes</span>
      </div>
    </div>
  </div>
</template>
