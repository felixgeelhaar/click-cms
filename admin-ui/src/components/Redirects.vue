<template>
  <div class="redirects">
    <div class="page-header">
      <div>
        <h1 class="page-title">Redirects</h1>
        <p class="page-subtitle">Send an old address to a new one. Checked when a page is not found.</p>
      </div>
      <button class="btn-primary" @click="addRule">+ Add redirect</button>
    </div>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="notice" class="banner success" role="status">{{ notice }}</p>

    <p v-if="!loading && rules.length === 0" class="empty">
      No redirects yet. Add one to send an old or changed address somewhere useful.
    </p>

    <ul v-else class="rule-list">
      <li v-for="(rule, i) in rules" :key="i" class="rule">
        <div class="rule-field">
          <label :for="`from-${i}`">From</label>
          <input :id="`from-${i}`" v-model="rule.from" type="text" placeholder="/old-page" />
        </div>
        <span class="arrow" aria-hidden="true">→</span>
        <div class="rule-field">
          <label :for="`to-${i}`">To</label>
          <input :id="`to-${i}`" v-model="rule.to" type="text" placeholder="/new-page or https://…" />
        </div>
        <div class="rule-field kind">
          <label :for="`perm-${i}`">Type</label>
          <select :id="`perm-${i}`" v-model="rule.permanent">
            <option :value="true">Permanent (301)</option>
            <option :value="false">Temporary (302)</option>
          </select>
        </div>
        <button
          class="btn-sm danger"
          :aria-label="`Remove the redirect from ${rule.from || 'this rule'}`"
          @click="rules.splice(i, 1)"
        >Remove</button>
      </li>
    </ul>

    <div class="actions">
      <button class="btn-primary" :disabled="saving" @click="save">
        {{ saving ? 'Saving…' : 'Save redirects' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';

const rules = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const notice = ref('');

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/redirects');
    if (!res.ok) throw new Error(`Request failed (${res.status})`);
    const body = await res.json();
    // permanent comes back as a boolean; keep it that way for the select.
    rules.value = (body.data ?? []).map((r) => ({
      from: r.from ?? '',
      to: r.to ?? '',
      permanent: r.permanent !== false,
    }));
  } catch (e) {
    error.value = `Could not load redirects: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

const addRule = () => {
  rules.value.push({ from: '', to: '', permanent: true });
};

const save = async () => {
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const res = await fetch('/api/redirects', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ redirects: rules.value }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      error.value = body.error || `Could not save (${res.status}).`;
      return;
    }
    // The server drops anything unsafe, so re-read what it actually kept —
    // otherwise the screen could show a rule that was silently rejected.
    const kept = body.data ?? [];
    rules.value = kept.map((r) => ({ from: r.from, to: r.to, permanent: r.permanent !== false }));
    notice.value = rules.value.length === (kept.length)
      ? `Saved ${kept.length} redirect${kept.length === 1 ? '' : 's'}.`
      : 'Saved. Some entries were dropped because their destination was not a valid path or URL.';
  } catch (e) {
    error.value = `Could not save: ${e.message}`;
  } finally {
    saving.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.redirects { max-width: 900px; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.35rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1rem; background: var(--app-surface-strong); }
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.success { color: var(--color-primary-600); }
.empty { color: var(--app-text-muted); padding: 1.5rem 0; }
.rule-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; }
.rule { display: flex; align-items: flex-end; gap: 0.75rem; padding: 0.875rem 1rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--card-bg); }
.rule-field { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; min-width: 0; }
.rule-field.kind { flex: 0 0 11rem; }
.rule-field label { font-size: 0.75rem; font-weight: 600; color: var(--app-text-muted); text-transform: uppercase; letter-spacing: 0.03em; }
.rule-field input, .rule-field select { padding: 0.55rem 0.65rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.arrow { padding-bottom: 0.6rem; color: var(--app-text-muted); }
.actions { margin-top: 1.25rem; }
.btn-primary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-sm { padding: 0.5rem 0.75rem; font-size: 0.8125rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); }
.btn-sm.danger { color: var(--color-danger-600, #dc2626); }

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
