import { ref } from 'vue';

/**
 * Reorder helpers shared by the section list and repeater rows.
 *
 * The reordering logic lives here, apart from any component, because the index
 * math is the part that goes wrong: an off-by-one drops an item next to where it
 * should land. Keeping it pure lets a test pin "move item i to j" directly,
 * without a browser or a drag event.
 */

/**
 * Return a new array with the item at `from` relocated to land at index `to`.
 *
 * The source list is never mutated — callers emit the result, so the emitted
 * payload is the same array shape, only reordered. Out-of-range or no-op moves
 * yield an unchanged copy rather than throwing, so a stray drop is harmless.
 */
export function moveItem(list, from, to) {
  const next = [...list];
  const last = next.length - 1;
  if (from === to || from < 0 || from > last || to < 0 || to > last) return next;

  const [moved] = next.splice(from, 1);
  next.splice(to, 0, moved);
  return next;
}

/**
 * Drag state plus event handlers for a reorderable list, driven by native HTML5
 * drag events. The dragged item's index is held here rather than in
 * dataTransfer, so the same value survives across handlers without depending on
 * a browser's dataTransfer support.
 *
 * `onReorder(from, to)` runs on a completed drop; the component maps it onto its
 * own list (via moveItem) and commits. The pointer path is an enhancement — the
 * arrow controls remain the accessible way to reorder.
 */
export function useDragReorder(onReorder) {
  const dragIndex = ref(null);
  const overIndex = ref(null);

  const reset = () => {
    dragIndex.value = null;
    overIndex.value = null;
  };

  const start = (index) => {
    dragIndex.value = index;
  };

  const over = (index, event) => {
    if (dragIndex.value === null) return;
    // Without preventDefault the browser rejects the element as a drop target.
    if (event) event.preventDefault();
    overIndex.value = index;
  };

  const drop = (index) => {
    const from = dragIndex.value;
    reset();
    if (from === null || from === index) return;
    onReorder(from, index);
  };

  const end = () => reset();

  return { dragIndex, overIndex, start, over, drop, end };
}
