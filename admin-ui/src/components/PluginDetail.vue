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

/*
 * Focus. Every control here is reachable by keyboard and, until this rule, none
 * of them said so: the browser default is easy to lose against these surfaces
 * and several controls sit on tinted backgrounds where it disappears entirely.
 * One ring, stated once, on whatever the keyboard is actually on.
 */
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
summary:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
