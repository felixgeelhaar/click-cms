<template>
  <div class="admin-app">
    <div v-if="!isLoggedIn" class="login-screen">
      <Login @loggedIn="handleLoginSuccess" />
    </div>
    <div v-else-if="mustChangePassword" class="login-screen">
      <ChangePassword forced @changed="handlePasswordChanged" />
    </div>
    <div v-else class="admin-layout">
      <header class="topbar">
        <button class="icon-button" @click="toggleSidebar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <button class="brand" @click="handleNavigate('/admin')">
          <span class="brand-mark">C</span>
          <span class="brand-name">{{ brandLabel }}</span>
        </button>
        <div class="topbar-right">
          <button class="chip-button" @click="toggleTheme">{{ theme === 'dark' ? 'Dark' : 'Light' }}</button>
          <button class="profile-button" @click="handleNavigate('/admin/profile')">
            {{ (currentUser?.displayName || currentUser?.username || 'U').slice(0, 1).toUpperCase() }}
          </button>
          <button class="text-button" @click="handleLogout">Logout</button>
        </div>
      </header>
      <div class="layout-body">
        <aside class="sidebar-shell" :class="{ collapsed: isCollapsed }">
          <Sidebar :active-route="currentRoute" :user-role="currentUser?.role" :collapsed="isCollapsed" :show-builder="hasBuilder" @navigate="handleNavigate" />
        </aside>
        <main class="main-content" :class="{ collapsed: isCollapsed }">
          <component :is="currentComponent" v-bind="currentProps" @navigate="handleNavigate" @saved="handleNavigate('/admin/pages')" @cancel="handleNavigate('/admin/pages')" @back="handleNavigate('/admin/plugins')" @branding-updated="handleBrandingUpdate" />
        </main>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import Sidebar from './Sidebar.vue';
import Login from './Login.vue';
import Dashboard from './Dashboard.vue';
import Pages from './Pages.vue';
import PageEdit from './PageEdit.vue';
import Media from './Media.vue';
import Users from './Users.vue';
import Profile from './Profile.vue';
import Plugins from './Plugins.vue';
import PluginDetail from './PluginDetail.vue';
import Marketplace from './Marketplace.vue';
import Builder from './Builder.vue';
import ChangePassword from './ChangePassword.vue';
import { installCsrfFetch, setCsrfToken } from '../lib/api.js';

const currentRoute = ref('/admin');
const isLoggedIn = ref(false);
const currentUser = ref(null);
const installedPluginIds = ref([]);
const theme = ref('light');
const isCollapsed = ref(false);
const branding = ref({ name: '', primaryColor: '' });

// An account still using the installed password may reach nothing but the
// password screen — the API enforces this too, so the UI is only the courtesy.
const mustChangePassword = computed(() => currentUser.value?.mustChangePassword === true);

const brandLabel = computed(() => branding.value.name || currentUser.value?.displayName || currentUser.value?.username || 'Workspace');
// Capability rather than role: the rules live on the server, and the UI asks
// what this account may do rather than re-deriving it from a role name.
const can = (capability) => (currentUser.value?.capabilities ?? []).includes(capability);

const hasBuilder = computed(
  () => installedPluginIds.value.includes('visual-builder') && can('edit.freeform')
);

const checkAuth = async () => {
  try {
    const res = await fetch('/api/auth/check');
    const data = await res.json();
    // Kept current on every check, so a re-login or a new session is picked up
    // without any component having to think about it.
    setCsrfToken(data.data?.csrfToken ?? null);
    isLoggedIn.value = data.data?.authenticated || false;
    currentUser.value = data.data?.user || null;
    if (isLoggedIn.value) { await loadInstalledPlugins(); }
  } catch (e) { isLoggedIn.value = false; }
};

const loadInstalledPlugins = async () => {
  try {
    const res = await fetch('/api/plugins');
    const data = await res.json();
    installedPluginIds.value = (data.data || []).map(p => p.id);
  } catch (e) { installedPluginIds.value = []; }
};

const handleLoginSuccess = async (user) => { currentUser.value = user; isLoggedIn.value = true; currentRoute.value = '/admin'; await checkAuth(); };
const handlePasswordChanged = async () => {
  await checkAuth();
};

const handleLogout = async () => { await fetch('/api/auth/logout', { method: 'POST' }); currentUser.value = null; isLoggedIn.value = false; };
const toggleSidebar = () => { isCollapsed.value = !isCollapsed.value; };
const toggleTheme = () => { theme.value = theme.value === 'light' ? 'dark' : 'light'; document.documentElement.setAttribute('data-theme', theme.value); };
const handleBrandingUpdate = (nextBranding) => { branding.value = nextBranding; };

const getRouteComponent = () => {
  const path = currentRoute.value.split('?')[0];
  if (path === '/admin' || path === '/admin/') return Dashboard;
  if (path === '/admin/pages') return Pages;
  if (path === '/admin/media') return Media;
  if (path === '/admin/users') return can('users.manage') ? Users : Dashboard;
  if (path === '/admin/profile') return Profile;
  if (path === '/admin/password') return ChangePassword;
  if (path === '/admin/plugins') return Plugins;
  if (path === '/admin/marketplace') return can('plugins.install') ? Marketplace : Dashboard;
  if (path === '/admin/builder') return hasBuilder.value ? Builder : Dashboard;
  if (path.startsWith('/admin/pages/edit/')) return PageEdit;
  if (path === '/admin/pages/new') return PageEdit;
  if (path.startsWith('/admin/plugins/')) return PluginDetail;
  return Dashboard;
};

const getRouteProps = () => {
  const path = currentRoute.value.split('?')[0];
  if (path.startsWith('/admin/pages/edit/')) return { slug: path.replace('/admin/pages/edit/', '') };
  if (path.startsWith('/admin/plugins/') && path !== '/admin/plugins') return { id: path.replace('/admin/plugins/', '') };
  if (path === '/admin/users') return { userRole: currentUser.value?.role, currentUsername: currentUser.value?.username };
  if (path === '/admin/plugins') return { userRole: currentUser.value?.role };
  if (path === '/admin/profile' && currentUser.value) return { user: currentUser.value };
  return {};
};

const currentComponent = computed(() => getRouteComponent());
const currentProps = computed(() => getRouteProps());

const handleNavigate = (path) => { currentRoute.value = path; window.history.pushState({}, '', path); };

onMounted(async () => {
  installCsrfFetch();
  currentRoute.value = window.location.pathname + window.location.search;
  await checkAuth();
  window.addEventListener('popstate', () => { currentRoute.value = window.location.pathname + window.location.search; });
});
</script>

<style scoped>
.admin-app { min-height: 100vh; }
.login-screen { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--app-surface-strong); }
.admin-layout { display: flex; flex-direction: column; min-height: 100vh; }
.topbar { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; background: var(--app-surface); border-bottom: 1px solid var(--app-border); }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 0.75rem; }
.icon-button { padding: 0.5rem; border: 1px solid var(--app-border); background: var(--app-surface-strong); border-radius: 8px; cursor: pointer; }
.icon-button svg { width: 20px; height: 20px; }
.brand { display: flex; align-items: center; gap: 0.5rem; background: none; border: none; cursor: pointer; font-weight: 700; color: var(--app-text); }
.brand-mark { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(140deg, var(--color-primary-500), var(--color-primary-700)); color: white; display: grid; place-items: center; }
.chip-button { padding: 0.4rem 0.75rem; border: 1px solid var(--app-border); background: var(--app-surface-strong); border-radius: 999px; font-size: 0.75rem; cursor: pointer; }
.profile-button { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--color-primary-500), var(--color-primary-700)); color: white; display: grid; place-items: center; font-weight: 600; border: none; cursor: pointer; }
.text-button { background: none; border: none; color: var(--app-text-muted); cursor: pointer; }
.layout-body { display: flex; flex: 1; }
.sidebar-shell { width: 240px; flex-shrink: 0; background: var(--app-surface); border-right: 1px solid var(--app-border); transition: width 0.2s; }
.sidebar-shell.collapsed { width: 64px; }
.main-content { flex: 1; padding: 2rem; min-height: calc(100vh - 64px); transition: margin-left 0.2s; }
.main-content.collapsed { margin-left: 0; }
</style>
