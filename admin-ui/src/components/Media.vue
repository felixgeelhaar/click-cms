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

    <div
      class="dropzone"
      :class="{ active: dragging }"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      Drag images here, or use the Upload button.
    </div>

    <p v-if="loading" class="banner">Loading…</p>

    <p v-else-if="items.length === 0" class="banner">
      Nothing here yet. Uploaded images appear in this library and can be picked
      from any image field.
    </p>

    <ul v-else class="grid">
      <li v-for="item in items" :key="item.id" class="card">
        <div class="thumb">
          <img
            :src="thumbFor(item)"
            :srcset="item.srcset || undefined"
            sizes="240px"
            :alt="item.alt || item.originalName"
            loading="lazy"
          />
        </div>

        <div class="card-body">
          <p class="card-name" :title="item.originalName">{{ item.originalName }}</p>
          <p class="card-meta">{{ item.width }}×{{ item.height }} · {{ formatBytes(item.bytes) }}</p>
          <p class="card-variants">
            <span v-if="item.variants.length">{{ item.variants.join(', ') }}</span>
            <span v-else class="muted">no resized versions</span>
          </p>

          <label class="alt-label" :for="`alt-${item.id}`">Description</label>
          <input
            :id="`alt-${item.id}`"
            :value="item.alt"
            class="alt-input"
            placeholder="Describe the image"
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
const loading = ref(true);
const uploading = ref(false);
const dragging = ref(false);
const errors = ref([]);
const copiedId = ref('');
const fileInput = ref(null);
const capabilities = ref({ acceptedMimeTypes: [], maxBytes: 0, resizingAvailable: true, variants: [] });

const acceptAttr = computed(() => capabilities.value.acceptedMimeTypes.join(',') || 'image/*');

const subtitle = computed(() => {
  const count = items.value.length;
  const noun = count === 1 ? 'image' : 'images';
  const sizes = capabilities.value.variants.map((v) => v.name).join(', ');
  return sizes ? `${count} ${noun}. Each upload is resized to: ${sizes}.` : `${count} ${noun}.`;
});

const formatBytes = (bytes) => {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
};

// Prefer the smallest variant for a thumbnail so a grid never pulls full-size
// originals over the wire.
const thumbFor = (item) => item.urls?.variants?.sm?.url ?? item.urls?.original;

const load = async () => {
  loading.value = true;
  try {
    const [mediaRes, capsRes] = await Promise.all([
      fetch('/api/media'),
      fetch('/api/media/capabilities'),
    ]);
    items.value = (await mediaRes.json()).data ?? [];
    capabilities.value = { ...capabilities.value, ...((await capsRes.json()).data ?? {}) };
  } catch (e) {
    errors.value = [`Could not load the media library: ${e.message}`];
  } finally {
    loading.value = false;
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

const remove = async (item) => {
  const res = await fetch(`/api/media/${item.id}`, { method: 'DELETE' });
  if (res.ok) items.value = items.value.filter((i) => i.id !== item.id);
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
.dropzone { border: 2px dashed var(--app-border); border-radius: 10px; padding: 1.5rem; text-align: center; color: var(--app-text-muted); font-size: 0.875rem; margin-bottom: 1.5rem; }
.dropzone.active { border-color: var(--color-primary-600); color: var(--app-text); }
.grid { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
.card { border: 1px solid var(--app-border); border-radius: 10px; overflow: hidden; background: var(--card-bg); }
.thumb { aspect-ratio: 4 / 3; background: var(--app-surface-strong); }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.card-body { padding: 0.75rem; }
.card-name { margin: 0; font-size: 0.875rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.card-meta, .card-variants { margin: 0.2rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.muted { opacity: 0.7; }
.alt-label { display: block; margin: 0.6rem 0 0.25rem; font-size: 0.75rem; font-weight: 500; }
.alt-input { width: 100%; padding: 0.4rem 0.5rem; border: 1px solid var(--app-border); border-radius: 6px; background: var(--app-surface); color: var(--app-text); font: inherit; font-size: 0.8125rem; }
.card-actions { display: flex; gap: 0.5rem; margin-top: 0.6rem; }
.btn-sm { flex: 1; padding: 0.35rem 0.5rem; font-size: 0.75rem; border: 1px solid var(--app-border); background: var(--app-surface); border-radius: 6px; cursor: pointer; color: var(--app-text); }
.btn-sm.danger { color: var(--color-danger-600, #dc2626); }
.btn-primary { padding: 0.625rem 1.25rem; border-radius: 8px; font-weight: 500; cursor: pointer; background: var(--color-primary-600); color: white; border: none; }
</style>
