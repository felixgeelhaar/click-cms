<template>
  <div class="themes">
    <div class="page-header">
      <div>
        <h1 class="page-title">Themes</h1>
        <p class="page-subtitle">The design your public pages wear. One is live at a time.</p>
      </div>
      <label class="btn-primary upload-button" data-test="theme-upload">
        {{ uploading ? 'Uploading…' : 'Upload ZIP' }}
        <input
          ref="fileInput"
          type="file"
          accept=".zip,application/zip"
          :disabled="uploading"
          @change="upload"
        />
      </label>
    </div>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="notice" class="banner success" role="status">{{ notice }}</p>

    <p class="upload-hint muted">
      A ZIP with a <code>theme.json</code> and its stylesheet. Uploads are not
      signature-verified — install one only if you trust where it came from.
      You can still copy a folder into <code>themes/</code> on disk.
    </p>

    <div v-if="loading" class="loading">Loading...</div>
    <p v-else-if="!themes.length" class="empty">
      No themes are installed. Upload a ZIP or copy a theme folder into <code>themes/</code>.
    </p>
    <div v-else class="theme-grid">
      <div v-for="theme in themes" :key="theme.id" :class="['theme-card', { active: theme.active }]">
        <div class="theme-info">
          <!-- h2: "Themes" is the h1, so a card heading at level 3 skips one. -->
          <h2>{{ theme.name }}</h2>
          <p class="theme-meta">
            <span v-if="theme.version">v{{ theme.version }}</span>
            <span v-if="theme.author">{{ theme.author }}</span>
          </p>
          <p>{{ theme.description || 'No description' }}</p>
        </div>
        <div class="theme-actions">
          <span v-if="theme.active" class="status active">Active</span>
          <button v-else class="btn-sm btn-primary" data-test="theme-activate" :disabled="switching" @click="activate(theme.id)">
            Activate
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const themes = ref([]);
const loading = ref(true);
const switching = ref(false);
const uploading = ref(false);
const error = ref('');
const notice = ref('');
const fileInput = ref(null);

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/themes');
    const body = await res.json();
    if (!res.ok) throw new Error(body.error || `Request failed (${res.status})`);
    themes.value = body.data?.themes || [];
  } catch (e) {
    error.value = `Could not read the themes: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

const activate = async (id) => {
  switching.value = true;
  error.value = '';
  notice.value = '';
  try {
    const res = await fetch('/api/themes/activate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      // A 403 is the ordinary case for an editor, not a bug: the server's own
      // wording explains it, so it is shown rather than replaced with our guess.
      error.value = body.error || `Could not switch theme (${res.status}).`;
      return;
    }
    // Re-read rather than marking it active here, so the list shows what the
    // server actually kept — the same reason Settings re-reads after a save.
    await load();
  } catch (e) {
    error.value = `Could not switch theme: ${e.message}`;
  } finally {
    switching.value = false;
  }
};

const upload = async (event) => {
  const file = event.target.files?.[0];
  if (!file) return;

  uploading.value = true;
  error.value = '';
  notice.value = '';

  try {
    const form = new FormData();
    form.append('file', file);
    const res = await fetch('/api/themes/upload', { method: 'POST', body: form });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      error.value = body.error || `Upload failed (${res.status}).`;
      return;
    }
    notice.value = body.data?.name
      ? `Installed “${body.data.name}”. Activate it when you are ready.`
      : 'Theme installed. Activate it when you are ready.';
    await load();
  } catch (e) {
    error.value = `Upload failed: ${e.message}`;
  } finally {
    uploading.value = false;
    if (fileInput.value) fileInput.value.value = '';
  }
};

onMounted(load);
</script>

<style scoped>
.themes { max-width: 1200px; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0 0 1rem; }
.upload-button { position: relative; overflow: hidden; display: inline-flex; align-items: center; flex: 0 0 auto; }
.upload-button input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.upload-hint { margin: 0 0 1.5rem; font-size: 0.875rem; line-height: 1.45; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); background: var(--app-surface-strong); }
.banner.success { color: var(--color-success-text, #15803d); background: var(--app-surface-strong); }
.muted { color: var(--app-text-muted); }
.loading { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.empty { color: var(--app-text-muted); font-size: 0.875rem; }
.theme-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
.theme-card { padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
.theme-card.active { border-color: var(--color-primary-600); }
.theme-info h2 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.25rem; }
.theme-info p { color: var(--app-text-muted); font-size: 0.875rem; }
.theme-meta { display: flex; gap: 0.75rem; margin-bottom: 0.5rem; font-size: 0.75rem; }
.theme-actions { display: flex; align-items: center; gap: 1rem; margin-top: 1rem; }
.status { font-size: 0.75rem; text-transform: uppercase; font-weight: 500; }
.status.active { color: var(--color-success-text, #15803d); }
.btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 6px; cursor: pointer; }
.btn-primary { padding: 0.625rem 1.25rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-sm.btn-primary { padding: 0.5rem 1rem; border-radius: 6px; }
.btn-sm:disabled, .upload-button:has(input:disabled) { opacity: 0.6; cursor: not-allowed; }

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
summary:focus-visible,
.upload-button:focus-within {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
