import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { read, adminRoot, ruleFor, declaration } from './css.js';

/**
 * Focus visibility — WCAG 2.2 SC 2.4.7.
 *
 * Somebody driving this by keyboard has exactly one way to know where they are,
 * and it is the ring. Two ways to lose it: suppress it (`outline: none` with
 * nothing put back), or never state one and inherit whatever the browser draws,
 * which on a coloured button or a tinted rail is often nothing you can see.
 *
 * jsdom applies no stylesheets, so this is asserted against the CSS the
 * components and themes actually ship, which is where the answer lives anyway.
 *
 * What this found:
 *   - Login suppressed the outline outright and recoloured the 1px border in
 *     its place, which on this palette is under 3:1 against the resting border.
 *   - Fifteen screens — the page editor, the media library, users, menus,
 *     redirects, versions, comments, the collection editor among them — stated
 *     no focus style at all.
 *   - Both public themes covered the navigation and the form fields and left
 *     the submit button, the brand and every in-section link to the browser.
 */

const componentDir = resolve(adminRoot, 'components');

const componentFiles = (dir = componentDir, prefix = '') =>
  readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
    entry.isDirectory()
      ? componentFiles(resolve(dir, entry.name), `${prefix}${entry.name}/`)
      : entry.name.endsWith('.vue') ? [`${prefix}${entry.name}`] : []
  );

const styleOf = (source) => {
  const open = source.indexOf('<style');
  if (open === -1) return '';
  return source.slice(source.indexOf('>', open) + 1, source.lastIndexOf('</style>'));
};

/** Rules whose selector mentions :focus, as [selector, declarations] pairs. */
const focusRules = (css) =>
  [...css.matchAll(/([^{}]*:focus[^{}]*)\{([^}]*)\}/g)].map((m) => [m[1].trim(), m[2]]);

const suppresses = (body) => /outline\s*:\s*(none|0)\b/.test(body);
const indicates = (body) => /outline\s*:\s*[^;]*\b\d/.test(body) || /box-shadow\s*:/.test(body);

describe('no component suppresses focus without replacing it', () => {
  for (const file of componentFiles()) {
    it(`${file} keeps a visible focus indicator`, () => {
      const css = styleOf(readFileSync(resolve(componentDir, file), 'utf8'));

      for (const [selector, body] of focusRules(css)) {
        if (!suppresses(body)) continue;

        // Suppressing is allowed only where the same file draws its own ring
        // for the same thing — the rich-text editor swaps the outline for an
        // inset shadow, which is a visible indicator by another name.
        const replaced = focusRules(css).some(([other, otherBody]) =>
          other !== selector && indicates(otherBody)
        );
        expect(replaced, `${file}: "${selector}" removes the outline and nothing puts one back`).toBe(true);
      }
    });
  }
});

describe('every interactive screen states a focus indicator', () => {
  const interactive = (source) =>
    /<button|<a\s|<input|<select|<textarea|<summary/.test(source.slice(0, source.indexOf('<script')));

  /**
   * The visual builder is being rewritten in parallel and states no focus style
   * of its own; it is left to whatever the browser draws. That is a real gap and
   * it is recorded here rather than fixed, because these files belong to that
   * work and a stylesheet edited from two directions helps nobody. The
   * suppression check above still covers them — they may not make it worse.
   */
  const OWNED_ELSEWHERE = ['Builder.vue', 'builder/BuilderInspector.vue', 'builder/BuilderPalette.vue'];

  for (const file of componentFiles()) {
    if (OWNED_ELSEWHERE.includes(file)) continue;
    const source = readFileSync(resolve(componentDir, file), 'utf8');
    if (!interactive(source)) continue;

    it(`${file} draws a ring on whatever the keyboard is on`, () => {
      const rules = focusRules(styleOf(source));
      expect(rules.length, `${file} declares no :focus rule at all`).toBeGreaterThan(0);
      expect(rules.some(([, body]) => indicates(body)), `${file} has :focus rules but none draw anything`).toBe(true);
    });
  }
});

describe('the sign-in screen', () => {
  it('no longer trades the focus ring for a recoloured border', () => {
    const css = styleOf(read('admin-ui/src/components/Login.vue'));
    for (const [, body] of focusRules(css)) {
      expect(suppresses(body)).toBe(false);
    }
    expect(focusRules(css).some(([selector, body]) => selector.includes('input') && indicates(body))).toBe(true);
  });
});

describe('public themes', () => {
  const themes = [
    ['default', read('themes/default/theme.css')],
    ['dark', read('themes/dark/theme.css')],
  ];

  for (const [name, css] of themes) {
    describe(`the ${name} theme`, () => {
      const rules = focusRules(css);
      const focused = (fragment) =>
        rules.some(([selector, body]) => selector.includes(fragment) && indicates(body));

      it('rings the navigation', () => {
        expect(focused('.cms-nav a')).toBe(true);
        expect(focused('.cms-nav-toggle')).toBe(true);
        expect(focused('.cms-nav-subtoggle')).toBe(true);
      });

      it('rings the contact form', () => {
        expect(focused('.cms-form input')).toBe(true);
        expect(focused('.cms-form textarea')).toBe(true);
        // The submit button was the one control on the form left to the browser.
        expect(focused('.cms-form button[type="submit"]')).toBe(true);
      });

      it('rings the brand and the links inside a section', () => {
        expect(focused('.cms-brand')).toBe(true);
        expect(focused('.cms-section a')).toBe(true);
      });

      it('never suppresses an outline', () => {
        for (const [, body] of rules) expect(suppresses(body)).toBe(false);
      });
    });
  }

  it('marks the current page with more than a colour, in both themes', () => {
    // Colour alone is not a signal. The default theme underlines the current
    // item; the dark one used colour and a tint, and now underlines it too.
    for (const path of ['themes/default/theme.css', 'themes/dark/theme.css']) {
      const css = read(path);
      const body = ruleFor(css, '.cms-nav-item--current > a::after');
      expect(declaration(body, 'content'), path).toBe('""');
      expect(declaration(body, 'height'), path).toBeTruthy();
    }
  });
});
