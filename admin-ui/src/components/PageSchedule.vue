<template>
  <!--
    Below the publication banner, not inside it.

    The banner answers "what can the public see right now". This answers "what
    is going to change, and when". Folding the second into the first would
    produce a strip saying two things at once, and the state an editor most
    needs to read clearly — published, with a takedown pending — is exactly
    where those two answers diverge.
  -->
  <section class="sch" :class="{ 'sch-overdue': overdue }" aria-labelledby="page-schedule-heading">
    <!--
      h2, matching the other panels on this screen. The editor's h1 is the page
      title, so an h3 here skips a level — which the axe-core suite catches, and
      which makes the panel unreachable as a landmark for anyone navigating by
      heading.
    -->
    <h2 id="page-schedule-heading" class="sch-title">Scheduled publishing</h2>

    <p class="sch-summary" role="status" aria-live="polite">{{ summary }}</p>

    <!--
      A time in the past with the schedule still standing means nothing swept
      it. On a site whose administrator never added the cron entry that is the
      permanent state, and without this line the editor simply waits for a
      publication no process is going to perform.
    -->
    <p v-if="overdue" class="sch-overdue-note">
      This should have happened already. Scheduled publishing needs
      <code>bin/click-schedule.php</code> to run from cron — ask whoever looks
      after this site whether it does.
    </p>

    <template v-if="canSchedule">
      <div class="sch-fields">
        <label class="sch-field">
          <span>Publish at</span>
          <input v-model="publishLocal" type="datetime-local" />
        </label>

        <label class="sch-field">
          <span>Take down at</span>
          <input v-model="unpublishLocal" type="datetime-local" />
        </label>
      </div>

      <p class="sch-zone">
        Times are in {{ zoneName }} — the same clock you are reading now.
      </p>

      <p v-if="error" class="sch-error" role="alert">{{ error }}</p>

      <div class="sch-actions">
        <button type="button" class="btn-schedule" :disabled="busy" @click="save">
          {{ busy ? 'Saving…' : 'Save schedule' }}
        </button>
        <button
          v-if="hasSchedule"
          type="button"
          class="btn-clear"
          :disabled="busy"
          @click="$emit('clear')"
        >
          Cancel schedule
        </button>
      </div>
    </template>

    <p v-else class="sch-no-permission">
      Your account cannot schedule this page. Ask an editor.
    </p>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
  /** The API's `schedule` object, or null before it is known. */
  schedule: { type: Object, default: null },
  canSchedule: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'clear']);

const error = ref('');

/**
 * `datetime-local` speaks wall-clock with no zone: "2099-08-01T09:00" means
 * nine o'clock wherever the browser is. The API speaks absolute instants. These
 * two functions are the whole conversion, and getting them wrong is the bug the
 * feature is most likely to have — an editor in Berlin scheduling nine o'clock
 * and being published at eleven, with nothing on screen to explain it.
 */
const toLocalField = (iso) => {
  if (!iso) return '';
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return '';

  // Shift by the browser's own offset so `toISOString` yields the local
  // wall-clock rather than UTC, then drop the zone and seconds the input
  // does not accept.
  const shifted = new Date(at.getTime() - at.getTimezoneOffset() * 60000);
  return shifted.toISOString().slice(0, 16);
};

const toInstant = (local) => {
  if (!local) return null;
  // `new Date('2099-08-01T09:00')` is interpreted in the browser's zone, which
  // is exactly what the editor meant. `toISOString` then states it absolutely.
  const at = new Date(local);
  return Number.isNaN(at.getTime()) ? null : at.toISOString();
};

const publishLocal = ref('');
const unpublishLocal = ref('');

watch(
  () => props.schedule,
  (next) => {
    publishLocal.value = toLocalField(next?.publishAt);
    unpublishLocal.value = toLocalField(next?.unpublishAt);
  },
  { immediate: true },
);

const hasSchedule = computed(
  () => Boolean(props.schedule?.publishAt || props.schedule?.unpublishAt),
);

const zoneName = computed(() => {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'your local time';
  } catch {
    return 'your local time';
  }
});

const when = (iso) => {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? '' : at.toLocaleString();
};

/**
 * Whether a stated time has passed with the schedule still standing.
 *
 * The signal that nothing is sweeping. Read from the stored schedule rather
 * than the form, so typing a past date does not accuse the site of being
 * misconfigured before the editor has even saved it.
 */
const overdue = computed(() => {
  const times = [props.schedule?.publishAt, props.schedule?.unpublishAt]
    .filter(Boolean)
    .map((iso) => new Date(iso).getTime())
    .filter((t) => !Number.isNaN(t));

  return times.some((t) => t < Date.now());
});

const summary = computed(() => {
  if (props.schedule === null) return 'Checking what is scheduled…';

  const up = props.schedule.publishAt ? when(props.schedule.publishAt) : '';
  const down = props.schedule.unpublishAt ? when(props.schedule.unpublishAt) : '';
  const by = props.schedule.scheduledBy ? ` Set by ${props.schedule.scheduledBy}.` : '';

  if (!up && !down) return 'Nothing is scheduled. This page changes only when somebody publishes it.';
  if (up && down) return `Set to go live ${up} and come down ${down}.${by}`;
  if (up) return `Set to go live ${up}.${by}`;
  return `Set to come down ${down}.${by}`;
});

/**
 * Checked here as well as on the server.
 *
 * Not because the server's check is unreliable — it is the one that counts —
 * but because a round trip to be told the second date is before the first is a
 * worse way to learn it than a line under the field.
 */
const save = () => {
  error.value = '';

  const publishAt = toInstant(publishLocal.value);
  const unpublishAt = toInstant(unpublishLocal.value);

  if (publishAt && unpublishAt && new Date(unpublishAt) <= new Date(publishAt)) {
    error.value = 'The takedown must be after the publication it ends.';
    return;
  }

  emit('save', { publishAt, unpublishAt });
};
</script>

<style scoped>
.sch {
  padding: 1rem 1.25rem;
  margin-bottom: 1.5rem;
  border: 1px solid var(--app-border);
  border-radius: 10px;
  background: var(--app-surface-strong);
}
.sch-overdue { border-left: 6px solid var(--color-warning-500); }
.sch-title { margin: 0 0 0.35rem; font-size: 0.9375rem; font-weight: 700; color: var(--app-text); }
.sch-summary { margin: 0; font-size: 0.875rem; line-height: 1.45; color: var(--app-text-muted); }
.sch-overdue-note { margin: 0.5rem 0 0; font-size: 0.8125rem; line-height: 1.45; color: var(--app-text); }
.sch-overdue-note code { font-size: 0.95em; }
.sch-fields { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 0.85rem; }
.sch-field { display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.8125rem; color: var(--app-text-muted); }
.sch-field input {
  padding: 0.5rem 0.65rem;
  border: 1px solid var(--control-border);
  border-radius: 8px;
  background: var(--app-surface);
  color: var(--app-text);
  font: inherit;
}
.sch-zone { margin: 0.6rem 0 0; font-size: 0.75rem; color: var(--app-text-muted); }
.sch-error { margin: 0.6rem 0 0; font-size: 0.8125rem; color: var(--color-danger-500); }
.sch-actions { display: flex; gap: 0.75rem; margin-top: 0.85rem; }
.sch-no-permission { margin: 0.5rem 0 0; font-size: 0.8125rem; color: var(--app-text-muted); }

.btn-schedule, .btn-clear { padding: 0.5rem 1rem; border-radius: 8px; font-weight: 500; cursor: pointer; font: inherit; }
.btn-schedule { background: var(--color-primary-600); color: white; border: none; }
.btn-clear { background: var(--app-surface); color: var(--app-text); border: 1px solid var(--control-border); }
.btn-schedule:disabled, .btn-clear:disabled { opacity: 0.6; cursor: not-allowed; }

button:focus-visible,
input:focus-visible {
  outline: 2px solid var(--focus-ring, #0f766e);
  outline-offset: 2px;
  border-radius: 6px;
}
</style>
