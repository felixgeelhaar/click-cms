<template>
  <div class="collections">
    <!--
      Three views under deep-linkable routes:
      /admin/collections
      /admin/collections/{type}
      /admin/collections/{type}/entries/{slug|new}
      AdminApp parses the path into props; this screen emits navigate so the
      address bar stays in sync when the editor moves between views.
    -->

    <CollectionEntryEdit
      v-if="selectedType && (creating || editingSlug !== null)"
      :key="editingSlug ?? 'new'"
      :type="selectedType"
      :slug="editingSlug"
      :initial-locale="initialLocale"
      @saved="onEntrySaved"
      @cancel="closeEditor"
      @deleted="onEntryDeleted"
    />

    <CollectionEntries
      v-else-if="selectedType"
      :type="selectedType"
      @new="openNew"
      @edit="openEdit"
      @back="closeType"
    />

    <template v-else>
      <div class="page-header">
        <div>
          <h1 class="page-title">Collections</h1>
          <p class="page-subtitle">Repeatable content types</p>
        </div>
      </div>

      <p v-if="error" class="banner error" role="alert">{{ error }}</p>

      <div v-if="loading" class="loading">Loading…</div>

      <div v-else-if="types.length === 0" class="empty-state">
        No collection types have been declared yet. A developer defines these
        server-side; once one exists it appears here.
      </div>

      <div v-else class="type-list">
        <button
          v-for="type in types"
          :key="type.id"
          type="button"
          class="collection-card"
          @click="openType(type)"
        >
          <span class="type-label">{{ type.label }}</span>
          <span v-if="type.description" class="type-description">{{ type.description }}</span>
          <span class="type-count">{{ entryCountLabel(type) }}</span>
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import CollectionEntries from './collections/CollectionEntries.vue';
import CollectionEntryEdit from './collections/CollectionEntryEdit.vue';

const props = defineProps({
  /** Collection type id from `/admin/collections/{type}/…`, or empty for the type list. */
  initialTypeId: { type: String, default: '' },
  /** Entry slug from `/…/entries/{slug}`, or null when listing / creating. */
  initialSlug: { type: String, default: null },
  /** True for `/…/entries/new`. */
  initialCreating: { type: Boolean, default: false },
  /** Optional `?locale=` so a translation opens as that language. */
  initialLocale: { type: String, default: '' },
});

const emit = defineEmits(['navigate']);

const types = ref([]);
const loading = ref(true);
const error = ref('');

const selectedType = ref(null);
const editingSlug = ref(null);
const creating = ref(false);

const entryCountLabel = (type) => {
  const n = Number(type.entryCount ?? 0);
  return `${n} ${n === 1 ? 'entry' : 'entries'}`;
};

const typeHref = (typeId) => `/admin/collections/${encodeURIComponent(typeId)}`;
const entryHref = (typeId, slug) => `${typeHref(typeId)}/entries/${encodeURIComponent(slug)}`;
const newHref = (typeId) => `${typeHref(typeId)}/entries/new`;

const applyRoute = () => {
  const typeId = props.initialTypeId || '';
  if (!typeId) {
    selectedType.value = null;
    editingSlug.value = null;
    creating.value = false;
    return;
  }

  const type = types.value.find((t) => t.id === typeId) || null;
  selectedType.value = type;
  if (!type) {
    editingSlug.value = null;
    creating.value = false;
    return;
  }

  if (props.initialCreating) {
    creating.value = true;
    editingSlug.value = null;
    return;
  }

  creating.value = false;
  editingSlug.value = props.initialSlug || null;
};

const loadTypes = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/collections');
    if (!res.ok) throw new Error(`Request failed (${res.status})`);
    const body = await res.json();
    types.value = Array.isArray(body.data) ? body.data : [];
    applyRoute();
    if (props.initialTypeId && !selectedType.value) {
      error.value = 'That collection type was not found.';
    }
  } catch (e) {
    error.value = 'Could not load collections.';
  } finally {
    loading.value = false;
  }
};

const openType = (type) => {
  selectedType.value = type;
  creating.value = false;
  editingSlug.value = null;
  emit('navigate', typeHref(type.id));
};

const closeType = () => {
  selectedType.value = null;
  creating.value = false;
  editingSlug.value = null;
  emit('navigate', '/admin/collections');
  loadTypes();
};

const openNew = () => {
  if (!selectedType.value) return;
  creating.value = true;
  editingSlug.value = null;
  emit('navigate', newHref(selectedType.value.id));
};

const openEdit = (slug) => {
  if (!selectedType.value) return;
  creating.value = false;
  editingSlug.value = slug;
  emit('navigate', entryHref(selectedType.value.id, slug));
};

const closeEditor = () => {
  creating.value = false;
  editingSlug.value = null;
  if (!selectedType.value) {
    emit('navigate', '/admin/collections');
    return;
  }
  emit('navigate', typeHref(selectedType.value.id));
};

const onEntryDeleted = () => closeEditor();

// A save keeps the editor open — publishing a freshly-created entry needs it to
// stay put. Once create returns a slug, point the address at that entry so a
// refresh does not reopen a blank form.
const onEntrySaved = (slug) => {
  const next = typeof slug === 'string' && slug !== '' ? slug : editingSlug.value;
  if (!selectedType.value || !next) return;
  creating.value = false;
  editingSlug.value = next;
  emit('navigate', entryHref(selectedType.value.id, next));
};

watch(
  () => [props.initialTypeId, props.initialSlug, props.initialCreating],
  () => {
    if (types.value.length > 0 || !loading.value) applyRoute();
  },
);

onMounted(loadTypes);
</script>

<style scoped>
.collections { max-width: 1200px; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); }

.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.loading, .empty-state { text-align: center; padding: 3rem; color: var(--app-text-muted); }

.type-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; }
.collection-card {
  display: flex; flex-direction: column; align-items: flex-start; gap: 0.4rem;
  padding: 1.5rem; text-align: left; cursor: pointer;
  background: var(--card-bg); border: 1px solid var(--card-border);
  border-radius: var(--card-radius-sm); color: var(--app-text); font: inherit;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.collection-card:hover { border-color: var(--color-primary-600); }
.collection-card:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: 2px; }
.type-label { font-size: 1.125rem; font-weight: 600; }
.type-description { font-size: 0.875rem; color: var(--app-text-muted); }
.type-count { margin-top: 0.35rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: var(--app-text-muted); }
</style>
