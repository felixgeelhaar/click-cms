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
.stat-card { display: flex; align-items: center; gap: 1rem; padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
.stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.stat-icon svg { width: 24px; height: 24px; }
.color-blue .stat-icon { background: #dbeafe; color: #2563eb; }
.color-green .stat-icon { background: #dcfce7; color: #16a34a; }
.color-yellow .stat-icon { background: #fef3c7; color: #d97706; }
.color-purple .stat-icon { background: #f3e8ff; color: #9333ea; }
.stat-value { font-size: 1.75rem; font-weight: 700; color: var(--app-text); }
.stat-label { font-size: 0.875rem; color: var(--app-text-muted); }
</style>
