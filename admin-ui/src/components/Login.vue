<template>
  <div class="login">
    <div class="login-card">
      <div class="login-header">
        <div class="logo">C</div>
        <h1>Click CMS</h1>
        <p>Sign in to your account</p>
      </div>
      <!-- Labels carry `for`, and the inputs carry the matching `id`. A
           placeholder is not a label: it is announced inconsistently and it
           disappears the moment the field has a value, which is exactly when
           somebody re-reading the form needs to know what the field was. -->
      <!--
        Single sign-on, when the site configured it. Above the password form
        rather than below: at an organisation that uses it, it is the way in,
        and the password fields are the exception.

        A plain link, not a fetch. The endpoint answers with a redirect to the
        identity provider, and following that is what a browser navigation does
        and what XHR deliberately cannot.
      -->
      <template v-if="!needsCode && sso.enabled">
        <a class="btn-sso" :href="ssoHref">{{ sso.label }}</a>
        <p v-if="sso.passwordLogin" class="divider"><span>or</span></p>
      </template>

      <p v-if="ssoError" class="error" role="alert">{{ ssoError }}</p>

      <form v-if="!needsCode && sso.passwordLogin" @submit.prevent="handleLogin">
        <div class="form-group">
          <label for="login-username">Username</label>
          <input id="login-username" v-model="credentials.username" type="text" autocomplete="username" placeholder="Enter your username" required :disabled="loading" />
        </div>
        <div class="form-group">
          <label for="login-password">Password</label>
          <input id="login-password" v-model="credentials.password" type="password" autocomplete="current-password" placeholder="Enter your password" required :disabled="loading" />
        </div>
        <!-- A refused sign-in is the whole outcome of this screen, and it is
             rendered without moving focus, so it has to announce itself. -->
        <div v-if="error" class="error" role="alert">{{ error }}</div>
        <button type="submit" class="btn-primary" :disabled="loading">{{ loading ? 'Signing in...' : 'Sign In' }}</button>
      </form>

      <!--
        The second step. A separate form rather than a field revealed in place,
        so a password manager that filled the first one does not try to fill this
        with the password again — and so `autocomplete="one-time-code"` reaches
        the field that actually wants it, which is what makes a phone offer the
        code from its messages.
      -->
      <form v-else @submit.prevent="handleCode">
        <div class="form-group">
          <label for="login-code">Authentication code</label>
          <input
            id="login-code"
            ref="codeField"
            v-model="code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            placeholder="6-digit code"
            required
            :disabled="loading"
          />
          <p class="hint">
            From your authenticator app. If you cannot reach it, type one of your
            recovery codes instead.
          </p>
        </div>
        <div v-if="error" class="error" role="alert">{{ error }}</div>
        <button type="submit" class="btn-primary" :disabled="loading">
          {{ loading ? 'Checking…' : 'Continue' }}
        </button>
        <button type="button" class="btn-text" :disabled="loading" @click="startOver">
          Start again
        </button>
      </form>

      <p v-if="!needsCode" class="demo">Demo credentials: admin / admin</p>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { setCsrfToken } from '../lib/api.js';
import { withBase } from '../lib/base.js';

const emit = defineEmits(['loggedIn']);

/**
 * Whether this site offers single sign-on, and whether passwords still work.
 *
 * `passwordLogin` starts true so a site with no single sign-on — which is most
 * of them — draws the ordinary form on the first frame rather than flashing an
 * empty card while the status request is in flight.
 */
const sso = ref({ enabled: false, label: '', passwordLogin: true });
const ssoError = ref('');

const ssoHref = computed(() => withBase('/api/auth/sso/start'));

/**
 * A failed sign-on comes back as a redirect to this screen with the reason in
 * the query, because the browser arrives by navigation and there is no client
 * here to read a JSON body.
 */
const readSsoError = () => {
  if (typeof window === 'undefined') return;

  const reason = new URLSearchParams(window.location.search).get('ssoError');
  if (!reason) return;

  ssoError.value = reason;

  // Cleared from the address bar, so a reload does not re-announce a failure
  // that has already been read.
  const url = new URL(window.location.href);
  url.searchParams.delete('ssoError');
  window.history.replaceState({}, '', url);
};

onMounted(async () => {
  readSsoError();

  try {
    const res = await fetch('/api/auth/sso/status');
    const body = await res.json();
    const status = res.ok ? body?.data : null;

    // Read field by field, and only what is actually there. Assigning the
    // response wholesale means any unexpected body — a proxy's error page, a
    // future field, a plugin that shadowed the route — sets `passwordLogin` to
    // undefined and removes the password form, leaving a screen with no way in
    // at all. Hiding the form is the one thing here that can lock everybody
    // out, so it happens only when the server says so in as many words.
    if (status && typeof status === 'object') {
      sso.value = {
        enabled: status.enabled === true,
        label: typeof status.label === 'string' && status.label ? status.label : 'Single sign-on',
        passwordLogin: status.passwordLogin !== false,
      };
    }
  } catch {
    // A status that cannot be read leaves the ordinary password form, for the
    // same reason.
  }
});
const credentials = ref({ username: '', password: '' });
const loading = ref(false);
const error = ref('');

// Whether the password step succeeded and the account wants a second factor.
const needsCode = ref(false);
const code = ref('');
const codeField = ref(null);

/**
 * An error message, whatever shape the API used.
 *
 * The original read `data.error?.message`, but every endpoint in this CMS
 * answers with `error` as a plain string — so a refused login showed the
 * fallback "Login failed" and threw away the server's actual reason, including
 * "too many failed attempts, try again in 15 minutes", which is the one message
 * a locked-out person most needs to see.
 */
const messageFrom = (data, fallback) =>
  (typeof data?.error === 'string' && data.error)
  || data?.error?.message
  || fallback;

const handleLogin = async () => {
  loading.value = true;
  error.value = '';
  // `finally`, not a trailing assignment. The two-factor branch below returns
  // early, and with the old shape that return skipped the reset — leaving every
  // control on the code form disabled, so the second step could be reached and
  // never completed.
  try {
    const res = await fetch('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(credentials.value) });
    const data = await res.json();

    if (res.ok && data.data?.twoFactorRequired) {
      // The pending session issued its own CSRF token, and the next request has
      // to carry it. Without this the second step is refused as a forgery and
      // nobody with two-factor on can ever sign in.
      setCsrfToken(data.data.csrfToken ?? null);
      needsCode.value = true;
      // The password is no longer needed and should not sit in memory — or in a
      // field — for the rest of the session.
      credentials.value.password = '';
      await nextTick();
      codeField.value?.focus();
      return;
    }

    if (res.ok && data.data?.user) {
      emit('loggedIn', data.data.user);
    } else {
      error.value = messageFrom(data, 'Login failed');
    }
  } catch (e) {
    error.value = 'Login failed';
  } finally {
    loading.value = false;
  }
};

const handleCode = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/auth/2fa', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code.value }),
    });
    const data = await res.json();

    if (res.ok && data.data?.user) {
      emit('loggedIn', data.data.user);
    } else {
      error.value = messageFrom(data, 'That code is not right.');
      code.value = '';
    }
  } catch (e) {
    error.value = 'That code could not be checked.';
  } finally {
    loading.value = false;
  }
};

const startOver = () => {
  needsCode.value = false;
  code.value = '';
  error.value = '';
  credentials.value.password = '';
};
</script>

<style scoped>
.login { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--app-surface-strong); }
.login-card { width: 100%; max-width: 400px; padding: 2rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
.login-header { text-align: center; margin-bottom: 2rem; }
.logo { width: 64px; height: 64px; margin: 0 auto 1rem; background: linear-gradient(140deg, var(--color-primary-500), var(--color-primary-700)); border-radius: 16px; display: grid; place-items: center; color: white; font-size: 2rem; font-weight: 700; }
.login-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.login-header p { color: var(--app-text-muted); }
.form-group { margin-bottom: 1rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--app-text); }
.form-group input { width: 100%; padding: 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); }
/* The outline is kept, not removed. A recoloured border alone is a 1px hue
   change — on this palette it is under 3:1 against the resting border, so it is
   not a focus indicator anyone can see. */
.form-group input:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }
.error { color: var(--color-danger-600, #dc2626); margin-bottom: 1rem; }
.btn-primary { width: 100%; padding: 0.75rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.btn-primary:disabled { opacity: 0.6; }
.btn-text { width: 100%; margin-top: 0.75rem; padding: 0.5rem; background: none; border: none; color: var(--app-text-muted); font: inherit; cursor: pointer; text-decoration: underline; }
.btn-text:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.hint { margin: 0.5rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); line-height: 1.4; }
.btn-sso { display: block; width: 100%; padding: 0.75rem; text-align: center; background: var(--app-surface); color: var(--app-text); border: 1px solid var(--control-border); border-radius: 8px; font-weight: 500; text-decoration: none; }
.btn-sso:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.divider { display: flex; align-items: center; gap: 0.75rem; margin: 1.25rem 0; color: var(--app-text-muted); font-size: 0.8125rem; }
.divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--app-border); }
.demo { text-align: center; margin-top: 1.5rem; font-size: 0.875rem; color: var(--app-text-muted); }
</style>
