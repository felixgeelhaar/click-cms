<template>
  <div class="themes">
    <h1 class="page-title">Themes</h1>
    <p class="page-subtitle">The design your public pages wear. One is live at a time.</p>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>

    <div v-if="loading" class="loading">Loading...</div>
    <p v-else-if="!themes.length" class="empty">
      No themes are installed. Copy a theme folder into <code>themes/</code> and it appears here.
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
          <button v-else class="btn-sm btn-primary" :disabled="switching" @click="activate(theme.id)">
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
const error = ref('');

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

onMounted(load);
</script>

<style scoped>
.themes { max-width: 1200px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin-bottom: 2rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); background: var(--app-surface-strong); }
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
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
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
