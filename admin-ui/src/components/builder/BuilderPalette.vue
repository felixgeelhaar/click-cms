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

    <!--
      Snapshot-only paste templates. Inserting deep-copies with new ids; editing
      a saved block never rewrites pages that already used it.
    -->
    <h2 class="panel-title blocks-title">Saved blocks</h2>
    <p v-if="ctx.blocksError.value" class="palette-hint palette-error">{{ ctx.blocksError.value }}</p>
    <p v-else-if="!ctx.blocks.value.length" class="palette-hint">No saved blocks yet.</p>
    <ul v-else class="blocks-list">
      <li v-for="block in ctx.blocks.value" :key="block.id" class="blocks-row">
        <span class="blocks-name">{{ block.name }}</span>
        <button
          type="button"
          class="palette-button blocks-insert"
          :disabled="ctx.blocksBusy.value"
          @click="ctx.insertBlock(block)"
        >Insert</button>
      </li>
    </ul>
    <button
      v-if="canSaveSelection"
      type="button"
      class="palette-button save-block"
      :disabled="ctx.blocksBusy.value"
      @click="ctx.saveBlock()"
    >Save selection as block</button>
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
  // Adding against a columns node lands in its first column, so say that rather
  // than let the placement look arbitrary.
  if (node.type === 'columns') return 'Adds inside the first column. Select a column to add to that one.';
  if (isContainer(node.type)) return `Adds inside the selected ${node.type}.`;
  return `Adds right after the selected ${node.type}.`;
});

// The page root is the document shell — saving it as a reusable paste is not
// useful, so the action only appears for a real selection inside the page.
const canSaveSelection = computed(() => {
  const id = ctx.selectedId.value;
  const root = ctx.rootId.value;
  return !!(id && root && id !== root && ctx.nodes.value[id]);
});
</script>

<style scoped>
.palette { padding: 1rem; }
.panel-title { font-size: 0.9rem; font-weight: 700; margin: 0 0 0.5rem; color: var(--app-text); }
.blocks-title { margin-top: 1.25rem; }
.palette-hint { margin: 0 0 0.75rem; font-size: 0.75rem; color: var(--app-text-muted); line-height: 1.4; }
.palette-error { color: var(--color-danger-600, #dc2626); }
.palette-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.palette-button { padding: 0.5rem; border: 1px solid var(--app-border); background: var(--app-surface-strong); color: var(--app-text); border-radius: 6px; cursor: pointer; text-transform: capitalize; font: inherit; font-size: 0.8125rem; }
.palette-button:hover { border-color: var(--color-primary-600); }
.palette-button:disabled { opacity: 0.6; cursor: not-allowed; }
.blocks-list { list-style: none; margin: 0 0 0.75rem; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.blocks-row { display: flex; align-items: center; gap: 0.5rem; }
.blocks-name { flex: 1; min-width: 0; font-size: 0.8125rem; color: var(--app-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.blocks-insert { flex-shrink: 0; text-transform: none; padding: 0.35rem 0.5rem; }
.save-block { width: 100%; text-transform: none; margin-top: 0.25rem; }
</style>
