<template>
  <section class="submissions" aria-labelledby="form-submissions-heading">
    <div class="submissions-head">
      <div>
        <h1 id="form-submissions-heading" class="submissions-title">Form submissions</h1>
        <p class="submissions-subtitle">Messages visitors have sent through a contact form.</p>
      </div>
      <button
        type="button"
        class="btn-sm"
        :disabled="loading"
        aria-label="Reload the list of form submissions"
        @click="load"
      >
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <!--
      Every value shown here was typed by an anonymous visitor and is therefore
      untrusted. It is rendered with text interpolation only — never v-html — so
      the browser shows it as text and cannot be tricked into running it. This is
      the display half of the contract the plugin holds at rest: it stores the
      bytes as data, this shows them as data.
    -->
    <p v-if="error" class="submissions-error" role="alert">{{ error }}</p>

    <p v-if="loading && !submissions.length" class="submissions-empty">Loading…</p>

    <p v-else-if="!submissions.length && !error" class="submissions-empty">
      No submissions yet. When a visitor sends a contact form, it appears here.
    </p>

    <ol v-else class="submissions-list">
      <li v-for="entry in submissions" :key="entry.id" class="submission">
        <div class="submission-head">
          <p class="submission-from">{{ displayName(entry) }}</p>
          <p class="submission-when">
            <span v-if="entry.page" class="submission-page">/{{ entry.page }}</span>
            <span class="submission-time">{{ formatWhen(entry.submittedAt) }}</span>
          </p>
        </div>

        <dl class="submission-fields">
          <div v-for="field in fieldsOf(entry)" :key="field.name" class="submission-field">
            <dt>{{ field.label }}</dt>
            <dd :class="{ 'is-message': field.name === 'message' }">{{ field.value }}</dd>
          </div>
        </dl>
      </li>
    </ol>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const submissions = ref([]);
const loading = ref(false);
const error = ref('');

/**
 * The labels a reader sees for the three contact fields. The stored keys are
 * the input names the form posts; this maps them to something readable without
 * depending on the section's editor-facing labels, which a reader here has no
 * access to.
 */
const FIELD_LABELS = { name: 'Name', email: 'Email', message: 'Message' };

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/forms/submissions');
    if (!res.ok) {
      // 403 is the expected refusal for an account without editorial reach;
      // say so plainly rather than showing an empty list that looks like "none".
      error.value = res.status === 403
        ? 'You do not have permission to read form submissions.'
        : 'Could not load submissions. Please try again.';
      submissions.value = [];
      return;
    }
    const body = await res.json();
    submissions.value = Array.isArray(body.data) ? body.data : [];
  } catch {
    error.value = 'Could not load submissions. Please try again.';
    submissions.value = [];
  } finally {
    loading.value = false;
  }
};

/** The three fields in a fixed, readable order, skipping any that are absent. */
const fieldsOf = (entry) => {
  const values = entry.fields || {};
  return ['name', 'email', 'message']
    .filter((name) => values[name] !== undefined && values[name] !== '')
    .map((name) => ({ name, label: FIELD_LABELS[name], value: values[name] }));
};

const displayName = (entry) => (entry.fields?.name || 'Anonymous');

const formatWhen = (value) => {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value ?? '') : parsed.toLocaleString();
};

onMounted(load);
</script>

<style scoped>
.submissions { max-width: 60rem; }
.submissions-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.submissions-title { margin: 0; font-size: 1.5rem; font-weight: 600; color: var(--app-text); }
.submissions-subtitle { margin: 0.25rem 0 0; font-size: 0.875rem; color: var(--app-text-muted); }
.submissions-error { margin: 1rem 0 0; padding: 0.6rem 0.85rem; font-size: 0.875rem; border-radius: 8px; color: var(--color-danger-600, #dc2626); border: 1px solid var(--color-danger-600, #dc2626); background: color-mix(in srgb, var(--color-danger-600, #dc2626) 8%, transparent); }
.submissions-empty { margin: 1.5rem 0 0; font-size: 0.9375rem; color: var(--app-text-muted); }
.submissions-list { list-style: none; margin: 1.25rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.85rem; }
.submission { padding: 1rem 1.25rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--app-surface-strong); }
.submission-head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.submission-from { margin: 0; font-size: 1rem; font-weight: 600; color: var(--app-text); }
.submission-when { margin: 0; font-size: 0.8125rem; color: var(--app-text-muted); display: flex; align-items: baseline; gap: 0.6rem; flex-wrap: wrap; }
.submission-page { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.submission-fields { margin: 0.75rem 0 0; display: grid; grid-template-columns: max-content 1fr; gap: 0.35rem 1rem; }
.submission-field { display: contents; }
.submission-field dt { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-text-muted); }
.submission-field dd { margin: 0; font-size: 0.9375rem; color: var(--app-text); overflow-wrap: anywhere; }
.submission-field dd.is-message { white-space: pre-wrap; }
.btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8125rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); font: inherit; white-space: nowrap; }
.btn-sm:disabled { opacity: 0.6; cursor: not-allowed; }

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
