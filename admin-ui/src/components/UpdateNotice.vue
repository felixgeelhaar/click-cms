<script setup>
/**
 * Tells an administrator, on sign-in, that a release is waiting — and offers to
 * install it.
 *
 * The alternative was a cron entry running `bin/click-update.php`, which is fine
 * for a server somebody administers and useless on the shared hosting this
 * project is written for: no shell, and a scheduler behind a control panel that
 * many people never open. An update nobody is told about does not get installed.
 *
 * Deliberately a notice rather than an automatic install. Swapping the software
 * under a running site is not something to do to somebody while they are working
 * — it is something to offer them, with the version and the notes in front of
 * them, at a moment they chose.
 *
 * Only shown to a user who could act on it: without `plugins.install` the button
 * would 403, and a banner you cannot dismiss by acting is just noise.
 */
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
  /** The signed-in user's capabilities, for deciding whether to show at all. */
  capabilities: { type: Array, default: () => [] },
});

const state = ref(null);
const installing = ref(false);
const error = ref('');
const dismissed = ref(false);
const done = ref(null);

const mayInstall = computed(() => props.capabilities.includes('plugins.install'));
const release = computed(() => state.value?.release ?? null);
const show = computed(
  () => !dismissed.value && mayInstall.value && (state.value?.hasUpdate ?? false) && release.value !== null,
);

onMounted(async () => {
  if (!mayInstall.value) return;
  try {
    // The cheap endpoint: answered from the last remembered check unless the
    // interval has passed, so signing in does not wait on the release feed.
    const res = await fetch('/api/updates');
    if (!res.ok) return; // an update notice is never worth an error on sign-in
    state.value = (await res.json()).data ?? null;
  } catch {
    // Same reasoning: silence. Whether a newer version exists is not something
    // to interrupt somebody's login over.
  }
});

const install = async () => {
  installing.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/updates/apply', { method: 'POST' });
    const body = await res.json().catch(() => ({}));
    if (!res.ok || !(body.data?.installed ?? false)) {
      error.value = body.error ?? `The update could not be installed (${res.status}).`;
      return;
    }
    done.value = body.data.version ?? release.value?.version ?? '';
  } catch (e) {
    error.value = `The update could not be installed: ${e.message}`;
  } finally {
    installing.value = false;
  }
};
</script>

<template>
  <!-- Reloading is left to the reader rather than done for them: the code
       changed underneath, and a page that reloads itself mid-sentence is
       startling. -->
  <div v-if="done !== null" class="notice notice--done" role="status">
    <strong>Updated to {{ done }}.</strong>
    <span>Reload to use the new version.</span>
    <button class="btn" @click="() => window.location.reload()">Reload</button>
  </div>

  <div v-else-if="show" class="notice" role="status">
    <div class="notice__text">
      <strong>Version {{ release.version }} is available.</strong>
      <span v-if="release.security" class="notice__security">Security release</span>
      <span v-else>You are running {{ state.currentVersion }}.</span>
    </div>

    <p v-if="error" class="notice__error" role="alert">{{ error }}</p>

    <div class="notice__actions">
      <button class="btn-primary" :disabled="installing" @click="install">
        {{ installing ? 'Installing…' : 'Install now' }}
      </button>
      <button class="btn" :disabled="installing" @click="dismissed = true">Not now</button>
    </div>
  </div>
</template>

<style scoped>
.notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem 1rem;
  padding: 0.85rem 1rem;
  margin-bottom: 1.25rem;
  border: 1px solid var(--color-border, #d6d9de);
  border-left: 3px solid var(--color-accent, #3b6ef5);
  border-radius: 6px;
  background: var(--color-surface-raised, #f7f8fa);
}
.notice--done {
  border-left-color: var(--color-success, #2e7d4f);
}
.notice__text {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: baseline;
  flex: 1 1 20rem;
}
.notice__security {
  font-weight: 600;
  color: var(--color-danger, #b3261e);
}
.notice__error {
  flex: 1 1 100%;
  margin: 0;
  color: var(--color-danger, #b3261e);
}
.notice__actions {
  display: flex;
  gap: 0.5rem;
}
</style>
