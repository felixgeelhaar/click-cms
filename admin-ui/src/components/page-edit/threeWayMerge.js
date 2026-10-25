const clone = (value) => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const equal = (left, right) => {
  if (Object.is(left, right)) return true;
  if (Array.isArray(left) && Array.isArray(right)) {
    return left.length === right.length && left.every((item, index) => equal(item, right[index]));
  }
  if (!isRecord(left) || !isRecord(right)) return false;
  const keys = Object.keys(left);
  return keys.length === Object.keys(right).length
    && keys.every((key) => Object.hasOwn(right, key) && equal(left[key], right[key]));
};
const setOwn = (object, key, value) => Object.defineProperty(object, key, {
  configurable: true, enumerable: true, writable: true, value,
});

// Shape deliberately ignores field values. Section `type` is part of the
// shape, because matching fields by array index after a template change can
// attach text to the wrong section.
function shapeOf(value, key = '') {
  if (Array.isArray(value)) return ['array', value.map((item) => shapeOf(item))];
  if (isRecord(value)) {
    return ['object', Object.keys(value).sort().map((name) => [
      name,
      name === 'type' ? ['type', value[name]] : shapeOf(value[name], name),
    ])];
  }
  return [typeof value, key === 'type' ? value : null];
}

function canMergeArray(base, local, server, path) {
  if (!equal(shapeOf(base), shapeOf(local)) || !equal(shapeOf(base), shapeOf(server))) return false;
  // Never match array entries by index alone. Primitive arrays and repeaters
  // without persisted IDs can be reordered or duplicated, so concurrent edits
  // remain one explicit array-level conflict.
  const identities = (items) => items.map((item) => {
    if (!isRecord(item)) return null;
    const id = item.id ?? item._id;
    return typeof id === 'string' && id.length > 0 || typeof id === 'number' ? String(id) : null;
  });
  const baseIds = identities(base);
  const localIds = identities(local);
  const serverIds = identities(server);
  if (baseIds.some((id) => id === null) || new Set(baseIds).size !== baseIds.length) return false;
  return equal(baseIds, localIds) && equal(baseIds, serverIds);
}

function mergeNode(base, local, server, path, conflicts) {
  if (equal(local, base)) return clone(server);
  if (equal(server, base) || equal(local, server)) return clone(local);

  if (Array.isArray(base) && Array.isArray(local) && Array.isArray(server)) {
    if (canMergeArray(base, local, server, path)) {
      return base.map((value, index) => mergeNode(value, local[index], server[index], `${path}[${index}]`, conflicts));
    }
    conflicts.push({ path: path || 'value', base: clone(base), local: clone(local), server: clone(server) });
    return clone(local);
  }

  if (isRecord(base) && isRecord(local) && isRecord(server)) {
    const output = {};
    const keys = new Set([...Object.keys(base), ...Object.keys(local), ...Object.keys(server)]);
    for (const key of keys) {
      const merged = mergeNode(base[key], local[key], server[key], path ? `${path}.${key}` : key, conflicts);
      if (merged !== undefined) setOwn(output, key, merged);
    }
    return output;
  }

  conflicts.push({ path: path || 'value', base: clone(base), local: clone(local), server: clone(server) });
  return clone(local);
}

/**
 * Combine unambiguous changes. Conflicting leaves are retained as structured
 * choices; `merged` carries local values there only as a preview, and callers
 * must not apply it until every conflict has an explicit choice.
 */
export function mergeThreeWay(base, local, server) {
  const conflicts = [];
  const merged = mergeNode(base, local, server, '', conflicts);
  return { merged, conflicts, resolved: conflicts.length === 0 };
}

/** Apply the explicitly selected side for each conflict and merge the rest. */
export function resolveThreeWayMerge(base, local, server, choices = {}) {
  const conflicts = [];
  const resolveNode = (before, ours, theirs, path) => {
    if (equal(ours, before)) return clone(theirs);
    if (equal(theirs, before) || equal(ours, theirs)) return clone(ours);

    if (Array.isArray(before) && Array.isArray(ours) && Array.isArray(theirs)) {
      if (canMergeArray(before, ours, theirs, path)) {
        return before.map((value, index) => resolveNode(value, ours[index], theirs[index], `${path}[${index}]`));
      }
    } else if (isRecord(before) && isRecord(ours) && isRecord(theirs)) {
      const output = {};
      for (const key of new Set([...Object.keys(before), ...Object.keys(ours), ...Object.keys(theirs)])) {
        const result = resolveNode(before[key], ours[key], theirs[key], path ? `${path}.${key}` : key);
        if (result !== undefined) setOwn(output, key, result);
      }
      return output;
    }

    const conflictPath = path || 'value';
    const choice = choices[conflictPath];
    if (choice !== 'local' && choice !== 'server') {
      conflicts.push(conflictPath);
      return undefined;
    }
    return clone(choice === 'local' ? ours : theirs);
  };

  const result = resolveNode(base, local, server, '');
  if (conflicts.length) throw new Error(`Choose local or server for: ${conflicts.join(', ')}`);
  return result;
}
