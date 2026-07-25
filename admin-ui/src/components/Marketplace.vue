<template>
  <div class="marketplace">
    <h1 class="page-title">Marketplace</h1>
    <p class="page-subtitle">
      Install plugins from the configured registry, or upload one you already have.
    </p>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="notice" class="banner success" role="status">{{ notice }}</p>

    <!-- Stated plainly, because the two paths are not equally trusted and the
         person installing should know which one they are using. -->
    <div class="trust">
      <p><strong>Registry installs are verified.</strong> The manifest is checked against the
        configured public key before anything is written.</p>
      <p><strong>Uploads are not verified.</strong> A plugin runs with the same access as the
        CMS itself — install one only if you trust where it came from.</p>
    </div>

    <section class="panel">
      <h2 class="panel-title">From the registry</h2>

      <p v-if="loading" class="muted">Loading…</p>
      <p v-else-if="!registryConfigured" class="muted">
        No registry is configured. Set <code>core.marketplace.registryUrl</code> and its public
        key in <code>config/core.json</code>.
      </p>
      <p v-else-if="!available.length" class="muted">The registry has nothing on offer.</p>

      <ul v-else class="grid">
        <li v-for="plugin in available" :key="plugin.id" class="card">
          <div>
            <p class="name">{{ plugin.name }}</p>
            <p class="version">{{ plugin.version }}</p>
            <p class="description">{{ plugin.description }}</p>
          </div>
          <button
            class="btn-primary"
            :disabled="installing === plugin.id || plugin.installed"
            @click="install(plugin)"
          >
            {{ plugin.installed ? 'Installed' : installing === plugin.id ? 'Installing…' : 'Install' }}
          </button>
        </li>
      </ul>
    </section>

    <section class="panel">
      <h2 class="panel-title">Upload a plugin</h2>
      <p class="muted">A ZIP containing a <code>plugin.json</code> and a <code>bootstrap.php</code>.</p>

      <label class="btn-secondary upload">
        {{ uploading ? 'Uploading…' : 'Choose a ZIP file' }}
        <input ref="fileInput" type="file" accept=".zip,application/zip" :disabled="uploading" @change="upload" />
      </label>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';

const available = ref([]);
const loading = ref(true);
const installing = ref('');
const uploading = ref(false);
const registryConfigured = ref(false);
const error = ref('');
const notice = ref('');

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/marketplace');
    if (!res.ok) throw new Error(`Request failed (${res.status})`);

    const body = await res.json();
    const data = body.data ?? {};
    available.value = data.available ?? data.plugins ?? [];
    registryConfigured.value = Boolean(data.registryUrl) || available.value.length > 0;

    // Surface registry problems rather than showing an empty list that looks
    // like "nothing available".
    if (Array.isArray(data.errors) && data.errors.length) {
      error.value = data.errors.join(' ');
    }
  } catch (e) {
    error.value = `Could not reach the marketplace: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

const install = async (plugin) => {
  installing.value = plugin.id;
  error.value = '';
  notice.value = '';

  try {
    const res = await fetch('/api/marketplace/install', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: plugin.id, version: plugin.version }),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      error.value = body.error ?? `Could not install (${res.status}).`;
      return;
    }

    notice.value = `${plugin.name} installed. Activate it on the Plugins page.`;
    await load();
  } catch (e) {
    error.value = `Could not install: ${e.message}`;
  } finally {
    installing.value = '';
  }
};

const fileInput = ref(null);

const upload = async (event) => {
  const file = event.target.files?.[0];
  if (!file) return;

  uploading.value = true;
  error.value = '';
  notice.value = '';

  const form = new FormData();
  form.append('file', file);

  try {
    const res = await fetch('/api/marketplace/upload', { method: 'POST', body: form });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      error.value = body.error ?? `Could not upload (${res.status}).`;
      return;
    }

    notice.value = 'Plugin uploaded. Activate it on the Plugins page.';
    await load();
  } catch (e) {
    error.value = `Could not upload: ${e.message}`;
  } finally {
    uploading.value = false;
    if (fileInput.value) fileInput.value.value = '';
  }
};

onMounted(load);
</script>

<style scoped>
.marketplace { max-width: 900px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.35rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0 0 1.25rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.success { color: var(--color-primary-600); }
.trust { border: 1px solid var(--app-border); border-radius: 10px; padding: 0.9rem 1.1rem; margin-bottom: 1.5rem; }
.trust p { margin: 0 0 0.4rem; font-size: 0.875rem; color: var(--app-text-muted); }
.trust p:last-child { margin-bottom: 0; }
.panel { border: 1px solid var(--app-border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem; background: var(--card-bg); }
.panel-title { margin: 0 0 0.75rem; font-size: 1.125rem; }
.muted { color: var(--app-text-muted); font-size: 0.875rem; margin: 0 0 0.75rem; }
.grid { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; }
.card { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.875rem 1rem; border: 1px solid var(--app-border); border-radius: 8px; }
.name { margin: 0; font-weight: 600; }
.version { margin: 0.1rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.description { margin: 0.3rem 0 0; font-size: 0.875rem; color: var(--app-text-muted); }
.upload { position: relative; overflow: hidden; display: inline-flex; align-items: center; }
.upload input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.btn-primary { padding: 0.55rem 1.1rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; white-space: nowrap; }
.btn-primary:disabled { opacity: 0.55; cursor: not-allowed; }
.btn-secondary { padding: 0.55rem 1.1rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }

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
