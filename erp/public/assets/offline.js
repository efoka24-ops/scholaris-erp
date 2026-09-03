/*
 * Saisie hors-ligne : file d'attente et renvoi automatique.
 *
 * Dans un etablissement mal couvert, l'enseignant fait l'appel et saisit ses
 * notes sans reseau. Perdre cette saisie parce que l'envoi a echoue serait le
 * pire defaut possible du produit : le travail est refait, ou pas fait.
 *
 * Le formulaire est donc intercepte. Sans reseau, il est ecrit dans IndexedDB
 * et l'utilisateur voit immediatement que sa saisie est conservee sur
 * l'appareil. Au retour de la connexion, la file est rejouee dans l'ordre.
 *
 * Chaque envoi porte un jeton unique conserve jusqu'a confirmation. Une
 * requete peut atteindre le serveur sans que la reponse revienne — c'est
 * frequent en bordure de couverture — et le renvoi ne doit alors rien
 * dupliquer : le serveur reconnait le jeton et n'applique l'operation qu'une
 * seule fois.
 */

(function () {
    'use strict';

    var DB_NAME = 'scholaris-outbox';
    var STORE = 'operations';

    /* Seules ces pages acceptent la saisie hors-ligne. Le reste — paiements,
     * inscriptions, parametres — exige une confirmation immediate du serveur :
     * differer une ecriture qui engage de l'argent serait dangereux. */
    var OFFLINE_PATHS = [/^\/attendance/, /^\/grades/, /^\/discipline/];

    function isOfflineCapable(action) {
        var path = new URL(action, window.location.origin).pathname;

        return OFFLINE_PATHS.some(function (pattern) { return pattern.test(path); });
    }

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = window.crypto
                ? window.crypto.getRandomValues(new Uint8Array(1))[0] % 16
                : Math.floor(Math.random() * 16);
            var v = c === 'x' ? r : ((r % 4) + 8);

            return v.toString(16);
        });
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            var request = window.indexedDB.open(DB_NAME, 1);

            request.onupgradeneeded = function () {
                request.result.createObjectStore(STORE, { keyPath: 'token' });
            };

            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    function withStore(mode, work) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE, mode);
                var request = work(tx.objectStore(STORE));

                tx.oncomplete = function () { resolve(request ? request.result : undefined); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function enqueue(entry) {
        return withStore('readwrite', function (store) { return store.put(entry); });
    }

    function pending() {
        return withStore('readonly', function (store) { return store.getAll(); });
    }

    function forget(token) {
        return withStore('readwrite', function (store) { return store.delete(token); });
    }

    /* --- Bandeau d'etat ----------------------------------------------------
     * L'utilisateur doit savoir a tout moment si ce qu'il vient de saisir est
     * parti ou non. Un travail « peut-etre enregistre » ne vaut rien. */

    var banner = null;

    function showBanner(text, tone) {
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'offline-banner';
            banner.setAttribute('role', 'status');
            document.body.appendChild(banner);
        }

        banner.textContent = text;
        banner.dataset.tone = tone;
        banner.hidden = false;
    }

    function hideBanner() {
        if (banner) {
            banner.hidden = true;
        }
    }

    function refreshBanner() {
        return pending().then(function (items) {
            var count = items ? items.length : 0;

            if (count > 0) {
                showBanner(
                    count > 1
                        ? count + ' saisies enregistrees sur cet appareil, en attente d envoi.'
                        : 'Une saisie enregistree sur cet appareil, en attente d envoi.',
                    'pending'
                );

                return;
            }

            if (!navigator.onLine) {
                showBanner('Hors ligne. Vos saisies seront transmises au retour du reseau.', 'offline');

                return;
            }

            hideBanner();
        }).catch(function () {
            /* IndexedDB indisponible (navigation privee, quota) : l'application
             * reste utilisable en ligne, mieux vaut se taire que crier. */
        });
    }

    /* --- Interception des formulaires -------------------------------------- */

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || form.method.toUpperCase() !== 'POST') {
            return;
        }

        var action = form.getAttribute('action') || window.location.pathname;

        if (!isOfflineCapable(action)) {
            return;
        }

        // Le jeton rend l'envoi rejouable sans risque de doublon : il accompagne
        // aussi bien l'envoi immediat que l'envoi differe.
        var token = form.dataset.opToken || uuid();
        form.dataset.opToken = token;

        if (!form.querySelector('input[name="_op"]')) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_op';
            hidden.value = token;
            form.appendChild(hidden);
        }

        if (navigator.onLine) {
            return; // Envoi normal, par le navigateur.
        }

        event.preventDefault();

        var body = [];

        new FormData(form).forEach(function (value, key) {
            body.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
        });

        enqueue({
            token: token,
            action: new URL(action, window.location.origin).pathname,
            body: body.join('&'),
            savedAt: new Date().toISOString()
        }).then(function () {
            form.reset();
            delete form.dataset.opToken;
            showBanner('Saisie enregistree sur cet appareil. Elle partira au retour du reseau.', 'saved');
        }).catch(function () {
            showBanner('Impossible d enregistrer localement : ne quittez pas cette page.', 'error');
        });
    });

    /* --- Renvoi ------------------------------------------------------------ */

    var flushing = false;

    function flush() {
        if (flushing || !navigator.onLine) {
            return Promise.resolve();
        }

        flushing = true;

        return pending().then(function (items) {
            // Ordre chronologique : une note corrigee doit ecraser la note
            // initiale, et non l'inverse.
            (items || []).sort(function (a, b) { return a.savedAt < b.savedAt ? -1 : 1; });

            return (items || []).reduce(function (chain, item) {
                return chain.then(function () { return send(item); });
            }, Promise.resolve());
        }).catch(function () {
            /* On reessaiera au prochain retour de connexion. */
        }).then(function () {
            flushing = false;

            return refreshBanner();
        });
    }

    function send(item) {
        return fetch(item.action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: item.body,
            credentials: 'same-origin',
            redirect: 'follow'
        }).then(function (response) {
            // Renvoye vers la connexion : la session a expire pendant la
            // coupure. La saisie reste en file — la perdre serait pire que
            // demander une reconnexion.
            if (response.redirected && new URL(response.url).pathname === '/login') {
                showBanner('Session expiree. Reconnectez-vous : vos saisies sont conservees.', 'error');

                throw new Error('session');
            }

            if (response.status < 400) {
                return forget(item.token);
            }

            // 4xx : le serveur refuse cette saisie. La rejouer indefiniment ne
            // changerait rien et bloquerait la file derriere elle.
            if (response.status < 500) {
                showBanner('Une saisie a ete refusee par le serveur. Ressaisissez-la.', 'error');

                return forget(item.token);
            }

            throw new Error('serveur');
        });
    }

    window.addEventListener('online', function () { flush(); });
    window.addEventListener('offline', refreshBanner);

    document.addEventListener('DOMContentLoaded', function () {
        refreshBanner();
        flush();
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {
                /* Sans service worker, l'application reste utilisable en ligne. */
            });
        });
    }
})();
