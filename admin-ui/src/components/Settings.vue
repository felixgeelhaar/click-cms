<template>
  <div class="settings">
    <h1 class="page-title">Settings</h1>
    <p class="page-subtitle">How this instance behaves. Changes take effect immediately.</p>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="notice" class="banner success" role="status">{{ notice }}</p>

    <section class="panel">
      <div class="setting">
        <div class="setting-copy">
          <h2 class="setting-title">Headless mode</h2>
          <p class="setting-desc">
            Off, this instance renders your website for visitors. On, it renders no
            public pages of its own — your content is served only through the
            <code>/api/pages</code> delivery API, for a separate front end to display.
            The admin stays exactly as it is either way.
          </p>
          <p v-if="headless" class="setting-state warn">
            The public site is off. Visitors to a page address get a “not found”;
            your front end reads the API instead.
          </p>
          <p v-else class="setting-state">
            The public site is on. Your pages render at their own addresses.
          </p>
        </div>

        <!--
          A real checkbox with a label, so it is reachable and announced without
          any custom keyboard handling. The switch look is CSS over the box.
        -->
        <label class="switch">
          <input
            type="checkbox"
            :checked="headless"
            :disabled="loading || saving"
            @change="toggle($event.target.checked)"
          />
          <span class="switch-track" aria-hidden="true"></span>
          <span class="switch-label">{{ headless ? 'On' : 'Off' }}</span>
        </label>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';

const headless = ref(false);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const notice = ref('');

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/settings');
    if (!res.ok) throw new Error(`Request failed (${res.status})`);
    const body = await res.json();
    headless.value = Boolean(body.data?.headless);
  } catch (e) {
    error.value = `Could not read the settings: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

const toggle = async (on) => {
  const previous = headless.value;

  // Move the switch to what was asked immediately, then put it back if the save
  // is refused. Reflecting intent through the ref — rather than leaving the DOM
  // checkbox to hold it — is what lets a refused change snap the switch back:
  // re-reading a value that did not change would not re-sync the box the user
  // had already flipped.
  headless.value = on;
  saving.value = true;
  error.value = '';
  notice.value = '';

  try {
    const res = await fetch('/api/settings', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ headless: on }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      headless.value = previous;
      error.value = body.error || `Could not save (${res.status}).`;
      return;
    }
    headless.value = Boolean(body.data?.headless);
    notice.value = headless.value
      ? 'Headless mode is on. Your public pages are no longer rendered here.'
      : 'Headless mode is off. Your public pages are rendered again.';
  } catch (e) {
    headless.value = previous;
    error.value = `Could not save: ${e.message}`;
  } finally {
    saving.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.settings { max-width: 760px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.35rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0 0 1.5rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); background: var(--app-surface-strong); }
.banner.success { color: var(--color-primary-600); background: var(--app-surface-strong); }
.panel { border: 1px solid var(--app-border); border-radius: 10px; padding: 1.25rem 1.5rem; background: var(--card-bg); }
.setting { display: flex; align-items: flex-start; justify-content: space-between; gap: 2rem; }
.setting-copy { flex: 1; min-width: 0; }
.setting-title { margin: 0 0 0.4rem; font-size: 1.0625rem; }
.setting-desc { margin: 0 0 0.6rem; font-size: 0.875rem; line-height: 1.5; color: var(--app-text-muted); }
.setting-state { margin: 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.setting-state.warn { color: var(--color-warning-500, #b45309); }
.switch { display: inline-flex; flex-direction: column; align-items: center; gap: 0.35rem; cursor: pointer; }
.switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.switch-track { position: relative; width: 46px; height: 26px; border-radius: 999px; background: var(--app-surface-strong); border: 1px solid var(--app-border); transition: background 0.15s; }
.switch-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.25); transition: transform 0.15s; }
.switch input:checked + .switch-track { background: var(--color-primary-600); border-color: var(--color-primary-600); }
.switch input:checked + .switch-track::after { transform: translateX(20px); }
.switch input:focus-visible + .switch-track { outline: 2px solid var(--color-primary-600); outline-offset: 2px; }
.switch input:disabled + .switch-track { opacity: 0.6; }
.switch-label { font-size: 0.75rem; font-weight: 600; color: var(--app-text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
</style>
