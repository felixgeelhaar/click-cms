import { describe, it, expect } from 'vitest';
import { read, tokensIn, ruleFor, declaration, resolve$, contrast, over, rgb, borderColour } from './css.js';

/**
 * Colour contrast, WCAG 2.2 AA.
 *
 *   4.5:1  body text
 *   3:1    large text, and the visual boundary of a user interface component
 *
 * axe cannot answer this here: it samples computed styles, and jsdom computes
 * no layout, so its `color-contrast` rule aborts rather than reporting. The
 * arithmetic does not need a browser — only the two colours and the knowledge
 * of which is painted over which — so it is done directly, against the values
 * read out of the shipped stylesheets.
 *
 * What this found, and what each expectation now holds shut:
 *
 *   2.15:1  --color-warning-500 as lettering ("Live but out of date", the
 *           stale-translation and stale-version notes)
 *   2.28:1  --color-success-500 as lettering ("ACTIVATED" on the plugin cards,
 *           "Active" on the theme cards, the update result)
 *   3.76:1  --color-danger-500 as lettering, and as the fill under the white
 *           lettering of every Delete button
 *   1.27:1  --app-border as the only boundary of every admin form control
 *   1.23:1  --rule as the only boundary of the public contact form's fields,
 *           in both themes
 *
 * All five were fill colours doing a text colour's job, or a divider doing a
 * control boundary's job. The -text and -border tokens are the same hues taken
 * to a legible weight; nothing else about the palette moved.
 */

const BODY = 4.5;
const UI = 3;

const adminCss = read('admin-ui/src/layouts/AdminLayout.astro');
const light = tokensIn(adminCss, ':root');
const dark = { ...light, ...tokensIn(adminCss, '[data-theme="dark"]') };

const MODES = [['light', light], ['dark', dark]];

/** A component's scoped stylesheet, resolved against a mode's tokens. */
const componentColour = (file, selector, property, tokens) => {
  const css = read(`admin-ui/src/components/${file}`);
  const value = declaration(ruleFor(css, selector), property);
  expect(value, `${file} ${selector} { ${property} }`).not.toBeNull();
  return resolve$(value, tokens);
};

describe('admin palette', () => {
  for (const [mode, tokens] of MODES) {
    describe(`${mode} mode`, () => {
      const surface = tokens['--app-surface'];
      const strong = tokens['--app-surface-strong'];
      const card = tokens['--card-bg'];

      it('body text is legible on every surface', () => {
        for (const background of [surface, strong, card]) {
          expect(contrast(tokens['--app-text'], background)).toBeGreaterThanOrEqual(BODY);
        }
      });

      it('muted text is legible on every surface', () => {
        for (const background of [surface, strong, card]) {
          expect(contrast(tokens['--app-text-muted'], background)).toBeGreaterThanOrEqual(BODY);
        }
      });

      it('the primary button carries its own label', () => {
        expect(contrast('#ffffff', tokens['--color-primary-600'])).toBeGreaterThanOrEqual(BODY);
      });

      it('the destructive button carries its own label', () => {
        // --color-danger-500 under white is 3.76:1; --color-danger-fill is the
        // same red taken just far enough.
        expect(contrast('#ffffff', tokens['--color-danger-fill'])).toBeGreaterThanOrEqual(BODY);
      });

      it('semantic words are legible, not merely coloured', () => {
        for (const token of ['--color-success-text', '--color-warning-text', '--color-danger-600']) {
          expect(contrast(tokens[token], card), `${token} on the card`).toBeGreaterThanOrEqual(BODY);
        }
      });

      it('a form control has a boundary you can see', () => {
        for (const background of [surface, strong, card]) {
          expect(contrast(tokens['--control-border'], background)).toBeGreaterThanOrEqual(UI);
        }
      });

      it('the focus ring stands out from what it surrounds', () => {
        const ring = resolve$(tokens['--focus-ring'], tokens);
        for (const background of [surface, strong, card]) {
          expect(contrast(ring, background)).toBeGreaterThanOrEqual(UI);
        }
      });

      it('the publication badges read as words on their own tints', () => {
        // Each badge states its state in text; the colour is reinforcement. The
        // words still have to be readable on the tint behind them.
        const css = read('admin-ui/src/components/Pages.vue');
        const scope = mode === 'dark' ? css.slice(css.indexOf('[data-theme="dark"] .status-badge')) : css;
        for (const state of ['live', 'pending', 'never', 'takendown']) {
          const body = ruleFor(scope, `.status-badge.${state}`);
          const tint = over(resolve$(declaration(body, 'background'), tokens), card);
          const ink = resolve$(declaration(body, 'color'), tokens);
          const hex = `#${tint.map((c) => c.toString(16).padStart(2, '0')).join('')}`;
          expect(contrast(ink, hex), `${state} badge in ${mode}`).toBeGreaterThanOrEqual(BODY);
        }
      });

      it('the plugin state reads as a word, not as a hue', () => {
        const colour = componentColour('Plugins.vue', '.status.activated', 'color', tokens);
        expect(contrast(colour, card)).toBeGreaterThanOrEqual(BODY);
      });

      it('the active theme marker reads as a word', () => {
        const colour = componentColour('Themes.vue', '.status.active', 'color', tokens);
        expect(contrast(colour, card)).toBeGreaterThanOrEqual(BODY);
      });

      it('the stale-translation warning reads as a sentence', () => {
        const colour = componentColour('PageLanguages.vue', '.langs-note.warn', 'color', tokens);
        expect(contrast(colour, tokens['--app-surface'])).toBeGreaterThanOrEqual(BODY);
      });

      it('the sign-in error reads as a sentence', () => {
        const colour = componentColour('Login.vue', '.error', 'color', tokens);
        expect(contrast(colour, tokens['--card-bg'])).toBeGreaterThanOrEqual(BODY);
      });
    });
  }
});

describe('public themes', () => {
  const themes = [
    ['default', read('themes/default/theme.css'), '#ffffff', '#ffffff'],
    ['dark', read('themes/dark/theme.css'), null, null],
  ];

  for (const [name, css, pageOverride, fieldOverride] of themes) {
    describe(`the ${name} theme`, () => {
      const tokens = tokensIn(css, ':root');
      const page = pageOverride ?? tokens['--ground'];
      // The contact form's inputs paint their own background.
      const fieldBackground = fieldOverride
        ?? resolve$(declaration(ruleFor(css, '.cms-form input'), 'background'), tokens);

      it('body text is legible', () => {
        expect(contrast(tokens['--ink'], page)).toBeGreaterThanOrEqual(BODY);
      });

      it('quiet text — every section paragraph — is legible', () => {
        expect(contrast(tokens['--muted'], page)).toBeGreaterThanOrEqual(BODY);
      });

      it('links are legible', () => {
        expect(contrast(tokens['--accent'], page)).toBeGreaterThanOrEqual(BODY);
      });

      it('the call-to-action button carries its own label', () => {
        const body = ruleFor(css, '.cms-section--call-to-action .cms-field--buttonUrl a');
        const fill = resolve$(declaration(body, 'background'), tokens);
        const ink = resolve$(declaration(body, 'color'), tokens);
        expect(contrast(ink, fill)).toBeGreaterThanOrEqual(BODY);
      });

      it('the submit button carries its own label', () => {
        const body = ruleFor(css, '.cms-form button[type="submit"]');
        const fill = resolve$(declaration(body, 'background'), tokens);
        const ink = resolve$(declaration(body, 'color'), tokens);
        expect(contrast(ink, fill)).toBeGreaterThanOrEqual(BODY);
      });

      it('a form field has a boundary you can see', () => {
        // --rule is a hairline — right for a divider, and 1.2:1 against the page.
        // The field's own background is the page's, so the border is the only
        // thing saying a control is there, and it has to clear 3:1.
        const border = borderColour(ruleFor(css, '.cms-form input'), tokens);
        expect(contrast(border, fieldBackground)).toBeGreaterThanOrEqual(UI);
        expect(rgb(border)).not.toEqual(rgb(tokens['--rule']));
      });

      it('the focus ring stands out from the page', () => {
        expect(contrast(tokens['--accent'], page)).toBeGreaterThanOrEqual(UI);
        expect(contrast(tokens['--accent'], fieldBackground)).toBeGreaterThanOrEqual(UI);
      });
    });
  }
});
