<template>
  <div class="palette">
    <h2 class="panel-title">Add</h2>
    <!--
      Where a new node lands depends on the selection, so say what the selection
      is: an editor who does not know a section is selected cannot predict that
      "Add text" nests rather than appends. The controller resolves the actual
      placement; this only names it.
    -->
    <p class="palette-hint">{{ hint }}</p>
    <div class="palette-grid">
      <button
        v-for="type in types"
        :key="type"
        type="button"
        class="palette-button"
        @click="ctx.addNode(type)"
      >{{ type }}</button>
    </div>
  </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { NODE_TYPES, isContainer } from './model.js';

const ctx = inject('builderCtx');
const types = NODE_TYPES;

const hint = computed(() => {
  const id = ctx.selectedId.value;
  const node = id ? ctx.nodes.value[id] : null;
  if (!node) return 'Adds to the top of the page. Select a section to nest inside it.';
  if (isContainer(node.type)) return `Adds inside the selected ${node.type}.`;
  return `Adds right after the selected ${node.type}.`;
});
</script>

<style scoped>
.palette { padding: 1rem; }
.panel-title { font-size: 0.9rem; font-weight: 700; margin: 0 0 0.5rem; color: var(--app-text); }
.palette-hint { margin: 0 0 0.75rem; font-size: 0.75rem; color: var(--app-text-muted); line-height: 1.4; }
.palette-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.palette-button { padding: 0.5rem; border: 1px solid var(--app-border); background: var(--app-surface-strong); color: var(--app-text); border-radius: 6px; cursor: pointer; text-transform: capitalize; font: inherit; font-size: 0.8125rem; }
.palette-button:hover { border-color: var(--color-primary-600); }
</style>
