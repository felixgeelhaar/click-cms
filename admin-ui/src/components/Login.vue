<template>
  <div class="login">
    <div class="login-card">
      <div class="login-header">
        <div class="logo">C</div>
        <h1>Click CMS</h1>
        <p>Sign in to your account</p>
      </div>
      <form @submit.prevent="handleLogin">
        <div class="form-group">
          <label>Username</label>
          <input v-model="credentials.username" type="text" placeholder="Enter your username" required :disabled="loading" />
        </div>
        <div class="form-group">
          <label>Password</label>
          <input v-model="credentials.password" type="password" placeholder="Enter your password" required :disabled="loading" />
        </div>
        <div v-if="error" class="error">{{ error }}</div>
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
.form-group input { width: 100%; padding: 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); }
.form-group input:focus { outline: none; border-color: var(--color-primary-500); }
.error { color: var(--color-danger-500); margin-bottom: 1rem; }
.btn-primary { width: 100%; padding: 0.75rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; }
.btn-primary:disabled { opacity: 0.6; }
.demo { text-align: center; margin-top: 1.5rem; font-size: 0.875rem; color: var(--app-text-muted); }
</style>
