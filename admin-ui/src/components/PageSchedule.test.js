import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PageSchedule from './PageSchedule.vue';

/**
 * The scheduling panel.
 *
 * Two things it exists to get right, both of which are ways an editor ends up
 * believing something will happen that will not:
 *
 * - **Local time in, UTC out.** An editor types nine o'clock meaning nine
 *   o'clock where they are. Sending that string unqualified would store it as
 *   nine UTC, so a German editor's announcement goes out an hour or two early,
 *   every time, and nothing on screen would say why.
 * - **A schedule is not a publication.** The panel must never imply the page is
 *   live, and must say plainly when a scheduled time has passed with nothing
 *   having happened — which is what a site with no cron entry looks like.
 */
describe('PageSchedule', () => {
  const mountWith = (props) => mount(PageSchedule, {
    props: { canSchedule: true, ...props },
  });

  /* ----------------------------------------------------------- reading -- */

  it('says nothing is scheduled when nothing is', () => {
    const text = mountWith({ schedule: { publishAt: null, unpublishAt: null } }).text();

    expect(text).toContain('Nothing is scheduled');
  });

  it('shows a pending publication', () => {
    const text = mountWith({
      schedule: { publishAt: '2099-08-01T09:00:00+00:00', unpublishAt: null },
    }).text();

    expect(text).toContain('go live');
  });

  it('shows a pending takedown', () => {
    const text = mountWith({
      schedule: { publishAt: null, unpublishAt: '2099-09-01T09:00:00+00:00' },
    }).text();

    expect(text).toContain('come down');
  });

  it('names who set the schedule when that is known', () => {
    const text = mountWith({
      schedule: { publishAt: '2099-08-01T09:00:00+00:00', unpublishAt: null, scheduledBy: 'jo' },
    }).text();

    expect(text).toContain('jo');
  });

  /**
   * The one that catches a site with no cron entry. A time in the past with the
   * schedule still standing means nothing swept it, and the editor has to be
   * told — otherwise they wait indefinitely for a publication that no process
   * on the machine is going to perform.
   */
  it('warns when a scheduled time has passed and nothing happened', () => {
    const text = mountWith({
      schedule: { publishAt: '2020-01-01T09:00:00+00:00', unpublishAt: null },
    }).text();

    expect(text).toContain('should have happened');
  });

  it('does not warn about a time still in the future', () => {
    const text = mountWith({
      schedule: { publishAt: '2099-01-01T09:00:00+00:00', unpublishAt: null },
    }).text();

    expect(text).not.toContain('should have happened');
  });

  /* ------------------------------------------------------- permissions -- */

  it('offers no controls to an account that cannot publish', () => {
    const wrapper = mountWith({
      canSchedule: false,
      schedule: { publishAt: null, unpublishAt: null },
    });

    expect(wrapper.findAll('input[type="datetime-local"]')).toHaveLength(0);
    expect(wrapper.text()).toContain('cannot schedule');
  });

  /* --------------------------------------------------------- timezone -- */

  /**
   * The core of the feature. `datetime-local` yields a wall-clock string with no
   * zone; sending it as-is means the server reads it as UTC and an editor two
   * hours ahead publishes two hours early. The component must convert through
   * the browser's own offset before emitting.
   */
  it('emits an absolute instant, not the bare wall-clock string', async () => {
    const wrapper = mountWith({ schedule: { publishAt: null, unpublishAt: null } });

    await wrapper.find('input[type="datetime-local"]').setValue('2099-08-01T09:00');
    await wrapper.find('button.btn-schedule').trigger('click');

    const emitted = wrapper.emitted('save');
    expect(emitted).toBeTruthy();

    const sent = emitted[0][0].publishAt;
    // Whatever the runner's zone, it must carry one and mean the instant the
    // editor picked in theirs.
    expect(sent).toMatch(/(Z|[+-]\d{2}:\d{2})$/);
    expect(new Date(sent).getTime()).toBe(new Date('2099-08-01T09:00').getTime());
  });

  it('round-trips a stored instant back into the local field', () => {
    const local = new Date('2099-08-01T09:00:00Z');
    const wrapper = mountWith({
      schedule: { publishAt: local.toISOString(), unpublishAt: null },
    });

    const field = wrapper.find('input[type="datetime-local"]').element.value;
    // The field shows the same instant expressed where the editor is sitting.
    expect(new Date(field).getTime()).toBe(local.getTime());
  });

  /* ---------------------------------------------------------- clearing -- */

  it('clears both ends', async () => {
    const wrapper = mountWith({
      schedule: { publishAt: '2099-08-01T09:00:00+00:00', unpublishAt: null },
    });

    await wrapper.find('button.btn-clear').trigger('click');

    expect(wrapper.emitted('clear')).toBeTruthy();
  });

  it('emits null for an end the editor emptied', async () => {
    const wrapper = mountWith({
      schedule: { publishAt: '2099-08-01T09:00:00+00:00', unpublishAt: null },
    });

    await wrapper.find('input[type="datetime-local"]').setValue('');
    await wrapper.find('button.btn-schedule').trigger('click');

    expect(wrapper.emitted('save')[0][0].publishAt).toBeNull();
  });

  /* ---------------------------------------------------------- refusal -- */

  it('refuses a takedown that is not after its publication, without asking the server', async () => {
    const wrapper = mountWith({ schedule: { publishAt: null, unpublishAt: null } });

    const fields = wrapper.findAll('input[type="datetime-local"]');
    await fields[0].setValue('2099-09-01T09:00');
    await fields[1].setValue('2099-08-01T09:00');
    await wrapper.find('button.btn-schedule').trigger('click');

    expect(wrapper.emitted('save')).toBeFalsy();
    expect(wrapper.text()).toContain('must be after');
  });
});
