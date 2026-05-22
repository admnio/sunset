module.exports = {
  content: ['./resources/js/dashboard/**/*.{vue,js}', './resources/views/sunset-app.blade.php'],
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Geist', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'sans-serif'],
        mono: ['"Geist Mono"', 'ui-monospace', '"Cascadia Code"', 'Menlo', 'monospace'],
      },
      colors: {
        // v2.0 — neutrals
        bg:       'rgb(var(--bg) / <alpha-value>)',
        'bg-2':   'rgb(var(--bg-2) / <alpha-value>)',
        'bg-3':   'rgb(var(--bg-3) / <alpha-value>)',
        'bg-4':   'rgb(var(--bg-4) / <alpha-value>)',
        card:     'rgb(var(--card) / <alpha-value>)',
        'card-hover': 'rgb(var(--card-hover) / <alpha-value>)',
        border:   'rgb(var(--border) / <alpha-value>)',
        'border-soft': 'rgb(var(--border-soft) / <alpha-value>)',
        'border-strong': 'rgb(var(--border-strong) / <alpha-value>)',
        text:     'rgb(var(--text) / <alpha-value>)',
        'text-2': 'rgb(var(--text-2) / <alpha-value>)',
        muted:    'rgb(var(--muted) / <alpha-value>)',
        dim:      'rgb(var(--dim) / <alpha-value>)',
        faint:    'rgb(var(--faint) / <alpha-value>)',
        // v2.0 — accent
        violet:        'rgb(var(--violet) / <alpha-value>)',
        'violet-2':    'rgb(var(--violet-2) / <alpha-value>)',
        'violet-deep': 'rgb(var(--violet-deep) / <alpha-value>)',
        // status (theme-aware via CSS vars)
        ok:    'rgb(var(--green) / <alpha-value>)',
        warn:  'rgb(var(--amber) / <alpha-value>)',
        err:   'rgb(var(--red) / <alpha-value>)',
        info:  'rgb(var(--blue) / <alpha-value>)',
        // Back-compat sunset aliases — point them at v2 tokens so existing pages
        // continue to render until they're migrated in Phase 5–6.
        sunset: {
          bg:     'rgb(var(--bg) / <alpha-value>)',
          rail:   'rgb(var(--bg-2) / <alpha-value>)',
          card:   'rgb(var(--card) / <alpha-value>)',
          border: 'rgb(var(--border-soft) / <alpha-value>)',
          text:   'rgb(var(--text) / <alpha-value>)',
          muted:  'rgb(var(--muted) / <alpha-value>)',
          accent: 'rgb(var(--violet) / <alpha-value>)',
        },
        status: {
          error: 'rgb(var(--red) / <alpha-value>)',
          warn:  'rgb(var(--amber) / <alpha-value>)',
          ok:    'rgb(var(--green) / <alpha-value>)',
          info:  'rgb(var(--blue) / <alpha-value>)',
        },
      },
      boxShadow: {
        sm: 'var(--shadow-sm)',
        DEFAULT: 'var(--shadow-md)',
        lg: 'var(--shadow-lg)',
        violet: 'var(--shadow-violet)',
      },
      backdropBlur: { sm: '8px', DEFAULT: '12px' },
    },
  },
  plugins: [],
};
