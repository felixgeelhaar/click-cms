<template>
  <div class="link-field">
    <!-- Picking a page is the primary path and needs no typing. The address is
         secondary text on each option, because an editor thinks "About the
         workshop", not "about" — and the slug alone is what made this a
         type-it-from-memory field. -->
    <select
      :id="selectId"
      class="link-select"
      :value="choice"
      :aria-describedby="describedByIds"
      :aria-invalid="invalid || missing ? 'true' : undefined"
      @change="pick($event.target.value)"
    >
      <option value="" :disabled="required">{{ promptLabel }}</option>
      <option v-for="option in options" :key="option.key" :value="option.target">{{ option.label }}</option>
      <option :value="EXTERNAL">External link…</option>
      <!-- A value this control does not recognise still gets an option, so the
           select can show it. Without one the browser shows nothing selected and
           the screen would read as empty while the stored value is untouched. -->
      <option v-if="unrecognised" :value="UNRECOGNISED">{{ value }} — not a known page</option>
    </select>

    <div v-if="showExternal" class="link-external">
      <label class="link-sub-label" :for="urlId">Web address</label>
      <input
        :id="urlId"
        class="link-url"
        type="url"
        inputmode="url"
        placeholder="https://example.com/page"
        :value="value"
        :aria-describedby="`${urlId}-hint`"
        @input="$emit('update:modelValue', $event.target.value)"
      />
      <p :id="`${urlId}-hint`" class="link-hint">A full address starting with http:// or https://</p>
    </div>

    <!--
      The live region is always in the DOM. A region created together with its
      own text announces nothing; one that already exists announces what appears
      inside it — which is the point, since both notices appear in reaction to a
      choice the editor just made.
    -->
    <div class="link-notices" aria-live="polite">
      <p v-if="missing" :id="missingId" class="link-warning">
        This points at a page that no longer exists. <code>{{ value }}</code> is still
        saved exactly as it was — choose a page above, or an external link, to change it.
      </p>
      <p v-else-if="unpublishedNotice" :id="unpublishedId" class="link-caution">{{ unpublishedNotice }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, useId } from 'vue';

/**
 * Point at one of the site's own pages, by title.
 *
 * Two screens need this and they store the answer in two different shapes, which
 * is the whole reason this is one component with a `format` rather than two
 * lookalikes:
 *
 *   - `slug` — what a menu target is. `MenuItem::classify()` accepts a bare slug
 *     (`about`) or `locale/slug` (`de/about`) or an http(s) URL, and rejects
 *     `/about` outright. Emitting a path here throws on save.
 *   - `path` — what a section's `url` field is. SectionRenderer puts the value
 *     straight into an `href`, so an internal page is the root-relative address
 *     the public router serves it at: `/about`, or `/de/about` outside the
 *     default locale. That mirrors MenusController::hrefFor().
 *
 * A value this control cannot place is never rewritten and never dropped. It is
 * shown, and said out loud, because a renamed page is a broken link on the live
 * site and an editor who opens this screen is the only person who can fix it.
 */

const props = defineProps({
  modelValue: { type: String, default: '' },
  /** 'slug' for a menu target, 'path' for a section url field. */
  format: { type: String, default: 'path' },
  required: { type: Boolean, default: false },
  invalid: { type: Boolean, default: false },
  /** Id for the <select>, so an outer <label for> names it. */
  inputId: { type: String, default: '' },
  describedBy: { type: String, default: undefined },
  /**
   * Already-loaded page rows. Given these, this control does not fetch: a menu
   * with twenty items should ask for the page list once, not twenty times.
   * Passing them is a promise that they are loaded — pass none to self-load.
   */
  pages: { type: Array, default: null },
  /** The locale served with `pages`; its pages need no locale prefix. */
  defaultLocale: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

// Neither sentinel can collide with a real value: a slug matches
// /^[a-z0-9][a-z0-9-]*$/, a path starts with "/", a URL with "http".
const EXTERNAL = '!external';
const UNRECOGNISED = '!unrecognised';

// The same shape MenuItem::classify() treats as an absolute URL, narrowed to the
// two schemes it allows. An `ftp://` or a protocol-relative `//host` is therefore
// not external here — it is unrecognised, which is honest, because it is a value
// the domain would refuse.
const ABSOLUTE_URL = /^https?:\/\/[^/?#\s]+/i;
const looksExternal = (v) => ABSOLUTE_URL.test(v);

const fetched = ref([]);
const fetchedLocale = ref('');
const loading = ref(props.pages === null);
const externalMode = ref(false);

const value = computed(() => props.modelValue ?? '');
const rows = computed(() => props.pages ?? fetched.value);
const baseLocale = computed(() => (props.pages === null ? fetchedLocale.value : props.defaultLocale));

const slugOf = (row) => row.slug ?? String(row.key ?? '').split(':').pop() ?? '';
const localeOf = (row) => row.locale ?? '';
const titleOf = (row) => row.data?.title || row.title || slugOf(row);
// Publication state reaches the admin UI only for a signed-in caller, and older
// rows carry none. Absent means "no reason to think otherwise" — a false "not
// published" badge on every page would be worse than none.
const isPublished = (row) => row.publication?.published !== false;

/** The internal address without a leading slash: `about`, or `de/about`. */
const addressOf = (row) => {
  const locale = localeOf(row);
  const slug = slugOf(row);
  return locale !== '' && locale !== baseLocale.value ? `${locale}/${slug}` : slug;
};
const pathOf = (row) => `/${addressOf(row)}`;
const targetOf = (row) => (props.format === 'slug' ? addressOf(row) : pathOf(row));

const options = computed(() => {
  const seen = new Set();
  const mapped = rows.value
    .filter((row) => slugOf(row) !== '')
    .map((row) => ({
      key: row.key ?? targetOf(row),
      target: targetOf(row),
      title: titleOf(row),
      published: isPublished(row),
      // The address is shown as a path in both formats, because that is what a
      // reader would see in the address bar — the storage shape is this
      // component's problem, not the editor's.
      label: `${titleOf(row)} — ${pathOf(row)}${isPublished(row) ? '' : ' (not published)'}`,
    }))
    .filter((option) => (seen.has(option.target) ? false : seen.add(option.target)));

  // Published first. Unpublished pages stay in the list — linking to one is a
  // real, occasionally deliberate choice — but they are not the default answer.
  return [...mapped.filter((o) => o.published), ...mapped.filter((o) => !o.published)];
});

/**
 * The page the stored value names, if any.
 *
 * Matching is deliberately limited to the shapes this format can itself emit,
 * plus a redundant-but-valid locale prefix (`en/about` where `en` is the
 * default). A menu target of `/about` is not matched, because it is not a value
 * the domain accepts — treating it as "the About page" would hide a link that
 * throws the moment the menu is saved.
 */
const matchedRow = computed(() => {
  const v = value.value;
  if (v === '') return null;

  const wantsSlash = props.format === 'path';
  if (wantsSlash !== v.startsWith('/')) return null;

  const parts = (wantsSlash ? v.slice(1) : v).split('/');
  let locale = null;
  let slug = null;
  if (parts.length === 1) {
    [slug] = parts;
  } else if (parts.length === 2) {
    [locale, slug] = parts;
  } else {
    return null;
  }
  if (!slug) return null;

  return rows.value.find(
    (row) =>
      slugOf(row) === slug && (locale === null || localeOf(row) === '' || localeOf(row) === locale)
  ) ?? null;
});

const chosen = computed(() => {
  const row = matchedRow.value;
  if (row === null) return null;
  return options.value.find((o) => o.target === targetOf(row)) ?? null;
});

const showExternal = computed(() => externalMode.value || looksExternal(value.value));

const unrecognised = computed(() => {
  if (loading.value || showExternal.value) return false;
  return value.value !== '' && chosen.value === null;
});

// Warn only when there is a page list to have been absent from. With no pages
// loaded at all — a failed request — "this page no longer exists" would be a
// guess, so the value is still shown and simply not accused.
const missing = computed(() => unrecognised.value && options.value.length > 0);

const choice = computed(() => {
  if (showExternal.value) return EXTERNAL;
  if (chosen.value !== null) return chosen.value.target;
  if (unrecognised.value) return UNRECOGNISED;
  return '';
});

// An optional field offers "nothing" as a real choice. A required one keeps the
// empty option so the control is never blank with no matching option, but
// disables it — there is no valid way back to nothing.
const required = computed(() => props.required);
const promptLabel = computed(() => {
  if (loading.value) return 'Loading pages…';
  return required.value ? '— Choose a page —' : '— none —';
});

const unpublishedNotice = computed(() => {
  const option = chosen.value;
  if (option === null || option.published) return '';
  return `“${option.title}” is not published, so anyone following this link gets a “page not found”.`;
});

const uid = useId();
const selectId = computed(() => props.inputId || `link-${uid}`);
const urlId = computed(() => `${selectId.value}-url`);
const missingId = computed(() => `${selectId.value}-missing`);
const unpublishedId = computed(() => `${selectId.value}-unpublished`);

const describedByIds = computed(() => {
  const ids = [
    props.describedBy,
    missing.value ? missingId.value : null,
    unpublishedNotice.value ? unpublishedId.value : null,
  ].filter(Boolean);
  return ids.length > 0 ? ids.join(' ') : undefined;
});

const pick = (raw) => {
  if (raw === EXTERNAL) {
    externalMode.value = true;
    // Switching away from a page replaces the link. Keeping the page's value
    // while the dropdown says "External link…" and the box below is empty would
    // show the editor one thing and save another; an empty required field is
    // flagged where it should be, by validation.
    if (!looksExternal(value.value)) emit('update:modelValue', '');
    return;
  }

  externalMode.value = false;
  // Re-picking the unrecognised value changes nothing, on purpose: it stays
  // stored byte for byte.
  if (raw === UNRECOGNISED) return;

  emit('update:modelValue', raw);
};

onMounted(async () => {
  if (props.pages !== null) return;
  try {
    // No locale parameter, so this is the default locale and its pages — which
    // is what an unprefixed slug or path means.
    const res = await fetch('/api/pages');
    const body = await res.json();
    fetched.value = Array.isArray(body.data) ? body.data : [];
    fetchedLocale.value = typeof body.locale === 'string' ? body.locale : '';
  } catch {
    fetched.value = [];
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.link-field { display: block; }
.link-select, .link-url {
  width: 100%; padding: 0.5rem 0.65rem; border: 1px solid var(--control-border);
  border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit;
}
.link-select[aria-invalid="true"] { border-color: var(--color-danger-600, #dc2626); }
.link-external { margin-top: 0.5rem; }
.link-sub-label { display: block; margin-bottom: 0.25rem; font-size: 0.75rem; font-weight: 500; color: var(--app-text-muted); }
.link-hint { margin: 0.25rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.link-warning { margin: 0.35rem 0 0; font-size: 0.75rem; color: var(--color-danger-600, #dc2626); }
.link-caution { margin: 0.35rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.link-warning code { font-size: 0.75rem; }

/* The keyboard needs to say where it is on both controls, and the revealed
   address box is the one a keyboard user reaches by Tab straight after the
   dropdown — losing the ring there loses the reveal. */
.link-select:focus-visible,
.link-url:focus-visible {
  outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring);
}
</style>
