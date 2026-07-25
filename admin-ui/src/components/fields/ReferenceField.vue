<template>
  <!-- A reference is stored as the target's slug(s), but an editor picks by
       title. The options are the target collection's entries (or the site's
       pages); the value sent back is the slug (single) or a list of slugs. -->
  <div>
    <!-- Many: a dropdown to add, and a chip per chosen item that can be reordered
         and removed. Order is meaningful — "featured posts" render in the order
         set here — so the chips carry move controls, not just a remove. -->
    <div v-if="field.multiple" class="ref-multi">
      <ul v-if="selected.length" class="ref-chips" :aria-label="`Chosen ${label}`">
        <li v-for="(slug, index) in selected" :key="slug" class="ref-chip">
          <span class="ref-chip-order">
            <button
              type="button"
              class="ref-chip-move"
              :disabled="index === 0"
              :aria-label="`Move ${titleFor(slug)} up`"
              @click="move(index, index - 1)"
            >↑</button>
            <button
              type="button"
              class="ref-chip-move"
              :disabled="index === selected.length - 1"
              :aria-label="`Move ${titleFor(slug)} down`"
              @click="move(index, index + 1)"
            >↓</button>
          </span>
          <span class="ref-chip-label">{{ titleFor(slug) }}</span>
          <button type="button" class="ref-chip-remove" :aria-label="`Remove ${titleFor(slug)}`" @click="remove(slug)">×</button>
        </li>
      </ul>
      <!-- The wrapper's <label for> points at `inputId`, which in multiple mode
           belongs to nothing — the chips are the value and this dropdown only
           adds to them. Naming it explicitly says what it does ("Add Related")
           rather than leaving a nameless combo box. -->
      <select
        class="reference-select"
        :aria-label="`Add ${label}`"
        :aria-describedby="describedBy"
        :disabled="loading"
        @change="addFromEvent($event)"
      >
        <option value="">{{ loading ? 'Loading…' : (available.length ? '+ Add…' : emptyLabel) }}</option>
        <option v-for="opt in available" :key="opt.slug" :value="opt.slug">{{ opt.title }}</option>
      </select>
    </div>

    <!-- One: a plain select. -->
    <select
      v-else
      :id="inputId"
      class="reference-select"
      :value="modelValue || ''"
      :aria-describedby="describedBy"
      @change="$emit('update:modelValue', $event.target.value || null)"
    >
      <option value="">{{ loading ? 'Loading…' : (options.length ? '— none —' : emptyLabel) }}</option>
      <option v-for="opt in options" :key="opt.slug" :value="opt.slug">{{ opt.title }}</option>
      <option v-if="danglingSlug" :value="danglingSlug">{{ danglingSlug }} (missing)</option>
    </select>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: { type: null, default: null },
  inputId: { type: String, default: '' },
  describedBy: { type: String, default: undefined },
});
const emit = defineEmits(['update:modelValue']);

const options = ref([]);
const loading = ref(true);

const target = computed(() => props.field.references || '');
// What to call this field when a control needs its own name. Falls back to the
// referenced type so a field declared without a label is still announced.
const label = computed(() => props.field.label || target.value || 'items');
const emptyLabel = computed(() =>
  target.value === 'page' ? 'No pages yet' : `No ${target.value} entries yet`
);

const selected = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));
// Options not already chosen, for the "add" dropdown.
const available = computed(() => options.value.filter((o) => !selected.value.includes(o.slug)));

const titleFor = (slug) => options.value.find((o) => o.slug === slug)?.title || `${slug} (missing)`;

// Single-mode: a value not among the loaded options is dangling.
const danglingSlug = computed(() => {
  const v = props.modelValue;
  if (!v || loading.value || props.field.multiple) return null;
  return options.value.some((o) => o.slug === v) ? null : v;
});

const add = (slug) => {
  if (slug && !selected.value.includes(slug)) emit('update:modelValue', [...selected.value, slug]);
};
const addFromEvent = (e) => {
  add(e.target.value);
  e.target.value = ''; // reset the dropdown to its "+ Add…" prompt
};
const remove = (slug) => emit('update:modelValue', selected.value.filter((s) => s !== slug));

// Reorder within the list — the stored order is the delivery order, so moving a
// chip is a real edit, not a display nicety.
const move = (from, to) => {
  if (to < 0 || to >= selected.value.length) return;
  const next = [...selected.value];
  const [item] = next.splice(from, 1);
  next.splice(to, 0, item);
  emit('update:modelValue', next);
};

const endpoint = () =>
  target.value === 'page'
    ? '/api/pages'
    : `/api/collections/${encodeURIComponent(target.value)}/entries`;

const load = async () => {
  loading.value = true;
  try {
    const res = await fetch(endpoint());
    const body = await res.json();
    const rows = body.data || [];
    options.value = rows.map((r) => ({ slug: r.slug, title: r.title || r.data?.title || r.slug }));
  } catch {
    options.value = [];
  } finally {
    loading.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.reference-select { width: 100%; padding: 0.5rem 0.65rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.reference-select:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }
.ref-chips { list-style: none; margin: 0 0 0.5rem; padding: 0; display: flex; flex-wrap: wrap; gap: 0.4rem; }
.ref-chip { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.25rem 0.35rem 0.25rem 0.4rem; border: 1px solid var(--control-border); border-radius: 999px; background: var(--app-surface-strong); font-size: 0.8125rem; }
.ref-chip-order { display: inline-flex; }
.ref-chip-move { border: 0; background: none; cursor: pointer; color: var(--app-text-muted); font-size: 0.8125rem; line-height: 1; padding: 0 0.1rem; }
.ref-chip-move:disabled { opacity: 0.35; cursor: not-allowed; }
.ref-chip-move:not(:disabled):hover { color: var(--color-primary-600); }
.ref-chip-remove { border: 0; background: none; cursor: pointer; color: var(--app-text-muted); font-size: 1rem; line-height: 1; padding: 0 0.2rem; }
.ref-chip-remove:hover { color: var(--color-danger-600, #dc2626); }
/* The chip controls are 12px glyphs with no border of their own; without a ring
   there is nothing on screen to say which one the keyboard is on. */
.ref-chip-move:focus-visible,
.ref-chip-remove:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-radius: 4px; }
</style>
