<template>
  <div class="plugin-detail">
    <button class="back-btn" @click="emit('back')">← Back</button>
    <h1>{{ id }}</h1>
  </div>
</template>

<script setup>
defineProps({ id: String });
const emit = defineEmits(['back']);
</script>

<style scoped>
.plugin-detail { max-width: 800px; }
.back-btn { background: none; border: none; color: var(--color-primary-600); cursor: pointer; margin-bottom: 1rem; }
h1 { font-size: 1.5rem; font-weight: 700; }
</style>
