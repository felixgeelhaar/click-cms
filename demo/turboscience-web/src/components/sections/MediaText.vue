<template>
  <section class="section media-text">
    <div class="container grid" :class="{ reverse: values.imagePosition === 'right' }">
      <div class="media">
        <img v-if="src" :src="src" :alt="values.alt || ''" />
        <div v-else class="placeholder"></div>
      </div>
      <div class="text">
        <h2 v-if="values.heading">{{ values.heading }}</h2>
        <div class="rich" v-html="values.body"></div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { imageUrl } from '../../api.js';
const props = defineProps({ values: Object, media: Object });
const src = computed(() => imageUrl(props.media, props.values?.image));
</script>

<style scoped>
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: center; }
.grid.reverse { direction: rtl; }
.grid.reverse .text { direction: ltr; }
.media img, .placeholder { width: 100%; aspect-ratio: 16 / 10; object-fit: cover; border-radius: var(--radius); }
.placeholder { background: linear-gradient(135deg, var(--electric), var(--spark)); }
.text h2 { font-size: clamp(1.5rem, 3.2vw, 2.1rem); margin-bottom: 16px; }
.text .rich { font-size: 1.08rem; color: var(--body); }
@media (max-width: 760px) { .grid, .grid.reverse { grid-template-columns: 1fr; direction: ltr; gap: 24px; } }
</style>
