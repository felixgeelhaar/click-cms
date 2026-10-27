# Click design system

Click's Editorial admin uses warm neutral surfaces, restrained indigo actions, a compact navigation rail and flat content rows. Click’s public documentation website uses the same foundations. Websites built with Click can retain their own theme and brand.

`admin-ui/src/styles/editorial.css` owns the shared tokens and primitives and is imported once by `AdminLayout.astro`. Use its surface, text, border, semantic state and radius tokens in new screens, including plugin screens. Component styles own their layouts. Shared primitive selectors deliberately take precedence over scoped Vue defaults.

Use `--action-fill` with white text for filled actions; `--color-primary-600` is for links and other accent text. Their separation preserves contrast in dark mode. Use semantic text/background pairs for success, warning and error states. Status wording must communicate the state without relying on color.

Keep keyboard focus visible, respect reduced motion, and allow content and actions to wrap on narrow screens. Mobile form text is at least 16px and primary controls have 44px touch targets. The font stack uses locally available Inter or system sans serif without fetching a third-party font.

Validation: `npm run test --prefix admin-ui` includes palette contrast checks. Against an isolated seeded instance, run `CLICK_E2E=1 CLICK_E2E_PASS=... CLICK_E2E_BASE_URL=... npx playwright test e2e/editorial.spec.js` to capture every supported core view in both themes and on mobile. The draft/preview smoke suite additionally verifies editing behavior.

The screenshot suite requires a seeded isolated site, an administrator with a completed first-login password change, and `home` content. Start the local harness with `php -S 127.0.0.1:8080 -t public e2e/router.php`; the router mirrors the production theme CSS alias. Credentials default to the CI demo account; override with `CLICK_E2E_USER` and `CLICK_E2E_PASS`. Screenshots default to ignored `test-results/` directories; `CLICK_E2E_SCREENSHOTS` selects an export directory. Optional plugin screens are checked when their navigation links are available. Set `CLICK_E2E_MOBILE_ONLY=1` for a focused responsive rerun. Run against disposable data: the editing suite saves a draft title and restores its text, leaving an unpublished revision.

## Public Click website

The documentation builder reads the light/dark token blocks directly from `admin-ui/src/styles/editorial.css` and includes them in its standalone stylesheet. This keeps surfaces, indigo links, focus colors and local/system typography aligned without importing admin layout rules. `scripts/docs/assets/site.css` owns the reading measure, navigation rail, tables and responsive layout. Dark mode follows the reader's system preference. The site needs no JavaScript or third-party font requests.

Build with `php scripts/docs/build-site.php /tmp/click-docs`. Check the homepage, English/German editor guides, long tables and code examples at desktop and narrow widths in both color schemes. The Pages verifier checks the published stylesheet along with all other public artifact files.
