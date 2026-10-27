import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { parse as parseSfc } from '@vue/compiler-sfc';
import { parse as parseTemplate } from '@vue/compiler-dom';
import german from './locales/de.json';

// These are language names, technical examples, CSS units, file paths and terms
// which have the same spelling in German. New interface prose must be localized.
const unchanged = new Set(['#ffffff', '(optional)', '/api/pages', '/media/clip.mp4', '24px', '32px', 'Click CMS', 'Deutsch', 'English', 'Link', 'Plugins', 'Status', 'Text', 'URL', 'Version', 'Webhooks', 'bin/click-schedule.php', 'bootstrap.php', 'click', 'config/core.json', 'core.marketplace.registryUrl', 'core.updates.feedUrl', 'currentColor', 'https://example.com/hooks/click', 'https://example.com/page', 'https://example.com/this-page', 'https://www.youtube.com/watch?v=…', 'plugin.json', 'plugins/<id>/themes/', 'px)', 'theme.json', 'themes/']);
const files = [];
function collect(directory) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) collect(path);
    else if (path.endsWith('.vue')) files.push(path);
  }
}
collect(join(process.cwd(), 'src/components'));

describe('interface catalog coverage', () => {
  it.each(files)('localizes static display text in %s', file => {
    const { descriptor } = parseSfc(readFileSync(file, 'utf8'));
    const missing = [];
    function check(text) {
      const value = text.trim().replace(/\s+/g, ' ');
      if (/[A-Za-z]{2}/.test(value) && !unchanged.has(value)) missing.push(value);
    }
    function visit(node) {
      if (node.type === 2) check(node.content);
      if (node.type === 1) {
        for (const prop of node.props) {
          if (prop.type === 6 && ['placeholder', 'aria-label', 'alt', 'title'].includes(prop.name) && prop.value) check(prop.value.content);
        }
      }
      node.children?.forEach(visit);
    }
    visit(parseTemplate(descriptor.template.content));
    expect(missing).toEqual([]);
  });

  it('keeps every parameter required by a German message available in English', () => {
    for (const [source, translation] of Object.entries(german)) {
      for (const slot of translation.match(/\{\d+\}/g) ?? []) expect(source, translation).toContain(slot);
      expect(translation.trim(), source).not.toBe('');
    }
  });
});
