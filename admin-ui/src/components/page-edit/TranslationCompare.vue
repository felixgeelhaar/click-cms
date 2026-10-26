<template>
  <section id="translation-compare-panel" class="translation-compare" aria-labelledby="translation-compare-title">
    <div class="compare-heading">
      <div>
        <h2 id="translation-compare-title">Compare translations</h2>
        <p>Compare saved source and target snapshots. Freshness is unknown until someone records a review.</p>
      </div>
      <button type="button" class="inline-button" @click="$emit('close')">Close comparison</button>
    </div>

    <div class="language-controls">
      <label>
        Source language
        <select v-model="sourceLocale" aria-label="Source language">
          <option v-for="code in locales" :key="`source-${code}`" :value="code">{{ nameOf(code) }} ({{ code }})</option>
        </select>
      </label>
      <label>
        Target language
        <select v-model="targetLocale" aria-label="Target language">
          <option v-for="code in locales" :key="`target-${code}`" :value="code">{{ nameOf(code) }} ({{ code }})</option>
        </select>
      </label>
    </div>

    <p v-if="sameLanguage" class="compare-message" role="status">Choose two different languages.</p>
    <div v-else class="compare-columns" aria-live="polite" :aria-busy="busy">
      <section class="compare-side" :aria-label="`Source: ${nameOf(sourceLocale)}`">
        <h3>{{ nameOf(sourceLocale) }} <span>{{ sourcePublication }}</span></h3>
        <p v-if="sourceBusy" class="compare-message" role="status">Loading source translation…</p>
        <p v-else-if="sourceError" class="compare-message error" role="alert">{{ sourceError }}</p>
        <p v-else-if="sourceMissing" class="compare-message" role="status">No saved translation exists in {{ nameOf(sourceLocale) }}.</p>
        <dl v-if="sourcePage" class="field-values">
          <template v-for="field in fieldsFor(sourcePage)" :key="`source-${field.path}`">
            <dt>{{ field.path }}</dt><dd>{{ field.value }}</dd>
          </template>
        </dl>
      </section>
      <section class="compare-side" :aria-label="`Target: ${nameOf(targetLocale)}`">
        <h3>{{ nameOf(targetLocale) }} <span>{{ targetPublication }}</span></h3>
        <p v-if="targetBusy" class="compare-message" role="status">Loading target translation…</p>
        <p v-else-if="targetError" class="compare-message error" role="alert">{{ targetError }}</p>
        <p v-else-if="targetMissing" class="compare-message" role="status">No saved translation exists in {{ nameOf(targetLocale) }}.</p>
        <p v-else-if="targetLocale === currentLocale" class="compare-message">Showing your current editor fields, including unsaved changes.</p>
        <dl v-if="targetPage" class="field-values">
          <template v-for="field in fieldsFor(targetPage)" :key="`target-${field.path}`">
            <dt>{{ field.path }}</dt><dd>{{ field.value }}</dd>
          </template>
        </dl>
      </section>
    </div>

    <div v-if="!sameLanguage && !busy && !sourceError && !targetError && !sourceMissing && !targetMissing" class="diff-table-wrap">
      <table aria-label="Translation field comparison">
        <thead>
          <tr><th scope="col">Field</th><th scope="col">{{ nameOf(sourceLocale) }}</th><th scope="col">{{ nameOf(targetLocale) }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="field in differences" :key="field.path">
            <th scope="row">{{ field.path }}</th>
            <td>{{ field.before }}</td>
            <td>{{ field.after }}</td>
          </tr>
          <tr v-if="differences.length === 0"><td colspan="3">No title or section field differences.</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="!sameLanguage && !busy && sourcePage && targetPage" class="freshness-panel" aria-live="polite">
      <p v-if="freshnessBusy" role="status">Checking saved translation review…</p>
      <p v-else-if="freshnessError" class="compare-message error" role="alert">{{ freshnessError }}</p>
      <p v-else-if="snapshotMismatch" class="compare-message error" role="status">Saved translations changed while the comparison loaded. Reload to compare the current saved versions.</p>
      <template v-else-if="freshness">
        <p v-if="freshness.freshness === 'unknown'" role="status">Not yet reviewed against this source.</p>
        <p v-else-if="freshness.freshness === 'reviewed'" role="status">Reviewed against current source by {{ freshness.reviewedBy }} on {{ formatDate(freshness.reviewedAt) }}.</p>
        <p v-else-if="freshness.staleReasons?.includes('source_changed')" class="compare-message error" role="status">Source changed since this review. Review the saved comparison again.</p>
        <p v-else class="compare-message error" role="status">Target changed since this review. Review the saved comparison again.</p>
      </template>
      <button
        v-if="mayMarkTarget"
        type="button"
        data-testid="mark-translation-reviewed"
        class="inline-button"
        :disabled="freshnessBusy || markBusy || snapshotMismatch || !displayedSourceRevision || !displayedTargetRevision || (targetLocale === currentLocale && targetDirty)"
        @click="markReviewed"
      >{{ markBusy ? 'Saving review…' : freshness?.freshness === 'reviewed' ? 'Confirm review of current saved versions' : 'Mark saved target reviewed against this source' }}</button>
      <p v-if="targetLocale === currentLocale && targetDirty" class="compare-message">Save the target translation before recording its review.</p>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { comparePageDrafts } from './pageComparison.js';

const props = defineProps({
  slug: { type: String, required: true },
  locales: { type: Array, default: () => [] },
  currentLocale: { type: String, default: '' },
  currentRevision: { type: String, default: '' },
  workingCopy: { type: Object, default: () => ({ title: '', sections: [] }) },
  translations: { type: Object, default: () => ({}) },
  canMark: { type: Boolean, default: false },
  canEditAny: { type: Boolean, default: false },
  currentUsername: { type: String, default: '' },
  targetDirty: { type: Boolean, default: false },
});
defineEmits(['close']);

const sourceLocale = ref('');
const targetLocale = ref('');
const sourcePage = ref(null);
const targetPage = ref(null);
const sourceBusy = ref(false);
const targetBusy = ref(false);
const sourceError = ref('');
const targetError = ref('');
const sourceMissing = ref(false);
const targetMissing = ref(false);
const freshness = ref(null);
const freshnessBusy = ref(false);
const freshnessError = ref('');
const markBusy = ref(false);
const displayedSourceRevision = ref('');
const displayedTargetRevision = ref('');
const savedTargetOwner = ref('');
const snapshotMismatch = ref(false);
let requestSequence = 0;

const sameLanguage = computed(() => !!sourceLocale.value && sourceLocale.value === targetLocale.value);
const busy = computed(() => sourceBusy.value || targetBusy.value);
const displayNames = (() => {
  try { return new Intl.DisplayNames(undefined, { type: 'language' }); } catch { return null; }
})();
const nameOf = (code) => {
  try { return displayNames?.of(code) || code; } catch { return code; }
};
const freshnessOf = (code) => {
  const translation = props.translations[code];
  if (!translation) return 'Status checking';
  if (!translation.exists) return 'Not translated';
  const publication = translation.publication;
  if (!publication) return 'Status unknown';
  if (!publication.published) return publication.neverPublished ? 'Draft, never published' : 'Taken down';
  return publication.hasUnpublishedChanges ? 'Live, edits pending' : 'Live and up to date';
};
const sourcePublication = computed(() => `Publication: ${freshnessOf(sourceLocale.value)}`);
const targetPublication = computed(() => `Publication: ${freshnessOf(targetLocale.value)}`);
const mayMarkTarget = computed(() => props.canMark && (props.canEditAny || (savedTargetOwner.value !== '' && savedTargetOwner.value === props.currentUsername)));
const formatDate = (value) => {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};
const normalize = (data) => ({
  title: typeof data?.title === 'string' ? data.title : '',
  sections: Array.isArray(data?.sections) ? data.sections : [],
});
const fieldsFor = (page) => {
  const fields = [{ path: 'Title', value: page.title || '—' }];
  const flatten = (value, path) => {
    if (value && typeof value === 'object') {
      const entries = Array.isArray(value) ? value.map((item, index) => [String(index), item]) : Object.entries(value);
      for (const [key, child] of entries) flatten(child, path ? `${path}.${key}` : key);
      return;
    }
    fields.push({ path, value: typeof value === 'string' ? value : JSON.stringify(value) ?? '—' });
  };
  page.sections.forEach((section, index) => {
    fields.push({ path: `Section ${index + 1} · design`, value: section.type || '—' });
    flatten(section.values ?? {}, `Section ${index + 1}`);
  });
  return fields;
};
const differences = computed(() => {
  if (!sourcePage.value || !targetPage.value) return [];
  return comparePageDrafts(sourcePage.value, targetPage.value)
    .filter((field) => field.path === 'title' || field.path.startsWith('sections'));
});

const readLocale = async (code) => {
  const response = await fetch(`/api/pages/${encodeURIComponent(props.slug)}?locale=${encodeURIComponent(code)}`);
  const body = await response.json().catch(() => ({}));
  if (response.status === 404 || body.fallback === true) return { missing: true };
  if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
  return {
    page: normalize(body.data?.data ?? body.data ?? {}),
    revision: typeof body.data?.revision === 'string' ? body.data.revision : '',
    owner: typeof body.data?.data?.owner === 'string' ? body.data.data.owner : '',
  };
};

const loadComparison = async () => {
  const sequence = ++requestSequence;
  sourcePage.value = null;
  targetPage.value = null;
  sourceError.value = '';
  targetError.value = '';
  sourceMissing.value = false;
  targetMissing.value = false;
  freshness.value = null;
  freshnessError.value = '';
  snapshotMismatch.value = false;
  displayedSourceRevision.value = '';
  displayedTargetRevision.value = '';
  savedTargetOwner.value = '';
  sourceBusy.value = false;
  targetBusy.value = false;
  markBusy.value = false;
  if (sameLanguage.value || !sourceLocale.value || !targetLocale.value || !props.slug) return;

  sourceBusy.value = true;
  if (targetLocale.value === props.currentLocale) {
    targetPage.value = normalize(props.workingCopy);
    targetBusy.value = false;
  } else {
    targetBusy.value = true;
  }

  const sourceRequest = readLocale(sourceLocale.value)
    .then((result) => {
      if (sequence !== requestSequence) return;
      sourceMissing.value = result.missing === true;
      sourcePage.value = result.page ?? null;
      displayedSourceRevision.value = result.revision ?? '';
    })
    .catch((error) => { if (sequence === requestSequence) sourceError.value = `Could not load ${nameOf(sourceLocale.value)}: ${error.message}`; })
    .finally(() => { if (sequence === requestSequence) sourceBusy.value = false; });

  const targetRequest = readLocale(targetLocale.value)
    .then((result) => {
      if (sequence !== requestSequence) return;
      targetMissing.value = result.missing === true;
      displayedTargetRevision.value = targetLocale.value === props.currentLocale
        ? props.currentRevision
        : (result.revision ?? '');
      savedTargetOwner.value = result.owner ?? '';
      if (targetLocale.value !== props.currentLocale) targetPage.value = result.page ?? null;
    })
    .catch((error) => { if (sequence === requestSequence) targetError.value = `Could not load ${nameOf(targetLocale.value)}: ${error.message}`; })
    .finally(() => { if (sequence === requestSequence) targetBusy.value = false; });
  await Promise.all([sourceRequest, targetRequest]);
  if (!sourceMissing.value && !targetMissing.value && !sourceError.value && !targetError.value) {
    await loadFreshness(sequence);
  }
};

const loadFreshness = async (sequence) => {
  if (!sourceLocale.value || !targetLocale.value || sourceLocale.value === targetLocale.value) return;
  freshnessBusy.value = true;
  try {
    const query = new URLSearchParams({ slug: props.slug, sourceLocale: sourceLocale.value, targetLocale: targetLocale.value });
    const response = await fetch(`/api/translation-freshness?${query}`);
    const body = await response.json().catch(() => ({}));
    if (sequence !== requestSequence) return;
    if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
    freshness.value = body.data ?? null;
    snapshotMismatch.value = !!(
      freshness.value?.currentSourceRevision && displayedSourceRevision.value
      && freshness.value.currentSourceRevision !== displayedSourceRevision.value
      || freshness.value?.currentTargetRevision && displayedTargetRevision.value
      && freshness.value.currentTargetRevision !== displayedTargetRevision.value
    );
  } catch (error) {
    if (sequence === requestSequence) freshnessError.value = `Could not load saved review status: ${error.message}`;
  } finally {
    if (sequence === requestSequence) freshnessBusy.value = false;
  }
};

const markReviewed = async () => {
  if (!mayMarkTarget.value || !displayedSourceRevision.value || !displayedTargetRevision.value || snapshotMismatch.value
      || (targetLocale.value === props.currentLocale && props.targetDirty)) return;
  const sequence = requestSequence;
  const slug = props.slug;
  const sourceCode = sourceLocale.value;
  const targetCode = targetLocale.value;
  markBusy.value = true;
  freshnessError.value = '';
  try {
    const response = await fetch('/api/translation-freshness', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        slug,
        sourceLocale: sourceCode,
        targetLocale: targetCode,
        sourceRevision: displayedSourceRevision.value,
        targetRevision: displayedTargetRevision.value,
        expectedReviewedAt: freshness.value.reviewedAt ?? null,
      }),
    });
    const body = await response.json().catch(() => ({}));
    if (sequence !== requestSequence || slug !== props.slug || sourceCode !== sourceLocale.value || targetCode !== targetLocale.value) return;
    if (!response.ok) {
      if (response.status === 409) {
        await loadComparison();
        throw new Error('A saved translation changed. The comparison has been refreshed; review it again before marking.');
      }
      throw new Error(body.error || `Request failed (${response.status})`);
    }
    freshness.value = body.data ?? freshness.value;
  } catch (error) {
    if (sequence === requestSequence && slug === props.slug && sourceCode === sourceLocale.value && targetCode === targetLocale.value) {
      freshnessError.value = `Could not record translation review: ${error.message}`;
    }
  } finally {
    if (sequence === requestSequence && slug === props.slug && sourceCode === sourceLocale.value && targetCode === targetLocale.value) {
      markBusy.value = false;
    }
  }
};

watch(() => [props.slug, props.currentLocale, props.locales.join(','), props.currentRevision], () => {
  const current = props.locales.includes(props.currentLocale) ? props.currentLocale : props.locales[0] || '';
  targetLocale.value = current;
  sourceLocale.value = props.locales.find((code) => code !== current) || props.locales[0] || '';
}, { immediate: true, deep: true, flush: 'sync' });
watch([sourceLocale, targetLocale], loadComparison, { immediate: true, flush: 'sync' });
watch(() => props.workingCopy, (value) => {
  if (targetLocale.value === props.currentLocale) targetPage.value = normalize(value);
}, { deep: true });
</script>

<style scoped>
.translation-compare { margin: 1rem 0 1.5rem; padding: 1rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface-strong); }
.compare-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
h2 { margin: 0; font-size: 1rem; }
.compare-heading p, .compare-message { margin: .25rem 0 .8rem; color: var(--app-text-muted); font-size: .85rem; }
.compare-heading button { flex: 0 0 auto; }
.language-controls { display: flex; flex-wrap: wrap; gap: 1rem; margin: .75rem 0; }
.language-controls label { display: grid; gap: .35rem; min-width: 12rem; font-size: .85rem; font-weight: 600; }
.language-controls select { padding: .5rem; border: 1px solid var(--control-border); border-radius: 6px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.language-controls select:focus-visible, .compare-heading button:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.compare-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
.compare-side { min-width: 0; padding: .75rem; border: 1px solid var(--app-border); border-radius: 6px; background: var(--app-surface); }
.compare-side h3 { margin: 0; font-size: .9rem; }
.compare-side h3 span { display: block; margin-top: .2rem; color: var(--app-text-muted); font-size: .75rem; font-weight: 400; }
.field-values { display: grid; grid-template-columns: minmax(5rem, auto) minmax(0, 1fr); gap: .35rem .65rem; margin: .75rem 0 0; font-size: .82rem; }
.field-values dt { color: var(--app-text-muted); font-weight: 600; }
.field-values dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.compare-message.error { color: var(--color-danger-600); }
.freshness-panel { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .9rem; margin-top: .8rem; padding: .7rem .8rem; border: 1px solid var(--app-border); border-radius: 6px; background: var(--app-surface); }
.freshness-panel p { margin: 0; font-size: .84rem; }
.freshness-panel button { border: 1px solid var(--control-border); border-radius: 6px; padding: .4rem .65rem; background: var(--app-surface-strong); color: var(--app-text); font: inherit; font-size: .82rem; cursor: pointer; }
.freshness-panel button:disabled { opacity: .55; cursor: not-allowed; }
.freshness-panel button:focus-visible { outline: 3px solid var(--color-primary-600); outline-offset: 2px; }
.diff-table-wrap { max-width: 100%; margin-top: .75rem; overflow: auto; }
table { width: 100%; border-collapse: collapse; background: var(--app-surface); font-size: .85rem; }
th, td { padding: .55rem; border: 1px solid var(--app-border); text-align: left; vertical-align: top; white-space: pre-wrap; overflow-wrap: anywhere; }
thead th { background: var(--app-surface-strong); }
tbody th { width: 20%; font-weight: 600; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
@media (max-width: 720px) { .compare-columns { grid-template-columns: 1fr; } .compare-heading { flex-direction: column; } }
</style>
