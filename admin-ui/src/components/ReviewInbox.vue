<template>
  <section class="review-inbox" aria-labelledby="reviews-title">
    <header class="inbox-heading">
      <div>
        <p class="eyebrow">Editorial workflow</p>
        <h1 id="reviews-title">Reviews</h1>
        <p class="intro">Pages waiting for a colleague's review before publication.</p>
      </div>
      <button type="button" class="btn-secondary" :disabled="loading" @click="load">Refresh</button>
    </header>

    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>
    <p v-if="loading && !reviews.length" class="empty">Loading reviews…</p>
    <p v-else-if="!reviews.length" class="empty">No reviews are waiting.</p>

    <ol v-else class="review-list">
      <li v-for="review in reviews" :key="`${review.page}:${review.locale}`" class="review-card">
        <div class="review-topline">
          <div>
            <h2><button type="button" class="page-link" :data-testid="`open-${review.page}`" @click="openPage(review)">{{ review.page }}</button></h2>
            <p class="review-meta">{{ languageName(review.locale) }} · Requested by {{ review.requestedByName || review.requestedBy || 'unknown' }} · {{ formatWhen(review.requestedAt) }}</p>
          </div>
          <span class="review-state">{{ review.state === 'changes_requested' ? 'Changes requested' : 'In review' }}</span>
        </div>
        <p v-if="review.reviewer" class="assigned">For {{ review.reviewer }}</p>
        <p v-if="review.note" class="request-note">{{ review.note }}</p>
        <details v-if="review.history?.length" class="review-history">
          <summary>Review history ({{ review.history.length }})</summary>
          <ol>
            <li v-for="(event, index) in review.history" :key="`${event.at}-${index}`">
              <strong>{{ event.state }}</strong> by {{ event.byName || event.by }} · {{ formatWhen(event.at) }}
              <p v-if="event.note">{{ event.note }}</p>
            </li>
          </ol>
        </details>
        <p v-if="review.stale" class="review-help" role="status">The draft changed after this review was requested. Ask the author to refresh the request before approval.</p>
        <p v-if="review.state === 'changes_requested'" class="review-help">Waiting for the author to update the page and request another review.</p>
        <div v-else-if="canReview" class="review-actions">
          <label :for="`decision-note-${review.page}-${review.locale}`">Decision note <span>(optional)</span></label>
          <textarea :id="`decision-note-${review.page}-${review.locale}`" v-model="notes[keyOf(review)]" rows="2" maxlength="2000" placeholder="Leave a note for the author"></textarea>
          <div class="action-buttons">
            <button v-if="review.requestedBy !== currentUsername" type="button" class="approve" :data-testid="`approve-${review.page}`" :disabled="Boolean(busy) || review.stale || !review.reviewRevision" @click="decide(review, 'approve')">{{ busy === keyOf(review) ? 'Saving…' : 'Approve' }}</button>
            <button type="button" class="changes" :data-testid="`changes-${review.page}`" :disabled="Boolean(busy) || !review.reviewRevision" @click="decide(review, 'changes')">Ask for changes</button>
            <button type="button" class="page-secondary" :data-testid="`open-link-${review.page}`" @click="openPage(review)">Open page</button>
          </div>
        </div>
        <p v-else class="review-help">You can read this review, but your account cannot approve it.</p>
      </li>
    </ol>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
  currentUsername: { type: String, default: '' },
  canReview: { type: Boolean, default: false },
});
const emit = defineEmits(['navigate']);
const reviews = ref([]);
const loading = ref(false);
const busy = ref('');
const error = ref('');
const notice = ref('');
const notes = ref({});

const keyOf = (review) => `${review.page}:${review.locale}`;
const languageName = (code) => {
  try { return new Intl.DisplayNames(undefined, { type: 'language' }).of(code) || code; }
  catch { return code; }
};
const formatWhen = (value) => {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};
const openPage = (review) => emit('navigate', `/admin/pages/edit/${encodeURIComponent(review.page)}?locale=${encodeURIComponent(review.locale)}`);

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/collaboration/review');
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
    reviews.value = Array.isArray(body.data?.open) ? body.data.open : [];
  } catch (exception) {
    error.value = `Could not load reviews: ${exception.message}`;
  } finally {
    loading.value = false;
  }
};

const decide = async (review, decision) => {
  if (!review.reviewRevision || (decision === 'approve' && review.stale)) return;
  const key = keyOf(review);
  busy.value = key;
  error.value = '';
  notice.value = '';
  try {
    const response = await fetch('/api/collaboration/review/decision', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ page: review.page, locale: review.locale, decision, reviewRevision: review.reviewRevision, note: notes.value[key] ?? '' }),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
    notice.value = decision === 'approve' ? `Approved ${review.page} (${languageName(review.locale)}).` : `Asked for changes to ${review.page} (${languageName(review.locale)}).`;
    await load();
  } catch (exception) {
    error.value = `Could not save the review decision: ${exception.message}`;
  } finally {
    busy.value = '';
  }
};

onMounted(load);
</script>

<style scoped>
.review-inbox { max-width: 900px; margin: 0 auto; padding: 1.5rem; }
.inbox-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
.eyebrow { margin: 0 0 0.3rem; color: var(--app-text-muted); font-size: 0.78rem; font-weight: 650; text-transform: uppercase; letter-spacing: .06em; }
h1 { margin: 0; font-size: 1.65rem; }
.intro { margin: .35rem 0 0; color: var(--app-text-muted); }
.btn-secondary, .page-secondary { border: 1px solid var(--control-border); background: var(--app-surface); color: var(--app-text); border-radius: 7px; padding: .55rem .8rem; font: inherit; cursor: pointer; }
.notice, .empty { margin: 1rem 0; padding: .85rem 1rem; border-radius: 8px; background: var(--app-surface-strong); }
.notice.error { color: var(--color-danger-600); }
.review-list { list-style: none; display: grid; gap: 1rem; margin: 0; padding: 0; }
.review-card { padding: 1rem 1.1rem; border: 1px solid var(--app-border); border-radius: 11px; background: var(--card-bg); }
.review-topline { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.review-topline h2 { margin: 0; font-size: 1.05rem; }
.page-link { border: 0; padding: 0; background: none; color: var(--color-primary-700); font: inherit; font-weight: 650; text-decoration: underline; cursor: pointer; }
.page-link:focus-visible, .btn-secondary:focus-visible, .page-secondary:focus-visible, .approve:focus-visible, .changes:focus-visible { outline: 3px solid var(--color-primary-600); outline-offset: 3px; }
.review-meta, .assigned { margin: .35rem 0 0; color: var(--app-text-muted); font-size: .84rem; }
.review-state { padding: .25rem .55rem; border-radius: 99px; background: var(--app-surface-strong); font-size: .76rem; white-space: nowrap; }
.request-note { margin: .9rem 0; white-space: pre-wrap; }
.review-history { margin: .7rem 0; color: var(--app-text-muted); font-size: .84rem; }
.review-history ol { display: grid; gap: .5rem; }
.review-history p { margin: .2rem 0; white-space: pre-wrap; }
.review-actions { display: grid; gap: .45rem; margin-top: 1rem; padding-top: .9rem; border-top: 1px solid var(--app-border); }
.review-actions label { font-size: .82rem; font-weight: 600; }
.review-actions label span { color: var(--app-text-muted); font-weight: 400; }
.review-actions textarea { width: 100%; box-sizing: border-box; min-height: 3.5rem; resize: vertical; padding: .55rem .65rem; border: 1px solid var(--control-border); border-radius: 7px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.action-buttons { display: flex; flex-wrap: wrap; gap: .5rem; }
.approve, .changes { border: 0; border-radius: 7px; padding: .55rem .85rem; font: inherit; font-weight: 600; cursor: pointer; }
.approve { background: var(--color-primary-700); color: white; }
.changes { background: var(--app-surface-strong); color: var(--app-text); }
.approve:disabled, .changes:disabled { opacity: .55; cursor: wait; }
.review-help { margin: .8rem 0 0; color: var(--app-text-muted); font-size: .85rem; }
@media (max-width: 600px) { .review-inbox { padding: 1rem; } .inbox-heading { align-items: center; } }
</style>
