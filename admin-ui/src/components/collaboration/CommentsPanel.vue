<template>
  <section class="comments" aria-labelledby="collaboration-comments-heading">
    <div class="comments-head">
      <h2 id="collaboration-comments-heading" class="comments-title">Comments</h2>
      <button
        type="button"
        class="btn-sm"
        :disabled="loading"
        aria-label="Reload comments for this page"
        @click="load"
      >
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <p v-if="error" class="comments-error" role="alert">{{ error }}</p>

    <p v-if="loading && !comments.length" class="comments-empty">Loading…</p>

    <p v-else-if="!comments.length && !error" class="comments-empty">
      No comments yet. Leave a note for whoever reviews this page.
    </p>

    <!--
      Every comment body was typed by an editor and is rendered with text
      interpolation only — never v-html — so the browser shows it as text and
      cannot be tricked into running it. This is the display half of the
      contract the plugin holds at rest: it stores the bytes as data, this shows
      them as data.
    -->
    <ol v-else class="comments-list">
      <li
        v-for="comment in comments"
        :key="comment.id"
        class="comment"
        :class="{ 'is-resolved': comment.resolved }"
      >
        <div class="comment-head">
          <span class="comment-author">{{ comment.authorName || comment.author || 'Someone' }}</span>
          <span class="comment-when">{{ formatWhen(comment.postedAt) }}</span>
        </div>
        <p class="comment-body">{{ comment.body }}</p>
        <div class="comment-foot">
          <span v-if="comment.resolved" class="comment-badge">Resolved</span>
          <button
            v-else
            type="button"
            class="btn-link"
            :disabled="busyId === comment.id"
            @click="resolve(comment)"
          >
            {{ busyId === comment.id ? 'Resolving…' : 'Resolve' }}
          </button>
        </div>
      </li>
    </ol>

    <form class="comment-form" @submit.prevent="submit">
      <label class="comment-form-label" for="collaboration-comment-body">Add a comment</label>
      <textarea
        id="collaboration-comment-body"
        v-model="draft"
        class="comment-input"
        rows="3"
        placeholder="Leave a note for whoever reviews this page…"
        :disabled="posting"
      ></textarea>
      <div class="comment-form-actions">
        <button type="submit" class="btn-primary" :disabled="posting || !draft.trim()">
          {{ posting ? 'Posting…' : 'Comment' }}
        </button>
      </div>
    </form>
  </section>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';

const props = defineProps({
  // The page and language the thread belongs to. Comments are addressed to a
  // specific document, so both are needed to fetch and to post.
  page: { type: String, required: true },
  locale: { type: String, default: '' },
});

const comments = ref([]);
const draft = ref('');
const loading = ref(false);
const posting = ref(false);
const busyId = ref('');
const error = ref('');

const query = () => {
  const params = new URLSearchParams({ page: props.page });
  if (props.locale) params.set('locale', props.locale);
  return params.toString();
};

const load = async () => {
  if (!props.page) return;
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch(`/api/collaboration/comments?${query()}`);
    if (!res.ok) {
      error.value = res.status === 403
        ? 'You do not have permission to view comments on this page.'
        : 'Could not load comments. Please try again.';
      comments.value = [];
      return;
    }
    const body = await res.json();
    comments.value = Array.isArray(body.data) ? body.data : [];
  } catch {
    error.value = 'Could not load comments. Please try again.';
    comments.value = [];
  } finally {
    loading.value = false;
  }
};

const submit = async () => {
  const body = draft.value.trim();
  if (!body || posting.value) return;
  posting.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/collaboration/comments', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ page: props.page, locale: props.locale, body }),
    });
    if (!res.ok) {
      error.value = 'Could not post the comment. Please try again.';
      return;
    }
    draft.value = '';
    // Reload rather than optimistically append, so the panel shows the server's
    // canonical record — id, timestamp and author as stored.
    await load();
  } catch {
    error.value = 'Could not post the comment. Please try again.';
  } finally {
    posting.value = false;
  }
};

const resolve = async (comment) => {
  if (busyId.value) return;
  busyId.value = comment.id;
  error.value = '';
  try {
    const res = await fetch('/api/collaboration/comments/resolve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: comment.id }),
    });
    if (!res.ok) {
      error.value = 'Could not resolve the comment. Please try again.';
      return;
    }
    await load();
  } catch {
    error.value = 'Could not resolve the comment. Please try again.';
  } finally {
    busyId.value = '';
  }
};

const formatWhen = (value) => {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value ?? '') : parsed.toLocaleString();
};

// Follow the editor when they switch page or language.
watch(() => [props.page, props.locale], load);

onMounted(load);
</script>

<style scoped>
.comments { max-width: 40rem; }
.comments-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.comments-title { margin: 0; font-size: 1.125rem; font-weight: 600; color: var(--app-text); }
.comments-error { margin: 0.75rem 0 0; padding: 0.6rem 0.85rem; font-size: 0.875rem; border-radius: 8px; color: var(--color-danger-600, #dc2626); border: 1px solid var(--color-danger-600, #dc2626); background: color-mix(in srgb, var(--color-danger-600, #dc2626) 8%, transparent); }
.comments-empty { margin: 1rem 0 0; font-size: 0.9375rem; color: var(--app-text-muted); }
.comments-list { list-style: none; margin: 1rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.75rem; }
.comment { padding: 0.85rem 1rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--app-surface-strong); }
.comment.is-resolved { opacity: 0.6; }
.comment-head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; }
.comment-author { font-size: 0.9375rem; font-weight: 600; color: var(--app-text); }
.comment-when { font-size: 0.75rem; color: var(--app-text-muted); }
.comment-body { margin: 0.4rem 0 0; font-size: 0.9375rem; color: var(--app-text); white-space: pre-wrap; overflow-wrap: anywhere; }
.comment-foot { margin-top: 0.5rem; }
.comment-badge { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-success-700, #15803d); }
.comment-form { margin-top: 1.25rem; }
.comment-form-label { display: block; font-size: 0.8125rem; font-weight: 600; color: var(--app-text-muted); margin-bottom: 0.35rem; }
.comment-input { width: 100%; box-sizing: border-box; padding: 0.6rem 0.75rem; font: inherit; font-size: 0.9375rem; color: var(--app-text); background: var(--app-surface); border: 1px solid var(--app-border); border-radius: 8px; resize: vertical; }
.comment-form-actions { margin-top: 0.6rem; display: flex; justify-content: flex-end; }
.btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8125rem; border: 1px solid var(--app-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); font: inherit; white-space: nowrap; }
.btn-sm:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-primary { padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; color: #fff; background: var(--color-primary-600, #4f46e5); font: inherit; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-link { padding: 0; font-size: 0.8125rem; font-weight: 600; border: none; background: none; cursor: pointer; color: var(--color-primary-600, #4f46e5); font: inherit; }
.btn-link:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
