<template>
  <div class="plugins">
    <h1 class="page-title">Plugins</h1>
    <p class="page-subtitle">Extend your CMS functionality</p>
    <div v-if="loading" class="loading">Loading...</div>
    <div v-else class="plugin-grid">
      <div v-for="plugin in plugins" :key="plugin.id" class="plugin-card">
        <div class="plugin-info">
          <h3>{{ plugin.name }}</h3>
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
const loading = ref(true);

const loadPlugins = async () => {
  try {
    const res = await fetch('/api/plugins');
    const data = await res.json();
    plugins.value = data.data || [];
  } catch (e) { console.error(e); }
  loading.value = false;
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
.loading { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.plugin-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
.plugin-card { padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
.plugin-info h3 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem; }
.plugin-info p { color: var(--app-text-muted); font-size: 0.875rem; }
.plugin-actions { display: flex; align-items: center; gap: 1rem; margin-top: 1rem; }
.status { font-size: 0.75rem; text-transform: uppercase; font-weight: 500; }
.status.activated { color: var(--color-success-500); }
.status.deactivated { color: var(--app-text-muted); }
.btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 6px; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--app-border); }
</style>
