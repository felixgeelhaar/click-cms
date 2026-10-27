<template>
  <div class="admin-app" :lang="messageLanguage">
    <div class="message-language">
      <label for="message-language">{{ message('Interface language') }}</label>
      <select id="message-language" :value="messageLanguage" @change="setMessageLanguage($event.target.value)">
        <option value="en">English</option><option value="de">Deutsch</option>
      </select>
    </div>
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
      <a class="skip-link" href="#admin-main" @click="focusMain">{{ message('Skip to main content') }}</a>
      <header class="topbar">
        <button
          class="icon-button"
          :aria-expanded="mobileNavOpen"
          aria-controls="admin-sidebar"
          :aria-label="message(&quot;Toggle navigation&quot;)"
          @click="toggleSidebar"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <button class="brand" @click="handleNavigate('/admin')">
          <span class="brand-mark">click</span>
          <span class="brand-name">{{ brandLabel }}</span>
        </button>
        <!--
          Which site this is, shown only when the installation serves more than
          one. Somebody looking after eight client sites with three tabs open
          needs the answer on screen rather than inferable from the address bar:
          editing the wrong client's homepage is a mistake with no warning and
          an audience.
        -->
        <span v-if="site.multiSite" class="site-chip" :title="message('You are editing {0}', [site.title])">
          {{ site.title }}
        </span>
        <div class="topbar-right">
          <!-- The visible word names the current theme; on its own that reads as
               a label, not a control. The accessible name says what pressing it
               does, and aria-pressed carries the state. -->
          <button
            class="chip-button"
            :aria-pressed="theme === 'dark'"
            :aria-label="message('Dark theme{0}', [message(theme === 'dark' ? ' (on)' : ' (off)')])"
            @click="toggleTheme"
          >{{ theme === 'dark' ? message('Dark') : message('Light') }}</button>
          <!-- A single initial in a coloured square. The letter is decoration;
               what the control does is open the profile, and that is what it
               has to announce. -->
          <button
            class="profile-button"
            :aria-label="message('Profile — {0}', [currentUser?.displayName || currentUser?.username || message('account')])"
            @click="handleNavigate('/admin/profile')"
          >
            <span aria-hidden="true">{{ (currentUser?.displayName || currentUser?.username || 'U').slice(0, 1).toUpperCase() }}</span>
          </button>
          <button class="text-button" @click="handleLogout">{{ message('Logout') }}</button>
        </div>
      </header>
      <div class="layout-body" :class="{ 'mobile-open': mobileNavOpen }">
        <!-- On a narrow screen the sidebar is a drawer over the content; the
             backdrop dismisses it, matching the expectation every mobile menu
             sets. It is only in the tree while open. -->
        <div v-if="mobileNavOpen" class="sidebar-backdrop" aria-hidden="true" @click="mobileNavOpen = false"></div>
        <aside id="admin-sidebar" class="sidebar-shell" :class="{ collapsed: isCollapsed, 'is-open': mobileNavOpen }">
          <Sidebar :active-route="currentRoute" :user-role="currentUser?.role" :capabilities="currentUser?.capabilities ?? []" :collapsed="isCollapsed" :show-builder="hasBuilder" :show-webhooks="hasWebhooks" :show-reviews="hasReviews" :show-release="hasRelease" @navigate="handleNavigate" />
        </aside>
        <main id="admin-main" ref="mainEl" tabindex="-1" class="main-content" :class="{ collapsed: isCollapsed }">
          <!-- Above the page rather than inside it, so it is seen once on
               arrival wherever the reader lands, and never competes with the
               screen they came for. -->
          <UpdateNotice :capabilities="currentUser?.capabilities ?? []" />
          <component :is="currentComponent" v-bind="currentProps" @navigate="handleNavigate" @replace-route="handleReplaceRoute" @saved="handleNavigate('/admin/pages')" @cancel="handleCancel" @back="handleNavigate('/admin/plugins')" @branding-updated="handleBrandingUpdate" @settings-updated="handleSettingsUpdated" />
        </main>
      </div>
    </div>
  </div>
</template>

<script setup>
import { message } from "../lib/messages.js";
import { messageLanguage, setMessageLanguage } from '../lib/messages.js';
import { ref, computed, onMounted, onUnmounted, watchEffect } from 'vue';
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
import Webhooks from './Webhooks.vue';
import Plugins from './Plugins.vue';
import PluginDetail from './PluginDetail.vue';
import Marketplace from './Marketplace.vue';
import Themes from './Themes.vue';
import Updates from './Updates.vue';
import Builder from './Builder.vue';
import ReviewsInbox from './collaboration/ReviewsInbox.vue';
import Release from './collaboration/Release.vue';
import ChangePassword from './ChangePassword.vue';
import SecurityHealth from './SecurityHealth.vue';
import ReviewInbox from './ReviewInbox.vue';
import { installCsrfFetch, setCsrfToken } from '../lib/api.js';
import { currentRoute as routeFromLocation, withBase } from '../lib/base.js';
import { clearAccountDraftRecovery } from './page-edit/draftRecovery.js';
import { clearSectionTemplates } from '../lib/sectionTemplates.js';

const currentRoute = ref('/admin');
const historyIndex = ref(0);
let restoringPopState = false;
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

const brandLabel = computed(() => branding.value.name || currentUser.value?.displayName || currentUser.value?.username || message('Workspace'));
// Capability rather than role: the rules live on the server, and the UI asks
// what this account may do rather than re-deriving it from a role name.
const can = (capability) => (currentUser.value?.capabilities ?? []).includes(capability);

const hasBuilder = computed(
  () => freeformEditing.value
    && installedPluginIds.value.includes('visual-builder')
    && can('edit.freeform')
);

// Same shape as the builder: the screen is offered only when the plugin that
// serves it is installed, so a site that deleted `plugins/webhooks` does not
// show a menu item whose every request answers 404.
const hasWebhooks = computed(
  () => installedPluginIds.value.includes('webhooks') && can('settings.manage')
);

// Same shape as webhooks: the inbox is offered only when the plugin that
// serves it is installed, and only to accounts the review API will actually
// answer — `content.edit.any`, the bar `GET /api/collaboration/review` holds.
const hasReviews = computed(
  () => installedPluginIds.value.includes('collaboration') && can('content.edit.any')
);

// Release publishes a chosen set together; the API needs `content.publish`, so
// the nav follows that bar rather than the broader review-read capability.
const hasRelease = computed(
  () => installedPluginIds.value.includes('collaboration') && can('content.publish')
);

/** Site allows free-form; default on until settings load (matches server default). */
const freeformEditing = ref(true);

const checkAuth = async () => {
  try {
    const res = await fetch('/api/auth/check');
    const data = await res.json();
    // Kept current on every check, so a re-login or a new session is picked up
    // without any component having to think about it.
    setCsrfToken(data.data?.csrfToken ?? null);
    isLoggedIn.value = data.data?.authenticated || false;
    currentUser.value = data.data?.user || null;
    if (isLoggedIn.value) {
      await Promise.all([loadInstalledPlugins(), loadSite(), loadEditingFlags()]);
    }
  } catch (e) { isLoggedIn.value = false; }
};

/**
 * The site this session is editing.
 *
 * `multiSite` false by default, so a single-site installation — which is most of
 * them — never draws the chip, and a failed request leaves it hidden rather than
 * showing an empty badge.
 */
const site = ref({ id: '', title: '', multiSite: false });

const loadSite = async () => {
  try {
    const res = await fetch('/api/site');
    const body = await res.json();

    if (res.ok && body.data && typeof body.data === 'object') {
      site.value = {
        id: String(body.data.id ?? ''),
        title: String(body.data.title || body.data.id || ''),
        multiSite: body.data.multiSite === true,
      };
    }
  } catch {
    // Cosmetic. A site that cannot be named is not a reason to interrupt
    // anybody's work.
  }
};

const loadEditingFlags = async () => {
  try {
    const res = await fetch('/api/settings');
    const body = await res.json();
    if (res.ok) {
      freeformEditing.value = body.data?.freeformEditing !== false;
    }
  } catch {
    freeformEditing.value = true;
  }
};

const handleSettingsUpdated = (data) => {
  if (data && typeof data === 'object' && 'freeformEditing' in data) {
    freeformEditing.value = data.freeformEditing !== false;
  }
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

const handleLogout = async () => {
  if (!approveNavigation()) return;
  const response = await fetch('/api/auth/logout', { method: 'POST' });
  if (response.ok) {
    clearAccountDraftRecovery(window.localStorage, currentUser.value?.username);
    if (site.value.id) clearSectionTemplates({
      account: currentUser.value?.username ?? '',
      site: `${window.location.origin}/${site.value.id}`,
    });
  }
  currentUser.value = null;
  isLoggedIn.value = false;
};
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
  if (path === '/admin/pages') return can('content.view') ? Pages : Dashboard;
  if (path === '/admin/collections' || path.startsWith('/admin/collections/')) return can('content.view') ? Collections : Dashboard;
  if (path === '/admin/media') return can('media.view') ? Media : Dashboard;
  if (path === '/admin/users') return can('users.manage') ? Users : Dashboard;
  if (path === '/admin/profile') return Profile;
  if (path === '/admin/password') return ChangePassword;
  if (path === '/admin/plugins') return can('plugins.manage') ? Plugins : Dashboard;
  if (path === '/admin/marketplace') return can('plugins.install') ? Marketplace : Dashboard;
  if (path === '/admin/settings') return can('settings.manage') ? Settings : Dashboard;
  if (path === '/admin/security') return can('settings.manage') ? SecurityHealth : Dashboard;
  if (path === '/admin/reviews') return can('content.edit.any') ? (hasReviews.value ? ReviewsInbox : ReviewInbox) : Dashboard;
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
  if (path === '/admin/webhooks') return hasWebhooks.value ? Webhooks : Dashboard;
  if (path === '/admin/release') return hasRelease.value ? Release : Dashboard;
  if (path.startsWith('/admin/pages/edit/')) return can('content.edit.any') || can('content.edit.own') ? PageEdit : Dashboard;
  if (path === '/admin/pages/new') return can('content.create') ? PageEdit : Dashboard;
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
    return { slug: path.replace('/admin/pages/edit/', ''), initialLocale: locale, capabilities: currentUser.value?.capabilities ?? [], username: currentUser.value?.username ?? '' };
  }
  if (path === '/admin/pages/new') {
    const locale = new URLSearchParams((currentRoute.value.split('?')[1]) || '').get('locale') || '';
    return { initialLocale: locale, capabilities: currentUser.value?.capabilities ?? [], username: currentUser.value?.username ?? '' };
  }
  if (path === '/admin/collections' || path.startsWith('/admin/collections/')) {
    // /admin/collections
    // /admin/collections/{type}
    // /admin/collections/{type}/entries/new
    // /admin/collections/{type}/entries/{slug}
    const locale = new URLSearchParams(query || '').get('locale') || '';
    const parts = path.replace(/^\/admin\/collections\/?/, '').split('/').filter(Boolean);
    const typeId = parts[0] ? decodeURIComponent(parts[0]) : '';
    if (!typeId) return { initialTypeId: '', initialSlug: null, initialCreating: false, initialLocale: locale };
    if (parts[1] === 'entries' && parts[2] === 'new') {
      return { initialTypeId: typeId, initialSlug: null, initialCreating: true, initialLocale: locale };
    }
    if (parts[1] === 'entries' && parts[2]) {
      return {
        initialTypeId: typeId,
        initialSlug: decodeURIComponent(parts[2]),
        initialCreating: false,
        initialLocale: locale,
      };
    }
    return { initialTypeId: typeId, initialSlug: null, initialCreating: false, initialLocale: locale };
  }
  if (path.startsWith('/admin/plugins/') && path !== '/admin/plugins') return { id: path.replace('/admin/plugins/', '') };
  if (path === '/admin/users') return { userRole: currentUser.value?.role, currentUsername: currentUser.value?.username };
  if (path === '/admin/reviews') return { currentUsername: currentUser.value?.username ?? '', canReview: can('content.publish') };
  if (path === '/admin/plugins') return { userRole: currentUser.value?.role };
  if (path === '/admin/profile' && currentUser.value) return { user: currentUser.value };
  if (path === '/admin/pages') return {
    capabilities: currentUser.value?.capabilities ?? [],
    username: currentUser.value?.username ?? '',
    siteId: site.value.id,
  };
  if (path === '/admin' || path === '/admin/') return { capabilities: currentUser.value?.capabilities ?? [] };
  return {};
};

const currentComponent = computed(() => getRouteComponent());
const currentProps = computed(() => getRouteProps());

const screenTitles = new Map([
  [Dashboard, 'Dashboard'], [Pages, 'Pages'], [Collections, 'Collections'],
  [Media, 'Media'], [Users, 'Users'], [Profile, 'Profile'],
  [Settings, 'Settings'], [Redirects, 'Redirects'], [Menus, 'Menus'],
  [SecurityHealth, 'Security health'],
  [ReviewInbox, 'Reviews'], [ReviewsInbox, 'Reviews'], [Release, 'Release'],
  [FormSubmissions, 'Form submissions'], [Webhooks, 'Webhooks'],
  [Plugins, 'Plugins'], [PluginDetail, 'Plugin details'],
  [Marketplace, 'Marketplace'], [Themes, 'Themes'], [Updates, 'Updates'],
  [Builder, 'Visual Builder'], [ChangePassword, 'Change password'],
]);

watchEffect(() => {
  let title;
  if (!isLoggedIn.value) title = 'Sign in';
  else if (mustChangePassword.value) title = 'Choose a password';
  else if (currentComponent.value === PageEdit) {
    title = currentProps.value.slug ? 'Edit Page' : 'New Page';
  } else title = screenTitles.get(currentComponent.value) || 'Dashboard';
  document.title = `${message(title)} - Click CMS Admin`;
});

// A newly saved page becomes bookmarkable without adding a stale creation
// screen to Back history or remounting the editor and losing its state.
const handleReplaceRoute = (path) => {
  const url = withBase(path);
  currentRoute.value = path;
  window.history.replaceState({ ...(window.history.state || {}), __clickAdminIndex: historyIndex.value }, '', url);
};
const onPopState = () => {
  const targetIndex = Number(window.history.state?.__clickAdminIndex);
  if (restoringPopState) {
    restoringPopState = false;
    currentRoute.value = routeFromLocation();
    if (Number.isFinite(targetIndex)) historyIndex.value = targetIndex;
    return;
  }
  if (!approveNavigation()) {
    // popstate cannot be cancelled. Return to the app's prior history entry;
    // the one-shot flag lets this restoration pass without a second prompt.
    if (Number.isFinite(targetIndex) && targetIndex !== historyIndex.value) {
      restoringPopState = true;
      window.history.go(historyIndex.value - targetIndex);
    } else {
      // An older entry may have no app index. Keep the URL aligned with the
      // editor rather than accepting a route change whose component stayed put.
      window.history.replaceState(
        { ...(window.history.state || {}), __clickAdminIndex: historyIndex.value },
        '',
        withBase(currentRoute.value)
      );
    }
    return;
  }
  currentRoute.value = routeFromLocation();
  if (Number.isFinite(targetIndex)) historyIndex.value = targetIndex;
};

const approveNavigation = () => {
  const event = new Event('click:before-navigate', { cancelable: true });
  window.dispatchEvent(event);
  return !event.defaultPrevented;
};

const commitNavigate = (path) => {
  // The route stays as the app spells it — `/admin/pages` — and only the address
  // bar gets the installation's prefix. That is what keeps every route
  // comparison below free of the question of where the CMS is installed.
  currentRoute.value = path;
  historyIndex.value += 1;
  window.history.pushState({ __clickAdminIndex: historyIndex.value }, '', withBase(path));
  // Following a link closes the mobile drawer, so the destination is not left
  // hidden behind the menu the reader just used.
  mobileNavOpen.value = false;
};

const handleNavigate = (path) => {
  if (approveNavigation()) commitNavigate(path);
};

// Editors already confirm their explicit Cancel action. This path avoids
// asking twice after the editor has approved that discard.
const handleCancel = () => commitNavigate('/admin/pages');

onMounted(async () => {
  installCsrfFetch();
  currentRoute.value = routeFromLocation();
  if (Number.isFinite(window.history.state?.__clickAdminIndex)) {
    historyIndex.value = window.history.state.__clickAdminIndex;
  } else {
    history.replaceState({ ...(window.history.state || {}), __clickAdminIndex: 0 }, '', window.location.href);
  }
  await checkAuth();
  window.addEventListener('popstate', onPopState);
  window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown);
  window.removeEventListener('popstate', onPopState);
});
</script>

<style scoped>
.message-language { display: flex; justify-content: flex-end; align-items: center; gap: .5rem; padding: .35rem 1rem; background: var(--app-surface); color: var(--app-text); font-size: .8rem; }
.message-language select { font: inherit; color: inherit; background: var(--app-surface); border: 1px solid var(--control-border); border-radius: 4px; padding: .2rem; }
.message-language select:focus-visible { outline: 2px solid var(--focus-ring); }

.site-chip {
  margin-left: 0.75rem;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  background: color-mix(in srgb, var(--color-primary-500) 16%, transparent);
  color: var(--app-text);
  border: 1px solid var(--app-border);
  white-space: nowrap;
}

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
