# Recette de bout en bout IPES : instituts de Garoua

Test : `tests/E2E/IpesSeptentrionJourneyTest.php` (aides `tests/E2E/Support/BuildsIpes.php`, `BuildsIpesGrades.php`), pile réelle
(`RealStackTestCase` : vraie authentification, vrais rôles de la RoleMatrix, 2FA TOTP), deux clients :

1. Institut Supérieur du Septentrion de Garoua (ISSEG), Ouro Mal-Amadou, Garoua, Nord, BP 210 : régime LMD, tutelle universitaire.
2. Institut de Formation Professionnelle les Étoiles du Septentrion de Garoua, quartier Ouro Mal-Amadou : régime BTS.

Le directeur, les personnels, les étudiants, les notes et les numéros de téléphone sont fictifs.

Commande : `php vendor/bin/phpunit tests/E2E/IpesSeptentrionJourneyTest.php`. Résultat : 14 tests, 9 verts, 5 incomplets (défauts ci-dessous).

## Étapes vérifiées (les deux instituts)

- Demande d'accès publique `higher`, acceptation par l'administrateur de plateforme (2FA), activation de l'administrateur client,
  `/me/applications` : `higher` en essai ; le client n'accède pas à `/platform`.
- Année, école, département, filière (LMD / BTS), niveaux, groupe, autorisation MINESUP, tutelle (LMD), règlement créé puis figé.
- Maquette S1 de 30 crédits (Marie N.) saisie par le directeur d'école (R04), publiée par le directeur académique (R03).
  BTS : chaque UE doit être rattachée à une épreuve de l'examen national (EX-MAQ-03), contrôle respecté.
- Campagne, candidature, pièces, TST-02 (décision « admis » refusée sans pièce vérifiée, RG-ADM-1), admission, inscription.
- TST-01 : filière à autorisation expirée : ouverture de la campagne refusée (RG-ACA-1) et inscription directe refusée.
- TST-08 : grille 50 000 + 450 000 en 3 tranches, compte, bourse de mérite 20 % (double validation), total dû 410 000,
  paiement de 170 000 : inscription et tranche 1 soldées, solde 240 000, reçu émis ; validation de l'inscription et matricule.
- Lot I2 : fiche Personnel, affectation des enseignants par le chef de département (R05), créneau d'emploi du temps, appel du
  jour validé par l'enseignant, séance du cahier de textes saisie et signée ; TST-03 (enseignante non affectée : 403 à la
  création et à la saisie) ; saisie, soumission, validation, verrouillage avec 2FA récente (refusé sans, accepté après) ;
  TST-06 (modification d'une note verrouillée refusée, note inchangée).
- Lot I3 : jury créé (président R04, membres dont R21), portail du jury ; TST-07 : liste des résultats DOC-16 refusée avant
  signature du PV (409, RG-DEL-3).
- TST-12 : un administrateur, une scolarité ou un comptable d'un institut qui demande l'étudiant, l'inscription, la candidature
  ou le compte de l'autre reçoit 404 sans donnée, dans les deux sens ; les listes ne mélangent rien.

## Défauts

### D-01 : frais de dossier en espèces au guichet impossibles
- Requête : `POST /api/v1/higher/admissions/applications/{id}/fee-payment` `{"channel":"cash"}`.
- Scolarité (R07) : `409 Aucune caisse ouverte` ; R07 n'a pas `billing.cash` et ne peut pas ouvrir de caisse.
  Caissier (R14, caisse ouverte) : `404 Candidature introuvable` (pas de lecture des candidatures).
- Règle : RG-ADM (candidature au guichet avec frais de dossier, EX-ADM) ; matrice « Frais et paiements » R14 E.
  Aucun rôle de la RoleMatrix ne peut encaisser ces frais en espèces. Contournement du test : mobile money avec référence.
- Test : `testFraisDeDossierEnEspecesAuGuichet` (incomplet).

### D-02 : publication des notes impossible pour tous les rôles
- Requête : `POST /api/v1/higher/grade-assessments/{id}/publish` par la scolarité (R07).
- Réponse : `403 Permission requise : higher.grades.publish`.
- Cause : `MarkPolicy::PUBLISH = 'higher.grades.publish'` n'est dans aucune ligne de `RoleMatrix::ROLES` ni de
  `202610109000_roles_higher.sql` (les tests de Grades l'attribuent à la main).
- Règle : cycle des notes du CDC (saisie, validation, verrouillage, publication) ; la publication doit être exerçable par un rôle.
- Test : `testNotesLotI2Tst03Tst06` (incomplet après toutes ses assertions).

### D-03 : l'étudiant inscrit dans un groupe n'est pas sur la liste d'appel
- Requête : `POST /api/v1/attendance-sessions` (créneau du groupe G1) après validation de l'inscription IPES dans G1.
- Réponse : `expected_count = 0` ; ajouter l'étudiante donne `422 Cet élève n'est pas attendu à cet appel`.
- Cause : l'inscription IPES (`hi_enrolments`) ne crée pas d'inscription du socle (`enrollments`) ; il faut la créer à la main
  (`POST /enrollments`, `validate`) puis `POST /higher/enrolments/{id}/link`. De plus la candidature ne recueille pas la date de
  naissance : le socle traite alors l'étudiant en mineur et exige un garant (`422 Ajoutez un parent…`) avant la validation.
- Règle : inscription pédagogique dans un groupe (INS) et appel par séance (PRE) : pas de double saisie.
- Test : `testNotesLotI2Tst03Tst06` (contournement documenté, puis incomplet).

### D-04 : aucune source de notes pour les délibérations
- Requête : `POST /api/v1/higher/deliberation/juries/{id}/simulate`.
- Réponse : `409 Aucune source de notes n'est disponible pour ce groupe et cette période`.
- Cause : aucun module ne déclare `higher.deliberation.mark_sources.*` (Grades ne fournit pas de `MarkSource`).
- Conséquence : simulation, liste du rattrapage, PV et calculs de TST-04 (Marie N. : moyenne 11,55, MGP 2,40, 30 crédits) et
  TST-05 (EC 9,80 après rattrapage, moyenne 12,09, Assez bien) impossibles sur la pile réelle.
- Test : `testDeliberationLotI3Tst04Tst05Tst07` (incomplet après TST-07).

## Observations (sans blocage)
- Les routes de Teaching renvoient la ressource sans enveloppe `data` (les autres modules l'enveloppent).
- Deux types d'erreur pour la même exigence de 2FA récente : `two-factor-recent-required` (plateforme) et
  `second-factor-required` (verrouillage des notes).
- Les horloges de l'IPES (AcademicClock, TeachingClock…) suivent l'heure système et non l'horloge du noyau : le cahier de
  textes du test porte sur la séance de la semaine passée.

## Non couvert
- TST-04 et TST-05 (bloqués par D-04) ; signature du PV, publication des résultats et relevés.
- Vérification visuelle des documents PDF (reçu, carte) : seuls les numéros et statuts sont contrôlés.
