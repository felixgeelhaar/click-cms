const display = (value) => {
  if (value === undefined) return '—';
  const text = typeof value === 'string' ? value : JSON.stringify(value);
  return text.length > 110 ? `${text.slice(0, 109)}…` : text;
};

function flatten(value, prefix, output) {
  if (value && typeof value === 'object') {
    const entries = Array.isArray(value)
      ? value.map((item, index) => [String(index), item])
      : Object.entries(value);
    for (const [key, child] of entries) flatten(child, prefix ? `${prefix}.${key}` : key, output);
    return;
  }
  output.set(prefix, value);
}

export function comparePageDrafts(before = {}, after = {}) {
  const left = new Map();
  const right = new Map();
  for (const key of ['title', 'sections', 'seo']) {
    if (before[key] !== undefined) flatten(before[key], key, left);
    if (after[key] !== undefined) flatten(after[key], key, right);
  }
  const paths = [...new Set([...left.keys(), ...right.keys()])].sort((a, b) => {
    const rank = (path) => path === 'title' ? 0 : path.startsWith('sections') ? 1 : 2;
    return rank(a) - rank(b) || a.localeCompare(b);
  });
  return paths.flatMap((path) => {
    const a = left.get(path);
    const b = right.get(path);
    if (JSON.stringify(a) === JSON.stringify(b)) return [];
    return [{ path: path.replace(/\.([0-9]+)(?=\.|$)/g, '[$1]'), before: display(a), after: display(b) }];
  });
}
