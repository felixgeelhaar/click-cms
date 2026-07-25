<template>
  <!--
    A file field, picked from the media library rather than typed.

    Before this existed a `file` field fell through to the generic text input in
    FieldInput, so putting a clip on a page meant going to the Media page,
    pressing "Copy reference", coming back and pasting an opaque id — and a typo
    produced a silently empty section. The library already knows which items are
    video; asking it is the whole fix.
  -->
  <div class="field" :class="{ 'field-error': error }">
    <span class="field-label">
      {{ field.label }}
      <span v-if="field.required" class="required" aria-hidden="true">*</span>
    </span>

    <div v-if="selected" class="selected" data-test="file-selected">
      <span class="badge" aria-hidden="true">▶</span>
      <div class="selected-meta">
        <p class="selected-name">{{ selected.originalName }}</p>
        <!-- No dimensions and no variants: the CMS stores video as uploaded and
             does not transcode it, so the honest readout is the format and the
             weight rather than an empty "×" borrowed from the image card. -->
        <p class="selected-detail">
          {{ formatOf(selected) }} · {{ formatBytes(selected.bytes) }}
        </p>
        <p v-if="heavy(selected)" class="selected-warning">
          This is a large file to send to a visitor. Nothing downloads until they
          press play, but under about 20&nbsp;MB is kinder on a phone.
        </p>
      </div>
      <button type="button" class="btn-sm" @click="clear">Remove</button>
    </div>

    <!-- A reference the library does not know still shows, so a value pasted in
         before this picker existed is recognisable rather than silently blank. -->
    <p v-else-if="modelValue" class="unknown" data-test="file-unknown">
      Using <code>{{ modelValue }}</code>, which is not in the media library.
      <button type="button" class="link-btn" @click="clear">Clear</button>
    </p>

    <div class="picker">
      <button type="button" class="btn-sm" :disabled="loading" @click="open = !open">
        {{ open ? 'Close library' : (selected ? 'Change video' : 'Choose video') }}
      </button>
      <span v-if="loading" class="hint">Loading library…</span>
      <!-- A request that failed is not an empty library: telling somebody to go
           and upload a film they already uploaded hides the real problem. -->
      <span v-else-if="failed" class="hint hint-error" data-test="library-failed">
        The library could not be loaded. Reload the page to try again.
      </span>
      <span v-else-if="!items.length" class="hint">
        No video in the library — upload one on the Media page first.
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
          <span class="chooser-name">{{ item.originalName }}</span>
          <span class="chooser-meta">{{ formatOf(item) }} · {{ formatBytes(item.bytes) }}</span>
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
const failed = ref(false);
const open = ref(false);

const selected = computed(() => items.value.find((i) => i.id === props.modelValue) ?? null);

const formatOf = (item) => (item?.extension || '').toUpperCase() || 'Video';

// Matches the wording the docs give editors: about 20 MB is the point worth
// mentioning, not a limit the server enforces.
const heavy = (item) => Number(item?.bytes) > 20 * 1024 * 1024;

const formatBytes = (bytes) => {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} kB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
};

const choose = (item) => {
  emit('update:modelValue', item.id);
  open.value = false;
};

const clear = () => emit('update:modelValue', '');

onMounted(async () => {
  try {
    // Only video: an image cannot go in a slot that renders a <video>, and the
    // server owns that distinction so every client asks the same question.
    const res = await fetch('/api/media?kind=video');
    // A 401 or a 500 resolves the promise with a body that has no `data`, which
    // would otherwise be indistinguishable from an empty library.
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    items.value = (await res.json()).data ?? [];
  } catch {
    items.value = [];
    failed.value = true;
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
.badge { display: grid; place-items: center; width: 44px; height: 44px; flex-shrink: 0; border-radius: 6px; background: var(--app-surface); color: var(--app-text-muted); font-size: 0.9rem; }
.selected-meta { flex: 1; min-width: 0; }
.selected-name { margin: 0; font-size: 0.875rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.selected-detail { margin: 0.15rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.selected-warning { margin: 0.3rem 0 0; font-size: 0.75rem; color: var(--color-warning-700, #b45309); }
.unknown { margin: 0; padding: 0.6rem; border: 1px dashed var(--app-border); border-radius: 8px; font-size: 0.8125rem; color: var(--app-text-muted); }
.picker { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.5rem; }
.hint { font-size: 0.75rem; color: var(--app-text-muted); }
.hint-error { color: var(--color-danger-600, #dc2626); }
.chooser { list-style: none; margin: 0.75rem 0 0; padding: 0.6rem; display: grid; gap: 0.4rem; border: 1px solid var(--control-border); border-radius: 8px; max-height: 280px; overflow-y: auto; }
.chooser-item { width: 100%; padding: 0.45rem 0.5rem; border: 1px solid transparent; border-radius: 6px; background: none; cursor: pointer; text-align: left; display: grid; gap: 0.1rem; }
.chooser-item.current { border-color: var(--color-primary-600); }
.chooser-item:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; }
.chooser-name { font-size: 0.8125rem; color: var(--app-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chooser-meta { font-size: 0.6875rem; color: var(--app-text-muted); }
.link-btn { background: none; border: 0; padding: 0; color: var(--color-primary-600); cursor: pointer; text-decoration: underline; font: inherit; }
.field-message { margin: 0.35rem 0 0; font-size: 0.75rem; }
.error-message { color: var(--color-danger-600, #dc2626); }
.help-message { color: var(--app-text-muted); }
</style>
