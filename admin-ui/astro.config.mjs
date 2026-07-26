import { defineConfig } from 'astro/config';
import vue from '@astrojs/vue';

export default defineConfig({
  integrations: [vue()],
  output: 'static',
  base: '/admin',
  vite: {
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
    format: 'directory',
    // Assets are addressed relative to the document, so this one build works
    // wherever the CMS is installed: `./_astro/app.js` resolves under
    // /admin/ and under /2026/cms/admin/ alike. With Astro's default the
    // markup names /admin/_astro/… from the domain root, and a subdirectory
    // install loads a page whose script and stylesheet both 404.
    //
    // The alternative would be building the admin per installation, which would
    // put a build step back into deployment — the thing this project does not
    // ask of a site on shared hosting.
    assetsPrefix: '.'
  }
});
