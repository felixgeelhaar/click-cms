<template>
  <section class="page-head">
    <div class="container">
      <p class="eyebrow">Field notes</p>
      <h1>The TurboScience blog</h1>
      <p class="sub">Experiments, explainers and open data — straight from the lab.</p>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <div v-if="loading" class="loading">Loading…</div>
      <div v-else-if="error" class="error">{{ error }}</div>
      <div v-else-if="!posts.length" class="loading">No posts published yet.</div>
      <div v-else class="post-grid">
        <router-link v-for="p in posts" :key="p.slug" :to="`/blog/${p.slug}`" class="post-card">
          <div class="date" v-if="p.data?.date">{{ formatDate(p.data.date) }}</div>
          <h2>{{ p.title }}</h2>
          <p v-if="p.data?.excerpt">{{ p.data.excerpt }}</p>
          <div class="by" v-if="authorName(p)">{{ authorName(p) }}</div>
        </router-link>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { fetchCollection } from '../api.js';

const loading = ref(true);
const error = ref('');
const posts = ref([]);

const authorName = (p) => p.references?.author?.title || '';
const formatDate = (d) => {
  const parsed = new Date(d);
  return Number.isNaN(parsed.getTime()) ? d : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
};

onMounted(async () => {
  try {
    const { items } = await fetchCollection('post');
    posts.value = items;
  } catch (e) {
    error.value = 'Could not load posts from the CMS.';
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.page-head { padding: 88px 0 0; }
.page-head h1 { font-size: clamp(2.2rem, 5vw, 3.2rem); }
.sub { color: var(--muted); font-size: 1.15rem; margin-top: 12px; }
.post-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 22px; }
.post-card { display: block; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 28px; color: inherit; transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease; }
.post-card:hover { transform: translateY(-3px); border-color: var(--electric); box-shadow: 0 20px 44px -24px rgba(10,16,36,.4); }
.date { font-size: 13px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--electric); margin-bottom: 12px; }
.post-card h2 { font-size: 1.35rem; margin-bottom: 10px; }
.post-card p { color: var(--body); margin: 0 0 16px; }
.by { font-size: 14px; color: var(--muted); }
</style>
