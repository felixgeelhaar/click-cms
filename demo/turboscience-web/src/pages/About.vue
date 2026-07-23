<template>
  <div v-if="loading" class="loading">Loading…</div>
  <div v-else-if="error" class="error">{{ error }}</div>
  <template v-else>
    <section class="page-head">
      <div class="container">
        <p class="eyebrow">TurboScience</p>
        <h1>{{ title }}</h1>
      </div>
    </section>
    <SectionRenderer :sections="sections" :media="media" />
  </template>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import SectionRenderer from '../components/SectionRenderer.vue';
import { fetchPage } from '../api.js';

const loading = ref(true);
const error = ref('');
const title = ref('');
const sections = ref([]);
const media = ref({});

onMounted(async () => {
  try {
    const page = await fetchPage('about');
    title.value = page.title;
    sections.value = page.sections;
    media.value = page.media;
  } catch (e) {
    error.value = 'Could not load this page from the CMS.';
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.page-head { padding: 88px 0 8px; }
.page-head h1 { font-size: clamp(2.2rem, 5vw, 3.2rem); }
</style>
