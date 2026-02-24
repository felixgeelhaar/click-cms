# Click CMS Admin UI

A modern, best-in-class admin interface built with **Astro + Vue 3 + D3.js**.

## Features

- 🚀 **Astro Framework** - Fast, modern static site generation
- ⚡ **Vue 3** - Reactive components with Composition API
- 📊 **D3.js** - Beautiful data visualizations and charts
- 🎨 **Modern Design** - Clean, professional UI with smooth animations
- 📱 **Responsive** - Works seamlessly on desktop, tablet, and mobile
- 🔌 **Real-time Stats** - Live connection status and data updates

## Architecture

```
admin-ui/
├── src/
│   ├── components/     # Vue components
│   │   ├── AdminApp.vue       # Main app layout
│   │   ├── Dashboard.vue      # Dashboard with D3 charts
│   │   ├── Sidebar.vue        # Navigation sidebar
│   │   └── StatCard.vue       # Statistics cards
│   ├── layouts/        # Astro layouts
│   │   └── AdminLayout.astro
│   ├── pages/          # Astro pages
│   │   └── index.astro
│   └── env.d.ts        # TypeScript definitions
├── package.json
├── astro.config.mjs
└── dist/               # Built files (generated)
```

## Setup

### 1. Install Dependencies

```bash
cd admin-ui
npm install
```

### 2. Build for Production

```bash
npm run build
```

This creates the `dist/` folder with static files that the PHP backend serves.

### 3. Development Mode

```bash
npm run dev
```

For development with hot reload. The dev server proxies `/api` to your backend.

Set the backend URL if needed:

```bash
CLICK_CMS_API_URL=http://localhost:8080 npm run dev
```

## Components

### Dashboard

The main dashboard features:
- **Stat Cards** - Overview of pages, published content, drafts, and plugins
- **Content Chart** - D3.js bar chart showing content creation over time
- **Plugin Donut Chart** - Visual representation of active vs inactive plugins
- **Quick Actions** - Fast access to common tasks

### Sidebar

Navigation sidebar with:
- Active route highlighting
- Connection status indicator
- Smooth hover animations

### Data Visualization (D3.js)

Two D3.js visualizations:

1. **Content Overview Bar Chart**
   - Shows published vs draft pages over time
   - Animated transitions
   - Interactive tooltips (ready for extension)

2. **Plugin Status Donut Chart**
   - Visual breakdown of plugin states
   - Center text showing total count
   - Animated entry

## Design System

### Colors

```css
--color-primary-500: #3b82f6;    /* Primary blue */
--color-primary-600: #2563eb;    /* Primary hover */
--color-success-500: #22c55e;    /* Success green */
--color-warning-500: #f59e0b;    /* Warning yellow */
--color-danger-500: #ef4444;     /* Danger red */
--color-gray-50-900: /* Gray scale */
```

### Typography

- **Font**: Inter (Google Fonts)
- **Base Size**: 14px
- **Headings**: 1.875rem - 1.125rem
- **Line Height**: 1.5

### Spacing

- **Cards**: 1.5rem padding
- **Grid Gap**: 1.5rem
- **Border Radius**: 12px (cards), 8px (buttons)

## API Integration

The admin UI connects to the Click CMS backend via:

- `GET /api/info` - System info and plugin list
- `GET /api/pages` - List all pages
- `POST /api/graphql` - GraphQL queries

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers

## Performance

- Static HTML generation via Astro
- Vue components hydrate on client
- D3 charts use SVG for crisp rendering
- Minimal JavaScript bundle size

## Future Enhancements

- [ ] Dark mode toggle
- [ ] Real-time WebSocket updates
- [ ] More D3 visualizations (line charts, area charts)
- [ ] Drag-and-drop page ordering
- [ ] Media library with image previews
- [ ] Plugin configuration UI
- [ ] User management interface
