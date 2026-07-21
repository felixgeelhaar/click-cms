<template>
  <fieldset class="repeater">
    <legend class="repeater-legend">
      {{ field.label }}
      <span v-if="field.required" class="required" aria-hidden="true">*</span>
    </legend>

    <p v-if="field.help" class="repeater-help">{{ field.help }}</p>
    <p v-if="error" class="repeater-error" role="alert">{{ error }}</p>

    <p v-if="rows.length === 0" class="repeater-empty">No entries yet.</p>

    <ol v-else class="repeater-rows">
      <li v-for="(row, index) in rows" :key="index" class="repeater-row">
        <div class="row-head">
          <span class="row-number">{{ index + 1 }}</span>
          <div class="row-controls">
            <button
              type="button"
              class="icon-btn"
              :disabled="index === 0"
              :aria-label="`Move entry ${index + 1} up`"
              @click="move(index, -1)"
            >↑</button>
            <button
              type="button"
              class="icon-btn"
              :disabled="index === rows.length - 1"
              :aria-label="`Move entry ${index + 1} down`"
              @click="move(index, 1)"
            >↓</button>
            <button
              type="button"
              class="icon-btn danger"
              :aria-label="`Remove entry ${index + 1}`"
              @click="remove(index)"
            >✕</button>
          </div>
        </div>

        <FieldInput
          v-for="sub in field.fields || []"
          :key="sub.name"
          :field="sub"
          :model-value="row[sub.name]"
          @update:model-value="setCell(index, sub.name, $event)"
        />
      </li>
    </ol>

    <button type="button" class="btn-add" :disabled="atMax" @click="add">
      + Add {{ singular }}
    </button>
    <span v-if="atMax" class="limit-note">Maximum of {{ field.max }} reached.</span>
  </fieldset>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import FieldInput from './FieldInput.vue';

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: { type: Array, default: () => [] },
  error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

/**
 * Rows are held locally rather than derived straight from the prop.
 *
 * Deriving them meant every edit read whatever the prop held when it fired. Two
 * changes in the same tick — fast typing across fields, a paste, anything
 * programmatic — both read the same snapshot, so the second emission discarded
 * the first and only the last write survived.
 *
 * Local state composes: each edit builds on the previous one, and the prop is
 * only copied back in when the parent genuinely supplies something different.
 */
const rows = ref([...(props.modelValue ?? [])]);

watch(
  () => props.modelValue,
  (next) => {
    const incoming = next ?? [];
    if (JSON.stringify(incoming) !== JSON.stringify(rows.value)) {
      rows.value = [...incoming];
    }
  }
);

const atMax = computed(() => props.field.max != null && rows.value.length >= props.field.max);

const singular = computed(() => {
  const label = props.field.label || 'entry';
  return label.endsWith('s') ? label.slice(0, -1).toLowerCase() : label.toLowerCase();
});

// Apply locally first, then emit a copy — so the next edit builds on this one
// even if the parent has not yet flowed the value back down.
const commit = (next) => {
  rows.value = next;
  emit('update:modelValue', next.map((row) => ({ ...row })));
};

const add = () => {
  const blank = {};
  for (const sub of props.field.fields || []) {
    if (sub.default !== undefined) blank[sub.name] = sub.default;
  }
  commit([...rows.value, blank]);
};

const remove = (index) => commit(rows.value.filter((_, i) => i !== index));

const setCell = (index, name, value) => {
  commit(rows.value.map((row, i) => (i === index ? { ...row, [name]: value } : row)));
};

const move = (index, delta) => {
  const target = index + delta;
  if (target < 0 || target >= rows.value.length) return;

  const next = [...rows.value];
  [next[index], next[target]] = [next[target], next[index]];
  commit(next);
};
</script>

<style scoped>
.repeater { border: 1px solid var(--app-border); border-radius: 10px; padding: 1rem; margin-bottom: 1.25rem; }
.repeater-legend { font-weight: 600; font-size: 0.875rem; padding: 0 0.4rem; }
.required { color: var(--color-danger-600, #dc2626); }
.repeater-help { margin: 0 0 0.75rem; font-size: 0.8125rem; color: var(--app-text-muted); }
.repeater-error { margin: 0 0 0.75rem; font-size: 0.8125rem; color: var(--color-danger-600, #dc2626); }
.repeater-empty { margin: 0 0 0.75rem; font-size: 0.875rem; color: var(--app-text-muted); }
.repeater-rows { list-style: none; margin: 0 0 0.75rem; padding: 0; display: grid; gap: 0.75rem; }
.repeater-row { border: 1px solid var(--app-border); border-radius: 8px; padding: 0.875rem; background: var(--app-surface-strong); }
.row-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
.row-number { font-size: 0.75rem; font-weight: 600; color: var(--app-text-muted); }
.row-controls { display: flex; gap: 0.25rem; }
.icon-btn { width: 28px; height: 28px; border: 1px solid var(--app-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; line-height: 1; }
.icon-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.icon-btn.danger { color: var(--color-danger-600, #dc2626); }
.btn-add { padding: 0.5rem 0.9rem; border: 1px dashed var(--app-border); background: none; border-radius: 8px; cursor: pointer; color: var(--app-text); font: inherit; }
.btn-add:disabled { opacity: 0.5; cursor: not-allowed; }
.limit-note { margin-left: 0.6rem; font-size: 0.8125rem; color: var(--app-text-muted); }
</style>
