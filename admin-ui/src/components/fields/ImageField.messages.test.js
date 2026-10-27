import { afterEach, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ImageField from './ImageField.vue';
import { messageLanguage } from '../../lib/messages.js';
afterEach(() => {messageLanguage.value='en';vi.restoreAllMocks();});
it('renders German quality, accessibility and validation messages without altering media', async () => {
 messageLanguage.value='de';
 const item={id:'poster',originalName:'poster.jpg',alt:'',width:1280,height:720,urls:{original:'/poster.jpg'},quality:{warning:true,sourceWidth:1280,displayWidth:1280,recommendedWidth:2560,message:'English quality warning'}};
 global.fetch=vi.fn(async()=>({ok:true,json:async()=>({data:[item]})}));
 const wrapper=mount(ImageField,{props:{field:{label:'Vorschaubild'},modelValue:'poster',error:'Vorschaubild is required.'}});
 await flushPromises();
 expect(wrapper.text()).toContain('Eine Bildbeschreibung fehlt');
 expect(wrapper.text()).toContain('2560 Pixeln');
 expect(wrapper.text()).toContain('Bitte „Vorschaubild“ ausfüllen.');
 expect(wrapper.text()).not.toContain('English quality warning');
 expect(wrapper.emitted('update:modelValue')).toBeUndefined();
});
it('translates library failures without claiming it is empty', async()=>{
 messageLanguage.value='de';global.fetch=vi.fn(async()=>({ok:false,status:500}));
 const wrapper=mount(ImageField,{props:{field:{label:'Bild'}}});await flushPromises();
 expect(wrapper.get('[data-test="library-failed"]').text()).toContain('konnte nicht geladen werden');
 expect(wrapper.text()).not.toContain('Mediathek ist leer');
});
