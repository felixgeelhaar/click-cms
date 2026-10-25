<template>
  <div class="section-editor">
    <div class="editor-head">
      <h2 class="editor-title">Sections</h2>
      <p class="editor-subtitle">
        Add sections to build the page. Each one uses a design the site provides.
      </p>
    </div>

    <section class="template-tools" aria-label="Starter templates">
      <label for="section-template-picker">Starter template</label>
      <div class="template-row">
        <select id="section-template-picker" v-model="selectedTemplateId" :disabled="templates.length === 0">
          <option value="">{{ templates.length ? 'Choose a saved starter…' : 'No saved starters yet' }}</option>
          <option v-for="template in templates" :key="template.selectionId" :value="template.selectionId">
            {{ template.name }}{{ template.source === 'shared' ? ` · shared · v${template.currentVersion}` : template.source === 'starter' ? ` · approved starter · v${template.currentVersion}` : '' }}
          </option>
        </select>
        <button type="button" data-testid="replace-shared-preset" :disabled="!adoptableTemplate" @click="applyTemplate('replace')">Replace sections</button>
        <button type="button" data-testid="append-shared-preset" :disabled="!adoptableTemplate" @click="applyTemplate('append')">Append sections</button>
        <button
          type="button" class="icon-btn danger"
          :disabled="busySharedPreset || !selectedTemplate || selectedTemplate.source === 'builtin' || selectedTemplate.source === 'starter' || (selectedTemplate.source === 'shared' && !canManagePresets)"
          :aria-label="`Delete starter ${selectedTemplate?.name ?? ''}`" @click="removeTemplate"
        >Delete</button>
      </div>
      <p v-if="isSharedPreset(selectedTemplate)" class="template-help">
        Version {{ selectedTemplate.currentVersion }} · {{ selectedTemplate.versions?.length ?? 1 }} retained versions.
        Adoption copies these sections into this page; later preset edits do not change this page.
      </p>
      <div v-if="isSharedPreset(selectedTemplate)" class="template-history">
        <label for="shared-preset-version">Adopt retained version</label>
        <select id="shared-preset-version" v-model.number="selectedPresetVersion" :disabled="loadingPresetVersion || busySharedPreset">
          <option v-for="version in [...(selectedTemplate.versions ?? [])].reverse()" :key="version.version" :value="version.version">
            v{{ version.version }} · {{ version.updatedAt }} · {{ version.updatedBy }}
          </option>
        </select>
        <p v-if="loadingPresetVersion" class="template-help">Loading version…</p>
        <p v-else-if="selectedSnapshot" class="template-help">Preview of v{{ selectedSnapshot.version }}: {{ selectedSnapshot.sections.length }} sections.</p>
        <ul v-if="selectedSnapshot" class="template-preview" aria-label="Selected preset version preview">
          <li v-for="(section, index) in selectedSnapshot.sections" :key="`${index}-${section.type}`">
            {{ index + 1 }}. {{ labelFor(section.type) }}
          </li>
        </ul>
        <p v-if="selectedSnapshotError" class="template-notice" role="alert">{{ selectedSnapshotError }}</p>
      </div>
      <p v-if="newerVersionAvailable" class="template-notice" role="status">Your adopted v{{ appliedSharedVersions[selectedTemplate.id] }} is older. Select v{{ selectedTemplate.currentVersion }} above, then Append or Replace to adopt it.</p>
      <p v-if="selectedSnapshot && !selectedSnapshot.compatible" class="template-notice" role="alert">This snapshot no longer matches the site's section schema and cannot be adopted. {{ Object.values(selectedSnapshot.compatibilityErrors ?? {}).join(' ') }}</p>
      <div class="template-row">
        <span class="template-help">Shared library</span>
        <button type="button" data-testid="refresh-shared-presets" :disabled="loadingSharedPresets" @click="loadSharedPresets">{{ loadingSharedPresets ? 'Refreshing…' : 'Refresh library' }}</button>
      </div>
      <p v-if="sharedPresetError" class="template-notice" role="status">{{ sharedPresetError }}</p>
      <form v-if="canManagePresets" class="template-row" @submit.prevent="selectedTemplate?.source === 'shared' && selectedTemplate.editable !== false ? updateSharedPreset() : createSharedPreset()">
        <label class="sr-only" for="shared-preset-name">Shared preset name</label>
        <input id="shared-preset-name" v-model="sharedPresetName" maxlength="80" placeholder="Name a shared preset" required>
        <button v-if="selectedTemplate?.source === 'shared' && selectedTemplate.editable !== false" type="submit" data-testid="update-shared-preset" :disabled="busySharedPreset || !sections.length">Save new version</button>
        <button v-else type="submit" data-testid="create-shared-preset" :disabled="busySharedPreset || !sections.length">Create shared preset</button>
      </form>
      <form class="template-row" @submit.prevent="saveTemplate">
        <label class="sr-only" for="section-template-name">Save current sections as a starter</label>
        <input id="section-template-name" v-model="templateName" maxlength="80" placeholder="Name this page starter" required>
        <button type="submit" :disabled="sections.length === 0 || !templateScope?.account || !templateScope?.site">Save personal starter</button>
      </form>
      <p class="template-help">Personal starters stay in this browser and are scoped to your account and site. Shared presets are versioned for this site.</p>
      <p v-if="templateNotice" class="template-notice" role="status">{{ templateNotice }}</p>
    </section>

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
import RepeaterField from './fields/RepeaterField.vue';
import { leafComponent } from './fields/leafComponent.js';
import { moveItem, useDragReorder } from '../lib/dragReorder.js';
import { cloneTemplateSections, deleteSectionTemplate, listSectionTemplates, saveSectionTemplate } from '../lib/sectionTemplates.js';
import businessSiteStarter from '../data/businessSiteStarter.json';

const props = defineProps({
  // [{ type: 'card-grid', values: { ... } }]
  modelValue: { type: Array, default: () => [] },
  // { '0.heading': 'Heading is required.' } — server-side validation errors.
  errors: { type: Object, default: () => ({}) },
  templateScope: { type: Object, default: null },
  canManagePresets: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const types = ref([]);
const typeWarnings = ref({});
const loadingTypes = ref(true);
const typesError = ref('');
const pendingType = ref('');
const templates = ref([]);
const sharedPresets = ref([]);
const selectedTemplateId = ref('');
const templateName = ref('');
const sharedPresetName = ref('');
const templateNotice = ref('');
const sharedPresetError = ref('');
const loadingSharedPresets = ref(true);
const busySharedPreset = ref(false);
const selectedPresetVersion = ref(null);
const historicalSnapshot = ref(null);
const loadingPresetVersion = ref(false);
const selectedSnapshotError = ref('');
const appliedSharedVersions = ref({});
const selectedTemplate = computed(() => templates.value.find((item) => item.selectionId === selectedTemplateId.value) ?? null);
const isSharedPreset = (template) => template?.source === 'shared' || template?.source === 'starter';
const selectedSnapshot = computed(() => !isSharedPreset(selectedTemplate.value)
  ? selectedTemplate.value
  : selectedPresetVersion.value === selectedTemplate.value.currentVersion
    ? { ...selectedTemplate.value, version: selectedTemplate.value.currentVersion }
    : historicalSnapshot.value);
const adoptableTemplate = computed(() => selectedSnapshot.value?.compatible && !loadingPresetVersion.value && !selectedSnapshotError.value);
const newerVersionAvailable = computed(() => selectedTemplate.value?.source === 'shared'
  && appliedSharedVersions.value[selectedTemplate.value.id] !== undefined
  && selectedTemplate.value.currentVersion > appliedSharedVersions.value[selectedTemplate.value.id]);
const refreshTemplates = () => {
  templates.value = [
    ...(!loadingSharedPresets.value && !sharedPresets.value.some((item) => item.id === 'starter-business-site')
      ? [{ ...businessSiteStarter, selectionId: `builtin:${businessSiteStarter.id}`, source: 'builtin', compatible: true }]
      : []),
    ...listSectionTemplates(props.templateScope).map((item) => ({ ...item, selectionId: `personal:${item.id}`, source: 'personal', compatible: true })),
    ...sharedPresets.value.map((item) => ({ ...item, selectionId: `${item.editable === false ? 'starter' : 'shared'}:${item.id}`, source: item.editable === false ? 'starter' : 'shared' })),
  ];
};
refreshTemplates();
watch(() => props.templateScope, () => { refreshTemplates(); loadSharedPresets(); }, { deep: true });
watch(selectedTemplate, (template) => {
  sharedPresetName.value = isSharedPreset(template) ? template.name : '';
  selectedPresetVersion.value = isSharedPreset(template) ? template.currentVersion : null;
  historicalSnapshot.value = null;
  selectedSnapshotError.value = '';
});
watch(selectedPresetVersion, (version) => {
  const template = selectedTemplate.value;
  historicalSnapshot.value = null;
  selectedSnapshotError.value = '';
  loadingPresetVersion.value = false;
  if (isSharedPreset(template) && version && version !== template.currentVersion) loadPresetVersion(template, version);
});

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

  return leafComponent(field);
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

const loadSharedPresets = async () => {
  loadingSharedPresets.value = true;
  sharedPresetError.value = '';
  try {
    const response = await fetch('/api/section-presets');
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
    sharedPresets.value = Array.isArray(body.data) ? body.data : [];
    refreshTemplates();
  } catch (error) {
    sharedPresetError.value = error.message.includes('401') || error.message.toLowerCase().includes('sign in')
      ? 'Sign in to load shared presets.'
      : `Could not load shared presets: ${error.message}`;
  } finally {
    loadingSharedPresets.value = false;
    refreshTemplates();
  }
};

const loadPresetVersion = async (template, version) => {
  loadingPresetVersion.value = true;
  const selectedId = template.id;
  try {
    const response = await fetch(`/api/section-presets/${encodeURIComponent(selectedId)}/versions/${version}`);
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
    if (selectedTemplate.value?.id !== selectedId || selectedPresetVersion.value !== version) return;
    historicalSnapshot.value = body.data;
  } catch (error) {
    if (selectedTemplate.value?.id === selectedId && selectedPresetVersion.value === version) {
      selectedSnapshotError.value = `Could not load version: ${error.message}`;
    }
  } finally {
    if (selectedTemplate.value?.id === selectedId && selectedPresetVersion.value === version) loadingPresetVersion.value = false;
  }
};

const applyTemplate = (mode = 'replace') => {
  if (!adoptableTemplate.value) return;
  const template = selectedSnapshot.value;
  const name = selectedTemplate.value.name;
  const snapshot = cloneTemplateSections(template);
  const adopted = mode === 'append' ? [...strip(), ...snapshot] : snapshot;
  hydrate(adopted);
  commit();
  if (selectedTemplate.value.source === 'shared') appliedSharedVersions.value = { ...appliedSharedVersions.value, [selectedTemplate.value.id]: template.version };
  templateNotice.value = `Applied “${name}”${template.version ? ` v${template.version}` : ''}. Adopted a snapshot (${mode}); later library edits do not change this page.`;
};

const saveTemplate = () => {
  try {
    const saved = saveSectionTemplate(templateName.value, strip(), props.templateScope);
    refreshTemplates();
    selectedTemplateId.value = `personal:${saved.id}`;
    templateName.value = '';
    templateNotice.value = `Saved “${saved.name}”.`;
  } catch (error) {
    templateNotice.value = error.message;
  }
};

const createSharedPreset = async () => {
  if (!props.canManagePresets) return;
  await mutateSharedPreset('/api/section-presets', 'POST', { name: sharedPresetName.value, sections: strip() }, 'Created shared preset');
};

const updateSharedPreset = async () => {
  const template = selectedTemplate.value;
  if (!props.canManagePresets || template?.source !== 'shared' || template.editable === false) return;
  await mutateSharedPreset(`/api/section-presets/${encodeURIComponent(template.id)}`, 'PUT', {
    name: sharedPresetName.value,
    sections: strip(),
    expectedVersion: template.currentVersion,
  }, 'Saved new shared preset version');
};

const mutateSharedPreset = async (url, method, payload, success) => {
  busySharedPreset.value = true;
  try {
    const response = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      await loadSharedPresets();
      throw new Error(body.error || `Request failed (${response.status})`);
    }
    if (body.data?.id) selectedTemplateId.value = `shared:${body.data.id}`;
    await loadSharedPresets();
    templateNotice.value = `${success} “${payload.name}”. The current page keeps its own snapshot until you adopt a version.`;
  } catch (error) {
    templateNotice.value = error.message;
  } finally {
    busySharedPreset.value = false;
  }
};

const removeTemplate = async () => {
  if (!selectedTemplate.value) return;
  const template = selectedTemplate.value;
  const name = template.name;
  if (template.source === 'builtin' || template.source === 'starter') return;
  if (template.source === 'personal') {
    deleteSectionTemplate(template.id, props.templateScope);
    refreshTemplates();
  } else if (template.source === 'shared' && props.canManagePresets) {
    busySharedPreset.value = true;
    try {
      const response = await fetch(`/api/section-presets/${encodeURIComponent(template.id)}`, {
        method: 'DELETE', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ expectedVersion: template.currentVersion }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
      await loadSharedPresets();
    } catch (error) {
      templateNotice.value = error.message;
      await loadSharedPresets();
      return;
    } finally {
      busySharedPreset.value = false;
    }
  } else return;
  selectedTemplateId.value = '';
  templateNotice.value = `Deleted “${name}”.`;
};

onMounted(async () => {
  loadSharedPresets();
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
.template-tools { display: grid; gap: 0.5rem; padding: 0.9rem; border: 1px solid var(--app-border); border-radius: 10px; }
.template-tools > label { font-weight: 600; }
.template-row { display: flex; gap: 0.5rem; align-items: center; }
.template-history { display: grid; gap: 0.35rem; padding: 0.65rem; border: 1px solid var(--app-border); border-radius: 8px; }
.template-history > label { font-weight: 600; }
.template-history select { width: 100%; padding: 0.5rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.template-preview { margin: 0; padding-left: 1.25rem; color: var(--app-text-muted); font-size: 0.875rem; }
.template-row select, .template-row input { flex: 1; min-width: 0; padding: 0.55rem 0.65rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.template-row button { padding: 0.55rem 0.8rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); cursor: pointer; }
.template-row button:disabled { opacity: 0.5; cursor: not-allowed; }
.template-notice { margin: 0; color: var(--app-text-muted); font-size: 0.875rem; }
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
