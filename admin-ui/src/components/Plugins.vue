<template>
  <div class="plugins">
    <h1 class="page-title">Plugins</h1>
    <p class="page-subtitle">Extend your CMS functionality</p>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>

    <!-- Discovery problems used to be silent skips. An invalid plugin.json or a
         bootstrap without a manifest left the list looking healthy while the
         folder did nothing — surface them here instead. -->
    <div v-if="issues.length" class="banner warn" role="status">
      <p class="banner-title">{{ issues.length === 1 ? 'One plugin folder was skipped' : `${issues.length} plugin folders were skipped` }}</p>
      <ul class="issue-list">
        <li v-for="issue in issues" :key="issue.directory">
          <code>{{ issue.directory }}</code> — {{ issue.reason }}
        </li>
      </ul>
    </div>

    <div v-if="loading" class="loading">Loading...</div>
    <div v-else-if="!plugins.length && !issues.length" class="empty">No plugins are installed.</div>
    <div v-else class="plugin-grid">
      <div v-for="plugin in plugins" :key="plugin.id" class="plugin-card">
        <div class="plugin-info">
          <!-- h2: "Plugins" is the h1, so a card heading at level 3 skips one. -->
          <h2>{{ plugin.name }}</h2>
          <p>{{ plugin.description || 'No description' }}</p>
        </div>
        <div class="plugin-actions">
          <span :class="['status', plugin.state]">{{ plugin.state }}</span>
          <button v-if="plugin.state === 'activated'" class="btn-sm btn-secondary" @click="deactivatePlugin(plugin.id)">Deactivate</button>
          <button v-else class="btn-sm btn-primary" @click="activatePlugin(plugin.id)">Activate</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const plugins = ref([]);
const issues = ref([]);
const loading = ref(true);
const error = ref('');

const loadPlugins = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/plugins');
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      error.value = data.error || `Could not load plugins (${res.status}).`;
      plugins.value = [];
      issues.value = [];
      return;
    }
    plugins.value = data.data || [];
    issues.value = Array.isArray(data.issues) ? data.issues : [];
  } catch (e) {
    error.value = e.message || 'Could not load plugins.';
    plugins.value = [];
    issues.value = [];
  } finally {
    loading.value = false;
  }
};

const activatePlugin = async (id) => {
  await fetch(`/api/plugins/${id}/activate`, { method: 'POST' });
  loadPlugins();
};

const deactivatePlugin = async (id) => {
  await fetch(`/api/plugins/${id}/deactivate`, { method: 'POST' });
  loadPlugins();
};

onMounted(loadPlugins);
</script>

<style scoped>
.plugins { max-width: 1200px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin-bottom: 2rem; }
.loading, .empty { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.banner {
  padding: 0.75rem 1rem;
  border-radius: 8px;
  background: var(--app-surface-strong);
  font-size: 0.875rem;
  margin: 0 0 1.25rem;
}
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.warn { color: var(--color-warning-text, #a16207); border: 1px solid var(--app-border); }
.banner-title { margin: 0 0 0.5rem; font-weight: 600; }
.issue-list { margin: 0; padding-left: 1.25rem; }
.issue-list li { margin: 0.25rem 0; }
.issue-list code { font-size: 0.8125rem; }
.plugin-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
.plugin-card { padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
.plugin-info h2 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem; }
.plugin-info p { color: var(--app-text-muted); font-size: 0.875rem; }
.plugin-actions { display: flex; align-items: center; gap: 1rem; margin-top: 1rem; }
.status { font-size: 0.75rem; text-transform: uppercase; font-weight: 500; }
/* --color-success-500 is a fill: as 12px lettering on a white card it measures
   2.3:1. --color-success-text is the same green at a legible weight, and is
   restated for dark mode where a dark green would fail the other way. */
.status.activated { color: var(--color-success-text, #15803d); }
.status.deactivated { color: var(--app-text-muted); }
.btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 6px; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-sm:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
</style>
