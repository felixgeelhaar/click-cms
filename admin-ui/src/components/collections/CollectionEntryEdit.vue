<template>
  <div class="entry-edit">
    <h1 class="page-title">{{ isNew ? `New ${type.label} entry` : `Edit ${type.label} entry` }}</h1>

    <p v-if="loadError" class="banner error" role="alert">{{ loadError }}</p>
    <p v-if="saveError" class="banner error" role="alert">{{ saveError }}</p>
    <p v-if="publishError" class="banner error" role="alert">{{ publishError }}</p>
    <p v-if="notice" class="banner notice" role="status">{{ notice }}</p>

    <div v-if="loading" class="banner">Loading…</div>

    <div v-else class="edit-form">
      <!--
        Languages, shown only when the entry exists and the site has more than
        one. Same panel and same rules as the page editor: publication is per
        language, so this is where an editor sees which translations are live,
        stale or missing. Switching creates the translation on first save.
      -->
      <PageLanguages
        v-if="!isNew && siteLocales.length > 1"
        :locales="siteLocales"
        :current="locale"
        :translations="translations"
        :busy="saving || !!busy"
        @select="switchLocale"
      />

      <p v-if="translationMissing" class="banner notice" role="status">
        This entry is not translated into {{ languageName(locale) }} yet. The
        fields are empty — saving here creates that translation.
      </p>

      <!-- Publication only means something once the entry exists on disk. -->
      <div v-if="!isNew && !translationMissing" class="publication-bar">
        <span :class="['status-badge', state]">{{ stateLabel(state) }}</span>
        <div class="pub-actions">
          <button
            v-if="canPublish"
            type="button"
            class="btn-secondary btn-publish"
            :disabled="!!busy"
            @click="publicationAction('publish')"
          >{{ busy === 'publish' ? 'Publishing…' : (state === 'pending' ? 'Publish changes' : 'Publish') }}</button>
          <button
            v-if="canUnpublish"
            type="button"
            class="btn-secondary btn-unpublish"
            :disabled="!!busy"
            @click="publicationAction('unpublish')"
          >{{ busy === 'unpublish' ? 'Unpublishing…' : 'Unpublish' }}</button>
        </div>
      </div>

      <!-- Slug is chosen only when creating; afterwards it is the entry's
           address and is not editable here, mirroring the page editor. -->
      <div v-if="isNew" class="form-group">
        <label for="entry-slug">Slug</label>
        <input
          id="entry-slug"
          v-model="slugInput"
          type="text"
          placeholder="Optional — derived from the title if left blank"
        />
      </div>

      <!--
        The entry form is the collection type's field schema rendered with the
        very same components the section editor uses, so text, rich text, images,
        repeaters and the rest behave identically to everywhere else in the admin.
      -->
      <component
        :is="fieldComponent(field)"
        v-for="field in type.fields"
        :key="field.name"
        :field="field"
        :model-value="values[field.name]"
        :error="fieldErrors[field.name]"
        @update:model-value="setValue(field.name, $event)"
      />

      <div class="actions">
        <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('cancel')">Cancel</button>
        <button
          v-if="!isNew && !translationMissing"
          type="button"
          class="btn-danger"
          :disabled="saving"
          @click="remove"
        >Delete</button>
        <button type="button" class="btn-primary" :disabled="saving" @click="save">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>

      <!--
        Version history, reusing the page editor's panel unchanged: it is a
        collection entry's now, keyed on this entry and this language. Restoring
        rewinds the working copy, not the live entry — the panel says so itself.
      -->
      <PageVersions
        v-if="!isNew && !translationMissing"
        :versions="versions"
        :loading="versionsLoading"
        :error="versionsError"
        :can-restore="can('content.restore')"
        :restoring="restoringId"
        @restore="restoreVersion"
        @reload="loadVersions"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import FieldInput from '../fields/FieldInput.vue';
import ImageField from '../fields/ImageField.vue';
import RepeaterField from '../fields/RepeaterField.vue';
import PageLanguages from '../PageLanguages.vue';
import PageVersions from '../PageVersions.vue';

const props = defineProps({
  // The full type object, including its `fields` schema.
  type: { type: Object, required: true },
  // The entry being edited, or null/absent when creating a new one.
  slug: { type: String, default: null },
});

const emit = defineEmits(['saved', 'cancel', 'deleted']);

// The address the entry lives at once it exists. Tracked apart from the prop so
// a save turns a new entry into an existing one — later saves PUT, and Publish
// becomes reachable — without leaving this screen.
const storedSlug = ref(props.slug || '');
const isNew = computed(() => !storedSlug.value);
const slugInput = ref('');

const values = ref({});
const publication = ref(null);
const fieldErrors = ref({});

const loading = ref(false);
const saving = ref(false);
const busy = ref('');
const loadError = ref('');
const saveError = ref('');
const publishError = ref('');
const notice = ref('');

/* ------------------------------------------------ capabilities & langs -- */

// Asked of the server, so the panel draws only what this account may do — a
// Restore button an author would get a 403 from teaches them the product is
// broken. An empty set fails closed.
const capabilities = ref([]);
const can = (capability) => capabilities.value.includes(capability);

const siteLocales = ref([]);
// CoreConfig lists the default language first, so no second request is needed.
const defaultLocale = computed(() => siteLocales.value[0] ?? '');
const locale = ref('');
const translations = ref({});
// True when the entry exists in some language but not the one being edited, so
// the form shows empty and a save creates the translation rather than a 404.
const translationMissing = ref(false);

const displayNames = (() => {
  try {
    return new Intl.DisplayNames(undefined, { type: 'language' });
  } catch {
    return null;
  }
})();

const languageName = (code) => {
  if (!code) return 'the default language';
  try {
    return displayNames?.of(code) || code;
  } catch {
    return code;
  }
};

// Appended to entry endpoints only for a non-default language, matching the
// page API where `/home` is the default and `/de/home` is German.
const localeQuery = () =>
  locale.value && locale.value !== defaultLocale.value
    ? `?locale=${encodeURIComponent(locale.value)}`
    : '';

// The version and translation endpoints key on the exact language, so they are
// always given one.
const versionLocale = () => locale.value || defaultLocale.value;

/* ------------------------------------------------------------ fields -- */

// The same mapping the section editor uses: repeaters and images have dedicated
// components, everything else is the shared FieldInput.
const fieldComponent = (field) => {
  if (field.type === 'repeater') return RepeaterField;
  if (field.type === 'image') return ImageField;
  return FieldInput;
};

// A blank entry seeded from the schema, so declared defaults appear and repeater
// fields start as arrays rather than undefined.
const blankValues = () => {
  const out = {};
  for (const field of props.type.fields || []) {
    if (field.default !== undefined) out[field.name] = field.default;
    if (field.type === 'repeater') out[field.name] = [];
  }
  return out;
};

const setValue = (name, value) => {
  values.value = { ...values.value, [name]: value };
};

/* -------------------------------------------------------- publication -- */

const STATES = {
  live: 'Live',
  pending: 'Unpublished changes',
  draft: 'Draft',
  takendown: 'Taken down',
};

const state = computed(() => {
  const p = publication.value;
  if (!p || typeof p.published !== 'boolean') return 'draft';
  if (p.published) return p.hasUnpublishedChanges ? 'pending' : 'live';
  return p.neverPublished ? 'draft' : 'takendown';
});

const stateLabel = (s) => STATES[s] ?? STATES.draft;

const canPublish = computed(() => !isNew.value && state.value !== 'live');
const canUnpublish = computed(() => !isNew.value && (state.value === 'live' || state.value === 'pending'));

/* --------------------------------------------------------------- load -- */

const versions = ref([]);
const versionsLoading = ref(false);
const versionsError = ref('');
const restoringId = ref('');

const loadCapabilities = async () => {
  try {
    const res = await fetch('/api/auth/check');
    const body = await res.json();
    capabilities.value = body.data?.user?.capabilities ?? [];
  } catch {
    capabilities.value = [];
  }
};

const loadSiteLocales = async () => {
  try {
    const res = await fetch('/api/pages');
    const body = await res.json();
    siteLocales.value = Array.isArray(body.locales) ? body.locales : [];
    if (!locale.value) locale.value = body.locale || siteLocales.value[0] || '';
  } catch {
    siteLocales.value = [];
  }
};

const entryUrl = (extra = '') =>
  `/api/collections/${encodeURIComponent(props.type.id)}/entries/${encodeURIComponent(storedSlug.value)}${extra}`;

const loadEntry = async () => {
  loading.value = true;
  loadError.value = '';
  translationMissing.value = false;
  try {
    const res = await fetch(entryUrl(localeQuery()));
    const body = await res.json().catch(() => ({}));

    if (res.status === 404) {
      // The entry exists (we have its slug from another language) but not in
      // this one — an empty form to translate into, not an error.
      values.value = blankValues();
      publication.value = null;
      translationMissing.value = true;
      return;
    }
    if (!res.ok) throw new Error(body.error || `Request failed (${res.status})`);

    const data = body.data ?? {};
    // Merge over a blank so a field absent from stored data still has its schema
    // shape — an empty repeater stays an array rather than becoming undefined.
    values.value = { ...blankValues(), ...(data.data ?? {}) };
    publication.value = data.publication ?? null;
  } catch (e) {
    loadError.value = `Could not load this entry: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

// Where every other language of this entry stands, one request per configured
// language, so the language panel can show live/stale/missing at a glance.
const loadTranslations = async () => {
  if (!storedSlug.value) return;

  const results = await Promise.all(siteLocales.value.map(async (code) => {
    const query = code === defaultLocale.value ? '' : `?locale=${encodeURIComponent(code)}`;
    try {
      const res = await fetch(entryUrl(query));
      if (!res.ok) return [code, { exists: false, publication: null }];
      const body = await res.json();
      return [code, { exists: true, publication: body.data?.publication ?? null }];
    } catch {
      return [code, { exists: false, publication: null }];
    }
  }));

  translations.value = Object.fromEntries(results);
};

const loadVersions = async () => {
  if (!storedSlug.value || translationMissing.value) {
    versions.value = [];
    return;
  }

  versionsLoading.value = true;
  versionsError.value = '';
  try {
    const res = await fetch(entryUrl(`/versions?locale=${encodeURIComponent(versionLocale())}`));
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      versionsError.value = body.error || `Could not read the history (${res.status}).`;
      return;
    }
    versions.value = Array.isArray(body.data) ? body.data : [];
  } catch (e) {
    versionsError.value = `Could not read the history: ${e.message}`;
  } finally {
    versionsLoading.value = false;
  }
};

const reload = async () => {
  await loadEntry();
  await Promise.all([loadTranslations(), loadVersions()]);
};

/* -------------------------------------------------------------- write -- */

const save = async () => {
  saving.value = true;
  saveError.value = '';
  fieldErrors.value = {};
  notice.value = '';

  // A translation that does not exist yet still has to be *created* in this
  // language: entries are separate documents per locale, so a PUT to a missing
  // one is a 404 by design rather than an upsert.
  const creating = isNew.value || translationMissing.value;
  const base = `/api/collections/${encodeURIComponent(props.type.id)}/entries`;
  const url = creating
    ? `${base}${localeQuery()}`
    : `${base}/${encodeURIComponent(storedSlug.value)}${localeQuery()}`;

  // The API takes the field values under a single `values` key; a slug rides
  // along when creating. For a new translation the slug is the entry's existing
  // address, so both languages share it.
  const slug = isNew.value ? slugInput.value : storedSlug.value;
  const payload = creating
    ? { ...(slug ? { slug } : {}), values: values.value }
    : { values: values.value };

  try {
    const res = await fetch(url, {
      method: creating ? 'POST' : 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      // 422 returns per-field messages keyed by field name, so each is shown
      // against the input that produced it.
      fieldErrors.value = body.errors ?? {};
      saveError.value = body.error
        || (Object.keys(fieldErrors.value).length
          ? 'Some fields need attention before this entry can be saved.'
          : `Could not save (${res.status}).`);
      return;
    }

    const data = body.data ?? {};
    storedSlug.value = data.slug || storedSlug.value || slugInput.value;
    if (data.data) values.value = { ...blankValues(), ...data.data };
    publication.value = data.publication ?? publication.value;
    translationMissing.value = false;
    notice.value = 'Saved. This is not on the public site until you publish.';
    emit('saved', storedSlug.value);
    await Promise.all([loadTranslations(), loadVersions()]);
  } catch (e) {
    saveError.value = `Could not save: ${e.message}`;
  } finally {
    saving.value = false;
  }
};

const publicationAction = async (action) => {
  publishError.value = '';
  notice.value = '';
  busy.value = action;
  try {
    const res = await fetch(entryUrl(`/${action}${localeQuery()}`), { method: 'POST' });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      publishError.value = body.error || `Could not ${action} this entry (${res.status}).`;
      return;
    }
    publication.value = body.data?.publication ?? publication.value;
    notice.value = action === 'publish'
      ? 'Published. This entry is now on the public site.'
      : 'Taken down. This entry is no longer on the public site.';
    await Promise.all([loadTranslations(), loadVersions()]);
  } catch (e) {
    publishError.value = `Could not ${action} this entry: ${e.message}`;
  } finally {
    busy.value = '';
  }
};

// Confirmation happens inside PageVersions, in the product's own voice.
const restoreVersion = async (version) => {
  restoringId.value = version.id;
  versionsError.value = '';
  notice.value = '';
  try {
    const res = await fetch(
      entryUrl(`/versions/${encodeURIComponent(version.id)}/restore?locale=${encodeURIComponent(versionLocale())}`),
      { method: 'POST' }
    );
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      versionsError.value = body.error || `Could not restore that version (${res.status}).`;
      return;
    }
    await reload();
    notice.value = 'Working copy restored. The live entry is unchanged until you publish.';
  } catch (e) {
    versionsError.value = `Could not restore that version: ${e.message}`;
  } finally {
    restoringId.value = '';
  }
};

const switchLocale = async (code) => {
  if (code === locale.value) return;
  locale.value = code;
  notice.value = '';
  publishError.value = '';
  saveError.value = '';
  await reload();
};

const remove = async () => {
  if (!window.confirm('Delete this entry? This cannot be undone.')) return;
  try {
    await fetch(entryUrl(localeQuery()), { method: 'DELETE' });
    emit('deleted', storedSlug.value);
  } catch (e) {
    saveError.value = `Could not delete this entry: ${e.message}`;
  }
};

onMounted(async () => {
  loading.value = true;
  try {
    await Promise.all([loadCapabilities(), loadSiteLocales()]);

    if (storedSlug.value) {
      await loadEntry();
      await Promise.all([loadTranslations(), loadVersions()]);
    } else {
      values.value = blankValues();
    }
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.entry-edit { max-width: 860px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 1.5rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.notice { border: 1px solid var(--app-border); }
.edit-form { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: 2rem; }

.publication-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--app-border); }
.pub-actions { display: flex; gap: 0.5rem; }

.status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.02em; }
.status-badge.live { background: #dcfce7; color: #166534; }
.status-badge.pending { background: #fef3c7; color: #92400e; }
.status-badge.draft { background: #e5e9ef; color: #3f4754; }
.status-badge.takendown { background: #fee2e2; color: #991b1b; }

.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
.form-group input { width: 100%; padding: 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }

.actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; }
.btn-primary, .btn-secondary, .btn-danger { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--app-border); }
.btn-danger { background: var(--color-danger-500); color: white; border: none; margin-right: auto; }
.btn-primary:disabled, .btn-secondary:disabled, .btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

[data-theme="dark"] .status-badge.live { background: rgba(34, 197, 94, 0.18); color: #86efac; }
[data-theme="dark"] .status-badge.pending { background: rgba(245, 158, 11, 0.18); color: #fcd34d; }
[data-theme="dark"] .status-badge.draft { background: rgba(148, 163, 184, 0.18); color: #cbd5e1; }
[data-theme="dark"] .status-badge.takendown { background: rgba(239, 68, 68, 0.18); color: #fca5a5; }
</style>
