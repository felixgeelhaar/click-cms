<template>
  <nav class="sidebar" :class="{ collapsed }">
    <div class="nav-items">
      <!-- A real `href` is what makes these links: without one an `<a>` is not
           focusable, cannot be reached by keyboard, and is not announced as a
           link. `@click.prevent` keeps navigation client-side for ordinary
           clicks; the href still carries the destination, so the browser can
           show it on hover and open it in a new tab. -->
      <a
        v-for="item in navItems"
        :key="item.href"
        :href="item.href"
        :class="['nav-item', { active: isActive(item.href) }]"
        :aria-current="isActive(item.href) ? 'page' : undefined"
        @click="navigate($event, item.href)"
      >
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path v-for="(d, i) in iconPaths[item.icon]" :key="i" :d="d" />
        </svg>
        <span class="label">{{ item.label }}</span>
      </a>
    </div>
  </nav>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ activeRoute: String, userRole: String, collapsed: Boolean, showBuilder: Boolean });
const emit = defineEmits(['navigate']);

const iconPaths = {
  dashboard: ['M3 3h8v8H3z', 'M13 3h8v5h-8z', 'M13 10h8v11h-8z', 'M3 13h8v8H3z'],
  pages: ['M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z', 'M14 3v5h5'],
  media: ['M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
  builder: ['M3 4h8v8H3z', 'M13 4h8v4h-8z', 'M13 10h8v10h-8z', 'M3 14h8v6H3z'],
  plugins: ['M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z'],
  marketplace: ['M12 2L2 7l10 5 10-5-10-5z', 'M2 17l10 5 10-5', 'M2 12l10 5 10-5'],
  users: ['M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0z', 'M4 20a8 8 0 0 1 16 0'],
  profile: ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'],
  menus: ['M4 6h16', 'M4 12h16', 'M4 18h10'],
  redirects: ['M9 17H7A5 5 0 0 1 7 7h2', 'M15 7h2a5 5 0 0 1 0 10h-2', 'M8 12h8'],
  settings: ['M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', 'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z'],
  analytics: ['M18 20V10', 'M12 20V4', 'M6 20v-6']
};

const navItems = computed(() => {
  const items = [
    { href: '/admin', icon: 'dashboard', label: 'Dashboard' },
    { href: '/admin/pages', icon: 'pages', label: 'Pages' },
    { href: '/admin/media', icon: 'media', label: 'Media' },
  ];
  if (props.showBuilder) items.push({ href: '/admin/builder', icon: 'builder', label: 'Builder' });
  if (props.userRole === 'admin') {
    items.push({ href: '/admin/plugins', icon: 'plugins', label: 'Plugins' });
    items.push({ href: '/admin/marketplace', icon: 'marketplace', label: 'Marketplace' });
    items.push({ href: '/admin/users', icon: 'users', label: 'Users' });
    items.push({ href: '/admin/redirects', icon: 'redirects', label: 'Redirects' });
    items.push({ href: '/admin/menus', icon: 'menus', label: 'Menus' });
    items.push({ href: '/admin/settings', icon: 'settings', label: 'Settings' });
  }
  items.push({ href: '/admin/profile', icon: 'profile', label: 'Profile' });
  return items;
});

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
.sidebar { height: 100%; padding: 1rem; overflow-y: auto; }
.nav-items { display: flex; flex-direction: column; gap: 0.25rem; }
.nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: 8px; color: var(--app-text-muted); text-decoration: none; cursor: pointer; transition: all 0.15s; }
.nav-item:hover { background: var(--sidebar-hover); color: var(--app-text); }
.nav-item.active { background: var(--sidebar-active); color: var(--sidebar-active-text); }
.icon { width: 20px; height: 20px; flex-shrink: 0; }
.label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.collapsed .label { display: none; }
.collapsed .nav-item { justify-content: center; padding: 0.75rem; }
</style>
