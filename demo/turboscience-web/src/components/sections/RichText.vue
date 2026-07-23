<template>
  <section class="section">
    <div class="container" :class="widthClass">
      <h2 v-if="values.heading" class="heading">{{ values.heading }}</h2>
      <!-- body is HTML the CMS already sanitised to a fixed allowlist server-side. -->
      <div class="rich" v-html="values.body"></div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ values: Object, media: Object });
const widthClass = computed(() => ({ narrow: 'w-narrow', wide: 'w-wide', full: 'w-full' }[props.values?.width] || 'w-wide'));
</script>

<style scoped>
.heading { font-size: clamp(1.6rem, 3.4vw, 2.3rem); margin-bottom: 20px; }
.rich { font-size: 1.12rem; color: var(--body); }
.w-narrow { max-width: 720px; }
.w-wide { max-width: 900px; }
.w-full :deep(.rich), .w-full { max-width: 1120px; }
</style>
