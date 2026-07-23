<template>
  <div class="dashboard">
    <div class="page-header">
      <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Welcome back to Click CMS</p>
      </div>
    </div>
    <div class="stats-grid">
      <StatCard v-for="stat in stats" :key="stat.label" v-bind="stat" />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import StatCard from './StatCard.vue';

const stats = ref([
  { icon: 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z', label: 'Total Pages', value: 0, color: 'blue' },
  { icon: 'M5 13l4 4L19 7', label: 'Live', value: 0, color: 'green' },
  { icon: 'M4 20h16M14 4l6 6M6 14l8-8 4 4-8 8H6z', label: 'Edits pending', value: 0, color: 'yellow' },
  { icon: 'M7 3v4M17 3v4M5 7h14v4a5 5 0 0 1-5 5h-4a5 5 0 0 1-5-5V7zM12 16v5', label: 'Active Plugins', value: 0, color: 'purple' },
]);

onMounted(async () => {
  try {
    const [pagesRes, pluginsRes] = await Promise.all([fetch('/api/pages'), fetch('/api/plugins')]);
    const pagesData = await pagesRes.json();
    const pluginsData = await pluginsRes.json();
    const pages = pagesData.data || [];
    const plugins = pluginsData.data || [];
    // Read from `publication`, not a `status` field. That field was removed when
    // publishing became a thing that happens rather than a value that is set,
    // and this screen went on counting it — so the landing page of the admin
    // reported "0 published, 0 drafts" for a site that was entirely live.
    const live = pages.filter(p => p.publication?.published).length;
    const pending = pages.filter(p => p.publication?.hasUnpublishedChanges).length;
    const activePlugins = plugins.filter(p => p.state === 'activated').length;
    stats.value = [
      { icon: 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z', label: 'Total Pages', value: pages.length, color: 'blue' },
      { icon: 'M5 13l4 4L19 7', label: 'Live', value: live, color: 'green' },
      // The number worth acting on: work that is finished but not yet public.
      { icon: 'M4 20h16M14 4l6 6M6 14l8-8 4 4-8 8H6z', label: 'Edits pending', value: pending, color: 'yellow' },
      { icon: 'M7 3v4M17 3v4M5 7h14v4a5 5 0 0 1-5 5h-4a5 5 0 0 1-5-5V7zM12 16v5', label: 'Active Plugins', value: activePlugins, color: 'purple' },
    ];
  } catch (e) { console.error(e); }
});
</script>

<style scoped>
.dashboard { max-width: 1400px; }
.page-header { margin-bottom: 2rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
</style>
