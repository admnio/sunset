import { defineStore } from 'pinia';

/**
 * Pinia store for the global command palette (⌘K).
 *
 * NOTE: State uses `isOpen` (not `open`) to avoid colliding with the
 * action name `open()` — Pinia merges state + actions onto the store
 * proxy, so a state key and action sharing the same name would shadow
 * each other. Readers should access `store.isOpen`; legacy `show`/`hide`
 * are kept as aliases for any v1 code that hasn't been migrated.
 */
export const usePaletteStore = defineStore('palette', {
  state: () => ({ isOpen: false }),
  actions: {
    open()   { this.isOpen = true; },
    close()  { this.isOpen = false; },
    toggle() { this.isOpen = !this.isOpen; },

    // Legacy aliases (v1) — safe to keep around.
    show()   { this.isOpen = true; },
    hide()   { this.isOpen = false; },
  },
});
