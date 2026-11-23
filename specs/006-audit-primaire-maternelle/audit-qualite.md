# Audit : contrôles exécutés (2026-10-19, sans modification)

| Commande | Résultat |
|---|---|
| `php vendor/bin/phpunit tests/Feature/PublicNursery tests/Feature/PublicSchools tests/Isolation` | **vert** : 143 tests, 2 708 assertions (2 min 53 s) |
| `php vendor/bin/phpunit tests/Feature/Platform` (contrôle complémentaire) | **1 échec** : `AccessRequestTest::testLeCataloguePublicListeLesApplicationsNonRetirees` (clé `public_nursery` en plus) |
| `php vendor/bin/phpunit tests/ApiContract tests/Architecture` (contrôle complémentaire) | **7 échecs sur 5 602** : 6 viennent de la Maternelle publique ou des territoires, 1 du socle Identity |
| `vendor/bin/phpstan analyse --memory-limit=2G src/Modules/Apps/PublicNursery src/Modules/Apps/PublicPrimary` | **51 erreurs**, toutes dans `PublicNursery` (12 fichiers) ; 0 dans `PublicPrimary` |
| `npx vue-tsc --noEmit` (dans `web/`) | **10 erreurs**, toutes dans `src/views/public-nursery/TransitionDecisionsView.vue` (`choice` et objet « possibly undefined », lignes 54 à 123) |
| `npx eslint src/views/public-nursery src/apps` | **1 erreur** : `vue/no-v-html` dans `TransitionLiaisonView.vue:102` |

## Détail des échecs de contrat d'API
1. `POST /public-nursery/development/frames` avec un corps vide → **500** au lieu de 422. Il échoue dans `AllRoutesContractTest` et `WriteRoutesContractTest`. C'est cohérent avec l'erreur phpstan `argument.type` de `FrameService::normalize()` (ligne 110 : une chaîne est passée là où un tableau est attendu).
2. `GET /public-nursery/development/{frames,lifebook,needs}` : listes sans pagination par curseur (`meta` absent), contrairement à la convention du socle.
3. `GET /territories` : `meta.next_cursor` absent (socle Organisation, travail non commité).
4. `POST /auth/login-authenticator` : route publique absente de la liste attendue (socle Identity, travail non commité d'une autre session, hors périmètre primaire).

## Lecture
- La suite complète `php vendor/bin/phpunit` n'est **plus verte** (5 130 tests verts selon HANDOVER §7) : il y a au moins 8 échecs connus.
- La règle « phpstan 0 erreur, sans baseline » et la chaîne front (`vue-tsc`, `npm run build`) sont violées par du code non commité. Aucun déploiement n'est possible en l'état.
- Le cloisonnement n'est vérifié que par permission : `tests/Isolation/PublicNurseryScopeTest.php` contient 1 test. Il n'y a pas de test de suspension (423) sur une route réelle.
- SQL : les migrations publiques passent le contrôle des mots réservés (tests d'architecture verts) ; aucun passage sur MySQL réel (`phpunit.mysql.xml`, `artisan db:check`) n'a été fait.
