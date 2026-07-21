import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

/**
 * Component tests for the admin UI.
 *
 * Added after four defects reached the merged branch that no test could have
 * caught, because there was no frontend test framework at all: removing the
 * `status` field left the page list and the dashboard reporting a fully live
 * site as "0 published", and adding a language segment to the content key broke
 * every slug the list rendered and made Delete remove the wrong language's
 * document. Every one was found by reading code.
 *
 * These run in jsdom rather than a browser on purpose. The bugs above were all
 * logic — deriving state from a payload, building a URL from a key — and none
 * needed a real browser to expose. A browser harness is a separate, slower tool
 * for a separate question.
 *
 * Test dependencies are dev-only. The "no runtime dependencies" rule in
 * docs/core.md is about what a site must install to run the CMS; nothing here
 * ships in the image or is required to serve a page.
 */
export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.js'],
    restoreMocks: true,
  },
});
