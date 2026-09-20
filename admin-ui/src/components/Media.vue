<template>
  <div class="media">
    <div class="page-header">
      <div>
        <h1 class="page-title">Media</h1>
        <p class="page-subtitle">{{ subtitle }}</p>
      </div>
      <label class="btn-primary upload-button">
        {{ uploading ? 'Uploading…' : '+ Upload' }}
        <input
          ref="fileInput"
          type="file"
          :accept="acceptAttr"
          multiple
          :disabled="uploading"
          @change="onFilesChosen"
        />
      </label>
    </div>

    <p v-if="!capabilities.resizingAvailable && !loading" class="banner warning">
      Image resizing is unavailable on this server, so uploads are stored at their
      original size only. Install the PHP <code>gd</code> extension to generate
      responsive versions.
    </p>

    <ul v-if="errors.length" class="banner error" role="alert">
      <li v-for="(message, i) in errors" :key="i">{{ message }}</li>
    </ul>

    <!-- Search and folder grouping: a library of hundreds is unusable without a
         way to narrow it. Both are applied on the server (GET /api/media?q=&folder=)
         so a large library is filtered before it is sent, not after. -->
    <div class="toolbar">
      <input
        v-model="search"
        type="search"
        class="search-input"
        data-test="media-search"
        placeholder="Search by filename…"
        aria-label="Search media by filename"
        @input="onSearchInput"
      />
      <select
        v-model="folder"
        class="folder-select"
        data-test="media-folder"
        aria-label="Filter media by folder"
        @change="load"
      >
        <option value="">All folders</option>
        <option v-for="name in folders" :key="name || '__root'" :value="name || ROOT">
          {{ name || 'Ungrouped' }}
        </option>
      </select>
    </div>

    <!-- The bulk bar only appears once something is selected, so the common case
         (browsing, single actions) is uncluttered. -->
    <div v-if="selectedIds.length" class="bulk-bar" data-test="bulk-bar">
      <span class="bulk-count">{{ selectedIds.length }} selected</span>
      <button type="button" class="btn-sm" data-test="bulk-clear" @click="clearSelection">
        Clear
      </button>
      <button
        type="button"
        class="btn-sm danger"
        data-test="bulk-delete"
        :disabled="deleting"
        @click="bulkDelete"
      >
        {{ deleting ? 'Deleting…' : `Delete ${selectedIds.length}` }}
      </button>
    </div>

    <div
      class="dropzone"
      :class="{ active: dragging }"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      Drag pictures or video here, or use the Upload button.
    </div>

    <p v-if="loading" class="banner">Loading…</p>

    <p v-else-if="items.length === 0 && filtering" class="banner" data-test="no-matches">
      Nothing matches your search. Clear the filters to see the whole library.
    </p>

    <p v-else-if="items.length === 0" class="banner">
      Nothing here yet. Whatever you upload appears in this library, and can then
      be picked from any picture or video field without copying a reference.
    </p>

    <ul v-else class="grid">
      <li
        v-for="item in items"
        :key="item.id"
        class="card"
        :class="{ selected: isSelected(item) }"
      >
        <!-- Multi-select for bulk actions. A plain checkbox rather than a
             click-to-select on the thumbnail, because the thumbnail is already
             the focal-point editor and must keep that job. -->
        <!-- The wrapping label has no text, so `title` was the only thing
             naming this box — and a title is a tooltip, announced by some
             screen readers and no braille display. The name goes on the control
             itself, where the accessible name calculation looks first. -->
        <label class="select-box" :title="`Select ${item.originalName}`">
          <input
            type="checkbox"
            data-test="select-item"
            :aria-label="`Select ${item.originalName}`"
            :checked="isSelected(item)"
            @change="toggleSelected(item)"
          />
        </label>

        <!-- A video has no thumbnail to show and no crop to set: the CMS stores
             it as uploaded and never transcodes it. Rendering it through the
             image card below put a broken <img> in the grid and offered a focal
             point that could not do anything. It gets a plain player instead,
             which is also the only way to check that the right file was
             uploaded. -->
        <video
          v-if="isVideo(item)"
          class="thumb"
          data-test="video-thumb"
          :src="item.urls?.original"
          controls
          preload="none"
          playsinline
        ></video>

        <!-- The ladder keeps the source aspect ratio, so a layout that crops an
             image can lose the subject. Marking the point that must stay visible
             fixes it: a click places it, the arrow keys nudge it without a mouse,
             and the thumbnail's object-position previews the crop. -->
        <button
          v-else
          type="button"
          class="thumb focal-target"
          data-test="focal-target"
          :aria-label="focalLabel(item)"
          @click="setFocalFromClick(item, $event)"
          @keydown="nudgeFocal(item, $event)"
        >
          <img
            :src="thumbFor(item)"
            :srcset="item.srcset || undefined"
            sizes="240px"
            :alt="item.alt || item.originalName"
            :style="{ objectPosition: objectPositionFor(item) }"
            loading="lazy"
          />
          <span
            class="focal-marker"
            data-test="focal-marker"
            :style="markerStyle(item)"
            aria-hidden="true"
          ></span>
        </button>

        <div class="card-body">
          <p class="card-name" :title="item.originalName">{{ item.originalName }}</p>
          <!-- An SVG has no raster dimensions — it scales to any size — so the
               pixel readout would be an empty "×". Say what it is instead. -->
          <p class="card-meta">
            <span v-if="isVideo(item)">
              {{ (item.extension || '').toUpperCase() || 'Video' }} · {{ formatBytes(item.bytes) }}
            </span>
            <span v-else-if="isVector(item)">Scalable vector · {{ formatBytes(item.bytes) }}</span>
            <span v-else>{{ item.width }}×{{ item.height }} · {{ formatBytes(item.bytes) }}</span>
          </p>
          <p class="card-variants">
            <!-- "no resized versions" is true of every video and reads as a
                 fault. It is how video works here, so say that instead. -->
            <span v-if="isVideo(item)" class="muted">served as uploaded</span>
            <span v-else-if="isVector(item)" class="muted">scales to any size</span>
            <span v-else-if="item.variants.length">{{ item.variants.join(', ') }}</span>
            <span v-else class="muted">no resized versions</span>
          </p>

          <!-- Declared art-directed crops already exist as files; showing them
               here is how an editor checks the focal point actually kept the
               subject in each box, without leaving the library. -->
          <ul
            v-if="cropList(item).length"
            class="crop-previews"
            data-test="crop-previews"
            :aria-label="`Art-directed crops for ${item.originalName}`"
          >
            <li v-for="crop in cropList(item)" :key="crop.name" class="crop-preview">
              <img :src="crop.url" :alt="`${crop.name} crop`" loading="lazy" />
              <span class="crop-name">{{ crop.name }}</span>
              <span class="crop-size">{{ crop.width }}×{{ crop.height }}</span>
            </li>
          </ul>

          <!-- The ladder never upscales, so a small upload quietly produces
               fewer variants. Saying only "sm" told the uploader nothing; the
               server words the consequence and this shows it. -->
          <p
            v-if="item.quality?.warning"
            class="card-quality"
            :class="item.quality.level"
          >
            {{ item.quality.message }}
          </p>

          <label class="alt-label" :for="`alt-${item.id}`">Description</label>
          <input
            :id="`alt-${item.id}`"
            :value="item.alt"
            class="alt-input"
            :placeholder="isVideo(item) ? 'Describe this video' : 'Describe the image'"
            @change="saveAlt(item, $event.target.value)"
          />

          <div class="card-actions">
            <button type="button" class="btn-sm" @click="copyId(item)">
              {{ copiedId === item.id ? 'Copied' : 'Copy reference' }}
            </button>
            <button type="button" class="btn-sm danger" @click="remove(item)">Delete</button>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';

const items = ref([]);
const folders = ref([]);
const loading = ref(true);
const uploading = ref(false);
const deleting = ref(false);
const dragging = ref(false);
const errors = ref([]);
const copiedId = ref('');
const fileInput = ref(null);
const capabilities = ref({ acceptedMimeTypes: [], maxBytes: 0, resizingAvailable: true, variants: [] });

// The search text and the chosen folder drive the server query. A sentinel is
// used for the root ("ungrouped") folder because "" already means "no folder
// filter" — the select needs a value that is distinct from "all folders".
// A sentinel for "the root folder", which must be distinct from the empty
// string the <select> uses for "all folders". Written as an escape rather
// than a raw NUL byte: the byte is legal in a JS string and the code worked,
// but it made this file binary to grep, diff and every other text tool, so a
// search for anything in this file silently returned nothing. A NUL is still
// the right sentinel — a folder name is derived from a filename prefix and
// can never contain one.
const ROOT = '\u0000root';
const search = ref('');
const folder = ref('');
const selectedIds = ref([]);

// Whether any filter is active, so an empty result can say "nothing matched"
// rather than "nothing here yet".
const filtering = computed(() => search.value.trim() !== '' || folder.value !== '');

const acceptAttr = computed(() => capabilities.value.acceptedMimeTypes.join(',') || 'image/*');

const subtitle = computed(() => {
  const count = items.value.length;
  // "9 images" was wrong the moment one of them was a film, and the resizing
  // sentence is a promise the CMS does not make about video. Both are counted
  // and worded separately rather than lumped under one noun.
  const pictures = items.value.filter((i) => !isVideo(i)).length;
  const videos = count - pictures;

  const parts = [];
  if (pictures || !videos) parts.push(`${pictures} ${pictures === 1 ? 'picture' : 'pictures'}`);
  if (videos) parts.push(`${videos} ${videos === 1 ? 'video' : 'videos'}`);
  const counted = parts.join(' and ');

  const sizes = capabilities.value.variants.map((v) => v.name).join(', ');
  if (!sizes) return `${counted}.`;

  return videos
    ? `${counted}. Each picture is resized to: ${sizes}. Video is served as uploaded.`
    : `${counted}. Each upload is resized to: ${sizes}.`;
});

// An SVG is resolution-independent: no width, no variant ladder, no focal crop.
// The card reads its metadata differently because a pixel size would be blank.
const isVector = (item) => item.mimeType === 'image/svg+xml' || item.extension === 'svg';

// The library holds video as well as pictures, and almost everything this
// page says about an item — a thumbnail, pixel dimensions, a focal point, a
// list of resized versions — is meaningless for one. The server labels each
// item's kind; this reads that rather than guessing from the extension.
const isVideo = (item) => (item.mimeType || '').startsWith('video/');

const formatBytes = (bytes) => {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
};

// Prefer the smallest variant for a thumbnail so a grid never pulls full-size
// originals over the wire.
const thumbFor = (item) => item.urls?.variants?.sm?.url ?? item.urls?.original;

// Declared art-directed crops ride in urls.crops as { name → { url, width, height } }.
// Flatten for the template; empty when the site declared none or this item has none.
const cropList = (item) => {
  const crops = item?.urls?.crops;
  if (!crops || typeof crops !== 'object') return [];
  return Object.entries(crops).map(([name, meta]) => ({
    name,
    url: meta?.url ?? '',
    width: meta?.width ?? 0,
    height: meta?.height ?? 0,
  })).filter((c) => c.url !== '');
};

// Build /api/media with the active search and folder. The root sentinel is sent
// as an empty folder, which the server reads as "the ungrouped folder"; "all
// folders" omits the parameter entirely so nothing is filtered.
const mediaUrl = () => {
  const params = new URLSearchParams();
  if (search.value.trim() !== '') params.set('q', search.value.trim());
  if (folder.value !== '') params.set('folder', folder.value === ROOT ? '' : folder.value);
  const qs = params.toString();
  return qs ? `/api/media?${qs}` : '/api/media';
};

const load = async () => {
  loading.value = true;
  try {
    const [mediaRes, capsRes] = await Promise.all([
      fetch(mediaUrl()),
      fetch('/api/media/capabilities'),
    ]);
    const body = await mediaRes.json();
    items.value = body.data ?? [];
    // The server returns the full folder set regardless of the current filter,
    // so the chooser never loses folders when a filter narrows the view.
    if (Array.isArray(body.folders)) folders.value = body.folders;
    capabilities.value = { ...capabilities.value, ...((await capsRes.json()).data ?? {}) };
    // Drop any selection that the reload no longer shows, so a hidden item can
    // never be deleted by a stale tick.
    pruneSelection();
  } catch (e) {
    errors.value = [`Could not load the media library: ${e.message}`];
  } finally {
    loading.value = false;
  }
};

// Debounce the search so a query is not fired on every keystroke of a fast
// typist; the folder change reloads immediately since it is a single choice.
let searchTimer;
const onSearchInput = () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(load, 250);
};

const isSelected = (item) => selectedIds.value.includes(item.id);

const toggleSelected = (item) => {
  selectedIds.value = isSelected(item)
    ? selectedIds.value.filter((id) => id !== item.id)
    : [...selectedIds.value, item.id];
};

const clearSelection = () => { selectedIds.value = []; };

const pruneSelection = () => {
  const visible = new Set(items.value.map((i) => i.id));
  selectedIds.value = selectedIds.value.filter((id) => visible.has(id));
};

// Delete every selected item in one request. Confirmed first because it is the
// one irreversible batch action on the page, then the survivors are reloaded so
// the grid reflects exactly what the server did.
const bulkDelete = async () => {
  const ids = [...selectedIds.value];
  if (!ids.length) return;

  if (!window.confirm(`Delete ${ids.length} selected image${ids.length === 1 ? '' : 's'}? This cannot be undone.`)) {
    return;
  }

  deleting.value = true;
  errors.value = [];
  try {
    const res = await fetch('/api/media/bulk-delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids }),
    });
    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      errors.value = [body.error ?? `Bulk delete failed (${res.status}).`];
      return;
    }
    clearSelection();
    await load();
  } catch (e) {
    errors.value = [`Bulk delete failed: ${e.message}`];
  } finally {
    deleting.value = false;
  }
};

const uploadAll = async (files) => {
  if (!files.length) return;

  uploading.value = true;
  errors.value = [];

  for (const file of files) {
    const form = new FormData();
    form.append('file', file);

    try {
      const res = await fetch('/api/media', { method: 'POST', body: form });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) errors.value.push(`${file.name}: ${body.error ?? `upload failed (${res.status})`}`);
    } catch (e) {
      errors.value.push(`${file.name}: ${e.message}`);
    }
  }

  uploading.value = false;
  if (fileInput.value) fileInput.value.value = '';
  await load();
};

const onFilesChosen = (event) => uploadAll([...event.target.files]);

const onDrop = (event) => {
  dragging.value = false;
  uploadAll([...event.dataTransfer.files]);
};

const saveAlt = async (item, alt) => {
  await fetch(`/api/media/${item.id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ alt }),
  });
  item.alt = alt;
};

// Fractions of the image, so the mark holds for the original and every variant
// at once. The keyboard nudge step is deliberately coarse — the point of a
// focal point is "roughly here", not pixel placement.
const FOCAL_STEP = 0.05;
const clampFraction = (n) => Math.min(1, Math.max(0, n));
// Three decimals is finer than any crop needs and keeps the stored value tidy.
const roundFraction = (n) => Math.round(n * 1000) / 1000;

const focalOf = (item) => item.focalPoint ?? { x: 0.5, y: 0.5 };
const objectPositionFor = (item) => item.objectPosition ?? '50% 50%';

const markerStyle = (item) => {
  const { x, y } = focalOf(item);
  return { left: `${x * 100}%`, top: `${y * 100}%` };
};

const focalLabel = (item) => {
  const { x, y } = focalOf(item);
  return (
    `Focal point for ${item.originalName}: ${Math.round(x * 100)}% across, ` +
    `${Math.round(y * 100)}% down. Click the image or use the arrow keys to move ` +
    `the point that stays visible when the image is cropped.`
  );
};

// Metadata only: the stored files are never re-cropped. A front end honours the
// point with CSS object-position. Persisted the same way alt text is, so it
// rides along in the media item's stored record.
const applyFocal = async (item, x, y) => {
  const point = { x: roundFraction(clampFraction(x)), y: roundFraction(clampFraction(y)) };
  item.focalPoint = point;
  item.objectPosition = `${roundFraction(point.x * 100)}% ${roundFraction(point.y * 100)}%`;

  await fetch(`/api/media/${item.id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ focalPoint: point }),
  });
};

const setFocalFromClick = (item, event) => {
  const rect = event.currentTarget.getBoundingClientRect();
  // Without a laid-out box there is nothing to measure the click against.
  if (!rect.width || !rect.height) return;

  applyFocal(
    item,
    (event.clientX - rect.left) / rect.width,
    (event.clientY - rect.top) / rect.height,
  );
};

const nudgeFocal = (item, event) => {
  const { x, y } = focalOf(item);
  const moved = {
    ArrowLeft: [x - FOCAL_STEP, y],
    ArrowRight: [x + FOCAL_STEP, y],
    ArrowUp: [x, y - FOCAL_STEP],
    ArrowDown: [x, y + FOCAL_STEP],
  }[event.key];

  if (!moved) return;

  // Keep arrow keys on the marker instead of scrolling the library.
  event.preventDefault();
  applyFocal(item, moved[0], moved[1]);
};

const remove = async (item) => {
  const res = await fetch(`/api/media/${item.id}`, { method: 'DELETE' });
  if (res.ok) {
    items.value = items.value.filter((i) => i.id !== item.id);
    selectedIds.value = selectedIds.value.filter((id) => id !== item.id);
  }
};

// The reference is what goes into an image field, so make it easy to carry.
const copyId = async (item) => {
  try {
    await navigator.clipboard.writeText(item.id);
    copiedId.value = item.id;
    setTimeout(() => { copiedId.value = ''; }, 1500);
  } catch {
    errors.value = ['Could not copy to the clipboard.'];
  }
};

onMounted(load);
</script>

<style scoped>
.media { max-width: 1100px; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin: 0 0 0.35rem; }
.page-subtitle { color: var(--app-text-muted); margin: 0; font-size: 0.9375rem; }
.upload-button { position: relative; overflow: hidden; display: inline-flex; align-items: center; }
.upload-button input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.banner { padding: 0.75rem 1rem; border-radius: 8px; background: var(--app-surface-strong); font-size: 0.875rem; margin-bottom: 1rem; }
.banner.error { color: var(--color-danger-600, #dc2626); list-style: none; }
.banner.warning { border: 1px solid var(--app-border); }
.toolbar { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
.search-input { flex: 1 1 240px; min-width: 200px; padding: 0.5rem 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; font-size: 0.875rem; }
.folder-select { padding: 0.5rem 0.75rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; font-size: 0.875rem; }
.bulk-bar { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.9rem; margin-bottom: 1rem; border: 1px solid var(--color-primary-600); border-radius: 8px; background: var(--app-surface-strong); }
.bulk-count { font-size: 0.875rem; font-weight: 600; margin-right: auto; }
.bulk-bar .btn-sm { flex: 0 0 auto; }
.dropzone { border: 2px dashed var(--control-border); border-radius: 10px; padding: 1.5rem; text-align: center; color: var(--app-text-muted); font-size: 0.875rem; margin-bottom: 1.5rem; }
.dropzone.active { border-color: var(--color-primary-600); color: var(--app-text); }
.grid { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
.card { position: relative; border: 1px solid var(--app-border); border-radius: 10px; overflow: hidden; background: var(--card-bg); }
.card.selected { border-color: var(--color-primary-600); box-shadow: 0 0 0 1px var(--color-primary-600); }
/* Sits over the thumbnail's top-left corner. The thumbnail is a focal-point
   editor, so the checkbox is layered above it with its own hit area rather than
   competing for the same click. */
.select-box { position: absolute; top: 0.5rem; left: 0.5rem; z-index: 1; display: inline-flex; padding: 0.25rem; border-radius: 6px; background: rgba(0, 0, 0, 0.45); cursor: pointer; }
.select-box input { width: 18px; height: 18px; cursor: pointer; margin: 0; }
.thumb { aspect-ratio: 4 / 3; background: var(--app-surface-strong); }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
/* The thumbnail doubles as the focal-point editor: a real button so it is
   focusable and keyboard-operable, reset to look like the plain frame it was. */
.focal-target { position: relative; width: 100%; padding: 0; border: 0; margin: 0; cursor: crosshair; }
.focal-target:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
/* Every remaining control on this screen — the search box, the folder filter,
   the description field, the bulk and per-card buttons, the checkbox and the
   file input hidden behind the Upload label — needs a ring of its own; the
   focal-point button was the only one that had one. */
.search-input:focus-visible,
.folder-select:focus-visible,
.alt-input:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 1px; border-color: var(--focus-ring); }
.btn-sm:focus-visible,
.select-box input:focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.upload-button:focus-within { outline: 2px solid var(--focus-ring); outline-offset: 2px; }
.focal-marker {
  position: absolute;
  width: 16px;
  height: 16px;
  margin: -8px 0 0 -8px;
  border: 2px solid #fff;
  border-radius: 50%;
  box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.55);
  pointer-events: none;
}
.card-body { padding: 0.75rem; }
.card-name { margin: 0; font-size: 0.875rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.card-meta, .card-variants { margin: 0.2rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.card-quality { margin: 0.35rem 0 0; font-size: 0.75rem; line-height: 1.35; }
.card-quality.low { color: var(--color-danger-600, #dc2626); }
.card-quality.adequate { color: var(--app-text-muted); }
.crop-previews {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.crop-preview {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.1rem;
  min-width: 0;
  flex: 0 0 auto;
}
.crop-preview img {
  display: block;
  width: 64px;
  height: 48px;
  object-fit: cover;
  border-radius: 4px;
  background: var(--app-surface-strong);
  border: 1px solid var(--app-border);
}
.crop-name { font-size: 0.65rem; font-weight: 600; color: var(--app-text); max-width: 64px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.crop-size { font-size: 0.6rem; color: var(--app-text-muted); }
.muted { opacity: 0.7; }
.alt-label { display: block; margin: 0.6rem 0 0.25rem; font-size: 0.75rem; font-weight: 500; }
.alt-input { width: 100%; padding: 0.4rem 0.5rem; border: 1px solid var(--control-border); border-radius: 6px; background: var(--app-surface); color: var(--app-text); font: inherit; font-size: 0.8125rem; }
.card-actions { display: flex; gap: 0.5rem; margin-top: 0.6rem; }
.btn-sm { flex: 1; padding: 0.35rem 0.5rem; font-size: 0.75rem; border: 1px solid var(--control-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); }
.btn-sm.danger { color: var(--color-danger-600, #dc2626); }
.btn-primary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
</style>
