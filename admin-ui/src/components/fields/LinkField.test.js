import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import LinkField from './LinkField.vue';

/**
 * The page picker.
 *
 * Editors used to type a slug from memory into a free-text box, and a typo there
 * is a broken link on the public site that nothing catches. This control lists
 * the pages by title instead — so the tests that matter are about the *value* it
 * emits, because the two screens that use it store the answer in two different
 * shapes and one of them throws on save if the shape is wrong.
 *
 * `classifyLikeDomain` below is a port of `MenuItem::classify()` in
 * src/Domain/Menu/MenuItem.php. It is here so that a value the PHP domain would
 * refuse fails in this suite rather than at the moment an editor presses Save.
 */

const LIVE = { published: true, hasUnpublishedChanges: false, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const DRAFT = { published: false, hasUnpublishedChanges: false, neverPublished: true, publishedAt: null };

const PAGES = [
  { key: 'page:en:home', slug: 'home', locale: 'en', data: { title: 'Home' }, publication: LIVE },
  { key: 'page:en:about', slug: 'about', locale: 'en', data: { title: 'About the workshop' }, publication: LIVE },
  { key: 'page:en:contact', slug: 'contact', locale: 'en', data: { title: 'Contact us' }, publication: LIVE },
  { key: 'page:en:prices', slug: 'prices', locale: 'en', data: { title: 'Prices' }, publication: DRAFT },
];

/** The same rules MenuItem::classify() enforces, as a predicate. */
const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]*$/;
const LOCALE_PATTERN = /^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/;

const classifyLikeDomain = (target) => {
  if (typeof target !== 'string' || target.trim() === '') return false;
  const value = target.trim();

  // A scheme followed by "://" is judged as an absolute URL, and only http(s)
  // with a host survives. Everything else falls through to the slug rules,
  // which it cannot satisfy.
  if (/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//.test(value)) {
    try {
      const url = new URL(value);
      return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '';
    } catch {
      return false;
    }
  }

  const parts = value.split('/');
  if (parts.length === 1) return SLUG_PATTERN.test(parts[0]);
  if (parts.length === 2) return LOCALE_PATTERN.test(parts[0]) && SLUG_PATTERN.test(parts[1]);
  return false;
};

const mountField = async (props = {}) => {
  const wrapper = mount(LinkField, {
    props: {
      pages: PAGES,
      defaultLocale: 'en',
      ...props,
      // Feed what is emitted back in as the value, which is what a `v-model`
      // binding does — and what both real call sites use.
      'onUpdate:modelValue': (next) => wrapper.setProps({ modelValue: next }),
    },
  });
  await flushPromises();
  return wrapper;
};

const select = (wrapper) => wrapper.find('select.link-select');
const emitted = (wrapper) => wrapper.emitted('update:modelValue');
const lastEmitted = (wrapper) => emitted(wrapper)?.at(-1)?.[0];

describe('the port of MenuItem::classify', () => {
  it('agrees with the domain on the shapes that matter', () => {
    expect(classifyLikeDomain('about')).toBe(true);
    expect(classifyLikeDomain('de/about')).toBe(true);
    expect(classifyLikeDomain('https://example.com/docs')).toBe(true);
    // The trap this whole exercise is about: a path is not a menu target.
    expect(classifyLikeDomain('/about')).toBe(false);
    expect(classifyLikeDomain('javascript:alert(1)')).toBe(false);
    expect(classifyLikeDomain('//evil.example')).toBe(false);
    expect(classifyLikeDomain('ftp://example.com/x')).toBe(false);
    expect(classifyLikeDomain('a/b/c')).toBe(false);
    expect(classifyLikeDomain('')).toBe(false);
  });
});

describe('listing pages', () => {
  it('names each page by its title with the address as secondary text', async () => {
    const wrapper = await mountField();
    const labels = wrapper.findAll('option').map((o) => o.text());

    expect(labels).toContain('About the workshop — /about');
    // Not the bare slug the datalist used to offer.
    expect(labels).not.toContain('about');
  });

  it('keeps unpublished pages in the list, marked, and after the published ones', async () => {
    const wrapper = await mountField();
    const labels = wrapper.findAll('option').map((o) => o.text());

    expect(labels).toContain('Prices — /prices (not published)');
    expect(labels.indexOf('Prices — /prices (not published)')).toBeGreaterThan(
      labels.indexOf('Contact us — /contact')
    );
  });

  it('says so at the moment an unpublished page is chosen', async () => {
    const wrapper = await mountField({ modelValue: '/prices' });

    const notice = wrapper.find('.link-caution');
    expect(notice.exists()).toBe(true);
    expect(notice.text()).toContain('not published');
    expect(notice.text()).toContain('page not found');
    // And the select points at it, so the warning is read out with the control.
    expect(select(wrapper).attributes('aria-describedby')).toContain(notice.attributes('id'));
  });

  it('does not accuse a page of being unpublished when the API said nothing', async () => {
    // Publication state only reaches a signed-in caller, and old rows carry none.
    const wrapper = await mountField({ pages: [{ slug: 'about' }], modelValue: '/about' });

    expect(wrapper.findAll('option').map((o) => o.text())).toContain('about — /about');
    expect(wrapper.find('.link-caution').exists()).toBe(false);
  });
});

describe('the value emitted for an internal page', () => {
  it('is a bare slug in slug format — what a menu target must be', async () => {
    const wrapper = await mountField({ format: 'slug' });
    await select(wrapper).setValue('about');

    expect(lastEmitted(wrapper)).toBe('about');
    expect(classifyLikeDomain(lastEmitted(wrapper))).toBe(true);
  });

  it('is a root-relative path in path format — what a url field stores', async () => {
    const wrapper = await mountField({ format: 'path' });
    await select(wrapper).setValue('/about');

    expect(lastEmitted(wrapper)).toBe('/about');
  });

  it('prefixes the locale outside the default one, in both formats', async () => {
    const german = [{ key: 'page:de:kontakt', slug: 'kontakt', locale: 'de', data: { title: 'Kontakt' }, publication: LIVE }];

    const slugs = await mountField({ pages: german, format: 'slug' });
    await select(slugs).setValue('de/kontakt');
    expect(lastEmitted(slugs)).toBe('de/kontakt');
    expect(classifyLikeDomain('de/kontakt')).toBe(true);

    const paths = await mountField({ pages: german, format: 'path' });
    await select(paths).setValue('/de/kontakt');
    // Mirrors MenusController::hrefFor(), which is what the public router serves.
    expect(lastEmitted(paths)).toBe('/de/kontakt');
  });

  it('never emits a value the menu domain would refuse, for any page', async () => {
    const wrapper = await mountField({ format: 'slug' });

    // Every page option, not just the one a test happens to click.
    const targets = wrapper
      .findAll('option')
      .map((o) => o.element.value)
      .filter((v) => v !== '' && !v.startsWith('!'));

    expect(targets.length).toBe(PAGES.length);
    for (const target of targets) {
      expect(classifyLikeDomain(target), `"${target}" would throw on save`).toBe(true);
    }
  });

  it('shows a stored value that is already a valid equivalent as the chosen page', async () => {
    // `en/about` names the default locale redundantly, but the domain accepts it.
    const wrapper = await mountField({ format: 'slug', modelValue: 'en/about' });

    expect(wrapper.find('.link-warning').exists()).toBe(false);
    // Not rewritten to `about` behind the editor's back.
    expect(emitted(wrapper)).toBeUndefined();
  });
});

describe('an external link', () => {
  it('round-trips a stored URL through the revealed address box', async () => {
    const wrapper = await mountField({ modelValue: 'https://example.com/docs' });

    const url = wrapper.find('input.link-url');
    expect(url.exists()).toBe(true);
    expect(url.element.value).toBe('https://example.com/docs');
    expect(select(wrapper).element.value).toBe('!external');

    await url.setValue('https://example.com/handbook');
    expect(lastEmitted(wrapper)).toBe('https://example.com/handbook');
    expect(classifyLikeDomain('https://example.com/handbook')).toBe(true);
  });

  it('reveals an empty, labelled address box when chosen over a page', async () => {
    const wrapper = await mountField({ format: 'slug', modelValue: 'about' });
    expect(wrapper.find('input.link-url').exists()).toBe(false);

    await select(wrapper).setValue('!external');
    await flushPromises();

    const url = wrapper.find('input.link-url');
    expect(url.exists()).toBe(true);
    expect(url.element.value).toBe('');
    // The page value is not silently kept behind a dropdown that says otherwise.
    expect(lastEmitted(wrapper)).toBe('');

    // A real label, associated, so the revealed control is announced.
    const label = wrapper.find('label.link-sub-label');
    expect(label.attributes('for')).toBe(url.attributes('id'));
    expect(label.text()).toBe('Web address');
  });

  it('stays in external mode while a half-typed address is not yet a URL', async () => {
    const wrapper = await mountField({ format: 'slug' });
    await select(wrapper).setValue('!external');
    await wrapper.find('input.link-url').setValue('https:/');

    expect(wrapper.find('input.link-url').exists()).toBe(true);
    // Not mistaken for a page that no longer exists mid-keystroke.
    expect(wrapper.find('.link-warning').exists()).toBe(false);
  });
});

describe('a value that matches nothing', () => {
  it('is preserved, shown and warned about rather than discarded', async () => {
    const wrapper = await mountField({ format: 'slug', modelValue: 'old-news' });

    // Still the control's value, so nothing is lost by opening the screen.
    expect(select(wrapper).element.value).toBe('!unrecognised');
    expect(wrapper.findAll('option').map((o) => o.text())).toContain('old-news — not a known page');

    const warning = wrapper.find('.link-warning');
    expect(warning.exists()).toBe(true);
    expect(warning.text()).toContain('no longer exists');
    expect(warning.text()).toContain('old-news');

    // Never silently "fixed".
    expect(emitted(wrapper)).toBeUndefined();
    // And announced with the control that holds it.
    expect(select(wrapper).attributes('aria-describedby')).toContain(warning.attributes('id'));
    expect(select(wrapper).attributes('aria-invalid')).toBe('true');
  });

  it('treats a menu target written as a path as unrecognised, because the domain does', async () => {
    // `/about` throws in MenuItem::classify(). Quietly showing it as "the About
    // page" would hide a link that fails the next time the menu is saved.
    const wrapper = await mountField({ format: 'slug', modelValue: '/about' });

    expect(classifyLikeDomain('/about')).toBe(false);
    expect(wrapper.find('.link-warning').exists()).toBe(true);
    expect(emitted(wrapper)).toBeUndefined();
  });

  it('keeps the value when its own option is re-picked', async () => {
    const wrapper = await mountField({ format: 'slug', modelValue: 'old-news' });
    await select(wrapper).setValue('!unrecognised');

    expect(emitted(wrapper)).toBeUndefined();
  });

  it('can be replaced by a real page', async () => {
    const wrapper = await mountField({ format: 'slug', modelValue: 'old-news' });
    await select(wrapper).setValue('contact');

    expect(lastEmitted(wrapper)).toBe('contact');
  });

  it('does not accuse anything while the page list is still loading', async () => {
    global.fetch = vi.fn(() => new Promise(() => {}));
    const wrapper = mount(LinkField, { props: { modelValue: 'about', format: 'slug' } });

    expect(wrapper.find('.link-warning').exists()).toBe(false);
    expect(wrapper.find('option').text()).toBe('Loading pages…');
  });

  it('does not accuse anything when no page list could be loaded at all', async () => {
    // A failed request is not evidence that a page was deleted.
    const wrapper = await mountField({ pages: [], modelValue: 'about', format: 'slug' });

    expect(wrapper.find('.link-warning').exists()).toBe(false);
    expect(select(wrapper).element.value).toBe('!unrecognised');
  });
});

describe('choosing nothing', () => {
  it('clears an optional field', async () => {
    const wrapper = await mountField({ format: 'path', modelValue: '/about' });

    const none = wrapper.findAll('option').find((o) => o.element.value === '');
    expect(none.text()).toBe('— none —');
    expect(none.attributes('disabled')).toBeUndefined();

    await select(wrapper).setValue('');
    expect(lastEmitted(wrapper)).toBe('');
  });

  it('is not offered on a required field', async () => {
    const wrapper = await mountField({ format: 'path', required: true });

    const none = wrapper.findAll('option').find((o) => o.element.value === '');
    expect(none.text()).toBe('— Choose a page —');
    expect(none.attributes('disabled')).toBeDefined();
  });
});

describe('loading the page list itself', () => {
  beforeEach(() => {
    global.fetch = vi.fn(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ data: PAGES, locale: 'en', locales: ['en', 'de'] }),
    }));
  });

  it('fetches once when no list is handed down', async () => {
    const wrapper = mount(LinkField, { props: { format: 'path' } });
    await flushPromises();

    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(String(global.fetch.mock.calls[0][0])).toBe('/api/pages');
    expect(wrapper.findAll('option').map((o) => o.text())).toContain('About the workshop — /about');
  });

  it('does not fetch when a list is handed down', async () => {
    await mountField();
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

describe('naming the control', () => {
  it('lets the screen own the id so an outer <label for> reaches the select', async () => {
    const wrapper = await mountField({ inputId: 'item-0-target', describedBy: 'item-0-hint' });

    expect(select(wrapper).attributes('id')).toBe('item-0-target');
    // The screen's own hint survives alongside anything this control adds.
    expect(select(wrapper).attributes('aria-describedby')).toContain('item-0-hint');
  });

  it('generates an id when the screen does not supply one', async () => {
    const wrapper = await mountField();
    expect(select(wrapper).attributes('id')).toBeTruthy();
  });
});
