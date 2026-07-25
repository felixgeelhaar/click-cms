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
    /**
     * The accessibility tests mount a whole screen and run axe over it, which
     * is seconds of real work rather than the milliseconds every other test
     * here takes. Vitest's 5s default is comfortable on a developer's machine
     * and not comfortable anywhere slower.
     *
     * The release build publishes a linux/arm64 image, which on an amd64 runner
     * is built under QEMU emulation. Every axe test took between 5.5 and 11
     * seconds there and nine of them timed out, which failed the image build
     * for the 1.2.0 release — reported as nine accessibility failures when
     * nothing was inaccessible.
     *
     * Raised rather than removed or scoped to those tests: a timeout is not the
     * property under test, and a suite that passes only on fast hardware tells
     * you about the hardware. Everything else here still finishes in
     * milliseconds, so a genuine hang is still caught, just later.
     */
    testTimeout: 30_000,
  },
});
