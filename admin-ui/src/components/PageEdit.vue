<template>
  <div class="page-edit">
    <h1 class="page-title">{{ isNew ? 'New Page' : 'Edit Page' }}</h1>

    <p v-if="loadError" class="banner error" role="alert">{{ loadError }}</p>
    <p v-if="saveError" class="banner error" role="alert">{{ saveError }}</p>

    <div v-if="loading" class="banner">Loading…</div>

    <div v-else class="edit-form">
      <div class="form-group">
        <label for="page-title">Title</label>
        <input id="page-title" v-model="page.title" type="text" placeholder="Page title" />
      </div>

      <div class="form-group">
        <label for="page-slug">Slug</label>
        <input
          id="page-slug"
          v-model="page.slug"
          type="text"
          placeholder="page-slug"
          :disabled="!isNew"
        />
        <p v-if="!isNew" class="field-help">
          The slug is part of the page's address and cannot be changed here.
        </p>
      </div>

      <div class="form-group">
        <label for="page-status">Status</label>
        <select id="page-status" v-model="page.status">
          <option value="draft">Draft</option>
          <option value="published">Published</option>
        </select>
      </div>

      <SectionEditor v-model="page.sections" :errors="sectionErrors" />

      <!--
        The link is shown rather than opened for the editor automatically. It is
        meant to be sent to somebody — a client, a proofreader — who has no
        account, so having it selectable is the point, and a popup blocker
        cannot swallow it.
      -->
      <div v-if="previewUrl" class="preview-link">
        <p class="preview-link-title">Preview link ready</p>
        <p class="field-help">
          Anyone with this link can see the page as it stands now, until
          {{ previewExpiry }}. It stops working after that.
        </p>
        <div class="preview-link-row">
          <input :value="previewAbsoluteUrl" readonly @focus="$event.target.select()" />
          <button class="btn-secondary" @click="copyPreviewLink">{{ copied ? 'Copied' : 'Copy' }}</button>
          <a class="btn-secondary" :href="previewUrl" target="_blank" rel="noopener">Open</a>
        </div>
      </div>

      <p v-if="previewError" class="banner error" role="alert">{{ previewError }}</p>

      <div class="actions">
        <button class="btn-secondary" :disabled="saving" @click="cancel">Cancel</button>
        <button class="btn-secondary" :disabled="saving" @click="previewPage">
          {{ previewing ? 'Preparing…' : 'Preview' }}
        </button>
        <button class="btn-primary" :disabled="saving" @click="savePage">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import SectionEditor from './SectionEditor.vue';

const props = defineProps({ slug: String });
const emit = defineEmits(['saved', 'cancel']);

// The address this page lives at once it exists. Tracked separately from the
// prop because previewing a new page has to save it first, and every write
// after that is an update rather than another create.
const storedSlug = ref(props.slug || '');
const isNew = computed(() => !storedSlug.value);

const page = ref({ title: '', slug: '', status: 'draft', sections: [] });
const loading = ref(false);
const saving = ref(false);
const previewing = ref(false);
const loadError = ref('');
const saveError = ref('');
const previewError = ref('');
const previewUrl = ref('');
const previewExpiry = ref('');
const copied = ref(false);
const sectionErrors = ref({});

const previewAbsoluteUrl = computed(() =>
  previewUrl.value ? new URL(previewUrl.value, window.location.origin).toString() : ''
);

/**
 * Write the page and report the address it ended up at.
 *
 * Separated from the Save button because previewing has to save first: a
 * preview of the last saved version, shown while the editor has unsaved
 * changes on screen, is exactly the kind of quiet wrongness that makes a
 * preview untrustworthy.
 */
const persist = async () => {
  saveError.value = '';
  sectionErrors.value = {};

  const res = await fetch(isNew.value ? '/api/pages' : `/api/pages/${storedSlug.value}`, {
    method: isNew.value ? 'POST' : 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      title: page.value.title,
      slug: page.value.slug,
      status: page.value.status,
      sections: page.value.sections,
    }),
  });

  const body = await res.json().catch(() => ({}));

  if (!res.ok) {
    // Field-level errors come back keyed "<sectionIndex>.<fieldName>" so the
    // editor can put each message against the input that caused it.
    sectionErrors.value = body.errors ?? {};
    saveError.value = body.error
      || (Object.keys(sectionErrors.value).length
        ? 'Some sections need attention before this page can be saved.'
        : `Could not save (${res.status}).`);
    return null;
  }

  const slug = body.data?.slug ?? storedSlug.value;
  storedSlug.value = slug;

  return slug;
};

const savePage = async () => {
  saving.value = true;

  try {
    if (await persist()) emit('saved');
  } catch (e) {
    saveError.value = `Could not save: ${e.message}`;
  } finally {
    saving.value = false;
  }
};

const previewPage = async () => {
  saving.value = true;
  previewing.value = true;
  previewError.value = '';
  previewUrl.value = '';
  copied.value = false;

  try {
    const slug = await persist();
    if (!slug) return;

    const res = await fetch(`/api/pages/${slug}/preview`, { method: 'POST' });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      previewError.value = body.error || `Could not prepare a preview (${res.status}).`;
      return;
    }

    previewUrl.value = body.data.url;
    previewExpiry.value = new Date(body.data.expiresAt * 1000).toLocaleString();
  } catch (e) {
    previewError.value = `Could not prepare a preview: ${e.message}`;
  } finally {
    saving.value = false;
    previewing.value = false;
  }
};

const copyPreviewLink = async () => {
  try {
    await navigator.clipboard.writeText(previewAbsoluteUrl.value);
    copied.value = true;
  } catch {
    // Clipboard access is not always granted. The field is selectable, so
    // there is still a way to get the link out; say nothing rather than raise
    // an error for something the editor can simply do by hand.
  }
};

const cancel = () => emit('cancel');

onMounted(async () => {
  if (!props.slug) return;

  loading.value = true;
  try {
    const res = await fetch(`/api/pages/${props.slug}`);
    if (!res.ok) throw new Error(`Request failed (${res.status})`);

    const body = await res.json();
    const data = body.data?.data ?? body.data ?? {};

    page.value = {
      title: data.title ?? '',
      slug: props.slug,
      status: data.status ?? 'draft',
      sections: Array.isArray(data.sections) ? data.sections : [],
    };
  } catch (e) {
    loadError.value = `Could not load this page: ${e.message}`;
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.page-edit { max-width: 860px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 2rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.edit-form { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: 2rem; }
.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
.form-group input, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.form-group input:disabled { opacity: 0.7; cursor: not-allowed; }
.field-help { margin: 0.35rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; }
.preview-link { margin-top: 1.5rem; padding: 1rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface-strong); }
.preview-link-title { margin: 0 0 0.25rem; font-weight: 600; }
.preview-link-row { display: flex; gap: 0.5rem; margin-top: 0.75rem; align-items: stretch; }
.preview-link-row input { flex: 1; min-width: 0; padding: 0.5rem 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.preview-link-row .btn-secondary { display: inline-flex; align-items: center; text-decoration: none; white-space: nowrap; }
.btn-primary, .btn-secondary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--app-border); }
.btn-primary:disabled, .btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
