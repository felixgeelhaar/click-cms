<template>
  <!--
    A list of short lines — the features of a plan, what a service includes.

    A textarea, one line per item, because that is how people already write
    lists and it is what they paste in from elsewhere. The alternative is a
    stack of single-line inputs with add and remove buttons, which is more
    machinery to look at and slower to fill in for the one case this exists for.
  -->
  <textarea
    :id="inputId"
    class="lines"
    :rows="rows"
    :value="text"
    :aria-describedby="describedby"
    :aria-invalid="invalid ? 'true' : undefined"
    @input="emitLines($event.target.value)"
  ></textarea>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  /** The stored value: a list of lines. A string is tolerated because that is
   *  what this data was before the field type existed. */
  modelValue: { type: [Array, String], default: () => [] },
  inputId: { type: String, default: '' },
  describedby: { type: String, default: undefined },
  invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const lines = computed(() => {
  if (Array.isArray(props.modelValue)) return props.modelValue;
  return typeof props.modelValue === 'string' && props.modelValue !== ''
    ? props.modelValue.split('\n')
    : [];
});

const text = computed(() => lines.value.join('\n'));

// Grows a little with the content, so a long list is not edited through a
// three-line window.
const rows = computed(() => Math.min(Math.max(lines.value.length + 1, 3), 12));

/**
 * Emitted unfiltered, blank lines included.
 *
 * Dropping empties here would delete the line the moment somebody pressed
 * Enter to start the next one, which makes the field unusable. The server
 * drops them on save, which is the right moment: the editor is finished by
 * then.
 */
const emitLines = (raw) => emit('update:modelValue', raw.split('\n'));
</script>

<style scoped>
.lines {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid var(--control-border);
  border-radius: 8px;
  background: var(--app-surface);
  color: var(--app-text);
  font: inherit;
  line-height: 1.6;
  resize: vertical;
}
.lines:focus-visible {
  outline: 2px solid var(--focus-ring);
  outline-offset: 1px;
  border-color: var(--focus-ring);
}
</style>
