# Cahier des charges IPES : fin du document (complément)

Source : CDC_Application_IPES.pdf complet (31 pages), fourni par l'utilisateur le 2026-10-13. Ce fichier reprend les parties qui manquaient dans [cdc-ipes.md](cdc-ipes.md), tronqué après NOT-11. **Le PDF fait foi.** La mention « tronqué » de cdc-ipes.md est levée par ce complément.

## Notifications supplémentaires
- NOT-11 (précision) : saisie des notes en retard ; destinataires enseignant et chef de département ; canaux e-mail et notification.
- NOT-12 Résultats publiés : étudiants concernés ; SMS, notification.
- NOT-13 Convocation au rattrapage ou à la soutenance : étudiant ; SMS, e-mail.
- NOT-14 Heures validées et vacation payée : enseignant ; SMS.
- NOT-15 Autorisation ou convention de tutelle bientôt expirée : directeur général et directeur académique ; e-mail.

## Conception technique : cadre
Le CDC écrit PHP 8.4, Laravel 13, PostgreSQL 16, Redis et Horizon, Gotenberg, dedoc/scramble, Sanctum et Passport. **Écart voulu** : l'implémentation réelle est PHP 8.1 sans framework (ADR-0001), MySQL 8, Dompdf, jetons du socle. Le module vit dans `src/Modules/Apps/Higher` (le CDC écrit app/Modules/Apps/Higher) et n'appelle que les Contracts du socle.

Structure du module (CDC) : Academic (ACA), Admissions (ADM), Enrolment (INS), Curriculum (MAQ), Teaching (ENS), Grades (NOT), Deliberation (DEL), Internships (STG), NationalExams (EXN), Tutelage (TUT), Documents (DOC-01 à 32), Policies, Http, Tests.

## Modèle de données propre (tables hi_*, toutes avec tenant_id et establishment_id)
- hi_schools, hi_departments : écoles internes et départements (id, code, nom, directeur).
- hi_programs : école, code, régime, mode, langue, durée.
- hi_authorizations : programme, référence, date, niveaux, fin, document (RG-ACA-1).
- hi_tutelages : programme, université, année, convention (RG-ACA-2).
- hi_regulations : programme, année, paramètres JSON, version, figé.
- hi_curricula, hi_units, hi_elements : version, semestre, crédits, coefficients, volumes.
- hi_campaigns, hi_applications : filière, places, prérequis ; statut, pièces, décision.
- hi_enrolments : personne, programme, niveau, année, statut, matricule.
- hi_learning_contracts : inscription, UE inscrites, UE capitalisées, dettes.
- hi_assignments : enseignant, élément, groupe, type, volume.
- hi_sessions_log : affectation, date, contenu, émargements (cahier de textes).
- hi_assessments, hi_marks : élément, type, poids ; valeur, code, état, version.
- hi_mark_changes : note, ancienne, nouvelle, motif, pièce, validations (RG-NOT-2).
- hi_juries, hi_deliberations : membres ; session, état, empreinte du PV, signatures.
- hi_results : inscription, UE ou semestre, moyenne, lettre, crédits, décision (résultats figés).
- hi_internships, hi_defenses : entreprise, tuteur, convention ; jury, note, PV.
- hi_national_sessions, hi_candidates : type, spécialité ; inscription, statut, résultat.

Remarque de l'analyse : plusieurs de ces tables (notes, corrections, jurys, résultats) doublonnent des tables du socle (module Assessment) ; le socle les fournit, Higher les étend sans les dupliquer.

## API spécifiques (préfixe /api/v1/higher ; jeton, X-Establishment-Id, politique d'autorisation)
| ID | Méthode et chemin | Description | Rôles |
|----|-------------------|-------------|-------|
| API-01 | GET /programs | Filières avec autorisation et tutelle valides | Tous rôles internes |
| API-02 | POST /authorizations | Enregistrer une autorisation de filière | R02, R20 |
| API-03 | POST /campaigns/{id}/applications | Déposer une candidature | Candidat, R07 |
| API-04 | POST /applications/{id}/decision | Décision d'admission | R04 |
| API-05 | POST /enrolments | Inscription administrative | R07 |
| API-06 | GET /enrolments/{id}/contract | Contrat pédagogique | R07, R12 (le sien) |
| API-07 | PUT /curricula/{id} | Modifier une maquette non publiée | R03, R05 |
| API-08 | POST /session-logs | Séance du cahier de textes et émargement | R09, R10 |
| API-09 | PUT /assessments/{id}/marks | Saisir ou importer les notes d'une évaluation | R09, R10 (affectés) |
| API-10 | POST /assessments/{id}/submit | Soumettre la saisie | R09, R10 |
| API-11 | POST /assessments/{id}/lock | Valider et verrouiller (double authentification) | R05 |
| API-12 | POST /marks/{id}/changes | Demander une correction de note | R09, R10 |
| API-13 | POST /deliberations/{id}/simulate | Simuler les résultats | R07, R04 |
| API-14 | POST /deliberations/{id}/sign | Signer le PV (double authentification) | R21 |
| API-15 | POST /deliberations/{id}/publish | Publier les résultats | R07 |
| API-16 | GET /students/me/results | Résultats publiés de l'étudiant | R12 |
| API-17 | POST /national-sessions/{id}/candidates/export | Exporter la liste des candidats BTS ou HND | R07, R02 |
| API-18 | POST /defenses | Programmer une soutenance | R16 |
| API-19 | GET /verify/{token} | Vérifier un document (public, limité en débit) | Public |

## Sécurité propre à l'application
- **Accès aux notes (API1, API5)** : politique MarkPolicy : un enseignant n'accède qu'aux évaluations de ses affectations ; un étudiant qu'à ses résultats publiés ; tests automatiques de refus croisés.
- **Opérations sensibles** : nouvelle saisie du code de double authentification pour verrouiller, corriger, signer un PV, publier, exporter la liste des candidats nationaux, modifier un règlement figé.
- **Intégrité des notes** : contrôle de version optimiste contre les écrasements ; journal avant et après dans le journal inaltérable du socle.
- **Intégrité des PV** : empreinte SHA-256 du contenu enregistrée à chaque signature ; toute différence ultérieure est signalée.
- **Vérification des documents** : jeton aléatoire de 128 bits non séquentiel ; page publique limitée à 30 requêtes par minute et par adresse ; aucune note affichée.
- **Saisie hors ligne** : lots signés par l'appareil, rejoués avec contrôle de version ; un conflit n'est jamais résolu automatiquement.
- **Exports nominatifs** : réservés aux rôles habilités, journalisés, filigranés avec le nom de l'exportateur et la date.
- **Séparation des tâches** : celui qui saisit une note ne la verrouille pas ; celui qui demande une correction ne la valide pas.
- **Accès externes (R22, R23)** : comptes nominatifs à durée limitée, lecture seule, journal des consultations.

## Cycles de vie
- **Candidature** : brouillon → soumise → complète → en évaluation → admis, admis sous réserve, liste d'attente ou refusé → inscrit.
- **Inscription** : en cours → inscrit (ou inscrit sous réserve financière) → suspendu, abandon, exclu, transféré ou diplômé.
- **Saisie de notes** : brouillon → soumise → validée → verrouillée → publiée ; correction possible après verrouillage (demande motivée, chef de département puis directeur d'école).
- **Délibération** : préparée → simulée → en séance → signée → publiée ; réouverture motivée possible.
- **Candidat à l'examen national** : éligible → dossier complet → exporté → convoqué → résultat importé.

## Tests d'acceptation du CDC
| ID | Scénario | Résultat attendu |
|----|----------|------------------|
| TST-01 | Inscrire un étudiant dans une filière dont l'autorisation a expiré | Refus avec message RG-ACA-1 |
| TST-02 | Admettre un candidat sans pièce du baccalauréat vérifiée | Décision « admis » impossible |
| TST-03 | Un enseignant appelle l'API de saisie d'un EC qui ne lui est pas affecté | 403 ; tentative journalisée |
| TST-04 | Calculer le semestre de Marie N. avec note éliminatoire 7 | Moyenne 11,55 ; MGP 2,40 ; 30 crédits ; UE2 acquise par compensation |
| TST-05 | Même calcul avec note éliminatoire 8 et rattrapage à 11 | Note EC 9,80 ; moyenne 12,09 ; 30 crédits ; mention Assez bien |
| TST-06 | Modifier une note verrouillée sans procédure | Refus ; la note d'origine reste inchangée |
| TST-07 | Publier les résultats avant signature du PV | Refus |
| TST-08 | Payer 170 000 FCFA sur l'échéancier de l'exemple | Inscription et tranche 1 soldées, reçu émis |
| TST-09 | Ajouter à la liste HND un étudiant non inscrit en année de sortie | Refus (RG-EXN-1) |
| TST-10 | Vérifier un relevé annulé par son QR | Page « annulé » avec motif |
| TST-11 | Calculer les vacations de l'exemple | 43,5 HETD ; 261 000 FCFA bruts |
| TST-12 | Utilisateur d'un autre client avec un identifiant d'étudiant valide | 404 ; aucune donnée renvoyée |

## Feuille de route, charge, risques
Lots : I1 Entrée et inscription (S10-S15) : ACA, ADM, INS, MAQ, FIN, DOC-01 à 06 et 27 à 30, portail étudiant (inscription, paiement) · I2 Enseignement et notes (S16-S21) : EDT, ENS (affectations, cahier de textes), PRE, NOT, DOC-07 et 08, portail enseignant · I3 Résultats et fin d'études (S22-S26) : DEL, vacations (paiement), STG, EXN, TUT, PIL, DOC-09 à 26, 31, 32 · I4 Recette et pilote (S27-S28) : IPES pilote, reprise des données, formation, bibliothèque en option. Charge estimée : 404 jours-personne.

Risques : règlements de tutelle différents d'une filière à l'autre (paramétrable par filière et année) · résistance des enseignants à la saisie en ligne (téléphone, hors ligne, import Excel, paiement des vacations lié à la saisie, RG-ENS-5) · formats MINESUP et tutelle qui changent (exports par modèles paramétrables) · reprise de notes antérieures incomplètes (limitée aux années en cours et aux UE capitalisées) · pression pour modifier les notes après délibération (réouverture uniquement par EX-DEL-08, double validation, journal inaltérable, alerte au directeur général).

## Questions ouvertes du CDC (non tranchées)
- IPES pilote : lequel, avec quelles écoles internes et quelles universités de tutelle ?
- Règlements des études et grilles de lettres officielles des universités de tutelle concernées ?
- Formats exacts attendus par le MINESUP pour les listes BTS et HND ?
- Taux de vacation par grade et retenues applicables, à fournir par le comptable ?
- Signature électronique : simple (code de confirmation) ou certificat qualifié ?
- Bibliothèque : dans la version 1 ou plus tard ?
