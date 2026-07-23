<template>
  <div class="inspector">
    <h2 class="panel-title">Inspector</h2>

    <p v-if="!node" class="inspector-empty">Select a node on the canvas to edit it.</p>

    <template v-else>
      <p class="inspector-type">{{ node.type }}</p>

      <!-- ------------------------------------------- content (props) -- -->
      <fieldset class="inspector-group">
        <legend>Content</legend>

        <label v-if="node.type === 'text'" class="field">
          <span>Text</span>
          <textarea rows="3" :value="node.props.text" @input="prop('text', $event.target.value)"></textarea>
        </label>

        <template v-else-if="node.type === 'image'">
          <label class="field">
            <span>Source URL</span>
            <input type="text" :value="node.props.src" @input="prop('src', $event.target.value)" />
          </label>
          <label class="field">
            <span>Alt text</span>
            <input type="text" :value="node.props.alt" @input="prop('alt', $event.target.value)" />
          </label>
        </template>

        <template v-else-if="node.type === 'button'">
          <label class="field">
            <span>Label</span>
            <input type="text" :value="node.props.label" @input="prop('label', $event.target.value)" />
          </label>
          <label class="field">
            <span>Link (href)</span>
            <input type="text" :value="node.props.href" @input="prop('href', $event.target.value)" />
          </label>
        </template>

        <label v-else-if="node.type === 'grid'" class="field">
          <span>Columns</span>
          <input type="number" min="1" max="12" :value="node.props.columns" @input="prop('columns', clampInt($event.target.value, 1, 12))" />
        </label>

        <template v-else-if="node.type === 'chart'">
          <label class="field">
            <span>Chart type</span>
            <select :value="node.props.chartType" @change="prop('chartType', $event.target.value)">
              <option value="bar">Bar</option>
              <option value="line">Line</option>
            </select>
          </label>
          <label class="field">
            <span>Title</span>
            <input type="text" :value="node.props.title" @input="prop('title', $event.target.value)" />
          </label>
          <label class="field">
            <span>Colour</span>
            <input type="text" :value="node.props.color" @input="prop('color', $event.target.value)" />
          </label>
          <label class="field">
            <span>Data — one "Label, Value" per line</span>
            <textarea rows="4" :value="dataText" @input="prop('data', parseChartData($event.target.value))"></textarea>
          </label>
        </template>

        <p v-else class="field-note">This node has no content of its own — arrange nodes inside it and style it below.</p>
      </fieldset>

      <!-- --------------------------------------------- basic styles -- -->
      <fieldset class="inspector-group">
        <legend>Style</legend>

        <label class="field">
          <span>Background</span>
          <input type="text" placeholder="#ffffff" :value="node.styles.background" @input="style('background', $event.target.value)" />
        </label>
        <label class="field">
          <span>Text colour</span>
          <input type="text" placeholder="#111827" :value="node.styles.color" @input="style('color', $event.target.value)" />
        </label>
        <label class="field">
          <span>Padding</span>
          <input type="text" placeholder="24px" :value="node.styles.padding" @input="style('padding', $event.target.value)" />
        </label>
        <label class="field">
          <span>Margin</span>
          <input type="text" placeholder="0" :value="node.styles.margin" @input="style('margin', $event.target.value)" />
        </label>
        <label v-if="node.type === 'spacer'" class="field">
          <span>Height</span>
          <input type="text" placeholder="32px" :value="node.styles.height" @input="style('height', $event.target.value)" />
        </label>
        <label class="field">
          <span>Text align</span>
          <select :value="node.styles.textAlign || ''" @change="style('textAlign', $event.target.value)">
            <option value="">—</option>
            <option value="left">Left</option>
            <option value="center">Center</option>
            <option value="right">Right</option>
          </select>
        </label>
      </fieldset>
    </template>
  </div>
</template>

<script setup>
import { computed, inject } from 'vue';

const ctx = inject('builderCtx');

const node = computed(() => {
  const id = ctx.selectedId.value;
  return id ? ctx.nodes.value[id] ?? null : null;
});

const prop = (key, value) => ctx.updateProp(node.value.id, key, value);
const style = (key, value) => ctx.updateStyle(node.value.id, key, value);

const clampInt = (raw, min, max) => {
  const n = parseInt(raw, 10);
  if (Number.isNaN(n)) return min;
  return Math.min(max, Math.max(min, n));
};

// Chart data is stored as [{label, value}] for the renderer, but edited as
// plain lines so an author needs no JSON. The two conversions are kept adjacent
// so the round-trip stays lossless for well-formed input.
const dataText = computed(() =>
  (node.value?.props?.data || [])
    .map((point) => `${point.label ?? ''}, ${point.value ?? ''}`)
    .join('\n')
);

function parseChartData(text) {
  return String(text)
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
    .map((line) => {
      const comma = line.lastIndexOf(',');
      if (comma === -1) return { label: line, value: 0 };
      const label = line.slice(0, comma).trim();
      const value = Number(line.slice(comma + 1).trim()) || 0;
      return { label, value };
    });
}
</script>

<style scoped>
.inspector { padding: 1rem; }
.panel-title { font-size: 0.9rem; font-weight: 700; margin: 0 0 0.5rem; color: var(--app-text); }
.inspector-empty { font-size: 0.8125rem; color: var(--app-text-muted); }
.inspector-type { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-text-muted); margin: 0 0 0.75rem; }
.inspector-group { border: 1px solid var(--app-border); border-radius: 8px; padding: 0.75rem; margin: 0 0 1rem; }
.inspector-group legend { font-size: 0.75rem; font-weight: 600; padding: 0 0.35rem; color: var(--app-text); }
.field { display: block; margin-bottom: 0.75rem; }
.field:last-child { margin-bottom: 0; }
.field span { display: block; font-size: 0.75rem; color: var(--app-text-muted); margin-bottom: 0.25rem; }
.field input, .field textarea, .field select { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid var(--app-border); border-radius: 6px; background: var(--app-surface); color: var(--app-text); font: inherit; font-size: 0.8125rem; }
.field textarea { resize: vertical; }
.field-note { font-size: 0.8125rem; color: var(--app-text-muted); margin: 0; }
</style>
