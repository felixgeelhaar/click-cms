<template>
  <div v-if="loading" class="loading">Loading…</div>
  <div v-else-if="error" class="error">{{ error }}</div>
  <template v-else>
    <!-- The first rich-text section becomes the hero; the rest render in place.
         This is the front end's own editorial convention over the CMS content. -->
    <section v-if="hero" class="hero">
      <div class="container">
        <p class="eyebrow on">TurboScience</p>
        <h1 v-html="hero.values.heading"></h1>
        <div class="hero-body rich" v-html="hero.values.body"></div>
        <div class="hero-actions">
          <a href="/blog" class="btn on-navy">Read the blog</a>
          <a href="/about" class="btn ghost on-dark">About us</a>
        </div>
      </div>
    </section>
    <SectionRenderer :sections="rest" :media="media" />
  </template>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import SectionRenderer from '../components/SectionRenderer.vue';
import { fetchPage } from '../api.js';

const loading = ref(true);
const error = ref('');
const sections = ref([]);
const media = ref({});

const hero = computed(() => (sections.value[0]?.type === 'rich-text' ? sections.value[0] : null));
const rest = computed(() => (hero.value ? sections.value.slice(1) : sections.value));

onMounted(async () => {
  try {
    const page = await fetchPage('home');
    sections.value = page.sections;
    media.value = page.media;
  } catch (e) {
    error.value = 'Could not load the homepage from the CMS.';
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.hero { background: radial-gradient(130% 120% at 15% -30%, var(--navy-2), var(--navy)); color: var(--on-navy);
  padding: 104px 0 96px; }
.eyebrow.on { color: var(--spark); }
.hero h1 { color: #fff; font-size: clamp(2.6rem, 7vw, 4.4rem); letter-spacing: -.03em; max-width: 15ch; }
.hero-body { color: var(--on-navy); font-size: 1.25rem; max-width: 60ch; margin-top: 22px; }
.hero-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 34px; }
.btn.ghost.on-dark { color: #fff; border-color: rgba(255,255,255,.28); }
.btn.ghost.on-dark:hover { border-color: #fff; color: #fff; background: rgba(255,255,255,.08); }
</style>
