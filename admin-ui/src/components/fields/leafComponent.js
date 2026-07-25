import FieldInput from './FieldInput.vue';
import ImageField from './ImageField.vue';
import FileField from './FileField.vue';

/**
 * Which editor a single (non-repeating) field is edited with.
 *
 * The section editor, the entry editor and the repeater each need this answer,
 * and each used to carry its own copy of it. Three copies meant a new field type
 * had to be remembered in three places, which is exactly how `file` came to have
 * no picker anywhere: it was added to the schema, given a renderer, and never
 * added to any of the maps — so it silently fell through to a text box and
 * editors were told to paste an opaque id.
 *
 * Repeater is deliberately absent. A repeater inside a repeater is rejected by
 * the schema, so the two container editors test for it themselves before asking
 * this, and importing it here would be a cycle.
 */
export const leafComponent = (field) => {
  if (field?.type === 'image') return ImageField;
  if (field?.type === 'file') return FileField;

  return FieldInput;
};

export default leafComponent;
