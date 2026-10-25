const keyFor = (scope) => {
  if (!scope || typeof scope.account !== 'string' || !scope.account.trim()
    || typeof scope.site !== 'string' || !scope.site.trim()) return null;
  return `click-cms:section-templates:v2:${encodeURIComponent(scope.site)}:${encodeURIComponent(scope.account)}`;
};

const copy = (value) => JSON.parse(JSON.stringify(value));

const normalizeSections = (sections) => {
  if (!Array.isArray(sections)) throw new TypeError('Sections must be a list.');
  return sections.map((section) => {
    if (!section || typeof section.type !== 'string' || !section.type.trim()
      || !section.values || typeof section.values !== 'object' || Array.isArray(section.values)) {
      throw new TypeError('Each section needs a type and values object.');
    }
    return { type: section.type, values: copy(section.values) };
  });
};

export function listSectionTemplates(scope) {
  const key = keyFor(scope);
  if (!key) return [];
  try {
    const parsed = JSON.parse(globalThis.localStorage?.getItem(key) ?? '[]');
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((item) => item && typeof item.id === 'string' && typeof item.name === 'string'
      && Array.isArray(item.sections));
  } catch {
    return [];
  }
}

export function saveSectionTemplate(name, sections, scope) {
  const key = keyFor(scope);
  if (!key) throw new TypeError('A signed-in account and site are required to save a personal starter.');
  const cleanName = String(name ?? '').trim();
  if (!cleanName) throw new TypeError('A template name is required.');
  const normalized = normalizeSections(sections);
  const all = listSectionTemplates(scope);
  const existing = all.find((item) => item.name.toLocaleLowerCase() === cleanName.toLocaleLowerCase());
  const template = {
    id: existing?.id ?? `section-template-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
    name: cleanName,
    sections: normalized,
  };
  const next = existing ? all.map((item) => item.id === existing.id ? template : item) : [...all, template];
  globalThis.localStorage?.setItem(key, JSON.stringify(next));
  return copy(template);
}

export function deleteSectionTemplate(id, scope) {
  const key = keyFor(scope);
  if (!key) return false;
  const all = listSectionTemplates(scope);
  const next = all.filter((item) => item.id !== id);
  if (next.length === all.length) return false;
  globalThis.localStorage?.setItem(key, JSON.stringify(next));
  return true;
}

/** Erase an account/site's browser-only starter snapshots when that account logs out. */
export function clearSectionTemplates(scope) {
  const key = keyFor(scope);
  if (!key) return false;
  try {
    if (globalThis.localStorage?.getItem(key) === null) return false;
    globalThis.localStorage?.removeItem(key);
    return true;
  } catch {
    return false;
  }
}

export function cloneTemplateSections(template) {
  return normalizeSections(template?.sections ?? []);
}
