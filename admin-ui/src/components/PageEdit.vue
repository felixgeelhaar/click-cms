<template>
  <div class="page-edit">
    <div class="edit-heading">
      <h1 class="page-title">{{ isNew ? 'New Page' : 'Edit Page' }}</h1>
      <!-- Who else is on this page right now. Only meaningful once the page
           exists (a new, unsaved page has no address to share). -->
      <PresenceBar
        v-if="!isNew && storedSlug"
        :page="storedSlug"
        :locale="locale"
        :current-user="currentUsername"
      />
    </div>

    <p v-if="loadError" class="banner error" role="alert">{{ loadError }}</p>
    <p v-if="saveError" class="banner error" role="alert">{{ saveError }}</p>
    <p v-if="publishError" class="banner error" role="alert">{{ publishError }}</p>
    <p v-if="notice" class="banner notice" role="status">{{ notice }}</p>

    <div v-if="loading" class="banner">Loading…</div>

    <div v-else class="edit-form">
      <PagePublication
        :publication="publication"
        :pending-count="pendingCount"
        :can-publish="can('content.publish')"
        :can-unpublish="can('content.unpublish')"
        :busy="publishBusy"
        :is-new="isNew || translationMissing"
        :slug="storedSlug"
        :locale="locale"
        :default-locale="defaultLocale"
        @publish="publishPage"
        @unpublish="unpublishPage"
      />

      <!--
        Only for a page that exists. Scheduling something with no working copy
        to promote would produce a schedule the sweeper drops as soon as it
        fires, which is a worse way to learn "save it first" than not offering
        the control.
      -->
      <PageSchedule
        v-if="!isNew && !translationMissing"
        :schedule="schedule"
        :can-schedule="can('content.publish')"
        :busy="scheduleBusy"
        @save="saveSchedule"
        @clear="clearSchedule"
      />
      <p v-if="scheduleError" class="banner error" role="alert">{{ scheduleError }}</p>

      <PageLanguages
        v-if="!isNew && siteLocales.length > 1"
        :locales="siteLocales"
        :current="locale"
        :translations="translations"
        :busy="loading || saving"
        @select="switchLocale"
      />

      <!--
        A language with no working copy of its own is not an empty page: it is a
        page that does not exist in that language yet, and saying so is what
        stops an editor believing their German text disappeared.
      -->
      <p v-if="translationMissing" class="banner notice">
        There is no {{ languageName(locale) }} version of this page yet. Fill this
        in and save to create one — it is a separate document from
        {{ languageName(defaultLocale) }} and is published on its own.
        <button
          v-if="fallbackSource"
          type="button"
          class="inline-button"
          @click="copyFallbackSource"
        >
          Start from the {{ languageName(fallbackSourceLocale) }} text
        </button>
      </p>

      <div class="form-group">
        <label for="page-title">Title</label>
        <input id="page-title" v-model="page.title" type="text" placeholder="Page title" />
      </div>

      <div class="form-group">
        <label for="page-slug">Slug</label>
        <input
          id="page-slug"
          v-model="page.slug"
          type="text"
          placeholder="page-slug"
          :disabled="!isNew"
        />
        <p v-if="!isNew" class="field-help">
          The slug is part of the page's address and cannot be changed here.
        </p>
      </div>

      <SectionEditor v-model="page.sections" :errors="sectionErrors" />

      <!--
        Page-level SEO. Collapsed by default because most edits are to the body,
        not the metadata, and an always-open block would push the sections down
        the page. The values live under a single `seo` key so they ride along in
        the ordinary page save — PageService leaves top-level keys it does not
        recognise untouched, so no new endpoint is needed.
      -->
      <details class="seo-group">
        <summary class="seo-summary">Search &amp; social (SEO)</summary>

        <div class="form-group">
          <label for="seo-meta-title">Meta title</label>
          <input
            id="seo-meta-title"
            v-model="page.seo.metaTitle"
            type="text"
            placeholder="Falls back to the page title"
          />
          <p class="field-help">
            Shown in the browser tab and search results. Left empty, the page
            title is used.
          </p>
        </div>

        <div class="form-group">
          <label for="seo-description">Meta description</label>
          <textarea
            id="seo-description"
            v-model="page.seo.description"
            rows="2"
            placeholder="A sentence or two summarising the page"
          ></textarea>
          <p class="field-help">
            The snippet search engines and social cards show under the title.
          </p>
        </div>

        <!--
          The same picker section image fields use, so the Open Graph image is
          chosen from the media library rather than pasted as a URL. Reusing it
          means one place resolves references, warns about low-resolution files
          and keeps the reference stable if the file is renamed.
        -->
        <ImageField
          :field="ogImageField"
          v-model="page.seo.ogImage"
        />

        <div class="form-group">
          <label for="seo-canonical">Canonical URL</label>
          <input
            id="seo-canonical"
            v-model="page.seo.canonicalUrl"
            type="url"
            placeholder="https://example.com/this-page"
          />
          <p class="field-help">
            The preferred address for this page, when the same content is
            reachable at more than one URL. Leave empty unless you need it.
          </p>
        </div>

        <div class="form-group form-check">
          <input id="seo-noindex" v-model="page.seo.noindex" type="checkbox" />
          <label for="seo-noindex">Hide this page from search engines (noindex)</label>
        </div>
      </details>

      <!--
        The link is shown rather than opened for the editor automatically. It is
        meant to be sent to somebody — a client, a proofreader — who has no
        account, so having it selectable is the point, and a popup blocker
        cannot swallow it.
      -->
      <div v-if="previewUrl" class="preview-link">
        <p class="preview-link-title">Preview link ready</p>
        <p class="field-help">
          Anyone with this link can see the page as it stands now, until
          {{ previewExpiry }}. It stops working after that.
        </p>
        <div class="preview-link-row">
          <label class="visually-hidden" for="preview-url">Preview link</label>
          <input
            id="preview-url"
            :value="previewAbsoluteUrl"
            readonly
            @focus="$event.target.select()"
          />
          <button class="btn-secondary" @click="copyPreviewLink">{{ copied ? 'Copied' : 'Copy' }}</button>
          <a class="btn-secondary" :href="previewUrl" target="_blank" rel="noopener">Open</a>
        </div>
      </div>

      <p v-if="previewError" class="banner error" role="alert">{{ previewError }}</p>

      <div class="actions">
        <button class="btn-secondary" :disabled="saving" @click="cancel">Cancel</button>
        <button class="btn-secondary" :disabled="saving" @click="previewPage">
          {{ previewing ? 'Preparing…' : 'Preview' }}
        </button>
        <button class="btn-primary" :disabled="saving" @click="savePage">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>

      <PageVersions
        v-if="!isNew"
        :versions="versions"
        :loading="versionsLoading"
        :error="versionsError"
        :can-restore="can('content.restore')"
        :restoring="restoringId"
        @restore="restoreVersion"
        @reload="loadVersions"
      />

      <!-- Review notes for this page. Comments live once the page does. -->
      <CommentsPanel v-if="!isNew && storedSlug" :page="storedSlug" :locale="locale" />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import SectionEditor from './SectionEditor.vue';
import PresenceBar from './collaboration/PresenceBar.vue';
import CommentsPanel from './collaboration/CommentsPanel.vue';
import PagePublication from './PagePublication.vue';
import PageSchedule from './PageSchedule.vue';
import PageLanguages from './PageLanguages.vue';
import PageVersions from './PageVersions.vue';
import ImageField from './fields/ImageField.vue';

const props = defineProps({ slug: String, initialLocale: { type: String, default: '' } });
const emit = defineEmits(['saved', 'cancel']);

// The address this page lives at once it exists. Tracked separately from the
// prop because previewing a new page has to save it first, and every write
// after that is an update rather than another create.
const storedSlug = ref(props.slug || '');
const isNew = computed(() => !storedSlug.value);

// The SEO block's stable shape. Kept as one factory so every place that resets
// the page — a fresh page, a load, an untranslated language — starts SEO from
// the same keys, and the save payload is predictable regardless of how the
// editor arrived here.
const emptySeo = () => ({ metaTitle: '', description: '', ogImage: '', canonicalUrl: '', noindex: false });

// A minimal field descriptor so the media picker section image fields use can
// be reused verbatim for the Open Graph image. displayWidth is 1200 because
// that is the width social cards render at, so the picker's quality warning is
// judged against the slot this image actually fills.
const ogImageField = {
  label: 'Open Graph image',
  help: 'The image shown when this page is shared on social media.',
  displayWidth: 1200,
};

// Fill the SEO shape from whatever the API returned, ignoring anything that is
// not the expected type. A stored payload can carry an array where a string is
// expected; binding that to a text input would throw, so the empty default
// wins over a value of the wrong shape.
const seoFrom = (raw) => {
  const base = emptySeo();
  if (!raw || typeof raw !== 'object') return base;

  return {
    metaTitle: typeof raw.metaTitle === 'string' ? raw.metaTitle : base.metaTitle,
    description: typeof raw.description === 'string' ? raw.description : base.description,
    ogImage: typeof raw.ogImage === 'string' ? raw.ogImage : base.ogImage,
    canonicalUrl: typeof raw.canonicalUrl === 'string' ? raw.canonicalUrl : base.canonicalUrl,
    noindex: raw.noindex === true,
  };
};

const page = ref({ title: '', slug: '', sections: [], seo: emptySeo() });
const loading = ref(false);
const saving = ref(false);
const previewing = ref(false);
const loadError = ref('');
const saveError = ref('');
const previewError = ref('');
const publishError = ref('');
const notice = ref('');
const previewUrl = ref('');
const previewExpiry = ref('');
const copied = ref(false);
const sectionErrors = ref({});

/* -------------------------------------------------- capabilities -- */

// Asked for here rather than taken as a prop, so this component is correct
// however it is mounted. The rules live on the server; this only decides what
// to draw, and drawing a Publish button an author can only ever get a 403 from
// teaches them the product is broken.
const capabilities = ref([]);
const can = (capability) => capabilities.value.includes(capability);
// The signed-in username, so the presence bar can show who *else* is on the page
// rather than counting the reader among the crowd.
const currentUsername = ref('');

/* ------------------------------------------------------ languages -- */

const siteLocales = ref([]);
// CoreConfig lists the default language first, so this needs no second request.
const defaultLocale = computed(() => siteLocales.value[0] ?? '');
// Seeded from the URL so a deep link to a translation opens in that language.
// loadSiteLocales only fills this in when it is still empty, so the seed wins.
const locale = ref(props.initialLocale || '');
const translations = ref({});
const translationMissing = ref(false);
const fallbackSource = ref(null);
const fallbackSourceLocale = ref('');

const displayNames = (() => {
  try {
    return new Intl.DisplayNames(undefined, { type: 'language' });
  } catch {
    return null;
  }
})();

const languageName = (code) => {
  if (!code) return 'the default language';
  try {
    return displayNames?.of(code) || code;
  } catch {
    return code;
  }
};

/* ----------------------------------------------------- publication -- */

const publication = ref(null);
const publishBusy = ref('');

/* ------------------------------------------------------ scheduling -- */

// Null until the API answers, which the panel reads as "still checking" rather
// than "nothing scheduled" — the same distinction the publication banner draws,
// and for the same reason: an unknown answer shown as a definite one is this
// codebase's recurring bug.
const schedule = ref(null);
const scheduleBusy = ref(false);
const scheduleError = ref('');

/* --------------------------------------------------------- history -- */

const versions = ref([]);
const versionsLoading = ref(false);
const versionsError = ref('');
const restoringId = ref('');

/**
 * How many saved changes are waiting behind the version the public is reading.
 *
 * A publish records a version, so the answer is simply how many versions sit
 * newer than the newest one a publish wrote. When no publish is recorded — a
 * page seeded straight onto disk, or history trimmed past it — the honest
 * answer is "unknown", and the button says "Publish changes" rather than
 * inventing a number.
 *
 * Only meaningful for the default language: the history endpoints address a
 * page by slug alone, with no locale.
 */
const pendingCount = computed(() => {
  if (locale.value !== defaultLocale.value) return null;
  if (!publication.value?.hasUnpublishedChanges) return null;

  const index = versions.value.findIndex((v) => v.reason === 'publish');

  return index > 0 ? index : null;
});

/* ------------------------------------------------------ dirty state -- */

// Compared against a snapshot rather than tracked by a flag on every input:
// switching language throws away whatever is on screen, and asking first
// requires knowing whether there is anything to lose.
const savedSnapshot = ref('');
const snapshot = () => JSON.stringify(page.value);
const dirty = computed(() => savedSnapshot.value !== '' && savedSnapshot.value !== snapshot());

/* ------------------------------------------------------------ load -- */

const localeQuery = (extra = '') => {
  const parts = [];
  if (locale.value) parts.push(`locale=${encodeURIComponent(locale.value)}`);
  if (extra) parts.push(extra);
  return parts.length ? `?${parts.join('&')}` : '';
};

const loadCapabilities = async () => {
  try {
    const res = await fetch('/api/auth/check');
    const body = await res.json();
    capabilities.value = body.data?.user?.capabilities ?? [];
    currentUsername.value = body.data?.user?.username ?? '';
  } catch {
    // An empty set hides every privileged control rather than showing one that
    // cannot work. Failing closed is the only safe direction here.
    capabilities.value = [];
  }
};

const loadSiteLocales = async () => {
  try {
    const res = await fetch('/api/pages');
    const body = await res.json();
    siteLocales.value = Array.isArray(body.locales) ? body.locales : [];
    if (!locale.value) locale.value = body.locale || siteLocales.value[0] || '';
  } catch {
    siteLocales.value = [];
  }
};

/** Read the page in the current language, and say plainly if there is none. */
const loadPage = async () => {
  loadError.value = '';
  translationMissing.value = false;
  fallbackSource.value = null;

  const res = await fetch(`/api/pages/${storedSlug.value}${localeQuery()}`);
  const body = await res.json().catch(() => ({}));

  if (res.status === 404) {
    // Nothing at this address in any language the API could fall back to, so
    // this is a translation waiting to be written rather than a broken link.
    translationMissing.value = true;
    publication.value = null;
    page.value = { title: '', slug: storedSlug.value, sections: [], seo: emptySeo() };
    savedSnapshot.value = snapshot();
    return;
  }

  if (!res.ok) throw new Error(body.error || `Request failed (${res.status})`);

  const data = body.data?.data ?? body.data ?? {};

  // A signed-in read is exact — no fallback — so being answered in another
  // language means this one has no working copy of its own.
  if (body.fallback === true) {
    translationMissing.value = true;
    publication.value = null;
    fallbackSource.value = {
      title: data.title ?? '',
      sections: Array.isArray(data.sections) ? data.sections : [],
    };
    fallbackSourceLocale.value = body.locale ?? defaultLocale.value;
    page.value = { title: '', slug: storedSlug.value, sections: [], seo: emptySeo() };
    savedSnapshot.value = snapshot();
    return;
  }

  if (body.locale) locale.value = body.locale;
  publication.value = body.publication ?? null;

  page.value = {
    title: data.title ?? '',
    slug: storedSlug.value,
    sections: Array.isArray(data.sections) ? data.sections : [],
    seo: seoFrom(data.seo),
  };
  savedSnapshot.value = snapshot();
};

/**
 * Where every other language of this page stands.
 *
 * One request per configured language. Publishing is per document, so English
 * going live while German sits stale is the ordinary case rather than an edge
 * one, and the only thing that stops nobody noticing is showing it here.
 */
const loadTranslations = async () => {
  if (!storedSlug.value) return;

  const results = await Promise.all(siteLocales.value.map(async (code) => {
    try {
      const res = await fetch(`/api/pages/${storedSlug.value}?locale=${encodeURIComponent(code)}`);
      if (!res.ok) return [code, { exists: false, publication: null }];

      const body = await res.json();
      if (body.fallback === true) return [code, { exists: false, publication: null }];

      return [code, { exists: true, publication: body.publication ?? null }];
    } catch {
      return [code, { exists: false, publication: null }];
    }
  }));

  translations.value = Object.fromEntries(results);
};

const loadVersions = async () => {
  if (!storedSlug.value) {
    versions.value = [];
    return;
  }

  versionsLoading.value = true;
  versionsError.value = '';

  try {
    // History is per translation, addressed by the same ?locale= the rest of
    // the page API takes. It used to answer for the default language whatever
    // was asked, so the panel refused to show anything but the default; that is
    // fixed on the server now, and a German page shows German history.
    const res = await fetch(
      `/api/pages/${storedSlug.value}/versions?locale=${encodeURIComponent(locale.value)}`
    );
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      versionsError.value = body.error || `Could not read the history (${res.status}).`;
      return;
    }

    versions.value = Array.isArray(body.data) ? body.data : [];
  } catch (e) {
    versionsError.value = `Could not read the history: ${e.message}`;
  } finally {
    versionsLoading.value = false;
  }
};

const reload = async () => {
  loading.value = true;
  try {
    await loadPage();
    await Promise.all([loadTranslations(), loadVersions(), loadSchedule()]);
  } catch (e) {
    loadError.value = `Could not load this page: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

/* ---------------------------------------------------------- write -- */

/**
 * Write the page and report the address it ended up at.
 *
 * Separated from the Save button because previewing has to save first: a
 * preview of the last saved version, shown while the editor has unsaved
 * changes on screen, is exactly the kind of quiet wrongness that makes a
 * preview untrustworthy.
 */
const persist = async () => {
  saveError.value = '';
  sectionErrors.value = {};
  notice.value = '';

  // A page that exists in another language still has to be *created* in this
  // one: translations are separate documents, and PUT to one that does not
  // exist is a 404 by design rather than an upsert.
  const creating = isNew.value || translationMissing.value;

  const res = await fetch(
    creating ? `/api/pages${localeQuery()}` : `/api/pages/${storedSlug.value}${localeQuery()}`,
    {
      method: creating ? 'POST' : 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        title: page.value.title,
        slug: creating ? (page.value.slug || storedSlug.value) : page.value.slug,
        sections: page.value.sections,
        // Rides along in the ordinary page write. PageService leaves top-level
        // keys it does not recognise untouched, so `seo` is stored and returned
        // as-is without any new endpoint or validator change.
        seo: page.value.seo,
      }),
    }
  );

  const body = await res.json().catch(() => ({}));

  if (!res.ok) {
    // Field-level errors come back keyed "<sectionIndex>.<fieldName>" so the
    // editor can put each message against the input that caused it.
    sectionErrors.value = body.errors ?? {};
    saveError.value = body.error
      || (Object.keys(sectionErrors.value).length
        ? 'Some sections need attention before this page can be saved.'
        : `Could not save (${res.status}).`);
    return null;
  }

  const slug = body.data?.slug ?? storedSlug.value;
  storedSlug.value = slug;
  translationMissing.value = false;
  savedSnapshot.value = snapshot();

  return slug;
};

const savePage = async () => {
  saving.value = true;

  try {
    if (await persist()) {
      // Saved, not published. Refreshing the publication state is what turns
      // the banner above from "up to date" into "your latest changes are not
      // live" — which is the whole point of doing it here rather than leaving
      // the editor to discover it on the public site.
      await refreshPublication();
      await Promise.all([loadTranslations(), loadVersions(), loadSchedule()]);
      notice.value = 'Saved. This is not on the public site until you publish.';
    }
  } catch (e) {
    saveError.value = `Could not save: ${e.message}`;
  } finally {
    saving.value = false;
  }
};

const refreshPublication = async () => {
  if (!storedSlug.value) return;

  try {
    const res = await fetch(`/api/pages/${storedSlug.value}${localeQuery()}`);
    if (!res.ok) return;
    const body = await res.json();
    if (body.fallback !== true) publication.value = body.publication ?? null;
  } catch {
    // Leave the last known state rather than blanking the banner: an unknown
    // publication state drawn as "nothing pending" is the lie this whole panel
    // exists to prevent.
  }
};

/* ---------------------------------------------------- publication -- */

const publicationAction = async (action) => {
  publishError.value = '';
  notice.value = '';
  publishBusy.value = action;

  try {
    const res = await fetch(
      `/api/pages/${storedSlug.value}/${action}${localeQuery()}`,
      { method: 'POST' }
    );
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      publishError.value = body.error || `Could not ${action} this page (${res.status}).`;
      return;
    }

    publication.value = body.data?.publication ?? publication.value;
    notice.value = action === 'publish'
      ? `Published in ${languageName(locale.value)}. This page is now on the public site.`
      : `Taken down. Visitors now get a "page not found" for this page in ${languageName(locale.value)}.`;

    await Promise.all([loadTranslations(), loadVersions(), loadSchedule()]);
  } catch (e) {
    publishError.value = `Could not ${action} this page: ${e.message}`;
  } finally {
    publishBusy.value = '';
  }
};

const publishPage = () => publicationAction('publish');
const unpublishPage = () => publicationAction('unpublish');

/* ------------------------------------------------------- scheduling -- */

const loadSchedule = async () => {
  if (!storedSlug.value) {
    schedule.value = { publishAt: null, unpublishAt: null };
    return;
  }

  try {
    const res = await fetch(`/api/pages/${storedSlug.value}/schedule${localeQuery()}`);
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      // A 501 means this installation has no schedule store. Not an error to
      // put in front of the editor — there is simply nothing to schedule with —
      // so the panel is left in its "nothing scheduled" state and says so.
      if (res.status !== 501) {
        scheduleError.value = body.error || `Could not read the schedule (${res.status}).`;
      }
      schedule.value = { publishAt: null, unpublishAt: null };
      return;
    }

    schedule.value = body.data ?? { publishAt: null, unpublishAt: null };
  } catch (e) {
    scheduleError.value = `Could not read the schedule: ${e.message}`;
    schedule.value = { publishAt: null, unpublishAt: null };
  }
};

const saveSchedule = async ({ publishAt, unpublishAt }) => {
  scheduleBusy.value = true;
  scheduleError.value = '';
  notice.value = '';

  try {
    const res = await fetch(`/api/pages/${storedSlug.value}/schedule${localeQuery()}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ publishAt, unpublishAt }),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      scheduleError.value = body.error || `Could not save the schedule (${res.status}).`;
      return;
    }

    schedule.value = body.data ?? schedule.value;
    notice.value = publishAt || unpublishAt
      ? 'Schedule saved. Nothing changes on the public site until the time you set.'
      : 'Schedule cancelled.';
  } catch (e) {
    scheduleError.value = `Could not save the schedule: ${e.message}`;
  } finally {
    scheduleBusy.value = false;
  }
};

const clearSchedule = async () => {
  scheduleBusy.value = true;
  scheduleError.value = '';
  notice.value = '';

  try {
    const res = await fetch(`/api/pages/${storedSlug.value}/schedule${localeQuery()}`, {
      method: 'DELETE',
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      scheduleError.value = body.error || `Could not cancel the schedule (${res.status}).`;
      return;
    }

    schedule.value = body.data ?? { publishAt: null, unpublishAt: null };
    notice.value = 'Schedule cancelled.';
  } catch (e) {
    scheduleError.value = `Could not cancel the schedule: ${e.message}`;
  } finally {
    scheduleBusy.value = false;
  }
};

/* -------------------------------------------------------- history -- */

// Confirmation happens inside PageVersions, in the product's own voice, so
// there is no second prompt here.
const restoreVersion = async (version) => {
  restoringId.value = version.id;
  versionsError.value = '';
  notice.value = '';

  try {
    // The language being edited, so a German restore rewinds the German working
    // copy and not the English one — the endpoint keys on locale now.
    const res = await fetch(
      `/api/pages/${storedSlug.value}/versions/${version.id}/restore?locale=${encodeURIComponent(locale.value)}`,
      { method: 'POST' }
    );
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      versionsError.value = body.error || `Could not restore that version (${res.status}).`;
      return;
    }

    await reload();
    notice.value = 'Working copy restored. The live page is unchanged until you publish.';
  } catch (e) {
    versionsError.value = `Could not restore that version: ${e.message}`;
  } finally {
    restoringId.value = '';
  }
};

/* --------------------------------------------------- interactions -- */

const switchLocale = async (code) => {
  if (code === locale.value) return;

  if (dirty.value && !window.confirm(
    `Switch to ${languageName(code)}? Unsaved changes to the `
    + `${languageName(locale.value)} version will be lost.`
  )) {
    return;
  }

  locale.value = code;
  previewUrl.value = '';
  notice.value = '';
  publishError.value = '';
  saveError.value = '';
  await reload();
};

const copyFallbackSource = () => {
  if (!fallbackSource.value) return;
  page.value = {
    title: fallbackSource.value.title,
    slug: storedSlug.value,
    // Deep-copied so editing the new translation cannot reach back into the
    // source document's sections through a shared reference.
    sections: JSON.parse(JSON.stringify(fallbackSource.value.sections)),
    // SEO is per language and starts blank: a canonical URL or description
    // copied from another translation is almost always wrong for this one.
    seo: emptySeo(),
  };
};

const previewAbsoluteUrl = computed(() =>
  previewUrl.value ? new URL(previewUrl.value, window.location.origin).toString() : ''
);

const previewPage = async () => {
  saving.value = true;
  previewing.value = true;
  previewError.value = '';
  previewUrl.value = '';
  copied.value = false;

  try {
    const slug = await persist();
    if (!slug) return;

    const res = await fetch(`/api/pages/${slug}/preview${localeQuery()}`, { method: 'POST' });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      previewError.value = body.error || `Could not prepare a preview (${res.status}).`;
      return;
    }

    previewUrl.value = body.data.url;
    previewExpiry.value = new Date(body.data.expiresAt * 1000).toLocaleString();
    await refreshPublication();
  } catch (e) {
    previewError.value = `Could not prepare a preview: ${e.message}`;
  } finally {
    saving.value = false;
    previewing.value = false;
  }
};

const copyPreviewLink = async () => {
  try {
    await navigator.clipboard.writeText(previewAbsoluteUrl.value);
    copied.value = true;
  } catch {
    // Clipboard access is not always granted. The field is selectable, so
    // there is still a way to get the link out; say nothing rather than raise
    // an error for something the editor can simply do by hand.
  }
};

const cancel = () => emit('cancel');

// Editing clears the "Saved" confirmation, so it can never sit above a screen
// full of changes that have not been saved at all.
watch(page, () => { if (dirty.value) notice.value = ''; }, { deep: true });

onMounted(async () => {
  loading.value = true;

  try {
    await Promise.all([loadCapabilities(), loadSiteLocales()]);

    if (!props.slug) {
      savedSnapshot.value = snapshot();
      return;
    }

    await loadPage();
    await Promise.all([loadTranslations(), loadVersions(), loadSchedule()]);
  } catch (e) {
    loadError.value = `Could not load this page: ${e.message}`;
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.page-edit { max-width: 860px; }
.edit-heading { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem; }
.edit-heading .page-title { margin-bottom: 0; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 2rem; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.banner.notice { border: 1px solid var(--app-border); line-height: 1.5; }
.edit-form { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: 2rem; }
.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.form-group textarea { resize: vertical; min-height: 3rem; }
.form-group input:disabled { opacity: 0.7; cursor: not-allowed; }
.form-check { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0; }
.form-check input { width: auto; }
.form-check label { margin-bottom: 0; font-weight: 400; }
.seo-group { margin-top: 1.5rem; border: 1px solid var(--app-border); border-radius: 8px; padding: 0 1rem; }
.seo-group[open] { padding: 0 1rem 0.5rem; }
.seo-summary { cursor: pointer; padding: 1rem 0; font-weight: 600; color: var(--app-text); }
.field-help { margin: 0.35rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.inline-button { background: none; border: none; padding: 0; font: inherit; color: var(--color-primary-600); text-decoration: underline; cursor: pointer; }
.actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; }
.preview-link { margin-top: 1.5rem; padding: 1rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface-strong); }
.preview-link-title { margin: 0 0 0.25rem; font-weight: 600; }
.preview-link-row { display: flex; gap: 0.5rem; margin-top: 0.75rem; align-items: stretch; }
.preview-link-row input { flex: 1; min-width: 0; padding: 0.5rem 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.preview-link-row .btn-secondary { display: inline-flex; align-items: center; text-decoration: none; white-space: nowrap; }
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
.btn-primary, .btn-secondary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-primary:disabled, .btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

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
