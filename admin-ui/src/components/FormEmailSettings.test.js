import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import FormEmailSettings from './FormEmailSettings.vue';
import { setMessageLanguage } from '../lib/messages.js';
const defaults = { enabled: false, to: '', from: '', locale: 'en' };
const response = (data, ok = true, status = 200) => ({ ok, status, json: async () => data });
beforeEach(() => { vi.restoreAllMocks(); setMessageLanguage('en'); });
describe('form email settings', () => {
  it('requires saving changes before testing and sends only saved settings', async () => {
    global.fetch = vi.fn(async (url, init) => response(url.endsWith('email-test') ? { success: true } : { data: init ? JSON.parse(init.body) : defaults }));
    const wrapper = mount(FormEmailSettings); await flushPromises();
    expect(wrapper.find('fieldset').attributes('disabled')).toBeUndefined();
    await wrapper.find('#form-email-to').setValue('editor@example.com');
    await wrapper.find('#form-email-from').setValue('cms@example.com');
    expect(wrapper.find('button[type=button]').element.disabled).toBe(true);
    await wrapper.find('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Email settings saved.');
    expect(wrapper.find('button[type=button]').element.disabled).toBe(false);
    await wrapper.find('button[type=button]').trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('accepted the test email');
    expect(wrapper.text()).toContain('confirm delivery');
    expect(fetch).toHaveBeenCalledWith('/api/forms/email-test', { method: 'POST' });
  });
  it('shows a translated transport failure and keeps entered settings', async () => {
    setMessageLanguage('de');
    global.fetch = vi.fn(async url => url.endsWith('email-test')
      ? response({ error: 'The server could not send the test email. Your settings are saved. Ask your hosting provider to check email sending.' }, false, 503)
      : response({ data: { ...defaults, to: 'editor@example.com', from: 'cms@example.com' } }));
    const wrapper = mount(FormEmailSettings); await flushPromises();
    await wrapper.find('button[type=button]').trigger('click'); await flushPromises();
    expect(wrapper.find('[role=alert]').text()).toContain('Deine Einstellungen sind gespeichert');
    expect(wrapper.find('#form-email-to').element.value).toBe('editor@example.com');
  });
  it('never reports a failed save as success and keeps the test disabled', async () => {
    global.fetch = vi.fn(async (url, init) => init
      ? response({ error: 'Enter valid recipient and sender email addresses.' }, false, 422)
      : response({ data: defaults }));
    const wrapper = mount(FormEmailSettings); await flushPromises();
    await wrapper.find('#form-email-to').setValue('bad');
    await wrapper.find('form').trigger('submit'); await flushPromises();
    expect(wrapper.find('[role=alert]').text()).toContain('valid recipient');
    expect(wrapper.find('[role=status]').exists()).toBe(false);
    expect(wrapper.find('button[type=button]').element.disabled).toBe(true);
  });
  it('explains how to enable the plugin when unavailable', async () => {
    global.fetch = vi.fn(async () => response({}, false, 404));
    const wrapper = mount(FormEmailSettings); await flushPromises();
    expect(wrapper.text()).toContain('Enable the Forms plugin');
    expect(wrapper.find('form').exists()).toBe(false);
  });
});
