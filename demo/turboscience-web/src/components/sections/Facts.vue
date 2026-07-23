<template>
  <section class="section facts">
    <div class="container">
      <h2 v-if="values.heading" class="eyebrow-h">{{ values.heading }}</h2>
      <div class="grid">
        <div v-for="(item, i) in items" :key="i" class="fact">
          <div class="value">{{ item.value }}</div>
          <div class="caption">{{ item.caption }}</div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ values: Object, media: Object });
const items = computed(() => Array.isArray(props.values?.items) ? props.values.items : []);
</script>

<style scoped>
.eyebrow-h { font-size: 13px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: var(--muted); margin-bottom: 32px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 28px; }
.fact { padding: 8px 0; border-top: 2px solid var(--electric); }
.value { font-size: clamp(2.2rem, 5vw, 3.2rem); font-weight: 850; color: var(--ink); letter-spacing: -.03em;
  font-variant-numeric: tabular-nums; }
.caption { margin-top: 6px; font-size: 14px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
</style>
