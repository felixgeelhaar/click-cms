<template>
  <div v-if="loading" class="loading">Loading…</div>
  <div v-else-if="error" class="error">{{ error }}</div>
  <article v-else class="post">
    <div class="container">
      <router-link to="/blog" class="back">← All posts</router-link>
      <div class="meta">
        <span v-if="entry.data?.date" class="date">{{ formatDate(entry.data.date) }}</span>
        <span v-if="author" class="by">by {{ author }}</span>
      </div>
      <h1>{{ entry.title }}</h1>
      <p v-if="entry.data?.excerpt" class="lede">{{ entry.data.excerpt }}</p>
      <div class="rich body" v-html="entry.data?.body"></div>
    </div>
  </article>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { fetchEntry } from '../api.js';

const props = defineProps({ slug: String });
const loading = ref(true);
const error = ref('');
const entry = ref(null);

const author = computed(() => entry.value?.references?.author?.title || '');
const formatDate = (d) => {
  const parsed = new Date(d);
  return Number.isNaN(parsed.getTime()) ? d : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
};

onMounted(async () => {
  try {
    const e = await fetchEntry('post', props.slug);
    if (!e) { error.value = 'Post not found.'; return; }
    entry.value = e;
  } catch (e) {
    error.value = 'Could not load this post from the CMS.';
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.post { padding: 72px 0 40px; }
.post .container { max-width: 760px; }
.back { font-weight: 650; font-size: 15px; }
.meta { display: flex; gap: 14px; align-items: center; margin: 28px 0 10px; font-size: 14px; }
.date { font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--electric); }
.by { color: var(--muted); }
.post h1 { font-size: clamp(2rem, 5vw, 3rem); }
.lede { font-size: 1.28rem; color: var(--body); margin: 20px 0 28px; }
.body { font-size: 1.14rem; color: var(--body); }
</style>
