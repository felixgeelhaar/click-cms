import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Users from './Users.vue';

/**
 * The users screen. These pin that the list comes from the API (slug → username),
 * that a failed load is said plainly, that creating an account POSTs the draft,
 * and that an empty site shows the empty state rather than a blank list.
 */

const storedUser = (slug, data = {}) => ({
  slug,
  data: {
    displayName: data.displayName ?? slug,
    email: data.email ?? `${slug}@example.com`,
    role: data.role ?? 'editor',
    ...data,
  },
});

const jsonRes = (status, body) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
});

const withUsers = (list, { onPost } = {}) => {
  let users = list;

  global.fetch = vi.fn(async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const path = String(url);

    if (method === 'GET' && path === '/api/users') {
      return jsonRes(200, { data: users });
    }
    if (method === 'POST' && path === '/api/users') {
      const sent = JSON.parse(init.body);
      if (onPost) return onPost(sent);
      users = [...users, storedUser(sent.username, {
        displayName: sent.displayName,
        email: sent.email,
        role: sent.role,
      })];
      return jsonRes(201, { data: { slug: sent.username } });
    }
    if (method === 'PUT' && path.startsWith('/api/users/')) {
      return jsonRes(200, { data: {} });
    }
    if (method === 'DELETE' && path.startsWith('/api/users/')) {
      const username = path.slice('/api/users/'.length);
      users = users.filter((u) => u.slug !== username);
      return jsonRes(200, { data: null });
    }
    return jsonRes(200, { data: [] });
  });
};

const mountUsers = async (list, opts = {}) => {
  withUsers(list, opts);
  const wrapper = mount(Users, {
    props: { currentUsername: opts.currentUsername ?? 'admin', userRole: 'admin' },
  });
  await flushPromises();
  return wrapper;
};

beforeEach(() => vi.restoreAllMocks());

describe('the user list', () => {
  it('renders every account from the API with name, email and role', async () => {
    const wrapper = await mountUsers([
      storedUser('admin', { displayName: 'Site Admin', email: 'admin@example.com', role: 'admin' }),
      storedUser('ed', { displayName: 'Ed Writer', email: 'ed@example.com', role: 'editor' }),
    ]);

    expect(wrapper.findAll('.row')).toHaveLength(2);
    expect(wrapper.text()).toContain('Site Admin');
    expect(wrapper.text()).toContain('ed@example.com');
    expect(wrapper.text()).toContain('Administrator');
    expect(wrapper.text()).toContain('Editor');
    expect(wrapper.text()).toContain('2 accounts');
  });

  it('says so plainly when there are no users', async () => {
    const wrapper = await mountUsers([]);

    expect(wrapper.text()).toContain('No users yet.');
    expect(wrapper.findAll('.row')).toHaveLength(0);
  });

  it('shows an error banner when the list cannot be loaded', async () => {
    global.fetch = vi.fn(async () => jsonRes(500, { error: 'Storage unavailable' }));
    const wrapper = mount(Users, { props: { currentUsername: 'admin', userRole: 'admin' } });
    await flushPromises();

    expect(wrapper.find('[role="alert"]').text()).toContain('Could not load users');
    expect(wrapper.find('[role="alert"]').text()).toContain('Request failed (500)');
  });
});

describe('creating a user', () => {
  it('POSTs the draft to /api/users and reloads the list', async () => {
    const wrapper = await mountUsers([
      storedUser('admin', { displayName: 'Site Admin', role: 'admin' }),
    ]);

    await wrapper.find('.btn-primary').trigger('click');
    await wrapper.find('#u-username').setValue('pat');
    await wrapper.find('#u-display').setValue('Pat Author');
    await wrapper.find('#u-email').setValue('pat@example.com');
    await wrapper.find('#u-role').setValue('author');
    await wrapper.find('#u-password').setValue('password1');
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    const post = global.fetch.mock.calls.find(([, init]) => init?.method === 'POST');
    expect(post, 'a POST should have been sent').toBeTruthy();
    expect(post[0]).toBe('/api/users');
    expect(JSON.parse(post[1].body)).toEqual({
      username: 'pat',
      displayName: 'Pat Author',
      email: 'pat@example.com',
      role: 'author',
      password: 'password1',
    });
    expect(wrapper.text()).toContain('Pat Author');
  });
});

describe('deleting a user', () => {
  it('DELETEs the chosen account and refreshes the list', async () => {
    const wrapper = await mountUsers([
      storedUser('admin', { displayName: 'Site Admin', role: 'admin' }),
      storedUser('ed', { displayName: 'Ed Writer', role: 'editor' }),
    ]);

    const edRow = wrapper.findAll('.row').find((r) => r.text().includes('Ed Writer'));
    await edRow.find('.btn-sm.danger').trigger('click');
    await flushPromises();

    const del = global.fetch.mock.calls.find(([, init]) => init?.method === 'DELETE');
    expect(del, 'a DELETE should have been sent').toBeTruthy();
    expect(del[0]).toBe('/api/users/ed');
    expect(wrapper.text()).not.toContain('Ed Writer');
  });
});
