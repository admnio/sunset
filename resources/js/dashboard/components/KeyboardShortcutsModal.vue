<!--
  KeyboardShortcutsModal — global help overlay triggered by `?`.

  Mount once in Layout.vue. Visibility toggled via `useShortcutsStore`.
  Content + classnames copied verbatim from the v3 mockup so the
  visual style matches.
-->
<script setup>
import { useShortcutsStore } from '../stores/shortcutsStore.js';

const shortcuts = useShortcutsStore();
</script>

<template>
  <div
    :class="['kbd-modal-backdrop', { open: shortcuts.isOpen }]"
    @click.self="shortcuts.close"
    role="dialog"
    aria-modal="true"
    aria-label="Keyboard shortcuts"
  >
    <div class="kbd-modal" @click.stop>
      <div class="head">
        <h3>Keyboard shortcuts</h3>
        <button class="close" type="button" @click="shortcuts.close">esc</button>
      </div>
      <div class="kbd-grid">
        <div class="kbd-section-label">Navigation</div>
        <div class="kbd-row"><span>Command palette</span><span class="kbd-keys"><span class="kbd-key">⌘</span><span class="kbd-key">K</span></span></div>
        <div class="kbd-row"><span>This help</span><span class="kbd-keys"><span class="kbd-key">?</span></span></div>
        <div class="kbd-row"><span>Overview</span><span class="kbd-keys"><span class="kbd-key">G</span><span class="kbd-key">O</span></span></div>
        <div class="kbd-row"><span>Activity</span><span class="kbd-keys"><span class="kbd-key">G</span><span class="kbd-key">A</span></span></div>
        <div class="kbd-row"><span>Workload</span><span class="kbd-keys"><span class="kbd-key">G</span><span class="kbd-key">W</span></span></div>
        <div class="kbd-row"><span>Failed jobs</span><span class="kbd-keys"><span class="kbd-key">G</span><span class="kbd-key">F</span></span></div>
        <div class="kbd-row"><span>Supervisors</span><span class="kbd-keys"><span class="kbd-key">G</span><span class="kbd-key">S</span></span></div>
        <div class="kbd-row"><span>Health</span><span class="kbd-keys"><span class="kbd-key">G</span><span class="kbd-key">H</span></span></div>

        <div class="kbd-section-label">Actions</div>
        <div class="kbd-row"><span>Refresh page</span><span class="kbd-keys"><span class="kbd-key">R</span></span></div>
        <div class="kbd-row"><span>Toggle theme</span><span class="kbd-keys"><span class="kbd-key">T</span></span></div>
        <div class="kbd-row"><span>Retry selected</span><span class="kbd-keys"><span class="kbd-key">⌘</span><span class="kbd-key">⏎</span></span></div>
        <div class="kbd-row"><span>Delete selected</span><span class="kbd-keys"><span class="kbd-key">⌘</span><span class="kbd-key">⌫</span></span></div>
        <div class="kbd-row"><span>Pause queue (cursor)</span><span class="kbd-keys"><span class="kbd-key">P</span></span></div>
        <div class="kbd-row"><span>Select all</span><span class="kbd-keys"><span class="kbd-key">⌘</span><span class="kbd-key">A</span></span></div>
        <div class="kbd-row"><span>Close modal</span><span class="kbd-keys"><span class="kbd-key">esc</span></span></div>
        <div class="kbd-row"><span>Open shortcuts</span><span class="kbd-keys"><span class="kbd-key">?</span></span></div>
      </div>
    </div>
  </div>
</template>
