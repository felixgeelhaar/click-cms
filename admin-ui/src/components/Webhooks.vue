<template>
  <div class="webhooks">
    <h1 class="page-title">Webhooks</h1>

    <p class="intro">
      Tell another system when something here changes — a static front end that
      needs to rebuild, a search index, a chat channel. Each webhook is a URL
      this site posts to, signed so the receiver can tell the delivery came from
      here.
    </p>

    <!--
      Said plainly and at the top, because without a way to make outbound
      requests nothing else on this screen works: endpoints could be created,
      deliveries would queue, and none of them would ever move.
    -->
    <p v-if="canSend === false" class="banner error" role="alert">
      This installation cannot make outbound HTTP requests: the curl extension
      is not loaded and <code>allow_url_fopen</code> is off. Webhooks can be
      configured here but nothing will be delivered until one of those is
      enabled.
    </p>

    <p v-if="error" class="banner error" role="alert">{{ error }}</p>
    <p v-if="notice" class="banner notice" role="status">{{ notice }}</p>

    <!--
      The secret is returned exactly once, when the endpoint is created. Shown
      here in a strip that has to be dismissed rather than in a toast that
      disappears: it cannot be read back, so an administrator who misses it has
      to delete the webhook and make another.
    -->
    <div v-if="newSecret" class="secret" role="alert">
      <p class="secret-title">Copy this signing secret now</p>
      <p class="secret-detail">
        It is not shown again. The receiver needs it to verify that deliveries
        came from this site.
      </p>
      <div class="secret-row">
        <label class="visually-hidden" for="new-secret">Signing secret</label>
        <input id="new-secret" :value="newSecret" readonly @focus="$event.target.select()" />
        <button type="button" class="btn-secondary" @click="copySecret">
          {{ copied ? 'Copied' : 'Copy' }}
        </button>
        <button type="button" class="btn-secondary" @click="newSecret = ''">Done</button>
      </div>
    </div>

    <section class="panel">
      <h2>Add a webhook</h2>

      <div class="form-row">
        <label class="field">
          <span>URL</span>
          <input v-model="draft.url" type="url" placeholder="https://example.com/hooks/click" />
        </label>

        <label class="field">
          <span>Description</span>
          <input v-model="draft.description" type="text" placeholder="What this is for" />
        </label>
      </div>

      <fieldset class="events">
        <legend>Send when</legend>
        <label v-for="event in EVENTS" :key="event.name" class="event">
          <input v-model="draft.events" type="checkbox" :value="event.name" />
          <span>
            <strong>{{ event.label }}</strong>
            <em>{{ event.detail }}</em>
          </span>
        </label>
      </fieldset>

      <button type="button" class="btn-primary" :disabled="busy" @click="create">
        {{ busy ? 'Adding…' : 'Add webhook' }}
      </button>
    </section>

    <section class="panel">
      <h2>Webhooks</h2>

      <p v-if="loading" class="empty">Loading…</p>
      <p v-else-if="endpoints.length === 0" class="empty">
        None yet. Nothing is being sent anywhere.
      </p>

      <ul v-else class="endpoint-list">
        <li v-for="endpoint in endpoints" :key="endpoint.id" class="endpoint">
          <div class="endpoint-body">
            <p class="endpoint-url">{{ endpoint.url }}</p>
            <p v-if="endpoint.description" class="endpoint-desc">{{ endpoint.description }}</p>
            <p class="endpoint-events">{{ endpoint.events.join(', ') }}</p>
          </div>

          <div class="endpoint-actions">
            <span class="state" :class="endpoint.active ? 'on' : 'off'">
              {{ endpoint.active ? 'Active' : 'Off' }}
            </span>
            <button type="button" class="btn-secondary" @click="toggle(endpoint)">
              {{ endpoint.active ? 'Switch off' : 'Switch on' }}
            </button>
            <button type="button" class="btn-danger" @click="remove(endpoint)">Delete</button>
          </div>
        </li>
      </ul>
    </section>

    <section class="panel">
      <h2>Recent deliveries</h2>

      <p class="panel-help">
        The answer to "did my webhook fire?". A failed delivery is retried with
        a growing gap for about two hours before it is given up on.
      </p>

      <p v-if="deliveries.length === 0" class="empty">Nothing sent yet.</p>

      <table v-else class="deliveries">
        <thead>
          <tr>
            <th scope="col">Event</th>
            <th scope="col">Status</th>
            <th scope="col">Tries</th>
            <th scope="col">Detail</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="delivery in deliveries" :key="delivery.id">
            <td>{{ delivery.event }}</td>
            <td><span class="pill" :class="delivery.status">{{ delivery.status }}</span></td>
            <td>{{ delivery.attempts }}</td>
            <td class="detail">{{ delivery.lastError || delivery.lastStatus || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';

/**
 * The events an administrator can subscribe to, in the product's words rather
 * than the hook's. `content.saved` means nothing to somebody wiring up a
 * rebuild; "an editor saved a change" does.
 */
const EVENTS = [
  { name: 'content.published', label: 'Something is published', detail: 'A page or entry goes live.' },
  { name: 'content.unpublished', label: 'Something is taken down', detail: 'A page or entry leaves the public site.' },
  { name: 'content.saved', label: 'Something is saved', detail: 'Any edit, published or not.' },
  { name: 'content.deleted', label: 'Something is deleted', detail: 'A page or entry is removed entirely.' },
];

const endpoints = ref([]);
const deliveries = ref([]);
const canSend = ref(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const newSecret = ref('');
const copied = ref(false);

const draft = ref({ url: '', description: '', events: ['content.published'] });

const load = async () => {
  error.value = '';

  try {
    const res = await fetch('/api/webhooks');
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      error.value = body.error || `Could not read the webhooks (${res.status}).`;
      return;
    }

    endpoints.value = body.data?.endpoints ?? [];
    canSend.value = body.data?.canSend ?? null;
  } catch (e) {
    error.value = `Could not read the webhooks: ${e.message}`;
  } finally {
    loading.value = false;
  }
};

const loadDeliveries = async () => {
  try {
    const res = await fetch('/api/webhooks/deliveries');
    const body = await res.json().catch(() => ({}));
    deliveries.value = res.ok && Array.isArray(body.data) ? body.data : [];
  } catch {
    // A delivery log that cannot be read is not worth an error banner over the
    // whole screen: the endpoints above are still managed, and the log is
    // diagnostic.
    deliveries.value = [];
  }
};

const create = async () => {
  busy.value = true;
  error.value = '';
  notice.value = '';

  try {
    const res = await fetch('/api/webhooks', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(draft.value),
    });
    const body = await res.json().catch(() => ({}));

    if (!res.ok) {
      error.value = body.error || `Could not add the webhook (${res.status}).`;
      return;
    }

    newSecret.value = body.data?.secret || '';
    copied.value = false;
    draft.value = { url: '', description: '', events: ['content.published'] };
    notice.value = 'Webhook added.';

    await load();
  } catch (e) {
    error.value = `Could not add the webhook: ${e.message}`;
  } finally {
    busy.value = false;
  }
};

const toggle = async (endpoint) => {
  error.value = '';

  try {
    const res = await fetch(`/api/webhooks/${endpoint.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ active: !endpoint.active }),
    });

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      error.value = body.error || `Could not change the webhook (${res.status}).`;
      return;
    }

    await load();
  } catch (e) {
    error.value = `Could not change the webhook: ${e.message}`;
  }
};

const remove = async (endpoint) => {
  // Deleting an endpoint discards whatever is still queued for it, so the
  // confirmation says so rather than asking a bare "are you sure".
  if (!window.confirm(
    `Delete the webhook for ${endpoint.url}?\n\n`
    + 'Anything still waiting to be sent to it will be discarded, and the '
    + 'signing secret cannot be recovered.'
  )) {
    return;
  }

  error.value = '';

  try {
    const res = await fetch(`/api/webhooks/${endpoint.id}`, { method: 'DELETE' });

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      error.value = body.error || `Could not delete the webhook (${res.status}).`;
      return;
    }

    notice.value = 'Webhook deleted.';
    await load();
  } catch (e) {
    error.value = `Could not delete the webhook: ${e.message}`;
  }
};

const copySecret = async () => {
  try {
    await navigator.clipboard.writeText(newSecret.value);
    copied.value = true;
  } catch {
    copied.value = false;
  }
};

onMounted(() => {
  load();
  loadDeliveries();
});
</script>

<style scoped>
.page-title { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.5rem; color: var(--app-text); }
.intro { margin: 0 0 1.5rem; max-width: 60ch; color: var(--app-text-muted); line-height: 1.5; }

.banner { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
.banner.error { background: color-mix(in srgb, var(--color-danger-500) 12%, var(--app-surface)); color: var(--app-text); }
.banner.notice { background: color-mix(in srgb, var(--color-success-500) 12%, var(--app-surface)); color: var(--app-text); }

.secret { border: 1px solid var(--color-warning-500); border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; background: color-mix(in srgb, var(--color-warning-500) 10%, var(--app-surface-strong)); }
.secret-title { margin: 0; font-weight: 700; color: var(--app-text); }
.secret-detail { margin: 0.25rem 0 0.75rem; font-size: 0.875rem; color: var(--app-text-muted); }
.secret-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.secret-row input { flex: 1; min-width: 18rem; padding: 0.5rem 0.65rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font-family: ui-monospace, monospace; }

.panel { border: 1px solid var(--app-border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem; background: var(--app-surface-strong); }
.panel h2 { margin: 0 0 0.75rem; font-size: 1.0625rem; font-weight: 700; color: var(--app-text); }
.panel-help { margin: -0.35rem 0 0.85rem; font-size: 0.875rem; color: var(--app-text-muted); }

.form-row { display: flex; flex-wrap: wrap; gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; flex: 1; min-width: 16rem; font-size: 0.8125rem; color: var(--app-text-muted); }
.field input { padding: 0.5rem 0.65rem; border: 1px solid var(--control-border); border-radius: 8px; background: var(--app-surface); color: var(--app-text); font: inherit; }

.events { border: none; padding: 0; margin: 1rem 0; }
.events legend { font-size: 0.8125rem; color: var(--app-text-muted); padding: 0; margin-bottom: 0.5rem; }
.event { display: flex; gap: 0.5rem; align-items: flex-start; margin-bottom: 0.5rem; }
.event strong { display: block; font-size: 0.875rem; font-weight: 600; color: var(--app-text); }
.event em { display: block; font-style: normal; font-size: 0.8125rem; color: var(--app-text-muted); }

.endpoint-list { list-style: none; margin: 0; padding: 0; }
.endpoint { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; padding: 0.85rem 0; border-top: 1px solid var(--app-border); }
.endpoint:first-child { border-top: none; }
.endpoint-body { flex: 1; min-width: 16rem; }
.endpoint-url { margin: 0; font-family: ui-monospace, monospace; font-size: 0.875rem; color: var(--app-text); word-break: break-all; }
.endpoint-desc { margin: 0.2rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }
.endpoint-events { margin: 0.2rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.endpoint-actions { display: flex; gap: 0.5rem; align-items: center; }

.state { font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 999px; }
.state.on { background: color-mix(in srgb, var(--color-success-500) 18%, transparent); color: var(--app-text); }
.state.off { background: var(--app-surface); color: var(--app-text-muted); border: 1px solid var(--app-border); }

.deliveries { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.deliveries th, .deliveries td { text-align: left; padding: 0.5rem 0.65rem; border-bottom: 1px solid var(--app-border); }
.deliveries th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-text-muted); }
.detail { color: var(--app-text-muted); }

.pill { font-size: 0.75rem; padding: 0.1rem 0.5rem; border-radius: 999px; background: var(--app-surface); border: 1px solid var(--app-border); }
.pill.delivered { background: color-mix(in srgb, var(--color-success-500) 18%, transparent); }
.pill.failed { background: color-mix(in srgb, var(--color-danger-500) 18%, transparent); }

.empty { margin: 0; color: var(--app-text-muted); font-size: 0.875rem; }

.btn-primary, .btn-secondary, .btn-danger { padding: 0.5rem 1rem; border-radius: 8px; font-weight: 500; cursor: pointer; font: inherit; }
.btn-primary { background: var(--color-primary-600); color: white; border: none; }
.btn-secondary { background: var(--app-surface); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-danger { background: var(--app-surface); color: var(--color-danger-500); border: 1px solid var(--control-border); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

button:focus-visible, input:focus-visible, a:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
