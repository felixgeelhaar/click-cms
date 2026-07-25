<template>
  <div class="menus">
    <div class="page-header">
      <div>
        <h1 class="page-title">Menus</h1>
        <p class="page-subtitle">Build the navigation the site renders — a header nav, a footer nav.</p>
      </div>
    </div>

    <div class="toolbar">
      <div class="menu-picker">
        <label class="picker-label" for="menu-select">Menu</label>
        <select id="menu-select" v-model="selectedId" class="picker-select" :disabled="loading" @change="selectMenu(selectedId)">
          <option v-for="m in menus" :key="m.id" :value="m.id">{{ m.name || m.id }}</option>
        </select>
      </div>

      <form class="new-menu" @submit.prevent="createMenu">
        <label class="picker-label" for="new-menu-id">New menu</label>
        <input
          id="new-menu-id"
          v-model="newId"
          class="picker-input"
          type="text"
          placeholder="e.g. footer"
          autocomplete="off"
          aria-describedby="new-menu-hint"
        />
        <button type="submit" class="btn-secondary" :disabled="!newId.trim()">Create</button>
        <span id="new-menu-hint" class="visually-hidden">Lowercase letters, digits and hyphens.</span>
      </form>
    </div>

    <p v-if="loading" class="loading">Loading…</p>

    <form v-else class="editor" @submit.prevent="save">
      <div class="name-row">
        <label class="field-label" for="menu-name">Display name</label>
        <input id="menu-name" v-model="menuName" class="text-input" type="text" placeholder="Main navigation" />
      </div>

      <h2 class="items-title">Items</h2>

      <p v-if="items.length === 0" class="empty-state">
        This menu has no items yet — that is a valid, empty nav. Add one below.
      </p>

      <ol v-else class="item-list">
        <li v-for="(item, i) in items" :key="item._uid" class="item">
          <div class="item-head">
            <span class="item-index">{{ i + 1 }}</span>
            <div class="item-fields">
              <div class="field">
                <label class="field-label" :for="`item-${i}-label`">Label</label>
                <input :id="`item-${i}-label`" v-model="item.label" class="text-input" type="text" placeholder="About us" />
              </div>
              <div class="field">
                <label class="field-label" :for="`item-${i}-target`">Target</label>
                <LinkField
                  :input-id="`item-${i}-target`"
                  v-model="item.target"
                  format="slug"
                  :pages="pages"
                  :default-locale="defaultLocale"
                  :described-by="`item-${i}-target-hint`"
                />
                <span :id="`item-${i}-target-hint`" class="field-hint">One of this site's pages, or an external http(s) address.</span>
              </div>
            </div>

            <div class="item-controls">
              <button type="button" class="icon-btn" :disabled="i === 0" :aria-label="`Move item ${i + 1} up`" @click="moveItem(i, -1)">↑</button>
              <button type="button" class="icon-btn" :disabled="i === items.length - 1" :aria-label="`Move item ${i + 1} down`" @click="moveItem(i, 1)">↓</button>
              <button type="button" class="icon-btn" :aria-label="`Add a sub-item under item ${i + 1}`" @click="addChild(i)">＋</button>
              <button type="button" class="icon-btn danger" :aria-label="`Remove item ${i + 1}`" @click="removeItem(i)">✕</button>
            </div>
          </div>

          <ol v-if="item.children.length" class="child-list" :aria-label="`Sub-items of item ${i + 1}`">
            <li v-for="(child, j) in item.children" :key="child._uid" class="child">
              <div class="item-fields">
                <div class="field">
                  <label class="field-label" :for="`item-${i}-${j}-label`">Sub-item label</label>
                  <input :id="`item-${i}-${j}-label`" v-model="child.label" class="text-input" type="text" />
                </div>
                <div class="field">
                  <label class="field-label" :for="`item-${i}-${j}-target`">Sub-item target</label>
                  <LinkField
                    :input-id="`item-${i}-${j}-target`"
                    v-model="child.target"
                    format="slug"
                    :pages="pages"
                    :default-locale="defaultLocale"
                  />
                </div>
              </div>
              <div class="item-controls">
                <button type="button" class="icon-btn" :disabled="j === 0" :aria-label="`Move sub-item ${j + 1} of item ${i + 1} up`" @click="moveChild(i, j, -1)">↑</button>
                <button type="button" class="icon-btn" :disabled="j === item.children.length - 1" :aria-label="`Move sub-item ${j + 1} of item ${i + 1} down`" @click="moveChild(i, j, 1)">↓</button>
                <button type="button" class="icon-btn danger" :aria-label="`Remove sub-item ${j + 1} of item ${i + 1}`" @click="removeChild(i, j)">✕</button>
              </div>
            </li>
          </ol>
        </li>
      </ol>

      <div class="actions">
        <button type="button" class="btn-secondary" @click="addItem">+ Add item</button>
        <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save menu' }}</button>
      </div>

      <p v-if="error" class="banner error" role="alert">{{ error }}</p>
      <p v-else-if="savedNotice" class="banner ok" role="status">{{ savedNotice }}</p>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import LinkField from './fields/LinkField.vue';

// UI-only key so v-for keys stay stable across reorder without keying on the
// index (which would move focus to the wrong row). Stripped before saving.
let uid = 0;
const withUid = (obj) => ({ _uid: ++uid, ...obj });

const menus = ref([]);
const selectedId = ref('');
const menuName = ref('');
const items = ref([]);
// Fetched once for the whole screen and handed to every target picker. Twenty
// menu items must not be twenty requests for the same list.
const pages = ref([]);
const defaultLocale = ref('');
const newId = ref('');

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const savedNotice = ref('');

const hydrateItems = (raw) =>
  (raw ?? []).map((item) =>
    withUid({
      label: item.label ?? '',
      target: item.target ?? '',
      children: (item.children ?? []).map((c) => withUid({ label: c.label ?? '', target: c.target ?? '' })),
    })
  );

const loadMenus = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/menus');
    const body = await res.json();
    menus.value = Array.isArray(body.data) ? body.data : [];

    // A site with no menus yet still needs somewhere to start, so offer a
    // "main" menu that exists only in the editor until it is first saved.
    if (menus.value.length === 0) {
      menus.value = [{ id: 'main', name: 'Main navigation' }];
    }

    selectedId.value = menus.value[0].id;
    // Fetch the full document rather than trusting the list row to carry its
    // items — the same path a menu switch takes.
    await selectMenu(selectedId.value);
  } catch (e) {
    error.value = 'Could not load menus.';
  }
};

const applyMenu = (menu) => {
  menuName.value = menu.name ?? menu.id ?? '';
  items.value = hydrateItems(menu.items);
};

const selectMenu = async (id) => {
  error.value = '';
  savedNotice.value = '';
  try {
    const res = await fetch(`/api/menus/${encodeURIComponent(id)}`);
    if (res.ok) {
      applyMenu((await res.json()).data);
      return;
    }
    // Not yet saved (an in-editor menu): start it empty rather than erroring.
    applyMenu(menus.value.find((m) => m.id === id) ?? { id, items: [] });
  } catch {
    applyMenu({ id, items: [] });
  }
};

const createMenu = () => {
  const id = newId.value.trim();
  if (!id) return;
  if (!menus.value.some((m) => m.id === id)) {
    menus.value.push({ id, name: id });
  }
  selectedId.value = id;
  newId.value = '';
  menuName.value = id;
  items.value = [];
  savedNotice.value = '';
  error.value = '';
};

const loadPages = async () => {
  try {
    const res = await fetch('/api/pages');
    const body = await res.json();
    pages.value = Array.isArray(body.data) ? body.data : [];
    defaultLocale.value = typeof body.locale === 'string' ? body.locale : '';
  } catch {
    pages.value = [];
  }
};

const addItem = () => items.value.push(withUid({ label: '', target: '', children: [] }));
const removeItem = (i) => items.value.splice(i, 1);
const addChild = (i) => items.value[i].children.push(withUid({ label: '', target: '' }));
const removeChild = (i, j) => items.value[i].children.splice(j, 1);

const swap = (list, index, delta) => {
  const target = index + delta;
  if (target < 0 || target >= list.length) return;
  const [moved] = list.splice(index, 1);
  list.splice(target, 0, moved);
};
const moveItem = (i, delta) => swap(items.value, i, delta);
const moveChild = (i, j, delta) => swap(items.value[i].children, j, delta);

// Strip UI keys and blank rows, so a half-typed row is not saved as an item
// with no label the server would reject.
const serialise = () =>
  items.value
    .filter((item) => item.label.trim() !== '' || item.target.trim() !== '')
    .map((item) => {
      const out = { label: item.label.trim(), target: item.target.trim() };
      const children = item.children
        .filter((c) => c.label.trim() !== '' || c.target.trim() !== '')
        .map((c) => ({ label: c.label.trim(), target: c.target.trim() }));
      if (children.length) out.children = children;
      return out;
    });

const save = async () => {
  saving.value = true;
  error.value = '';
  savedNotice.value = '';
  try {
    const res = await fetch(`/api/menus/${encodeURIComponent(selectedId.value)}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: menuName.value.trim() || selectedId.value, items: serialise() }),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      // The server's message names the offending target — a rejected
      // javascript: link, an unknown scheme — so it is surfaced rather than
      // hidden behind a generic failure.
      error.value = body.error || `Save failed (${res.status}).`;
      return;
    }

    savedNotice.value = 'Menu saved.';
    const existing = menus.value.find((m) => m.id === selectedId.value);
    if (existing) existing.name = menuName.value.trim() || selectedId.value;
  } catch {
    error.value = 'Could not save the menu.';
  } finally {
    saving.value = false;
  }
};

onMounted(async () => {
  // Both, before the item list is drawn. A target picker handed an empty page
  // list would report every existing target as a page that no longer exists —
  // an alarm raised by a request that had simply not come back yet.
  await Promise.all([loadMenus(), loadPages()]);
  loading.value = false;
});
</script>

<style scoped>
.menus { max-width: 900px; }
.page-header { margin-bottom: 1.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); }

.toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1.5rem; margin-bottom: 1.5rem; }
.menu-picker, .new-menu { display: flex; align-items: flex-end; gap: 0.5rem; }
.new-menu { flex-wrap: wrap; }
.picker-label, .field-label { display: block; color: var(--app-text-muted); font-size: 0.875rem; font-weight: 500; margin-bottom: 0.35rem; }
.picker-select, .picker-input, .text-input { padding: 0.5rem 0.75rem; border-radius: 8px; border: 1px solid var(--control-border); background: var(--app-surface); color: var(--app-text); font: inherit; }
.picker-input { min-width: 10rem; }

.loading, .empty-state { text-align: center; padding: 2rem; color: var(--app-text-muted); }

.name-row { margin-bottom: 1.5rem; }
.name-row .text-input { width: 100%; max-width: 24rem; }
.items-title { font-size: 1.125rem; font-weight: 600; margin: 0 0 0.75rem; }

.item-list, .child-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem; }
.item { border: 1px solid var(--app-border); border-radius: 10px; background: var(--card-bg); padding: 1rem; }
.item-head { display: flex; align-items: flex-start; gap: 0.75rem; }
.item-index { flex-shrink: 0; width: 26px; height: 26px; display: grid; place-items: center; border-radius: 6px; background: var(--app-surface-strong); font-size: 0.75rem; font-weight: 600; margin-top: 1.75rem; }
.item-fields { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.field { display: flex; flex-direction: column; }
.field .text-input { width: 100%; }
.field-hint { margin-top: 0.25rem; font-size: 0.75rem; color: var(--app-text-muted); }
.item-controls { display: flex; gap: 0.25rem; margin-top: 1.75rem; flex-shrink: 0; }
.icon-btn { width: 28px; height: 28px; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; line-height: 1; color: var(--app-text); }
.icon-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.icon-btn.danger { color: var(--color-danger-600, #dc2626); }

.child-list { margin: 1rem 0 0 2rem; gap: 0.75rem; }
.child { display: flex; align-items: flex-start; gap: 0.75rem; border-left: 2px solid var(--app-border); padding-left: 0.75rem; }
.child .item-fields { margin: 0; }
.child .item-controls { margin-top: 1.75rem; }

.actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }
.btn-primary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { padding: 0.5rem 1rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }

.banner { margin-top: 1rem; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; }
.banner.error { background: rgba(239, 68, 68, 0.12); color: var(--color-danger-600, #dc2626); }
.banner.ok { background: rgba(34, 197, 94, 0.12); color: #166534; }

.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

@media (max-width: 640px) {
  .item-fields { grid-template-columns: 1fr; }
}
[data-theme="dark"] .banner.ok { color: #86efac; }

/*
 * Focus. Every control here is reachable by keyboard and, until this rule, none
 * of them said so: the browser default is easy to lose against these surfaces
 * and several controls sit on tinted backgrounds where it disappears entirely.
 * One ring, stated once, on whatever the keyboard is actually on.
 */
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
summary:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
