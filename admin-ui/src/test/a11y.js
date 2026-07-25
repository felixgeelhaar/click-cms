/**
 * The automated accessibility check the whole admin suite shares.
 *
 * axe-core is run against the real rendered DOM of a mounted component, which
 * means a component has to be attached to the document — a detached fragment
 * has no computed styles and axe declines to judge most of it.
 *
 * Two families of rule are turned off, and both for the same reason: jsdom does
 * not lay pages out.
 *
 *   - `color-contrast` needs painted pixels to sample. In jsdom every element
 *     reports a transparent background and no inherited colour, so the rule
 *     either aborts or reports nonsense. Contrast is checked instead by
 *     `contrast.js`, which computes the ratios from the palette itself — the
 *     numbers are the same either way, and that check does not need a browser.
 *   - `region`, `page-has-heading-one`, `landmark-*` and the other
 *     document-scoped rules ask questions about a whole page. A component
 *     mounted on its own is not a page, so they would fire on every test for
 *     reasons that say nothing about the component. AdminApp, which *is* the
 *     page, opts them back in.
 */
import { axe } from 'vitest-axe';

/** Rules that need a laid-out browser, or a whole document, to mean anything. */
const DOCUMENT_RULES = [
  'region',
  'page-has-heading-one',
  'landmark-one-main',
  'landmark-unique',
  'landmark-no-duplicate-banner',
  'landmark-no-duplicate-contentinfo',
  'html-has-lang',
  'html-lang-valid',
  'bypass',
  'document-title',
];

const disabled = (ids) => Object.fromEntries(ids.map((id) => [id, { enabled: false }]));

/**
 * Mount options every a11y test needs: the component must live in the real
 * document so axe can read it.
 */
export const attached = () => {
  const host = document.createElement('div');
  document.body.appendChild(host);
  return host;
};

/**
 * Run axe over an element (or a Vue wrapper) and return its violations.
 *
 * `rules` lets a caller re-enable a document-scoped rule — AdminApp does, since
 * it renders the landmarks — or silence one that genuinely does not apply.
 */
export const violationsIn = async (target, { rules = {} } = {}) => {
  const el = target?.element ?? target;
  const results = await axe(el, {
    rules: { 'color-contrast': { enabled: false }, ...disabled(DOCUMENT_RULES), ...rules },
  });
  return results.violations;
};

/** A readable failure: axe's own object dumps as `[Object]` and helps nobody. */
export const describeViolations = (violations) =>
  violations
    .map((v) => `${v.id} (${v.impact}): ${v.help}\n    ${v.nodes.map((n) => n.html).join('\n    ')}`)
    .join('\n  ');

/**
 * The assertion every component test makes. Kept as a function rather than a
 * matcher so the failure message names the offending markup.
 */
export const expectNoViolations = async (target, options) => {
  const violations = await violationsIn(target, options);
  if (violations.length > 0) {
    throw new Error(`Accessibility violations:\n  ${describeViolations(violations)}`);
  }
};
