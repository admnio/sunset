import { ref } from 'vue';

// Tri-state theme: 'light' | 'dark' | 'system'
//
// - The inline script in sunset-app.blade.php sets data-theme before paint to
//   prevent FOUC. This composable manages the user-visible state ("which choice
//   did the user pick") and persistence.
// - The 'system' choice respects `prefers-color-scheme` and listens for OS
//   changes; the resolved value is written to data-theme on the html element.

const STORAGE_KEY = 'sunset.theme';
const choice = ref('system');         // raw user preference
const effective = ref('dark');         // resolved 'light' | 'dark'
let mediaQuery = null;

function resolveEffective(pick) {
  if (pick === 'light' || pick === 'dark') return pick;
  return mediaQuery && mediaQuery.matches ? 'light' : 'dark';
}

function apply(pick) {
  const eff = resolveEffective(pick);
  document.documentElement.setAttribute('data-theme', eff);
  effective.value = eff;
}

export function useTheme() {
  function bootstrap() {
    if (!mediaQuery && typeof window !== 'undefined') {
      mediaQuery = window.matchMedia('(prefers-color-scheme: light)');
      mediaQuery.addEventListener?.('change', () => {
        if (choice.value === 'system') apply('system');
      });
    }
    const stored = localStorage.getItem(STORAGE_KEY);
    choice.value = stored === 'light' || stored === 'dark' ? stored : 'system';
    apply(choice.value);
  }

  function set(t) {
    choice.value = t;
    if (t === 'system') localStorage.removeItem(STORAGE_KEY);
    else localStorage.setItem(STORAGE_KEY, t);
    apply(t);
  }

  // Cycle: system -> light -> dark -> system
  function cycle() {
    const cur = choice.value;
    const next = cur === 'system' ? 'light' : cur === 'light' ? 'dark' : 'system';
    set(next);
  }

  // Back-compat with v1 callers (toggle between light/dark only).
  function toggle() {
    set(effective.value === 'dark' ? 'light' : 'dark');
  }

  function current() { return choice.value; }

  return { choice, effective, bootstrap, set, cycle, toggle, current };
}
