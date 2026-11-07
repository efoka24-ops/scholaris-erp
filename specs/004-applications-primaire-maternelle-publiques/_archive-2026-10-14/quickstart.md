# Guide de validation — applications publiques Primaire/Maternelle

Ce guide est destiné à la future implémentation. **Aucun déploiement n'est autorisé par ce document.** Ne pas utiliser les identifiants des captures dans une commande, un test, un fichier ou le dépôt. Faire renouveler les secrets avant tout accès. Ne tester aucune donnée réelle d'enfant avant HTTPS et runtime maintenu.

## Préconditions

- Portes P0 résolues dans [plan.md](plan.md#portes-de-décision-et-clarification-obligatoire) et décisions d'équipe : dépôt/branche officielle, produit public vs privé, tenant pilote, modèle de rôles, sources MINEDUB et sécurité d'exploitation.
- PHP 8.1 local, Composer installé ; Node/npm selon la version retenue dans `web/package.json` ; MySQL de développement isolé ou SQLite de test, jamais la base de production pour tests/destructifs.
- Fixtures synthétiques seulement, sans vrais noms/photos/téléphones.

## Scénarios de validation attendus

1. **Isolation territoriale** : utilisateur inspecteur limité à un arrondissement reçoit les seules écoles de son territoire ; tentative autre tenant/territoire est refusée ; agrégat de petite cellule et déductions par filtres ne révèlent aucun élève.
2. **Inscription inclusive** : créer un élève sans référence d'acte et une inscription sans contribution APEE ; toutes deux restent admissibles, avertissement de régularisation séparé ; aucun état de paiement ne change le droit scolaire.
3. **Appel hors ligne** : inscrire une classe fictive, couper réseau, saisir présence matin/après-midi, synchroniser puis rejouer le lot ; aucun doublon/perte, UUID réutilisé avec payload différent provoque conflit conservé.
4. **Sortie maternelle** : enregistrer une personne autorisée, confirmer une remise hors ligne ; personne non autorisée refusée, journal et alerte créés, restriction valide respectée.
5. **Bulletin versionné** : importer un référentiel `example` et vérifier l'étiquette ; seul un référentiel officiellement approuvé permet un test de calcul métier ; publier/rectifier conserve l'original et sa trace.
6. **Questionnaire annuel** : incohérence provoque retour motivé ; validation suit rôles territoriaux ; version régionale figée ; correction postérieure crée une nouvelle version.
7. **APEE** : reçu et dépenses, dépense sans justificatif en attente ; état agrégé correct ; enseignants ne voient pas les familles contributrices/non-contributrices.
8. **Examen** : liste candidats soumise et validée est figée, ajout tardif motivé ; import résultats traçable ; système prépare mais n'émet aucun diplôme officiel.

## Contrôles locaux (après code, non exécutés pendant le cadrage)

Depuis la racine, appliquer les commandes de qualité de `specs/HANDOVER.md` uniquement sur environnement local/CI : PHPUnit, PHPStan, PHP-CS-Fixer sur les fichiers touchés ; puis depuis `web/` `vue-tsc`, ESLint, Vitest et build. La suite MySQL requiert une base de test jetable et des identifiants de test distincts de production. Les tests charge nationaux doivent tourner sur une plateforme cible séparée, pas sur un hébergement mutualisé partagé.

## Limites offline observées (14 octobre 2026)

- La file web découpe les envois en lots de **50 éléments maximum**. L'API Attendance accepte également au plus **50 séances par requête de synchronisation** et **500 lignes par séance**. Ces plafonds de requête ne mesurent pas la capacité totale de la file ni une durée de fonctionnement hors ligne.
- Le code consulté stocke les éléments en IndexedDB (avec repli mémoire si indisponible). Il ne démontre pas de chiffrement applicatif des données locales, de TTL/effacement automatique ni de révocation hors ligne. Ne jamais y stocker de données réelles dans ce périmètre ; utiliser uniquement des fixtures synthétiques.
- L'objectif de conservation/fonctionnement offline de **30 jours n'est pas validé** : aucune mesure de capacité, de rétention ou de sécurité sur cette durée n'est disponible. Aucune promesse de 30 jours n'est faite.

## Porte de pilote réel

Avant toute donnée réelle : TLS/HTTPS actif, runtime PHP maintenu, revue sécurité/loi 2024/017, accord écrit du commanditaire, jeux d'accès territoriaux validés, sauvegardes/restauration prouvées et clés renouvelées. Tant qu'un point manque, recette avec données synthétiques uniquement.
