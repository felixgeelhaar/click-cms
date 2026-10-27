<template>
  <section class="draft-merge" aria-labelledby="draft-merge-title">
    <h2 id="draft-merge-title">{{ message('Combine your edits with the latest saved version') }}</h2>
    <p class="merge-intro"> {{ message('Unchanged fields are taken from the side that changed them. Choose which value to keep for each conflict, then apply the result to your editor. Nothing is saved or published by applying it.') }} </p>

    <p v-if="conflicts.length === 0" class="merge-ready" role="status"> {{ message('The changes do not overlap. Review the result, then apply it to the editor.') }} </p>
    <fieldset v-for="conflict in conflicts" :key="conflict.path" class="merge-conflict">
      <legend>{{ conflict.path }}</legend>
      <p class="conflict-base"><strong>{{ message('Previously saved:') }}</strong> {{ display(conflict.base) }}</p>
      <label>
        <input type="radio" :name="radioName(conflict.path)" value="local" :checked="choices.get(conflict.path) === 'local'" @change="setChoice(conflict.path, 'local')" /> {{ message('Keep your edit:') }} {{ display(conflict.local) }}
      </label>
      <label>
        <input type="radio" :name="radioName(conflict.path)" value="server" :checked="choices.get(conflict.path) === 'server'" @change="setChoice(conflict.path, 'server')" /> {{ message('Use latest saved:') }} {{ display(conflict.server) }}
      </label>
    </fieldset>

    <p v-if="conflicts.length" class="merge-count" role="status">
      {{ resolvedCount }} {{ message('of') }} {{ conflicts.length }} {{ message('conflicts resolved.') }} </p>
    <details class="merge-preview">
      <summary>{{ message('Preview the combined draft values') }}</summary>
      <pre>{{ format(preview) }}</pre>
    </details>
    <button type="button" data-testid="apply-merge" :disabled="!canApply" @click="applyMerge"> {{ message('Apply merged draft to editor') }} </button>
  </section>
</template>

<script setup>
import { message } from "../../lib/messages.js";
import { computed, reactive, watch } from 'vue';
import { mergeThreeWay, resolveThreeWayMerge } from './threeWayMerge.js';

const props = defineProps({
  base: { type: Object, required: true },
  local: { type: Object, required: true },
  server: { type: Object, required: true },
  serverRevision: { type: String, required: true },
});
const emit = defineEmits(['apply']);
const choices = reactive(new Map());
const result = computed(() => mergeThreeWay(props.base, props.local, props.server));
const conflicts = computed(() => result.value.conflicts);
const preview = computed(() => {
  try { return resolveThreeWayMerge(props.base, props.local, props.server, Object.fromEntries(choices)); }
  catch { return result.value.merged; }
});
const resolvedCount = computed(() => conflicts.value.filter(({ path }) => choices.get(path) === 'local' || choices.get(path) === 'server').length);
const canApply = computed(() => !!props.serverRevision && resolvedCount.value === conflicts.value.length);
const radioName = (path) => `merge-${path.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
const display = (value) => {
  if (value === undefined) return '(removed)';
  if (typeof value === 'string') return value || '(empty)';
  if (value === null) return '(empty)';
  return JSON.stringify(value);
};
const format = (value) => JSON.stringify(value, null, 2);

watch(() => [props.base, props.local, props.server, props.serverRevision], () => choices.clear(), { deep: true });

const setChoice = (path, choice) => choices.set(path, choice);

const applyMerge = () => {
  if (!canApply.value) return;
  const page = resolveThreeWayMerge(props.base, props.local, props.server, Object.fromEntries(choices));
  emit('apply', { page, revision: props.serverRevision });
};
</script>

<style scoped>
.draft-merge { margin: 1rem 0; padding: 1rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface-strong); }
h2 { margin: 0; font-size: 1rem; }
.merge-intro, .merge-count, .merge-ready { color: var(--app-text-muted); font-size: .86rem; }
.merge-conflict { display: grid; gap: .5rem; margin: .8rem 0; padding: .75rem; border: 1px solid var(--app-border); border-radius: 6px; background: var(--app-surface); }
.merge-conflict legend { padding: 0 .25rem; font-weight: 700; overflow-wrap: anywhere; }
.merge-conflict label { display: flex; align-items: flex-start; gap: .5rem; overflow-wrap: anywhere; }
.merge-conflict input { flex: 0 0 auto; margin-top: .2rem; }
.conflict-base { margin: 0; color: var(--app-text-muted); overflow-wrap: anywhere; }
.merge-preview { margin: .8rem 0; }
.merge-preview summary { cursor: pointer; font-weight: 600; }
.merge-preview pre { max-height: 20rem; overflow: auto; padding: .7rem; border-radius: 6px; background: var(--app-surface); white-space: pre-wrap; overflow-wrap: anywhere; }
button { border: 0; border-radius: 7px; padding: .55rem .85rem; background: var(--color-primary-700); color: white; font: inherit; font-weight: 600; cursor: pointer; }
button:disabled { opacity: .55; cursor: not-allowed; }
button:focus-visible, input:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 3px; }
</style>
