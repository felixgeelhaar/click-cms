import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoViolations, attached } from './a11y.js';

import AdminApp from '../components/AdminApp.vue';
import Login from '../components/Login.vue';
import Sidebar from '../components/Sidebar.vue';
import Pages from '../components/Pages.vue';
import PageEdit from '../components/PageEdit.vue';
import Media from '../components/Media.vue';
import SectionEditor from '../components/SectionEditor.vue';
import Collections from '../components/Collections.vue';
import Plugins from '../components/Plugins.vue';
import Profile from '../components/Profile.vue';
import Menus from '../components/Menus.vue';
import Users from '../components/Users.vue';
import Settings from '../components/Settings.vue';
import Redirects from '../components/Redirects.vue';
import Marketplace from '../components/Marketplace.vue';
import Updates from '../components/Updates.vue';
import Themes from '../components/Themes.vue';
import FormSubmissions from '../components/FormSubmissions.vue';
import ChangePassword from '../components/ChangePassword.vue';
import PageVersions from '../components/PageVersions.vue';
import PagePublication from '../components/PagePublication.vue';
import PageLanguages from '../components/PageLanguages.vue';
import CommentsPanel from '../components/collaboration/CommentsPanel.vue';
import CollectionEntries from '../components/collections/CollectionEntries.vue';
import CollectionEntryEdit from '../components/collections/CollectionEntryEdit.vue';
import FieldInput from '../components/fields/FieldInput.vue';
import ImageField from '../components/fields/ImageField.vue';
import ReferenceField from '../components/fields/ReferenceField.vue';
import RepeaterField from '../components/fields/RepeaterField.vue';
import RichTextField from '../components/fields/RichTextField.vue';

/**
 * The automated accessibility pass.
 *
 * axe-core is run over each screen as it actually renders, with content in it —
 * an empty screen has no cards, no chips and no fields, and every defect this
 * suite was written for lived in one of those. The fixtures below are therefore
 * populated on purpose, and the mock answers by URL so a screen that fetches
 * three things gets three plausible answers.
 *
 * What axe found the first time it ran, and what each assertion holds shut:
 *
 *   critical  select-name       the reference picker's "add" dropdown had no
 *                               name at all in multiple mode — the wrapper's
 *                               label points at an id nothing carries there.
 *   critical  label             both fields on the Profile screen had a caption
 *                               and no `for`, so neither input had a name.
 *   serious   aria-input-field- the rich-text editor is role="textbox", and a
 *             name              textbox needs a name; `for` cannot reach a
 *                               contenteditable div, so nothing named it.
 *   moderate  heading-order     Pages, Plugins and the collection entry list
 *                               each put an h3 directly under their h1.
 *   minor     image-redundant-  the media chooser's thumbnails repeated, as alt
 *             alt               text, the filename printed right beneath them.
 *
 * Contrast is checked in contrast.test.js and focus indicators in
 * focus.test.js: jsdom does not lay pages out, so axe cannot judge either here.
 */

const LIVE = { published: true, hasUnpublishedChanges: false, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const PENDING = { published: true, hasUnpublishedChanges: true, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const NEVER = { published: false, hasUnpublishedChanges: false, neverPublished: true, publishedAt: null };
const DOWN = { published: false, hasUnpublishedChanges: false, neverPublished: false, publishedAt: null };

const PAGES = [
  { key: 'page:en:home', slug: 'home', locale: 'en', data: { title: 'Home' }, publication: LIVE },
  { key: 'page:en:about', slug: 'about', locale: 'en', data: { title: 'About' }, publication: PENDING },
  { key: 'page:en:draft', slug: 'draft', locale: 'en', data: { title: 'Draft' }, publication: NEVER },
  { key: 'page:en:gone', slug: 'gone', locale: 'en', data: { title: 'Gone' }, publication: DOWN },
];

const MEDIA = [{
  id: 'harbour-a1', extension: 'jpg', originalName: 'Harbour.jpg', mimeType: 'image/jpeg',
  width: 2400, height: 1600, bytes: 204800, variants: ['sm', 'md'], alt: '',
  focalPoint: { x: 0.5, y: 0.5 }, objectPosition: '50% 50%', srcset: '',
  urls: { original: '/api/media/file/harbour-a1.jpg', variants: { sm: { url: '/api/media/file/harbour-a1-sm.jpg', width: 640 } } },
  quality: { warning: true, level: 'warn', message: 'Too small for this slot.' },
}];

const SECTION_TYPES = [{
  id: 'hero', label: 'Hero', description: 'Big banner',
  fields: [
    { name: 'heading', label: 'Heading', type: 'text', required: true },
    { name: 'body', label: 'Body', type: 'richtext', help: 'Prose' },
    { name: 'image', label: 'Image', type: 'image' },
    { name: 'cards', label: 'Cards', type: 'repeater', fields: [{ name: 'title', label: 'Title', type: 'text' }] },
    { name: 'related', label: 'Related', type: 'reference', references: 'post', multiple: true },
  ],
}];

const COLLECTION = {
  id: 'post', label: 'Posts', titleField: 'title', description: 'Blog posts',
  fields: [
    { name: 'title', label: 'Title', type: 'text', required: true },
    { name: 'body', label: 'Body', type: 'richtext' },
  ],
};

const ROUTES = [
  [/\/api\/auth\/check/, { data: { authenticated: true, csrfToken: 't', user: { username: 'admin', displayName: 'Admin', role: 'admin', capabilities: ['users.manage', 'settings.manage', 'plugins.install', 'content.publish'] } } }],
  [/\/api\/pages\/[^?]+\/versions/, { data: [{ id: 'v1', savedAt: '2026-07-01T10:00:00+00:00', author: 'admin' }] }],
  [/\/api\/pages\b/, { data: PAGES, locale: 'en', locales: ['en', 'de'] }],
  [/\/api\/media\/capabilities/, { data: { acceptedMimeTypes: ['image/jpeg'], maxBytes: 1000000, resizingAvailable: true, variants: ['sm'] } }],
  [/\/api\/media/, { data: MEDIA }],
  [/\/api\/section-types/, { data: SECTION_TYPES, warnings: { 'bad.json': 'Malformed' } }],
  [/\/api\/collections\/[^/]+\/entries/, { data: [{ slug: 'first', title: 'First post', data: { title: 'First post' }, publication: LIVE }] }],
  [/\/api\/collections/, { data: [COLLECTION] }],
  [/\/api\/users/, { data: [
    { slug: 'admin', data: { displayName: 'Admin', email: 'admin@example.com', role: 'admin' } },
    { slug: 'edd', data: { displayName: 'Edd', email: 'edd@example.com', role: 'editor' } },
  ] }],
  [/\/api\/plugins/, { data: [
    { id: 'seo', name: 'SEO', state: 'activated', version: '1.0.0', description: 'SEO tools' },
    { id: 'forms', name: 'Forms', state: 'deactivated', version: '1.0.0', description: 'Contact forms' },
  ] }],
  [/\/api\/marketplace/, { data: { registryConfigured: true, available: [{ id: 'seo', name: 'SEO', version: '1.0.0', description: 'SEO tools', installed: false }] } }],
  [/\/api\/redirects/, { data: [{ from: '/old', to: '/new', permanent: true }] }],
  [/\/api\/menus\/[^/?]+/, { data: { id: 'main', name: 'Main navigation', items: [{ label: 'Home', target: '/', children: [{ label: 'Team', target: '/team' }] }] } }],
  [/\/api\/menus/, { data: [{ id: 'main', name: 'Main navigation' }] }],
  [/\/api\/forms\/submissions/, { data: [{ id: 's1', page: 'contact', submittedAt: '2026-07-01T10:00:00+00:00', values: { name: 'A visitor', message: 'Hello' } }] }],
  [/\/api\/themes/, { data: { themes: [
    { id: 'default', name: 'Default', version: '1.0.0', author: 'Click', description: 'The default theme', active: true },
    { id: 'dark', name: 'Dark', version: '1.0.0', author: 'Click', description: 'A dark theme', active: false },
  ] } }],
  [/\/api\/updates\/history/, { data: [{ version: '1.0.0', appliedAt: '2026-07-01T10:00:00+00:00', outcome: 'ok' }] }],
  [/\/api\/updates/, { data: { currentVersion: '1.0.0', policy: 'security', configured: true, hasUpdate: true, release: { version: '1.1.0', notes: 'Fixes', security: true }, step: 'minor', reason: '' } }],
  [/\/api\/settings/, { data: { siteName: 'Site', branding: { name: 'Site', primaryColor: '#0f766e' } } }],
  [/\/api\/collaboration\/comments/, { data: [{ id: 'c1', author: 'admin', body: 'Needs a shorter headline.', createdAt: '2026-07-01T10:00:00+00:00', resolved: false }] }],
  [/\/api\/collaboration\/presence/, { data: [{ username: 'admin', displayName: 'Admin', at: '2026-07-01T10:00:00+00:00' }] }],
];

const payloadFor = (url) => {
  for (const [pattern, payload] of ROUTES) if (pattern.test(String(url))) return payload;
  return { data: [] };
};

beforeEach(() => {
  global.fetch = vi.fn(async (url) => ({
    ok: true,
    status: 200,
    json: async () => payloadFor(url),
    text: async () => JSON.stringify(payloadFor(url)),
  }));
  window.confirm = vi.fn(() => true);
  window.alert = vi.fn();
});

/** Mount, let every fetch settle, and hand back a wrapper attached to the page. */
const render = async (component, props = {}) => {
  const wrapper = mount(component, { props, attachTo: attached() });
  await flushPromises();
  await flushPromises();
  return wrapper;
};

const field = (over = {}) => ({ name: 'headline', label: 'Headline', type: 'text', ...over });

describe('axe: the screens an editor uses daily', () => {
  it('the sign-in screen has no violations', async () => {
    await expectNoViolations(await render(Login));
  });

  it('the navigation rail has no violations, expanded or collapsed', async () => {
    await expectNoViolations(await render(Sidebar, { activeRoute: '/admin/pages', userRole: 'admin', showBuilder: true }));
    await expectNoViolations(await render(Sidebar, { activeRoute: '/admin/pages', userRole: 'admin', collapsed: true }));
  });

  it('the page list has no violations', async () => {
    await expectNoViolations(await render(Pages));
  });

  it('the page editor has no violations', async () => {
    await expectNoViolations(await render(PageEdit, { slug: 'home' }));
  });

  it('the media library has no violations', async () => {
    await expectNoViolations(await render(Media));
  });

  it('the section editor has no violations, including an orphaned section', async () => {
    const wrapper = await render(SectionEditor, {
      modelValue: [
        { type: 'hero', values: { heading: 'x', cards: [{ title: 'a' }], related: ['first'] } },
        { type: 'removed-type', values: { a: 1 } },
      ],
      errors: { '0.heading': 'Heading is required.' },
    });
    await expectNoViolations(wrapper);
  });

  it('the collection screens have no violations', async () => {
    await expectNoViolations(await render(Collections));
    await expectNoViolations(await render(CollectionEntries, { type: COLLECTION }));
    await expectNoViolations(await render(CollectionEntryEdit, { type: COLLECTION, slug: 'first' }));
  });
});

describe('axe: every field type', () => {
  const cases = [
    ['text', { field: field() }],
    ['textarea', { field: field({ type: 'textarea', help: 'Some help' }) }],
    ['select', { field: field({ type: 'select', options: ['a', 'b'] }) }],
    ['boolean', { field: field({ type: 'boolean' }) }],
    ['number', { field: field({ type: 'number', min: 0, max: 9 }) }],
    ['richtext', { field: field({ type: 'richtext' }) }],
    ['richtext in error', { field: field({ type: 'richtext' }), error: 'Required.' }],
    ['single reference', { field: field({ type: 'reference', references: 'post' }) }],
    ['multiple reference', { field: field({ type: 'reference', references: 'post', multiple: true }), modelValue: ['first'] }],
  ];

  for (const [name, props] of cases) {
    it(`a ${name} field has no violations`, async () => {
      await expectNoViolations(await render(FieldInput, props));
    });
  }

  it('the image field has no violations with the chooser open', async () => {
    const wrapper = await render(ImageField, { field: field({ type: 'image', displayWidth: 800 }), modelValue: 'harbour-a1' });
    await wrapper.findAll('button.btn-sm').at(-1).trigger('click');
    await flushPromises();
    expect(wrapper.find('.chooser').exists()).toBe(true);
    await expectNoViolations(wrapper);
  });

  it('the repeater has no violations with rows in it', async () => {
    await expectNoViolations(await render(RepeaterField, {
      field: field({ type: 'repeater', label: 'Cards', fields: [field()], max: 5 }),
      modelValue: [{ headline: 'a' }, { headline: 'b' }],
    }));
  });

  it('the rich-text editor has no violations standing alone', async () => {
    await expectNoViolations(await render(RichTextField));
  });

  it('the reference picker has no violations with chips', async () => {
    await expectNoViolations(await render(ReferenceField, {
      field: field({ label: 'Related', type: 'reference', references: 'post', multiple: true }),
      modelValue: ['first'],
    }));
  });
});

describe('axe: the remaining admin screens', () => {
  const screens = [
    ['Menus', Menus, {}],
    ['Users', Users, { userRole: 'admin', currentUsername: 'admin' }],
    ['Settings', Settings, {}],
    ['Redirects', Redirects, {}],
    ['Plugins', Plugins, { userRole: 'admin' }],
    ['Marketplace', Marketplace, {}],
    ['Updates', Updates, {}],
    ['Themes', Themes, {}],
    ['Submissions', FormSubmissions, {}],
    ['Profile', Profile, { user: { username: 'admin', displayName: 'Admin', email: 'a@b.c', role: 'admin' } }],
    ['ChangePassword', ChangePassword, { forced: true }],
    ['PageVersions', PageVersions, { versions: [{ id: 'v1', savedAt: '2026-07-01T10:00:00+00:00', author: 'admin' }], canRestore: true }],
    ['PageLanguages', PageLanguages, { locales: ['en', 'de'], current: 'en' }],
    ['CommentsPanel', CommentsPanel, { page: 'home', locale: 'en' }],
  ];

  for (const [name, component, props] of screens) {
    it(`${name} has no violations`, async () => {
      await expectNoViolations(await render(component, props));
    });
  }

  // Every publication state, because each one is a different tone and a
  // different set of buttons.
  for (const [name, publication] of [['live', LIVE], ['pending', PENDING], ['never published', NEVER], ['taken down', DOWN]]) {
    it(`the publication banner has no violations when ${name}`, async () => {
      await expectNoViolations(await render(PagePublication, { publication, canPublish: true, canUnpublish: true, slug: 'home' }));
    });
  }
});

describe('axe: the whole admin page', () => {
  /**
   * The one place the document-scoped rules mean anything. A component mounted
   * alone is not a page; this is, so landmarks, a single h1 and a way past the
   * navigation are all fair questions here and nowhere else.
   */
  it('renders one main landmark, a heading and a way past the navigation', async () => {
    const wrapper = await render(AdminApp);

    await expectNoViolations(wrapper, {
      rules: {
        region: { enabled: true },
        'landmark-one-main': { enabled: true },
        'landmark-unique': { enabled: true },
        bypass: { enabled: true },
      },
    });
  });

  it('offers a skip link that moves focus into the content, not just the viewport', async () => {
    const wrapper = await render(AdminApp);

    const skip = wrapper.find('.skip-link');
    expect(skip.exists()).toBe(true);
    expect(skip.attributes('href')).toBe('#admin-main');

    await skip.trigger('click');
    expect(document.activeElement).toBe(wrapper.find('#admin-main').element);
  });

  it('closes the mobile drawer on Escape', async () => {
    window.matchMedia = vi.fn(() => ({ matches: true }));
    const wrapper = await render(AdminApp);

    await wrapper.find('.icon-button').trigger('click');
    expect(wrapper.find('.sidebar-shell').classes()).toContain('is-open');

    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
    await flushPromises();
    expect(wrapper.find('.sidebar-shell').classes()).not.toContain('is-open');
  });

  it('names the theme toggle by what it does and carries its state', async () => {
    const wrapper = await render(AdminApp);

    const toggle = wrapper.find('.chip-button');
    // "Light" alone reads as a label. The name has to say it is a switch.
    expect(toggle.attributes('aria-label')).toMatch(/dark theme/i);
    expect(toggle.attributes('aria-pressed')).toBe('false');

    await toggle.trigger('click');
    expect(toggle.attributes('aria-pressed')).toBe('true');
  });
});

describe('names and associations that axe cannot see', () => {
  /**
   * A placeholder satisfies axe's `label` rule and satisfies nobody else: it is
   * announced inconsistently, and it vanishes the moment the field has a value —
   * exactly when somebody re-reading the form needs to know what the field was.
   */
  it('the sign-in fields are labelled, not merely placeholdered', async () => {
    const wrapper = await render(Login);

    for (const [labelText, type] of [['Username', 'text'], ['Password', 'password']]) {
      const label = wrapper.findAll('label').find((l) => l.text() === labelText);
      const input = wrapper.find(`input[type="${type}"]`);
      expect(label.attributes('for')).toBe(input.attributes('id'));
      expect(input.attributes('id')).toBeTruthy();
    }
  });

  it('a refused sign-in announces itself', async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 401, json: async () => ({ error: { message: 'Wrong password' } }) }));
    const wrapper = await render(Login);

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    const error = wrapper.find('.error');
    expect(error.text()).toBe('Wrong password');
    expect(error.attributes('role')).toBe('alert');
  });

  it('the profile fields are bound to their captions', async () => {
    const wrapper = await render(Profile, { user: { displayName: 'Admin', email: 'a@b.c' } });

    for (const [labelText, type] of [['Display Name', 'text'], ['Email', 'email']]) {
      const label = wrapper.findAll('label').find((l) => l.text() === labelText);
      expect(label.attributes('for')).toBe(wrapper.find(`input[type="${type}"]`).attributes('id'));
    }
  });

  it('the rich-text editor is named by the field label, not left anonymous', async () => {
    const wrapper = await render(FieldInput, { field: field({ type: 'richtext', label: 'Body' }) });

    const label = wrapper.find('label.field-label');
    const editor = wrapper.find('[role="textbox"]');
    // `for` cannot bind a label to a contenteditable div, so it must not claim to.
    expect(label.attributes('for')).toBeUndefined();
    expect(label.attributes('id')).toBeTruthy();
    expect(editor.attributes('aria-labelledby')).toBe(label.attributes('id'));
  });

  it('the rich-text editor names itself when used without a wrapper', async () => {
    const wrapper = await render(RichTextField);
    expect(wrapper.find('[role="textbox"]').attributes('aria-label')).toBeTruthy();
  });

  it('the reference picker names its add-dropdown after the field', async () => {
    const wrapper = await render(ReferenceField, {
      field: { name: 'related', label: 'Related posts', type: 'reference', references: 'post', multiple: true },
      modelValue: ['first'],
    });

    expect(wrapper.find('select').attributes('aria-label')).toBe('Add Related posts');
  });

  it('the media checkboxes are named on the control, not by a tooltip', async () => {
    const wrapper = await render(Media);

    const box = wrapper.find('[data-test="select-item"]');
    expect(box.attributes('aria-label')).toBe('Select Harbour.jpg');
  });

  it('the profile button says what it opens rather than reading out an initial', async () => {
    const wrapper = await render(AdminApp);
    expect(wrapper.find('.profile-button').attributes('aria-label')).toMatch(/^Profile —/);
  });

  /**
   * The drag handles are pointer-only and take no focus. ARIA forbids aria-label
   * on a role-less <span>, and screen readers duly drop it — so labelling them
   * advertised a control a keyboard cannot reach. The arrow buttons are the
   * reachable path, and they are labelled.
   */
  it('hides the pointer-only drag handles rather than naming them', async () => {
    const sections = await render(SectionEditor, { modelValue: [{ type: 'hero', values: {} }] });
    const handle = sections.find('.drag-handle');
    expect(handle.attributes('aria-hidden')).toBe('true');
    expect(handle.attributes('aria-label')).toBeUndefined();

    const repeater = await render(RepeaterField, {
      field: field({ type: 'repeater', label: 'Cards', fields: [field()] }),
      modelValue: [{ headline: 'a' }],
    });
    const rowHandle = repeater.find('.drag-handle');
    expect(rowHandle.attributes('aria-hidden')).toBe('true');
    expect(rowHandle.attributes('aria-label')).toBeUndefined();
  });
});

describe('heading order', () => {
  /**
   * A screen reader navigates a screen by its headings. Jumping h1 → h3 says
   * "there is a level you cannot see", and there is not.
   */
  const levels = (wrapper) =>
    wrapper.findAll('h1, h2, h3, h4, h5, h6').map((h) => Number(h.element.tagName[1]));

  const noSkips = (found) => found.every((level, i) => i === 0 || level - found[i - 1] <= 1);

  it('the page list steps h1 → h2', async () => {
    const found = levels(await render(Pages));
    expect(found[0]).toBe(1);
    expect(noSkips(found)).toBe(true);
  });

  it('the plugin list steps h1 → h2', async () => {
    const found = levels(await render(Plugins, { userRole: 'admin' }));
    expect(found[0]).toBe(1);
    expect(noSkips(found)).toBe(true);
  });

  it('the collection entry list steps h1 → h2', async () => {
    const found = levels(await render(CollectionEntries, { type: COLLECTION }));
    expect(found[0]).toBe(1);
    expect(noSkips(found)).toBe(true);
  });

  it('the theme list steps h1 → h2', async () => {
    const found = levels(await render(Themes));
    expect(found[0]).toBe(1);
    expect(noSkips(found)).toBe(true);
  });
});

describe('status that is not conveyed by colour alone', () => {
  it('every page state is spelled out beside its badge', async () => {
    const wrapper = await render(Pages);
    expect(wrapper.findAll('.status-badge').map((b) => b.text()))
      .toEqual(['Live', 'Live, edits pending', 'Never published', 'Taken down']);
  });

  it('async outcomes are announced', async () => {
    // Save and publish both finish without moving focus. Without a live region
    // a screen reader is told nothing at all.
    const wrapper = await render(PageEdit, { slug: 'home' });
    expect(wrapper.findAll('[role="alert"], [role="status"]').length).toBeGreaterThan(0);
  });
});
