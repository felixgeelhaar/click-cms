<template>
  <div class="media">
    <h1 class="page-title">Media</h1>
    <p class="page-subtitle">Manage your media files</p>
    <div class="upload-area">
      <p>Drag and drop files here or click to upload</p>
    </div>
  </div>
</template>

<style scoped>
.media { max-width: 1200px; }
.page-title { font-size: 1.875rem; font-weight: 700; color: var(--app-text); margin-bottom: 0.5rem; }
.page-subtitle { color: var(--app-text-muted); margin-bottom: 2rem; }
.upload-area { padding: 4rem; border: 2px dashed var(--app-border); border-radius: var(--card-radius); text-align: center; color: var(--app-text-muted); }
</style>
