# Spécification : application Secondaire (lycées, CES, CETIC ; privé et public ; FR et EN)

**Branche** : `feat/rewrite-php-laravel` · **Date** : 2026-10-15 · **Source** : [cdc-secondaire.md](cdc-secondaire.md) (fait foi ; les ID EX-/RG-/DOC-/NOT-/TST-/NF- sont ceux du CDC) · **Analyse** : [etat-des-lieux.md](etat-des-lieux.md)

Toute valeur chiffrée du CDC (coefficients, seuils, barèmes, tarifs) est un **paramètre par défaut « à confirmer »** ([questions.md](questions.md)) ; aucune règle officielle n'est codée en dur.

## 1. User stories priorisées

### P1 : MVP (lots E1 et E2)
**US1 Paramétrer l'établissement (ETB)** — Le censeur et l'administrateur fonctionnel décrivent le type (lycée, CES, CETIC, lycée technique, polyvalent), le statut (public, privé laïc, privé confessionnel), les sections FR/EN, cycles, séries, spécialités, matières, coefficients, volumes horaires, calendrier (3 trimestres, 6 séquences), règlement intérieur, salles, labos et ateliers.
- Acceptation : un CES qui crée une classe de 2nde reçoit 422 (RG-ORG-1) ; un coefficient « officiel » verrouillé ne peut pas être modifié par l'établissement, une matière de projet a coefficient 0 (RG-ORG-4) ; une séquence hors des dates de son trimestre → 422.

**US2 Inscrire et suivre les dossiers (INS, CSC)** — La scolarité préinscrit, inscrit, réinscrit, transfère ; enregistre le matricule national ; liste les élèves en attente de matricule ; importe les paiements de cartescolaire.cm ; détecte les écarts.
- Acceptation : un élève sans matricule a le statut `pending_national_id` et figure dans la liste à régulariser (RG-INS-1) ; [PRI] inscription dans une série sans autorisation valide → refus (RG-ORG-3) ; un exclu définitif ne se réinscrit qu'avec décision motivée (RG-INS-3) ; [PUB] aucune inscription refusée pour impayé (RG-INS-4).

**US3 Encaisser frais, pension, contributions et APEE (FIN)** — L'intendant tient la grille [PRI] ou le catalogue autorisé [PUB], les remises, les relances, les plans de paiement ; le caissier encaisse.
- Acceptation : TST-06, TST-07 ; un frais d'examen payé sur cartescolaire.cm n'est jamais facturé (RG-FIN-3) ; aucune action « renvoyer pour impayé » n'existe (RG-FIN-1).

**US4 Faire l'appel par heure et tenir la discipline (PRE)** — L'enseignant fait l'appel à chaque heure (hors ligne) ; le surveillant général justifie, voit les propositions de sanction, ouvre un conseil de discipline.
- Acceptation : TST-10 ; SMS au parent à la 1re heure d'absence du jour, un seul par jour (EX-PRE-03, NOT-02) ; exclusion définitive seulement par conseil de discipline avec PV (RG-PRE-3).

**US5 Saisir et valider les notes (NOT)** — L'enseignant saisit (téléphone, hors ligne, Excel) ; le censeur valide puis verrouille avec 2FA ; corrections par demande motivée validée par le censeur puis le chef.
- Acceptation : TST-09 ; une note verrouillée n'est jamais écrasée (RG-NOT-4) ; quart de point (RG-NOT-1) ; fraude → 0 et incident pour le surveillant général (RG-NOT-3).

**US6 Cahier de textes et couverture (CTX)** — Progressions, séances signées, visas, taux de couverture.
- Acceptation : une séance sans leçon de la progression est refusée ; taux = heures faites / heures prévues à date, au centième.

### P2 : lot E3
**US7 Calculer moyennes et rangs, tenir les conseils, éditer les bulletins (BUL)** — TST-01 à TST-05 ; DOC-09 à DOC-14 ; publication en ligne et SMS ; rectificatif après conseil sur décision du chef.
**US8 Passage et orientation (PAS)** — propositions automatiques, vœux, avis, fiche d'orientation, recours.
**US9 Examens officiels (EXA)** — listes de candidats, contrôles, exports, import des résultats, attestations internes (TST-08).
**US10 Ateliers et stages [TEC] (ATL, STG)** — séances, EPI, stock, grilles pratiques, plafond sécurité, conventions, visites, note de stage.
**US11 Personnel et vacations (RHP)** — statuts, heures tirées du cahier de textes et de l'appel, vacations, paie simplifiée [PRI].

### P3 : lot E4
**US12 Tableaux de bord et rapports (PIL)** — couverture, réussite, recouvrement, absentéisme ; DOC-32 à DOC-34.
**US13 Services annexes (SVC)** — internat (Should), cantine/transport (Could), bibliothèque (Could), infirmerie (Should).
**US14 Portails (POR)** — élève, parent, enseignant : emploi du temps, notes publiées, absences, bulletins (liens 24 h), paiements.

## 2. Exigences testables
Les exigences reprennent les ID du CDC ; on n'ajoute ici que la forme testable.

| ID | Forme testable |
|----|----------------|
| EX-ETB-01..06 | CRUD + validation ; RG-ORG-1/2/4 en 422 avec message français |
| EX-INS-01..07 | parcours préinscription → inscription → validation ; DOC-01, 02, 04, 05 émis |
| EX-CSC-01..05 | import CSV (colonnes paramétrées, Q1) ; rapprochement par matricule ; rapport des écarts ; DOC-33 |
| EX-FIN-01..07 | grille, remises, catalogue public verrouillé, relances, plans, blocages documentaires seulement |
| EX-EDT-01..04 | génération sans conflit sur un jeu de 10 classes ; conflit détecté = 409 ; NOT-14 envoyée |
| EX-CTX-01..04 | progression, séance, couverture, visa, lecture R21 seulement |
| EX-PRE-01..06 | appel hors ligne idempotent ; barème ; conseil de discipline ; conduite au conseil |
| EX-NOT-01..08 | 6 séquences ; codes ; cycle 5 états ; correction 2 étapes ; lettres [EN] |
| EX-BUL-01..07 | calculs (§3), bulletins FR/EN, appréciations, dossier DOC-12, PV DOC-13, publication, DOC-14 |
| EX-PAS-01..04 | propositions (§3), orientation, DOC-16, recours dans le délai paramétré |
| EX-EXA-01..06 | listes, contrôles (TST-08), export, import, taux de réussite |
| EX-ATL-01..06 | ateliers, planning, fiches, stock (coût par élève), grille (§3), registre ; RG-ATL-1 |
| EX-STG-01..03 | conventions DOC-21, visites, appréciation DOC-22, note intégrée par coefficient |
| EX-RHP-01..04 | statuts ; heures = séances du cahier de textes avec appel fait ; validation censeur ; paie [PRI] |
| EX-SVC-01..04 | internat, abonnements, prêts, infirmerie ; santé visible de l'infirmier seul |

## 3. Règles de calcul (paramètres par défaut du CDC)
| Règle | Défaut | Clé de paramètre |
|-------|--------|------------------|
| Échelle | 0 à 20, quart de point | `RuleSet::scale`, `assessment.step` |
| Note de séquence d'une matière | moyenne pondérée des épreuves de la séquence | stratégie `secondary` |
| Moyenne trimestrielle d'une matière | moyenne des 2 notes de séquence | stratégie |
| Moyenne trimestrielle générale | Σ(moyenne × coef) / Σ coef des matières évaluées | stratégie |
| Moyenne annuelle générale | moyenne des 3 moyennes trimestrielles générales | stratégie (`year`) |
| Composition de fin d'année | une épreuve de la séquence 6 | option `final_exam_in_sequence: 6` |
| Arrondi | au centième, à chaque étape, demi vers le haut | `rounding: 2`, option `round_each_step: true` |
| Rang | dans la classe, ex æquo au même rang, suivant décalé | `RuleEngine::ranks` |
| Matière non évaluée | exclue avec son coefficient, signalée | stratégie |
| Distinctions (trimestre) | ≥16 félicitations + TH ; ≥14 encouragements + TH ; ≥12 TH ; <10 et ≥8 avertissement travail ; <8 blâme travail | `councilProposals` |
| Distinction refusée si sanction de conduite dans le trimestre | oui | option `distinction_blocked_by_conduct: true` |
| Passage | ≥10 admis ; 9 à <10 examen par le conseil ; <9 redoublement ; <9 et redoublant ou âge > limite → exclusion proposée | options `promotion.*` |
| [TEC] Pratique professionnelle | moyenne annuelle de la matière `practical` < 8 → refus proposé, passage conditionnel possible | option `practical_min: 8` |
| Grille pratique | somme des points observés, sur 20 ; manquement grave à la sécurité → plafond 8 | `secondary.workshop.safety_cap` |
| Absences (trimestre, heures injustifiées) | 10 avertissement ; 20 blâme ; 30 exclusion 3 jours ; >30 ou récidive : conseil de discipline | `secondary.discipline.scale` |
| Affectation d'un paiement [PRI] | inscription, APEE, tranches (plus ancienne d'abord), services ; famille tranche par tranche | `billing.allocation_rule = kind_priority` (SS7) |

## 4. Exemples chiffrés (tests obligatoires, valeurs exactes du CDC)
- **E1 Aïcha M. (TST-01)** : total 248,50, coefficients 22, moyenne 11,30, aucune distinction ni sanction ; maths 7,75 signalée (soutien).
- **E2 année (TST-02)** : 11,30 ; 11,20 ; 12,05 → 11,52, « admise ».
- **E3 Brice T. (TST-03)** : total 221,50 / 22 = 10,07 ; alerte pratique 7,75 < 8.
- **E4 ex æquo (TST-04)** : deux élèves 3e ex æquo, le suivant 5e.
- **E5 grille pratique** : 3 + 2 + 4 + 2 + 1 = 12/20 ; même grille avec manquement grave → 8.
- **E6 absences (TST-10)** : 20 h injustifiées → proposition « blâme de conduite », appliquée seulement après décision ; 10 h → avertissement ; 30 h → exclusion 3 jours ; 31 h → conseil de discipline.
- **E7 frais (TST-07)** : familles de 2 enfants (3 500 ; 150 000 en 60 000/50 000/40 000 ; APEE 10 000 ; fratrie 10 % → 135 000 en 54 000/45 000/36 000) ; totaux 163 500 et 148 500 ; 120 000 le 25/09 → inscriptions 7 000, APEE 20 000, 93 000 sur 114 000 des tranches 1, reste 21 000 au 30/09.
- TST-05, TST-06, TST-08, TST-09 : refus (bulletin avec note non verrouillée ; frais « reprographie » hors catalogue avec demande tracée ; candidat BAC « frais non constatés » ; 403 journalisé).

## 5. Exigences non fonctionnelles et sécurité
NF-S1 à NF-S5 du CDC, mesurées sur MySQL 8 réel (pas seulement SQLite) ; sécurité du CDC (affectation enseignant/classe/matière, 2FA au verrouillage, versionnement, mineurs, liens 24 h, matricule jamais dans une URL ni une page publique, idempotence, comptes externes en lecture seule datés, séparation des tâches).

## 6. Critères de succès
- SC-1 : les 10 TST et les 7 exemples passent avec les valeurs exactes ci-dessus.
- SC-2 : NF-S5 : 100 classes générées, écart nul au centième avec un calcul de référence indépendant (script séparé de la stratégie).
- SC-3 : NF-S1 : 3 000 élèves calculés en < 60 s ; NF-S2 : 3 000 bulletins en < 15 min par `documents:process-jobs`, sur la configuration Camoo (MySQL 8, sans Redis).
- SC-4 : 0 route d'écriture `/secondary/*` hors `WriteGate` (test d'architecture) ; 0 valeur officielle codée en dur (`SecondaryConventionsTest`).
- SC-5 : [PUB] 0 frais créé hors catalogue (O5) ; tout refus produit une demande tracée.
- SC-6 : les 34 types de documents et 14 notifications existent, chacun avec un test d'émission.

## 7. Hors périmètre
Organisation des examens officiels, délivrance de diplômes, paie des fonctionnaires, comptabilité générale (export seulement), plateforme d'enseignement en ligne, automatisation du site cartescolaire.cm (RG-CSC-1).
