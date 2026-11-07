# Back-office de l'administrateur de plateforme (super admin) : inventaire

Date : 2026-10-16. Exigence : `platform-applications.md` (décisions du 2026-10-09). Compte : client technique `platform`,
rôle `platform_admin`, double authentification obligatoire. Toute écriture exige une 2FA de moins de 15 minutes
(403 `two-factor-recent-required`, action rejouable après ressaisie du code) et est journalisée (journal `platform`
et journal du client concerné). La plateforme ne lit aucune donnée métier d'un client.

## 1. Diagnostic « côté back-office on ne voit rien » (demande ETS FOKA en attente)
- Pile serveur vérifiée de bout en bout (`tests/E2E/PlatformAccessJourneyTest.php`, `tests/Feature/Platform`) : une demande
  `pending` est bien listée par `GET /platform/access-requests?filter[status]=pending` et comptée au tableau de bord.
- Défaut trouvé et corrigé : la file était triée de la plus **ancienne** à la plus récente (curseur `id >`). Au-delà d'une
  page, une nouvelle demande n'apparaissait jamais sans « Voir plus ». Demandes et paiements sont désormais triés du plus
  récent au plus ancien. Test : `BackOfficeOperationsTest::testUneDemandeRecenteApparaitEnPremierePage…`.
- Autres causes possibles en production, hors code (à vérifier sur le serveur) :
  1. compte connecté créé par `admin:create` (super administrateur **d'un client**) et non par `platform:admin-create` :
     il voit le lanceur du client, jamais les écrans `/platform` (`GET /me` → `platform_admin: false`) ;
  2. même identifiant dans deux clients avec le même mot de passe : la connexion retient le premier compte dont le mot de
     passe correspond (`AuthService::login`) ; utilisez un identifiant propre au compte de plateforme ;
  3. migrations ou construction du front non déployées (`artisan migrate:status`, `scripts/build-web.ps1`).

## 2. Fonctionnalités et état (C créer, R lire, U modifier, D supprimer/archiver)

| Domaine | C | R | U | D / archiver | API | Écran |
|---|---|---|---|---|---|---|
| Tableau de bord | — | existant | — | — | `GET /platform/dashboard` | Tableau de bord |
| Clients | **livré** (création directe) | existant + fiche (état, contact, ville, région) | **livré** (nom, contact, ville, région, langue) | **livré** : suspendre, réactiver, archiver (motif ; aucune suppression) | `POST/PATCH /platform/clients…`, `/suspend`, `/reactivate`, `/archive` | Clients, fiche client |
| Établissements d'un client | **livré** (nursery, primary, secondary, higher) | **livré** (fermés compris) | **livré** (renommer) | **livré** : fermer / rouvrir | `/platform/clients/{t}/establishments…` | Fiche client |
| Administrateurs d'un client (super_admin) | **livré** : inviter (e-mail ou téléphone ; invitation e-mail, SMS non branché) | **livré** | **livré** : renvoyer l'invitation, débloquer, réinitialiser l'accès | **livré** : bloquer | `/platform/clients/{t}/administrators…` | Fiche client |
| Administrateurs de plateforme | **livré** (invitation ; 2FA imposée au rôle) + console `platform:admin-create` | **livré** | **livré** : renvoyer, débloquer, réinitialiser | **livré** : bloquer (jamais soi-même, jamais le dernier actif) | `/platform/administrators…` | Administrateurs |
| Catalogue et tarifs | — (manifestes) | existant | existant (libellé, statut, version, tarif, essai, grâce) | statut `retired` | `GET/PATCH /platform/applications` | Catalogue |
| Applications d'un client (droits d'usage) | existant (ouvrir) | existant | existant (tarif propre) | existant : suspendre, fermer, réactiver | `/platform/clients/{t}/applications…` | Fiche client |
| Demandes d'accès | public (formulaire) | existant (filtre état, **archivées**) | existant : accepter, refuser, complément | **livré** : archiver (refusée ou test), supprimer une archivée sans client | `POST …/{id}/archive`, `DELETE …/{id}` | Demandes, détail |
| Abonnements et essais | ouverture = essai 90 j | existant | existant (réactivation jusqu'au…) | suspension / fermeture | `platform:billing-run` | Fiche client, tableau de bord |
| Paiements et reçus | **livré** : paiement manuel espèces / virement avec reçu | existant + **reçu** plateforme | — | **livré** : annulation motivée (dernier paiement : couverture rétablie) | `POST /platform/payments`, `/{id}/cancel`, `GET /{id}/receipt` | Paiements |
| Relances et suspensions | automatique (J-14, J-3, J0, grâce) | via audit | — | — | `platform:billing-run` | Journal |
| Journal d'audit plateforme | — | **livré** (filtres action, auteur, objet, période ; curseur) | — | inaltérable | `GET /platform/audit` | Journal |

Chiffres : 11 domaines ; 28 routes ajoutées (54 au total dans le module) ; 7 manques livrés (a–g).

## 3. Règles
- Archivage d'un client : seulement s'il est suspendu ; ses applications sont fermées, ses données restent exportables
  (`tenant:export`). Les applications suspendues par la suspension du client portent le motif préfixé `[client] ` ;
  seules celles-ci sont réactivées avec le client.
- Établissement fermé : `establishments.closed_at` ; il disparaît de l'annuaire (`EstablishmentDirectory`), donc des
  contextes des utilisateurs ; la plateforme le voit toujours (`ClientProvisioning::establishmentsOf`).
- Administrateurs : contrat `Identity\Contracts\AccountAdministration` limité aux rôles `super_admin` (client) et
  `platform_admin` (plateforme) ; aucun autre compte d'un client n'est lisible.
- Migrations : `202610151700_platform_backoffice.sql`, `202610151701_platform_invitation_templates.sql`,
  `202610151702_organisation_establishment_closed.sql`.

## 4. Reste à faire
- Refuser la connexion des utilisateurs d'un client suspendu ou archivé (aujourd'hui : applications en lecture seule ou fermées).
- Envoi SMS des invitations (fournisseur à brancher) ; reçu PDF côté plateforme ; filtre du journal par client.
- Approbation à deux personnes pour l'ouverture (exigence initiale, non réalisée).
