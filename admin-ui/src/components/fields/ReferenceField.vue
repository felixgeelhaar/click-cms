<template>
  <!-- A reference is stored as the target's slug, but an editor picks it by
       title. The options are the target collection's entries (or the site's
       pages); the value sent back is the slug. -->
  <select
    :id="inputId"
    class="reference-select"
    :value="modelValue || ''"
    :aria-describedby="describedBy"
    @change="$emit('update:modelValue', $event.target.value || null)"
  >
    <option value="">{{ loading ? 'Loading…' : (options.length ? '— none —' : emptyLabel) }}</option>
    <option v-for="opt in options" :key="opt.slug" :value="opt.slug">{{ opt.title }}</option>
    <!-- A value pointing at something no longer in the list is still shown, so a
         dangling reference is visible rather than silently cleared. -->
    <option v-if="danglingSlug" :value="danglingSlug">{{ danglingSlug }} (missing)</option>
  </select>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: { type: null, default: null },
  inputId: { type: String, default: '' },
  describedBy: { type: String, default: undefined },
});
defineEmits(['update:modelValue']);

const options = ref([]);
const loading = ref(true);

const target = computed(() => props.field.references || '');
const emptyLabel = computed(() =>
  target.value === 'page' ? 'No pages yet' : `No ${target.value} entries yet`
);

// The current value is dangling if it is set but not among the loaded options.
const danglingSlug = computed(() => {
  const v = props.modelValue;
  if (!v || loading.value) return null;
  return options.value.some((o) => o.slug === v) ? null : v;
});

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
    // Collections list rows carry `title`; page rows carry it under `data.title`.
    options.value = rows.map((r) => ({
      slug: r.slug,
      title: r.title || r.data?.title || r.slug,
    }));
  } catch {
    options.value = [];
  } finally {
    loading.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.reference-select { width: 100%; padding: 0.5rem 0.65rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.reference-select:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: 1px; border-color: var(--color-primary-600); }
</style>
