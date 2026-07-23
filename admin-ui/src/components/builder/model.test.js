import { describe, it, expect } from 'vitest';
import {
  createEmptyBuilder,
  normalizeBuilder,
  addNode,
  removeNode,
  moveNode,
  updateProp,
  updateStyle,
  findParentId,
  isContainer,
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
  it('is true only for section and grid', () => {
    expect(isContainer('section')).toBe(true);
    expect(isContainer('grid')).toBe(true);
    expect(isContainer('text')).toBe(false);
    expect(isContainer('image')).toBe(false);
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
