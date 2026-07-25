<template>
  <div class="profile">
    <h1 class="page-title">Profile</h1>
    <p class="page-subtitle">Manage your account settings</p>
    <div class="profile-card">
      <!-- Both fields had a visible caption and no `for`, so neither input had
           an accessible name at all: a screen reader announced two anonymous
           text boxes. -->
      <div class="form-group">
        <label for="profile-display-name">Display Name</label>
        <input id="profile-display-name" v-model="profile.displayName" type="text" autocomplete="name" />
      </div>
      <div class="form-group">
        <label for="profile-email">Email</label>
        <input id="profile-email" v-model="profile.email" type="email" autocomplete="email" />
      </div>
      <button class="btn-primary" :disabled="saving" @click="saveProfile">
        {{ saving ? 'Saving…' : 'Save Changes' }}
      </button>

      <p v-if="error" class="banner error" role="alert">{{ error }}</p>
      <p v-else-if="saved" class="banner ok" role="status">Your details have been saved.</p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({ user: Object });
const profile = ref({ displayName: props.user?.displayName || '', email: props.user?.email || '' });

const saving = ref(false);
const saved = ref(false);
const error = ref('');

/**
 * Actually save, and say truthfully what happened.
 *
 * This was `alert('Profile saved!')` and nothing else — no request, no write.
 * The button announced success and persisted nothing, which is worse than a
 * button that fails: somebody who changes their name here, sees "saved" and
 * closes the tab has been told a falsehood by the software.
 *
 * `/api/users/*` requires ManageUsers, so this succeeds for an administrator
 * and is refused for everybody else. That refusal is reported as what it is
 * rather than smoothed over — an editor who cannot change their own display
 * name needs to know to ask, not to wonder why it keeps reverting. A
 * self-service endpoint would be the better answer and does not exist yet.
 */
const saveProfile = async () => {
  const username = props.user?.username;
  if (!username) {
    error.value = 'Your account could not be identified, so nothing was saved.';
    return;
  }

  saving.value = true;
  saved.value = false;
  error.value = '';

  try {
    const res = await fetch(`/api/users/${encodeURIComponent(username)}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        displayName: profile.value.displayName,
        email: profile.value.email,
      }),
    });

    if (res.status === 403) {
      error.value = 'Your account cannot change these details. Ask an administrator '
        + 'to update them for you on the Users screen.';
      return;
    }

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      error.value = body.error || `Your details could not be saved (${res.status}).`;
      return;
    }

    saved.value = true;
  } catch (e) {
    error.value = `Your details could not be saved: ${e.message}`;
  } finally {
    saving.value = false;
  }
};
</script>

<style scoped>
.profile { max-width: 600px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin-bottom: 2rem; }
.profile-card { padding: 2rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
.form-group input { width: 100%; padding: 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); }
.form-group input:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }
.banner { margin: 1rem 0 0; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; }
.banner.error { border: 1px solid var(--color-danger-600); color: var(--color-danger-600); background: color-mix(in srgb, var(--color-danger-500) 8%, var(--app-surface)); }
.banner.ok { border: 1px solid var(--color-success-text); color: var(--color-success-text); background: color-mix(in srgb, var(--color-success-500) 8%, var(--app-surface)); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-primary { padding: 0.75rem 1.5rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
</style>
