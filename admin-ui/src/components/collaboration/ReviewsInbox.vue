<template>
  <div class="reviews">
    <div class="page-header">
      <div>
        <h1 class="page-title">Reviews</h1>
        <p class="page-subtitle">Pages waiting on a review decision.</p>
      </div>
      <button
        type="button"
        class="btn-sm"
        :disabled="loading"
        aria-label="Reload open reviews"
        @click="load"
      >
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>

    <p v-if="loading && !loaded" class="loading">Loading…</p>
    <p v-else-if="!error && reviews.length === 0" class="empty">
      No open reviews. When someone asks for a look at a page, it shows up here.
    </p>
    <div v-else-if="!error" class="table-wrap">
      <table class="review-table">
        <thead>
          <tr>
            <th>Page</th>
            <th>Locale</th>
            <th>State</th>
            <th>Requester</th>
            <th>Assignee</th>
            <th>Requested</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in reviews" :key="rowKey(item)">
            <td>
              <a
                class="page-link"
                :href="withBase(editorHref(item))"
                @click="go($event, editorHref(item))"
              >{{ item.page }}</a>
            </td>
            <td>{{ item.locale || '—' }}</td>
            <td>
              <span class="review-badge" :class="`is-${stateKey(item)}`">{{ stateLabel(item) }}</span>
            </td>
            <td>{{ requester(item) }}</td>
            <td>{{ assignee(item) }}</td>
            <td>
              <time v-if="item.requestedAt" :datetime="item.requestedAt">{{ formatWhen(item.requestedAt) }}</time>
              <span v-else>—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { withBase } from '../../lib/base.js';

const emit = defineEmits(['navigate']);

const STATE_LABELS = {
  in_review: 'Waiting for review',
  changes_requested: 'Changes requested',
};

const reviews = ref([]);
const loading = ref(false);
const loaded = ref(false);
const error = ref('');

const rowKey = (item) => `${item.page ?? ''}:${item.locale ?? ''}:${item.requestedAt ?? ''}`;

const stateKey = (item) => item.state || 'none';

const stateLabel = (item) => STATE_LABELS[item.state] ?? (item.state || '—');

const requester = (item) => item.requestedByName || item.requestedBy || '—';

const assignee = (item) => item.reviewer || '—';

/**
 * The page editor route AdminApp already matches: `/admin/pages/edit/{slug}`,
 * with `?locale=` so a translation opens as that language rather than the default.
 */
const editorHref = (item) => {
  const slug = encodeURIComponent(item.page || '');
  const path = `/admin/pages/edit/${slug}`;
  return item.locale ? `${path}?locale=${encodeURIComponent(item.locale)}` : path;
};

const go = (event, href) => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  emit('navigate', href);
};

const formatWhen = (value) => {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value ?? '') : parsed.toLocaleString();
};

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    // No `page` query: that is the inbox, not one page's review state.
    const res = await fetch('/api/collaboration/review');
    if (!res.ok) {
      reviews.value = [];
      error.value = res.status === 403
        ? 'You do not have permission to view open reviews.'
        : 'Could not load open reviews. Please try again.';
      return;
    }
    const body = await res.json();
    const open = body.data?.open;
    reviews.value = Array.isArray(open) ? open : [];
  } catch {
    reviews.value = [];
    error.value = 'Could not load open reviews. Please try again.';
  } finally {
    loading.value = false;
    loaded.value = true;
  }
};

onMounted(load);
</script>

<style scoped>
.reviews { max-width: 1200px; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0 0 1rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); background: var(--app-surface-strong); border: 1px solid var(--color-danger-600, #dc2626); }
.loading { text-align: center; padding: 3rem; color: var(--app-text-muted); }
.empty { color: var(--app-text-muted); font-size: 0.875rem; }
.table-wrap { overflow-x: auto; }
.review-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.review-table th { text-align: left; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--app-text-muted); padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--app-border); }
.review-table td { padding: 0.75rem; border-bottom: 1px solid var(--app-border); color: var(--app-text); vertical-align: middle; }
.page-link { color: var(--color-primary-700, var(--color-primary-600, #4338ca)); font-weight: 600; text-decoration: none; }
.page-link:hover { text-decoration: underline; }
.review-badge { display: inline-block; padding: 0.2rem 0.55rem; font-size: 0.8125rem; font-weight: 600; border-radius: 999px; border: 1px solid var(--app-border); background: var(--app-surface-strong); color: var(--app-text); white-space: nowrap; }
.review-badge.is-in_review { color: var(--color-warning-800, #92400e); border-color: var(--color-warning-600, #d97706); background: color-mix(in srgb, var(--color-warning-600, #d97706) 10%, transparent); }
.review-badge.is-changes_requested { color: var(--color-danger-700, #b91c1c); border-color: var(--color-danger-600, #dc2626); background: color-mix(in srgb, var(--color-danger-600, #dc2626) 8%, transparent); }
.btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8125rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); font: inherit; white-space: nowrap; }
.btn-sm:disabled { opacity: 0.6; cursor: not-allowed; }

button:focus-visible,
a:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
