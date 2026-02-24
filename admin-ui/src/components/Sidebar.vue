<template>
  <nav class="sidebar" :class="{ collapsed }">
    <div class="nav-items">
      <a v-for="item in navItems" :key="item.href" :class="['nav-item', { active: isActive(item.href) }]" @click.prevent="emit('navigate', item.href)">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
    items.push({ href: '/admin/analytics', icon: 'analytics', label: 'Analytics' });
  }
  items.push({ href: '/admin/profile', icon: 'profile', label: 'Profile' });
  return items;
});

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
