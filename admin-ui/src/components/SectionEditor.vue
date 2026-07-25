<template>
  <div class="section-editor">
    <div class="editor-head">
      <h2 class="editor-title">Sections</h2>
      <p class="editor-subtitle">
        Add sections to build the page. Each one uses a design the site provides.
      </p>
    </div>

    <p v-if="typesError" class="banner error" role="alert">{{ typesError }}</p>

    <p v-else-if="loadingTypes" class="banner">Loading section types…</p>

    <p v-else-if="types.length === 0" class="banner">
      This site has not declared any section types yet, so there is nothing to add.
    </p>

    <!-- Warnings surface malformed definitions instead of hiding them, so an
         author notices a typo rather than wondering where a type went. -->
    <div v-if="Object.keys(typeWarnings).length" class="banner warning">
      <strong>Some section types could not be loaded:</strong>
      <ul>
        <li v-for="(message, file) in typeWarnings" :key="file">{{ file }} — {{ message }}</li>
      </ul>
    </div>

    <ol v-if="sections.length" class="section-list">
      <li
        v-for="(section, index) in sections"
        :key="section._uid"
        class="section-item"
        :class="{ dragging: dragIndex === index, 'drag-over': overIndex === index }"
        :aria-grabbed="dragIndex === index ? 'true' : 'false'"
        @dragover="onDragOver(index, $event)"
        @drop="onDrop(index)"
      >
        <header class="section-head">
          <div class="section-identity">
            <!-- Pointer-only, and hidden on purpose: a role-less <span> may not
                 carry aria-label, and this handle takes no focus. The labelled
                 arrow buttons opposite are the keyboard path. -->
            <span
              class="drag-handle"
              draggable="true"
              aria-hidden="true"
              @dragstart="onDragStart(index)"
              @dragend="onDragEnd"
            >⠿</span>
            <span class="section-index">{{ index + 1 }}</span>
            <div>
              <p class="section-type">{{ labelFor(section.type) }}</p>
              <!--
                Only claim a design is gone when we actually know the catalogue.
                `typeFor()` is null both for a type that was removed and for one
                we simply failed to fetch, and reporting the second as the first
                told an editor their page's design "is no longer declared" when
                nothing was wrong with it but a request.
              -->
              <p v-if="!typeFor(section.type) && catalogueKnown" class="section-missing">
                Unknown section type “{{ section.type }}” — its design is no longer declared.
              </p>
              <p v-else-if="!typeFor(section.type)" class="section-unavailable">
                The fields for this section cannot be shown until the section
                designs load. Your content is safe — reload the page to try again.
              </p>
            </div>
          </div>

          <div class="section-controls">
            <button
              type="button" class="icon-btn" :disabled="index === 0"
              :aria-label="`Move section ${index + 1} up`" @click="move(index, -1)"
            >↑</button>
            <button
              type="button" class="icon-btn" :disabled="index === sections.length - 1"
              :aria-label="`Move section ${index + 1} down`" @click="move(index, 1)"
            >↓</button>
            <button
              type="button" class="icon-btn"
              :aria-label="`${section._collapsed ? 'Expand' : 'Collapse'} section ${index + 1}`"
              @click="toggle(index)"
            >{{ section._collapsed ? '▸' : '▾' }}</button>
            <button
              type="button" class="icon-btn danger"
              :aria-label="`Remove section ${index + 1}`" @click="remove(index)"
            >✕</button>
          </div>
        </header>

        <div v-if="!section._collapsed" class="section-body">
          <template v-if="typeFor(section.type)">
            <component
              :is="fieldComponent(field)"
              v-for="field in typeFor(section.type).fields"
              :key="field.name"
              :field="field"
              :model-value="section.values[field.name]"
              :error="errorFor(index, field.name)"
              @update:model-value="setValue(index, field.name, $event)"
            />
          </template>

          <!-- A section whose type has been removed keeps its stored values so
               deleting a definition cannot silently destroy content. Shown only
               once we know the type is genuinely gone: dumping JSON at an editor
               because a request failed reads as "your page is broken". -->
          <pre v-else-if="catalogueKnown" class="orphan-values">{{ JSON.stringify(section.values, null, 2) }}</pre>
        </div>
      </li>
    </ol>

    <div class="add-row">
      <label class="add-label" for="section-type-picker">Add a section</label>
      <div class="add-controls">
        <select id="section-type-picker" v-model="pendingType" :disabled="types.length === 0">
          <option value="">Choose a design…</option>
          <option v-for="type in types" :key="type.id" :value="type.id">{{ type.label }}</option>
        </select>
        <button type="button" class="btn-primary" :disabled="!pendingType" @click="add">Add</button>
      </div>
      <p v-if="pendingDescription" class="add-description">{{ pendingDescription }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import FieldInput from './fields/FieldInput.vue';
import RepeaterField from './fields/RepeaterField.vue';
import ImageField from './fields/ImageField.vue';
import { moveItem, useDragReorder } from '../lib/dragReorder.js';

const props = defineProps({
  // [{ type: 'card-grid', values: { ... } }]
  modelValue: { type: Array, default: () => [] },
  // { '0.heading': 'Heading is required.' } — server-side validation errors.
  errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const types = ref([]);
const typeWarnings = ref({});
const loadingTypes = ref(true);
const typesError = ref('');
const pendingType = ref('');

// Local copy carrying UI-only keys (_uid, _collapsed). They are stripped before
// emitting so they never reach the API or storage.
const sections = ref([]);
let uid = 0;

const hydrate = (value) => {
  sections.value = (value ?? []).map((section) => ({
    _uid: ++uid,
    _collapsed: false,
    type: section.type,
    values: { ...(section.values ?? {}) },
  }));
};

hydrate(props.modelValue);

// Re-hydrate only when the parent supplies a genuinely different set, so typing
// does not reset the editor on every keystroke.
watch(
  () => props.modelValue,
  (next) => {
    if (JSON.stringify(strip()) !== JSON.stringify(next ?? [])) hydrate(next);
  }
);

const strip = () =>
  sections.value.map((section) => ({ type: section.type, values: section.values }));

const commit = () => emit('update:modelValue', strip());

const typeFor = (id) => types.value.find((t) => t.id === id) ?? null;

/**
 * Whether the set of declared designs is actually known.
 *
 * False while the catalogue is loading and false if it failed, so nothing
 * downstream mistakes "we could not ask" for "the answer is no".
 */
const catalogueKnown = computed(() => !loadingTypes.value && !typesError.value);
const labelFor = (id) => typeFor(id)?.label ?? id;

const fieldComponent = (field) => {
  if (field.type === 'repeater') return RepeaterField;
  if (field.type === 'image') return ImageField;
  return FieldInput;
};

const pendingDescription = computed(() => typeFor(pendingType.value)?.description ?? '');

const errorFor = (index, name) => props.errors?.[`${index}.${name}`] ?? '';

const setValue = (index, name, value) => {
  sections.value[index].values = { ...sections.value[index].values, [name]: value };
  commit();
};

const add = () => {
  const type = typeFor(pendingType.value);
  if (!type) return;

  const values = {};
  for (const field of type.fields) {
    if (field.default !== undefined) values[field.name] = field.default;
    if (field.type === 'repeater') values[field.name] = [];
  }

  sections.value.push({ _uid: ++uid, _collapsed: false, type: type.id, values });
  pendingType.value = '';
  commit();
};

const remove = (index) => {
  sections.value.splice(index, 1);
  commit();
};

const move = (index, delta) => {
  const target = index + delta;
  if (target < 0 || target >= sections.value.length) return;
  sections.value = moveItem(sections.value, index, target);
  commit();
};

// Drag-and-drop performs the same reorder as the arrow buttons, by pointer. The
// UI-only keys (_uid, _collapsed) ride along with each item and strip() drops
// them, so the emitted payload keeps its { type, values } shape, just reordered.
const reorder = (from, to) => {
  sections.value = moveItem(sections.value, from, to);
  commit();
};
const {
  dragIndex,
  overIndex,
  start: onDragStart,
  over: onDragOver,
  drop: onDrop,
  end: onDragEnd,
} = useDragReorder(reorder);

const toggle = (index) => {
  sections.value[index]._collapsed = !sections.value[index]._collapsed;
};

onMounted(async () => {
  try {
    const res = await fetch('/api/section-types');
    if (!res.ok) throw new Error(`Request failed (${res.status})`);

    const body = await res.json();
    types.value = body.data ?? [];
    typeWarnings.value = body.warnings ?? {};
  } catch (e) {
    typesError.value = `Could not load section types: ${e.message}`;
  } finally {
    loadingTypes.value = false;
  }
});
</script>

<style scoped>
.section-editor { margin-top: 2rem; }
.editor-head { margin-bottom: 1rem; }
.editor-title { font-size: 1.125rem; font-weight: 600; margin: 0; }
.editor-subtitle { margin: 0.25rem 0 0; font-size: 0.875rem; color: var(--app-text-muted); }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.warning ul { margin: 0.35rem 0 0; padding-left: 1.1rem; }
.section-list { list-style: none; margin: 0 0 1.5rem; padding: 0; display: grid; gap: 1rem; }
.section-item { border: 1px solid var(--app-border); border-radius: 10px; background: var(--card-bg); }
.section-item.dragging { opacity: 0.5; }
.section-item.drag-over { border-color: var(--color-primary-600); }
.section-head { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; border-bottom: 1px solid var(--app-border); }
.section-identity { display: flex; align-items: center; gap: 0.75rem; }
.drag-handle { cursor: grab; user-select: none; color: var(--app-text-muted); line-height: 1; }
.drag-handle:active { cursor: grabbing; }
.section-index { width: 26px; height: 26px; display: grid; place-items: center; border-radius: 6px; background: var(--app-surface-strong); font-size: 0.75rem; font-weight: 600; }
.section-type { margin: 0; font-weight: 600; font-size: 0.9375rem; }
.section-missing { margin: 0.15rem 0 0; font-size: 0.8125rem; color: var(--color-danger-600, #dc2626); }
.section-controls { display: flex; gap: 0.25rem; }
.icon-btn { width: 28px; height: 28px; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); line-height: 1; }
.icon-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.icon-btn.danger { color: var(--color-danger-600, #dc2626); }
.icon-btn:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.section-body { padding: 1rem; }
.section-unavailable { margin: 0.25rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.orphan-values { margin: 0; padding: 0.75rem; background: var(--app-surface-strong); border-radius: 8px; font-size: 0.8125rem; overflow-x: auto; }
.add-row { border: 1px dashed var(--app-border); border-radius: 10px; padding: 1rem; }
.add-label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; }
.add-controls { display: flex; gap: 0.75rem; }
.add-controls select { flex: 1; padding: 0.625rem 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.add-controls select:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }
.add-description { margin: 0.5rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.btn-primary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
</style>
