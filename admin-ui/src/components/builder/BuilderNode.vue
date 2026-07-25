<template>
  <!--
    Every node is one selectable, draggable box. The click and dragstart both
    stopPropagation so selecting a child does not also select its parent, and
    dragging a child does not start a drag of the whole section around it.

    All editor-supplied text reaches the DOM through Vue's {{ }} interpolation
    and :attr bindings, both of which escape — there is deliberately no v-html
    anywhere in this file, so a page title of `<script>` renders as text.
  -->
  <div
    class="bnode"
    :class="{ selected: isSelected, container: isContainer, [`drop-${dropEdge}`]: !!dropEdge }"
    :data-node-type="node.type"
    draggable="true"
    @click.stop="ctx.select(node.id)"
    @dragstart.stop="onDragStart"
    @dragend="onDragEnd"
    @dragover.prevent.stop="onDragOver"
    @dragleave="dropEdge = ''"
    @drop.prevent.stop="onDrop"
  >
    <div class="bnode-tag">
      <span class="bnode-name">{{ node.type }}</span>
      <button
        v-if="node.id !== rootId"
        type="button"
        class="bnode-remove"
        :aria-label="`Remove ${node.type}`"
        @click.stop="ctx.removeNode(node.id)"
      >×</button>
    </div>

    <!-- Container: draw its children, or an explicit empty hint so an empty
         section is still a visible, selectable drop target rather than a
         zero-height line nobody can hit. -->
    <template v-if="isContainer">
      <div class="bnode-children" :style="containerStyle">
        <BuilderNode
          v-for="childId in node.children"
          :key="childId"
          :node="ctx.nodes.value[childId]"
          :root-id="rootId"
        />
        <p v-if="node.children.length === 0" class="bnode-empty">Empty {{ node.type }} — select it and add a node, or drop one here</p>
      </div>
    </template>

    <!-- Leaves render a faithful-enough preview of what the server emits. -->
    <p v-else-if="node.type === 'text'" class="leaf-text" :style="node.styles">{{ node.props.text }}</p>

    <template v-else-if="node.type === 'image'">
      <img v-if="node.props.src" :src="node.props.src" :alt="node.props.alt" class="leaf-image" :style="node.styles" />
      <div v-else class="leaf-placeholder" :style="node.styles">Image — set a source in the inspector</div>
    </template>

    <span v-else-if="node.type === 'button'" class="leaf-button" :style="node.styles">{{ node.props.label || 'Button' }}</span>

    <div v-else-if="node.type === 'spacer'" class="leaf-spacer" :style="{ height: node.styles.height || '32px' }">spacer</div>

    <div v-else-if="node.type === 'chart'" class="leaf-chart" :style="node.styles">
      <strong>{{ node.props.title || 'Chart' }}</strong>
      <span class="leaf-chart-meta">{{ node.props.chartType || 'bar' }} · {{ (node.props.data || []).length }} points</span>
    </div>

    <!-- preload="none" so a canvas full of clips costs nothing to open. -->
    <template v-else-if="node.type === 'video'">
      <video
        v-if="node.props.src"
        class="leaf-video"
        :style="node.styles"
        :src="node.props.src"
        :poster="node.props.poster || undefined"
        preload="none"
        controls
        muted
        playsinline
      ></video>
      <div v-else class="leaf-placeholder" :style="node.styles">Video — set a source in the inspector</div>
    </template>

    <!--
      An embed is previewed as a description, never as a live iframe: loading a
      third-party frame into the admin would hand it a seat inside an
      authenticated session for no editorial benefit.
    -->
    <div v-else-if="node.type === 'embed'" class="leaf-placeholder" :style="node.styles">
      <template v-if="node.props.url">Embed — {{ embedProvider }}</template>
      <template v-else>Embed — paste a YouTube, Vimeo or map link</template>
    </div>

    <component
      :is="node.props.ordered ? 'ol' : 'ul'"
      v-else-if="node.type === 'list'"
      class="leaf-list"
      :style="node.styles"
    >
      <li v-for="(item, index) in node.props.items || []" :key="index">{{ item }}</li>
    </component>

    <figure v-else-if="node.type === 'quote'" class="leaf-quote" :style="node.styles">
      <blockquote><p>{{ node.props.text }}</p></blockquote>
      <figcaption v-if="node.props.attribution">
        {{ node.props.attribution }}
        <cite v-if="node.props.source">{{ node.props.source }}</cite>
      </figcaption>
    </figure>

    <hr v-else-if="node.type === 'divider'" class="leaf-divider" :style="dividerStyle" />

    <div v-else class="leaf-placeholder">{{ node.type }}</div>
  </div>
</template>

<script setup>
import { computed, inject, ref } from 'vue';
import { isContainer as isContainerType } from './model.js';

const props = defineProps({
  node: { type: Object, required: true },
  rootId: { type: String, required: true },
});

// The single controller Builder.vue provides. Injected rather than threaded
// through props because this component recurses arbitrarily deep and passing
// the same six callbacks down every level would be noise.
const ctx = inject('builderCtx');

const isSelected = computed(() => ctx.selectedId.value === props.node.id);
const isContainer = computed(() => isContainerType(props.node.type));

// A grid's column count lives in props (what the server reads) but the canvas
// has to preview it, so it is projected into gridTemplateColumns here the same
// way bootstrap.php does when rendering the public page.
//
// A columns node is drawn side by side even though the published page stacks it
// below its breakpoint: the canvas is a desktop-width surface, so showing the
// stacked form would misrepresent what most visitors see.
const containerStyle = computed(() => {
  const style = { ...props.node.styles };
  if (props.node.type === 'grid' && props.node.props.columns) {
    style.display = style.display || 'grid';
    style.gridTemplateColumns = `repeat(${Number(props.node.props.columns) || 1}, minmax(0, 1fr))`;
  }
  if (props.node.type === 'columns') {
    style.display = style.display || 'grid';
    const count = Number(props.node.props.count) || props.node.children.length || 1;
    style.gridTemplateColumns = `repeat(${count}, minmax(0, 1fr))`;
  }
  return style;
});

// Mirrors dividerStyles() on the server so the canvas shows the same rule.
const dividerStyle = computed(() => ({
  border: 0,
  borderTop: `${Number(props.node.props.thickness) || 1}px ${props.node.props.lineStyle || 'solid'} ${props.node.props.color || 'currentColor'}`,
  ...props.node.styles,
}));

// Named from the URL alone. The editor deliberately does not reimplement the
// server's allowlist — it only hints, and bootstrap.php remains the one place
// that decides what actually becomes an iframe.
const embedProvider = computed(() => {
  const url = String(props.node.props.url || '');
  if (/youtube\.com|youtu\.be/.test(url)) return 'YouTube';
  if (/vimeo\.com/.test(url)) return 'Vimeo';
  if (/openstreetmap\.org|google\.[a-z.]+\/maps/.test(url)) return 'map';
  return 'published as a link (unrecognised provider)';
});

/* ----------------------------------------------------- drag & drop -- */

// Which edge the pointer is over, so the drop lands before/after a sibling or
// inside a container. Held locally per node; the dragged id lives on the shared
// controller so any node can be the drop target.
const dropEdge = ref('');

function onDragStart(event) {
  ctx.dragId.value = props.node.id;
  // Some browsers require data to be set for a drag to begin at all.
  event.dataTransfer?.setData('text/plain', props.node.id);
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function onDragEnd() {
  ctx.dragId.value = '';
  dropEdge.value = '';
}

function onDragOver(event) {
  if (!ctx.dragId.value || ctx.dragId.value === props.node.id) return;
  const rect = event.currentTarget.getBoundingClientRect();
  const offset = event.clientY - rect.top;
  // A container's middle band accepts an "inside" drop; its top and bottom
  // thirds, like every leaf, mean "as a sibling before/after me".
  if (isContainer.value && offset > rect.height * 0.33 && offset < rect.height * 0.67) {
    dropEdge.value = 'inside';
  } else {
    dropEdge.value = offset < rect.height / 2 ? 'before' : 'after';
  }
}

function onDrop() {
  const dragId = ctx.dragId.value;
  const edge = dropEdge.value;
  dropEdge.value = '';
  if (!dragId || !edge) return;
  ctx.moveNode(dragId, props.node.id, edge);
  ctx.dragId.value = '';
}
</script>

<style scoped>
.bnode { position: relative; border: 1px dashed transparent; border-radius: 6px; padding: 0.35rem; cursor: pointer; }
.bnode:hover { border-color: var(--app-border); }
.bnode.selected { border-color: var(--color-primary-600); border-style: solid; }
.bnode.container { padding-top: 1.4rem; }
.bnode-tag { position: absolute; top: -0.1rem; left: 0.35rem; display: none; align-items: center; gap: 0.35rem; font-size: 0.7rem; }
.bnode:hover > .bnode-tag, .bnode.selected > .bnode-tag { display: flex; }
.bnode-name { background: var(--app-surface-strong); color: var(--app-text-muted); padding: 0 0.35rem; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.03em; }
.bnode-remove { border: none; background: var(--color-danger-600, #dc2626); color: white; width: 1.1rem; height: 1.1rem; line-height: 1; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
.bnode-children { display: flex; flex-direction: column; gap: 0.5rem; min-height: 1.5rem; }
.bnode-empty { margin: 0; padding: 0.75rem; text-align: center; color: var(--app-text-muted); font-size: 0.8125rem; background: var(--app-surface-strong); border-radius: 6px; }
.bnode.drop-before { box-shadow: 0 -3px 0 var(--color-primary-600); }
.bnode.drop-after { box-shadow: 0 3px 0 var(--color-primary-600); }
.bnode.drop-inside { border-color: var(--color-primary-600); border-style: solid; background: var(--app-surface-strong); }
.leaf-text { margin: 0; }
.leaf-image { max-width: 100%; display: block; }
.leaf-placeholder { padding: 1rem; text-align: center; color: var(--app-text-muted); background: var(--app-surface-strong); border-radius: 6px; font-size: 0.8125rem; }
.leaf-button { display: inline-block; padding: 0.5rem 1rem; background: var(--color-primary-600); color: white; border-radius: 6px; font-size: 0.875rem; }
.leaf-spacer { display: flex; align-items: center; justify-content: center; color: var(--app-text-muted); background: repeating-linear-gradient(45deg, var(--app-surface-strong), var(--app-surface-strong) 6px, transparent 6px, transparent 12px); border-radius: 6px; font-size: 0.75rem; }
.leaf-chart { display: flex; flex-direction: column; gap: 0.25rem; padding: 1rem; background: var(--app-surface-strong); border-radius: 6px; }
.leaf-chart-meta { font-size: 0.75rem; color: var(--app-text-muted); }
.leaf-video { max-width: 100%; display: block; }
.leaf-list { margin: 0; padding-left: 1.25rem; }
.leaf-quote { margin: 0; }
.leaf-quote blockquote { margin: 0; font-style: italic; }
.leaf-quote figcaption { font-size: 0.8125rem; color: var(--app-text-muted); margin-top: 0.25rem; }
.leaf-divider { margin: 0.5rem 0; }
</style>
