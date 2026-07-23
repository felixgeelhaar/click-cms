<template>
  <section class="section cards">
    <div class="container">
      <h2 v-if="values.heading" class="heading">{{ values.heading }}</h2>
      <div v-if="values.intro" class="rich intro" v-html="values.intro"></div>
      <div class="grid" :style="{ '--cols': columns }">
        <article v-for="(card, i) in cards" :key="i" class="card">
          <img v-if="imageUrl(card.image)" :src="imageUrl(card.image)" :alt="card.title || ''" class="card-img" />
          <h3>{{ card.title }}</h3>
          <p v-if="card.body">{{ card.body }}</p>
          <a v-if="card.link" :href="card.link" class="more">Learn more →</a>
        </article>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { imageUrl as resolve } from '../../api.js';
const props = defineProps({ values: Object, media: Object });
const cards = computed(() => Array.isArray(props.values?.cards) ? props.values.cards : []);
const columns = computed(() => Number(props.values?.columns) || 3);
const imageUrl = (id) => (id ? resolve(props.media, id) : '');
</script>

<style scoped>
.heading { font-size: clamp(1.6rem, 3.4vw, 2.3rem); margin-bottom: 12px; }
.intro { color: var(--muted); margin-bottom: 32px; }
.grid { display: grid; grid-template-columns: repeat(var(--cols, 3), 1fr); gap: 22px; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 26px;
  transition: transform .14s ease, box-shadow .14s ease; }
.card:hover { transform: translateY(-3px); box-shadow: 0 20px 40px -22px rgba(10,16,36,.4); }
.card-img { width: 100%; height: 160px; object-fit: cover; border-radius: 10px; margin-bottom: 16px; }
.card h3 { font-size: 1.2rem; margin-bottom: 8px; }
.card p { margin: 0 0 14px; color: var(--body); font-size: .98rem; }
.more { font-weight: 650; font-size: .95rem; }
@media (max-width: 860px) { .grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
</style>
