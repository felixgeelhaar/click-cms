<template>
  <section class="responsive-preview" aria-label="Responsive page preview">
    <div class="preview-toolbar">
      <div>
        <h2>Page preview</h2>
        <p>{{ busy ? 'Refreshing unsaved changes…' : 'Changes refresh here while you edit.' }}</p>
      </div>
      <div class="device-controls" role="group" aria-label="Preview width">
        <button v-for="device in devices" :key="device.id" type="button" :aria-pressed="width === device.id" @click="width = device.id">{{ device.label }}</button>
        <button type="button" :disabled="busy" @click="$emit('refresh')">Refresh</button>
      </div>
    </div>
    <div class="preview-stage">
      <p v-if="error" class="preview-error" role="alert">{{ error }}</p>
      <iframe v-else-if="html" :srcdoc="html" title="Rendered page preview" sandbox="" :style="{ width: widths[width] }" />
      <p v-else class="preview-empty">{{ busy ? 'Rendering preview…' : 'Waiting for preview…' }}</p>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue';

defineProps({ html: { type: String, default: '' }, busy: { type: Boolean, default: false }, error: { type: String, default: '' } });
defineEmits(['refresh']);
const devices = [{ id: 'mobile', label: 'Mobile' }, { id: 'tablet', label: 'Tablet' }, { id: 'desktop', label: 'Desktop' }];
const widths = { mobile: '375px', tablet: '768px', desktop: '100%' };
const width = ref('desktop');
</script>

<style scoped>
.responsive-preview { margin-top: 1.25rem; border: 1px solid var(--app-border); border-radius: 8px; overflow: hidden; }
.preview-toolbar { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .9rem 1rem; background: var(--app-surface-strong); }
h2 { margin: 0; font-size: 1rem; }
p { margin: .2rem 0 0; color: var(--app-text-muted); font-size: .8rem; }
.device-controls { display: flex; flex-wrap: wrap; gap: .4rem; }
.device-controls button { border: 1px solid var(--control-border); border-radius: 6px; padding: .4rem .6rem; background: var(--app-surface); color: var(--app-text); cursor: pointer; }
.device-controls button[aria-pressed="true"] { border-color: var(--focus-ring, #0f766e); box-shadow: inset 0 0 0 1px var(--focus-ring, #0f766e); }
.device-controls button:focus-visible { outline: 2px solid var(--focus-ring, #0f766e); outline-offset: 2px; }
.preview-stage { display: flex; justify-content: center; min-height: 520px; padding: 1rem; overflow: auto; background: #e5e7eb; }
iframe { min-height: 520px; flex: 0 0 auto; border: 1px solid #9ca3af; background: white; transition: width .15s ease; }
.preview-error, .preview-empty { margin: auto; color: var(--app-text-muted); }
@media (max-width: 640px) { .preview-toolbar { align-items: flex-start; flex-direction: column; } .preview-stage { justify-content: flex-start; } }
</style>
