<template>
  <div class="pages">
    <div class="page-header">
      <div>
        <h1 class="page-title">Pages</h1>
        <p class="page-subtitle">Manage your content pages</p>
      </div>
      <a class="btn-primary" :href="withBase(newPageHref)" @click="go($event, newPageHref)">+ New Page</a>
    </div>

    <div class="toolbar">
      <div v-if="locales.length > 1" class="locale-picker">
        <label class="locale-label" for="pages-locale">Language</label>
        <select id="pages-locale" v-model="activeLocale" class="locale-select">
          <option v-for="code in locales" :key="code" :value="code">
            {{ localeName(code) }}
          </option>
        </select>
      </div>

      <div class="filter-tabs" role="group" aria-label="Filter pages by publication state">
        <button
          v-for="tab in visibleTabs"
          :key="tab.value"
          type="button"
          :class="['tab', { active: currentTab === tab.value }]"
          :aria-pressed="currentTab === tab.value"
          @click="currentTab = tab.value"
        >
          {{ tab.label }} ({{ tabCount(tab.value) }})
        </button>
      </div>
    </div>

    <p v-if="localeError" class="notice" role="status">{{ localeError }}</p>

    <div v-if="loading" class="loading">Loading...</div>
    <div v-else-if="filteredPages.length === 0" class="empty-state">{{ emptyMessage }}</div>
    <div v-else class="page-list">
      <div v-for="page in filteredPages" :key="page.key" class="page-card">
        <div class="page-info">
          <!-- h2, not h3: this screen's only h1 is "Pages", so a card title at
               level 3 skips a level and breaks the outline a screen reader
               navigates by. The size is set in CSS, not by the tag. -->
          <h2>{{ page.data?.title || slugOf(page) }}</h2>
          <p class="page-slug">/{{ slugOf(page) }}</p>

          <div class="state-row">
            <span :class="['status-badge', stateOf(page)]">{{ stateLabel(stateOf(page)) }}</span>
            <span v-if="publishedAt(page)" class="state-meta">Published {{ publishedAt(page) }}</span>
          </div>

          <ul v-if="locales.length > 1" class="translations" :aria-label="`Languages for ${slugOf(page)}`">
            <li v-for="code in locales" :key="code">
              <a
                v-if="pageIn(code, slugOf(page))"
                class="lang-chip"
                :class="[stateOf(pageIn(code, slugOf(page))), { current: code === activeLocale }]"
                :href="withBase(editHref(slugOf(page), code))"
                :aria-label="`Edit ${page.data?.title || slugOf(page)} in ${localeName(code)} — ${stateLabel(stateOf(pageIn(code, slugOf(page))))}`"
                @click="go($event, editHref(slugOf(page), code))"
              >
                <span class="lang-code">{{ code }}</span>
                <span class="lang-state">{{ stateLabel(stateOf(pageIn(code, slugOf(page)))) }}</span>
              </a>
              <span v-else class="lang-chip missing">
                <span class="lang-code">{{ code }}</span>
                <span class="lang-state">Not translated</span>
              </span>
            </li>
          </ul>
        </div>

        <div class="page-actions">
          <a
            class="btn-sm btn-secondary"
            :href="withBase(editHref(slugOf(page), activeLocale))"
            :aria-label="`Edit ${page.data?.title || slugOf(page)}`"
            @click="go($event, editHref(slugOf(page), activeLocale))"
          >Edit</a>
          <button
            type="button"
            class="btn-sm btn-danger"
            :aria-label="`Delete ${page.data?.title || slugOf(page)}`"
            @click="deletePage(slugOf(page))"
          >Delete</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { withBase } from '../lib/base.js';

const emit = defineEmits(['navigate']);

/**
 * Publication is no longer a field on the document — `content/` holds published
 * documents only, and the API derives three facts from that which cannot
 * contradict each other. The list reports those facts rather than a `status`
 * string that no longer exists.
 */
const STATES = {
  live: 'Live',
  pending: 'Live, edits pending',
  never: 'Never published',
  takendown: 'Taken down',
  unknown: 'Status unavailable',
};

const TABS = [
  { label: 'All', value: 'all' },
  { label: 'Live', value: 'live' },
  { label: 'Edits pending', value: 'pending' },
  { label: 'Never published', value: 'never' },
  { label: 'Taken down', value: 'takendown' },
];

const byLocale = ref({});
const locales = ref([]);
const activeLocale = ref('');
const defaultLocale = ref('');
const loading = ref(true);
const localeError = ref('');
const currentTab = ref('all');

const rows = computed(() => byLocale.value[activeLocale.value] ?? []);

const slugOf = (page) => page.slug ?? String(page.key ?? '').split(':').pop();

/**
 * Publication state, or `unknown` when the server did not supply one — an
 * anonymous caller gets no `publication` key at all, and asserting "draft" in
 * its absence is exactly the lie this replaced.
 */
const stateOf = (page) => {
  const p = page?.publication;
  if (!p || typeof p.published !== 'boolean') return 'unknown';
  if (p.published) return p.hasUnpublishedChanges ? 'pending' : 'live';
  return p.neverPublished ? 'never' : 'takendown';
};

const stateLabel = (state) => STATES[state] ?? STATES.unknown;

const publishedAt = (page) => {
  const at = page?.publication?.publishedAt;
  if (!at) return '';
  const date = new Date(at);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString();
};

// Without publication data there is nothing to filter on, so offer no filter
// rather than tabs that would all read zero.
const hasPublicationData = computed(() => rows.value.some((p) => stateOf(p) !== 'unknown'));
const visibleTabs = computed(() => (hasPublicationData.value ? TABS : TABS.slice(0, 1)));

const filteredPages = computed(() =>
  currentTab.value === 'all' ? rows.value : rows.value.filter((p) => stateOf(p) === currentTab.value)
);

const tabCount = (tab) =>
  tab === 'all' ? rows.value.length : rows.value.filter((p) => stateOf(p) === tab).length;

const emptyMessage = computed(() =>
  currentTab.value === 'all'
    ? 'No pages in this language yet.'
    : `No pages are ${stateLabel(currentTab.value).toLowerCase()} in this language.`
);

/** The same slug in another language, if it exists there. */
const pageIn = (code, slug) => (byLocale.value[code] ?? []).find((p) => slugOf(p) === slug);

const displayNames = (() => {
  try {
    return new Intl.DisplayNames(undefined, { type: 'language' });
  } catch {
    return null;
  }
})();

const localeName = (code) => {
  const name = displayNames?.of(code);
  return name && name !== code ? `${name} (${code})` : code.toUpperCase();
};

// Real hrefs, so these are keyboard-reachable and open in a new tab like any
// other link; the click handler only upgrades them to client-side navigation.
const withLocale = (path, code) => (code && code !== defaultLocale.value ? `${path}?locale=${encodeURIComponent(code)}` : path);
const editHref = (slug, code) => withLocale(`/admin/pages/edit/${encodeURIComponent(slug)}`, code);
const newPageHref = computed(() => withLocale('/admin/pages/new', activeLocale.value));

const go = (event, href) => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  emit('navigate', href);
};

const fetchLocale = async (code) => {
  const res = await fetch(code ? `/api/pages?locale=${encodeURIComponent(code)}` : '/api/pages');
  return res.json();
};

const loadPages = async () => {
  loading.value = true;
  localeError.value = '';
  try {
    const first = await fetchLocale(null);
    defaultLocale.value = first.locale ?? '';
    locales.value = Array.isArray(first.locales) && first.locales.length
      ? first.locales
      : [first.locale].filter(Boolean);

    if (!locales.value.includes(activeLocale.value)) {
      activeLocale.value = defaultLocale.value || locales.value[0] || '';
    }

    const loaded = { [defaultLocale.value]: first.data ?? [] };
    const others = locales.value.filter((c) => c !== defaultLocale.value);
    const results = await Promise.all(others.map((c) => fetchLocale(c).catch(() => null)));
    others.forEach((c, i) => { loaded[c] = results[i]?.data ?? []; });

    byLocale.value = loaded;
  } catch (e) {
    console.error(e);
    localeError.value = 'Could not load pages.';
  }
  loading.value = false;
};

const deletePage = async (slug) => {
  if (!confirm(`Delete "${slug}" in ${localeName(activeLocale.value)}? Other languages are not affected.`)) return;
  // Locale-scoped: page:de:home and page:en:home are separate documents, and
  // deleting one must not take the other down.
  await fetch(`/api/pages/${encodeURIComponent(slug)}?locale=${encodeURIComponent(activeLocale.value)}`, {
    method: 'DELETE',
  });
  await loadPages();
};

watch(activeLocale, () => { currentTab.value = 'all'; });

onMounted(loadPages);
</script>

<style scoped>
.pages { max-width: 1200px; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); }
.btn-primary { display: inline-block; padding: 0.625rem 1rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; text-decoration: none; }

.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
.locale-picker { display: flex; align-items: center; gap: 0.5rem; }
.locale-label { color: var(--app-text-muted); font-size: 0.875rem; font-weight: 500; }
.locale-select { padding: 0.5rem 0.75rem; border-radius: 8px; border: 1px solid var(--control-border); background: var(--app-surface); color: var(--app-text); font-size: 0.875rem; font-weight: 500; }
.locale-select:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }

.filter-tabs { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.tab { padding: 0.5rem 1rem; background: none; border: none; border-radius: 8px; cursor: pointer; color: var(--app-text-muted); font-weight: 500; }
.tab.active { background: var(--sidebar-active); color: var(--sidebar-active-text); }
.tab:focus-visible,
.btn-primary:focus-visible,
.btn-sm:focus-visible,
.lang-chip:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }

.notice { margin-bottom: 1rem; color: var(--color-danger-600, #dc2626); font-size: 0.875rem; }
.loading, .empty-state { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.page-list { display: flex; flex-direction: column; gap: 1rem; }
.page-card { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius-sm); }
.page-info h2 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.25rem; }
.page-slug { color: var(--app-text-muted); font-size: 0.875rem; margin-bottom: 0.5rem; }

.state-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.625rem; }
.state-meta { color: var(--app-text-muted); font-size: 0.75rem; }

.status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.02em; }
.status-badge.live { background: #dcfce7; color: #166534; }
.status-badge.pending { background: #fef3c7; color: #92400e; }
.status-badge.never { background: #e5e9ef; color: #3f4754; }
.status-badge.takendown { background: #fee2e2; color: #991b1b; }
.status-badge.unknown { background: #e5e9ef; color: #3f4754; }

.translations { list-style: none; display: flex; flex-wrap: wrap; gap: 0.375rem; margin: 0.625rem 0 0; padding: 0; }
.lang-chip { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.1875rem 0.5rem; border-radius: 6px; border: 1px solid var(--app-border); font-size: 0.6875rem; text-decoration: none; color: var(--app-text-muted); }
.lang-chip.current { border-color: var(--color-primary-600); }
.lang-code { text-transform: uppercase; font-weight: 700; letter-spacing: 0.04em; color: var(--app-text); }
.lang-state { color: var(--app-text-muted); }
.lang-chip.live .lang-state { color: #166534; }
.lang-chip.pending .lang-state { color: #92400e; }
.lang-chip.takendown .lang-state { color: #991b1b; }
.lang-chip.missing { border-style: dashed; }
a.lang-chip:hover, a.lang-chip:focus-visible { background: var(--sidebar-hover); }

.page-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }
.btn-sm { display: inline-block; padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 6px; cursor: pointer; text-decoration: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }
/* White on --color-danger-500 is 3.8:1 — under the 4.5:1 its own label needs. */
.btn-danger { background: var(--color-danger-fill, #dc2626); color: white; border: none; }

/* The badge palette above is tuned for light surfaces; on dark ones the same
   tints go muddy, so they are restated rather than left to wash out. */
[data-theme="dark"] .status-badge.live { background: rgba(34, 197, 94, 0.18); color: #86efac; }
[data-theme="dark"] .status-badge.pending { background: rgba(245, 158, 11, 0.18); color: #fcd34d; }
[data-theme="dark"] .status-badge.never,
[data-theme="dark"] .status-badge.unknown { background: rgba(148, 163, 184, 0.18); color: #cbd5e1; }
[data-theme="dark"] .status-badge.takendown { background: rgba(239, 68, 68, 0.18); color: #fca5a5; }
[data-theme="dark"] .lang-chip.live .lang-state { color: #86efac; }
[data-theme="dark"] .lang-chip.pending .lang-state { color: #fcd34d; }
[data-theme="dark"] .lang-chip.takendown .lang-state { color: #fca5a5; }
</style>
