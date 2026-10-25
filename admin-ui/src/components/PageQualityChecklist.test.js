import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PageQualityChecklist from './PageQualityChecklist.vue';

describe('pre-publish quality checklist', () => {
  it('links unresolved title, body, SEO and translation checks to their editors', () => {
    const wrapper = mount(PageQualityChecklist, {
      props: {
        page: { title: '', sections: [], seo: { description: '' } },
        locales: ['en', 'de'],
        translations: { en: { exists: true, publication: { published: true } }, de: { exists: false } },
      },
    });
    expect(wrapper.text()).toContain('Add a page title');
    expect(wrapper.text()).toContain('Add page content');
    expect(wrapper.text()).toContain('Add a search description');
    expect(wrapper.text()).toContain('Create the German translation');
    expect(wrapper.find('a[href="#page-title"]').exists()).toBe(true);
    expect(wrapper.find('a[href="#section-type-picker"]').exists()).toBe(true);
    expect(wrapper.find('a[href="#seo-description"]').exists()).toBe(true);
    expect(wrapper.find('a[href="#page-languages-heading"]').exists()).toBe(true);
  });

  it('marks the page ready when its minimum content checks pass', () => {
    const wrapper = mount(PageQualityChecklist, {
      props: { page: { title: 'Home', sections: [{ type: 'hero', values: {} }], seo: { description: 'Welcome.' } } },
    });
    expect(wrapper.text()).toContain('No publishing checks need attention.');
  });
});
