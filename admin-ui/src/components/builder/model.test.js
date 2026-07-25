import { describe, it, expect } from 'vitest';
import {
  createEmptyBuilder,
  createNode,
  normalizeBuilder,
  addNode,
  removeNode,
  moveNode,
  updateProp,
  updateStyle,
  setColumnCount,
  findParentId,
  isContainer,
  NODE_TYPES,
  MAX_COLUMNS,
} from './model.js';

/**
 * The tree operations are pure and carry every invariant the editor and the
 * server renderer rely on: a document always has a valid root, containers hold
 * children and leaves do not, removing prunes the whole subtree, and a move can
 * never detach a branch by dropping it into itself. Testing them directly is
 * cheaper and sharper than driving the same paths through the components.
 */

describe('createEmptyBuilder', () => {
  it('produces a valid single-section document', () => {
    const b = createEmptyBuilder();
    expect(b.version).toBe('1.0');
    expect(b.nodes[b.root]).toBeTruthy();
    expect(b.nodes[b.root].type).toBe('section');
    expect(Array.isArray(b.breakpoints)).toBe(true);
  });
});

describe('normalizeBuilder', () => {
  it('falls back to an empty builder for junk input', () => {
    expect(normalizeBuilder(null).nodes).toBeTruthy();
    expect(normalizeBuilder('nope').root).toBeTruthy();
    expect(normalizeBuilder({ root: 'x', nodes: {} }).nodes).toBeTruthy(); // root missing from nodes
  });

  it('keeps a valid builder but deep-clones it and repairs node shape', () => {
    const source = {
      version: '1.0',
      root: 'a',
      nodes: { a: { id: 'a', type: 'section' } }, // no children/props/styles
    };
    const out = normalizeBuilder(source);
    expect(out).not.toBe(source);
    expect(out.nodes.a.children).toEqual([]);
    expect(out.nodes.a.props).toEqual({});
    expect(out.nodes.a.styles).toEqual({});
    // Mutating the result must not reach the source.
    out.nodes.a.children.push('z');
    expect(source.nodes.a.children).toBeUndefined();
  });
});

describe('isContainer', () => {
  it('is true for the four types that hold children', () => {
    expect(isContainer('section')).toBe(true);
    expect(isContainer('grid')).toBe(true);
    expect(isContainer('columns')).toBe(true);
    expect(isContainer('column')).toBe(true);
    expect(isContainer('text')).toBe(false);
    expect(isContainer('image')).toBe(false);
    expect(isContainer('quote')).toBe(false);
    expect(isContainer('divider')).toBe(false);
  });
});

describe('NODE_TYPES', () => {
  it('offers every type the renderer knows', () => {
    for (const type of ['section', 'columns', 'grid', 'text', 'image', 'video', 'embed', 'list', 'quote', 'button', 'divider', 'spacer', 'chart']) {
      expect(NODE_TYPES).toContain(type);
    }
  });

  it('does not offer a loose column', () => {
    // A column only exists inside a columns node, which manages its own.
    expect(NODE_TYPES).not.toContain('column');
  });
});

describe('defaults for the new node types', () => {
  it('gives each type props the server renderer actually reads', () => {
    // These key names are the contract with plugins/visual-builder/bootstrap.php:
    // a rename on either side silently stops the node rendering.
    expect(createNode('columns').props).toMatchObject({ count: 2, stackAt: 'sm' });
    expect(createNode('video').props).toMatchObject({ src: '', preload: 'none', autoplay: false });
    expect(createNode('embed').props).toMatchObject({ url: '' });
    expect(createNode('list').props.items.length).toBeGreaterThan(0);
    expect(createNode('quote').props).toHaveProperty('attribution');
    expect(createNode('divider').props).toMatchObject({ lineStyle: 'solid', thickness: 1 });
  });

  it('does not autoplay a freshly added video', () => {
    expect(createNode('video').props.autoplay).toBe(false);
  });
});

describe('addNode', () => {
  it('nests into a selected container', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'text', b.root);
    expect(b.nodes[b.root].children).toContain(id);
    expect(b.nodes[id].type).toBe('text');
  });

  it('adds as the next sibling of a selected leaf', () => {
    const b = createEmptyBuilder();
    const first = addNode(b, 'text', b.root);
    const second = addNode(b, 'button', first); // leaf selected
    const children = b.nodes[b.root].children;
    expect(children.indexOf(second)).toBe(children.indexOf(first) + 1);
  });

  it('appends to the root when nothing is selected', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'image', null);
    expect(b.nodes[b.root].children).toContain(id);
  });
});

describe('adding a columns node', () => {
  it('materialises its columns so it is never an empty shell', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'columns', b.root);

    expect(b.nodes[id].props.count).toBe(2);
    expect(b.nodes[id].children).toHaveLength(2);
    for (const childId of b.nodes[id].children) {
      expect(b.nodes[childId].type).toBe('column');
    }
  });

  it('sends content added against the columns node into its first column', () => {
    const b = createEmptyBuilder();
    const columnsId = addNode(b, 'columns', b.root);
    const [first] = b.nodes[columnsId].children;

    const textId = addNode(b, 'text', columnsId);

    expect(b.nodes[first].children).toContain(textId);
    expect(b.nodes[columnsId].children).not.toContain(textId);
  });

  it('lets each column hold its own children', () => {
    const b = createEmptyBuilder();
    const columnsId = addNode(b, 'columns', b.root);
    const [left, right] = b.nodes[columnsId].children;

    const leftText = addNode(b, 'text', left);
    const rightImage = addNode(b, 'image', right);

    expect(b.nodes[left].children).toEqual([leftText]);
    expect(b.nodes[right].children).toEqual([rightImage]);
  });
});

describe('setColumnCount', () => {
  it('adds columns as the count grows', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'columns', b.root);

    setColumnCount(b, id, 4);

    expect(b.nodes[id].props.count).toBe(4);
    expect(b.nodes[id].children).toHaveLength(4);
  });

  it('drops the trailing columns and their contents as the count shrinks', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'columns', b.root);
    const [, right] = b.nodes[id].children;
    const doomed = addNode(b, 'text', right);

    setColumnCount(b, id, 1);

    expect(b.nodes[id].children).toHaveLength(1);
    // No orphans left behind in the node map to bloat the saved payload.
    expect(b.nodes[right]).toBeUndefined();
    expect(b.nodes[doomed]).toBeUndefined();
  });

  it('clamps a nonsensical count rather than trusting the input', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'columns', b.root);

    setColumnCount(b, id, 0);
    expect(b.nodes[id].props.count).toBe(1);

    setColumnCount(b, id, 999);
    expect(b.nodes[id].props.count).toBe(MAX_COLUMNS);
    expect(b.nodes[id].children).toHaveLength(MAX_COLUMNS);

    setColumnCount(b, id, 'nonsense');
    expect(b.nodes[id].props.count).toBe(1);
  });

  it('ignores a node that is not a columns node', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'text', b.root);
    setColumnCount(b, id, 3);
    expect(b.nodes[id].props.count).toBeUndefined();
  });
});

describe('removeNode', () => {
  it('prunes the node and its whole subtree', () => {
    const b = createEmptyBuilder();
    const section = addNode(b, 'section', b.root);
    const child = addNode(b, 'text', section);
    expect(removeNode(b, section)).toBe(true);
    expect(b.nodes[section]).toBeUndefined();
    expect(b.nodes[child]).toBeUndefined();
    expect(b.nodes[b.root].children).not.toContain(section);
  });

  it('refuses to remove the root', () => {
    const b = createEmptyBuilder();
    expect(removeNode(b, b.root)).toBe(false);
    expect(b.nodes[b.root]).toBeTruthy();
  });
});

describe('moveNode', () => {
  it('reorders siblings with before/after', () => {
    const b = createEmptyBuilder();
    const a = addNode(b, 'text', b.root);
    const c = addNode(b, 'text', b.root);
    expect(b.nodes[b.root].children).toEqual([a, c]);
    moveNode(b, c, a, 'before');
    expect(b.nodes[b.root].children).toEqual([c, a]);
  });

  it('reparents a node inside a container', () => {
    const b = createEmptyBuilder();
    const section = addNode(b, 'section', b.root);
    const leaf = addNode(b, 'text', b.root);
    moveNode(b, leaf, section, 'inside');
    expect(b.nodes[section].children).toContain(leaf);
    expect(b.nodes[b.root].children).not.toContain(leaf);
  });

  it('refuses to drop a node into its own subtree', () => {
    const b = createEmptyBuilder();
    const outer = addNode(b, 'section', b.root);
    const inner = addNode(b, 'section', outer);
    expect(moveNode(b, outer, inner, 'inside')).toBe(false);
    // The outer section keeps its child; nothing detached.
    expect(b.nodes[outer].children).toContain(inner);
  });

  it('refuses to move the root', () => {
    const b = createEmptyBuilder();
    const section = addNode(b, 'section', b.root);
    expect(moveNode(b, b.root, section, 'inside')).toBe(false);
  });
});

describe('updateProp / updateStyle', () => {
  it('sets a prop value', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'text', b.root);
    updateProp(b, id, 'text', 'Hi');
    expect(b.nodes[id].props.text).toBe('Hi');
  });

  it('sets a style value and clears it when emptied', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'text', b.root);
    updateStyle(b, id, 'color', '#f00');
    expect(b.nodes[id].styles.color).toBe('#f00');
    updateStyle(b, id, 'color', '');
    expect(b.nodes[id].styles.color).toBeUndefined();
  });
});

describe('findParentId', () => {
  it('finds the parent of a child, and null for the root', () => {
    const b = createEmptyBuilder();
    const id = addNode(b, 'text', b.root);
    expect(findParentId(b, id)).toBe(b.root);
    expect(findParentId(b, b.root)).toBeNull();
  });
});
