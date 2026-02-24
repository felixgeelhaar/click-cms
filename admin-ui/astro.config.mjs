import { defineConfig } from 'astro/config';
import vue from '@astrojs/vue';
import tsconfigPaths from 'vite-tsconfig-paths';

export default defineConfig({
  integrations: [vue()],
  output: 'static',
  base: '/admin',
  vite: {
    plugins: [tsconfigPaths()],
    server: {
      proxy: {
        '/api': {
          target: process.env.CLICK_CMS_API_URL || 'http://localhost:8080',
          changeOrigin: true
        }
      }
    },
    build: {
      rollupOptions: {
        external: []
      }
    }
  },
  build: {
    format: 'directory'
  }
});
