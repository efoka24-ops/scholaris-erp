# Spécification : application Primaire publique et modules communs

Date : 2026-10-15. Statut : projet. Source : [../cdc-public.md](../cdc-public.md) (fait foi ; les ID EX-, RG-, DOC-, NOT-, API-, TST- sont ceux du CDC). Couverture du socle : [couverture.md](couverture.md). Questions : [questions.md](questions.md) (Qn).

Toute règle chiffrée du CDC (âges, seuils, deux tiers, politique de passage, 5 élèves, 30 jours…) est une **valeur par défaut paramétrable au niveau national**, à valider par l'Inspection générale (hypothèse du CDC). Aucune n'est codée en dur.

## 1. Acteurs
R01-R21 du CDC. Trois niveaux d'accès :
- **National** (R01-R04, R21) : rôles de niveau client ; R01, R03, R19 ne voient que des agrégats ; R02 voit les listes d'examen ; R04 gère référentiels et modèles.
- **Territorial** (R05 région, R06 département, R07 arrondissement, R08 écoles attribuées, R18, R19, R20) : rôle affecté à un territoire (PP2). Nominatif seulement jusqu'au département (RG-TER-3) et pour une action prévue, journalisée (RG-TER-2).
- **École** (R09-R17) : rôle affecté à un établissement, éventuellement restreint à une classe (R10) ; R14 n'a pas de compte (Maternelle).

Matrice : celle du CDC (« Matrice des droits ») traduite en permissions `public_schools.*` et `public_primary.*` dans [plan.md](plan.md) §5. Les élèves n'ont pas de compte.

## 2. User stories priorisées

### P1 (lot P1 : école et présence) — Must
- **US-01 Registre de l'école** (R09, R07, R21). Le directeur tient la fiche et les infrastructures annuelles ; l'inspecteur valide ; l'administrateur rattache l'école à son arrondissement. *Exigences* : EX-ECO-01, 02, 05, RG-TER-1, RG-TER-5. *Acceptation* : une école sans référence d'acte n'est pas comptée dans les statistiques officielles ; la clôture du questionnaire fige l'année d'infrastructures.
- **US-02 Inscrire un élève** (R09, R20). Inscription avec ou sans acte de naissance, contrôle de doublon territorial, identifiant national attribué une fois. *Exigences* : EX-ELV-01..04, RG-ELV-1..3, NOT-01, API-02. *Acceptation* : TST-01, TST-02 ; deux saisies identiques (nom, date, parents) proposent la fiche existante ; un âge hors norme (paramètre 6 ans au 31/12 en SIL) avertit sans refuser.
- **US-03 Transférer un élève** (R09 origine, R09 accueil, R06). *Exigences* : EX-ELV-05, RG-ELV-4, RG-ELV-5, DOC-03, API-03. *Acceptation* : à l'accueil, l'inscription d'origine est close à la date d'accueil ; l'école d'origine garde la fiche en lecture seule ; l'école d'accueil ne reçoit que identité, parcours, dernier bulletin.
- **US-04 Classes et effectifs** (R09). Classes jumelées, double flux, titulaire daté, alerte d'effectif. *Exigences* : EX-CLA-01..03. *Acceptation* : une classe de 61 élèves déclenche l'alerte (seuil 60 par défaut).
- **US-05 Appel en 2 minutes, hors ligne** (R10). Tous présents par défaut, demi-journées, correction le jour même, ensuite par le directeur avec motif. *Exigences* : EX-PRE-01, 02, 05, RG-PRE-1, RG-PRE-3, NOT-02, NOT-03, NOT-12, API-04, API-05. *Acceptation* : TST-04 ; 5 jours d'absence en un mois (paramètre) alertent directeur et famille ; aucune sanction automatique.
- **US-06 Présence des enseignants** (R09, R07). Arrivée/départ déclarés, contrôle aléatoire de l'inspection, absences et permissions avec pièce, « classe sans enseignant », synthèse mensuelle. *Exigences* : EX-PRE-03, 04, RG-PRE-2, DOC-22, NOT-13.
- **US-07 Personnel de l'école** (R09, R07, R06). Fiche, statut public, affectations et mutations, besoins. *Exigences* : EX-PER-01, 02, 04, RG-PER-2. *Acceptation* : l'inspection ne voit que statut, grade, affectations, présences.
- **US-08 Communiquer avec les familles** (R09, R10, R07, R06). SMS par classe/niveau/école, annonces territoriales, refus des non essentiels, portail parent. *Exigences* : EX-COM-01, 04, 05, RG-COM-1, RG-COM-2, NOT-14. *Acceptation* : un parent qui a refusé les SMS non essentiels reçoit quand même NOT-14.
- **US-09 Supervision territoriale** (R05-R07, R01, R19). Écoles d'un territoire, agrégats, journal des consultations nominatives. *Exigences* : RG-TER-2, RG-TER-3, API-01, API-12. *Acceptation* : TST-05, TST-10.
- **US-10 Synchronisation par lots et téléphone partagé** (R09, R10, R07). *Exigences* : évolution « synchronisation hors ligne par lots », NF-P4, sécurité « Hors-ligne » et « Comptes partagés interdits ». *Acceptation* : TST-04 ; un appareil révoqué voit ses lots refusés (401) ; un lot rejoué n'est appliqué qu'une fois.

### P2 (lot P2 : évaluation et finances)
- **US-11 Référentiel et unités** (R04, R21). Référentiel national versionné par sous-système et niveau ; calendrier des unités (8 par défaut). *Exigences* : EX-EVA-01, 02.
- **US-12 Saisir les critères sur téléphone** (R10). *Exigences* : EX-EVA-03, 04, 05, RG-EVA-4, API-06, DOC-11. *Acceptation* : TST-03 avec l'exemple Paul E. (CE1 B, unité 3) : Français ECA (C1 2/3, C2 2/3, C3 1/3), Mathématiques A (3/3, 2/3, 2/3), English NA (1/3, 1/3, 1/3) ; remédiation « Français, C3 Cohérence » et « English C1, C2, C3 » ; message au parent pour English. Un élève absent à toutes les occasions d'une discipline reçoit « non évalué ».
- **US-13 Bulletins** (R10, R09, R13). *Exigences* : EX-EVA-06, 07, RG-EVA-1..3, DOC-08..10, NOT-04, API-07, RG-DOC-3..6. *Acceptation* : publication par le directeur, puis figé ; modification d'une mention par le directeur seul, avec motif, réédition « Rectificatif » au même numéro ; ni rang ni APEE sur le bulletin ; PDF parent < 200 Ko.
- **US-14 Conseil des maîtres et passage** (R09, R10, R07). *Exigences* : EX-PAS-01..04, RG-PAS-1, RG-PAS-2, DOC-12, API-08. *Acceptation* : proposition automatique selon la politique nationale ; un deuxième redoublement dans le même niveau exige l'avis de l'inspecteur.
- **US-15 APEE** (R15, R09, R13). *Exigences* : EX-APE-01..06, RG-APE-1..4, RG-PER-1, DOC-24, DOC-25, DOC-27, NOT-10, API-14. *Acceptation* : TST-09 et l'exemple chiffré : attendu 420 × 5 000 = 2 100 000 ; encaissé 1 720 000 ; taux 81,9 % ; dépenses 450 000 + 300 000 + 250 000 = 1 000 000 ; solde 720 000 FCFA ; les 76 familles non contributrices gardent bulletins et documents ; la liste des non-contributeurs n'est visible que du trésorier et du président.
- **US-16 Questionnaire statistique** (R09, R07, R06, R05, R03, R20). *Exigences* : EX-STA-01..06, RG-STA-1, RG-STA-2, RG-TER-4, DOC-23, NOT-11, API-11. *Acceptation* : TST-08 ; gel à la validation régionale, correction = nouvelle version motivée.

### P3 (lot P3 : examens et supervision)
- **US-17 Listes d'examen** (R09, R07, R06, R02). *Exigences* : EX-EXA-01..04, RG-EXA-1, RG-EXA-2, DOC-13..15, NOT-08, API-09. *Acceptation* : TST-07 ; NOT-08 en janvier de l'année d'examen.
- **US-18 Résultats et attestations** (R06, R02, R09). *Exigences* : EX-EXA-05, 06, RG-EXA-3, DOC-16, DOC-17, NOT-09, API-10. *Acceptation* : l'application ne délivre jamais le diplôme ; les attestations attendent la signature du délégué départemental.
- **US-19 Transfert vers le Secondaire** (R09). EX-EXA-07 (Should ; dépend de l'existence de l'application Secondaire, Q18).
- **US-20 Inspection** (R07, R08, R09, R10). *Exigences* : EX-INS-01..05, DOC-26. *Acceptation* : grille remplie hors ligne ; recommandations suivies jusqu'à la visite suivante ; comptage sur place comparé au registre (O1).
- **US-21 Cantine et santé scolaire** (R17, R18). *Exigences* : EX-CAN-01..03 (Could/Should).
- **US-22 Tableaux de bord territoriaux et USSD/voix** (R01-R07, R19, R13). *Exigences* : EX-STA-05, DOC-28, EX-COM-02, EX-COM-03, API-12, API-15, NF-P3.
- **US-23 Saisie par l'agent de l'inspection** (R20). EX-ELV-07, RG-STA-2.

## 3. Exigences fonctionnelles
Les exigences sont celles du CDC, reprises par leur ID ; [couverture.md](couverture.md) §2 donne pour chacune le statut et ce qui manque. Précisions testables :

| Réf. | Précision |
|---|---|
| EX-ELV-02 | Identifiant attribué à la première inscription dans le client, jamais réattribué ni modifié, conservé à la radiation, transmis par le transfert. Format : Q6 (défaut : `<code région>-<année>-<séquence 7 chiffres>`, paramètre). |
| EX-ELV-03 | Doublon probable : même nom normalisé + même date de naissance + au moins un parent commun, dans le département de l'école (paramètre `public_schools.duplicates.territory_level`, défaut `departement`). Le directeur peut passer outre avec motif, journalisé. |
| EX-ELV-06 | Abandon présumé : absence continue de `public_schools.dropout.days` jours ouvrés (défaut 20, à confirmer Q11). |
| EX-CLA-03 | Seuil `public_schools.class_size.alert` (défaut 60). |
| EX-PRE-01 | Mode demi-journée ; `public_schools.attendance.correction_until` = fin de journée (défaut 23:59 heure locale). |
| EX-PRE-05 | `public_schools.absence.repeated_days` (défaut 5) dans `public_schools.absence.window` (défaut mois civil). |
| EX-EVA-04 | Paramètres `public_primary.evaluation.*` : `occasions` (3), `mastery_min_success` (2), `mention_rule` (`all_minimal=A`, `at_least_one=ECA`, `none=NA`), `perfection_only_if_A` (vrai), `trimester_mention` (`last_unit`), `annual_mention` (`final_integration_else_last_unit`). La stratégie lit ces valeurs ; les tests les fournissent. |
| EX-PAS-01 | Politique `public_primary.promotion.policy` (version nationale datée) : disciplines clés par sous-système, effets « passe », « passe avec remédiation », « examen par le conseil ». |
| EX-EXA-01 | Classe d'examen : `CM2` (francophone), `Class 6` (anglophone) (paramètre par sous-système). |
| Sécurité | Masquage des agrégats < `public_schools.disclosure.min_cell` (défaut 5) pour R01, R03, R05, R19 et les rôles de supervision. |
| NF-P6 | Plafond `public_schools.sms.cap_per_pupil_year` (défaut à fixer, Q15) ; au-delà, seuls les messages essentiels partent. |

## 4. Exigences non fonctionnelles
NF-P1 à NF-P6 du CDC. Adaptations à l'hébergement réel (écart voulu, ADR-0001) : PHP 8.1 sans framework, MySQL 8, Dompdf, tâches cron (`queue:send`, `documents:process-jobs`, nouvelle `reporting:aggregate-territories` chaque nuit, nouvelle `sync:process` chaque minute). L'application mobile du CDC est d'abord la PWA existante (Q2). Mesures de recette : tests de charge `tests/Load` et `scripts/loadtest/k6-api.js` sur `POST /sync/batches` et `GET /public/dashboards/{territory}`.

## 5. Critères de succès
| ID | Critère | Mesure |
|---|---|---|
| SC-01 | Les 10 tests d'acceptation TST-01..TST-10 passent en E2E (`tests/E2E/PublicSchools`, `tests/E2E/PublicPrimary`) | CI |
| SC-02 | Aucune route d'écriture n'échappe au `WriteGate` ni au contrôle de périmètre | `tests/Architecture/PublicSchoolsConventionsTest` |
| SC-03 | Aucune table d'application ne contient de colonne liant inscription et APEE (RG-ELV-1) ; aucun document d'élève ne contient de variable APEE (RG-DOC-3) | test d'architecture sur les migrations et les modèles |
| SC-04 | Aucun seuil officiel codé en dur dans `Domain/` (liste de littéraux interdits hors 0 et 1) | test d'architecture |
| SC-05 | Un délégué régional n'obtient jamais de donnée nominative hors listes d'examen | `tests/Isolation/PublicTerritoryTest` |
| SC-06 | Lot d'une classe de 60 élèves sur une semaine (appel + critères) < 50 Ko compressé | test unitaire de taille |
| SC-07 | Agrégats d'un département (≈ 500 écoles simulées) servis en < 3 s | test de charge |
| SC-08 | PDF parent (DOC-09) < 200 Ko | test de document |
| SC-09 | Objectifs O1-O7 suivis par des indicateurs du tableau de bord (DOC-28) | recette pilote |

## 6. Hors périmètre
Paie de l'État, organisation matérielle des examens, comptabilité de l'État, dossier médical, enseignement à distance (CDC « Périmètre »). Modules DEV, SOR, TRA, DOC-18..21, NOT-06, NOT-07, API-13 : Maternelle (autre agent).
