<template>
  <div class="builder">
    <header class="builder-head">
      <div>
        <h1 class="page-title">Visual Builder</h1>
        <p class="page-subtitle">Compose a page from sections, grids and content blocks.</p>
      </div>
      <div v-if="builder" class="builder-actions">
        <button class="btn-secondary" :disabled="saving" @click="reset">Discard changes</button>
        <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
      </div>
    </header>

    <p v-if="loadError" class="banner error" role="alert">{{ loadError }}</p>
    <p v-if="saveError" class="banner error" role="alert">{{ saveError }}</p>
    <p v-if="notice" class="banner notice" role="status">{{ notice }}</p>

    <!--
      /admin/builder is reached with no page in hand, so the first thing to do is
      choose which page's layout to build. Loading a page reads its `builder`, or
      starts a fresh one when it has none.
    -->
    <div class="page-picker">
      <label for="builder-page">Page</label>
      <select id="builder-page" :value="activeSlug" :disabled="loading || saving" @change="onPick($event.target.value)">
        <option value="">Select a page…</option>
        <option v-for="page in pageList" :key="page.slug" :value="page.slug">{{ page.title }}</option>
      </select>
      <span v-if="loading" class="picker-status">Loading…</span>
    </div>

    <div v-if="builder" class="builder-workspace">
      <aside class="workspace-side left">
        <BuilderPalette />
      </aside>

      <main class="workspace-canvas">
        <BuilderNode :node="builder.nodes[builder.root]" :root-id="builder.root" />
      </main>

      <aside class="workspace-side right">
        <BuilderInspector />
      </aside>
    </div>

    <div v-else-if="!loading" class="builder-empty">
      <p>Select a page above to start building.</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, provide, ref } from 'vue';
import BuilderNode from './builder/BuilderNode.vue';
import BuilderPalette from './builder/BuilderPalette.vue';
import BuilderInspector from './builder/BuilderInspector.vue';
import {
  normalizeBuilder,
  addNode as addNodeOp,
  removeNode as removeNodeOp,
  moveNode as moveNodeOp,
  updateProp as updatePropOp,
  updateStyle as updateStyleOp,
  setColumnCount as setColumnCountOp,
} from './builder/model.js';

// Optional: a test (or a future deep link) can mount straight onto a page. The
// /admin/builder route passes nothing, so the page picker is the normal path.
const props = defineProps({ slug: { type: String, default: '' } });

const builder = ref(null);
const selectedId = ref(null);
const dragId = ref('');

const pageList = ref([]);
const activeSlug = ref('');
const locale = ref('');
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const saveError = ref('');
const notice = ref('');

const nodes = computed(() => builder.value?.nodes ?? {});
// The inspector offers these as the breakpoint a columns node un-stacks at, so
// the choice is limited to breakpoints this document actually declares.
const breakpoints = computed(() => (builder.value?.breakpoints ?? []).filter((bp) => bp.id !== 'base'));

/* ---------------------------------------------------- controller -- */

// The one object every child injects. Wrapping the pure model ops keeps all
// tree mutations in a single place — the components never touch `builder`
// directly — and lets selection follow the edit (a removed node clears the
// selection; a new node becomes selected).
const ctx = {
  selectedId,
  dragId,
  nodes,
  breakpoints,
  select(id) {
    selectedId.value = id;
  },
  addNode(type) {
    const id = addNodeOp(builder.value, type, selectedId.value);
    selectedId.value = id;
    touch();
    return id;
  },
  removeNode(id) {
    if (removeNodeOp(builder.value, id) && selectedId.value === id) {
      selectedId.value = null;
    }
    touch();
  },
  moveNode(drag, ref_, position) {
    moveNodeOp(builder.value, drag, ref_, position);
    touch();
  },
  updateProp(id, key, value) {
    updatePropOp(builder.value, id, key, value);
    touch();
  },
  updateStyle(id, key, value) {
    updateStyleOp(builder.value, id, key, value);
    touch();
  },
  setColumnCount(id, count) {
    setColumnCountOp(builder.value, id, count);
    // Shrinking deletes columns, which can take the selection with them.
    if (selectedId.value && !builder.value.nodes[selectedId.value]) {
      selectedId.value = id;
    }
    touch();
  },
};
provide('builderCtx', ctx);

// Any structural or content edit invalidates the "Saved" confirmation, so it
// can never sit above a canvas full of unsaved work.
function touch() {
  if (notice.value) notice.value = '';
}

/* ---------------------------------------------------------- load -- */

const slugOf = (page) => page.slug ?? String(page.key ?? '').split(':').pop();

async function loadPageList() {
  try {
    const res = await fetch('/api/pages');
    const body = await res.json();
    locale.value = body.locale || '';
    pageList.value = (Array.isArray(body.data) ? body.data : []).map((page) => ({
      slug: slugOf(page),
      title: page.data?.title || slugOf(page),
    }));
  } catch (e) {
    loadError.value = `Could not list pages: ${e.message}`;
  }
}

async function loadPage(slug) {
  if (!slug) return;
  loading.value = true;
  loadError.value = '';
  saveError.value = '';
  notice.value = '';
  selectedId.value = null;

  try {
    const query = locale.value ? `?locale=${encodeURIComponent(locale.value)}` : '';
    const res = await fetch(`/api/pages/${slug}${query}`);
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      loadError.value = body.error || `Could not load the page (${res.status}).`;
      return;
    }

    // The page payload is nested { data: { data: { …page fields } } }, matching
    // how PageEdit reads it; the layout lives under that object's `builder` key.
    const data = body.data?.data ?? body.data ?? {};
    if (body.locale) locale.value = body.locale;
    builder.value = normalizeBuilder(data.builder);
    activeSlug.value = slug;
    // Select the root so the first Add has an obvious, named destination.
    selectedId.value = builder.value.root;
  } catch (e) {
    loadError.value = `Could not load the page: ${e.message}`;
  } finally {
    loading.value = false;
  }
}

function onPick(slug) {
  if (!slug) {
    builder.value = null;
    activeSlug.value = '';
    return;
  }
  loadPage(slug);
}

function reset() {
  if (activeSlug.value) loadPage(activeSlug.value);
}

/* ---------------------------------------------------------- save -- */

/**
 * Write the layout back onto the page.
 *
 * The save is deliberately just `{ builder }`: the page endpoint's update
 * merges field-by-field (Content::update in PageService), so sending only the
 * builder preserves the page's title, sections and SEO instead of blanking
 * every field this editor does not touch. Same PUT /api/pages/:slug contract
 * PageEdit uses, so it rides the existing CSRF-wrapped fetch and validation.
 */
async function save() {
  if (!builder.value || !activeSlug.value) return;
  saving.value = true;
  saveError.value = '';
  notice.value = '';

  try {
    const query = locale.value ? `?locale=${encodeURIComponent(locale.value)}` : '';
    const res = await fetch(`/api/pages/${activeSlug.value}${query}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ builder: builder.value }),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      saveError.value = body.error || `Could not save (${res.status}).`;
      return;
    }
    notice.value = 'Layout saved. Publish the page to put it live.';
  } catch (e) {
    saveError.value = `Could not save: ${e.message}`;
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  await loadPageList();
  if (props.slug) await loadPage(props.slug);
});

// Surfaced for tests and for any future host that wants to drive the editor.
defineExpose({ builder, selectedId, ctx, loadPage, save });
</script>

<style scoped>
.builder { max-width: 1400px; }
.builder-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.25rem; }
.page-subtitle { color: var(--app-text-muted); }
.builder-actions { display: flex; gap: 0.75rem; flex-shrink: 0; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.notice { border: 1px solid var(--app-border); }
.page-picker { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; }
.page-picker label { font-weight: 500; }
.page-picker select { padding: 0.5rem 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; min-width: 16rem; }
.picker-status { font-size: 0.8125rem; color: var(--app-text-muted); }
.builder-workspace { display: grid; grid-template-columns: 220px 1fr 300px; gap: 1rem; align-items: start; }
.workspace-side { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); position: sticky; top: 80px; }
.workspace-canvas { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: 1.5rem; min-height: 60vh; }
.builder-empty { padding: 4rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); text-align: center; color: var(--app-text-muted); }
.btn-primary, .btn-secondary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--app-border); }
.btn-primary:disabled, .btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 1024px) {
  .builder-workspace { grid-template-columns: 1fr; }
  .workspace-side { position: static; }
}
</style>
