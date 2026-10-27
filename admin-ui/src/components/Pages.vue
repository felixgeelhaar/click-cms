<template>
  <div class="pages">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ message('Pages') }}</h1>
        <p class="page-subtitle">{{ message('Manage your content pages') }}</p>
      </div>
      <a v-if="canCreate" class="btn-primary" :href="withBase(newPageHref)" @click="go($event, newPageHref)">{{ message('+ New Page') }}</a>
    </div>

    <div class="toolbar">
      <div v-if="locales.length > 1" class="locale-picker">
        <label class="locale-label" for="pages-locale">{{ message('Language') }}</label>
        <select id="pages-locale" v-model="activeLocale" class="locale-select">
          <option v-for="code in locales" :key="code" :value="code">
            {{ localeName(code) }}
          </option>
        </select>
      </div>

      <div class="page-search">
        <label class="locale-label" for="pages-search">{{ message('Search pages') }}</label>
        <input id="pages-search" v-model="search" type="search" :placeholder="message(&quot;Title or slug&quot;)" autocomplete="off">
      </div>
      <div class="page-sort">
        <label class="locale-label" for="pages-sort">{{ message('Sort by') }}</label>
        <select id="pages-sort" v-model="sortBy" class="locale-select">
          <option value="recent">{{ message('Recently updated') }}</option>
          <option value="title">{{ message('Title') }}</option>
          <option value="slug">{{ message('Slug') }}</option>
        </select>
      </div>

      <div class="filter-tabs" role="group" :aria-label="message(&quot;Filter pages by publication state&quot;)">
        <button
          v-for="tab in visibleTabs"
          :key="tab.value"
          type="button"
          :class="['tab', { active: currentTab === tab.value }]"
          :aria-pressed="currentTab === tab.value"
          @click="currentTab = tab.value"
        >
          {{ message(tab.label) }} ({{ tabCount(tab.value) }})
        </button>
      </div>
    </div>

    <div v-if="localeError" class="notice" role="alert">
      <p><LocalizedError :message="localeError" /></p>
      <button type="button" class="btn-sm btn-secondary" @click="loadPages" :disabled="loading">{{ message('Retry') }}</button>
    </div>

    <div v-if="loading" class="loading">{{ message('Loading...') }}</div>
    <div v-else-if="!loadFailed && !unavailableLocales.includes(activeLocale) && filteredPages.length === 0" class="empty-state">{{ message(emptyMessage) }}</div>
    <div v-else-if="!loadFailed" class="page-list">
      <div v-for="page in filteredPages" :key="page.key" class="page-card">
        <div class="page-info">
          <!-- h2, not h3: this screen's only h1 is "Pages", so a card title at
               level 3 skips a level and breaks the outline a screen reader
               navigates by. The size is set in CSS, not by the tag. -->
          <h2>{{ page.data?.title || slugOf(page) }}</h2>
          <p class="page-slug">/{{ slugOf(page) }}</p>

          <div class="state-row">
            <span :class="['status-badge', stateOf(page)]">{{ message(stateLabel(stateOf(page))) }}</span>
            <span v-if="publishedAt(page)" class="state-meta">{{ message('Published') }} {{ publishedAt(page) }}</span>
          </div>

          <ul v-if="locales.length > 1" class="translations" :aria-label="message('Languages for {0}', [slugOf(page)])">
            <li v-for="code in locales" :key="code">
              <a
                v-if="pageIn(code, slugOf(page))"
                class="lang-chip"
                :class="[stateOf(pageIn(code, slugOf(page))), { current: code === activeLocale }]"
                :href="withBase(editHref(slugOf(page), code))"
                :aria-label="message('Edit {0} in {1} — {2}', [page.data?.title || slugOf(page), localeName(code), message(stateLabel(stateOf(pageIn(code, slugOf(page)))))])"
                @click="go($event, editHref(slugOf(page), code))"
              >
                <span class="lang-code">{{ code }}</span>
                <span class="lang-state">{{ message(stateLabel(stateOf(pageIn(code, slugOf(page))))) }}</span>
              </a>
              <span v-else class="lang-chip missing">
                <span class="lang-code">{{ code }}</span>
                <span class="lang-state">{{ unavailableLocales.includes(code) ? message('Status unavailable') : message('Not translated') }}</span>
              </span>
            </li>
          </ul>
        </div>

        <div class="page-actions">
          <a v-if="canEditPage(page)"
            class="btn-sm btn-secondary"
            :href="withBase(editHref(slugOf(page), activeLocale))"
            :aria-label="message('Edit {0}', [page.data?.title || slugOf(page)])"
            @click="go($event, editHref(slugOf(page), activeLocale))"
          >{{ message('Edit') }}</a>
          <button v-if="canDeletePage(page)"
            type="button"
            class="btn-sm btn-danger"
            :aria-label="message('Delete {0}', [page.data?.title || slugOf(page)])"
            :disabled="deleting !== ''"
            @click="deletePage(slugOf(page))"
          >{{ deleting === slugOf(page) ? message('Deleting…') : message('Delete') }}</button>
        </div>
      </div>
    </div>
    <p v-if="!loading && !loadFailed && !canEditAnything" class="notice" role="status">{{ message('Read only: you can review pages, but your account cannot edit them.') }}</p>
  </div>
</template>

<script setup>
import { message, messageLanguage } from "../lib/messages.js";
import LocalizedError from './LocalizedError.vue';
import { ref, computed, onMounted, watch } from 'vue';
import { withBase } from '../lib/base.js';

const emit = defineEmits(['navigate']);
const props = defineProps({
  capabilities: { type: Array, default: () => [] },
  username: { type: String, default: '' },
  siteId: { type: String, default: '' },
});

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
const legacyFilterKey = 'click-admin-pages-filters';
const filterStorageKey = `click-admin-pages-filters:${encodeURIComponent(props.siteId || 'default')}`;
const savedFilters = (() => {
  try {
    // Older versions persisted search text globally across accounts and sites.
    // It can include private page titles, so discard that legacy record.
    window.localStorage?.removeItem(legacyFilterKey);
    return JSON.parse(window.localStorage?.getItem(filterStorageKey) || '{}');
  }
  catch { return {}; }
})();
const activeLocale = ref(typeof savedFilters.locale === 'string' ? savedFilters.locale : '');
const defaultLocale = ref('');
const loading = ref(true);
const localeError = ref('');
const loadFailed = ref(false);
const unavailableLocales = ref([]);
const deleting = ref('');
const search = ref('');
const sortBy = ref(['recent', 'title', 'slug'].includes(savedFilters.sortBy) ? savedFilters.sortBy : 'recent');
const currentTab = ref(TABS.some(({ value }) => value === savedFilters.currentTab) ? savedFilters.currentTab : 'all');
const can = (capability) => props.capabilities.includes(capability);
const canCreate = computed(() => can('content.create'));
const canEditAnything = computed(() => can('content.edit.any') || can('content.edit.own'));
const canEditPage = (page) => can('content.edit.any') || (can('content.edit.own') && !!props.username && page.data?.owner === props.username);
const canDeletePage = (page) => can('content.delete.any') || (can('content.delete.own') && !!props.username && page.data?.owner === props.username);

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
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(messageLanguage.value);
};

// Without publication data there is nothing to filter on, so offer no filter
// rather than tabs that would all read zero.
const hasPublicationData = computed(() => rows.value.some((p) => stateOf(p) !== 'unknown'));
const visibleTabs = computed(() => (hasPublicationData.value ? TABS : TABS.slice(0, 1)));
watch(visibleTabs, (tabs) => {
  if (!tabs.some(({ value }) => value === currentTab.value)) currentTab.value = 'all';
});

const filteredPages = computed(() => {
  const query = search.value.trim().toLocaleLowerCase();
  const matches = rows.value.filter((page) => {
    const matchesState = currentTab.value === 'all' || stateOf(page) === currentTab.value;
    const searchable = `${page.data?.title ?? ''} ${slugOf(page)}`.toLocaleLowerCase();
    return matchesState && (!query || searchable.includes(query));
  });
  const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });
  return matches.sort((a, b) => {
    if (sortBy.value === 'title') return collator.compare(a.data?.title || slugOf(a), b.data?.title || slugOf(b));
    if (sortBy.value === 'slug') return collator.compare(slugOf(a), slugOf(b));
    const updated = (p) => Date.parse(p.data?.updatedAt || p.data?.updated_at || p.data?.createdAt || '') || 0;
    return updated(b) - updated(a);
  });
});

const tabCount = (tab) =>
  tab === 'all' ? rows.value.length : rows.value.filter((p) => stateOf(p) === tab).length;

const emptyMessage = computed(() =>
  search.value.trim()
    ? 'No pages match your search and filters.'
    : currentTab.value === 'all'
      ? 'No pages in this language yet.'
      : message('No pages are {0} in this language.', [message(stateLabel(currentTab.value)).toLocaleLowerCase(messageLanguage.value)])
);

watch([search, sortBy, currentTab, activeLocale], () => {
  try { window.localStorage?.setItem(filterStorageKey, JSON.stringify({ sortBy: sortBy.value, currentTab: currentTab.value, locale: activeLocale.value })); }
  catch { /* Preferences are a convenience; private browsing may deny storage. */ }
});

/** The same slug in another language, if it exists there. */
const pageIn = (code, slug) => (byLocale.value[code] ?? []).find((p) => slugOf(p) === slug);

const displayNames = computed(() => {
  try {
    return new Intl.DisplayNames(messageLanguage.value, { type: 'language' });
  } catch {
    return null;
  }
});

const localeName = (code) => {
  const name = displayNames.value?.of(code);
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
  const body = await res.json();
  if (!res.ok) throw new Error(body.error || `Could not load pages (${res.status}).`);
  return body;
};

const loadPages = async () => {
  loading.value = true;
  localeError.value = '';
  loadFailed.value = false;
  unavailableLocales.value = [];
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
    others.forEach((c, i) => {
      if (results[i] === null) unavailableLocales.value.push(c);
      loaded[c] = results[i]?.data ?? [];
    });
    if (unavailableLocales.value.length) {
      localeError.value = `Could not load pages in ${unavailableLocales.value.map(localeName).join(', ')}. Retry to check their translation status.`;
    }

    byLocale.value = loaded;
  } catch (e) {
    console.error(e);
    loadFailed.value = true;
    localeError.value = e.message || 'Could not load pages.';
  }
  loading.value = false;
};

const deletePage = async (slug) => {
  if (!confirm(message(`Delete "${slug}" in ${localeName(activeLocale.value)}? Other languages are not affected.`))) return;
  deleting.value = slug;
  localeError.value = '';
  try {
    const res = await fetch(`/api/pages/${encodeURIComponent(slug)}?locale=${encodeURIComponent(activeLocale.value)}`, {
      method: 'DELETE',
    });
    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      throw new Error(body.error || `Could not delete this page (${res.status}).`);
    }
    await loadPages();
  } catch (e) {
    localeError.value = e.message || 'Could not delete this page. Try again.';
  } finally {
    deleting.value = '';
  }
};

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
.page-search, .page-sort { display: flex; align-items: center; gap: 0.5rem; }
.page-search input { min-width: 14rem; padding: 0.5rem 0.75rem; border-radius: 8px; border: 1px solid var(--control-border); background: var(--app-surface); color: var(--app-text); font: inherit; }
.page-search input:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }
.locale-select:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }

.filter-tabs { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.tab { padding: 0.5rem 1rem; background: none; border: none; border-radius: 8px; cursor: pointer; color: var(--app-text-muted); font-weight: 500; }
.tab.active { background: var(--sidebar-active); color: var(--sidebar-active-text); }
.tab:focus-visible,
.btn-primary:focus-visible,
.btn-sm:focus-visible,
.lang-chip:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }

.notice { margin-bottom: 1rem; color: var(--color-danger-600); font-size: 0.875rem; }
.loading, .empty-state { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.page-list { display: flex; flex-direction: column; gap: 1rem; }
.page-card { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1.5rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius-sm); }
.page-info h2 { font-size: 1.125rem; font-weight: 600; margin-bottom: 0.25rem; }
.page-slug { color: var(--app-text-muted); font-size: 0.875rem; margin-bottom: 0.5rem; }

.state-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.625rem; }
.state-meta { color: var(--app-text-muted); font-size: 0.75rem; }

.status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.02em; }
.status-badge.live { background: var(--color-success-bg); color: var(--color-success-text); }
.status-badge.pending { background: var(--color-warning-bg); color: var(--color-warning-text); }
.status-badge.never { background: var(--app-border); color: #3f4754; }
.status-badge.takendown { background: var(--color-danger-bg); color: var(--color-danger-600); }
.status-badge.unknown { background: var(--app-border); color: #3f4754; }

.translations { list-style: none; display: flex; flex-wrap: wrap; gap: 0.375rem; margin: 0.625rem 0 0; padding: 0; }
.lang-chip { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.1875rem 0.5rem; border-radius: 6px; border: 1px solid var(--app-border); font-size: 0.6875rem; text-decoration: none; color: var(--app-text-muted); }
.lang-chip.current { border-color: var(--color-primary-600); }
.lang-code { text-transform: uppercase; font-weight: 700; letter-spacing: 0.04em; color: var(--app-text); }
.lang-state { color: var(--app-text-muted); }
.lang-chip.live .lang-state { color: var(--color-success-text); }
.lang-chip.pending .lang-state { color: var(--color-warning-text); }
.lang-chip.takendown .lang-state { color: var(--color-danger-600); }
.lang-chip.missing { border-style: dashed; }
a.lang-chip:hover, a.lang-chip:focus-visible { background: var(--sidebar-hover); }

.page-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }
.btn-sm { display: inline-block; padding: 0.5rem 1rem; font-size: 0.875rem; border-radius: 6px; cursor: pointer; text-decoration: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }
/* White on --color-danger-500 is 3.8:1 — under the 4.5:1 its own label needs. */
.btn-danger { background: var(--color-danger-fill); color: white; border: none; }

/* The badge palette above is tuned for light surfaces; on dark ones the same
   tints go muddy, so they are restated rather than left to wash out. */
[data-theme="dark"] .status-badge.live { background: rgba(34, 197, 94, 0.18); color: var(--color-success-text); }
[data-theme="dark"] .status-badge.pending { background: rgba(245, 158, 11, 0.18); color: #fcd34d; }
[data-theme="dark"] .status-badge.never,
[data-theme="dark"] .status-badge.unknown { background: rgba(148, 163, 184, 0.18); color: #cbd5e1; }
[data-theme="dark"] .status-badge.takendown { background: rgba(239, 68, 68, 0.18); color: var(--color-danger-600); }
[data-theme="dark"] .lang-chip.live .lang-state { color: var(--color-success-text); }
[data-theme="dark"] .lang-chip.pending .lang-state { color: #fcd34d; }
[data-theme="dark"] .lang-chip.takendown .lang-state { color: var(--color-danger-600); }
</style>

<style scoped>
button:disabled { opacity: 0.65; cursor: wait; }
@media (max-width: 600px) {
  .page-header, .page-card { align-items: flex-start; flex-direction: column; }
  .filter-tabs { flex-wrap: wrap; }
  .page-actions { width: 100%; }
}
</style>
