import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Builder from './Builder.vue';

/**
 * The visual builder editor. These cover the load→edit→save round trip the
 * server renderer (plugins/visual-builder/bootstrap.php) depends on: a page's
 * stored `builder` must populate the canvas, palette/inspector/remove edits
 * must land on the right node, and Save must PUT a schema-shaped `builder`
 * object to the same page endpoint PageEdit uses — and nothing else, so the
 * page's other fields survive.
 *
 * Assertions target the exposed `builder` state and specific canvas/inspector
 * elements rather than whole-document text, because a builder tree repeats type
 * names ("text", "section") all over the DOM and a loose match would pass
 * against the wrong node.
 */

/* -------------------------------------------------------- fixtures -- */

const ok = (body, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

// A stored page whose data carries a builder with one section holding one text
// node — the minimal shape that proves "loading populates the canvas".
const PAGE_WITH_BUILDER = {
  title: 'Landing',
  builder: {
    version: '1.0',
    root: 'root-1',
    breakpoints: [{ id: 'base', label: 'Base', minWidth: 0 }],
    nodes: {
      'root-1': { id: 'root-1', type: 'section', children: ['text-1'], props: {}, styles: { padding: '48px' } },
      'text-1': { id: 'text-1', type: 'text', children: [], props: { text: 'Hello world' }, styles: {} },
    },
  },
};

/**
 * Routes fetch by method + path: the page list, a single page read (whose
 * `data` is what the fixture provides), and the PUT save which records its body
 * for inspection.
 */
const installFetch = ({ page = PAGE_WITH_BUILDER, list = [{ key: 'page:en:landing', slug: 'landing', data: { title: 'Landing' } }] } = {}) => {
  const putBodies = [];
  global.fetch = vi.fn(async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const u = new URL(String(url), 'http://localhost');
    const path = u.pathname;

    if (path === '/api/pages' && method === 'GET') {
      return ok({ data: list, locale: 'en', locales: ['en'] });
    }

    const pageMatch = path.match(/^\/api\/pages\/([^/]+)$/);
    if (pageMatch && method === 'GET') {
      return ok({ data: { slug: pageMatch[1], data: page }, locale: 'en' });
    }
    if (pageMatch && method === 'PUT') {
      putBodies.push(JSON.parse(init.body));
      return ok({ data: { slug: pageMatch[1] } });
    }
    return ok({ data: [] });
  });
  return { putBodies };
};

const mountBuilder = async (opts = {}) => {
  installFetch(opts);
  const wrapper = mount(Builder, { props: { slug: 'landing', ...(opts.props || {}) } });
  await flushPromises();
  await flushPromises();
  return wrapper;
};

const paletteButton = (wrapper, type) =>
  wrapper.findAll('.palette-button').find((b) => b.text() === type);

beforeEach(() => {
  vi.restoreAllMocks();
});

/* ------------------------------------------- loading populates canvas -- */

describe('loading a page with a builder', () => {
  it('populates the canvas from the stored builder', async () => {
    const wrapper = await mountBuilder();

    // The stored tree is the editable state, root and all.
    expect(wrapper.vm.builder.root).toBe('root-1');
    expect(Object.keys(wrapper.vm.builder.nodes)).toContain('text-1');

    // And the stored text is actually drawn on the canvas.
    const textNode = wrapper.find('.bnode[data-node-type="text"] .leaf-text');
    expect(textNode.exists()).toBe(true);
    expect(textNode.text()).toBe('Hello world');
  });

  it('starts a fresh single-section builder for a page with no builder', async () => {
    const wrapper = await mountBuilder({ page: { title: 'Bare' } });

    const nodeIds = Object.keys(wrapper.vm.builder.nodes);
    expect(nodeIds).toHaveLength(1);
    expect(wrapper.vm.builder.nodes[wrapper.vm.builder.root].type).toBe('section');
  });
});

/* --------------------------------------------- palette adds under parent -- */

describe('the palette adding nodes', () => {
  it('inserts a new node under the selected parent', async () => {
    const wrapper = await mountBuilder();

    // Loading selects the root section, so a plain "add text" nests into it.
    expect(wrapper.vm.selectedId).toBe('root-1');
    const before = wrapper.vm.builder.nodes['root-1'].children.length;

    await paletteButton(wrapper, 'text').trigger('click');

    const rootChildren = wrapper.vm.builder.nodes['root-1'].children;
    expect(rootChildren).toHaveLength(before + 1);

    const addedId = rootChildren[rootChildren.length - 1];
    expect(wrapper.vm.builder.nodes[addedId].type).toBe('text');
    // The new node becomes the selection, so the inspector points at it.
    expect(wrapper.vm.selectedId).toBe(addedId);
  });

  it('nests into a newly added section when that section is selected', async () => {
    const wrapper = await mountBuilder();

    await paletteButton(wrapper, 'section').trigger('click');
    const sectionId = wrapper.vm.selectedId;
    expect(wrapper.vm.builder.nodes[sectionId].type).toBe('section');

    await paletteButton(wrapper, 'button').trigger('click');
    expect(wrapper.vm.builder.nodes[sectionId].children).toContain(wrapper.vm.selectedId);
    expect(wrapper.vm.builder.nodes[wrapper.vm.selectedId].type).toBe('button');
  });
});

/* ------------------------------------------- inspector edits the node -- */

describe('the inspector editing a node', () => {
  it('updates the selected node prop as its field is edited', async () => {
    const wrapper = await mountBuilder();

    // Select the stored text node by clicking it on the canvas.
    await wrapper.find('.bnode[data-node-type="text"]').trigger('click');
    expect(wrapper.vm.selectedId).toBe('text-1');

    const textarea = wrapper.find('.inspector textarea');
    expect(textarea.exists()).toBe(true);
    await textarea.setValue('Edited copy');

    expect(wrapper.vm.builder.nodes['text-1'].props.text).toBe('Edited copy');
    // And the change is reflected back on the canvas.
    expect(wrapper.find('.bnode[data-node-type="text"] .leaf-text').text()).toBe('Edited copy');
  });

  it('updates a basic style from the inspector', async () => {
    const wrapper = await mountBuilder();
    await wrapper.find('.bnode[data-node-type="text"]').trigger('click');

    const bg = wrapper.findAll('.inspector .field input').find((i) => i.attributes('placeholder') === '#ffffff');
    await bg.setValue('#eee');

    expect(wrapper.vm.builder.nodes['text-1'].styles.background).toBe('#eee');
  });
});

/* -------------------------------------------- removing drops the subtree -- */

describe('removing a node', () => {
  it('drops the node and all of its children', async () => {
    const wrapper = await mountBuilder();

    // Build a section containing a text node, then remove the section.
    await paletteButton(wrapper, 'section').trigger('click');
    const sectionId = wrapper.vm.selectedId;
    await paletteButton(wrapper, 'text').trigger('click');
    const childId = wrapper.vm.selectedId;

    expect(wrapper.vm.builder.nodes[sectionId]).toBeTruthy();
    expect(wrapper.vm.builder.nodes[childId]).toBeTruthy();

    wrapper.vm.ctx.removeNode(sectionId);
    await flushPromises();

    expect(wrapper.vm.builder.nodes[sectionId]).toBeUndefined();
    // The child is gone too — no orphan left in the node map.
    expect(wrapper.vm.builder.nodes[childId]).toBeUndefined();
    expect(wrapper.vm.builder.nodes['root-1'].children).not.toContain(sectionId);
  });

  it('refuses to remove the root', async () => {
    const wrapper = await mountBuilder();
    wrapper.vm.ctx.removeNode('root-1');
    await flushPromises();
    expect(wrapper.vm.builder.nodes['root-1']).toBeTruthy();
  });
});

/* ------------------------------------------------ save matches the schema -- */

describe('saving the builder', () => {
  it('PUTs a schema-shaped builder object to the page endpoint', async () => {
    const { putBodies } = installFetch();
    const wrapper = mount(Builder, { props: { slug: 'landing' } });
    await flushPromises();
    await flushPromises();

    await paletteButton(wrapper, 'text').trigger('click');
    await wrapper.vm.save();
    await flushPromises();

    const put = global.fetch.mock.calls.find(([url, init]) =>
      (init?.method || 'GET').toUpperCase() === 'PUT' &&
      new URL(String(url), 'http://localhost').pathname === '/api/pages/landing');
    expect(put, 'save should PUT to /api/pages/landing').toBeTruthy();

    expect(putBodies).toHaveLength(1);
    const payload = putBodies[0];

    // Only `builder` is sent, so the page's title/sections/seo are left
    // untouched by the field-merging update on the server.
    expect(Object.keys(payload)).toEqual(['builder']);

    const b = payload.builder;
    expect(typeof b.version).toBe('string');
    expect(typeof b.root).toBe('string');
    expect(b.nodes[b.root]).toBeTruthy();
    for (const id of Object.keys(b.nodes)) {
      const node = b.nodes[id];
      expect(node.id).toBe(id);
      expect(typeof node.type).toBe('string');
      expect(Array.isArray(node.children)).toBe(true);
      expect(typeof node.props).toBe('object');
      expect(typeof node.styles).toBe('object');
    }
  });
});
