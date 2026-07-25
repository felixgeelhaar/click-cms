<template>
  <div class="updates">
    <h1 class="page-title">Updates</h1>
    <p class="page-subtitle">Keep the CMS itself current.</p>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="notice" class="banner success" role="status">{{ notice }}</p>

    <!-- A non-administrator gets the reason rather than an empty page: updating
         is the same privilege as installing a plugin, and saying so is more use
         than a blank screen. -->
    <p v-if="forbidden" class="muted">
      You do not have permission to update this site. Ask an administrator.
    </p>

    <template v-else>
      <section class="panel">
        <div class="row">
          <div>
            <p class="label">Running</p>
            <p class="version">{{ currentVersion || '—' }}</p>
          </div>
          <div>
            <p class="label">Policy</p>
            <p class="policy">{{ policyLabel }}</p>
          </div>
          <button class="btn-secondary" :disabled="checking" @click="check">
            {{ checking ? 'Checking…' : 'Check now' }}
          </button>
        </div>
      </section>

      <section class="panel">
        <p v-if="loading" class="muted">Loading…</p>

        <p v-else-if="!configured" class="muted">
          No update feed is configured. Set <code>core.updates.feedUrl</code> and its public key in
          <code>config/core.json</code>.
        </p>

        <!-- Why the feed gave nothing, when it did — otherwise a broken key or a
             wrong URL reads as "you are up to date" forever. -->
        <p v-else-if="feedError" class="muted">{{ feedError }}</p>

        <div v-else-if="hasUpdate" class="offer">
          <p class="offer-head">
            <span class="offer-version">{{ release.version }}</span>
            <span class="step">{{ step }}</span>
            <span v-if="release.security" class="badge security">Security</span>
          </p>
          <p v-if="release.notes" class="notes">{{ release.notes }}</p>
          <button class="btn-primary" :disabled="installing" @click="apply">
            {{ installing ? 'Installing…' : 'Install this update' }}
          </button>
        </div>

        <p v-else class="muted">{{ reason || 'Already up to date.' }}</p>
      </section>

      <section class="panel">
        <h2 class="panel-title">History</h2>
        <p v-if="!history.length" class="muted">Nothing has been installed yet.</p>
        <ul v-else class="list">
          <li v-for="(entry, i) in history" :key="i" class="entry">
            <span :class="['result', entry.ok ? 'ok' : 'failed']">{{ entry.ok ? 'Installed' : 'Failed' }}</span>
            <span class="entry-version">{{ entry.from }} → {{ entry.to }}</span>
            <span class="entry-at">{{ entry.at }}</span>
            <span v-if="entry.error" class="entry-error">{{ entry.error }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';

const POLICY_LABELS = {
  manual: 'Manual — never checks',
  notify: 'Notify — tells you, installs nothing',
  security: 'Security — installs security releases automatically',
  minor: 'Minor — installs patch and minor releases automatically',
  all: 'All — installs everything, including majors',
};

const currentVersion = ref('');
const policy = ref('');
const configured = ref(false);
const feedError = ref('');
const hasUpdate = ref(false);
const release = ref({});
const step = ref('');
const reason = ref('');
const history = ref([]);

const loading = ref(true);
const checking = ref(false);
const installing = ref(false);
const forbidden = ref(false);
const error = ref('');
const notice = ref('');

const policyLabel = computed(() => POLICY_LABELS[policy.value] ?? policy.value ?? '—');

const applyState = (data) => {
  currentVersion.value = data.currentVersion ?? '';
  policy.value = data.policy ?? '';
  configured.value = Boolean(data.configured);
  feedError.value = data.feedError ?? '';
  hasUpdate.value = Boolean(data.hasUpdate);
  release.value = data.release ?? {};
  step.value = data.step ?? '';
  reason.value = data.reason ?? '';
};

const loadHistory = async () => {
  try {
    const res = await fetch('/api/updates/history');
    if (!res.ok) return;
    const body = await res.json();
    history.value = body.data ?? [];
  } catch {
    // The history is context, not the point of the page; a failure to read it
    // must not hide whether an update is available.
  }
};

const load = async (url = '/api/updates', init = undefined) => {
  error.value = '';
  try {
    const res = await fetch(url, init);
    const body = await res.json().catch(() => ({}));

    if (res.status === 401 || res.status === 403) {
      forbidden.value = true;
      return;
    }
    if (!res.ok) {
      error.value = body.error ?? `Could not check for updates (${res.status}).`;
      return;
    }

    forbidden.value = false;
    applyState(body.data ?? {});
    await loadHistory();
  } catch (e) {
    error.value = `Could not check for updates: ${e.message}`;
  }
};

const check = async () => {
  checking.value = true;
  notice.value = '';
  await load('/api/updates/check', { method: 'POST' });
  checking.value = false;
};

const apply = async () => {
  installing.value = true;
  error.value = '';
  notice.value = '';

  try {
    const res = await fetch('/api/updates/apply', { method: 'POST' });
    const body = await res.json().catch(() => ({}));

    if (res.status === 401 || res.status === 403) {
      forbidden.value = true;
      return;
    }
    if (!res.ok) {
      error.value = body.error ?? `Could not install the update (${res.status}).`;
      return;
    }

    notice.value = `Version ${body.data?.version ?? ''} installed. Reload to run the new code.`;
    await load();
  } catch (e) {
    error.value = `Could not install the update: ${e.message}`;
  } finally {
    installing.value = false;
  }
};

onMounted(async () => {
  await load();
  loading.value = false;
});
</script>

<style scoped>
.updates { max-width: 900px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.35rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0 0 1.25rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.success { color: var(--color-primary-600); }
.panel { border: 1px solid var(--app-border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem; background: var(--card-bg); }
.panel-title { margin: 0 0 0.75rem; font-size: 1.125rem; }
.muted { color: var(--app-text-muted); font-size: 0.875rem; margin: 0; }
.row { display: flex; align-items: center; gap: 2rem; flex-wrap: wrap; }
.row button { margin-left: auto; }
.label { margin: 0; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-text-muted); }
.version { margin: 0.15rem 0 0; font-size: 1.25rem; font-weight: 600; }
.policy { margin: 0.15rem 0 0; font-size: 0.875rem; color: var(--app-text-muted); }
.offer-head { display: flex; align-items: center; gap: 0.6rem; margin: 0 0 0.5rem; }
.offer-version { font-size: 1.25rem; font-weight: 600; }
.step { font-size: 0.75rem; text-transform: uppercase; color: var(--app-text-muted); }
.badge { font-size: 0.7rem; text-transform: uppercase; font-weight: 600; padding: 0.15rem 0.45rem; border-radius: 999px; }
.badge.security { background: var(--color-danger-600, #dc2626); color: white; }
.notes { margin: 0 0 0.9rem; font-size: 0.875rem; color: var(--app-text-muted); white-space: pre-line; }
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.entry { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; font-size: 0.875rem; padding: 0.6rem 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; }
.result { font-size: 0.75rem; text-transform: uppercase; font-weight: 600; }
.result.ok { color: var(--color-success-text, #15803d); }
.result.failed { color: var(--color-danger-600, #dc2626); }
.entry-version { font-weight: 500; }
.entry-at, .entry-error { color: var(--app-text-muted); font-size: 0.8125rem; }
.btn-primary { padding: 0.55rem 1.1rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
.btn-primary:disabled { opacity: 0.55; cursor: not-allowed; }
.btn-secondary { padding: 0.55rem 1.1rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-secondary:disabled { opacity: 0.55; cursor: not-allowed; }

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
