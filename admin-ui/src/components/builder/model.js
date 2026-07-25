/**
 * Pure builder-tree operations, kept out of the Vue components on purpose.
 *
 * The renderer in plugins/visual-builder/bootstrap.php is the authority on the
 * shape this produces: a `builder` object of { version, root, breakpoints[],
 * nodes{} }, where each node is { id, type, children[], props{}, styles{} } and
 * optionally `responsive`. Keeping the shape logic here — rather than tangled
 * into recursive components — is what lets it be unit-tested against that schema
 * directly, and is why the same functions are reused by every editor component.
 */

// The nodes that hold children; everything else is a leaf. The public renderer
// draws these as block containers and the rest as their own tags, so "can this
// accept a drop?" is exactly this predicate.
const CONTAINER_TYPES = new Set(['section', 'grid', 'columns', 'column']);

// What the palette offers. `column` is absent on purpose: a column only exists
// as part of a `columns` node, which creates and destroys its own, so offering
// a loose one would let an editor build a column with nothing to sit in.
export const NODE_TYPES = [
  'section',
  'columns',
  'grid',
  'text',
  'image',
  'video',
  'embed',
  'list',
  'quote',
  'button',
  'divider',
  'spacer',
  'chart',
];

// A columns node cannot usefully be split further than this, and the generated
// media query would produce unreadable slivers past it.
export const MAX_COLUMNS = 6;

export function isContainer(type) {
  return CONTAINER_TYPES.has(type);
}

// Per-type starting props/styles. These mirror what the server renderer reads
// (props.text, props.src/alt, props.label/href, props.columns, chart's
// chartType/data …) so a freshly-added node renders as something visible rather
// than an empty tag the editor cannot see or select.
const NODE_DEFAULTS = {
  section: { props: {}, styles: { padding: '24px' } },
  grid: { props: { columns: 2 }, styles: { display: 'grid', gap: '16px' } },
  // `stackAt` names the breakpoint at which the columns stop stacking. The
  // renderer generates that media query itself, so an author gets a layout that
  // survives a phone without hand-writing a responsive override.
  columns: { props: { count: 2, stackAt: 'sm' }, styles: { display: 'grid', gap: '24px' } },
  column: { props: {}, styles: {} },
  text: { props: { text: 'New text' }, styles: {} },
  image: { props: { src: '', alt: '' }, styles: {} },
  // Nothing is fetched and nothing moves until a visitor asks; autoplay is the
  // author's explicit choice, and the renderer mutes it when they make it.
  video: { props: { src: '', poster: '', label: '', controls: true, autoplay: false, preload: 'none' }, styles: {} },
  embed: { props: { url: '', title: '', height: 0 }, styles: {} },
  list: { props: { ordered: false, items: ['First item', 'Second item'] }, styles: {} },
  quote: { props: { text: 'Quoted text', attribution: '', source: '', cite: '' }, styles: {} },
  divider: { props: { lineStyle: 'solid', thickness: 1, color: '' }, styles: { margin: '24px 0' } },
  spacer: { props: {}, styles: { height: '32px' } },
  chart: { props: { chartType: 'bar', title: '', color: '#0ea5a4', width: 640, height: 280, data: [] }, styles: {} },
};

// A monotonic counter mixed with a time+random suffix. Ids only need to be
// unique within one document; the counter guarantees no collision inside a
// single session even when two nodes are added in the same millisecond.
let seq = 0;
function genId() {
  seq += 1;
  return `node-${Date.now().toString(36)}-${seq.toString(36)}-${Math.floor(Math.random() * 1e6).toString(36)}`;
}

export function defaultBreakpoints() {
  return [
    { id: 'base', label: 'Base', minWidth: 0 },
    { id: 'sm', label: 'Small', minWidth: 640 },
    { id: 'lg', label: 'Large', minWidth: 1024 },
  ];
}

export function createNode(type) {
  const defaults = NODE_DEFAULTS[type] ?? NODE_DEFAULTS.section;
  return {
    id: genId(),
    type,
    // Every node carries a children array even when it is a leaf: it is
    // schema-valid, keeps the shape uniform for the recursive walk, and the
    // renderer simply never iterates a leaf's (empty) children.
    children: [],
    props: JSON.parse(JSON.stringify(defaults.props)),
    styles: JSON.parse(JSON.stringify(defaults.styles)),
  };
}

export function createEmptyBuilder() {
  const root = createNode('section');
  root.styles = { padding: '48px' };
  return {
    version: '1.0',
    root: root.id,
    breakpoints: defaultBreakpoints(),
    nodes: { [root.id]: root },
  };
}

/**
 * Coerce whatever a page's `data.builder` holds into a valid, editable tree.
 *
 * A stored builder can be absent, half-written, or point at a root that no
 * longer exists. Rather than let the canvas render nothing or throw, an
 * unusable payload falls back to a fresh single-section document — the same
 * empty state a page that never had a builder starts from.
 */
export function normalizeBuilder(raw) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return createEmptyBuilder();
  if (typeof raw.root !== 'string' || !raw.nodes || typeof raw.nodes !== 'object') {
    return createEmptyBuilder();
  }
  if (!raw.nodes[raw.root]) return createEmptyBuilder();

  // Deep clone so editing never reaches back into the object the API returned.
  const clone = JSON.parse(JSON.stringify(raw));
  clone.version = typeof clone.version === 'string' ? clone.version : '1.0';
  if (!Array.isArray(clone.breakpoints)) clone.breakpoints = defaultBreakpoints();

  for (const id of Object.keys(clone.nodes)) {
    const node = clone.nodes[id];
    // The id is the map key by definition; a mismatch in the stored payload
    // would break every parent lookup, so the key wins.
    node.id = id;
    if (typeof node.type !== 'string') node.type = 'section';
    if (!Array.isArray(node.children)) node.children = [];
    if (!node.props || typeof node.props !== 'object') node.props = {};
    if (!node.styles || typeof node.styles !== 'object') node.styles = {};
  }
  return clone;
}

export function findParentId(builder, childId) {
  for (const id of Object.keys(builder.nodes)) {
    const node = builder.nodes[id];
    if (Array.isArray(node.children) && node.children.includes(childId)) return id;
  }
  return null;
}

// Every id in the subtree rooted at `id`, `id` included. Used both to delete a
// node with its descendants and to stop a node being dropped into itself.
export function collectSubtree(builder, id) {
  const out = [id];
  const node = builder.nodes[id];
  if (node && Array.isArray(node.children)) {
    for (const childId of node.children) out.push(...collectSubtree(builder, childId));
  }
  return out;
}

/**
 * Add a new node of `type` and return its id.
 *
 * Where it lands follows the selection: dropped inside a selected container,
 * or placed as the next sibling of a selected leaf so a click-then-add reads
 * left-to-right the way an editor expects. With nothing (or nothing usable)
 * selected it appends to the root, which is always a container.
 */
export function addNode(builder, type, targetId) {
  const node = createNode(type);
  builder.nodes[node.id] = node;

  // A columns node is meaningless without its columns, and an editor should not
  // have to assemble one by hand, so the children come with it.
  if (type === 'columns') {
    for (let i = 0; i < (Number(node.props.count) || 2); i += 1) {
      const column = createNode('column');
      builder.nodes[column.id] = column;
      node.children.push(column.id);
    }
  }

  let target = targetId ? builder.nodes[targetId] : null;
  // The columns node itself holds only columns. Selecting it — which is what an
  // editor clicking the outer box does — and adding content means the first
  // column, not a stray child sharing the grid with them.
  if (target && target.type === 'columns' && type !== 'column' && target.children.length > 0) {
    target = builder.nodes[target.children[0]] ?? target;
  }

  if (target && isContainer(target.type)) {
    target.children.push(node.id);
  } else if (target) {
    const parentId = findParentId(builder, targetId);
    const parent = parentId ? builder.nodes[parentId] : null;
    if (parent) {
      parent.children.splice(parent.children.indexOf(targetId) + 1, 0, node.id);
    } else {
      builder.nodes[builder.root].children.push(node.id);
    }
  } else {
    builder.nodes[builder.root].children.push(node.id);
  }
  return node.id;
}

/**
 * Remove a node and its whole subtree.
 *
 * The root is refused: a document with no root cannot be rendered or re-edited,
 * so there must always be one section to build on. Descendants are deleted with
 * the node so no orphan entries linger in `nodes` to bloat the saved payload.
 */
export function removeNode(builder, id) {
  if (id === builder.root) return false;
  if (!builder.nodes[id]) return false;

  const parentId = findParentId(builder, id);
  if (parentId) {
    const parent = builder.nodes[parentId];
    parent.children = parent.children.filter((childId) => childId !== id);
  }
  for (const nodeId of collectSubtree(builder, id)) {
    delete builder.nodes[nodeId];
  }
  return true;
}

/**
 * Reparent/reorder `dragId` relative to `refId`.
 *
 * `position` is 'before' | 'after' | 'inside'. The root cannot be moved, a node
 * cannot be dropped into its own subtree (which would detach it from the tree),
 * and 'inside' only applies to containers — every other case is refused rather
 * than corrupting the tree.
 */
export function moveNode(builder, dragId, refId, position) {
  if (dragId === refId || dragId === builder.root) return false;
  if (!builder.nodes[dragId] || !builder.nodes[refId]) return false;

  // Dropping a node onto one of its own descendants would cut the branch loose.
  if (collectSubtree(builder, dragId).includes(refId)) return false;

  const currentParentId = findParentId(builder, dragId);
  if (!currentParentId) return false;

  if (position === 'inside') {
    if (!isContainer(builder.nodes[refId].type)) return false;
    builder.nodes[currentParentId].children =
      builder.nodes[currentParentId].children.filter((id) => id !== dragId);
    builder.nodes[refId].children.push(dragId);
    return true;
  }

  const targetParentId = findParentId(builder, refId);
  if (!targetParentId) return false;

  builder.nodes[currentParentId].children =
    builder.nodes[currentParentId].children.filter((id) => id !== dragId);

  const siblings = builder.nodes[targetParentId].children;
  const refIndex = siblings.indexOf(refId);
  const insertAt = position === 'after' ? refIndex + 1 : refIndex;
  siblings.splice(insertAt, 0, dragId);
  return true;
}

/**
 * Change how many columns a `columns` node has, adding or removing them.
 *
 * The count and the actual children have to move together: the renderer builds
 * its media query from `props.count` but lays out whatever children it finds, so
 * a count of 3 over 2 columns would leave a visible gap in the published page.
 *
 * Shrinking drops the trailing columns *with their contents* — the same as
 * deleting them, which is what the numbers on screen already imply. It is
 * undoable only by not saving, so the inspector says so next to the field.
 */
export function setColumnCount(builder, id, count) {
  const node = builder.nodes[id];
  if (!node || node.type !== 'columns') return;

  const parsed = parseInt(count, 10);
  const next = Number.isNaN(parsed) ? 1 : Math.min(MAX_COLUMNS, Math.max(1, parsed));
  node.props = { ...node.props, count: next };

  while (node.children.length < next) {
    const column = createNode('column');
    builder.nodes[column.id] = column;
    node.children.push(column.id);
  }
  while (node.children.length > next) {
    removeNode(builder, node.children[node.children.length - 1]);
  }
}

export function updateProp(builder, id, key, value) {
  const node = builder.nodes[id];
  if (!node) return;
  node.props = { ...node.props, [key]: value };
}

export function updateStyle(builder, id, key, value) {
  const node = builder.nodes[id];
  if (!node) return;
  // An empty style value drops the key, so clearing a field in the inspector
  // does not leave `padding:""` in the payload and, through it, in the page CSS.
  const next = { ...node.styles };
  if (value === '' || value === null || value === undefined) {
    delete next[key];
  } else {
    next[key] = value;
  }
  node.styles = next;
}
