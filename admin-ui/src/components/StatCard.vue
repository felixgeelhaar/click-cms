<template>
  <div class="stat-card" :class="'color-' + color">
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path v-for="(d, i) in iconPaths" :key="i" :d="d" />
      </svg>
    </div>
    <div class="stat-content">
      <div class="stat-value">{{ value }}</div>
      <div class="stat-label">{{ label }}</div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ icon: String, label: String, value: Number, color: String });
const iconPaths = computed(() => {
  const p = props.icon || '';
  if (!p) return ['M3 3h8v8H3z'];
  return p.split('|');
});
</script>

<style scoped>
.stat-card { padding: 1.5rem 1rem; }
.stat-icon { display: none; }
.stat-content { display: flex; flex-direction: column-reverse; gap: .35rem; }
.stat-value { font-size: 2rem; font-weight: 500; color: var(--app-text); letter-spacing: -.04em; }
.stat-label { font-size: .8125rem; color: var(--app-text-muted); }
</style>
