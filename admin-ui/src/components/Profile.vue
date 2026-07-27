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

    <div v-if="twoFactor.available" class="profile-card two-factor">
      <h2>Two-step sign-in</h2>

      <p v-if="twoFactor.active" class="state-line" role="status">
        <strong>On.</strong> Signing in asks for a code from your authenticator
        app as well as your password.
      </p>
      <p v-else class="state-line" role="status">
        <strong>Off.</strong> Your password is the only thing protecting this
        account.
      </p>

      <p v-if="twoFactorError" class="banner error" role="alert">{{ twoFactorError }}</p>

      <!-- Setting up: the secret, then proof that it was scanned. -->
      <div v-if="enrolment" class="enrolment">
        <ol class="steps">
          <li>
            Add this to your authenticator app. Most will scan a QR code; if
            yours asks you to type a key, this is it:
            <code class="secret">{{ enrolment.secret }}</code>
          </li>
          <li>
            <strong>Save these recovery codes somewhere safe.</strong> Each works
            once, and they are the only way back in if you lose your phone. They
            are not shown again.
            <ul class="codes">
              <li v-for="recoveryCode in enrolment.recoveryCodes" :key="recoveryCode">{{ recoveryCode }}</li>
            </ul>
          </li>
          <li>
            Type the six-digit code your app is showing now, to prove it worked.
            <div class="confirm-row">
              <label class="visually-hidden" for="confirm-code">Six-digit code</label>
              <input id="confirm-code" v-model="confirmCode" type="text" inputmode="numeric" placeholder="000000" />
              <button class="btn-primary" :disabled="busy" @click="confirmTwoFactor">
                {{ busy ? 'Checking…' : 'Turn it on' }}
              </button>
            </div>
          </li>
        </ol>
      </div>

      <template v-else>
        <p v-if="twoFactor.active" class="hint">
          {{ twoFactor.recoveryCodesLeft }} recovery
          {{ twoFactor.recoveryCodesLeft === 1 ? 'code' : 'codes' }} left.
        </p>

        <div v-if="twoFactor.active" class="disable-row">
          <label class="visually-hidden" for="disable-password">Your password</label>
          <input id="disable-password" v-model="disablePassword" type="password" autocomplete="current-password" placeholder="Your password" />
          <button class="btn-secondary" :disabled="busy" @click="disableTwoFactor">
            {{ busy ? 'Turning off…' : 'Turn off' }}
          </button>
        </div>

        <button v-else class="btn-primary" :disabled="busy" @click="startTwoFactor">
          {{ busy ? 'Setting up…' : 'Set up two-step sign-in' }}
        </button>
      </template>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({ user: Object });
const profile = ref({ displayName: props.user?.displayName || '', email: props.user?.email || '' });

const saving = ref(false);
const saved = ref(false);
const error = ref('');

/* ------------------------------------------------------- two-factor -- */

const twoFactor = ref({ available: false, active: false, pending: false, recoveryCodesLeft: 0 });
const twoFactorError = ref('');
const busy = ref(false);
const confirmCode = ref('');
const disablePassword = ref('');

/**
 * The secret and recovery codes, held only while the person is setting up.
 *
 * Never re-fetched: the server returns them exactly once and stores only their
 * hashes, so if this is lost the only way forward is to start again. That is the
 * intended property, and the reason the panel tells the reader to write them
 * down before it asks for the confirming code.
 */
const enrolment = ref(null);

const loadTwoFactor = async () => {
  try {
    const res = await fetch('/api/auth/2fa');
    const body = await res.json().catch(() => ({}));

    if (res.ok) {
      twoFactor.value = body.data ?? twoFactor.value;
    }
  } catch {
    // A status that cannot be read leaves the panel hidden rather than showing
    // a control that would fail. `available` stays false.
  }
};

const startTwoFactor = async () => {
  busy.value = true;
  twoFactorError.value = '';

  try {
    const res = await fetch('/api/auth/2fa/enrol', { method: 'POST' });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      twoFactorError.value = body.error || `Two-step sign-in could not be set up (${res.status}).`;
      return;
    }

    enrolment.value = body.data;
  } catch (e) {
    twoFactorError.value = `Two-step sign-in could not be set up: ${e.message}`;
  } finally {
    busy.value = false;
  }
};

const confirmTwoFactor = async () => {
  busy.value = true;
  twoFactorError.value = '';

  try {
    const res = await fetch('/api/auth/2fa/confirm', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: confirmCode.value }),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      twoFactorError.value = body.error || `That code was not accepted (${res.status}).`;
      return;
    }

    enrolment.value = null;
    confirmCode.value = '';
    await loadTwoFactor();
  } catch (e) {
    twoFactorError.value = `That code could not be checked: ${e.message}`;
  } finally {
    busy.value = false;
  }
};

const disableTwoFactor = async () => {
  busy.value = true;
  twoFactorError.value = '';

  try {
    const res = await fetch('/api/auth/2fa/disable', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: disablePassword.value }),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      twoFactorError.value = body.error || `Two-step sign-in could not be turned off (${res.status}).`;
      return;
    }

    disablePassword.value = '';
    await loadTwoFactor();
  } catch (e) {
    twoFactorError.value = `Two-step sign-in could not be turned off: ${e.message}`;
  } finally {
    busy.value = false;
  }
};

onMounted(loadTwoFactor);

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

.two-factor { margin-top: 1.5rem; }
.two-factor h2 { font-size: 1.125rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.75rem; }
.state-line { margin: 0 0 1rem; color: var(--app-text-muted); line-height: 1.5; }
.state-line strong { color: var(--app-text); }
.hint { margin: 0 0 1rem; font-size: 0.875rem; color: var(--app-text-muted); }
.steps { margin: 0; padding-left: 1.25rem; line-height: 1.6; color: var(--app-text); }
.steps li { margin-bottom: 1rem; }
.secret { display: block; margin-top: 0.5rem; padding: 0.5rem 0.65rem; background: var(--app-surface); border: 1px solid var(--app-border); border-radius: 8px; font-family: ui-monospace, monospace; word-break: break-all; }
.codes { list-style: none; margin: 0.5rem 0 0; padding: 0.75rem; background: var(--app-surface); border: 1px solid var(--app-border); border-radius: 8px; font-family: ui-monospace, monospace; font-size: 0.875rem; columns: 2; }
.confirm-row, .disable-row { display: flex; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap; }
.confirm-row input, .disable-row input { flex: 1; min-width: 10rem; padding: 0.6rem 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.btn-secondary { padding: 0.75rem 1.25rem; background: var(--app-surface); color: var(--app-text); border: 1px solid var(--control-border); border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-secondary:focus-visible, input:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
</style>
