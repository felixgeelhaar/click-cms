import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PluginDetail from './PluginDetail.vue';
import manifest from '../../../plugins/forms/plugin.json';
describe('plugin-owned settings', () => {
  it('renders the settings declared by an active plugin and returns to the list', async () => {
    global.fetch = vi.fn(async url => ({ ok: true, json: async () => ({ data: url === '/api/plugins/forms' ? { name: 'Forms', state: 'activated', settings: manifest.settings } : { enabled: false, to: '', from: '', locale: 'en' } }) }));
    const wrapper = mount(PluginDetail, { props: { id: 'forms' } }); await flushPromises();
    expect(wrapper.find('h1').text()).toContain('Forms');
    expect(wrapper.find('#plugin-forms-to').exists()).toBe(true);
    await wrapper.find('.back-btn').trigger('click'); expect(wrapper.emitted('back')).toBeTruthy();
  });
  it('does not request settings or render controls for an inactive plugin', async () => {
    global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ data: { name: 'Forms', state: 'deactivated', settings: manifest.settings } }) }));
    const wrapper = mount(PluginDetail, { props: { id: 'forms' } }); await flushPromises();
    expect(wrapper.text()).toContain('Activate this plugin');
    expect(wrapper.find('form').exists()).toBe(false); expect(fetch).toHaveBeenCalledTimes(1);
  });
});
