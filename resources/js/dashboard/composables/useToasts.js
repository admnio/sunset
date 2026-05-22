import { ref } from 'vue';

/**
 * Toast manager — singleton-style.
 *
 * One shared `toasts` ref backs every call site, so any component
 * (or composable) can `useToasts().push({ ... })` and the message
 * shows up in the mounted `<ToastContainer />`.
 *
 * Each toast auto-dismisses after AUTO_DISMISS_MS (3.8s). Callers
 * can pass `undo: true` to render an "Undo" button; the dismissal
 * handler still fires after the timeout regardless of click.
 *
 * Shape:
 *   { id, kind: 'info' | 'ok' | 'warn' | 'err', title, sub?, undo? }
 *
 * Icon mapping (resolved in ToastContainer.vue):
 *   info → #i-info,  ok → #i-check,  warn → #i-alert,  err → #i-alert
 */

const AUTO_DISMISS_MS = 3800;

// Module-level singleton — survives component unmount.
const toasts = ref([]);
let _nextId = 1;

function dismiss(id) {
  const i = toasts.value.findIndex((t) => t.id === id);
  if (i !== -1) toasts.value.splice(i, 1);
}

function clearAll() {
  toasts.value = [];
}

function push(opts = {}) {
  const t = {
    id: _nextId++,
    kind:  opts.kind ?? 'info',
    title: opts.title ?? 'Done.',
    sub:   opts.sub ?? '',
    undo:  Boolean(opts.undo),
  };
  toasts.value.push(t);
  if (typeof window !== 'undefined') {
    window.setTimeout(() => dismiss(t.id), AUTO_DISMISS_MS);
  }
  return t.id;
}

export function useToasts() {
  return { toasts, push, dismiss, clearAll };
}
