# Analyse croisée : CDC plateforme, CDC IPES, maquettes, spécifications 001 et 002

Date : 2026-10-08. Sources : `CDC_Plateforme_SCHOLARIS.pdf`, `CDC_Application_IPES.pdf`, maquette « SCHOLARIS ERP — Design UI » (6 écrans), `specs/001-socle-plateforme`, `specs/002-application-ipes`. Analyse en lecture seule, hormis les corrections listées en fin de document.

## 1. Conflits et décisions à prendre

| # | Gravité | Constat | Où | Recommandation |
|---|---------|---------|----|----------------|
| C1 | Élevée | **Ordonnancement S10.** Le module ENS de l'IPES (affectations, cahier de textes, émargement) est prévu en I2 (S16–S21), mais le service Personnel S10 du socle n'arrive qu'en L3 (S20–S23). | CDC plateforme (feuille de route) vs CDC IPES (lots) | Avancer le dossier du personnel et les affectations (EX-S10-01) en L2 ; garder vacations et paie simplifiée en L3. Appliqué dans 001/tasks.md (T126 scindée) |
| C2 | Élevée | **Chevauchement S09.** Le moteur d'évaluation (L2, S14–S19) doit être prêt avant la saisie de notes IPES (I2, S16–S21). Marge de 3 semaines seulement. | idem | Livrer d'abord le cycle de vie des notes (T116 à T119), puis le moteur de règles (T120 à T124) en parallèle de l'application IPES |
| C3 | Élevée | **Écart d'infrastructure.** Les deux CDC exigent PostgreSQL 16, Redis, Horizon, Gotenberg ; la seule infrastructure disponible est Camoo (MySQL 8, FTP, sans Redis ni Docker). | CDC vs environnement | Décision déjà prise : MySQL en recette, écarts consignés dans 001/plan.md. Prévoir le saut de bascule avant tout pilote réel (génération de 1 000 relevés et files impossibles sur mutualisé) |
| C4 | Moyenne | **Laravel 13 vs 12.** Le CDC IPES dit « Laravel 13 », la plateforme dit « 13 ou 12 si l'hébergement l'impose ». | | Laravel 12 par défaut tant que la version PHP de Camoo n'est pas confirmée (T011) ; mise à jour avant le 24/02/2027, fin du support de sécurité de Laravel 12 |
| C5 | Moyenne | **Cycle de vie des notes différent.** Plateforme (EX-S09-05) : saisie, validation par l'enseignant (verrouillage), publication. IPES : brouillon, soumise, validée par le chef de département, verrouillée (avec 2FA), publiée. | S09 vs NOT-05 | Le socle doit offrir un cycle paramétrable (liste d'états et de rôles par application). Ajouté comme tâche T328 à 001 |
| C6 | Moyenne | **Collision d'identifiants de règles.** Les deux documents ont leurs propres RG-ORG-1 à RG-ORG-n (plateforme : 6, IPES : 8) ; l'IPES renvoie à RG-ORG-1 de la plateforme. | | Dans le code et les tests, préfixer : `S-RG-ORG-x` pour le socle et `H-RG-ORG-x` pour l'IPES |
| C7 | Moyenne | **Identifiants de lots.** Plateforme : L0 à L3 ; IPES : I1 à I4 ; deux numérotations de semaines qui se recouvrent. | | Utiliser les phases de 001 (1 à 13) et les phases de 002 (A à M) dans les tâches ; la correspondance est dans 002/plan.md |
| C8 | Moyenne | **Rôles : 23 rôles IPES vs rôles de la maquette.** La maquette montre « Directeur de groupe », « Directeur », « Enseignant », « Parent ». Les rôles du CDC IPES (R01 à R23) n'apparaissent pas tous ; « Directeur de groupe » n'existe pas dans les 23. | Maquette 01 vs CDC IPES | Ajouter le rôle de niveau groupe « Direction de groupe » au socle (RG-IHM-3) en plus de R01 à R23 ; aligner les libellés de la maquette (R02 Directeur général, R04 Directeur d'école) |
| C9 | Moyenne | **Garant vs parent.** Le CDC plateforme parle de parent, de tuteur, de garant financier ; l'IPES de garant (parent ou tuteur) seulement. | | Un seul type de relation « garant financier » côté IPES, mappé sur `person_relations` du socle |
| C10 | Basse | **Étapes non couvertes par les maquettes.** Connexion, OTP, 2FA, admissions, inscription, saisie des notes, délibération, documents, stages, examens nationaux, tutelle, paramétrage, écrans Secondaire et Maternelle. | Maquettes | Tâches de design à ajouter avant les phases concernées (voir §4) |
| C11 | Basse | **Menu IPES de la maquette incomplet.** Il manque Admissions, Stages et soutenances, Examens nationaux, Tutelle et Maquettes (« Filières & UE » les couvre partiellement). | Maquette 02 vs CDC IPES | Ajouter ces entrées de menu selon le rôle (R03, R07, R16) |
| C12 | Basse | **Objectif chiffré.** SC-010 de 002 (saisie de 42 étudiants en 5 minutes) n'est pas dans le CDC. | 002/spec.md | À confirmer avec le pilote |

## 2. Couverture des exigences

Les effectifs sont comptés dans les CDC ; la couverture est une estimation faite à la lecture des tâches, pas un contrôle automatisé (à refaire avec /speckit-analyze).

| Source | Éléments | Couverts par une tâche | Écarts |
|--------|----------|------------------------|--------|
| CDC plateforme : exigences EX-S01 à EX-S14 | 97 | Presque toutes (rapprochement fait par module, non automatisé) | EX-S01-10 (comptes de service) et EX-S07-04 (génération assistée d'emploi du temps, Could) : la seconde n'a pas de tâche, volontairement |
| CDC plateforme : règles d'interface RG-IHM-1 à 9 | 9 | RG-IHM-1 à 8 couvertes via T045, T153, T154, T139 ; RG-IHM-9 (3G sur smartphone d'entrée de gamme) uniquement par T141 et le front | Ajouter un budget de poids de page au front (T139) |
| CDC IPES : modules (ACA, ADM, INS, MAQ, EDT, ENS, PRE, NOT, DEL, FIN, STG, EXN, TUT, DOC, POR, PIL) | 16 | 16 | BIB en option (T324) |
| CDC IPES : exigences EX-xxx | 93 | Presque toutes | Pas de tâche explicite pour EX-ENS-08 (évaluation des enseignements, Could), EX-NOT-09 couverte par T272 |
| CDC IPES : règles RG-xxx | 63 | Majorité | Règles de détail non testées individuellement : RG-ACA, RG-MAQ-3 (conformité à la tutelle : contrôle humain), RG-INS-2, RG-INS-5/6, RG-DOC-4/5/6, RG-TUT-1 |
| CDC IPES : documents DOC-01 à DOC-32 | 32 | 32 | Répartis sur T239, T252, T270, T287, T294, T299, T302, T308, T310, T315 |
| CDC IPES : notifications NOT-01 à NOT-15 | 15 | 14 | NOT-08, NOT-09, NOT-10 passent par les relances et reçus du socle (T086, T081) |
| CDC IPES : tests d'acceptation TST-01 à TST-12 | 12 | 12 | Tâches T213, T226, T254, T256, T242, T273, T274, T277, T289, T295, T304, T210 |
| Maquettes | 6 écrans | Lanceur (T154), tableau de bord (T313), caisse (T253), parent mobile (T131), appel (T271), fondations (T152/T153) | Les écrans servent de référence visuelle, pas de livrable codé ici |

## 3. Vérification des exemples chiffrés du CDC IPES

Tous les calculs ont été refaits à la main.

| Exemple | Résultat annoncé | Vérifié |
|---------|------------------|---------|
| Note EC Comptabilité générale : 0,4 × 12 + 0,6 × 9 | 10,20 | oui |
| Moyenne UE1 : (10,20 × 4 + 12,20 × 2) ÷ 6 | 10,87 | oui |
| Moyenne UE3 / UE4 / UE5 | 11,55 / 13,10 / 15,15 | oui (11,55 ; 13,10 ; 15,15) |
| Moyenne de semestre : 346,60 ÷ 30 | 11,55 | oui |
| MGP : (2,00 + 1,00 + 2,30 + 3,00 + 3,70) × 6 ÷ 30 | 2,40 | oui |
| Scénario B : note EC 0,4 × 8 + 0,6 × 11 | 9,80 | oui |
| Scénario B : moyenne de semestre (346,60 − 42,60 + 58,80) ÷ 30 | 12,09 | oui (12,093) |
| Vacations : 18 × 1,5 + 12 + 6 × 0,75 | 43,5 HETD, 261 000 FCFA | oui |
| Échéancier : 450 000 − 20 % = 360 000 ; 3 × 120 000 ; total 410 000 | | oui |
| Chronologie des paiements (170 000, 100 000, 20 000) | tranche 2 soldée le 2 février | oui |

Points de vigilance de l'exemple « Marie N. » (à trancher en cadrage, pas des erreurs) :
- La lettre de l'UE2 reste D (1,00 point) dans le MGP même quand l'UE est acquise par compensation : confirmer avec la tutelle que le MGP n'est pas modifié par la compensation.
- Dans le scénario B, la moyenne de semestre est calculée avec la note de rattrapage qui remplace l'examen de l'EC, ce qui reste conforme à « la note de rattrapage remplace la note d'examen ; le contrôle continu est conservé ».
- Les mentions à seuils (Passable [10 ; 12[, Assez bien [12 ; 14[) s'appliquent à la moyenne de semestre : 11,55 donne Passable, 12,09 donne Assez bien : cohérent.

## 4. Actions de design à planifier (d'après la maquette)

| Écran à concevoir | Avant la phase | Réf. |
|-------------------|----------------|------|
| Connexion, activation par code, double authentification, verrouillage | 001 phase 3 (US1) | T033 à T043 |
| Admissions : campagne, candidature en ligne, jury, concours | 002 phase D | T231 à T234 |
| Inscription, contrat pédagogique, carte d'étudiant | 002 phase D | T235 à T241 |
| Maquette de formation (UE, EC, crédits) | 002 phase C | T220 |
| Saisie des notes en grille, import Excel, verrouillage | 002 phase F | T265, T266 |
| Simulation et séance de délibération, signature du PV | 002 phase G | T282 à T284 |
| Documents : modèles, émission, vérification publique | 001 phase 8 et 002 phase H | T101 à T105, T289 |
| Stages et soutenances, examens nationaux, tutelle | 002 phases J et K | T302, T308, T310 |
| Portail étudiant et garant | 002 phase D et L | T241, T317 |
| Vues Secondaire et Maternelle | applications ultérieures | hors périmètre |

## 5. Corrections appliquées dans 001

- C1 : tâche T126 de 001/tasks.md scindée (dossier du personnel et affectations avancés en phase 9, vacations en phase 11).
- C2 : note d'ordonnancement ajoutée dans 001/tasks.md (cycle de vie des notes avant le moteur de règles).
- C5 : tâche T328 ajoutée (cycle de vie des notes paramétrable par application).
- C8 : tâche T329 ajoutée (rôle « Direction de groupe » et alignement des libellés avec la maquette).
- C6 : convention de préfixe des règles consignée dans la constitution.

## 6. Questions pour l'utilisateur

1. Quel IPES pilote (écoles internes, universités de tutelle) ? Il conditionne les règlements, grilles de lettres et formats MINESUP.
2. Front-end : Next.js existant ou Vue/Nuxt (T012) ? Il conditionne la phase 12 de 001 et tout le design.
3. Hébergement de production : quel centre de données au Cameroun ? C3 bloque tout pilote sérieux sur Camoo mutualisé.
