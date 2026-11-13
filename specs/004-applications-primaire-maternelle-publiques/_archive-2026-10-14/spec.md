# Spécification : applications Primaire et Maternelle publiques

**Date** : 2026-10-14. **Statut** : cadrage projet, dérivé du cahier des charges public v1.0 du 9 octobre 2026 fourni dans la conversation. **Règle** : les éléments pédagogiques, réglementaires et opérationnels non documentés officiellement sont des paramètres « à confirmer » ; aucun seuil ou modèle de bulletin n'est une vérité officielle avant validation MINEDUB.

## 1. Objectif et périmètre

Fournir à des écoles publiques camerounaises une plateforme partagée par le MINEDUB ou un programme pilote, avec applications distinctes Primaire et Maternelle, données et services communs, et supervision allant de l'école à l'inspection, délégations et services centraux.

Inclus : école/carte scolaire, infrastructures, territoires, élèves et transferts, classes jumelées et double flux, personnel sans paie de l'État, présences élèves/enseignants, communication familiale, contributions APEE/dons sans condition d'accès, statistiques, inspection, évaluations/bulletins du primaire, décisions de passage, examens (préparation des listes seulement), suivi du développement, sorties sécurisées en maternelle, documents et tableaux de bord. Cantine/santé scolaire, messages vocaux/USSD et certains services sont optionnels suivant priorité du cahier.

Exclus : paie de l'État, comptabilité publique, organisation matérielle des examens officiels, gestion médicale, enseignement à distance et création/maintenance d'infrastructures.

## 2. Acteurs et scénarios (priorités)

- **US1 — Registre public et territoires (P1)** : administration centrale rattache écoles à arrondissement/département/région, maintient identité et infrastructures annuelles ; une clôture statistique reste stable après modification du découpage.
- **US2 — Élèves, classes, personnel et présence (P1)** : directeur/enseignants inscrivent sans exiger acte de naissance ni contribution APEE, répartissent en classe, saisissent présences hors ligne, gèrent transferts et signalent les situations à régulariser.
- **US3 — Protection maternelle et accès famille (P1)** : parent gère personnes autorisées ; personnel contrôle les sorties hors ligne ; famille reçoit informations et notifications selon consentement ; les enfants n'ont pas de compte.
- **US4 — Référentiel, compétences et bulletins (P1, règles officielles bloquantes)** : enseignant évalue selon référentiel versionné, saisit hors ligne, prépare remédiation ; directeur valide et publie des bulletins figés, sans note/rang si le paramétrage national le prescrit. Maternelle produit un carnet positif sans notes ni rang.
- **US5 — APEE et dons (P2)** : trésorier suit contributions, reçus, dépenses justifiées, dons et état financier ; aucune information de non-contribution n'est visible aux enseignants ni ne bloque un service scolaire.
- **US6 — Questionnaire et supervision (P2)** : école prépare les statistiques annuelles, soumises par validation en cascade ; inspection peut saisir pour les écoles non connectées ; agrégats remontent selon territoires et seuils de confidentialité.
- **US7 — Examens et passage (P2/P3)** : école prépare les listes CEP/FSLC/concours, inspection et délégation les contrôlent ; l'outil importe les résultats officiels, prépare les attestations à signer par l'autorité, et ne délivre pas les diplômes.
- **US8 — Inspection, documents et pilotage (P2/P3)** : inspection programme/consigne des visites, contrôle les effectifs et suit des recommandations ; les rôles autorisés consultent indicateurs agrégés et documents vérifiables.

## 3. Exigences fonctionnelles et règles inviolables

- **FR-001** L'école appartient à un seul arrondissement, lui-même rattaché à un département et une région ; le rattachement est administré centralement. Les données des années clôturées gardent leur périmètre historique.
- **FR-002** Les droits combinent rôle, affectation territoriale et établissement. Le national et le régional ne voient que des agrégats ; l'accès nominatif autorisé est limité à une action et journalisé. Les agrégats de moins de cinq élèves sont masqués, sous réserve d'une analyse anti-réidentification.
- **FR-003** Une inscription publique ne peut être refusée pour absence d'acte de naissance ou non-paiement APEE. Un élève ne possède qu'une inscription scolaire active ; le transfert clôt l'ancienne inscription à la date d'accueil.
- **FR-004** Les classes supportent niveaux, classes jumelées et double flux ; affectations des enseignants sont historisées. Présences d'élèves et d'enseignants sont enregistrables avec connectivité intermittente et synchronisation sans doublon/perte silencieuse.
- **FR-005** En maternelle, une remise n'est validée qu'à une personne autorisée en vigueur ; en cas de doute, l'enfant reste à l'école. Le contrôle doit pouvoir utiliser la liste locale hors ligne et journaliser l'événement.
- **FR-006** Les règles d'évaluation, calendrier, référentiels, modèles, seuils et paramètres nationaux/régionaux sont versionnés et configurables ; une règle officielle ne doit jamais être inventée ou codée en dur. Bulletin publié figé ; rectification motivée et traçable.
- **FR-007** Les contributions APEE, les dons et leurs comptes ne modifient jamais inscription, présence, examen, bulletin ou délivrance de documents. Liste nominative des familles non contributrices réservée aux rôles APEE expressément autorisés.
- **FR-008** Les questionnaires statistiques sont préremplis, contrôlés, renvoyés avec motif, validés dans l'ordre défini et figés à validation régionale ; correction ultérieure crée une version motivée.
- **FR-009** L'application prépare les listes et documents d'examen ; elle ne délivre jamais le CEP/FSLC. Les listes validées sont figées, ajout tardif tracé.
- **FR-010** Minimisation des données de mineurs, consentement révocable pour photos et communications non essentielles, rétention à confirmer juridiquement, données sensibles accessibles selon rôle.

## 4. Critères de succès mesurables

- **SC-001** Pilote : écart effectifs déclarés/contrôlés < 2 % après un an.
- **SC-002** Consolidation carte scolaire d'arrondissement en moins de 30 jours après rentrée.
- **SC-003** Temps de génération des bulletins divisé par trois par rapport au processus manuel mesuré au pilote.
- **SC-004** Au moins 90 % des écoles du pilote remontent chaque semaine la présence des enseignants.
- **SC-005** Aucun candidat rejeté au pilote pour dossier incomplet/état civil manquant lorsque la régularisation relevant de l'école était possible ; processus de contrôle démontré.
- **SC-006** 100 % des écoles du pilote publient un état APEE à l'assemblée générale.
- **SC-007** Aucune sortie maternelle sans remise autorisée dans les événements captés par le système.
- **SC-008** La synchronisation hors ligne ne perd ni ne duplique un appel après 10 jours déconnectés ; objectif 30 jours doit être validé par tests de capacité et sécurité.
- **SC-009** Les objectifs nationaux de 40 000 lots/heure et agrégats en <3 s ne sont acceptés qu'après architecture de capacité distincte de Camoo, protocole de mesure et environnement de charge disponibles.

## 5. Contraintes et décisions de périmètre

- La conception cible l'architecture réelle SCHOLARIS : PHP 8.1 sans framework applicatif, monolithe modulaire, Vue 3/TypeScript/PWA, MySQL 8 en production actuelle, SQLite en tests, SQL portable, Dompdf, API `/api/v1`; migrations au module propriétaire. Toute évolution du runtime/hébergement est séparée et soumise à décision.
- Applications : `src/Modules/Apps/Primary` et `Nursery` selon conventions réelles (pas `app/Modules`, Laravel, Redis ni PostgreSQL dans la phase actuelle).
- Aucune dépendance ne peut casser l'isolation `tenant_id` / `establishment_id`. Les périmètres territoriaux doivent être ajoutés comme couche d'autorisation, jamais comme contournement de tenant.
- Le cahier estime un déploiement national, mais l'hébergement documenté est mutualisé. Phase pilote locale d'abord ; charge nationale = étude/porte de décision, non promesse de capacité Camoo.
- Les valeurs de notation, passage, calendrier, examens, conservation légale, modèles statistiques, commanditaire et territoire pilote sont à confirmer auprès des sources/autorités compétentes.

## 6. Conditions de blocage

Avant activation en pilote : accord formel du commanditaire et du territoire, modèle de bulletin et référentiel d'évaluation validés, règles de passage et calendrier officiels fournis, rôle de chaque niveau administratif approuvé, règles légales de rétention/consentement examinées, formats de questionnaires et examens obtenus, et hébergement/capacité approuvés. Les tâches concernées sont balisées `[B]` dans `tasks.md`.
