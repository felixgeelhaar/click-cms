<template>
  <!--
    Who else is on this page right now. Presence has no "leave" event to trust —
    a browser can close without saying goodbye — so the bar shows exactly the
    roster the last poll returned, which the server has already pruned of anyone
    gone stale. Every name is rendered with text interpolation only, never
    v-html: a display name is account data, but the rule that shown text is inert
    text holds everywhere in this UI without exception.
  -->
  <section
    v-if="others.length"
    class="presence"
    aria-label="Editors currently on this page"
  >
    <span class="presence-label">Editing now</span>
    <ul class="presence-list">
      <li v-for="editor in others" :key="editor.user" class="presence-editor" :title="editor.name">
        <span class="presence-avatar" aria-hidden="true">{{ initial(editor.name) }}</span>
        <span class="presence-name">{{ editor.name }}</span>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
  // The page slug and language being edited — presence is per page and per
  // language, because those are separate documents an editor works on separately.
  page: { type: String, required: true },
  locale: { type: String, default: '' },
  // The signed-in account's username, so the bar can show *others* rather than
  // reflecting the viewer back at themselves. Optional: without it, everyone on
  // the page is shown.
  currentUser: { type: String, default: '' },
  // Poll cadence. The default matches the plugin's ~10s heartbeat: the server
  // treats a beat as present for 30s (three missed beats), so 10s keeps the
  // roster live without a socket. Presence is not latency-sensitive — nobody is
  // co-typing — so a few seconds of lag is invisible and a socket buys nothing.
  pollInterval: { type: Number, default: 10000 },
});

const editors = ref([]);
let timer = null;

const others = computed(() =>
  editors.value.filter((e) => !props.currentUser || e.user !== props.currentUser),
);

const initial = (name) => (name || '?').trim().charAt(0).toUpperCase() || '?';

/**
 * One poll: a heartbeat that says "I am still here" and, in the same response,
 * the current roster — so a single request per tick both refreshes and reads
 * presence. A failed poll leaves the last known roster in place rather than
 * blanking the bar on a transient blip.
 */
const tick = async () => {
  if (!props.page) return;
  try {
    const res = await fetch('/api/collaboration/presence', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ page: props.page, locale: props.locale }),
    });
    if (!res.ok) return;
    const body = await res.json();
    editors.value = Array.isArray(body.data?.editors) ? body.data.editors : [];
  } catch {
    // Keep the last roster; a dropped poll is not evidence anyone left.
  }
};

const start = () => {
  stop();
  tick();
  timer = setInterval(tick, props.pollInterval);
};

const stop = () => {
  if (timer !== null) {
    clearInterval(timer);
    timer = null;
  }
};

// Re-beat against the new document when the editor switches page or language,
// so the bar never shows the previous page's roster.
watch(() => [props.page, props.locale], start);

onMounted(start);
onBeforeUnmount(stop);
</script>

<style scoped>
.presence { display: flex; align-items: center; gap: 0.6rem; }
.presence-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-text-muted); }
.presence-list { list-style: none; margin: 0; padding: 0; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
.presence-editor { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.15rem 0.55rem 0.15rem 0.15rem; border: 1px solid var(--app-border); border-radius: 999px; background: var(--app-surface-strong); }
.presence-avatar { display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; font-size: 0.75rem; font-weight: 600; color: #fff; background: var(--color-primary-600, #4f46e5); }
.presence-name { font-size: 0.8125rem; color: var(--app-text); }
</style>
