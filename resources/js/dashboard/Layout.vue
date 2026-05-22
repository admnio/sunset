<script setup>
import IconSprite from './components/IconSprite.vue';
import LeftRail from './components/LeftRail.vue';
import HeaderBar from './components/Header.vue';
import HealthStrip from './components/HealthStrip.vue';
import CommandPalette from './components/CommandPalette.vue';
import { useKeyboard } from './composables/useKeyboard.js';

useKeyboard();
</script>

<template>
  <div class="min-h-screen text-text" style="background: var(--bg);">
    <!-- Shared SVG icon defs — must be mounted once for <use href="#i-*"/> refs to resolve. -->
    <IconSprite />

    <a
      href="#sunset-main"
      class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] px-3 py-1 rounded text-xs font-mono font-semibold"
      style="background: var(--violet); color: #fff;"
    >Skip to content</a>

    <HeaderBar />
    <HealthStrip />

    <div class="grid" style="grid-template-columns: 232px 1fr; min-height: calc(100vh - 56px);">
      <LeftRail />
      <main
        id="sunset-main"
        tabindex="-1"
        class="p-7 max-w-[1480px] focus:outline-none"
      >
        <slot />
      </main>
    </div>

    <CommandPalette />
    <!-- TODO(v2-phase-4): mount <ToastContainer /> and <KeyboardShortcutsModal /> here. -->
  </div>
</template>
