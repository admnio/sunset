import { defineStore } from 'pinia';

/**
 * Pinia store for the global keyboard-shortcuts modal (`?`).
 *
 * Same shape as `paletteStore` — `isOpen` is the canonical state key
 * to avoid colliding with the `open()` action name.
 */
export const useShortcutsStore = defineStore('shortcuts', {
  state: () => ({ isOpen: false }),
  actions: {
    open()   { this.isOpen = true; },
    close()  { this.isOpen = false; },
    toggle() { this.isOpen = !this.isOpen; },
  },
});
