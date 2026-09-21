import { describe, it, expect } from 'vitest';
import { contentEditorHref } from './contentHref.js';

describe('contentEditorHref', () => {
  it('builds page editor URLs', () => {
    expect(contentEditorHref({ page: 'home', locale: 'en' }))
      .toBe('/admin/pages/edit/home?locale=en');
  });

  it('builds collection entry editor URLs', () => {
    expect(contentEditorHref({ type: 'post', page: 'hello-world', locale: 'de' }))
      .toBe('/admin/collections/post/entries/hello-world?locale=de');
  });
});
