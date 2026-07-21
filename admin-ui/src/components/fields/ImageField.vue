<template>
  <div class="field" :class="{ 'field-error': error }">
    <span class="field-label">
      {{ field.label }}
      <span v-if="field.required" class="required" aria-hidden="true">*</span>
    </span>

    <div v-if="selected" class="selected">
      <img
        :src="selected.urls?.variants?.sm?.url ?? selected.urls?.original"
        :alt="selected.alt || selected.originalName"
        class="preview"
      />
      <div class="selected-meta">
        <p class="selected-name">{{ selected.originalName }}</p>
        <p class="selected-detail">
          {{ selected.width }}×{{ selected.height }}
          <span v-if="selected.variants?.length"> · {{ selected.variants.join(', ') }}</span>
        </p>
        <p v-if="!selected.alt" class="selected-warning">
          No description set — add one in the Media library so screen readers can
          describe it.
        </p>
        <!-- When the field declares displayWidth the library is fetched judged
             against that slot, so this is the server's verdict for this field
             specifically: the same file can be fine in a card and wrong in a
             header, and the wording says which. -->
        <p v-if="selected.quality?.warning" class="selected-warning">
          {{ selected.quality.message }}
        </p>
      </div>
      <button type="button" class="btn-sm" @click="clear">Remove</button>
    </div>

    <!-- A reference that is not in the library still shows, so content authored
         before the library existed keeps working and can be recognised. -->
    <p v-else-if="modelValue" class="unknown">
      Using <code>{{ modelValue }}</code>, which is not in the media library.
      <button type="button" class="link-btn" @click="clear">Clear</button>
    </p>

    <div class="picker">
      <button type="button" class="btn-sm" :disabled="loading" @click="open = !open">
        {{ open ? 'Close library' : (selected ? 'Change image' : 'Choose image') }}
      </button>
      <span v-if="loading" class="hint">Loading library…</span>
      <span v-else-if="!items.length" class="hint">
        The library is empty — upload images on the Media page first.
      </span>
    </div>

    <ul v-if="open && items.length" class="chooser">
      <li v-for="item in items" :key="item.id">
        <button
          type="button"
          class="chooser-item"
          :class="{ current: item.id === modelValue }"
          :aria-pressed="item.id === modelValue"
          @click="choose(item)"
        >
          <img
            :src="item.urls?.variants?.sm?.url ?? item.urls?.original"
            :alt="item.alt || item.originalName"
            loading="lazy"
          />
          <span class="chooser-name">{{ item.originalName }}</span>
        </button>
      </li>
    </ul>

    <p v-if="error" class="field-message error-message">{{ error }}</p>
    <p v-else-if="field.help" class="field-message help-message">{{ field.help }}</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: { type: null, default: '' },
  error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const items = ref([]);
const loading = ref(true);
const open = ref(false);

const selected = computed(() => items.value.find((i) => i.id === props.modelValue) ?? null);

const choose = (item) => {
  emit('update:modelValue', item.id);
  open.value = false;
};

const clear = () => emit('update:modelValue', '');

onMounted(async () => {
  try {
    // Ask for the library judged against this field's slot. The comparison and
    // its wording stay in the domain; this only says which slot to judge for.
    const width = Number(props.field.displayWidth) || 0;
    const query = width > 0 ? `?displayWidth=${width}` : '';
    const res = await fetch(`/api/media${query}`);
    items.value = (await res.json()).data ?? [];
  } catch {
    items.value = [];
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.field { margin-bottom: 1.25rem; }
.field-label { display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.875rem; color: var(--app-text); }
.required { color: var(--color-danger-600, #dc2626); margin-left: 0.15rem; }
.selected { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.6rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface-strong); }
.preview { width: 88px; height: 66px; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
.selected-meta { flex: 1; min-width: 0; }
.selected-name { margin: 0; font-size: 0.875rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.selected-detail { margin: 0.15rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.selected-warning { margin: 0.3rem 0 0; font-size: 0.75rem; color: var(--color-danger-600, #dc2626); }
.unknown { margin: 0 0 0.5rem; font-size: 0.8125rem; color: var(--app-text-muted); }
.picker { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.5rem; }
.hint { font-size: 0.8125rem; color: var(--app-text-muted); }
.chooser { list-style: none; margin: 0.75rem 0 0; padding: 0.6rem; display: grid; gap: 0.6rem; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); border: 1px solid var(--app-border); border-radius: 8px; max-height: 280px; overflow-y: auto; }
.chooser-item { width: 100%; padding: 0.3rem; border: 1px solid transparent; border-radius: 6px; background: none; cursor: pointer; text-align: left; }
.chooser-item.current { border-color: var(--color-primary-600); }
.chooser-item img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 4px; display: block; }
.chooser-name { display: block; margin-top: 0.25rem; font-size: 0.6875rem; color: var(--app-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8125rem; border: 1px solid var(--app-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); }
.link-btn { background: none; border: none; color: var(--color-primary-600); cursor: pointer; padding: 0; font: inherit; text-decoration: underline; }
.field-message { margin: 0.35rem 0 0; font-size: 0.8125rem; }
.help-message { color: var(--app-text-muted); }
.error-message { color: var(--color-danger-600, #dc2626); }
</style>
