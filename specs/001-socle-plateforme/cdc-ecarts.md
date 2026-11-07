# Écarts entre le réalisé et le cahier des charges (T147)

Liste à reporter dans le CDC. Les éléments « décision » sont des choix pris et consignés ; les éléments « reste » sont des manques réels. État au 2026-10-09.

## Écarts de pile et d'hébergement (décisions)
| CDC / plan initial | Réalisé | Référence |
|---|---|---|
| Laravel (PHP 8.4), Larastan niveau maximal, Pint, Horizon | PHP 8.1.34 **sans framework**, standards PSR, PHP-DI, PHPStan niveau 8 sans baseline (niveau 9 mesuré : 1 643 erreurs), php-cs-fixer | adr-0001, research R1, qualite-et-charge.md |
| Tests sur MySQL local | Tests sur SQLite en mémoire + suite MySQL jetable (jamais exécutée ici) | tests-mysql.md |
| Files Redis / Horizon | Files en base (`document_jobs`, `message_deliveries`) et cron | research R6, passage-gotenberg-redis.md |
| PDF Gotenberg en production | Dompdf (Gotenberg préparé derrière `PdfRenderer`) | passage-gotenberg-redis.md |
| PostgreSQL + RLS | MySQL 8, migrations portables ; migration documentée, non faite | migration-postgresql.md |
| Front Next.js ou Nuxt (question ouverte) | Vue 3 + Vite + TypeScript, PWA, servi statiquement à la racine du site (R12 disait `/app/`) | research R12, T012 |
| Déploiement par script SSH / `artisan` complet | FTP limité, pas de SSH, terminal restreint à `artisan`, `composer`, `git`, `ls`, `cd`, `cat`, `rm`, `unzip` ; cron à confirmer | research R11 |
| `quickstart.md` Laravel | Réécrit pour la pile réelle et automatisé | quickstart.md, `QuickstartTest` |

## Exigences non satisfaites ou partielles (reste)
| Exigence | État |
|---|---|
| FR-017 Mobile money (Must) | Client et passerelle Sungku codés et testés avec transport simulé ; **non activés**, dépend de la clé, du secret, du HTTPS et d'un webhook de test réels (integrations-externes.md) |
| FR-017 SMS réel (Must) | Adaptateur HTTP configurable par gabarit ; fournisseur et paramètres non fournis |
| FR-017 cartescolaire.cm, tutelle (Should) | Ports et export fichier générique (CSV/JSON, colonnes configurables) ; aucun format ni API fournis |
| FR-017 WhatsApp (Should), Moodle (Could) | Non commencés |
| FR-017 Comptabilité (Should) | Export CSV générique (journal, ventes, remises) ; format d'un logiciel donné non spécifié |
| FR-002 « contrôle des fuites » de mots de passe | Liste locale de mots de passe courants ou compromis (avec variantes) appliquée à l'activation et à la réinitialisation ; pas de base de fuites en ligne (aucun appel réseau) |
| PHPStan « niveau maximal » (T140) | Niveau 8 atteint, sans baseline ni ignore ; le niveau 9 donne 1 643 erreurs (types `mixed` des lignes PDO et des tableaux de configuration) réparties dans tous les modules : à traiter module par module |
| FR-014 PWA hors ligne, 3G | Front en cours ; vérification visuelle et réseau lent non faites (aucun navigateur ici) |
| FR-016 Journal inaltérable | Chaîne SHA-256 vérifiable ; l'immutabilité côté base (utilisateur MySQL sans `UPDATE`/`DELETE`) reste une action d'exploitation |
| FR-016 Export / suppression des données d'une personne | Sources People, Messaging, Identity, Audit branchées ; **les autres modules (Billing, Assessment, Attendance, Documents...) n'ont pas de source d'effacement par personne** : l'effacement est partiel, les pièces comptables étant à conserver légalement. Les fichiers téléversés ne sont pas dans l'export client |
| SC / 1 000 PDF en lot < 10 min ; p95 < 300 ms | Non mesurés sur la recette (aucun résultat k6) ; mesure locale en processus seulement |
| Sécurité ASVS niveau 2, scan ZAP | Non faits (security-review.md) |
| Sauvegarde hors serveur | Chiffrement et rétention faits ; la copie hors serveur est manuelle (FTP) |
| T106 exports CSV de toutes les listes, T132, T134, T137, T139 | Hors de ce lot (vérifiés par un autre agent) |

## Précisions ajoutées au CDC
- Période en cours dans les tuiles `/me/contexts` : trimestre ou semestre couvrant la date du jour, séquence à défaut, champ omis si inconnu.
- Double authentification désactivable par le serveur (`AUTH_2FA_REQUIRED`) : le front suit ce que `/me` renvoie.
- Export complet client : archive ZIP de JSON, une liste blanche de colonnes par table, mémoire bornée (lots), santé déchiffrée incluse uniquement dans cet export.
- Sauvegarde : format `scholaris-backup/1`, restauration atomique, `--force` requis après migration.
