<template>
  <div class="dashboard">
    <div class="page-header">
      <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">{{ subtitle }}</p>
      </div>
    </div>

    <p v-if="error" class="banner error" role="alert">
      {{ error }}
      <button type="button" class="linkish" @click="load">Try again</button>
    </p>

    <p v-if="notice" class="banner success" role="status">{{ notice }}</p>

    <div v-if="loading" class="muted" aria-live="polite">Loading…</div>

    <template v-else-if="!error">
      <div class="stats-grid">
        <StatCard v-for="stat in stats" :key="stat.label" v-bind="stat" />
      </div>

      <!-- A fresh install has an admin account and nothing else. The counts
           above all read zero, which used to look identical to a failed fetch
           that never reported itself. Offer a clear next step instead. -->
      <section v-if="isEmpty" class="first-run" aria-labelledby="first-run-title">
        <h2 id="first-run-title" class="first-run-title">Your site is empty</h2>
        <p class="first-run-copy">
          Start with a blank page, or load a small example site so you can see
          how pages, collections and media fit together. The example never
          overwrites anything you already have.
        </p>
        <div class="first-run-actions">
          <a
            class="btn-primary"
            :href="withBase('/admin/pages/new')"
            @click="go($event, '/admin/pages/new')"
          >Create a page</a>
          <button
            v-if="canSeed"
            type="button"
            class="btn-secondary"
            :disabled="seeding"
            @click="seed"
          >
            {{ seeding ? 'Loading example…' : 'Load example site' }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import StatCard from './StatCard.vue';
import { withBase } from '../lib/base.js';

const props = defineProps({
  capabilities: { type: Array, default: () => [] },
});

const emit = defineEmits(['navigate']);

const ICON = {
  pages: 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z',
  live: 'M5 13l4 4L19 7',
  pending: 'M4 20h16M14 4l6 6M6 14l8-8 4 4-8 8H6z',
  plugins: 'M7 3v4M17 3v4M5 7h14v4a5 5 0 0 1-5 5h-4a5 5 0 0 1-5-5V7zM12 16v5',
};

const emptyStats = () => [
  { icon: ICON.pages, label: 'Total Pages', value: 0, color: 'blue' },
  { icon: ICON.live, label: 'Live', value: 0, color: 'green' },
  { icon: ICON.pending, label: 'Edits pending', value: 0, color: 'yellow' },
  { icon: ICON.plugins, label: 'Active Plugins', value: 0, color: 'purple' },
];

const stats = ref(emptyStats());
const loading = ref(true);
const error = ref('');
const notice = ref('');
const pageCount = ref(0);
const seeding = ref(false);

const canSeed = computed(() => props.capabilities.includes('settings.manage'));
const isEmpty = computed(() => pageCount.value === 0);
const subtitle = computed(() => (
  isEmpty.value && !loading.value && !error.value
    ? 'Nothing here yet — pick a starting point below'
    : 'Welcome back to Click CMS'
));

const go = (event, href) => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button === 1) return;
  event.preventDefault();
  emit('navigate', href);
};

const load = async () => {
  loading.value = true;
  error.value = '';

  try {
    const [pagesRes, pluginsRes] = await Promise.all([fetch('/api/pages'), fetch('/api/plugins')]);

    if (!pagesRes.ok || !pluginsRes.ok) {
      const failed = !pagesRes.ok ? pagesRes : pluginsRes;
      const body = await failed.json().catch(() => ({}));
      throw new Error(body.error || `Could not load the dashboard (${failed.status})`);
    }

    const pagesData = await pagesRes.json();
    const pluginsData = await pluginsRes.json();
    const pages = pagesData.data || [];
    const plugins = pluginsData.data || [];

    // Read from `publication`, not a `status` field. That field was removed when
    // publishing became a thing that happens rather than a value that is set,
    // and this screen went on counting it — so the landing page of the admin
    // reported "0 published, 0 drafts" for a site that was entirely live.
    const live = pages.filter((p) => p.publication?.published).length;
    const pending = pages.filter((p) => p.publication?.hasUnpublishedChanges).length;
    const activePlugins = plugins.filter((p) => p.state === 'activated').length;

    pageCount.value = pages.length;
    stats.value = [
      { icon: ICON.pages, label: 'Total Pages', value: pages.length, color: 'blue' },
      { icon: ICON.live, label: 'Live', value: live, color: 'green' },
      { icon: ICON.pending, label: 'Edits pending', value: pending, color: 'yellow' },
      { icon: ICON.plugins, label: 'Active Plugins', value: activePlugins, color: 'purple' },
    ];
  } catch (e) {
    error.value = e.message || 'Could not load the dashboard.';
    pageCount.value = 0;
    stats.value = emptyStats();
  } finally {
    loading.value = false;
  }
};

const seed = async () => {
  seeding.value = true;
  error.value = '';
  notice.value = '';

  try {
    const res = await fetch('/api/seed', { method: 'POST' });
    const body = await res.json().catch(() => ({}));

    if (!res.ok && res.status !== 207) {
      error.value = body.error ?? `Could not load the example site (${res.status}).`;
      return;
    }

    const created = body.data?.created?.length ?? 0;
    const failures = body.data?.failures ?? [];

    if (body.data?.noop) {
      notice.value = 'The example site is already here.';
    } else if (failures.length) {
      notice.value = `Loaded ${created} item${created === 1 ? '' : 's'}; ${failures.length} could not be created.`;
      error.value = failures.join(' ');
    } else {
      notice.value = `Loaded ${created} example item${created === 1 ? '' : 's'}. Open Pages to explore them.`;
    }

    await load();
  } catch (e) {
    error.value = e.message || 'Could not load the example site.';
  } finally {
    seeding.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.dashboard { max-width: 1400px; }
.page-header { margin-bottom: 2rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
.muted { color: var(--app-text-muted); font-size: 0.875rem; }
.banner {
  padding: 0.75rem 1rem;
  border-radius: 8px;
  background: var(--app-surface-strong);
  font-size: 0.875rem;
  margin: 0 0 1.25rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
}
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.success { color: var(--color-primary-600); }
.linkish {
  background: none;
  border: none;
  padding: 0;
  color: inherit;
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
}
.first-run {
  margin-top: 2rem;
  padding: 1.5rem 1.75rem;
  border: 1px solid var(--app-border);
  border-radius: 12px;
  background: var(--card-bg, var(--app-surface));
  max-width: 40rem;
}
.first-run-title { margin: 0 0 0.5rem; font-size: 1.25rem; color: var(--app-text); }
.first-run-copy { margin: 0 0 1.25rem; color: var(--app-text-muted); font-size: 0.9375rem; line-height: 1.5; }
.first-run-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.btn-primary, .btn-secondary {
  padding: 0.55rem 1.1rem;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  font-size: 0.875rem;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
}
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary {
  background: var(--app-surface-strong);
  color: var(--app-text);
  border: 1px solid var(--control-border, var(--app-border));
}
.btn-secondary:disabled { opacity: 0.55; cursor: not-allowed; }

button:focus-visible,
a:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
