<script setup>
import LeftRail from './components/LeftRail.vue';
import HeaderBar from './components/Header.vue';
import CommandPalette from './components/CommandPalette.vue';
import { useKeyboard } from './composables/useKeyboard.js';

useKeyboard();
</script>

<template>
  <div class="min-h-screen bg-sunset-bg text-sunset-text font-mono">
    <a
      href="#sunset-main"
      class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 bg-sunset-accent text-sunset-bg px-3 py-1 rounded text-xs font-bold"
    >Skip to content</a>
    <HeaderBar />
    <div class="flex">
      <LeftRail class="hidden md:block" />
      <main id="sunset-main" tabindex="-1" class="flex-1 p-4">
        <slot />
      </main>
    </div>
    <CommandPalette />
  </div>
</template>
