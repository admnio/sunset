import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  // Vue (and other deps) reference process.env.NODE_ENV for dev/prod
  // branching. In a library/IIFE build that isn't replaced automatically, so
  // the browser throws `process is not defined`. Pin it to production for the
  // shipped dashboard bundle; this also dead-code-eliminates Vue's dev warnings.
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: 'public-dist',
    emptyOutDir: true,
    lib: {
      entry: 'resources/js/dashboard/app.js',
      formats: ['iife'],
      name: 'sunsetDashboard',
      fileName: () => 'app.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'app.[ext]',
      },
    },
  },
  plugins: [vue()],
});
