<template>
  <div class="users">
    <div class="page-header">
      <div>
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle">{{ subtitle }}</p>
      </div>
      <button class="btn-primary" @click="startCreate">+ New user</button>
    </div>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="loading" class="banner">Loading…</p>

    <form v-if="draft" class="editor" @submit.prevent="save">
      <h2 class="editor-title">{{ draft.isNew ? 'New user' : `Edit ${draft.username}` }}</h2>

      <label for="u-username">Username</label>
      <input id="u-username" v-model="draft.username" :disabled="!draft.isNew" required />
      <p v-if="!draft.isNew" class="hint">The username identifies the account and cannot be changed.</p>

      <label for="u-display">Display name</label>
      <input id="u-display" v-model="draft.displayName" />

      <label for="u-email">Email</label>
      <input id="u-email" v-model="draft.email" type="email" />

      <label for="u-role">Role</label>
      <select id="u-role" v-model="draft.role">
        <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
      </select>
      <p class="hint">{{ roleHint }}</p>

      <template v-if="draft.isNew">
        <label for="u-password">Password</label>
        <input id="u-password" v-model="draft.password" type="password" minlength="8" required />
        <p class="hint">At least 8 characters. They can change it after signing in.</p>
      </template>

      <div class="editor-actions">
        <button type="button" class="btn-secondary" @click="draft = null">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="saving">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </form>

    <ul v-if="!loading && users.length" class="list">
      <li v-for="user in users" :key="user.username" class="row">
        <div class="identity">
          <span class="avatar">{{ (user.displayName || user.username).slice(0, 1).toUpperCase() }}</span>
          <div>
            <p class="name">
              {{ user.displayName || user.username }}
              <span v-if="user.username === currentUsername" class="you">you</span>
            </p>
            <p class="meta">{{ user.username }}<span v-if="user.email"> · {{ user.email }}</span></p>
          </div>
        </div>

        <span class="role" :class="`role--${user.role}`">{{ roleLabel(user.role) }}</span>

        <div class="row-actions">
          <button class="btn-sm" @click="startEdit(user)">Edit</button>
          <button
            class="btn-sm danger"
            :disabled="user.username === currentUsername"
            :title="user.username === currentUsername ? 'You cannot delete your own account' : ''"
            @click="remove(user)"
          >Delete</button>
        </div>
      </li>
    </ul>

    <p v-else-if="!loading" class="banner">No users yet.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({ userRole: String, currentUsername: String });

const users = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const draft = ref(null);

// Mirrors Domain\Identity\Role. The server decides; this only explains.
const roles = [
  { value: 'admin', label: 'Administrator', hint: 'Full access, including users and plugins.' },
  { value: 'editor', label: 'Editor', hint: 'Edits and publishes any content. Cannot manage users or plugins.' },
  { value: 'author', label: 'Author', hint: 'Writes and edits their own content. Cannot publish.' },
  { value: 'viewer', label: 'Viewer', hint: 'Read-only.' },
];

const roleLabel = (value) => roles.find((r) => r.value === value)?.label ?? value ?? 'Viewer';
const roleHint = computed(() => roles.find((r) => r.value === draft.value?.role)?.hint ?? '');
const subtitle = computed(() => `${users.value.length} ${users.value.length === 1 ? 'account' : 'accounts'}`);

const load = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/users');
    if (!res.ok) throw new Error(`Request failed (${res.status})`);
    const body = await res.json();
    // The API returns stored content; the account itself lives under `data`.
    users.value = (body.data ?? []).map((u) => ({ username: u.slug, ...(u.data ?? {}) }));
  } catch (e) {
    error.value = `Could not load users: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

const startCreate = () => {
  draft.value = { isNew: true, username: '', displayName: '', email: '', role: 'editor', password: '' };
};

const startEdit = (user) => {
  draft.value = { isNew: false, ...user };
};

const save = async () => {
  saving.value = true;
  error.value = '';

  const payload = {
    username: draft.value.username,
    displayName: draft.value.displayName,
    email: draft.value.email,
    role: draft.value.role,
  };
  if (draft.value.isNew) payload.password = draft.value.password;

  try {
    const res = await fetch(
      draft.value.isNew ? '/api/users' : `/api/users/${draft.value.username}`,
      {
        method: draft.value.isNew ? 'POST' : 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      error.value = body.error ?? `Could not save (${res.status}).`;
      return;
    }
    draft.value = null;
    await load();
  } catch (e) {
    error.value = `Could not save: ${e.message}`;
  } finally {
    saving.value = false;
  }
};

const remove = async (user) => {
  // Deleting your own account would sign you out of a system you may be the
  // only administrator of, so it is refused here as well as on the server.
  if (user.username === props.currentUsername) return;

  const res = await fetch(`/api/users/${user.username}`, { method: 'DELETE' });
  if (res.ok) {
    await load();
  } else {
    const body = await res.json().catch(() => ({}));
    error.value = body.error ?? `Could not delete (${res.status}).`;
  }
};

onMounted(load);
</script>

<style scoped>
.users { max-width: 900px; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.35rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); }
.editor { border: 1px solid var(--app-border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem; background: var(--card-bg); }
.editor-title { margin: 0 0 1rem; font-size: 1.125rem; }
.editor label { display: block; margin: 0 0 0.3rem; font-size: 0.875rem; font-weight: 500; }
.editor input, .editor select { width: 100%; padding: 0.6rem 0.7rem; margin-bottom: 0.3rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }
.editor input:disabled { opacity: 0.65; }
.hint { margin: 0 0 1rem; font-size: 0.8125rem; color: var(--app-text-muted); }
.editor-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; }
.row { display: flex; align-items: center; gap: 1rem; padding: 0.875rem 1rem; border: 1px solid var(--app-border); border-radius: 10px; background: var(--card-bg); }
.identity { display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 0; }
.avatar { width: 36px; height: 36px; border-radius: 8px; display: grid; place-items: center; font-weight: 600; color: #fff; background: linear-gradient(135deg, var(--color-primary-500), var(--color-primary-700)); }
.name { margin: 0; font-weight: 600; font-size: 0.9375rem; }
.you { margin-left: 0.4rem; font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-text-muted); }
.meta { margin: 0.1rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.role { font-size: 0.75rem; padding: 0.2rem 0.6rem; border-radius: 999px; background: var(--app-surface-strong); white-space: nowrap; }
.role--admin { background: var(--color-primary-600); color: #fff; }
.row-actions { display: flex; gap: 0.5rem; }
.btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8125rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); }
.btn-sm.danger { color: var(--color-danger-600, #dc2626); }
.btn-sm:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-primary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--app-surface-strong); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

/*
 * Focus. Every control here is reachable by keyboard and, until this rule, none
 * of them said so: the browser default is easy to lose against these surfaces
 * and several controls sit on tinted backgrounds where it disappears entirely.
 * One ring, stated once, on whatever the keyboard is actually on.
 */
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
summary:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
