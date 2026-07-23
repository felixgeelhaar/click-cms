<template>
  <component
    v-for="(section, i) in sections"
    :is="componentFor(section.type)"
    :key="i"
    :values="section.values || {}"
    :media="media"
    class="fade-up"
  />
</template>

<script setup>
// Maps each click-cms section type to a TurboScience-branded component. A type
// the front end does not know how to render is skipped rather than breaking the
// page — the CMS can ship a new section design before the front end supports it.
import RichText from './sections/RichText.vue';
import Facts from './sections/Facts.vue';
import CardGrid from './sections/CardGrid.vue';
import CallToAction from './sections/CallToAction.vue';
import MediaText from './sections/MediaText.vue';

const MAP = {
  'rich-text': RichText,
  facts: Facts,
  'card-grid': CardGrid,
  'call-to-action': CallToAction,
  'media-text': MediaText,
};

defineProps({
  sections: { type: Array, default: () => [] },
  media: { type: Object, default: () => ({}) },
});

const componentFor = (type) => MAP[type] || null;
</script>
