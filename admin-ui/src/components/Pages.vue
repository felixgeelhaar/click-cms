<template>
  <div class="pages">
    <div class="page-header">
      <div>
        <h1 class="page-title">Pages</h1>
        <p class="page-subtitle">Manage your content pages</p>
      </div>
      <button class="btn-primary" @click="createPage">+ New Page</button>
    </div>
    <div class="filter-tabs">
      <button v-for="tab in tabs" :key="tab.value" :class="['tab', { active: currentTab === tab.value }]" @click="currentTab = tab.value">
        {{ tab.label }} ({{ getTabCount(tab.value) }})
      </button>
    </div>
    <div v-if="loading" class="loading">Loading...</div>
    <div v-else-if="filteredPages.length === 0" class="empty-state">No pages found in this category.</div>
    <div v-else class="page-list">
      <div v-for="page in filteredPages" :key="page.key" class="page-card">
        <div class="page-info">
          <h3>{{ page.data?.title || getSlug(page.key) }}</h3>
          <p class="page-slug">/{{ getSlug(page.key) }}</p>
          <span :class="['status-badge', page.data?.status || 'draft']">{{ page.data?.status || 'draft' }}</span>
        </div>
        <div class="page-actions">
          <button class="btn-sm btn-secondary" @click="editPage(getSlug(page.key))">Edit</button>
          <button class="btn-sm btn-danger" @click="deletePage(getSlug(page.key))">Delete</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
const emit = defineEmits(['navigate']);
const pages = ref([]);
const loading = ref(true);
const currentTab = ref('all');
const tabs = [{ label: 'All Pages', value: 'all' }, { label: 'Published', value: 'published' }, { label: 'Drafts', value: 'draft' }];

const filteredPages = computed(() => {
  if (currentTab.value === 'all') return pages.value;
  return pages.value.filter(p => (p.data?.status || 'draft') === currentTab.value);
});

const getTabCount = (tab) => {
  if (tab === 'all') return pages.value.length;
  return pages.value.filter(p => (p.data?.status || 'draft') === tab).length;
};

const getSlug = (key) => key.replace('page:', '');

const loadPages = async () => {
  try {
    const res = await fetch('/api/pages');
    const data = await res.json();
    pages.value = data.data || [];
  } catch (e) { console.error(e); }
  loading.value = false;
};

const createPage = () => emit('navigate', '/admin/pages/new');
const editPage = (slug) => emit('navigate', `/admin/pages/edit/${slug}`);
const deletePage = async (slug) => {
  if (!confirm('Delete this page?')) return;
  await fetch(`/api/pages/${slug}`, { method: 'DELETE' });
  pages.value = pages.value.filter(p => !p.key.endsWith(slug));
};

onMounted(loadPages);
</script>

<style scoped>
.pages { max-width: 1200px; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); }
.btn-primary { padding: 0.625rem 1rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; }
.filter-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; }
.tab { padding: 0.5rem 1rem; background: none; border: none; border-radius: 8px; cursor: pointer; color: var(--app-text-muted); font-weight: 500; }
.tab.active { background: var(--sidebar-active); color: var(--sidebar-active-text); }
.loading, .empty-state { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.page-list { display: flex; flex-direction: column; gap: 1rem; }
.page-card { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius-sm); }
.page-info h3 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.25rem; }
.page-slug { color: var(--app-text-muted); font-size: 0.875rem; margin-bottom: 0.5rem; }
.status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; }
.status-badge.published { background: #dcfce7; color: #166534; }
.status-badge.draft { background: #fef3c7; color: #92400e; }
.page-actions { display: flex; gap: 0.5rem; }
.btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 6px; cursor: pointer; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--app-border); }
.btn-danger { background: var(--color-danger-500); color: white; border: none; }
</style>
