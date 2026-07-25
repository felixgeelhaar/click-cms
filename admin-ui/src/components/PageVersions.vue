<template>
  <section class="versions" aria-labelledby="page-versions-heading">
    <div class="versions-head">
      <h2 id="page-versions-heading" class="versions-heading">Version history</h2>
      <button
        type="button"
        class="btn-sm"
        :disabled="loading"
        aria-label="Reload the version history for this page"
        @click="$emit('reload')"
      >
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </div>

    <!--
      Stated up front rather than beside the button, because an editor who
      believes restore rewinds the public site is wrong in a way that costs
      either an unnoticed regression or an unnecessary panic.
    -->
    <p class="versions-note">
      Restoring changes the working copy, not the live page. The public site keeps
      showing whatever is published until you publish again — and the restore
      itself is recorded as a new version, so it can be undone the same way.
    </p>

    <!--
      History is per translation: the endpoints take the same ?locale= the rest
      of the page API does, so a German page shows German versions. This used to
      answer for the default language whatever was edited, and the panel refused
      to show anything else rather than offer to restore German text from an
      English version. That constraint is gone.
    -->
    <p v-if="error" class="versions-error" role="alert">{{ error }}</p>

    <p v-if="loading && !versions.length" class="versions-empty">Loading…</p>

    <p v-else-if="!versions.length" class="versions-empty">
      No versions recorded yet. Every save from here on leaves one behind.
    </p>

    <ol v-else class="versions-list">
        <li v-for="(version, index) in versions" :key="version.id" class="version">
          <div class="version-body">
            <p class="version-when">
              {{ formatWhen(version.recordedAt) }}
              <span v-if="index === 0" class="version-tag current">Working copy</span>
              <span v-else-if="version.reason === 'publish'" class="version-tag live">
                Published from here
              </span>
              <span v-else class="version-tag">{{ reasonLabel(version.reason) }}</span>
            </p>
            <p class="version-meta">
              {{ version.title || 'Untitled' }} · {{ version.author || 'unknown author' }}
            </p>
          </div>

          <button
            v-if="canRestore && index !== 0 && confirming !== version.id"
            type="button"
            class="btn-sm"
            :disabled="Boolean(restoring)"
            :aria-label="`Restore the working copy to the version saved ${formatWhen(version.recordedAt)}`"
            @click="confirming = version.id"
          >
            {{ restoring === version.id ? 'Restoring…' : 'Restore' }}
          </button>

          <!--
            Confirmed in the page rather than through window.confirm. A native
            dialog cannot say this in the product's own voice, and what needs
            saying — that the public site does not move — is the whole reason
            to ask twice.
          -->
          <div v-else-if="confirming === version.id" class="confirm" role="group"
               :aria-label="`Confirm restoring the version saved ${formatWhen(version.recordedAt)}`">
            <p class="confirm-text">
              Replace the working copy with this version? The live page does not
              change until you publish.
            </p>
            <button
              type="button"
              class="btn-sm primary"
              :disabled="Boolean(restoring)"
              :aria-label="`Confirm restore of the version saved ${formatWhen(version.recordedAt)}`"
              @click="$emit('restore', version)"
            >
              {{ restoring === version.id ? 'Restoring…' : 'Yes, restore' }}
            </button>
            <button
              type="button"
              class="btn-sm"
              :disabled="Boolean(restoring)"
              aria-label="Cancel this restore"
              @click="confirming = ''"
            >
              Cancel
            </button>
          </div>
        </li>
    </ol>
  </section>
</template>

<script setup>
import { ref } from 'vue';

const confirming = ref('');

defineProps({
  versions: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  canRestore: { type: Boolean, default: false },
  /** The id currently being restored, or '' . */
  restoring: { type: String, default: '' },
});

defineEmits(['restore', 'reload']);

const formatWhen = (value) => {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
};

const reasonLabel = (reason) => ({
  save: 'Saved',
  restore: 'Restored',
  publish: 'Published from here',
  delete: 'Before deletion',
}[reason] || 'Saved');
</script>

<style scoped>
.versions { margin-top: 1.5rem; padding: 1rem 1.25rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--app-surface-strong); }
.versions-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.versions-heading { margin: 0; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--app-text-muted); }
.versions-note { margin: 0.75rem 0 0; font-size: 0.8125rem; line-height: 1.45; color: var(--app-text-muted); }
.versions-note.warn { color: var(--color-warning-text, #b45309); }
.versions-error { margin: 0.75rem 0 0; font-size: 0.8125rem; color: var(--color-danger-600, #dc2626); }
.versions-empty { margin: 0.75rem 0 0; font-size: 0.875rem; color: var(--app-text-muted); }
.versions-list { list-style: none; margin: 0.75rem 0 0; padding: 0; }
.version { display: flex; align-items: center; gap: 1rem; padding: 0.6rem 0; border-top: 1px solid var(--app-border); }
.version:first-child { border-top: none; }
.version-body { flex: 1; min-width: 0; }
.version-when { margin: 0; font-size: 0.875rem; font-weight: 500; color: var(--app-text); display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.version-meta { margin: 0.15rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.version-tag { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.1rem 0.4rem; border-radius: 999px; border: 1px solid var(--app-border); color: var(--app-text-muted); }
.version-tag.current { border-color: var(--color-primary-600); color: var(--color-primary-600); }
.version-tag.live { border-color: var(--color-success-500); color: var(--color-success-text, #15803d); }
.confirm { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 0.5rem; max-width: 24rem; }
.confirm-text { flex-basis: 100%; margin: 0; font-size: 0.8125rem; line-height: 1.4; color: var(--app-text-muted); text-align: right; }
.btn-sm.primary { background: var(--color-primary-600); color: white; border-color: var(--color-primary-600); }
.btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8125rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); font: inherit; white-space: nowrap; }
.btn-sm:disabled { opacity: 0.6; cursor: not-allowed; }

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
