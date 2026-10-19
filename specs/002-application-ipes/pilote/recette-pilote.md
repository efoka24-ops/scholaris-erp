# Recette du pilote IPES : critères d'acceptation

Un seul cahier pour les deux instituts ; la recette est jouée **séparément dans chaque institut** (un client = un IPES). Résultat attendu : tous les critères « Bloquant » passent. Les parcours automatisés de référence sont `tests/E2E/IpesSeptentrionJourneyTest.php` et les tests `tests/Feature/Higher/**` ; la recette pilote les rejoue sur le client réel avec des **données de recette fictives** (jamais de données d'étudiants réels dans le dépôt).

Légende : B = bloquant pour la bascule, M = majeur (correction avant la fin du pilote), m = mineur.

## 1. Scénarios par rôle

| # | Rôle | Scénario | Résultat attendu | Gravité |
|---|---|---|---|---|
| R1 | Administrateur de l'institut | créer un compte de personnel, attribuer un rôle et un périmètre ; l'utilisateur ouvre l'application | accès limité à son périmètre ; 2FA exigée pour les rôles sensibles | B |
| R2 | Directeur d'école | saisir une maquette de 30 crédits ; (directeur académique) la publier | publication refusée si le total n'est pas de 30 crédits (LMD) | B |
| R3 | Scolarité | inscrire un admis : groupe, mode, contrat pédagogique, validation | matricule attribué une fois, jamais réattribué ; fiche et carte d'étudiant émises | B |
| R4 | Caissier / comptable | encaisser 170 000 FCFA sur le compte de l'exemple (410 000 dus en 3 tranches) | inscription et tranche 1 soldées, solde 240 000, reçu numéroté | B |
| R5 | Chef de département | affecter un enseignant à un EC et un groupe (CM/TD/TP, volume) | affectation visible de l'enseignant ; dépassement de volume refusé sans accord | B |
| R6 | Enseignant | faire l'appel, saisir le cahier de textes, saisir et soumettre les notes de son EC | uniquement ses EC et groupes (autre EC : 403 journalisé) | B |
| R7 | Responsable de filière / validation | valider puis verrouiller les notes (2FA) | verrouillage refusé sans 2FA récente ; note verrouillée non modifiable sans correction motivée | B |
| R8 | Directeur d'école / jury | tenir le jury, simuler, clôturer, faire signer le PV, publier | publication refusée avant la signature du PV | B |
| R9 | Étudiant | consulter ses notes publiées, son compte, ses documents, payer par mobile money | ne voit que ses données ; reçu disponible | B |
| R10 | Garant | consulter la situation financière ; notes seulement avec consentement | situation financière visible, notes masquées sans consentement | M |
| R11 | Service des examens | établir la liste des candidats à l'examen national et l'exporter | étudiant hors année de sortie refusé ; export tracé et alerte au directeur général | M |
| R12 | Direction générale | tableau de bord, alertes d'expiration, vacations validées | chiffres cohérents avec les écrans sources | M |
| R13 | Reprise de données | pour chacun des 5 jeux : simulation, correction, application, nouvelle simulation, annulation | simulation sans écriture ; relance sans doublon ; annulation propre ; soldes non annulables | B |

## 2. TST-01 à TST-12 rejoués

| TST | Cas | Attendu | Test automatisé de référence | Rejoué sur le client réel |
|---|---|---|---|---|
| TST-01 | inscrire dans une filière dont l'autorisation a expiré | refus RG-ACA-1 | `Higher/Academic/AuthorizationBlockTest` | ☐ |
| TST-02 | admettre sans pièce du baccalauréat vérifiée | « admis » impossible | `Higher/Admissions/AdmissionsTest` | ☐ |
| TST-03 | enseignant non affecté appelle la saisie d'un EC | 403, tentative journalisée | `Higher/Grades/MarkAccessTest` | ☐ |
| TST-04 | semestre de Marie N., note éliminatoire 7 | moyenne 11,55 ; MGP 2,40 ; 30 crédits ; UE2 par compensation | `tests/Unit/Higher/Deliberation` | ☐ |
| TST-05 | note éliminatoire 8 et rattrapage à 11 | EC 9,80 ; moyenne 12,09 ; 30 crédits ; Assez bien | idem | ☐ |
| TST-06 | modifier une note verrouillée sans procédure | refus ; note inchangée | `Higher/Grades/GradeCorrectionTest` | ☐ |
| TST-07 | publier avant signature du PV | refus (RG-DEL-3) | `Higher/Deliberation` | ☐ |
| TST-08 | payer 170 000 FCFA sur l'échéancier de l'exemple | inscription et tranche 1 soldées, reçu | `Higher/Billing/InstalmentExampleTest` | ☐ |
| TST-09 | ajouter à la liste HND un étudiant hors année de sortie | refus (RG-EXN-1) | `Higher/NationalExams/CandidateListTest` | ☐ |
| TST-10 | vérifier un relevé annulé par son QR | page « annulé » avec motif | `Higher/Documents/HigherDocumentsTest` | ☐ |
| TST-11 | calculer les vacations de l'exemple | 43,5 HETD ; 261 000 FCFA bruts | `Higher/Vacations` | ☐ |
| TST-12 | utilisateur d'un autre client avec un identifiant d'étudiant valide | 404, aucune donnée | `E2E/IpesSeptentrionJourneyTest`, `Higher/Core` | ☐ |

Pour le client réel, TST-12 se joue avec **deux comptes** : un compte ISSEG demande un identifiant IFPES, et inversement (404 dans les deux sens).

## 3. Critères non fonctionnels

| Critère | Seuil | Mesure | Gravité |
|---|---|---|---|
| Réponse des écrans courants (listes, fiche, saisie de notes) | moins de 2 s au 95e centile sur 2 000 étudiants, réseau mobile | mesure chronométrée sur 20 opérations | M |
| Simulation d'un fichier de 2 000 lignes | moins de 60 s ; aucune écriture | test `StudentsImportTest::testDeuxMilleLignes…` puis essai réel | M |
| Application d'un fichier de 2 000 lignes | moins de 3 min, tout ou rien | idem | M |
| Saisie des notes hors ligne | notes gardées sur l'appareil, envoyées au retour du réseau, conflits jamais écrasés (409) | essai téléphone en mode avion | M |
| Envoi des messages | e-mails reçus ; SMS : non branchés (attendu : en échec ou en file) | boîte de test | m |
| Tâches planifiées | toutes exécutées 24 h sans erreur (runbook §6) | journaux | B |
| Accessibilité | écrans courants utilisables au clavier, contrastes suffisants | revue manuelle | m |
| Sécurité | HTTPS partout ; 2FA pour les rôles sensibles ; isolation entre clients | TST-12 + revue des comptes | B |

## 4. Sauvegarde et restauration testées

| Étape | Résultat | Date | Par |
|---|---|---|---|
| `backup:run` produit un fichier `.sbk` | ☐ | | |
| copie hors serveur téléchargée | ☐ | | |
| `backup:restore … --force` sur une **base de test** : nombres de lignes contrôlés | ☐ | | |
| `audit:verify <client>` : chaîne intacte après restauration | ☐ | | |
| temps total de restauration relevé | ____ min | | |

La bascule est **refusée** tant que ce tableau n'est pas entièrement coché.

## 5. Critères d'acceptation du pilote

Le pilote est accepté quand, pour chaque institut :

1. tous les scénarios B du §1 passent ;
2. TST-01 à TST-12 sont rejoués et passent ;
3. aucun défaut bloquant ouvert au registre (§6) ; défauts majeurs : plan de correction daté ;
4. la sauvegarde a été restaurée avec succès (§4) ;
5. les rôles ont suivi la formation (`formation/plan-de-formation.md`) avec évaluation de fin de séance réussie ;
6. la direction de l'institut signe le procès-verbal d'acceptation.

## 6. Registre des défauts

Un défaut = une ligne. Gravité : B bloquant, M majeur, m mineur. Statut : ouvert, corrigé, vérifié, refusé (avec motif). Ne jamais coller de données personnelles réelles dans le registre.

| Réf. | Date | Institut | Rôle | Écran / route | Étapes pour reproduire | Attendu | Obtenu | Gravité | Statut | Correction (version) | Vérifié par |
|---|---|---|---|---|---|---|---|---|---|---|---|
| D-P01 | | | | | | | | | ouvert | | |
| D-P02 | | | | | | | | | | | |

Rappel : les défauts D-01 à D-04 de la recette Septentrion (`recette-septentrion.md`) sont à vérifier comme corrigés avant la bascule : frais de dossier en espèces au guichet, publication des notes, étudiant sur la liste d'appel, source de notes des délibérations.
