import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { registerSW } from 'virtual:pwa-register';
import App from './App.vue';
import { createAppRouter } from './router';
import { http } from './api/http';
import { locale } from './i18n';
import { useAuthStore } from './stores/auth';
import { useContextStore } from './stores/context';
import { useOfflineStore } from './stores/offline';
import './styles/main.css';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia);

const auth = useAuthStore();
const ctx = useContextStore();
const router = createAppRouter();

http.configure({
  getEstablishmentId: () => ctx.establishmentId,
  getLocale: () => locale.value,
  onUnauthorized: () => {
    // Session expirée : retour à la connexion en gardant l'adresse demandée. La file hors ligne n'est pas effacée.
    auth.clear();
    ctx.clear();
    // À l'ouverture directe d'une page, la première navigation n'est pas encore résolue : la route courante est vide.
    // On résout alors l'adresse du navigateur, sinon une page publique serait renvoyée vers la connexion.
    const loaded = router.currentRoute.value;
    const current = loaded.matched.length === 0 ? router.resolve(window.location.pathname + window.location.search + window.location.hash) : loaded;
    if (!current.meta.public) void router.replace({ name: 'login', query: { redirect: current.fullPath } });
  },
});

app.use(router);
app.mount('#app');

useOfflineStore().start();

// Application installable et utilisable hors ligne : le service worker met en cache l'application, jamais l'API.
registerSW({ immediate: true });
