/*
 * Service worker : consultation hors-ligne.
 *
 * Deux strategies, selon ce que la ressource represente.
 *
 * Les fichiers statiques (CSS, JS, polices) changent a chaque deploiement et
 * jamais entre deux : ils sont servis depuis le cache, et rafraichis en
 * arriere-plan. C'est ce qui rend l'ouverture instantanee sur une connexion
 * lente.
 *
 * Les pages, elles, portent des donnees scolaires : servir une version
 * perimee alors que le reseau repond serait trompeur. Elles sont donc
 * demandees au reseau d'abord, avec un delai court, et le cache ne sert que
 * de secours. L'ecran affiche alors clairement qu'il montre une copie et
 * depuis quand.
 *
 * Les ecritures ne passent pas par ici : elles sont mises en file par la page
 * elle-meme (voir assets/offline.js), qui seule peut prevenir l'utilisateur
 * de ce qui reste a transmettre.
 */

const VERSION = 'scholaris-v1';
const STATIC_CACHE = VERSION + '-static';
const PAGE_CACHE = VERSION + '-pages';

// Delai au-dela duquel on considere le reseau inutilisable. En zone a couverture
// faible, une requete peut rester en attente une minute sans jamais aboutir :
// mieux vaut afficher la copie locale que laisser l'ecran vide.
const NETWORK_TIMEOUT_MS = 4000;

const PRECACHE = [
    '/assets/app.css',
    '/assets/offline.js',
    '/assets/icon.svg',
    '/hors-ligne',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            // Un fichier manquant ne doit pas empecher l'installation :
            // l'application resterait alors sans aucun cache.
            .catch(() => undefined)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => !key.startsWith(VERSION))
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/assets/')
        || url.pathname === '/manifest.webmanifest';
}

/** Ne jamais mettre en cache ce qui touche a la session ou a l'argent. */
function isNeverCached(url) {
    return url.pathname === '/login'
        || url.pathname === '/logout'
        || url.pathname.startsWith('/finance/pay')
        || url.pathname.startsWith('/paiement');
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);

    const network = fetch(request).then((response) => {
        if (response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    }).catch(() => cached);

    return cached || network;
}

async function networkFirst(request) {
    const cache = await caches.open(PAGE_CACHE);

    try {
        const response = await Promise.race([
            fetch(request),
            new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), NETWORK_TIMEOUT_MS)),
        ]);

        if (response.ok) {
            // La date de mise en cache permet a la page d'annoncer
            // l'anciennete de ce qu'elle affiche.
            const copy = response.clone();
            const headers = new Headers(copy.headers);
            headers.set('X-Scholaris-Cached-At', new Date().toISOString());

            cache.put(request, new Response(await copy.blob(), {
                status: copy.status,
                statusText: copy.statusText,
                headers: headers,
            }));
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        const fallback = await caches.match('/hors-ligne');

        return fallback || new Response(
            'Hors ligne, et cette page n a pas encore ete consultee sur cet appareil.',
            { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
        );
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Uniquement nos propres URL : un service worker ne doit pas s'interposer
    // entre le navigateur et un tiers.
    if (url.origin !== self.location.origin || isNeverCached(url)) {
        return;
    }

    event.respondWith(
        isStaticAsset(url) ? staleWhileRevalidate(request) : networkFirst(request)
    );
});
