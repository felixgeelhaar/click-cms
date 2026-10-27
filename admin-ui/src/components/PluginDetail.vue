<template>
  <div class="plugin-detail">
    <button class="back-btn" @click="emit('back')">{{ message('Back to plugins') }}</button>
    <p v-if="error" role="alert"><LocalizedError :message="error" /></p>
    <p v-else-if="loading">{{ message('Loading…') }}</p>
    <template v-else-if="plugin">
      <h1>{{ plugin.name }} · {{ message('Settings') }}</h1>
      <p v-if="plugin.state !== 'activated'">{{ message('Activate this plugin to configure its settings.') }}</p>
      <PluginSettingsForm v-else-if="plugin.settings" :key="id" :id="id" :schema="plugin.settings" />
      <p v-else>{{ message('This plugin has no settings screen.') }}</p>
    </template>
  </div>
</template>
<script setup>
import { ref, watch } from 'vue';
import { message } from '../lib/messages.js';
import LocalizedError from './LocalizedError.vue';
import PluginSettingsForm from './PluginSettingsForm.vue';
const props = defineProps({ id: { type: String, required: true } });
const emit = defineEmits(['back']);
const plugin = ref(null);
const error = ref('');
const loading = ref(true);
watch(() => props.id, async id => {
  loading.value = true; error.value = ''; plugin.value = null;
  try {
    const response = await fetch(`/api/plugins/${encodeURIComponent(id)}`);
    const body = await response.json();
    if (!response.ok) { error.value = body.error || 'Could not load plugins.'; return; }
    plugin.value = body.data;
  } catch { error.value = 'Could not connect. Please check your connection and try again.'; }
  finally { loading.value = false; }
}, { immediate: true });
</script>
<style scoped>
.plugin-detail { max-width: 800px; }
.back-btn { background: none; border: none; color: var(--color-primary-600); cursor: pointer; margin-bottom: 1rem; font: inherit; padding: .5rem 0; }
h1 { font-size: 1.875rem; font-weight: 700; margin: 0 0 1rem; }
button:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
</style>
