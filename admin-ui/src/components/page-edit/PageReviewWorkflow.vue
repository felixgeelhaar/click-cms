<template>
  <section class="page-review" aria-labelledby="page-review-title">
    <div class="review-heading">
      <div>
        <h2 id="page-review-title">Review</h2>
        <p class="review-state" :class="`state-${review.state || 'none'}`">{{ stateLabel }}</p>
      </div>
      <button v-if="review.history?.length" type="button" class="history-toggle" @click="showHistory = !showHistory">
        {{ showHistory ? 'Hide history' : `History (${review.history.length})` }}
      </button>
    </div>

    <p v-if="unavailable" class="review-message">Review workflow is not available on this site.</p>
    <p v-else-if="error" class="review-message error" role="alert">{{ error }}</p>
    <p v-else-if="loading" class="review-message">Loading review status…</p>
    <template v-else>
      <p v-if="review.state === 'in_review' && review.stale" class="review-message error">
        The page changed after this review was requested. Refresh the request for the current saved version.
      </p>
      <p v-else-if="review.state === 'in_review'" class="review-message">
        Waiting for {{ review.reviewer || 'a reviewer' }}. Requested by {{ review.requestedByName || review.requestedBy }}.
      </p>
      <p v-else-if="review.state === 'changes_requested'" class="review-message">
        {{ review.decidedByName || 'A reviewer' }} asked for changes. Update this page, then request another review.
      </p>
      <p v-else-if="review.state === 'approved' && review.stale" class="review-message error">
        The page changed after approval. Save the current version and request another review.
      </p>
      <p v-else-if="review.state === 'published' && review.stale" class="review-message error">
        The page changed after the review was completed. Save the current version and request another review.
      </p>
      <p v-else-if="review.state === 'approved'" class="review-message">
        Approved by {{ review.decidedByName || 'a reviewer' }}. Publishing remains a separate action.
      </p>
      <p v-else-if="review.state === 'published'" class="review-message">This review cycle has been published.</p>
      <p v-if="review.note" class="request-note">Request: {{ review.note }}</p>
      <p v-if="review.decisionNote" class="request-note">Reviewer: {{ review.decisionNote }}</p>

      <form v-if="canRequest && (review.state !== 'in_review' || review.stale) && (review.state !== 'approved' || review.stale)" class="request-form" @submit.prevent="requestReview">
        <label :for="`review-note-${slug}`">Ask for review <span>(optional note)</span></label>
        <textarea :id="`review-note-${slug}`" data-testid="review-note" v-model="note" maxlength="2000" rows="2" placeholder="What should the reviewer focus on?"></textarea>
        <label :for="`reviewer-id-${slug}`">Reviewer username <span>(optional)</span></label>
        <input :id="`reviewer-id-${slug}`" data-testid="reviewer-id" v-model="reviewer" maxlength="120" autocomplete="off" placeholder="Leave blank for the review inbox">
        <button type="submit" class="request-button" data-testid="request-review" :disabled="busy || dirty || !savedRevision">{{ busy ? 'Sending…' : review.state === 'changes_requested' || review.stale ? 'Request review again' : 'Request review' }}</button>
        <p v-if="dirty || !savedRevision" class="save-first">Save the page before requesting a review.</p>
      </form>

      <ol v-if="showHistory" class="review-history">
        <li v-for="(event, index) in review.history" :key="`${event.at}-${index}`">
          <strong>{{ labelFor(event.state) }}</strong> · {{ event.byName || event.by || 'unknown' }} · {{ formatWhen(event.at) }}
          <p v-if="event.note">{{ event.note }}</p>
        </li>
      </ol>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
  slug: { type: String, required: true },
  locale: { type: String, required: true },
  currentUsername: { type: String, default: '' },
  canRequest: { type: Boolean, default: false },
  savedRevision: { type: String, default: '' },
  refreshToken: { type: [String, Number], default: '' },
  dirty: { type: Boolean, default: false },
});
const review = ref({ state: '', history: [] });
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const unavailable = ref(false);
const note = ref('');
const reviewer = ref('');
const showHistory = ref(false);
let loadSequence = 0;
const stateNames = {
  '': 'No review requested', in_review: 'Waiting for review', changes_requested: 'Changes requested',
  approved: 'Approved', cancelled: 'Review cancelled', published: 'Published',
};
const labelFor = (state) => stateNames[state] || state || 'Review updated';
const stateLabel = computed(() => loading.value ? 'Loading…' : labelFor(review.value.state));
const formatWhen = (value) => {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

const load = async () => {
  if (!props.slug || !props.locale) return;
  const sequence = ++loadSequence;
  loading.value = true;
  unavailable.value = false;
  error.value = '';
  try {
    const params = new URLSearchParams({ page: props.slug, locale: props.locale });
    const response = await fetch(`/api/collaboration/review?${params}`);
    const body = await response.json().catch(() => ({}));
    if (sequence !== loadSequence) return;
    if (!response.ok) {
      if (response.status === 404) { unavailable.value = true; return; }
      throw new Error(body.error || `Request failed (${response.status})`);
    }
    review.value = body.data ?? { state: '', history: [] };
    reviewer.value = review.value.reviewer || '';
  } catch (exception) {
    if (sequence === loadSequence) error.value = `Could not load review status: ${exception.message}`;
  } finally {
    if (sequence === loadSequence) loading.value = false;
  }
};

const requestReview = async () => {
  if (props.dirty || !props.savedRevision) return;
  busy.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/collaboration/review', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ page: props.slug, locale: props.locale, note: note.value, reviewer: reviewer.value, revision: props.savedRevision }),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      if (response.status === 409 && body.data?.currentRevision) {
        throw new Error('The page changed after it was loaded. Reload the page, save it, then request review again.');
      }
      throw new Error(body.error || `Request failed (${response.status})`);
    }
    note.value = '';
    review.value = body.data ?? review.value;
  } catch (exception) {
    error.value = `Could not request review: ${exception.message}`;
  } finally {
    busy.value = false;
  }
};

watch(() => [props.slug, props.locale, props.savedRevision, props.refreshToken], load, { immediate: true });
</script>

<style scoped>
.page-review { margin: 1rem 0 1.5rem; padding: .9rem 1rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--app-surface-strong); }
.review-heading { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.review-heading h2 { margin: 0; font-size: .96rem; }
.review-state { margin: .2rem 0 0; color: var(--app-text-muted); font-size: .82rem; font-weight: 600; }
.state-approved { color: var(--color-success-700, #15803d); }
.state-changes_requested { color: var(--color-warning-text, #b45309); }
.history-toggle { border: 0; padding: .3rem; background: none; color: var(--color-primary-700, #1d4ed8); text-decoration: underline; cursor: pointer; font: inherit; font-size: .82rem; }
.review-message { margin: .7rem 0 0; font-size: .84rem; }
.review-message.error { color: var(--color-danger-600, #b91c1c); }
.save-first { margin: .2rem 0 0; color: var(--app-text-muted); font-size: .78rem; }
.request-note { margin: .5rem 0 0; white-space: pre-wrap; font-size: .84rem; }
.request-form { display: grid; gap: .45rem; margin-top: .8rem; }
.request-form label { font-size: .82rem; font-weight: 600; }
.request-form label span { color: var(--app-text-muted); font-weight: 400; }
.request-form textarea, .request-form input { width: 100%; box-sizing: border-box; padding: .55rem .65rem; border: 1px solid var(--control-border); border-radius: 7px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.request-form textarea { resize: vertical; }
.request-button { justify-self: start; border: 0; border-radius: 7px; padding: .55rem .85rem; background: var(--color-primary-700, #1d4ed8); color: white; font: inherit; font-weight: 600; cursor: pointer; }
.request-button:disabled { opacity: .6; cursor: wait; }
.review-history { display: grid; gap: .45rem; margin: .8rem 0 0; padding-left: 1.25rem; font-size: .82rem; }
.review-history p { margin: .2rem 0; white-space: pre-wrap; }
.history-toggle:focus-visible, .request-button:focus-visible { outline: 3px solid var(--color-primary-600, #2563eb); outline-offset: 3px; }
</style>
