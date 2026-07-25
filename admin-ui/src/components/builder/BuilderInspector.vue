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

        <template v-else-if="node.type === 'columns'">
          <label class="field">
            <span>Columns</span>
            <input
              type="number"
              min="1"
              :max="MAX_COLUMNS"
              class="columns-count"
              :value="node.props.count"
              @input="ctx.setColumnCount(node.id, $event.target.value)"
            />
          </label>
          <p class="field-note">Reducing the count removes the last columns and anything inside them.</p>
          <template v-if="ctx.breakpoints.value.length">
            <label class="field">
              <span>Side by side from</span>
              <select :value="node.props.stackAt || 'sm'" @change="prop('stackAt', $event.target.value)">
                <option v-for="bp in ctx.breakpoints.value" :key="bp.id" :value="bp.id">
                  {{ bp.label || bp.id }} ({{ bp.minWidth }}px)
                </option>
              </select>
            </label>
            <p class="field-note">Below that width the columns stack, one above the other.</p>
          </template>
          <p v-else class="field-note">This page declares no breakpoints, so the columns go side by side from 640px and stack below it.</p>
        </template>

        <template v-else-if="node.type === 'video'">
          <label class="field">
            <span>Video URL</span>
            <input type="text" placeholder="/media/clip.mp4" :value="node.props.src" @input="prop('src', $event.target.value)" />
          </label>
          <label class="field">
            <span>Poster image</span>
            <input type="text" :value="node.props.poster" @input="prop('poster', $event.target.value)" />
          </label>
          <label class="field">
            <span>Captions (.vtt)</span>
            <input type="text" :value="node.props.captions" @input="prop('captions', $event.target.value)" />
          </label>
          <label class="field">
            <span>Description for screen readers</span>
            <input type="text" :value="node.props.label" @input="prop('label', $event.target.value)" />
          </label>
          <label class="field-check">
            <input type="checkbox" :checked="node.props.controls !== false" @change="prop('controls', $event.target.checked)" />
            <span>Show controls</span>
          </label>
          <label class="field-check">
            <input type="checkbox" :checked="!!node.props.autoplay" @change="prop('autoplay', $event.target.checked)" />
            <span>Play automatically</span>
          </label>
          <p v-if="node.props.autoplay" class="field-note">An autoplaying video is always muted and looped — browsers refuse to start one with sound.</p>
        </template>

        <template v-else-if="node.type === 'embed'">
          <label class="field">
            <span>URL</span>
            <input type="text" placeholder="https://www.youtube.com/watch?v=…" :value="node.props.url" @input="prop('url', $event.target.value)" />
          </label>
          <label class="field">
            <span>Title (read out by screen readers)</span>
            <input type="text" :value="node.props.title" @input="prop('title', $event.target.value)" />
          </label>
          <label class="field">
            <span>Height in pixels (0 for 16:9)</span>
            <input type="number" min="0" max="2000" :value="node.props.height" @input="prop('height', clampInt($event.target.value, 0, 2000))" />
          </label>
          <p class="field-note">YouTube, Vimeo, OpenStreetMap and Google Maps links become embeds. Anything else is published as a plain link.</p>
        </template>

        <template v-else-if="node.type === 'list'">
          <label class="field-check">
            <input type="checkbox" :checked="!!node.props.ordered" @change="prop('ordered', $event.target.checked)" />
            <span>Numbered list</span>
          </label>
          <label class="field">
            <span>Items — one per line</span>
            <textarea rows="4" :value="itemsText" @input="prop('items', parseItems($event.target.value))"></textarea>
          </label>
        </template>

        <template v-else-if="node.type === 'quote'">
          <label class="field">
            <span>Quote</span>
            <textarea rows="3" :value="node.props.text" @input="prop('text', $event.target.value)"></textarea>
          </label>
          <label class="field">
            <span>Attributed to</span>
            <input type="text" :value="node.props.attribution" @input="prop('attribution', $event.target.value)" />
          </label>
          <label class="field">
            <span>Source title</span>
            <input type="text" :value="node.props.source" @input="prop('source', $event.target.value)" />
          </label>
          <label class="field">
            <span>Source URL</span>
            <input type="text" :value="node.props.cite" @input="prop('cite', $event.target.value)" />
          </label>
        </template>

        <template v-else-if="node.type === 'divider'">
          <label class="field">
            <span>Line</span>
            <select :value="node.props.lineStyle || 'solid'" @change="prop('lineStyle', $event.target.value)">
              <option value="solid">Solid</option>
              <option value="dashed">Dashed</option>
              <option value="dotted">Dotted</option>
              <option value="double">Double</option>
            </select>
          </label>
          <label class="field">
            <span>Thickness in pixels</span>
            <input type="number" min="1" max="20" :value="node.props.thickness" @input="prop('thickness', clampInt($event.target.value, 1, 20))" />
          </label>
          <label class="field">
            <span>Colour</span>
            <input type="text" placeholder="currentColor" :value="node.props.color" @input="prop('color', $event.target.value)" />
          </label>
        </template>

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
import { MAX_COLUMNS } from './model.js';

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

// Same trade as the chart data below: a list is an array for the renderer but a
// block of lines to edit, so nobody has to type JSON to write three bullets.
const itemsText = computed(() => (node.value?.props?.items || []).join('\n'));

function parseItems(text) {
  return String(text)
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '');
}

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
.field-check { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
.field-check input { width: auto; }
.field-check span { font-size: 0.8125rem; color: var(--app-text); }
.field-note { font-size: 0.8125rem; color: var(--app-text-muted); margin: 0 0 0.75rem; }
.field-note:last-child { margin-bottom: 0; }
</style>
