import { createRouter, createWebHistory } from 'vue-router';
import Home from './pages/Home.vue';
import About from './pages/About.vue';
import Blog from './pages/Blog.vue';
import Post from './pages/Post.vue';

export default createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/', component: Home, name: 'home' },
    { path: '/about', component: About, name: 'about' },
    { path: '/blog', component: Blog, name: 'blog' },
    { path: '/blog/:slug', component: Post, name: 'post', props: true },
  ],
});
