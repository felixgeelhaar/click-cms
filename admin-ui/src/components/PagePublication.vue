<template>
  <!--
    Deliberately a banner and not a badge.

    The failure this guards against is an editor saving, opening the live site,
    seeing nothing change and concluding the CMS is broken. A small coloured pill
    beside the title is exactly what somebody in that state scrolls past, so the
    state gets a full-width strip with a sentence in it that says what the public
    can currently see.
  -->
  <section class="pub" :class="tone" role="status" aria-live="polite">
    <div class="pub-body">
      <p class="pub-headline">
        <span class="pub-dot" aria-hidden="true"></span>{{ headline }}
      </p>
      <p class="pub-detail">{{ detail }}</p>

      <p v-if="livePath" class="pub-live">
        <a :href="livePath" target="_blank" rel="noopener">
          Open the live page in a new tab
        </a>
      </p>
    </div>

    <div v-if="canPublish || canUnpublish" class="pub-actions">
      <button
        v-if="canUnpublish && publication?.published"
        type="button"
        class="btn-secondary"
        :disabled="busy"
        @click="$emit('unpublish')"
      >
        {{ busy === 'unpublish' ? 'Taking down…' : 'Take down' }}
      </button>

      <button
        v-if="canPublish && !upToDate"
        type="button"
        class="btn-publish"
        :disabled="Boolean(busy) || isNew"
        @click="$emit('publish')"
      >
        {{ busy === 'publish' ? 'Publishing…' : publishLabel }}
      </button>
    </div>

    <!--
      An author has neither content.publish nor content.unpublish. Showing them
      a button that can only ever answer 403 teaches them the product is broken;
      saying who can do it tells them what to do next.
    -->
    <p v-else class="pub-no-permission">
      Your account cannot publish. Ask an editor to put these changes live.
    </p>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  /** The API's `publication` object, or null before it is known. */
  publication: { type: Object, default: null },
  /** Number of saved changes since the version the public is reading, when knowable. */
  pendingCount: { type: Number, default: null },
  canPublish: { type: Boolean, default: false },
  canUnpublish: { type: Boolean, default: false },
  /** '' | 'publish' | 'unpublish' */
  busy: { type: String, default: '' },
  isNew: { type: Boolean, default: false },
  slug: { type: String, default: '' },
  locale: { type: String, default: '' },
  defaultLocale: { type: String, default: '' },
});

defineEmits(['publish', 'unpublish']);

const published = computed(() => props.publication?.published === true);
const pending = computed(() => props.publication?.hasUnpublishedChanges === true);
const never = computed(() => props.publication?.neverPublished === true);

const upToDate = computed(() => published.value && !pending.value);

const publishedAt = computed(() => {
  const at = props.publication?.publishedAt;
  if (!at) return '';
  const parsed = new Date(at);
  return Number.isNaN(parsed.getTime()) ? '' : parsed.toLocaleString();
});

/**
 * The address the public reads this page at.
 *
 * Only the default language lives at the bare path; a translation is addressed
 * with its locale, and pointing an editor at the wrong one would have them
 * checking an English page for a German change.
 */
const livePath = computed(() => {
  if (!published.value || !props.slug) return '';
  const base = `/${props.slug}`;
  return props.locale && props.locale !== props.defaultLocale
    ? `${base}?locale=${encodeURIComponent(props.locale)}`
    : base;
});

const tone = computed(() => {
  if (props.isNew) return 'unsaved';
  if (!props.publication) return 'unsaved';
  if (never.value) return 'never';
  if (!published.value) return 'down';
  return pending.value ? 'pending' : 'live';
});

const changes = computed(() => {
  const n = props.pendingCount;
  if (n === null || n < 1) return '';
  return n === 1 ? '1 change' : `${n} changes`;
});

const headline = computed(() => ({
  unsaved: 'Not published — this page has not been saved yet',
  never: 'Not published — no visitor can see this page',
  down: 'Taken down — this page is no longer on the public site',
  pending: 'Published — but your latest changes are not live',
  live: 'Published — the public site matches what is here',
}[tone.value]));

const detail = computed(() => {
  switch (tone.value) {
    case 'unsaved':
      return 'Save it first. Saving is not publishing: nothing appears on the public '
        + 'site until you publish.';
    case 'never':
      return 'This page has never been on the public site. Saving keeps your work in '
        + 'the CMS, but visitors see nothing until you publish.';
    case 'down':
      return 'Anyone following a link to this page gets a "page not found". Your work '
        + 'is still here — publish to put it back.';
    case 'pending':
      return `Visitors are still reading the version published ${publishedAt.value || 'earlier'}. `
        + `${changes.value ? `Your ${changes.value} since then exist` : 'Your changes since then exist'} `
        + 'only in the CMS until you publish.';
    default:
      return publishedAt.value
        ? `Last published ${publishedAt.value}. There is nothing waiting to go live.`
        : 'There is nothing waiting to go live.';
  }
});

const publishLabel = computed(() => {
  if (tone.value === 'down') return 'Publish again';
  if (tone.value === 'pending') return changes.value ? `Publish ${changes.value}` : 'Publish changes';
  return 'Publish this page';
});
</script>

<style scoped>
.pub {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.25rem;
  margin-bottom: 1.5rem;
  border: 1px solid var(--app-border);
  border-left-width: 6px;
  border-radius: 10px;
  background: var(--app-surface-strong);
}
.pub-body { flex: 1; min-width: 260px; }
.pub-headline { margin: 0; font-size: 1.0625rem; font-weight: 700; color: var(--app-text); display: flex; align-items: center; gap: 0.5rem; }
.pub-detail { margin: 0.35rem 0 0; font-size: 0.875rem; line-height: 1.45; color: var(--app-text-muted); }
.pub-live { margin: 0.5rem 0 0; font-size: 0.8125rem; }
.pub-live a { color: var(--color-primary-600); }
.pub-actions { display: flex; gap: 0.75rem; flex-shrink: 0; }
.pub-no-permission { margin: 0; flex-shrink: 0; font-size: 0.8125rem; color: var(--app-text-muted); max-width: 16rem; }
.pub-dot { width: 10px; height: 10px; border-radius: 999px; flex-shrink: 0; background: currentColor; }

.pub.never, .pub.down, .pub.unsaved { border-left-color: var(--color-danger-500); background: color-mix(in srgb, var(--color-danger-500) 8%, var(--app-surface-strong)); }
.pub.never .pub-dot, .pub.down .pub-dot, .pub.unsaved .pub-dot { color: var(--color-danger-500); }
.pub.pending { border-left-color: var(--color-warning-500); background: color-mix(in srgb, var(--color-warning-500) 10%, var(--app-surface-strong)); }
.pub.pending .pub-dot { color: var(--color-warning-500); }
.pub.live { border-left-color: var(--color-success-500); }
.pub.live .pub-dot { color: var(--color-success-500); }

.btn-publish, .btn-secondary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; font: inherit; white-space: nowrap; }
.btn-publish { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-publish:disabled, .btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

/*
 * Focus. Every control here is reachable by keyboard and, until this rule, none
 * of them said so: the browser default is easy to lose against these surfaces
 * and several controls sit on tinted backgrounds where it disappears entirely.
 * One ring, stated once, on whatever the keyboard is actually on.
 */
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
