<template>
  <section class="review" aria-labelledby="collaboration-review-heading">
    <div class="review-head">
      <h2 id="collaboration-review-heading" class="review-title">Review</h2>
      <button
        type="button"
        class="btn-sm"
        :disabled="loading || Boolean(busy)"
        aria-label="Reload review state for this page"
        @click="load"
      >
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <p v-if="!gateEnabled" class="review-note" role="status">
      Review gating is off — publishing does not require approval. You can still
      request a review to coordinate with your team.
    </p>

    <p v-if="error" class="review-error" role="alert">{{ error }}</p>

    <p v-if="loading && !loaded" class="review-empty">Loading…</p>

    <template v-else>
      <p class="review-state" role="status">
        <span class="review-state-label">Status</span>
        <span class="review-badge" :class="`is-${stateKey}`">{{ stateLabel }}</span>
      </p>

      <dl v-if="hasDetails" class="review-details">
        <template v-if="review.requestedByName || review.requestedBy">
          <dt>Requested by</dt>
          <dd>{{ review.requestedByName || review.requestedBy }}</dd>
        </template>
        <template v-if="review.requestedAt">
          <dt>Requested</dt>
          <dd>{{ formatWhen(review.requestedAt) }}</dd>
        </template>
        <template v-if="review.note">
          <dt>Request note</dt>
          <dd class="review-detail-note">{{ review.note }}</dd>
        </template>
        <template v-if="review.reviewer">
          <dt>Suggested reviewer</dt>
          <dd>{{ review.reviewer }}</dd>
        </template>
        <template v-if="review.decidedByName || review.decidedBy">
          <dt>Decided by</dt>
          <dd>{{ review.decidedByName || review.decidedBy }}</dd>
        </template>
        <template v-if="review.decidedAt">
          <dt>Decided</dt>
          <dd>{{ formatWhen(review.decidedAt) }}</dd>
        </template>
        <template v-if="review.decisionNote">
          <dt>Decision note</dt>
          <dd class="review-detail-note">{{ review.decisionNote }}</dd>
        </template>
      </dl>

      <!-- Request a new review when none is open. -->
      <form
        v-if="canRequest"
        class="review-form"
        @submit.prevent="requestReview"
      >
        <p class="review-form-intro">Ask someone to look over this page before it goes live.</p>
        <label class="review-form-label" for="collaboration-review-request-note">Note (optional)</label>
        <textarea
          id="collaboration-review-request-note"
          v-model="requestNote"
          class="review-input"
          rows="2"
          placeholder="What should the reviewer focus on?"
          :disabled="Boolean(busy)"
        ></textarea>
        <div class="review-form-actions">
          <button type="submit" class="btn-primary" :disabled="Boolean(busy)">
            {{ busy === 'request' ? 'Requesting…' : 'Request review' }}
          </button>
        </div>
      </form>

      <!-- Decide an open review. -->
      <div v-if="canDecide" class="review-decision">
        <p class="review-form-intro">This page is waiting for a decision.</p>
        <label class="review-form-label" for="collaboration-review-decision-note">Note (optional)</label>
        <textarea
          id="collaboration-review-decision-note"
          v-model="decisionNote"
          class="review-input"
          rows="2"
          placeholder="Explain your decision…"
          :disabled="Boolean(busy)"
        ></textarea>
        <div class="review-form-actions review-form-actions-split">
          <button
            type="button"
            class="btn-secondary"
            :disabled="Boolean(busy)"
            @click="submitDecision('changes')"
          >
            {{ busy === 'changes' ? 'Saving…' : 'Request changes' }}
          </button>
          <button
            type="button"
            class="btn-primary"
            :disabled="Boolean(busy)"
            @click="submitDecision('approve')"
          >
            {{ busy === 'approve' ? 'Approving…' : 'Approve' }}
          </button>
        </div>
      </div>

      <!-- Cancel an open review. -->
      <form
        v-if="canCancel"
        class="review-form review-cancel"
        @submit.prevent="cancelReview"
      >
        <label class="review-form-label" for="collaboration-review-cancel-note">Cancel review</label>
        <textarea
          id="collaboration-review-cancel-note"
          v-model="cancelNote"
          class="review-input"
          rows="2"
          placeholder="Why is this review being withdrawn? (optional)"
          :disabled="Boolean(busy)"
        ></textarea>
        <div class="review-form-actions">
          <button type="submit" class="btn-secondary" :disabled="Boolean(busy)">
            {{ busy === 'cancel' ? 'Cancelling…' : 'Cancel review' }}
          </button>
        </div>
      </form>
    </template>
  </section>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';

const props = defineProps({
  page: { type: String, required: true },
  locale: { type: String, default: '' },
  /** Content type under review. Defaults to page so existing mounts stay valid. */
  type: { type: String, default: 'page' },
});

const emptyReview = () => ({
  state: '',
  open: false,
  requestedBy: '',
  requestedByName: '',
  requestedAt: '',
  note: '',
  reviewer: '',
  decidedBy: null,
  decidedByName: null,
  decidedAt: null,
  decisionNote: null,
});

const gateEnabled = ref(false);
const review = ref(emptyReview());
const loading = ref(false);
const loaded = ref(false);
const busy = ref('');
const error = ref('');

const requestNote = ref('');
const decisionNote = ref('');
const cancelNote = ref('');

const query = () => {
  const params = new URLSearchParams({ page: props.page });
  if (props.locale) params.set('locale', props.locale);
  if (props.type && props.type !== 'page') params.set('type', props.type);
  return params.toString();
};

const reviewBody = (extra = {}) => ({
  page: props.page,
  locale: props.locale || undefined,
  type: props.type && props.type !== 'page' ? props.type : undefined,
  ...extra,
});

const stateKey = computed(() => {
  const state = review.value.state || 'none';
  return state === '' ? 'none' : state;
});

const STATE_LABELS = {
  none: 'No review',
  in_review: 'Waiting for review',
  approved: 'Approved',
  changes_requested: 'Changes requested',
  cancelled: 'Cancelled',
  published: 'Published',
};

const stateLabel = computed(() => STATE_LABELS[stateKey.value] ?? review.value.state);

const hasDetails = computed(() => {
  const r = review.value;
  return Boolean(
    r.requestedByName || r.requestedBy || r.requestedAt || r.note || r.reviewer
      || r.decidedByName || r.decidedBy || r.decidedAt || r.decisionNote,
  );
});

const canRequest = computed(() => !review.value.open && !busy.value);
const canDecide = computed(() => review.value.state === 'in_review' && !busy.value);
const canCancel = computed(() => review.value.open && !busy.value);

const loadSettings = async () => {
  try {
    const res = await fetch('/api/collaboration/review/settings');
    if (!res.ok) return;
    const body = await res.json();
    gateEnabled.value = body.data?.enabled === true;
  } catch {
    // Leave gateEnabled false; the panel still works for optional reviews.
  }
};

const load = async () => {
  if (!props.page) return;
  loading.value = true;
  error.value = '';
  try {
    await loadSettings();
    const res = await fetch(`/api/collaboration/review?${query()}`);
    if (!res.ok) {
      error.value = res.status === 403
        ? 'You do not have permission to view review state on this page.'
        : 'Could not load review state. Please try again.';
      review.value = emptyReview();
      return;
    }
    const body = await res.json();
    review.value = { ...emptyReview(), ...(body.data ?? {}) };
  } catch {
    error.value = 'Could not load review state. Please try again.';
    review.value = emptyReview();
  } finally {
    loading.value = false;
    loaded.value = true;
  }
};

const readError = async (res) => {
  try {
    const body = await res.json();
    if (typeof body.error === 'string' && body.error !== '') return body.error;
  } catch {
    // Fall through to a generic message.
  }
  return null;
};

const requestReview = async () => {
  if (busy.value) return;
  busy.value = 'request';
  error.value = '';
  try {
    const res = await fetch('/api/collaboration/review', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(reviewBody({
        note: requestNote.value.trim(),
      })),
    });
    if (!res.ok) {
      error.value = await readError(res) || 'Could not request a review. Please try again.';
      return;
    }
    requestNote.value = '';
    await load();
  } catch {
    error.value = 'Could not request a review. Please try again.';
  } finally {
    busy.value = '';
  }
};

const submitDecision = async (decision) => {
  if (busy.value) return;
  busy.value = decision;
  error.value = '';
  try {
    const res = await fetch('/api/collaboration/review/decision', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(reviewBody({
        decision,
        note: decisionNote.value.trim(),
      })),
    });
    if (!res.ok) {
      error.value = await readError(res) || 'Could not record the decision. Please try again.';
      return;
    }
    decisionNote.value = '';
    await load();
  } catch {
    error.value = 'Could not record the decision. Please try again.';
  } finally {
    busy.value = '';
  }
};

const cancelReview = async () => {
  if (busy.value) return;
  busy.value = 'cancel';
  error.value = '';
  try {
    const res = await fetch('/api/collaboration/review/cancel', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(reviewBody({
        note: cancelNote.value.trim(),
      })),
    });
    if (!res.ok) {
      error.value = await readError(res) || 'Could not cancel the review. Please try again.';
      return;
    }
    cancelNote.value = '';
    await load();
  } catch {
    error.value = 'Could not cancel the review. Please try again.';
  } finally {
    busy.value = '';
  }
};

const formatWhen = (value) => {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value ?? '') : parsed.toLocaleString();
};

watch(() => [props.page, props.locale, props.type], () => {
  loaded.value = false;
  load();
});

onMounted(load);
</script>

<style scoped>
.review { max-width: 40rem; margin-top: 2rem; }
.review-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.review-title { margin: 0; font-size: 1.125rem; font-weight: 600; color: var(--app-text); }
.review-note { margin: 0.75rem 0 0; padding: 0.6rem 0.85rem; font-size: 0.875rem; line-height: 1.45; border-radius: 8px; color: var(--app-text-muted); border: 1px solid var(--app-border); background: var(--app-surface-strong); }
.review-error { margin: 0.75rem 0 0; padding: 0.6rem 0.85rem; font-size: 0.875rem; border-radius: 8px; color: var(--color-danger-600, #dc2626); border: 1px solid var(--color-danger-600, #dc2626); background: color-mix(in srgb, var(--color-danger-600, #dc2626) 8%, transparent); }
.review-empty { margin: 1rem 0 0; font-size: 0.9375rem; color: var(--app-text-muted); }
.review-state { margin: 1rem 0 0; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.review-state-label { font-size: 0.8125rem; font-weight: 600; color: var(--app-text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.review-badge { display: inline-block; padding: 0.2rem 0.55rem; font-size: 0.8125rem; font-weight: 600; border-radius: 999px; border: 1px solid var(--app-border); background: var(--app-surface-strong); color: var(--app-text); }
.review-badge.is-in_review { color: var(--color-warning-800, #92400e); border-color: var(--color-warning-600, #d97706); background: color-mix(in srgb, var(--color-warning-600, #d97706) 10%, transparent); }
.review-badge.is-approved { color: var(--color-success-700, #15803d); border-color: var(--color-success-600, #16a34a); background: color-mix(in srgb, var(--color-success-600, #16a34a) 10%, transparent); }
.review-badge.is-changes_requested { color: var(--color-danger-700, #b91c1c); border-color: var(--color-danger-600, #dc2626); background: color-mix(in srgb, var(--color-danger-600, #dc2626) 8%, transparent); }
.review-details { margin: 0.85rem 0 0; display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; font-size: 0.875rem; }
.review-details dt { margin: 0; font-weight: 600; color: var(--app-text-muted); }
.review-details dd { margin: 0; color: var(--app-text); }
.review-detail-note { white-space: pre-wrap; overflow-wrap: anywhere; }
.review-form { margin-top: 1.25rem; }
.review-decision { margin-top: 1.25rem; }
.review-form-intro { margin: 0 0 0.6rem; font-size: 0.875rem; color: var(--app-text-muted); }
.review-form-label { display: block; font-size: 0.8125rem; font-weight: 600; color: var(--app-text-muted); margin-bottom: 0.35rem; }
.review-input { width: 100%; box-sizing: border-box; padding: 0.6rem 0.75rem; font: inherit; font-size: 0.9375rem; color: var(--app-text); background: var(--app-surface); border: 1px solid var(--control-border); border-radius: 8px; resize: vertical; }
.review-form-actions { margin-top: 0.6rem; display: flex; justify-content: flex-end; gap: 0.5rem; }
.review-form-actions-split { justify-content: space-between; }
.review-cancel { padding-top: 1rem; border-top: 1px solid var(--app-border); }
.btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8125rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); font: inherit; white-space: nowrap; }
.btn-sm:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-primary { padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; color: #fff; background: var(--color-primary-600, #4f46e5); font: inherit; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-secondary { padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border: 1px solid var(--control-border); border-radius: 8px; cursor: pointer; color: var(--app-text); background: var(--app-surface-strong); font: inherit; }
.btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
summary:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
