<template>
  <div class="change-password">
    <div class="card">
      <h1 class="title">{{ forced ? 'Choose a password' : 'Change password' }}</h1>

      <p v-if="forced" class="intro">
        This account still uses the password it was installed with, which is
        published in the documentation. Set your own before continuing.
      </p>

      <p v-if="error" class="message error" role="alert">{{ error }}</p>
      <p v-else-if="done" class="message success">Password changed.</p>

      <form @submit.prevent="submit">
        <label for="current">Current password</label>
        <input id="current" v-model="current" type="password" autocomplete="current-password" required />

        <label for="next">New password</label>
        <input
          id="next"
          v-model="next"
          type="password"
          autocomplete="new-password"
          :minlength="minLength"
          required
        />
        <p class="hint">At least {{ minLength }} characters.</p>

        <label for="confirm">Repeat new password</label>
        <input id="confirm" v-model="confirm" type="password" autocomplete="new-password" required />
        <p v-if="mismatch" class="hint mismatch">The two entries do not match.</p>

        <button class="btn-primary" type="submit" :disabled="saving || mismatch">
          {{ saving ? 'Saving…' : 'Set password' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  forced: { type: Boolean, default: false },
  minLength: { type: Number, default: 8 },
});

const emit = defineEmits(['changed']);

const current = ref('');
const next = ref('');
const confirm = ref('');
const saving = ref(false);
const error = ref('');
const done = ref(false);

// Checked here as a courtesy; the server is what actually decides.
const mismatch = computed(() => confirm.value !== '' && next.value !== confirm.value);

const submit = async () => {
  saving.value = true;
  error.value = '';
  done.value = false;

  try {
    const res = await fetch('/api/auth/password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ currentPassword: current.value, newPassword: next.value }),
    });

    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      error.value = body.error ?? `Could not change the password (${res.status}).`;
      return;
    }

    done.value = true;
    current.value = next.value = confirm.value = '';
    emit('changed');
  } catch (e) {
    error.value = `Could not change the password: ${e.message}`;
  } finally {
    saving.value = false;
  }
};
</script>

<style scoped>
.change-password { display: flex; justify-content: center; padding: 3rem 1rem; }
.card { width: 100%; max-width: 420px; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: 2rem; }
.title { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.75rem; }
.intro { margin: 0 0 1.25rem; font-size: 0.9375rem; color: var(--app-text-muted); }
.message { padding: 0.7rem 0.9rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1rem; }
.error { color: var(--color-danger-600, #dc2626); background: var(--app-surface-strong); }
.success { color: var(--color-primary-600); background: var(--app-surface-strong); }
label { display: block; margin: 0 0 0.35rem; font-size: 0.875rem; font-weight: 500; }
input { width: 100%; padding: 0.65rem 0.75rem; margin-bottom: 0.35rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.hint { margin: 0 0 1rem; font-size: 0.8125rem; color: var(--app-text-muted); }
.mismatch { color: var(--color-danger-600, #dc2626); }
.btn-primary { width: 100%; padding: 0.7rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
