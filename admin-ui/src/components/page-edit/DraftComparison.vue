<template>
  <section class="draft-comparison" :aria-label="message(&quot;Draft comparison&quot;)">
    <h2>{{ message(heading) }}</h2>
    <p v-if="changes.length === 0">{{ message('There are no differences.') }}</p>
    <ul v-else>
      <li v-for="change in changes" :key="change.path">
        <strong>{{ change.path }}</strong>
        <span class="values"><del>{{ change.before }}</del><span aria-hidden="true"> → </span><ins>{{ change.after }}</ins></span>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { message } from "../../lib/messages.js";
import { computed } from 'vue';
import { comparePageDrafts } from './pageComparison.js';

const props = defineProps({ before: { type: Object, required: true }, after: { type: Object, required: true }, heading: { type: String, default: 'Changes since the saved page' } });
const changes = computed(() => comparePageDrafts(props.before, props.after));
</script>

<style scoped>
.draft-comparison { margin-top: 1rem; padding: 1rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface-strong); }
h2 { font-size: 1rem; margin: 0 0 .75rem; }
ul { display: grid; gap: .75rem; margin: 0; padding-left: 1.25rem; }
li { min-width: 0; }
.values { display: block; overflow-wrap: anywhere; margin-top: .2rem; }
del { color: var(--app-text-muted); }
ins { color: var(--app-text); text-decoration: none; }
</style>
