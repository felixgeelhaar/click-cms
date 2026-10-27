import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PluginSettingsForm from './PluginSettingsForm.vue';
import formsManifest from '../../../plugins/forms/plugin.json';
const mountForm = () => mount(PluginSettingsForm, { props: { id: 'forms', schema: formsManifest.settings } });
import { setMessageLanguage } from '../lib/messages.js';
const defaults = { enabled: false, to: '', from: '', locale: 'en' };
const response = (data, ok = true, status = 200) => ({ ok, status, json: async () => data });
beforeEach(() => { vi.restoreAllMocks(); setMessageLanguage('en'); });
describe('form email settings', () => {
  it('requires saving changes before testing and sends only saved settings', async () => {
    global.fetch = vi.fn(async (url, init) => response(url.endsWith('email-test') ? { success: true } : { data: init ? JSON.parse(init.body) : defaults }));
    const wrapper = mountForm(); await flushPromises();
    expect(wrapper.find('fieldset').attributes('disabled')).toBeUndefined();
    await wrapper.find('#plugin-forms-to').setValue('editor@example.com');
    await wrapper.find('#plugin-forms-from').setValue('cms@example.com');
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
    const wrapper = mountForm(); await flushPromises();
    await wrapper.find('button[type=button]').trigger('click'); await flushPromises();
    expect(wrapper.find('[role=alert]').text()).toContain('Deine Einstellungen sind gespeichert');
    expect(wrapper.find('#plugin-forms-to').element.value).toBe('editor@example.com');
  });
  it('never reports a failed save as success and keeps the test disabled', async () => {
    global.fetch = vi.fn(async (url, init) => init
      ? response({ error: 'Enter valid recipient and sender email addresses.' }, false, 422)
      : response({ data: defaults }));
    const wrapper = mountForm(); await flushPromises();
    await wrapper.find('#plugin-forms-to').setValue('bad');
    await wrapper.find('form').trigger('submit'); await flushPromises();
    expect(wrapper.find('[role=alert]').text()).toContain('valid recipient');
    expect(wrapper.find('[role=status]').exists()).toBe(false);
    expect(wrapper.find('button[type=button]').element.disabled).toBe(true);
  });
  it('supports a different plugin without Forms-specific UI code', async () => {
    global.fetch = vi.fn(async (url, init) => response({ data: init ? JSON.parse(init.body) : { sender: 'A studio' } }));
    const wrapper = mount(PluginSettingsForm, { props: { id: 'newsletter', schema: {
      title: 'Newsletter preferences', endpoint: '/api/newsletter/settings',
      fields: [{ name: 'sender', type: 'text', label: 'Sender name' }],
    } } });
    await flushPromises();
    await wrapper.find('#plugin-newsletter-sender').setValue('Another studio');
    await wrapper.find('form').trigger('submit'); await flushPromises();
    expect(fetch).toHaveBeenCalledWith('/api/newsletter/settings', expect.objectContaining({ method: 'PUT', body: '{"sender":"Another studio"}' }));
  });
  it('refuses cross-origin and unrelated API endpoints from a manifest', async () => {
    for (const endpoint of ['https://evil.example/settings', '/api/users/admin', '/api/forms/../users', '/api/forms//settings']) {
      global.fetch = vi.fn();
      const wrapper = mount(PluginSettingsForm, { props: { id: 'forms', schema: { ...formsManifest.settings, endpoint } } });
      await flushPromises();
      expect(fetch).not.toHaveBeenCalled();
      expect(wrapper.find('form').exists()).toBe(false);
    }
  });
});
