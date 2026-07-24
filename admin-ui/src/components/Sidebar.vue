<template>
  <nav class="sidebar" :class="{ collapsed }" aria-label="Admin navigation">
    <div v-for="group in navGroups" :key="group.id" class="nav-group">
      <!-- A collapsible group's heading is a real button so it can be reached by
           keyboard and announces its expanded state. When the rail is collapsed
           to icons the heading is hidden and the items always show, so nothing
           becomes unreachable. -->
      <button
        v-if="group.label && !collapsed && group.collapsible"
        type="button"
        class="nav-group-header"
        :aria-expanded="isOpen(group.id)"
        @click="toggleGroup(group.id)"
      >
        <span class="nav-group-label">{{ group.label }}</span>
        <svg class="caret" :class="{ open: isOpen(group.id) }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M6 9l6 6 6-6" />
        </svg>
      </button>

      <ul v-show="collapsed || !group.collapsible || isOpen(group.id)" class="nav-items">
        <!-- A real `href` is what makes these links: without one an `<a>` is not
             focusable, cannot be reached by keyboard, and is not announced as a
             link. `@click.prevent` keeps navigation client-side for ordinary
             clicks; the href still carries the destination, so the browser can
             show it on hover and open it in a new tab. -->
        <li v-for="item in group.items" :key="item.href">
          <a
            :href="item.href"
            :class="['nav-item', { active: isActive(item.href) }]"
            :aria-current="isActive(item.href) ? 'page' : undefined"
            :title="collapsed ? item.label : undefined"
            @click="navigate($event, item.href)"
          >
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path v-for="(d, i) in iconPaths[item.icon]" :key="i" :d="d" />
            </svg>
            <span class="label">{{ item.label }}</span>
          </a>
        </li>
      </ul>
    </div>
  </nav>
</template>

<script setup>
import { computed, ref } from 'vue';
const props = defineProps({ activeRoute: String, userRole: String, collapsed: Boolean, showBuilder: Boolean });
const emit = defineEmits(['navigate']);

const iconPaths = {
  dashboard: ['M3 3h8v8H3z', 'M13 3h8v5h-8z', 'M13 10h8v11h-8z', 'M3 13h8v8H3z'],
  pages: ['M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z', 'M14 3v5h5'],
  collections: ['M4 4h7v7H4z', 'M13 4h7v7h-7z', 'M13 13h7v7h-7z', 'M4 13h7v7H4z'],
  media: ['M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
  builder: ['M3 4h8v8H3z', 'M13 4h8v4h-8z', 'M13 10h8v10h-8z', 'M3 14h8v6H3z'],
  plugins: ['M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z'],
  marketplace: ['M12 2L2 7l10 5 10-5-10-5z', 'M2 17l10 5 10-5', 'M2 12l10 5 10-5'],
  users: ['M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0z', 'M4 20a8 8 0 0 1 16 0'],
  profile: ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'],
  submissions: ['M22 12h-6l-2 3h-4l-2-3H2', 'M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z'],
  menus: ['M4 6h16', 'M4 12h16', 'M4 18h10'],
  redirects: ['M9 17H7A5 5 0 0 1 7 7h2', 'M15 7h2a5 5 0 0 1 0 10h-2', 'M8 12h8'],
  // A paint roller: a theme is the site's surface, not its structure.
  themes: ['M19 3H5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z', 'M12 10v4', 'M9 14h6v7H9z'],
  // A download arrow into a tray: new code arriving.
  updates: ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'M7 10l5 5 5-5', 'M12 15V3'],
  settings: ['M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', 'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z'],
};

/**
 * The navigation, grouped into sections rather than one flat list, so an editor
 * scans "where do I manage content" instead of reading twelve equal rows. A
 * section a role cannot use (the admin-only Manage group) is not built at all,
 * so it is never rendered rather than rendered-and-hidden.
 */
const navGroups = computed(() => {
  const groups = [
    { id: 'overview', label: '', collapsible: false, items: [
      { href: '/admin', icon: 'dashboard', label: 'Dashboard' },
    ] },
  ];

  const content = [
    { href: '/admin/pages', icon: 'pages', label: 'Pages' },
    { href: '/admin/collections', icon: 'collections', label: 'Collections' },
    { href: '/admin/media', icon: 'media', label: 'Media' },
  ];
  if (props.showBuilder) content.push({ href: '/admin/builder', icon: 'builder', label: 'Builder' });
  content.push({ href: '/admin/submissions', icon: 'submissions', label: 'Submissions' });
  groups.push({ id: 'content', label: 'Content', collapsible: true, items: content });

  if (props.userRole === 'admin') {
    groups.push({ id: 'manage', label: 'Manage', collapsible: true, items: [
      { href: '/admin/plugins', icon: 'plugins', label: 'Plugins' },
      { href: '/admin/marketplace', icon: 'marketplace', label: 'Marketplace' },
      { href: '/admin/users', icon: 'users', label: 'Users' },
      { href: '/admin/redirects', icon: 'redirects', label: 'Redirects' },
      { href: '/admin/menus', icon: 'menus', label: 'Menus' },
      { href: '/admin/themes', icon: 'themes', label: 'Themes' },
      { href: '/admin/settings', icon: 'settings', label: 'Settings' },
      { href: '/admin/updates', icon: 'updates', label: 'Updates' },
    ] });
  }

  groups.push({ id: 'account', label: 'Account', collapsible: true, items: [
    { href: '/admin/profile', icon: 'profile', label: 'Profile' },
  ] });

  return groups;
});

// Groups are open until explicitly closed, so a returning editor sees every
// section rather than having to remember which they collapsed.
const closedGroups = ref({});
const isOpen = (id) => closedGroups.value[id] !== true;
const toggleGroup = (id) => { closedGroups.value = { ...closedGroups.value, [id]: isOpen(id) }; };

/**
 * Route in the client for an ordinary click, and stay out of the way otherwise.
 *
 * Cmd/Ctrl/Shift-click and middle-click mean "open this somewhere else". Calling
 * preventDefault on those would break an expectation every link on the web sets,
 * so they are left to the browser and the href handles them.
 */
const navigate = (event, href) => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  emit('navigate', href);
};

const isActive = (href) => {
  if (href === '/admin') return props.activeRoute === '/admin' || props.activeRoute === '/admin/';
  return props.activeRoute?.startsWith(href);
};
</script>

<style scoped>
.sidebar { height: 100%; padding: 1rem 0.75rem; overflow-y: auto; }
.nav-group { margin-bottom: 0.5rem; }
.nav-group-header {
  display: flex; align-items: center; justify-content: space-between; width: 100%;
  padding: 0.35rem 0.75rem; margin: 0.35rem 0 0.1rem; border: 0; background: none;
  cursor: pointer; color: var(--app-text-muted);
  font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
}
.nav-group-label { pointer-events: none; }
.caret { width: 14px; height: 14px; transition: transform 0.15s; transform: rotate(-90deg); }
.caret.open { transform: rotate(0deg); }
.nav-items { list-style: none; display: flex; flex-direction: column; gap: 0.15rem; margin: 0; padding: 0; }
.nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.75rem; border-radius: 8px; color: var(--app-text-muted); text-decoration: none; cursor: pointer; transition: background 0.15s, color 0.15s; }
.nav-item:hover { background: var(--sidebar-hover); color: var(--app-text); }
.nav-item:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: -2px; }
.nav-group-header:focus-visible { outline: 2px solid var(--color-primary-600); outline-offset: -2px; border-radius: 6px; }
/* The current page: filled pill plus an accent rail, so place reads instantly. */
.nav-item.active { background: var(--sidebar-active); color: var(--sidebar-active-text); font-weight: 600; position: relative; }
.nav-item.active::before {
  content: ""; position: absolute; left: -0.75rem; top: 0.35rem; bottom: 0.35rem;
  width: 3px; border-radius: 0 3px 3px 0; background: var(--color-primary-600);
}
.icon { width: 20px; height: 20px; flex-shrink: 0; }
.label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.collapsed .label, .collapsed .nav-group-header { display: none; }
.collapsed .nav-item { justify-content: center; padding: 0.6rem; }
.collapsed .nav-item.active::before { left: 0; }
</style>
