<template>
  <div class="profile">
    <h1 class="page-title">Profile</h1>
    <p class="page-subtitle">Manage your account settings</p>
    <div class="profile-card">
      <div class="form-group">
        <label>Display Name</label>
        <input v-model="profile.displayName" type="text" />
      </div>
      <div class="form-group">
        <label>Email</label>
        <input v-model="profile.email" type="email" />
      </div>
      <button class="btn-primary" @click="saveProfile">Save Changes</button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
const props = defineProps({ user: Object });
const profile = ref({ displayName: props.user?.displayName || '', email: props.user?.email || '' });
const saveProfile = () => alert('Profile saved!');
</script>

<style scoped>
.profile { max-width: 600px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin-bottom: 2rem; }
.profile-card { padding: 2rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
.form-group input { width: 100%; padding: 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); }
.btn-primary { padding: 0.75rem 1.5rem; background: var(--color-primary-600); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; }
</style>
