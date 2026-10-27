<template>
  <section class="security-health">
    <header class="page-header">
      <div>
        <h1>{{ message('Security health') }}</h1>
        <p>{{ message('Configuration checks with evidence and a next step for each finding.') }}</p>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert">
      <p><LocalizedError :message="error" /></p>
      <button class="btn-sm btn-secondary" type="button" :disabled="loading" @click="load">{{ message('Retry') }}</button>
    </div>
    <p v-if="loading" class="loading" role="status">{{ message('Checking configuration…') }}</p>

    <div v-else-if="checks.length" class="check-list">
      <article v-for="check in checks" :key="check.id" class="check-card">
        <div class="check-heading">
          <h2>{{ message(check.label) }}</h2>
          <span :class="['status', check.status]">{{ message(statusLabel(check.status)) }}</span>
        </div>
        <p class="check-value">{{ message(check.value) }}</p>
        <p>{{ detailLabel(check) }}</p>
        <p class="next-step">
          <strong>{{ message('Next step:') }}</strong>
          <a v-if="check.href" :href="check.external ? check.href : withBase(check.href)" :target="check.external ? '_blank' : undefined" :rel="check.external ? 'noopener noreferrer' : undefined" @click="check.external ? undefined : navigate($event, check.href)">{{ message(check.action) }}</a>
          <span v-else>{{ message(check.action) }}</span>
        </p>
      </article>
    </div>
    <p v-else class="empty-state">{{ message('No checks were returned.') }}</p>
  </section>
</template>

<script setup>
import { message } from "../lib/messages.js";
import LocalizedError from './LocalizedError.vue';
import { ref, onMounted } from 'vue';
import { withBase } from '../lib/base.js';

const emit = defineEmits(['navigate']);
const checks = ref([]);
const loading = ref(true);
const error = ref('');

// PHP findings are returned as separate sentences joined by the API.
const detailLabel = (check) => {
  if (check.id === 'php-runtime') {
    const translated = message(check.detail);
    return translated !== check.detail ? translated : String(check.detail ?? '').split(/(?<=\.) /).map(text => message(text)).join(' ');
  }
  if (check.id === 'scheduled-backups') return message(String(check.detail ?? '').replace(' with media.', message(' with media') + '.').replace(' without media.', message(' without media') + '.'));
  return message(check.detail);
};
const statusLabel = (status) => ({ good: 'Configured', review: 'Review', attention: 'Action needed' }[status] || 'Unverified');
const navigate = (event, href) => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  emit('navigate', href);
};

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/security/health');
    const body = await res.json();
    if (!res.ok) throw new Error(body.error || `Could not load security health (${res.status}).`);
    checks.value = Array.isArray(body.data?.checks) ? body.data.checks : [];
  } catch (e) {
    error.value = e.message || 'Could not load security health.';
  } finally {
    loading.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.security-health { max-width: 1050px; }
.page-header { margin-bottom: 1.5rem; }
h1 { margin: 0 0 0.5rem; color: var(--app-text); font-size: 1.875rem; }
.page-header p, .check-card > p { color: var(--app-text-muted); }
.check-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; }
.check-card { padding: 1.25rem; border: 1px solid var(--card-border); border-radius: var(--card-radius-sm); background: var(--card-bg); color: var(--app-text); }
.check-heading { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
h2 { margin: 0; font-size: 1.05rem; }
.status { flex-shrink: 0; border-radius: 999px; padding: 0.25rem 0.65rem; background: var(--app-surface-strong); color: var(--app-text-muted); font-size: 0.75rem; font-weight: 700; }
.status.good { background: var(--color-success-bg); color: var(--color-success-text); }
.status.review { background: var(--color-warning-bg); color: var(--color-warning-text); }
.status.attention { background: var(--color-danger-bg); color: var(--color-danger-600); }
.check-value { margin: 0.75rem 0 0.25rem; color: var(--app-text); font-weight: 600; }
.next-step { margin-bottom: 0; }
.next-step a { color: var(--color-primary-700); font-weight: 600; }
.notice { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.error { color: var(--color-danger-600); }
.loading, .empty-state { padding: 2rem; text-align: center; color: var(--app-text-muted); }
button:focus-visible,
a:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: 2px; }
[data-theme="dark"] .status.good { background: rgba(34, 197, 94, 0.18); color: var(--color-success-text); }
[data-theme="dark"] .status.review { background: rgba(245, 158, 11, 0.18); color: #fcd34d; }
[data-theme="dark"] .status.attention { background: rgba(239, 68, 68, 0.18); color: var(--color-danger-600); }
</style>
