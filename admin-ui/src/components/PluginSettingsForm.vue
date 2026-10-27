<template>
  <section class="plugin-settings" :aria-labelledby="`settings-${id}`">
    <h2 :id="`settings-${id}`">{{ message(schema.title || 'Settings') }}</h2>
    <p v-if="schema.description">{{ message(schema.description) }}</p>
    <p v-if="error" role="alert" class="email-error"><LocalizedError :message="error" /></p>
    <p v-if="notice" role="status">{{ message(notice) }}</p>
    <form v-if="validSchema" @submit.prevent="save">
      <fieldset :disabled="loading || busy !== ''">
        <template v-for="field in fields" :key="field.name">
          <label v-if="field.type === 'checkbox'" class="email-enabled"><input v-model="settings[field.name]" type="checkbox" /> {{ message(field.label) }}</label>
          <template v-else>
            <label :for="fieldId(field)">{{ message(field.label) }}</label>
            <select v-if="field.type === 'select'" :id="fieldId(field)" v-model="settings[field.name]">
              <option v-for="option in field.options" :key="option.value" :value="option.value">{{ message(option.label) }}</option>
            </select>
            <input v-else :id="fieldId(field)" v-model="settings[field.name]" :type="field.type" :maxlength="field.maxLength || 254" :required="field.required === true || Boolean(settings[field.requiredWhen])" :aria-describedby="field.help ? `${fieldId(field)}-help` : undefined" />
          </template>
          <small v-if="field.help" :id="`${fieldId(field)}-help`">{{ message(field.help) }}</small>
        </template>
        <p v-if="schema.privacyHelp">{{ message(schema.privacyHelp) }}</p>
        <div class="email-actions">
          <button class="btn-primary" type="submit">{{ message(busy === 'save' ? 'Saving…' : (schema.saveLabel || 'Save')) }}</button>
          <button v-if="actionEndpoint" type="button" :disabled="dirty || (schema.action.requires || []).some(key => !settings[key])" @click="runAction">{{ message(busy === 'action' ? (schema.action.busyLabel || 'Loading…') : schema.action.label) }}</button>
        </div>
        <small v-if="dirty && schema.action?.dirtyHelp">{{ message(schema.action.dirtyHelp) }}</small>
      </fieldset>
    </form>
    <p v-if="schema.help" class="email-help">{{ message(schema.help) }}</p>
  </section>
</template>
<script setup>
import { computed, onMounted, ref } from 'vue';
import { message } from '../lib/messages.js';
import LocalizedError from './LocalizedError.vue';
const props = defineProps({ id: { type: String, required: true }, schema: { type: Object, required: true } });
// A manifest can describe fields, never markup, scripts or arbitrary request targets.
const endpointFor = value => typeof value === 'string' && new RegExp(`^/api/${props.id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/[a-zA-Z0-9_/-]+$`).test(value) && !value.includes('..') && !value.includes('//') ? value : '';
const endpoint = computed(() => endpointFor(props.schema.endpoint));
const actionEndpoint = computed(() => endpointFor(props.schema.action?.endpoint));
const fields = computed(() => (Array.isArray(props.schema.fields) ? props.schema.fields : []).filter(field => field && /^[a-z][a-zA-Z0-9_]{0,63}$/.test(field.name) && !['constructor', 'prototype', '__proto__'].includes(field.name) && ['text', 'email', 'checkbox', 'select'].includes(field.type)));
const validSchema = computed(() => endpoint.value && fields.value.length > 0);
const settings = ref(Object.fromEntries(fields.value.map(field => [field.name, field.default ?? (field.type === 'checkbox' ? false : '')])));
const saved = ref('');
const dirty = computed(() => JSON.stringify(settings.value) !== saved.value);
const loading = ref(true);
const busy = ref('');
const error = ref('');
const notice = ref('');
const fallback = 'Could not connect. Please check your connection and try again.';
const fieldId = field => `plugin-${props.id}-${field.name}`;
function accept(data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('Invalid settings response');
  settings.value = Object.fromEntries(fields.value.map(field => [field.name, data[field.name] ?? settings.value[field.name]]));
  saved.value = JSON.stringify(settings.value);
}
onMounted(async () => {
  if (!validSchema.value) { error.value = 'This plugin has an invalid settings definition.'; loading.value = false; return; }
  try {
    const response = await fetch(endpoint.value);
    const body = await response.json();
    if (!response.ok) { error.value = body.error || fallback; return; }
    accept(body.data);
  } catch { error.value = fallback; }
  finally { loading.value = false; }
});
async function save() {
  busy.value = 'save'; error.value = ''; notice.value = '';
  try {
    const response = await fetch(endpoint.value, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(settings.value) });
    const body = await response.json();
    if (!response.ok) { error.value = body.error || fallback; return; }
    accept(body.data);
    notice.value = props.schema.savedMessage || 'Settings saved.';
  } catch { error.value = fallback; }
  finally { busy.value = ''; }
}
async function runAction() {
  busy.value = 'action'; error.value = ''; notice.value = '';
  try {
    const response = await fetch(actionEndpoint.value, { method: 'POST' });
    const body = await response.json();
    if (!response.ok) { error.value = body.error || fallback; return; }
    notice.value = props.schema.action.successMessage || 'Done.';
  } catch { error.value = fallback; }
  finally { busy.value = ''; }
}
</script>
<style scoped>
.plugin-settings { padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--app-border); border-radius: 10px; }
h2 { font-size: 1.0625rem; margin: 0 0 .5rem; }
p, small { font-size: .875rem; color: var(--app-text-muted); line-height: 1.5; }
fieldset { padding: 0; border: 0; min-width: 0; }
label { display: block; margin: 1rem 0 .35rem; font-weight: 500; }
input:not([type=checkbox]), select { display: block; box-sizing: border-box; width: 100%; max-width: 30rem; padding: .6rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
small { display: block; margin-top: .35rem; }
.email-enabled { display: flex; align-items: center; gap: .5rem; }
.email-actions { display: flex; gap: .75rem; flex-wrap: wrap; }
button { padding: .6rem 1rem; border-radius: 8px; border: 1px solid var(--control-border); background: var(--app-surface); color: var(--app-text); font: inherit; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border-color: transparent; }
button:disabled, fieldset:disabled { opacity: .65; cursor: not-allowed; }
:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: 2px; }
.email-error { color: var(--color-danger-600); }
.email-help { margin-bottom: 0; }
</style>
