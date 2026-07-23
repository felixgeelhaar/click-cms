<template>
  <div class="collection-entries">
    <div class="page-header">
      <div>
        <button type="button" class="btn-back" @click="$emit('back')">← Collections</button>
        <h1 class="page-title">{{ type.label }}</h1>
        <p v-if="type.description" class="page-subtitle">{{ type.description }}</p>
      </div>
      <button type="button" class="btn-primary btn-new" @click="$emit('new')">+ New entry</button>
    </div>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>

    <div v-if="loading" class="loading">Loading…</div>

    <div v-else-if="entries.length === 0" class="empty-state">
      No entries yet. Create the first one with “New entry”.
    </div>

    <div v-else class="entry-list">
      <div v-for="entry in entries" :key="entry.slug" class="entry-row">
        <div class="entry-info">
          <!-- Interpolated, never v-html: an entry title is author-supplied data
               and is escaped like every other value on this screen. -->
          <h3 class="entry-title">{{ entryTitle(entry) }}</h3>
          <p class="entry-slug">{{ entry.slug }}</p>
          <span :class="['status-badge', stateOf(entry)]">{{ stateLabel(stateOf(entry)) }}</span>
        </div>
        <div class="entry-actions">
          <button
            type="button"
            class="btn-sm btn-secondary btn-edit"
            :aria-label="`Edit ${entryTitle(entry)}`"
            @click="$emit('edit', entry.slug)"
          >Edit</button>
          <button
            type="button"
            class="btn-sm btn-danger btn-delete"
            :aria-label="`Delete ${entryTitle(entry)}`"
            @click="deleteEntry(entry)"
          >Delete</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';

const props = defineProps({
  // The full type object from GET /api/collections — carries id, label,
  // titleField and the field schema, so no request describes the type again.
  type: { type: Object, required: true },
});

defineEmits(['new', 'edit', 'back']);

const entries = ref([]);
const loading = ref(true);
const error = ref('');

/**
 * Publication state, the same three facts the page list reports, derived from
 * the server rather than a stored status. Absence of a `publication` key reads
 * as a draft rather than a guess at something worse.
 */
const STATES = {
  live: 'Live',
  pending: 'Unpublished changes',
  draft: 'Draft',
  takendown: 'Taken down',
};

const stateOf = (entry) => {
  const p = entry?.publication;
  if (!p || typeof p.published !== 'boolean') return 'draft';
  if (p.published) return p.hasUnpublishedChanges ? 'pending' : 'live';
  return p.neverPublished ? 'draft' : 'takendown';
};

const stateLabel = (state) => STATES[state] ?? STATES.draft;

const entryTitle = (entry) =>
  entry.title || entry.data?.[props.type.titleField] || entry.slug;

const loadEntries = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch(`/api/collections/${encodeURIComponent(props.type.id)}/entries`);
    if (!res.ok) throw new Error(`Request failed (${res.status})`);
    const body = await res.json();
    entries.value = Array.isArray(body.data) ? body.data : [];
  } catch (e) {
    error.value = 'Could not load entries.';
  } finally {
    loading.value = false;
  }
};

const deleteEntry = async (entry) => {
  if (!window.confirm(`Delete “${entryTitle(entry)}”? This cannot be undone.`)) return;
  try {
    await fetch(
      `/api/collections/${encodeURIComponent(props.type.id)}/entries/${encodeURIComponent(entry.slug)}`,
      { method: 'DELETE' }
    );
    await loadEntries();
  } catch (e) {
    error.value = 'Could not delete that entry.';
  }
};

watch(() => props.type.id, loadEntries);
onMounted(loadEntries);
</script>

<style scoped>
.collection-entries { max-width: 1000px; }
.page-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-bottom: 1.5rem; }
.btn-back { background: none; border: none; padding: 0; margin-bottom: 0.5rem; color: var(--color-primary-600); cursor: pointer; font: inherit; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.35rem; }
.page-subtitle { color: var(--app-text-muted); }

.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.loading, .empty-state { text-align: center; padding: 3rem; color: var(--app-text-muted); }

.entry-list { display: flex; flex-direction: column; gap: 1rem; }
.entry-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1.25rem 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius-sm); }
.entry-title { font-size: 1.0625rem; font-weight: 600; margin-bottom: 0.25rem; }
.entry-slug { color: var(--app-text-muted); font-size: 0.8125rem; margin-bottom: 0.5rem; }
.entry-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }

.status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.02em; }
.status-badge.live { background: #dcfce7; color: #166534; }
.status-badge.pending { background: #fef3c7; color: #92400e; }
.status-badge.draft { background: #e5e9ef; color: #3f4754; }
.status-badge.takendown { background: #fee2e2; color: #991b1b; }

.btn-primary { padding: 0.625rem 1rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 6px; cursor: pointer; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--app-border); }
.btn-danger { background: var(--color-danger-500); color: white; border: none; }

[data-theme="dark"] .status-badge.live { background: rgba(34, 197, 94, 0.18); color: #86efac; }
[data-theme="dark"] .status-badge.pending { background: rgba(245, 158, 11, 0.18); color: #fcd34d; }
[data-theme="dark"] .status-badge.draft { background: rgba(148, 163, 184, 0.18); color: #cbd5e1; }
[data-theme="dark"] .status-badge.takendown { background: rgba(239, 68, 68, 0.18); color: #fca5a5; }
</style>
