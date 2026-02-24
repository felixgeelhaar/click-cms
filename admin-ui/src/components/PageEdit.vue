<template>
  <div class="page-edit">
    <h1 class="page-title">{{ isNew ? 'New Page' : 'Edit Page' }}</h1>
    <div class="edit-form">
      <div class="form-group">
        <label>Title</label>
        <input v-model="page.title" type="text" placeholder="Page title" />
      </div>
      <div class="form-group">
        <label>Slug</label>
        <input v-model="page.slug" type="text" placeholder="page-slug" />
      </div>
      <div class="form-group">
        <label>Content</label>
        <textarea v-model="page.content" rows="10" placeholder="Page content..."></textarea>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select v-model="page.status">
          <option value="draft">Draft</option>
          <option value="published">Published</option>
        </select>
      </div>
      <div class="actions">
        <button class="btn-secondary" @click="cancel">Cancel</button>
        <button class="btn-primary" @click="savePage">Save</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
const props = defineProps({ slug: String });
const emit = defineEmits(['saved', 'cancel']);
const isNew = computed(() => !props.slug);
const page = ref({ title: '', slug: '', content: '', status: 'draft' });

const savePage = async () => {
  const method = isNew.value ? 'POST' : 'PUT';
  const url = isNew.value ? '/api/pages' : `/api/pages/${props.slug}`;
  await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title: page.value.title, slug: page.value.slug, content: page.value.content, status: page.value.status }) });
  emit('saved');
};

const cancel = () => emit('cancel');

onMounted(async () => {
  if (props.slug) {
    const res = await fetch(`/api/pages/${props.slug}`);
    const data = await res.json();
    if (data.data) { page.value = { title: data.data.title || '', slug: props.slug, content: data.data.content || '', status: data.data.status || 'draft' }; }
  }
});
</script>

<style scoped>
.page-edit { max-width: 800px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 2rem; }
.edit-form { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: 2rem; }
.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
.form-group input, .form-group textarea, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); }
.actions { display: flex; gap: 1rem; justify-content: flex-end; }
.btn-primary, .btn-secondary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--app-border); }
</style>
