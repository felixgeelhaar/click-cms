<template>
  <div class="field" :class="{ 'field-error': error }">
    <label :for="inputId" class="field-label">
      {{ field.label }}
      <span v-if="field.required" class="required" aria-hidden="true">*</span>
    </label>

    <!-- Rich text is HTML by design: a small contenteditable editor with a
         formatting toolbar. Its value is sanitised on the server before it is
         ever rendered into a page, so the editor's own cleanup is convenience,
         not the security boundary. -->
    <RichTextField
      v-if="field.type === 'richtext'"
      :model-value="modelValue ?? ''"
      :describedby="describedBy"
      :invalid="!!error"
      @update:model-value="emitValue($event)"
    />

    <!-- Plain long-form prose stays a textarea; it is escaped, not rendered as
         markup, so it needs no editor. -->
    <textarea
      v-else-if="field.type === 'textarea'"
      :id="inputId"
      :value="modelValue ?? ''"
      :rows="4"
      :aria-describedby="describedBy"
      :aria-invalid="error ? 'true' : undefined"
      @input="emitValue($event.target.value)"
    ></textarea>

    <select
      v-else-if="field.type === 'select'"
      :id="inputId"
      :value="modelValue ?? ''"
      :aria-describedby="describedBy"
      :aria-invalid="error ? 'true' : undefined"
      @change="emitValue($event.target.value)"
    >
      <option value="">— none —</option>
      <option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
    </select>

    <label v-else-if="field.type === 'boolean'" class="switch">
      <input
        :id="inputId"
        type="checkbox"
        :checked="modelValue === true"
        :aria-describedby="describedBy"
        @change="emitValue($event.target.checked)"
      />
      <span>{{ modelValue ? 'Yes' : 'No' }}</span>
    </label>

    <!-- Images and files carry a reference the site resolves, so this is a text
         input until a media library exists to pick from. -->
    <input
      v-else
      :id="inputId"
      :type="htmlType"
      :value="modelValue ?? ''"
      :min="field.type === 'number' ? field.min : undefined"
      :max="field.type === 'number' ? field.max : undefined"
      :aria-describedby="describedBy"
      :aria-invalid="error ? 'true' : undefined"
      @input="emitValue($event.target.value)"
    />

    <p v-if="error" :id="`${inputId}-error`" class="field-message error-message">{{ error }}</p>
    <p v-else-if="field.help" :id="`${inputId}-help`" class="field-message help-message">{{ field.help }}</p>
  </div>
</template>

<script setup>
import { computed, useId } from 'vue';
import RichTextField from './RichTextField.vue';

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: { type: null, default: null },
  error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const uid = useId();
const inputId = computed(() => `field-${props.field.name}-${uid}`);

const describedBy = computed(() => {
  if (props.error) return `${inputId.value}-error`;
  return props.field.help ? `${inputId.value}-help` : undefined;
});

const htmlType = computed(() => ({
  number: 'number',
  url: 'url',
  email: 'email',
  date: 'date',
}[props.field.type] || 'text'));

// Numbers are emitted as numbers rather than strings so what the editor sees
// matches what the API will validate.
const emitValue = (raw) => {
  if (props.field.type === 'number') {
    emit('update:modelValue', raw === '' ? null : Number(raw));
    return;
  }
  emit('update:modelValue', raw);
};
</script>

<style scoped>
.field { margin-bottom: 1.25rem; }
.field-label { display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.875rem; color: var(--app-text); }
.required { color: var(--color-danger-600, #dc2626); margin-left: 0.15rem; }
input[type="text"], input[type="number"], input[type="url"], input[type="email"], input[type="date"], textarea, select {
  width: 100%; padding: 0.625rem 0.75rem; border: 1px solid var(--app-border);
  border-radius: 8px; background: var(--app-surface); color: var(--app-text);
  font: inherit;
}
textarea { resize: vertical; }
.field-error input, .field-error textarea, .field-error select { border-color: var(--color-danger-600, #dc2626); }
.switch { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
.switch input { width: auto; }
.field-message { margin: 0.35rem 0 0; font-size: 0.8125rem; }
.help-message { color: var(--app-text-muted); }
.error-message { color: var(--color-danger-600, #dc2626); }
</style>
