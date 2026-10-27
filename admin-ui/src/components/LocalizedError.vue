<template>
  <span :lang="messageLanguage">
    {{ translated.text }}
    <template v-if="translated.technical">
      <button type="button" class="diagnostic-toggle" :aria-expanded="expanded" @click="expanded = !expanded">{{ expanded ? 'Details schließen' : 'Technische Details' }}</button>
      <span v-if="expanded" class="diagnostic" lang="en">{{ translated.technical }}</span>
    </template>
  </span>
</template>
<script setup>
import { computed, ref, watch } from 'vue';
import { errorTranslation, messageLanguage } from '../lib/messages.js';
const props = defineProps({ message: { default: '' } });
const expanded = ref(false);
const translated = computed(() => errorTranslation(props.message));
watch(() => props.message, () => { expanded.value = false; });
</script>
<style scoped>
.diagnostic-toggle { background: transparent; color: inherit; border: 0; text-decoration: underline; cursor: pointer; font: inherit; }
.diagnostic-toggle:focus-visible { outline: 2px solid var(--focus-ring); }
.diagnostic { display: block; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
