<template>
  <section class="section cta-wrap">
    <div class="container">
      <div class="cta">
        <h2 v-if="values.heading">{{ values.heading }}</h2>
        <p v-if="values.body">{{ values.body }}</p>
        <a v-if="values.buttonLabel" :href="values.buttonUrl || '#'" class="btn on-navy">{{ values.buttonLabel }}</a>
      </div>
    </div>
  </section>
</template>

<script setup>
defineProps({ values: Object, media: Object });
</script>

<style scoped>
.cta { position: relative; overflow: hidden; border-radius: 24px; padding: 64px 40px; text-align: center;
  background: radial-gradient(120% 140% at 50% -20%, var(--navy-2), var(--navy)); }
.cta::after { content: ""; position: absolute; inset: 0; opacity: .5;
  background: radial-gradient(60% 60% at 80% 120%, color-mix(in srgb, var(--electric) 55%, transparent), transparent 70%); }
.cta > * { position: relative; }
.cta h2 { color: #fff; font-size: clamp(1.7rem, 3.6vw, 2.4rem); margin-bottom: 12px; }
.cta p { color: var(--on-navy); max-width: 52ch; margin: 0 auto 26px; font-size: 1.08rem; }
</style>
