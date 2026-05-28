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
import { router, usePage } from '@inertiajs/vue3';
import { usePaletteStore } from '../stores/paletteStore.js';

const palette = usePaletteStore();
const page = usePage();

const query = ref('');
const inputEl = ref(null);
const focused = ref(0);

// Sunset dashboard base path (shared by SetSunsetInertiaRoot middleware).
const basePath = computed(() => {
  const p = page.props?.sunset?.path ?? 'sunset';
  return '/' + String(p).replace(/^\/+/, '');
});

// ─── Catalog ────────────────────────────────────────────────────────
// Pages — all 13 dashboard routes. `meta` is the G-prefix hint.

const pages = computed(() => {
  const b = basePath.value;
  return [
    { label: 'Overview',          href: b,                    icon: '#i-home',     meta: 'G O' },
    { label: 'Activity stream',   href: `${b}/activity`,      icon: '#i-activity', meta: 'G A' },
    { label: 'Workload',          href: `${b}/workload`,      icon: '#i-list',     meta: 'G W' },
    { label: 'Metrics',           href: `${b}/metrics`,       icon: '#i-chart',    meta: 'G M' },
    { label: 'Failed jobs',       href: `${b}/jobs/failed`,   icon: '#i-alert',    meta: 'G F' },
    { label: 'Supervisors',       href: `${b}/supervisors`,   icon: '#i-server',   meta: 'G S' },
    { label: 'Health',            href: `${b}/health`,        icon: '#i-heart',    meta: 'G H' },
    { label: 'Pending jobs',      href: `${b}/jobs/pending`,  icon: '#i-clock',    meta: 'G P' },
    { label: 'Completed jobs',    href: `${b}/jobs/completed`,icon: '#i-check',    meta: 'G C' },
    { label: 'Recent jobs',       href: `${b}/jobs/recent`,   icon: '#i-list',     meta: 'G R' },
    { label: 'Rate limits',       href: `${b}/rate-limits`,   icon: '#i-gauge',    meta: 'G L' },
    { label: 'Batches',           href: `${b}/batches`,       icon: '#i-layers',   meta: 'G B' },
    { label: 'Monitoring (tags)', href: `${b}/monitoring`,    icon: '#i-tag',      meta: 'G T' },
  ];
});

// Queues + job classes — real data, lazy-loaded from /search-index the first
// time the palette opens (kept off the hot path of normal navigation).
const queues = ref([]);
const classes = ref([]);
const indexLoaded = ref(false);

async function loadIndex() {
  if (indexLoaded.value) return;
  indexLoaded.value = true;
  try {
    const res = await fetch(`${basePath.value}/search-index`, { headers: { Accept: 'application/json' } });
    if (!res.ok) { indexLoaded.value = false; return; }
    const json = await res.json();
    queues.value = (json.queues ?? []).map((q) => ({ ...q, icon: '#i-list' }));
    classes.value = (json.classes ?? []).map((c) => ({ ...c, icon: '#i-zap' }));
  } catch {
    indexLoaded.value = false; // allow a retry on the next open
  }
}

// ─── Filtering ──────────────────────────────────────────────────────
function matches(item, q) {
  if (!q) return true;
  const hay = (item.label + ' ' + (item.meta ?? '')).toLowerCase();
  return hay.includes(q);
}

const groups = computed(() => {
  const q = query.value.trim().toLowerCase();
  return [
    { key: 'pages',   title: 'Pages',       kind: 'page',  items: pages.value.filter((i) => matches(i, q)) },
    { key: 'queues',  title: 'Queues',      kind: 'queue', items: queues.value.filter((i) => matches(i, q)) },
    { key: 'classes', title: 'Job classes', kind: 'class', items: classes.value.filter((i) => matches(i, q)) },
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
    loadIndex();
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
    palette.close();
    router.visit(`${basePath.value}/workload`);
  } else if (kind === 'class') {
    palette.close();
    router.visit(`${basePath.value}/metrics/jobs/${encodeURIComponent(item.label)}/detail`);
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
          placeholder="Jump to anything — pages, queues, job classes…"
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
        <span class="right">Search across {{ pages.length }} pages, {{ queues.length }} queues, {{ classes.length }} classes</span>
      </div>
    </div>
  </div>
</template>
