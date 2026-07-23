import { describe, it, expect, vi } from 'vitest';
import { moveItem, useDragReorder } from './dragReorder.js';

/**
 * The index math is where reordering breaks, so it is tested on its own — a drag
 * that lands an item one slot off looks the same in a browser as a correct one.
 */
describe('moveItem', () => {
  it('moves an item forward and lands it at the target index', () => {
    const result = moveItem(['a', 'b', 'c', 'd'], 0, 2);
    expect(result).toEqual(['b', 'c', 'a', 'd']);
    expect(result[2]).toBe('a');
  });

  it('moves an item backward and lands it at the target index', () => {
    const result = moveItem(['a', 'b', 'c', 'd'], 3, 1);
    expect(result).toEqual(['a', 'd', 'b', 'c']);
    expect(result[1]).toBe('d');
  });

  it('reorders adjacent items like a swap', () => {
    expect(moveItem(['a', 'b', 'c'], 1, 2)).toEqual(['a', 'c', 'b']);
  });

  it('does not mutate the source array', () => {
    const source = ['a', 'b', 'c'];
    moveItem(source, 0, 2);
    expect(source).toEqual(['a', 'b', 'c']);
  });

  it('preserves the identity of items it does not move', () => {
    const rows = [{ id: 1 }, { id: 2 }, { id: 3 }];
    const result = moveItem(rows, 2, 0);
    expect(result[0]).toBe(rows[2]);
    expect(result[1]).toBe(rows[0]);
    expect(result[2]).toBe(rows[1]);
  });

  it('returns an unchanged copy for a no-op or out-of-range move', () => {
    expect(moveItem(['a', 'b'], 1, 1)).toEqual(['a', 'b']);
    expect(moveItem(['a', 'b'], -1, 0)).toEqual(['a', 'b']);
    expect(moveItem(['a', 'b'], 0, 5)).toEqual(['a', 'b']);
  });
});

describe('useDragReorder', () => {
  it('calls onReorder with the dragged and dropped indices on a completed drop', () => {
    const onReorder = vi.fn();
    const dnd = useDragReorder(onReorder);

    dnd.start(0);
    dnd.over(2, { preventDefault: vi.fn() });
    dnd.drop(2);

    expect(onReorder).toHaveBeenCalledWith(0, 2);
  });

  it('tracks the drag and drop-target indices while dragging', () => {
    const dnd = useDragReorder(vi.fn());

    dnd.start(1);
    expect(dnd.dragIndex.value).toBe(1);

    dnd.over(3, { preventDefault: vi.fn() });
    expect(dnd.overIndex.value).toBe(3);
  });

  it('clears state and does not reorder when dropped on itself', () => {
    const onReorder = vi.fn();
    const dnd = useDragReorder(onReorder);

    dnd.start(2);
    dnd.drop(2);

    expect(onReorder).not.toHaveBeenCalled();
    expect(dnd.dragIndex.value).toBe(null);
    expect(dnd.overIndex.value).toBe(null);
  });

  it('ignores dragover when nothing is being dragged', () => {
    const dnd = useDragReorder(vi.fn());
    const event = { preventDefault: vi.fn() };

    dnd.over(1, event);

    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(dnd.overIndex.value).toBe(null);
  });
});
