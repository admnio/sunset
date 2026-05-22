<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useTheme } from '../composables/useTheme.js';
import { usePaletteStore } from '../stores/paletteStore.js';

const palette = usePaletteStore();
const theme = useTheme();
const page = usePage();

// Sunset dashboard base path — shared by SetSunsetInertiaRoot middleware.
const basePath = computed(() => {
  const p = page.props?.sunset?.path ?? 'sunset';
  return '/' + String(p).replace(/^\/+/, '');
});

// Environment label. TODO(v2-shared-props): wire to Inertia shared props
// (e.g. config('app.env')) once middleware extension lands.
const envLabel = computed(() => page.props?.sunset?.env ?? 'prod-east');

// Breadcrumb title — derived from the Inertia component name
// (e.g. "Sunset/Overview" -> "Overview").
const crumbTitle = computed(() => {
  const raw = page.props?.sunset?.pageTitle
    ?? (page.component ? String(page.component).split('/').pop() : 'Overview');
  // Convert RateLimits -> Rate Limits, ClassDetail -> Class Detail
  return raw.replace(/([a-z])([A-Z])/g, '$1 $2');
});

// Theme button icon — reflects current choice (system / light / dark).
const themeIcon = computed(() => {
  if (theme.choice.value === 'light') return '#i-sun';
  if (theme.choice.value === 'dark') return '#i-moon';
  return '#i-monitor';
});

function openShortcuts() {
  // Wired in Phase 4 (KeyboardShortcutsModal). For now expose a global hook
  // so other Phase-4 wiring can call it without touching Header again.
  if (typeof window !== 'undefined' && typeof window.openShortcuts === 'function') {
    window.openShortcuts();
  }
}
</script>

<template>
  <header
    role="banner"
    class="sticky top-0 z-50 h-14 flex items-center gap-[18px] px-5 border-b border-border-soft"
    style="background: var(--topbar-bg); backdrop-filter: blur(12px) saturate(1.4); -webkit-backdrop-filter: blur(12px) saturate(1.4);"
  >
    <!-- Brand -->
    <Link
      :href="basePath"
      class="flex items-center gap-2.5 font-semibold text-text"
      style="font-size: 14.5px; letter-spacing: -0.01em;"
    >
      <span
        class="relative grid place-items-center w-6 h-6 rounded-[7px]"
        style="background: linear-gradient(135deg, var(--violet) 0%, var(--violet-deep) 100%); box-shadow: 0 4px 12px -2px var(--violet-glow), inset 0 1px 0 rgba(255, 255, 255, 0.25);"
        aria-hidden="true"
      >
        <span
          class="block w-[9px] h-[9px] rounded-[2px]"
          style="background: rgba(255, 255, 255, 0.95); transform: rotate(45deg);"
        ></span>
      </span>
      Sunset
    </Link>

    <span class="w-px h-[18px]" style="background: var(--border);" aria-hidden="true"></span>

    <!-- Crumb: env pill + separator + current page title -->
    <div class="flex items-center gap-1.5 text-[13px] text-muted">
      <span
        class="sunset-env-pill inline-flex items-center gap-2 font-mono font-medium px-[9px] py-[4px] rounded-md border"
        style="font-size: 12px; color: var(--text-2); background: var(--bg-3); border-color: var(--border); letter-spacing: 0.02em;"
      >{{ envLabel }}</span>
      <span class="text-[11px]" style="color: var(--faint);">/</span>
      <span class="text-text font-medium">{{ crumbTitle }}</span>
    </div>

    <!-- Search trigger -->
    <div class="ml-auto">
      <button
        type="button"
        @click="palette.toggle"
        aria-label="Open command palette"
        class="flex items-center gap-2.5 w-[280px] text-left rounded-lg border transition-colors"
        style="padding: 7px 12px; background: var(--bg-3); border-color: var(--border); font-size: 13px; color: var(--muted);"
      >
        <svg class="w-3.5 h-3.5 shrink-0" style="color: var(--dim);"><use href="#i-search"/></svg>
        <span class="flex-1">Search queues, jobs, supervisors…</span>
        <span
          class="font-mono px-[5px] py-px rounded border"
          style="font-size: 11px; background: var(--bg); border-color: var(--border); color: var(--dim);"
        >⌘K</span>
      </button>
    </div>

    <!-- Theme cycle -->
    <button
      type="button"
      @click="theme.cycle"
      aria-label="Switch theme"
      class="grid place-items-center w-8 h-8 rounded-lg text-muted hover:text-text transition-colors"
      style="background: transparent;"
      @mouseenter="$event.currentTarget.style.background = 'var(--bg-3)'"
      @mouseleave="$event.currentTarget.style.background = 'transparent'"
    >
      <svg class="w-4 h-4"><use :href="themeIcon"/></svg>
    </button>

    <!-- Help (?) -->
    <button
      type="button"
      @click="openShortcuts"
      aria-label="Keyboard shortcuts"
      title="Shortcuts (?)"
      class="grid place-items-center w-8 h-8 rounded-lg text-muted hover:text-text transition-colors"
      @mouseenter="$event.currentTarget.style.background = 'var(--bg-3)'"
      @mouseleave="$event.currentTarget.style.background = 'transparent'"
    >
      <svg class="w-4 h-4"><use href="#i-info"/></svg>
    </button>

    <!-- Bell (decorative for now) -->
    <button
      type="button"
      aria-label="Notifications"
      class="grid place-items-center w-8 h-8 rounded-lg text-muted hover:text-text transition-colors"
      @mouseenter="$event.currentTarget.style.background = 'var(--bg-3)'"
      @mouseleave="$event.currentTarget.style.background = 'transparent'"
    >
      <svg class="w-4 h-4"><use href="#i-bell"/></svg>
    </button>

    <!-- Settings (decorative for now) -->
    <button
      type="button"
      aria-label="Settings"
      class="grid place-items-center w-8 h-8 rounded-lg text-muted hover:text-text transition-colors"
      @mouseenter="$event.currentTarget.style.background = 'var(--bg-3)'"
      @mouseleave="$event.currentTarget.style.background = 'transparent'"
    >
      <svg class="w-4 h-4"><use href="#i-settings"/></svg>
    </button>

    <!-- Avatar (decorative) -->
    <div
      class="relative w-[30px] h-[30px] rounded-full"
      style="background: linear-gradient(135deg, #fb923c 0%, #ec4899 100%); border: 1.5px solid rgba(255, 255, 255, 0.1);"
      aria-hidden="true"
    >
      <span
        class="absolute -bottom-px -right-px w-[9px] h-[9px] rounded-full"
        style="background: var(--green); border: 2px solid var(--bg);"
      ></span>
    </div>
  </header>
</template>

<style scoped>
/* Animated pulse dot inside the env pill — picks up theme green via CSS var. */
.sunset-env-pill::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--green);
  box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.18);
  animation: sunset-env-pulse 2s ease-in-out infinite;
}
@keyframes sunset-env-pulse {
  50% { opacity: 0.55; }
}
</style>
