<template>
  <div class="release">
    <div class="page-header">
      <div>
        <h1 class="page-title">Release</h1>
        <p class="page-subtitle">Publish a chosen set of pages and collection entries together.</p>
      </div>
      <button
        type="button"
        class="btn-sm"
        :disabled="loading"
        aria-label="Reload pages, entries, and review readiness"
        @click="load"
      >
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="notice" class="banner notice" role="status">{{ notice }}</p>

    <p v-if="loading && !loaded" class="loading">Loading…</p>

    <template v-else-if="!error || pages.length > 0 || entries.length > 0">
      <div class="locale-row">
        <label class="locale-label" for="release-locale">Locale</label>
        <input
          id="release-locale"
          v-model="locale"
          type="text"
          class="locale-input"
          autocomplete="off"
          spellcheck="false"
          aria-describedby="release-locale-hint"
        >
        <p id="release-locale-hint" class="locale-hint">
          Defaults to the site language. Publishing uses this locale for every selected item.
        </p>
      </div>

      <p v-if="pages.length === 0 && entries.length === 0" class="empty">
        Nothing to release yet. Create pages or collection entries first, then choose which ones to publish together.
      </p>

      <template v-else>
        <section v-if="pages.length > 0" class="release-section" aria-labelledby="release-pages-heading">
          <h2 id="release-pages-heading" class="section-title">Pages</h2>
          <div class="table-wrap">
            <table class="release-table">
              <thead>
                <tr>
                  <th scope="col" class="col-check">
                    <span class="visually-hidden">Include</span>
                  </th>
                  <th scope="col">Page</th>
                  <th scope="col">Title</th>
                  <th scope="col">Readiness</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="page in pages" :key="`page:${page.slug}`">
                  <td class="col-check">
                    <input
                      :id="`release-page-${page.slug}`"
                      v-model="selectedPages"
                      type="checkbox"
                      :value="page.slug"
                    >
                  </td>
                  <td>
                    <a
                      class="page-slug page-link"
                      :href="withBase(pageHref(page))"
                      @click="go($event, pageHref(page))"
                    >{{ page.slug }}</a>
                  </td>
                  <td>{{ page.title }}</td>
                  <td>
                    <span
                      v-if="page.review"
                      class="review-badge"
                      :class="`is-${page.review.state || 'none'}`"
                    >{{ stateLabel(page.review) }}</span>
                    <span v-else class="review-badge is-ready">Ready</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="entries.length > 0" class="release-section" aria-labelledby="release-entries-heading">
          <h2 id="release-entries-heading" class="section-title">Collection entries</h2>
          <div class="table-wrap">
            <table class="release-table">
              <thead>
                <tr>
                  <th scope="col" class="col-check">
                    <span class="visually-hidden">Include</span>
                  </th>
                  <th scope="col">Type</th>
                  <th scope="col">Entry</th>
                  <th scope="col">Title</th>
                  <th scope="col">Readiness</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="entry in entries" :key="entryKey(entry)">
                  <td class="col-check">
                    <input
                      :id="`release-entry-${entryKey(entry)}`"
                      v-model="selectedEntries"
                      type="checkbox"
                      :value="entryKey(entry)"
                    >
                  </td>
                  <td>{{ entry.typeLabel }}</td>
                  <td>
                    <a
                      class="page-slug page-link"
                      :href="withBase(entryHref(entry))"
                      @click="go($event, entryHref(entry))"
                    >{{ entry.slug }}</a>
                  </td>
                  <td>{{ entry.title }}</td>
                  <td>
                    <span
                      v-if="entry.review"
                      class="review-badge"
                      :class="`is-${entry.review.state || 'none'}`"
                    >{{ stateLabel(entry.review) }}</span>
                    <span v-else class="review-badge is-ready">Ready</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <p class="readiness-note">
          Ready means this item is not in an open review. Publish may still refuse for other
          reasons — the response will explain.
        </p>

        <div class="actions">
          <button
            type="button"
            class="btn-primary"
            :disabled="busy || selectionCount === 0"
            @click="publish"
          >
            {{ busy ? 'Publishing…' : 'Publish together' }}
          </button>
        </div>
      </template>
    </template>

    <div v-if="blockers.length > 0" class="result blockers" role="alert">
      <h2 class="result-title">Editorial blockers</h2>
      <p class="result-detail">Nothing was published. Fix these, then try again.</p>
      <ul class="result-list">
        <li v-for="(item, i) in blockers" :key="`block-${i}`">
          <a class="page-link" :href="withBase(resultHref(item))" @click="go($event, resultHref(item))">
            <strong>{{ itemLabel(item) }}</strong>
          </a>
          <span v-if="item.locale"> ({{ item.locale }})</span>
          — {{ item.reason }}
        </li>
      </ul>
    </div>

    <div v-if="published.length > 0" class="result success" role="status">
      <h2 class="result-title">Published</h2>
      <ul class="result-list">
        <li v-for="(item, i) in published" :key="`pub-${i}`">
          <a class="page-link" :href="withBase(resultHref(item))" @click="go($event, resultHref(item))">
            <strong>{{ itemLabel(item) }}</strong>
          </a>
          <span v-if="item.locale"> ({{ item.locale }})</span>
        </li>
      </ul>
    </div>

    <div v-if="failed.length > 0" class="result blockers" role="alert">
      <h2 class="result-title">Could not publish</h2>
      <ul class="result-list">
        <li v-for="(item, i) in failed" :key="`fail-${i}`">
          <a class="page-link" :href="withBase(resultHref(item))" @click="go($event, resultHref(item))">
            <strong>{{ itemLabel(item) }}</strong>
          </a>
          <span v-if="item.locale"> ({{ item.locale }})</span>
          — {{ item.reason }}
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { withBase } from '../../lib/base.js';
import { contentEditorHref } from '../../lib/contentHref.js';

const emit = defineEmits(['navigate']);

const STATE_LABELS = {
  in_review: 'Waiting for review',
  changes_requested: 'Changes requested',
};

const pages = ref([]);
const entries = ref([]);
const selectedPages = ref([]);
const selectedEntries = ref([]);
const locale = ref('en');
const loading = ref(false);
const loaded = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const blockers = ref([]);
const published = ref([]);
const failed = ref([]);

const selectionCount = computed(
  () => selectedPages.value.length + selectedEntries.value.length,
);

const slugOf = (page) => page.slug ?? String(page.key ?? '').split(':').pop();

const entryKey = (entry) => `${entry.type}:${entry.slug}`;

const pageHref = (page) => contentEditorHref({ type: 'page', page: page.slug, locale: locale.value });
const entryHref = (entry) => contentEditorHref({
  type: entry.type,
  page: entry.slug,
  locale: locale.value,
});
const resultHref = (item) => contentEditorHref({
  type: item?.type || 'page',
  page: item?.page || '',
  locale: item?.locale || locale.value,
});

const go = (event, href) => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  emit('navigate', href);
};

const entryTitle = (entry, type) => {
  const field = type?.titleField || 'title';
  const values = entry?.data?.values;
  if (values && typeof values === 'object' && values[field]) {
    return String(values[field]);
  }
  return entry.slug ?? String(entry.key ?? '').split(':').pop();
};

const stateLabel = (item) => STATE_LABELS[item?.state] ?? (item?.state || 'Not ready');

const itemLabel = (item) => {
  const type = item?.type && item.type !== 'page' ? item.type : null;
  return type ? `${type}/${item.page}` : item.page;
};

/**
 * Match an open review to a document for the release locale. A review in another
 * language or on a different content type does not block this readiness column.
 */
const reviewFor = (slug, releaseLocale, open, type = 'page') => {
  const want = String(releaseLocale || '').toLowerCase();
  const wantType = type || 'page';
  return open.find((item) => {
    if ((item.page || '') !== slug) return false;
    const itemType = item.type || 'page';
    if (itemType !== wantType) return false;
    const itemLocale = String(item.locale || '').toLowerCase();
    return !want || !itemLocale || itemLocale === want;
  }) ?? null;
};

const load = async () => {
  loading.value = true;
  error.value = '';
  notice.value = '';
  try {
    const [pagesRes, reviewRes, collectionsRes] = await Promise.all([
      fetch('/api/pages'),
      fetch('/api/collaboration/review'),
      fetch('/api/collections'),
    ]);

    if (!pagesRes.ok) {
      pages.value = [];
      entries.value = [];
      error.value = 'Could not load pages. Please try again.';
      return;
    }

    const pagesBody = await pagesRes.json();
    const list = Array.isArray(pagesBody.data) ? pagesBody.data : [];
    locale.value = pagesBody.locale || 'en';

    let open = [];
    if (reviewRes.ok) {
      const reviewBody = await reviewRes.json();
      open = Array.isArray(reviewBody.data?.open) ? reviewBody.data.open : [];
    } else if (reviewRes.status !== 403) {
      notice.value = 'Could not load open reviews; readiness may be incomplete.';
    }

    const releaseLocale = locale.value;
    pages.value = list.map((page) => {
      const slug = slugOf(page);
      return {
        slug,
        title: page.data?.title || slug,
        review: reviewFor(slug, releaseLocale, open, 'page'),
      };
    });

    const nextEntries = [];
    if (collectionsRes.ok) {
      const collectionsBody = await collectionsRes.json();
      const types = Array.isArray(collectionsBody.data) ? collectionsBody.data : [];
      const entryLists = await Promise.all(types.map(async (type) => {
        const id = type?.id;
        if (!id) return [];
        try {
          const res = await fetch(`/api/collections/${encodeURIComponent(id)}/entries`);
          if (!res.ok) return [];
          const body = await res.json();
          const rows = Array.isArray(body.data) ? body.data : [];
          return rows.map((entry) => {
            const slug = entry.slug ?? String(entry.key ?? '').split(':').pop();
            return {
              type: id,
              typeLabel: type.label || id,
              slug,
              title: entryTitle(entry, type),
              review: reviewFor(slug, releaseLocale, open, id),
            };
          });
        } catch {
          return [];
        }
      }));
      for (const batch of entryLists) {
        nextEntries.push(...batch);
      }
    } else if (collectionsRes.status !== 403 && notice.value === '') {
      notice.value = 'Could not load collection entries; only pages are listed.';
    }
    entries.value = nextEntries;

    // Drop selections that no longer exist after a refresh.
    const slugs = new Set(pages.value.map((p) => p.slug));
    selectedPages.value = selectedPages.value.filter((s) => slugs.has(s));
    const keys = new Set(entries.value.map(entryKey));
    selectedEntries.value = selectedEntries.value.filter((k) => keys.has(k));
  } catch {
    pages.value = [];
    entries.value = [];
    error.value = 'Could not load pages. Please try again.';
  } finally {
    loading.value = false;
    loaded.value = true;
  }
};

const publish = async () => {
  if (selectionCount.value === 0 || busy.value) return;

  busy.value = true;
  error.value = '';
  notice.value = '';
  blockers.value = [];
  published.value = [];
  failed.value = [];

  try {
    const payload = {
      locale: locale.value || 'en',
    };
    if (selectedPages.value.length > 0) {
      payload.pages = [...selectedPages.value];
    }
    if (selectedEntries.value.length > 0) {
      payload.entries = selectedEntries.value.map((key) => {
        const colon = key.indexOf(':');
        return {
          type: key.slice(0, colon),
          page: key.slice(colon + 1),
        };
      });
    }

    const res = await fetch('/api/collaboration/release', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await res.json().catch(() => ({}));

    if (res.status === 409) {
      blockers.value = Array.isArray(body.data?.refused) ? body.data.refused : [];
      error.value = body.error || 'This release was not published because some of its items are not ready.';
      return;
    }

    if (res.status === 500 && Array.isArray(body.data?.failed)) {
      published.value = Array.isArray(body.data?.published) ? body.data.published : [];
      failed.value = body.data.failed;
      error.value = body.error || 'Part of this release could not be published.';
      return;
    }

    if (!res.ok) {
      error.value = body.error || 'Could not publish this release. Please try again.';
      return;
    }

    published.value = Array.isArray(body.data?.published) ? body.data.published : [];
    notice.value = published.value.length
      ? 'Release published.'
      : 'Nothing to publish.';
  } catch {
    error.value = 'Could not publish this release. Please try again.';
  } finally {
    busy.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.release { max-width: 1200px; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0 0 1rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); background: var(--app-surface-strong); border: 1px solid var(--color-danger-600, #dc2626); }
.banner.notice { color: var(--app-text); background: var(--app-surface-strong); border: 1px solid var(--app-border); }
.loading { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.empty { color: var(--app-text-muted); font-size: 0.875rem; }
.locale-row { margin-bottom: 1.25rem; max-width: 20rem; }
.locale-label { display: block; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--app-text-muted); margin-bottom: 0.35rem; }
.locale-input { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--control-border); border-radius: 6px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.locale-hint { margin: 0.35rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.release-section { margin-bottom: 1.5rem; }
.section-title { margin: 0 0 0.75rem; font-size: 1.125rem; font-weight: 600; color: var(--app-text); }
.table-wrap { overflow-x: auto; margin-bottom: 0.25rem; }
.release-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.release-table th { text-align: left; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--app-text-muted); padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--app-border); }
.release-table td { padding: 0.75rem; border-bottom: 1px solid var(--app-border); color: var(--app-text); vertical-align: middle; }
.col-check { width: 2.5rem; }
.page-slug { font-weight: 600; cursor: pointer; }
.page-link { color: var(--color-primary-700, var(--color-primary-600, #4338ca)); text-decoration: none; }
.page-link:hover { text-decoration: underline; }
.readiness-note { margin: 0.75rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.review-badge { display: inline-block; padding: 0.2rem 0.55rem; font-size: 0.8125rem; font-weight: 600; border-radius: 999px; border: 1px solid var(--app-border); background: var(--app-surface-strong); color: var(--app-text); white-space: nowrap; }
.review-badge.is-ready { color: var(--color-success-800, #166534); border-color: var(--color-success-600, #16a34a); background: color-mix(in srgb, var(--color-success-600, #16a34a) 10%, transparent); }
.review-badge.is-in_review { color: var(--color-warning-800, #92400e); border-color: var(--color-warning-600, #d97706); background: color-mix(in srgb, var(--color-warning-600, #d97706) 10%, transparent); }
.review-badge.is-changes_requested { color: var(--color-danger-700, #b91c1c); border-color: var(--color-danger-600, #dc2626); background: color-mix(in srgb, var(--color-danger-600, #dc2626) 8%, transparent); }
.actions { margin: 1rem 0 1.5rem; }
.btn-primary { padding: 0.625rem 1.25rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; font: inherit; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8125rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); font: inherit; white-space: nowrap; }
.btn-sm:disabled { opacity: 0.6; cursor: not-allowed; }
.result { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid var(--app-border); background: var(--app-surface-strong); }
.result.blockers { border-color: var(--color-danger-600, #dc2626); }
.result.success { border-color: var(--color-success-600, #16a34a); }
.result-title { margin: 0 0 0.35rem; font-size: 1rem; color: var(--app-text); }
.result-detail { margin: 0 0 0.75rem; font-size: 0.875rem; color: var(--app-text-muted); }
.result-list { margin: 0; padding-left: 1.25rem; font-size: 0.875rem; color: var(--app-text); }
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

button:focus-visible,
input:focus-visible,
a:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
