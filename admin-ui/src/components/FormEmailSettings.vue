<template>
  <section class="panel email-settings" aria-labelledby="email-settings-heading">
    <h2 id="email-settings-heading">{{ message('Form email alerts') }}</h2>
    <p>{{ message('Get an email when someone sends a contact form. Messages are always saved under Submissions, even if an email fails.') }}</p>
    <p v-if="unavailable">{{ message('Enable the Forms plugin under Plugins to set up email alerts.') }}</p>
    <template v-else>
      <p v-if="error" role="alert" class="email-error">{{ message(error) }}</p>
      <p v-if="notice" role="status">{{ message(notice) }}</p>
      <form @submit.prevent="save">
        <fieldset :disabled="loading || busy !== ''">
          <label class="email-enabled"><input v-model="settings.enabled" type="checkbox" /> {{ message('Enable email alerts') }}</label>
          <label for="form-email-to">{{ message('Send alerts to') }}</label>
          <input id="form-email-to" v-model="settings.to" type="email" maxlength="254" :required="settings.enabled" autocomplete="email" />
          <small>{{ message('The person who should be notified about new messages.') }}</small>
          <label for="form-email-from">{{ message('Sender email address') }}</label>
          <input id="form-email-from" v-model="settings.from" type="email" maxlength="254" :required="settings.enabled" aria-describedby="form-email-from-help" />
          <small id="form-email-from-help">{{ message('Use an address your website is allowed to send from. Your hosting provider can confirm this.') }}</small>
          <label for="form-email-language">{{ message('Email language') }}</label>
          <select id="form-email-language" v-model="settings.locale"><option value="en">English</option><option value="de">Deutsch</option></select>
          <p>{{ message('Alerts contain a reference to the saved message, without the visitor’s personal details.') }}</p>
          <div class="email-actions">
            <button class="btn-primary" type="submit">{{ message(busy === 'save' ? 'Saving…' : 'Save email settings') }}</button>
            <button type="button" :disabled="dirty || !settings.to || !settings.from" @click="sendTest">{{ message(busy === 'test' ? 'Sending…' : 'Send test email') }}</button>
          </div>
          <small v-if="dirty">{{ message('Save your changes before sending a test email.') }}</small>
        </fieldset>
      </form>
      <p class="email-help">{{ message('After sending a test, check the recipient’s inbox and spam folder. If it does not arrive, ask your hosting provider to check email sending.') }}</p>
    </template>
  </section>
</template>
<script setup>
import { computed, onMounted, ref } from 'vue';
import { message } from '../lib/messages.js';
const settings = ref({ enabled: false, to: '', from: '', locale: 'en' });
const saved = ref('');
const dirty = computed(() => JSON.stringify(settings.value) !== saved.value);
const loading = ref(true);
const busy = ref('');
const error = ref('');
const notice = ref('');
const unavailable = ref(false);
const knownErrors = new Set([
  'You do not have permission to change email settings.',
  'Choose whether alerts are enabled and select an email language.',
  'Enter valid recipient and sender email addresses.',
  'Email settings could not be read or saved. Please try again or contact your website administrator.',
  'Too many test emails. Please wait ten minutes before trying again.',
  'Save valid recipient and sender email addresses before sending a test.',
  'The server could not send the test email. Your settings are saved. Ask your hosting provider to check email sending.',
]);
const fallback = 'Could not connect. Please check your connection and try again.';
const fail = body => { error.value = knownErrors.has(body.error) ? body.error : fallback; };
onMounted(async () => {
  try {
    const response = await fetch('/api/forms/email-settings');
    if (response.status === 404) { unavailable.value = true; return; }
    const body = await response.json();
    if (!response.ok) { fail(body); return; }
    settings.value = { ...settings.value, ...body.data };
    saved.value = JSON.stringify(settings.value);
  } catch { error.value = fallback; }
  finally { loading.value = false; }
});
async function save() {
  busy.value = 'save'; error.value = ''; notice.value = '';
  try {
    const response = await fetch('/api/forms/email-settings', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(settings.value) });
    const body = await response.json();
    if (!response.ok) { fail(body); return; }
    settings.value = body.data;
    saved.value = JSON.stringify(settings.value);
    notice.value = 'Email settings saved.';
  } catch { error.value = fallback; }
  finally { busy.value = ''; }
}
async function sendTest() {
  busy.value = 'test'; error.value = ''; notice.value = '';
  try {
    const response = await fetch('/api/forms/email-test', { method: 'POST' });
    const body = await response.json();
    if (!response.ok) { fail(body); return; }
    notice.value = 'The mail server accepted the test email. Check the recipient’s inbox and spam folder to confirm delivery.';
  } catch { error.value = fallback; }
  finally { busy.value = ''; }
}
</script>
<style scoped>
.email-settings { margin-top: 1.5rem; }
h2 { font-size: 1.0625rem; margin: 0 0 .5rem; }
p, small { font-size: .875rem; color: var(--app-text-muted); line-height: 1.5; }
fieldset { padding: 0; border: 0; min-width: 0; }
label { display: block; margin: 1rem 0 .35rem; font-weight: 500; }
input:not([type=checkbox]), select { display: block; box-sizing: border-box; width: 100%; max-width: 30rem; padding: .6rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
small { display: block; margin-top: .35rem; }
.email-enabled { display: flex; align-items: center; gap: .5rem; }
.email-actions { display: flex; gap: .75rem; flex-wrap: wrap; }
button { padding: .6rem 1rem; border-radius: 8px; border: 1px solid var(--control-border); background: var(--app-surface); color: var(--app-text); font: inherit; cursor: pointer; }
.btn-primary { background: var(--color-primary-600); color: white; border-color: transparent; }
button:disabled, fieldset:disabled { opacity: .65; cursor: not-allowed; }
:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: 2px; }
.email-error { color: var(--color-danger-600); }
.email-help { margin-bottom: 0; }
</style>
