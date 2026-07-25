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
      <form @submit.prevent="handleLogin">
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
      <p class="demo">Demo credentials: admin / admin</p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
const emit = defineEmits(['loggedIn']);
const credentials = ref({ username: '', password: '' });
const loading = ref(false);
const error = ref('');

const handleLogin = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(credentials.value) });
    const data = await res.json();
    if (res.ok && data.data?.user) {
      emit('loggedIn', data.data.user);
    } else {
      error.value = data.error?.message || 'Login failed';
    }
  } catch (e) { error.value = 'Login failed'; }
  loading.value = false;
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
.demo { text-align: center; margin-top: 1.5rem; font-size: 0.875rem; color: var(--app-text-muted); }
</style>
