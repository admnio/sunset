import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePaletteStore } from '../stores/paletteStore.js';
import { useShortcutsStore } from '../stores/shortcutsStore.js';
import { useTheme } from '../composables/useTheme.js';
import { useToasts } from '../composables/useToasts.js';

/**
 * Global keyboard shortcut handler.
 *
 * Called from Layout.vue. Wires document-level keydown listening so
 * ⌘K / ? / Esc / T / R and the `G`-prefix navigation chord work
 * everywhere except inside input/textarea/contenteditable surfaces.
 *
 * Idempotent — a module-level flag stops repeated installs from
 * stacking listeners if the layout remounts.
 */

// G-prefix → route map. R clashes with the standalone "Refresh"
// shortcut; the G prefix wins (this is the documented behavior).
const G_ROUTES = {
  o: '/sunset',
  a: '/sunset/activity',
  w: '/sunset/workload',
  m: '/sunset/metrics',
  f: '/sunset/jobs/failed',
  s: '/sunset/supervisors',
  h: '/sunset/health',
  p: '/sunset/jobs/pending',
  c: '/sunset/jobs/completed',
  l: '/sunset/rate-limits',
  r: '/sunset/jobs/recent',
  b: '/sunset/batches',
  t: '/sunset/monitoring',
};

const G_PREFIX_WINDOW_MS = 800;

let _installed = false;
let _handler = null;
let _gPrefixActive = false;
let _gPrefixTimer = null;

function isEditable(el) {
  if (!el) return false;
  const tag = el.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (el.isContentEditable) return true;
  return false;
}

function clearGPrefix() {
  _gPrefixActive = false;
  if (_gPrefixTimer) {
    clearTimeout(_gPrefixTimer);
    _gPrefixTimer = null;
  }
}

function install() {
  if (_installed) return;

  const palette = usePaletteStore();
  const shortcuts = useShortcutsStore();
  const theme = useTheme();
  const toasts = useToasts();

  _handler = (e) => {
    // Esc closes any open modal — palette takes precedence.
    if (e.key === 'Escape') {
      if (palette.isOpen) {
        e.preventDefault();
        palette.close();
        return;
      }
      if (shortcuts.isOpen) {
        e.preventDefault();
        shortcuts.close();
        return;
      }
      // Esc also cancels a pending G-prefix.
      if (_gPrefixActive) {
        clearGPrefix();
        return;
      }
      return;
    }

    // ⌘K / Ctrl+K — toggle palette. Works even from inside inputs.
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      palette.toggle();
      return;
    }

    // From here on we ignore shortcuts when the user is typing into a field.
    if (isEditable(document.activeElement)) return;

    // Ignore plain modifier keys + chord modifiers we don't bind.
    if (e.altKey || e.metaKey || e.ctrlKey) return;

    // `?` — toggle shortcuts modal. The browser fires `?` as Shift+/,
    // so accept either spelling.
    if (e.key === '?' || (e.shiftKey && e.key === '/')) {
      e.preventDefault();
      shortcuts.toggle();
      return;
    }

    // G-prefix second key.
    if (_gPrefixActive) {
      const key = e.key.toLowerCase();
      const route = G_ROUTES[key];
      clearGPrefix();
      if (route) {
        e.preventDefault();
        router.visit(route);
      }
      return;
    }

    const lower = e.key.toLowerCase();

    // Start a G-prefix chord.
    if (lower === 'g') {
      _gPrefixActive = true;
      _gPrefixTimer = window.setTimeout(clearGPrefix, G_PREFIX_WINDOW_MS);
      return;
    }

    // Standalone single-key shortcuts.
    if (lower === 't') {
      e.preventDefault();
      theme.cycle();
      return;
    }
    if (lower === 'r') {
      e.preventDefault();
      // Phase 4 stub — usePolling can subscribe to this later. For now
      // just confirm via toast so the keybinding is observable.
      toasts.push({ kind: 'info', title: 'Refreshed.' });
      return;
    }
  };

  document.addEventListener('keydown', _handler);
  _installed = true;
}

function uninstall() {
  if (!_installed) return;
  document.removeEventListener('keydown', _handler);
  _handler = null;
  _installed = false;
  clearGPrefix();
}

/**
 * Side-effect form: call from a component's `<script setup>` to
 * register/unregister on mount/unmount. The module-level singleton
 * flag protects against double-install if Layout.vue ever re-runs.
 */
export function useKeyboard() {
  onMounted(install);
  onBeforeUnmount(uninstall);
  return { install, uninstall };
}
