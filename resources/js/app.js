import './bootstrap';
import '../css/app.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import ui from '@nuxt/ui/vue-plugin';

import App from '@/App.vue';
import router from '@/router';
import i18n from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);
app.use(ui);

// Restore the session before the first route resolves, so a reload on a guarded
// page doesn't bounce the user to /login while the token is still being read.
const auth = useAuthStore();
auth.restore();

app.mount('#app');
