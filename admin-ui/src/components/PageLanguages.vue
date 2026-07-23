<template>
  <!--
    Publication is per language, so English can go live while German sits stale
    and nobody notices. Coupling the publish action would fix that by pushing an
    unreviewed translation live, which trades a visible problem for a silent one
    — so the mitigation is visibility: every configured language, and where it
    stands, on the page being edited.
  -->
  <section class="langs" aria-labelledby="page-languages-heading">
    <h2 id="page-languages-heading" class="langs-heading">Languages</h2>

    <ul class="langs-list">
      <li v-for="code in locales" :key="code">
        <button
          type="button"
          class="lang"
          :class="[stateOf(code), { current: code === current }]"
          :aria-pressed="code === current"
          :aria-label="`${nameOf(code)} — ${labelOf(code)}${code === current ? ' (currently editing)' : ''}`"
          :disabled="busy"
          @click="$emit('select', code)"
        >
          <span class="lang-name">
            {{ nameOf(code) }}<span class="lang-code">{{ code }}</span>
          </span>
          <span class="lang-state">{{ labelOf(code) }}</span>
        </button>
      </li>
    </ul>

    <p v-if="untranslated.length" class="langs-note">
      Not translated yet: {{ untranslated.map(nameOf).join(', ') }}. Switching to
      one of these shows the fields empty — saving there creates that translation.
    </p>

    <p v-if="stale.length" class="langs-note warn">
      Live but out of date: {{ stale.map(nameOf).join(', ') }}. Those visitors are
      reading an older version until each is published on its own.
    </p>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  /** Every language this site publishes in, from GET /api/pages. */
  locales: { type: Array, default: () => [] },
  /** The language being edited right now. */
  current: { type: String, default: '' },
  /**
   * code -> { exists: boolean, publication: object|null }
   * Absent while still being probed, which reads as "unknown" rather than
   * "missing" — claiming a translation does not exist because a request has
   * not come back yet is the sort of quiet wrongness this panel exists to stop.
   */
  translations: { type: Object, default: () => ({}) },
  busy: { type: Boolean, default: false },
});

defineEmits(['select']);

// Intl is in every browser this admin runs in, so language names cost no
// dependency and no translation table to keep current.
const displayNames = (() => {
  try {
    return new Intl.DisplayNames(undefined, { type: 'language' });
  } catch {
    return null;
  }
})();

const nameOf = (code) => {
  try {
    return displayNames?.of(code) || code;
  } catch {
    return code;
  }
};

const stateOf = (code) => {
  const entry = props.translations[code];
  if (!entry) return 'unknown';
  if (!entry.exists) return 'missing';

  const publication = entry.publication;
  if (!publication) return 'unknown';
  if (!publication.published) return publication.neverPublished ? 'never' : 'down';
  return publication.hasUnpublishedChanges ? 'stale' : 'live';
};

const labelOf = (code) => ({
  unknown: 'Checking…',
  missing: 'Not translated',
  never: 'Draft, never published',
  down: 'Taken down',
  stale: 'Live, edits pending',
  live: 'Live and up to date',
}[stateOf(code)]);

const untranslated = computed(() => props.locales.filter((c) => stateOf(c) === 'missing'));
const stale = computed(() => props.locales.filter((c) => c !== props.current && stateOf(c) === 'stale'));
</script>

<style scoped>
.langs { margin-bottom: 1.5rem; padding: 1rem 1.25rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--app-surface-strong); }
.langs-heading { margin: 0 0 0.75rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--app-text-muted); }
.langs-list { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem; }
.lang { display: flex; flex-direction: column; align-items: flex-start; gap: 0.1rem; padding: 0.5rem 0.85rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; cursor: pointer; text-align: left; }
.lang:disabled { opacity: 0.6; cursor: not-allowed; }
.lang.current { border-color: var(--color-primary-600); box-shadow: inset 0 0 0 1px var(--color-primary-600); }
.lang-name { font-size: 0.875rem; font-weight: 600; }
.lang-code { margin-left: 0.4rem; font-size: 0.6875rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-text-muted); }
.lang-state { font-size: 0.75rem; color: var(--app-text-muted); }
.lang.live .lang-state { color: var(--color-success-500); }
.lang.stale .lang-state { color: var(--color-warning-500); }
.lang.never .lang-state, .lang.down .lang-state { color: var(--color-danger-500); }
.lang.missing { border-style: dashed; }
.langs-note { margin: 0.75rem 0 0; font-size: 0.8125rem; line-height: 1.45; color: var(--app-text-muted); }
.langs-note.warn { color: var(--color-warning-500); }
</style>
