import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// The dev server proxies the delivery API to a locally running click-cms so the
// SPA is same-origin in development too — the same shape nginx gives it in the
// container (see nginx.conf). Override the target with CMS_ORIGIN.
export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': { target: process.env.CMS_ORIGIN || 'http://localhost:8080', changeOrigin: true },
    },
  },
});
