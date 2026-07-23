<template>
  <div class="collections">
    <!--
      One screen, three views, driven by internal state rather than a route each:
      the collection types, one type's entries, and an entry editor. AdminApp
      wires a single /admin/collections route and this coordinates the rest, the
      same way the page editor lives under the Pages screen.
    -->

    <!-- Entry editor. Keyed so switching between "new" and a specific entry
         mounts a fresh editor rather than reusing one with stale field values. -->
    <CollectionEntryEdit
      v-if="selectedType && (creating || editingSlug !== null)"
      :key="editingSlug ?? 'new'"
      :type="selectedType"
      :slug="editingSlug"
      @saved="onEntrySaved"
      @cancel="closeEditor"
      @deleted="onEntryDeleted"
    />

    <!-- Entries of the selected type. -->
    <CollectionEntries
      v-else-if="selectedType"
      :type="selectedType"
      @new="openNew"
      @edit="openEdit"
      @back="closeType"
    />

    <!-- The list of collection types. -->
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
import { ref, onMounted } from 'vue';
import CollectionEntries from './collections/CollectionEntries.vue';
import CollectionEntryEdit from './collections/CollectionEntryEdit.vue';

const types = ref([]);
const loading = ref(true);
const error = ref('');

// The full type object once one is chosen — it already carries `fields`,
// `titleField` and `label` from the list response, so the entries screen and the
// editor need no second request to describe the type.
const selectedType = ref(null);
const editingSlug = ref(null);
const creating = ref(false);

const entryCountLabel = (type) => {
  const n = Number(type.entryCount ?? 0);
  return `${n} ${n === 1 ? 'entry' : 'entries'}`;
};

const loadTypes = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/collections');
    if (!res.ok) throw new Error(`Request failed (${res.status})`);
    const body = await res.json();
    types.value = Array.isArray(body.data) ? body.data : [];
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
};

// Returning to the type list refreshes the counts, which a save or delete under
// the selected type may have moved.
const closeType = () => {
  selectedType.value = null;
  loadTypes();
};

const openNew = () => { creating.value = true; editingSlug.value = null; };
const openEdit = (slug) => { editingSlug.value = slug; creating.value = false; };

// Leaving the editor drops back to the entries list, which re-fetches on mount.
const closeEditor = () => { creating.value = false; editingSlug.value = null; };
const onEntryDeleted = () => closeEditor();
// A save keeps the editor open — publishing a freshly-created entry needs it to
// stay put, now addressing the entry it just created rather than a blank form.
const onEntrySaved = () => {};

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
