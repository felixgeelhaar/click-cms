<template>
  <section class="quality-checklist" aria-labelledby="quality-checklist-heading">
    <h2 id="quality-checklist-heading">{{ message('Before you publish') }}</h2>
    <p v-if="!issues.length" class="quality-ready" role="status">{{ message('No publishing checks need attention.') }}</p>
    <ul v-else>
      <li v-for="issue in issues" :key="issue.key">
        <span class="issue-dot" aria-hidden="true">!</span>
        <span>{{ message(issue.message) }}</span>
        <a :href="issue.href">{{ message(issue.action) }}</a>
      </li>
    </ul>
    <p class="quality-note">{{ message('These are editorial reminders, not publication blockers. Language status and media resolution warnings are also shown in their editors.') }}</p>
  </section>
</template>

<script setup>
import { message, messageLanguage } from "../lib/messages.js";
import { computed } from 'vue';

const props = defineProps({
  page: { type: Object, default: () => ({}) },
  locales: { type: Array, default: () => [] },
  translations: { type: Object, default: () => ({}) },
});

const languageName = (code) => {
  try { return new Intl.DisplayNames(messageLanguage.value, { type: 'language' }).of(code) || code; }
  catch { return code; }
};
const issues = computed(() => {
  const result = [];
  if (!String(props.page?.title ?? '').trim()) {
    result.push({ key: 'title', message: 'Add a page title.', action: 'Edit title', href: '#page-title' });
  }
  if (!Array.isArray(props.page?.sections) || props.page.sections.length === 0) {
    result.push({ key: 'content', message: 'Add page content.', action: 'Add a section', href: '#section-type-picker' });
  }
  if (!String(props.page?.seo?.description ?? '').trim()) {
    result.push({ key: 'description', message: 'Add a search description so people can recognize this page in results.', action: 'Edit search description', href: '#seo-description' });
  }
  for (const code of props.locales) {
    const translation = props.translations[code];
    const language = languageName(code);
    if (translation?.exists === false) {
      result.push({ key: `missing-${code}`, message: `${language} has no translation yet.`, action: `Create the ${language} translation`, href: '#page-languages-heading' });
    } else if (translation?.exists && translation.publication?.published && translation.publication?.hasUnpublishedChanges) {
      result.push({ key: `stale-${code}`, message: `${language} is live with unpublished edits.`, action: `Review ${language}`, href: '#page-languages-heading' });
    }
  }
  return result;
});
</script>

<style scoped>
.quality-checklist { margin: 1rem 0 1.5rem; padding: 0.85rem 1rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--app-surface-strong); }
.quality-checklist h2 { margin: 0; font-size: 0.95rem; }
.quality-checklist ul { list-style: none; display: grid; gap: 0.45rem; margin: 0.65rem 0 0; padding: 0; }
.quality-checklist li { display: grid; grid-template-columns: 1rem minmax(0, 1fr) auto; align-items: baseline; gap: 0.55rem; font-size: 0.84rem; }
.issue-dot { color: var(--color-warning-text); font-weight: 700; }
.quality-checklist a { white-space: nowrap; font-weight: 600; }
.quality-checklist a:focus-visible { outline: 3px solid var(--color-primary-600); outline-offset: 3px; border-radius: 2px; }
.quality-ready, .quality-note { margin: 0.55rem 0 0; font-size: 0.82rem; color: var(--app-text-muted); }
.quality-note { font-size: 0.75rem; }
@media (max-width: 640px) { .quality-checklist li { grid-template-columns: 1rem minmax(0, 1fr); } .quality-checklist li a { grid-column: 2; } }
</style>
