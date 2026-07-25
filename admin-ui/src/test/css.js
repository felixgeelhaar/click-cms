import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Enough CSS reading to check contrast and focus without a browser.
 *
 * The values under test are the ones actually shipped, read out of the files
 * themselves — a table of colours copied into a test proves only that the copy
 * is consistent with itself. jsdom cannot lay a page out, so axe's own
 * `color-contrast` rule aborts there; the arithmetic, though, does not need a
 * layout engine, only the two colours and which is on top of which.
 */

const here = dirname(fileURLToPath(import.meta.url));
export const repoRoot = resolve(here, '../../..');
export const adminRoot = resolve(here, '..');

export const read = (relativeToRepo) => readFileSync(resolve(repoRoot, relativeToRepo), 'utf8');

/**
 * Custom properties declared in the block a selector opens.
 *
 * The closing brace is found by counting, not by looking for a `}` at the start
 * of a line: the admin tokens live inside an indented `<style>` in an .astro
 * file, and scanning for "\n}" ran straight past the end of `:root` and read
 * the dark overrides as though they were the light ones — which made every
 * light-mode contrast assertion quietly measure dark-mode colours.
 */
export const tokensIn = (css, selector) => {
  const start = css.indexOf(selector);
  if (start === -1) throw new Error(`No "${selector}" block found`);

  const open = css.indexOf('{', start);
  let depth = 0;
  let close = open;
  for (; close < css.length; close += 1) {
    if (css[close] === '{') depth += 1;
    else if (css[close] === '}') {
      depth -= 1;
      if (depth === 0) break;
    }
  }
  const block = css.slice(open, close);

  const tokens = {};
  for (const [, name, value] of block.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
    tokens[name] = value.trim();
  }
  return tokens;
};

/**
 * The declarations of the first rule whose selector list ends a selector with
 * `selector`. The lazy prefix is what lets `.status-badge.live` also find the
 * `[data-theme="dark"] .status-badge.live` override.
 */
export const ruleFor = (css, selector) => {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = new RegExp(`(^|[,{}\\n])[^,{}\\n]*?${escaped}\\s*(,[^{]*)?\\{([^}]*)\\}`, 'm');
  const match = css.match(pattern);
  if (!match) throw new Error(`No rule for "${selector}"`);
  return match[3];
};

/** One declaration's value out of a rule body. */
export const declaration = (body, property) => {
  const match = body.match(new RegExp(`(?:^|;)\\s*${property}\\s*:\\s*([^;]+)`));
  return match ? match[1].trim() : null;
};

/** Resolve `var(--x, fallback)` chains against a token map. */
export const resolve$ = (value, tokens, depth = 0) => {
  if (value == null || depth > 8) return value;
  const trimmed = String(value).trim();
  const match = trimmed.match(/^var\(\s*(--[\w-]+)\s*(?:,\s*([^)]+))?\)$/);
  if (!match) return trimmed;
  const [, name, fallback] = match;
  const next = tokens[name] ?? fallback;
  if (next == null) throw new Error(`Unresolved token ${name}`);
  return resolve$(next, tokens, depth + 1);
};

const HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;

/** A colour as [r, g, b], 0-255. Hex and rgb()/rgba() only — enough for these. */
export const rgb = (colour) => {
  const value = String(colour).trim();
  if (value === 'white' || value === '#fff' || value === '#ffffff') return [255, 255, 255];
  if (value === 'black') return [0, 0, 0];

  if (HEX.test(value)) {
    const hex = value.slice(1);
    const full = hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex;
    return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16));
  }

  const parts = value.match(/rgba?\(([^)]+)\)/);
  if (parts) {
    const numbers = parts[1].split(/[,/\s]+/).filter(Boolean).map(Number);
    return numbers.slice(0, 3);
  }

  throw new Error(`Cannot read colour "${colour}"`);
};

/** A translucent colour painted over an opaque one. */
export const over = (colour, background) => {
  const parts = String(colour).match(/rgba\(([^)]+)\)/);
  if (!parts) return rgb(colour);
  const numbers = parts[1].split(/[,/\s]+/).filter(Boolean).map(Number);
  const alpha = numbers[3] ?? 1;
  const base = rgb(background);
  return numbers.slice(0, 3).map((channel, i) => Math.round(channel * alpha + base[i] * (1 - alpha)));
};

const channel = (value) => {
  const c = value / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};

/** WCAG relative luminance. */
export const luminance = (colour) => {
  const [r, g, b] = rgb(colour);
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
};

/** WCAG contrast ratio, rounded to two places so failures read as numbers. */
export const contrast = (foreground, background) => {
  const a = luminance(foreground);
  const b = luminance(background);
  const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  return Math.round(ratio * 100) / 100;
};

/** The colour out of a `border: 1px solid <colour>` shorthand. */
export const borderColour = (body, tokens) =>
  resolve$(declaration(body, 'border').split(/\s+/).pop(), tokens);
