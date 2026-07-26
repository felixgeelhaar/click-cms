<template>
  <div class="admin-app">
    <div v-if="!isLoggedIn" class="login-screen">
      <Login @loggedIn="handleLoginSuccess" />
    </div>
    <div v-else-if="mustChangePassword" class="login-screen">
      <ChangePassword forced @changed="handlePasswordChanged" />
    </div>
    <div v-else class="admin-layout">
      <!-- Every screen begins with the same twenty-odd navigation links. Without
           a way past them, reaching the content by keyboard means tabbing
           through the lot on every page. The link is off-screen until focused,
           which is the first thing Tab reaches. -->
      <a class="skip-link" href="#admin-main" @click="focusMain">Skip to main content</a>
      <header class="topbar">
        <button
          class="icon-button"
          :aria-expanded="mobileNavOpen"
          aria-controls="admin-sidebar"
          aria-label="Toggle navigation"
          @click="toggleSidebar"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <button class="brand" @click="handleNavigate('/admin')">
          <span class="brand-mark">C</span>
          <span class="brand-name">{{ brandLabel }}</span>
        </button>
        <div class="topbar-right">
          <!-- The visible word names the current theme; on its own that reads as
               a label, not a control. The accessible name says what pressing it
               does, and aria-pressed carries the state. -->
          <button
            class="chip-button"
            :aria-pressed="theme === 'dark'"
            :aria-label="`Dark theme${theme === 'dark' ? ' (on)' : ' (off)'}`"
            @click="toggleTheme"
          >{{ theme === 'dark' ? 'Dark' : 'Light' }}</button>
          <!-- A single initial in a coloured square. The letter is decoration;
               what the control does is open the profile, and that is what it
               has to announce. -->
          <button
            class="profile-button"
            :aria-label="`Profile — ${currentUser?.displayName || currentUser?.username || 'account'}`"
            @click="handleNavigate('/admin/profile')"
          >
            <span aria-hidden="true">{{ (currentUser?.displayName || currentUser?.username || 'U').slice(0, 1).toUpperCase() }}</span>
          </button>
          <button class="text-button" @click="handleLogout">Logout</button>
        </div>
      </header>
      <div class="layout-body" :class="{ 'mobile-open': mobileNavOpen }">
        <!-- On a narrow screen the sidebar is a drawer over the content; the
             backdrop dismisses it, matching the expectation every mobile menu
             sets. It is only in the tree while open. -->
        <div v-if="mobileNavOpen" class="sidebar-backdrop" aria-hidden="true" @click="mobileNavOpen = false"></div>
        <aside id="admin-sidebar" class="sidebar-shell" :class="{ collapsed: isCollapsed, 'is-open': mobileNavOpen }">
          <Sidebar :active-route="currentRoute" :user-role="currentUser?.role" :collapsed="isCollapsed" :show-builder="hasBuilder" @navigate="handleNavigate" />
        </aside>
        <main id="admin-main" ref="mainEl" tabindex="-1" class="main-content" :class="{ collapsed: isCollapsed }">
          <!-- Above the page rather than inside it, so it is seen once on
               arrival wherever the reader lands, and never competes with the
               screen they came for. -->
          <UpdateNotice :capabilities="currentUser?.capabilities ?? []" />
          <component :is="currentComponent" v-bind="currentProps" @navigate="handleNavigate" @saved="handleNavigate('/admin/pages')" @cancel="handleNavigate('/admin/pages')" @back="handleNavigate('/admin/plugins')" @branding-updated="handleBrandingUpdate" />
        </main>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import Sidebar from './Sidebar.vue';
import UpdateNotice from './UpdateNotice.vue';
import Login from './Login.vue';
import Dashboard from './Dashboard.vue';
import Pages from './Pages.vue';
import Collections from './Collections.vue';
import PageEdit from './PageEdit.vue';
import Media from './Media.vue';
import Users from './Users.vue';
import Profile from './Profile.vue';
import Settings from './Settings.vue';
import Redirects from './Redirects.vue';
import Menus from './Menus.vue';
import FormSubmissions from './FormSubmissions.vue';
import Plugins from './Plugins.vue';
import PluginDetail from './PluginDetail.vue';
import Marketplace from './Marketplace.vue';
import Themes from './Themes.vue';
import Updates from './Updates.vue';
import Builder from './Builder.vue';
import ChangePassword from './ChangePassword.vue';
import { installCsrfFetch, setCsrfToken } from '../lib/api.js';
import { currentRoute as routeFromLocation, withBase } from '../lib/base.js';

const currentRoute = ref('/admin');
const isLoggedIn = ref(false);
const currentUser = ref(null);
const installedPluginIds = ref([]);
const theme = ref('light');
const isCollapsed = ref(false);
const mobileNavOpen = ref(false);
const mainEl = ref(null);

// The same control does two jobs by width: on a phone it opens the drawer, on a
// desktop it collapses the rail to icons. matchMedia is optional-chained so a
// test environment without it simply takes the desktop path.
const isMobileViewport = () =>
  typeof window !== 'undefined' && window.matchMedia?.('(max-width: 860px)')?.matches === true;
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
const toggleSidebar = () => {
  if (isMobileViewport()) { mobileNavOpen.value = !mobileNavOpen.value; }
  else { isCollapsed.value = !isCollapsed.value; }
};
const toggleTheme = () => { theme.value = theme.value === 'light' ? 'dark' : 'light'; document.documentElement.setAttribute('data-theme', theme.value); };

/**
 * Move focus, not just the viewport.
 *
 * A plain `#hash` jump scrolls but leaves focus where it was, so the next Tab
 * carries on through the navigation the reader just skipped. `<main>` carries
 * tabindex="-1" so it can receive focus programmatically without joining the
 * tab order.
 */
const focusMain = (event) => {
  event.preventDefault();
  mainEl.value?.focus();
};

/**
 * Escape closes the mobile drawer. It covers the screen and swallows clicks, so
 * without a key that dismisses it a keyboard reader who opens it is stuck
 * behind it.
 */
const onKeydown = (event) => {
  if (event.key === 'Escape' && mobileNavOpen.value) mobileNavOpen.value = false;
};
const handleBrandingUpdate = (nextBranding) => { branding.value = nextBranding; };

const getRouteComponent = () => {
  const path = currentRoute.value.split('?')[0];
  if (path === '/admin' || path === '/admin/') return Dashboard;
  if (path === '/admin/pages') return Pages;
  if (path === '/admin/collections') return Collections;
  if (path === '/admin/media') return Media;
  if (path === '/admin/users') return can('users.manage') ? Users : Dashboard;
  if (path === '/admin/profile') return Profile;
  if (path === '/admin/password') return ChangePassword;
  if (path === '/admin/plugins') return can('plugins.manage') ? Plugins : Dashboard;
  if (path === '/admin/marketplace') return can('plugins.install') ? Marketplace : Dashboard;
  if (path === '/admin/settings') return can('settings.manage') ? Settings : Dashboard;
  if (path === '/admin/themes') return can('settings.manage') ? Themes : Dashboard;
  if (path === '/admin/updates') return can('plugins.install') ? Updates : Dashboard;
  // Menus and redirects were the two routes with no check at all, so an account
  // the sidebar deliberately hides them from could still open them by address
  // and only discover the refusal when Save failed. Gated on the capability the
  // sidebar's own Manage group is gated on, so the screen an account can reach
  // and the screen it is offered are the same set.
  //
  // Note this is deliberately stricter than the API, which treats a menu and a
  // redirect as ordinary content documents and so permits any account with
  // content-edit rights. Whether restructuring navigation should be editorial
  // rather than administrative is a product question, not one to settle by
  // leaving a route unguarded.
  if (path === '/admin/redirects') return can('settings.manage') ? Redirects : Dashboard;
  if (path === '/admin/menus') return can('settings.manage') ? Menus : Dashboard;
  if (path === '/admin/submissions') return FormSubmissions;
  if (path === '/admin/builder') return hasBuilder.value ? Builder : Dashboard;
  if (path.startsWith('/admin/pages/edit/')) return PageEdit;
  if (path === '/admin/pages/new') return PageEdit;
  if (path.startsWith('/admin/plugins/')) return PluginDetail;
  return Dashboard;
};

const getRouteProps = () => {
  const [path, query] = currentRoute.value.split('?');
  if (path.startsWith('/admin/pages/edit/')) {
    // The language is carried in the query so a link to a translation — from the
    // page list's language chips, or a bookmark — opens that translation rather
    // than always the default one.
    const locale = new URLSearchParams(query || '').get('locale') || '';
    return { slug: path.replace('/admin/pages/edit/', ''), initialLocale: locale };
  }
  if (path === '/admin/pages/new') {
    const locale = new URLSearchParams((currentRoute.value.split('?')[1]) || '').get('locale') || '';
    return { initialLocale: locale };
  }
  if (path.startsWith('/admin/plugins/') && path !== '/admin/plugins') return { id: path.replace('/admin/plugins/', '') };
  if (path === '/admin/users') return { userRole: currentUser.value?.role, currentUsername: currentUser.value?.username };
  if (path === '/admin/plugins') return { userRole: currentUser.value?.role };
  if (path === '/admin/profile' && currentUser.value) return { user: currentUser.value };
  return {};
};

const currentComponent = computed(() => getRouteComponent());
const currentProps = computed(() => getRouteProps());

const handleNavigate = (path) => {
  // The route stays as the app spells it — `/admin/pages` — and only the address
  // bar gets the installation's prefix. That is what keeps every route
  // comparison below free of the question of where the CMS is installed.
  currentRoute.value = path;
  window.history.pushState({}, '', withBase(path));
  // Following a link closes the mobile drawer, so the destination is not left
  // hidden behind the menu the reader just used.
  mobileNavOpen.value = false;
};

onMounted(async () => {
  installCsrfFetch();
  currentRoute.value = routeFromLocation();
  await checkAuth();
  window.addEventListener('popstate', () => { currentRoute.value = routeFromLocation(); });
  window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => { window.removeEventListener('keydown', onKeydown); });
</script>

<style scoped>
.admin-app { min-height: 100vh; }
/* Off-screen until focused, then a real, visible target at the top left. */
.skip-link {
  position: absolute; left: -9999px; top: 0; z-index: 100;
  padding: 0.6rem 1rem; border-radius: 0 0 8px 0;
  background: var(--color-primary-600); color: #fff; text-decoration: none; font-weight: 600;
}
.skip-link:focus { left: 0; outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.main-content:focus { outline: none; }
.main-content:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: -2px; }
.login-screen { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--app-surface-strong); }
.admin-layout { display: flex; flex-direction: column; min-height: 100vh; }
.topbar { position: sticky; top: 0; z-index: 50; display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; background: var(--app-surface); border-bottom: 1px solid var(--app-border); }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 0.75rem; }
.icon-button { padding: 0.5rem; border: 1px solid var(--control-border); background: var(--app-surface-strong); border-radius: 8px; cursor: pointer; color: var(--app-text); }
.icon-button svg { width: 20px; height: 20px; }
.topbar button:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: 2px; }
.brand { display: flex; align-items: center; gap: 0.5rem; background: none; border: none; cursor: pointer; font-weight: 700; color: var(--app-text); }
.brand-mark { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(140deg, var(--color-primary-500), var(--color-primary-700)); color: white; display: grid; place-items: center; }
.chip-button { padding: 0.4rem 0.75rem; border: 1px solid var(--control-border); background: var(--app-surface-strong); border-radius: 999px; font-size: 0.75rem; color: var(--app-text); cursor: pointer; }
.profile-button { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--color-primary-500), var(--color-primary-700)); color: white; display: grid; place-items: center; font-weight: 600; border: none; cursor: pointer; }
.text-button { background: none; border: none; color: var(--app-text-muted); cursor: pointer; }
.layout-body { display: flex; flex: 1; align-items: flex-start; }
/* The sidebar tracks the scroll on desktop, so navigation is always in reach on
   a long editing screen rather than scrolled off the top. */
.sidebar-shell { position: sticky; top: 65px; align-self: flex-start; height: calc(100vh - 65px); width: 240px; flex-shrink: 0; background: var(--app-surface); border-right: 1px solid var(--app-border); transition: width 0.2s; overflow-y: auto; }
.sidebar-shell.collapsed { width: 64px; }
.main-content { flex: 1; padding: 2rem; min-height: calc(100vh - 65px); transition: margin-left 0.2s; }
.main-content.collapsed { margin-left: 0; }
.sidebar-backdrop { display: none; }

/* Mobile: the rail becomes an off-canvas drawer. The hamburger opens it, the
   backdrop or a chosen link closes it. */
@media (max-width: 860px) {
  .sidebar-shell {
    position: fixed; top: 0; bottom: 0; left: 0; height: 100%; width: 264px !important; z-index: 60;
    transform: translateX(-100%); transition: transform 0.2s ease;
    box-shadow: 0 0 40px -10px rgba(16, 24, 40, 0.4);
  }
  .sidebar-shell.is-open { transform: translateX(0); }
  .layout-body.mobile-open .sidebar-backdrop {
    display: block; position: fixed; inset: 0; z-index: 55;
    background: rgba(16, 24, 40, 0.45);
  }
  .main-content { padding: 1.25rem; }
}
</style>
