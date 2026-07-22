<template>
  <div class="rich-text" :class="{ invalid }">
    <!-- The toolbar is buttons, not links, and every one has an aria-label:
         the glyphs alone say nothing to a screen reader. type="button" keeps a
         click from submitting the surrounding page form. -->
    <div class="rt-toolbar" role="toolbar" aria-label="Text formatting">
      <button type="button" class="rt-btn" aria-label="Bold" title="Bold" @mousedown.prevent @click="wrapInline('strong')"><b>B</b></button>
      <button type="button" class="rt-btn" aria-label="Italic" title="Italic" @mousedown.prevent @click="wrapInline('em')"><i>I</i></button>
      <button type="button" class="rt-btn" aria-label="Link" title="Add a link" @mousedown.prevent @click="addLink">🔗</button>
      <span class="rt-sep" aria-hidden="true"></span>
      <button type="button" class="rt-btn" aria-label="Heading 2" title="Heading" @mousedown.prevent @click="formatBlock('h2')">H2</button>
      <button type="button" class="rt-btn" aria-label="Heading 3" title="Subheading" @mousedown.prevent @click="formatBlock('h3')">H3</button>
      <button type="button" class="rt-btn" aria-label="Paragraph" title="Paragraph" @mousedown.prevent @click="formatBlock('p')">¶</button>
      <span class="rt-sep" aria-hidden="true"></span>
      <button type="button" class="rt-btn" aria-label="Bullet list" title="Bulleted list" @mousedown.prevent @click="makeList('ul')">•</button>
      <button type="button" class="rt-btn" aria-label="Numbered list" title="Numbered list" @mousedown.prevent @click="makeList('ol')">1.</button>
    </div>

    <!-- contenteditable holds HTML. It is populated from the model imperatively
         rather than with v-html bound to a keystroke, so the caret is not reset
         on every character typed. -->
    <div
      ref="editable"
      class="rt-content"
      contenteditable="true"
      role="textbox"
      aria-multiline="true"
      :aria-describedby="describedby"
      :aria-invalid="invalid ? 'true' : undefined"
      @input="onInput"
      @blur="onInput"
    ></div>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  // Forwarded from the field wrapper for accessibility; optional.
  describedby: { type: String, default: undefined },
  invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const editable = ref(null);

// The tags this editor is allowed to produce. It mirrors the server sanitiser
// deliberately: whatever the editor emits here, the server reduces to the same
// allowlist, so keeping them in step means clean content survives untouched.
const ALLOWED = new Set(['p', 'br', 'strong', 'em', 'b', 'i', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote']);
const VOID = new Set(['br']);
const DROP_WHOLE = new Set(['script', 'style']);

const safeHref = (raw) => {
  const href = (raw || '').trim();
  // A scheme can hide behind control characters — "java\tscript:" runs — so
  // they are stripped before the prefix is checked.
  const probe = href.replace(/[\u0000-\u0020]+/g, '').toLowerCase();
  return /^(https?:|mailto:)/.test(probe) ? href : null;
};

const escapeText = (value) =>
  value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// Client-side mirror of the PHP RichTextSanitizer. This is not the security
// boundary — a direct API call never runs it — but it keeps the model clean:
// contenteditable emits <div>s and stray attributes a browser to browser, and
// nobody wants those written into a page. Disallowed wrappers are unwrapped so
// their text is kept; script/style are dropped whole.
const sanitize = (html) => {
  if (typeof html !== 'string' || html.trim() === '') return '';

  const doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');

  const renderChildren = (node) => {
    let out = '';
    node.childNodes.forEach((child) => { out += renderNode(child); });
    return out;
  };

  const renderNode = (node) => {
    if (node.nodeType === 3) return escapeText(node.textContent);
    if (node.nodeType !== 1) return '';

    const tag = node.tagName.toLowerCase();
    if (DROP_WHOLE.has(tag)) return '';
    if (!ALLOWED.has(tag)) return renderChildren(node);
    if (VOID.has(tag)) return '<' + tag + '>';

    if (tag === 'a') {
      const inner = renderChildren(node);
      const href = safeHref(node.getAttribute('href'));
      if (!href) return inner;
      return '<a href="' + escapeText(href) + '" rel="noopener noreferrer">' + inner + '</a>';
    }

    return '<' + tag + '>' + renderChildren(node) + '</' + tag + '>';
  };

  return renderChildren(doc.body).trim();
};

// Push the model into the editable region only when it genuinely differs from
// what is already shown, so an external change hydrates the editor without a
// self-inflicted reset while typing.
const syncFromModel = () => {
  const el = editable.value;
  if (!el) return;
  const incoming = props.modelValue || '';
  if (sanitize(el.innerHTML) !== sanitize(incoming)) {
    el.innerHTML = incoming;
  }
};

onMounted(syncFromModel);
watch(() => props.modelValue, syncFromModel);

const onInput = () => {
  const el = editable.value;
  if (!el) return;
  emit('update:modelValue', sanitize(el.innerHTML));
};

// --- Formatting commands -------------------------------------------------
//
// Implemented over the Selection/Range API rather than document.execCommand:
// execCommand is deprecated, emits inconsistent markup across browsers, and is
// a no-op under jsdom, which would leave the behaviour untestable. A small
// hand-rolled set is predictable and mirrors exactly the allowlist above.

const currentRange = () => {
  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0) return null;
  const range = sel.getRangeAt(0);
  // Only act on a selection that sits inside this editor.
  return editable.value && editable.value.contains(range.commonAncestorContainer) ? range : null;
};

const reselect = (el) => {
  const sel = window.getSelection();
  const range = document.createRange();
  range.selectNodeContents(el);
  sel.removeAllRanges();
  sel.addRange(range);
};

const wrapInline = (tag, href = null) => {
  const range = currentRange();
  if (!range || range.collapsed) return;

  const wrapper = document.createElement(tag);
  if (tag === 'a' && href) wrapper.setAttribute('href', href);
  wrapper.appendChild(range.extractContents());
  range.insertNode(wrapper);
  reselect(wrapper);
  onInput();
};

const addLink = () => {
  const range = currentRange();
  if (!range || range.collapsed) return;

  const url = window.prompt('Link URL (https://…)');
  const href = safeHref(url);
  // A refused or empty URL leaves the text untouched rather than making a dead
  // or dangerous link.
  if (!href) return;

  wrapInline('a', href);
};

// The block that directly holds the selection within the editor, or null when
// the caret sits in the editor's bare text with no block wrapper yet.
const currentBlock = () => {
  const range = currentRange();
  const root = editable.value;
  if (!range || !root) return null;

  let node = range.startContainer;
  if (node.nodeType === 3) node = node.parentNode;
  while (node && node.parentNode !== root && node !== root) node = node.parentNode;
  return node && node !== root ? node : null;
};

const formatBlock = (tag) => {
  const root = editable.value;
  const range = currentRange();
  if (!root || !range) return;

  const block = currentBlock();
  if (block) {
    const el = document.createElement(tag);
    el.innerHTML = block.innerHTML;
    block.replaceWith(el);
    reselect(el);
  } else {
    // No block wrapper: lift the selection into a fresh one.
    const el = document.createElement(tag);
    el.appendChild(range.extractContents());
    range.insertNode(el);
    reselect(el);
  }
  onInput();
};

const makeList = (tag) => {
  const root = editable.value;
  const range = currentRange();
  if (!root || !range) return;

  const list = document.createElement(tag);
  const item = document.createElement('li');

  const block = currentBlock();
  if (block) {
    item.innerHTML = block.innerHTML;
    list.appendChild(item);
    block.replaceWith(list);
  } else {
    item.appendChild(range.extractContents());
    list.appendChild(item);
    range.insertNode(list);
  }
  reselect(item);
  onInput();
};
</script>

<style scoped>
.rich-text {
  border: 1px solid var(--app-border);
  border-radius: 8px;
  background: var(--app-surface);
  overflow: hidden;
}
.rich-text.invalid { border-color: var(--color-danger-600, #dc2626); }
.rt-toolbar {
  display: flex;
  align-items: center;
  gap: 0.15rem;
  padding: 0.35rem 0.4rem;
  border-bottom: 1px solid var(--app-border);
  background: var(--app-surface-strong);
  flex-wrap: wrap;
}
.rt-btn {
  min-width: 30px;
  height: 30px;
  padding: 0 0.4rem;
  border: 1px solid transparent;
  border-radius: 6px;
  background: transparent;
  color: var(--app-text);
  cursor: pointer;
  font-size: 0.8125rem;
  line-height: 1;
}
.rt-btn:hover { background: var(--app-surface); border-color: var(--app-border); }
.rt-btn:focus-visible { outline: 2px solid var(--color-primary-600, #2563eb); outline-offset: 1px; }
.rt-sep { width: 1px; align-self: stretch; margin: 0.15rem 0.25rem; background: var(--app-border); }
.rt-content {
  min-height: 8rem;
  padding: 0.75rem;
  color: var(--app-text);
  font: inherit;
  line-height: 1.55;
  outline: none;
}
.rt-content:focus { box-shadow: inset 0 0 0 2px var(--color-primary-600, #2563eb); }
.rt-content :deep(h2) { font-size: 1.25rem; margin: 0.5rem 0; }
.rt-content :deep(h3) { font-size: 1.1rem; margin: 0.5rem 0; }
.rt-content :deep(ul),
.rt-content :deep(ol) { padding-left: 1.4rem; margin: 0.5rem 0; }
.rt-content :deep(a) { color: var(--color-primary-600, #2563eb); text-decoration: underline; }
.rt-content :deep(p) { margin: 0.5rem 0; }
.rt-content :deep(blockquote) {
  margin: 0.5rem 0;
  padding-left: 0.85rem;
  border-left: 3px solid var(--app-border);
  color: var(--app-text-muted);
}
</style>
