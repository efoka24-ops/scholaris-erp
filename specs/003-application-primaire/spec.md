# Spécification : application École primaire (`primary`)

Date : 2026-10-13. Statut : **brouillon sans cahier des charges**. Entrées : [etat-des-lieux.md](etat-des-lieux.md) (analyse), [questions.md](questions.md), socle `specs/001-socle-plateforme/`, maquettes `scholaris-html`. Les règles marquées **[H]** sont des hypothèses paramétrables à confirmer ; celles marquées **[F]** sont des faits du socle ou des choix d'architecture déjà pris. Aucune règle officielle camerounaise n'est posée ici.

## 1. Périmètre
- **Inclus (V1)** : organisation de l'école (niveaux, classes, sections), dossier élève et inscriptions, évaluations par séquence et trimestre (notes et compétences), calculs paramétrés, conseil de fin de période, bulletins, présences et conduite, frais, communication avec les familles, documents administratifs, examen de fin de cycle (inscriptions et résultats), tableau de bord du directeur, exports tutelle.
- **Optionnel (Could)** : cantine, garderie, transport, sortie sécurisée, cahier de liaison avec photos.
- **Exclus** : maternelle (application `nursery` distincte), secondaire, paie (socle Staff), comptabilité générale.
- **Cible** : un établissement de type `primary` d'un client ; un client peut aussi avoir maternelle, collège, IPES (lanceur `Main.html`, famille multi-établissements `Parent.html`) [F].

## 2. Acteurs et rôles
Rôles nouveaux préfixés `primary_` (migration `database/migrations/<date>_roles_primary.sql`), sinon rôles du socle réutilisés [H, Q18].
| Code | Rôle | Rôle socle / nouveau | Périmètre (`ScopeType`) | Permissions principales |
|------|------|----------------------|-------------------------|-------------------------|
| P01 | Fondateur / promoteur | `primary_promoteur` | establishment | lectures, `primary.dashboard.read`, `reporting.read` |
| P02 | Directeur d'école | `directeur` (+ `primary_directeur`) | establishment ou `org_unit` (section) | `primary.council.preside`, `primary.report_card.sign`, `assessment.correction.approve`, `primary.settings.manage`, `primary.promotion.decide` |
| P03 | Directeur adjoint / responsable pédagogique | `primary_adjoint` | section (`org_unit`) | `assessment.validate`, `primary.referential.manage`, `scheduling.manage` |
| P04 | Maître titulaire | `enseignant` | classe (`class`) | `grades.enter`, `assessment.submit`, `attendance.take`, `primary.competency.enter`, `primary.journal.write`, `primary.conduct.enter` |
| P05 | Maître intervenant (anglais, EPS…) | `enseignant` | classe(s) + matière | `grades.enter` (ses matières), `attendance.take` |
| P06 | Secrétaire | `primary_secretaire` | establishment | `people.manage`, `primary.enrol`, `documents.issue`, `primary.exam.manage` |
| P07 | Économe / intendant | `comptable` | establishment | `billing.manage`, `primary.services.manage` |
| P08 | Caissier | `caissier` | establishment | `billing.cash` |
| P09 | Surveillant / agent de sortie | `primary_surveillant` | establishment | `attendance.sanction.manage` (proposition), `primary.pickup.handover` |
| P10 | Parent / garant | `parent` | ses enfants | `self.read`, paiement, messagerie, cahier de liaison |
| P11 | Élève | `apprenant` | soi | désactivé par défaut (Q20) |
| P12 | Administrateur fonctionnel du client | `super_admin` | tenant | `identity.manage`, `settings.manage`, `documents.template.manage` |

## 3. Parcours principaux
1. **Ouvrir l'application** : le client ouvre `primary` (libre-service ou administrateur de plateforme), essai 90 jours ; modules requis activés [F].
2. **Préparer l'année** : le directeur crée l'année, 3 trimestres et leurs séquences [H Q4], les niveaux et classes (sections FR/EN [H Q1]), choisit le référentiel de domaines/matières/compétences (copie d'un référentiel modèle ou saisie), publie les règles de calcul par niveau.
3. **Inscrire** : la secrétaire crée ou retrouve l'élève (détection de doublons du socle), saisit parents, santé, consentements, pièces, classe ; facture générée (inscription + 1re tranche) ; paiement au guichet ou mobile money ; validation de l'inscription.
4. **Faire classe** : le maître fait l'appel chaque demi-journée hors ligne [H Q9], saisit ses notes et évaluations de compétences par séquence (web ou mobile hors ligne), renseigne la conduite et le cahier de liaison.
5. **Clore une période** : le directeur verrouille la saisie, l'application calcule moyennes, rangs et propositions, le conseil (présidé par le directeur) arrête décisions et appréciations, les bulletins partent en file de génération, sont publiés aux familles (portail, SMS « bulletin disponible »).
6. **Clore l'année** : récapitulatif annuel, propositions de passage, décision du conseil, réinscriptions préparées en classe supérieure (brouillons), report des soldes.
7. **Examen de fin de cycle** : inscription des candidats de la dernière année, liste exportée, frais d'examen facturés, résultats officiels saisis, taux de réussite au tableau de bord.
8. **Suivre** : le directeur suit effectifs, présence du jour, avancement de la saisie, recouvrement, alertes (absences répétées, impayés), et exporte les statistiques pour la tutelle.

## 4. Exigences (EX-PRI-nn)
Couverture : **C** socle tel quel, **P** partiel (extension socle PS ou adaptation Primary), **N** à créer. Priorité MoSCoW.
| ID | Exigence | Prio | Couv. | Réalisation (socle réel / Primary) |
|----|----------|------|-------|-------------------------------------|
| EX-PRI-01 | L'application `primary` se déclare au catalogue, s'ouvre par client, respecte suspension/fermeture | Must | P | Catalogue seedé [F] ; manifeste `platform.applications.primary` et `WriteGate` à créer |
| EX-PRI-02 | Établissement `primary`, vocabulaire, image de marque | Must | C | `EstablishmentType::Primary`, `GET /vocabulary`, `/establishments/{id}/branding` |
| EX-PRI-03 | Années scolaires, trimestres, séquences, calendrier (congés, réunions) | Must | C | `/academic-years`, `/periods` (`trimester`, `sequence`), `/calendar-events` |
| EX-PRI-04 | Une séquence appartient à un trimestre ; la période courante rend les deux | Must | P | PS1 (`periods.parent_id`, `CurrentPeriod`) |
| EX-PRI-05 | Niveaux et classes (effectif maximal), classe rattachée à son niveau | Must | C | `/structures` (`level`, `class`, `parent_id`), `StructureWriter` |
| EX-PRI-06 | Sections francophone / anglophone (ou unique), langue des documents par section | Should | P | `/org-units` type `section` ; langue du modèle de document par section (Primary) |
| EX-PRI-07 | Paramètres de l'école hérités et surchargés par section | Must | C | `/settings`, `OrgUnitSettingsReader` |
| EX-PRI-08 | Inscription refusée sans autorisation d'ouverture valide du niveau | Should | C | `EnrolmentGate`, `/authorizations` (`covered_levels`) |
| EX-PRI-09 | Rôles du primaire et périmètre classe du maître | Must | P | Migration de rôles ; portée `class` existante ; `RoleMatrix` Primary |
| EX-PRI-10 | Dossier élève : identité, photo, doublons, import | Must | C | `/people`, `/people/duplicates`, `/people/import` |
| EX-PRI-11 | Parents, garants, personnes à contacter | Must | C | `/people/{id}/relations`, `/people/{id}/contacts` |
| EX-PRI-12 | Santé (allergies, consignes) chiffrée, consentements (photo, SMS) | Must | C | `/people/{id}/health`, `/consents` |
| EX-PRI-13 | Inscription et réinscription en classe, validation, statut | Must | C | `/enrollments` (+ `validate`, `status`) |
| EX-PRI-14 | Indicateur « redoublant » visible (dossier, bulletin) | Should | P | Calculé par Primary depuis l'inscription précédente (`EnrollmentDirectory::forPerson`) |
| EX-PRI-15 | Transfert sortant (exeat / certificat de radiation) et entrant | Should | P | Statut d'inscription + type de document `primary_transfer` |
| EX-PRI-16 | Matières par niveau regroupées en domaines, ordre d'affichage | Must | P | `/subjects` + `pri_domains`, `pri_level_subjects` |
| EX-PRI-17 | Référentiel de compétences par matière et niveau (copiable d'une année à l'autre) | Must | N | `pri_competencies` (Q6, DOC-3) |
| EX-PRI-18 | Évaluations par séquence ; saisie des notes web et mobile hors ligne ; import Excel | Must | C | `/assessments`, `/grades` (+ `sync`, `import`) |
| EX-PRI-19 | Verrouillage de la saisie ; correction motivée et approuvée | Must | C | `/grade-sheets/transition`, `/grade-corrections` |
| EX-PRI-20 | Évaluation par compétence sur une échelle paramétrable (libellés FR/EN) | Must | P | PS2 + `pri_competency_marks` |
| EX-PRI-21 | Moyenne par matière et générale de la séquence, barème et coefficients **par niveau** paramétrés | Must | P | `/calculation-rules` + `assessment.rule_scopes.primary` (classe → niveau) |
| EX-PRI-22 | Moyennes trimestrielle et annuelle à partir des séquences et trimestres (pondérations paramétrées) | Must | N | Stratégie `assessment.rule_strategies.primary` + PS1 |
| EX-PRI-23 | Rang dans la classe, ex æquo au même rang, affichage activable | Should | C | `RuleEngine::ranks`, `results.rank_in_class` |
| EX-PRI-24 | Appréciation automatique par tranche paramétrable, modifiable par le maître | Should | P | `RuleSet::mentions` réutilisé ; aucun seuil par défaut (Q7) |
| EX-PRI-25 | Conseil de fin de période : proposition, décision, appréciation, procès-verbal | Must | C | `/class-councils` (+ `refresh`, `validate`) |
| EX-PRI-26 | Bulletin trimestriel « APC » (domaines, matières, compétences, séquences, moyennes, rang, absences, conduite, décision, signatures, QR) FR/EN | Must | P | Type `primary_report_card` + fournisseur de variables Primary (Q2) |
| EX-PRI-27 | Récapitulatif annuel et proposition de passage / redoublement | Must | N | `RuleStrategy::decideYear` (Primary) |
| EX-PRI-28 | Génération en lot, publication aux familles, duplicata | Must | C | `/class-councils/{id}/report-cards`, `/document-batches`, `/documents/{id}/duplicate` |
| EX-PRI-29 | Appel par demi-journée, hors ligne, sans emploi du temps détaillé | Must | P | PS3 |
| EX-PRI-30 | Justificatifs d'absence, notification immédiate du parent | Must | C | `/absence-justifications`, `attendance:retry-absences`, `AbsenteeDirectory` |
| EX-PRI-31 | Conduite par période sur une échelle paramétrable, reportée au conseil et au bulletin | Should | P | `pri_conduct_marks` → `council_lines.conduct` |
| EX-PRI-32 | Incidents et sanctions avec approbation | Should | C | `/incidents`, `/sanctions` |
| EX-PRI-33 | Sortie sécurisée : remise à une personne autorisée, journal, refus et alerte | Could | N | `/authorized-pickups` [F] + `pri_pickup_logs` (Q13) |
| EX-PRI-34 | Frais par niveau (inscription, pension par tranches, autres), échéancier, remise fratrie | Must | C | `/fee-items`, `/fee-schedules`, `SiblingDiscountResolver` ; natures `billing.fee_kinds.primary` |
| EX-PRI-35 | Encaissement guichet et mobile money, reçus, arrêté de caisse, relances | Must | C | `/cash/receipts`, `/payments`, `/cash-sessions`, `reminders:send` |
| EX-PRI-36 | Abonnements cantine, garderie, transport facturés | Could | N | `pri_service_subscriptions` + `InvoiceIssuer::forCharges` (Q12) |
| EX-PRI-37 | Messages et annonces aux familles par classe/niveau, SMS, messagerie modérée | Must | C | `/messages`, `/announcements`, `/conversations` |
| EX-PRI-38 | Cahier de liaison / cahier de textes de la classe visible des parents | Should | N | `pri_class_journal` + `portals.sections.primary` |
| EX-PRI-39 | Portail parent : notes publiées, bulletins, absences, solde, paiement, messages | Must | P | `/portal/family` [F] + section `primary` (compétences, cahier) |
| EX-PRI-40 | Documents administratifs : certificat de scolarité, attestation de fréquentation, carte scolaire | Must | C | `/document-types`, `/documents`, `/document-requests` |
| EX-PRI-41 | Vérification publique des documents par QR | Must | C | `/public/verify/{token}` |
| EX-PRI-42 | Examen de fin de cycle : candidats, numéros, frais, export, résultats, taux de réussite | Should | N | `pri_exam_sessions`, `pri_exam_candidates` (Q11) |
| EX-PRI-43 | Tableau de bord du directeur (effectifs, présence du jour, saisie, recouvrement, alertes) | Must | P | `/dashboards/{kind}` + `reporting.dashboards.primary` |
| EX-PRI-44 | Indicateurs par niveau et classe (réussite, absentéisme, recouvrement) | Should | N | `reporting.indicators.primary` |
| EX-PRI-45 | Exports tutelle (MINEDUB) à colonnes configurables | Should | P | `integrations.export_types.primary` (Q16) |
| EX-PRI-46 | Clôture d'année : passage selon décision du conseil, réinscriptions en brouillon, report des soldes | Must | P | `People\Application\YearPromotion` (via `AdmissionDirectory`) + `people.promotion.next_structure` ; étiquettes de décision « passing » paramétrables (S9, `CalculationRules::passingLabels`) ; dernier niveau sans classe supérieure = non promu [F] ; planificateur `organisation.closure.promotion.primary` seulement si Q10 l'exige |

**Synthèse** : 46 exigences ; **21 C**, **17 P**, **8 N**. Must 30, Should 13, Could 3.

## 5. Règles de gestion (RG-PRI-n)
### Faits (architecture et socle) [F]
- RG-PRI-1 Toute écriture Primary passe par `WriteGate` : application suspendue → lecture et export seuls ; fermée → ni lecture ni écriture.
- RG-PRI-2 Toute table `pri_*` porte `tenant_id` et `establishment_id` ; un identifiant d'un autre client ou établissement répond 404.
- RG-PRI-3 Un maître ne voit et ne saisit que les classes (et matières) de ses affectations en vigueur (portée `class`) ; hors périmètre : 404 en lecture, 403 journalisé en écriture.
- RG-PRI-4 Une note verrouillée ne se modifie que par une correction motivée approuvée (`/grade-corrections`).
- RG-PRI-5 Un bulletin émis est figé (données copiées) ; une correction produit une nouvelle version, l'ancienne est révoquée et marquée remplacée à la vérification publique.
- RG-PRI-6 Les bulletins se génèrent après validation du conseil (`ReportCardService`).
- RG-PRI-7 Les règles de calcul sont versionnées ; un résultat garde la version qui l'a produit (`rule_version`).
- RG-PRI-8 Ex æquo : même rang, rang suivant sauté (`RuleEngine::ranks`).
- RG-PRI-9 Les données de santé ne sont visibles que des rôles autorisés ; les photos d'élèves ne sont publiées qu'avec consentement.
### Hypothèses paramétrables [H] (toutes « à confirmer »)
- RG-PRI-10 L'année compte `primary.trimesters` = 3 trimestres et `primary.sequences_per_trimester` = 2 (Q4).
- RG-PRI-11 Moyenne de matière d'une séquence = moyenne pondérée des épreuves de la séquence (poids 1 par défaut).
- RG-PRI-12 Moyenne générale de séquence = moyenne pondérée par coefficients des matières notées ; coefficients 1 par défaut (= moyenne simple) (Q3).
- RG-PRI-13 Moyenne trimestrielle = moyenne pondérée des séquences du trimestre ; annuelle = moyenne pondérée des trimestres ; poids 1 par défaut (Q4).
- RG-PRI-14 Barème (`scale`) et note de passage (`passMark`) définis **par niveau** ; défaut 20 et 10 (Q3). Aucune conversion entre barèmes n'est faite implicitement : une moyenne s'exprime dans le barème de son niveau.
- RG-PRI-15 Une évaluation de compétence prend une valeur de l'échelle active ; défaut `acquired`, `in_progress`, `not_acquired` ; elle n'entre dans aucune moyenne numérique sauf paramètre `primary.competencies.numeric_map` (vide par défaut) (Q7).
- RG-PRI-16 Absence non justifiée à une épreuve : effet `zero` du socle par défaut, paramétrable `excluded` (Q3).
- RG-PRI-17 Proposition de passage : « admis » si moyenne annuelle ≥ `passMark` du niveau, sinon « redouble » ; la décision du conseil prévaut et est motivée si elle diffère (Q10).
- RG-PRI-18 Dernier niveau du cycle : la décision de fin d'année est « fin de cycle » et l'élève n'est pas promu automatiquement (Q10, Q11).
- RG-PRI-19 Appel par demi-journée (`morning`, `afternoon`) ; une absence = une demi-journée ; bulletin en demi-journées (Q9).
- RG-PRI-20 Le bulletin n'est jamais bloqué pour impayé par défaut (`primary.report_card.block_on_debt=false`) (Q17).
- RG-PRI-21 Le rang est affiché par défaut ; masquable par niveau (`primary.report_card.show_rank`) (Q8).
- RG-PRI-22 Une sortie sécurisée n'est confirmée que vers une personne autorisée en vigueur ce jour ; un refus déclenche une alerte au directeur et au parent (Q13).

## 6. Documents produits
| Code | Document | Source | Statut |
|------|----------|--------|--------|
| DOC-PRI-01 | Bulletin trimestriel (`primary_report_card`) | Conseil validé | P (modèle à valider, DOC-1) |
| DOC-PRI-02 | Bulletin annuel / récapitulatif (`primary_annual_report`) | Conseil du 3e trimestre | N |
| DOC-PRI-03 | Certificat de scolarité (`certificate` du socle) | Inscription active | C |
| DOC-PRI-04 | Attestation de fréquentation / d'inscription | Inscription | C (modèle) |
| DOC-PRI-05 | Carte scolaire avec photo et QR | Inscription validée | C (modèle) |
| DOC-PRI-06 | Exeat / certificat de radiation (`primary_transfer`) | Inscription close | N |
| DOC-PRI-07 | Reçu de paiement, relevé de compte famille | Billing | C |
| DOC-PRI-08 | Procès-verbal de conseil | Conseil | C |
| DOC-PRI-09 | Liste des candidats à l'examen de fin de cycle | Examen | N |
| DOC-PRI-10 | Fiche de renseignements de l'élève | Dossier | C (modèle) |

## 7. Notifications (via Messaging, modèles en données)
| Code | Événement | Destinataires | Canal |
|------|-----------|---------------|-------|
| NOT-PRI-01 | Absence constatée à l'appel | Parents | SMS (socle) |
| NOT-PRI-02 | Bulletin disponible | Parents | SMS + portail |
| NOT-PRI-03 | Échéance de frais proche / dépassée | Parents | SMS (`reminders:send`) |
| NOT-PRI-04 | Reçu de paiement | Payeur | SMS |
| NOT-PRI-05 | Nouvelle entrée du cahier de liaison | Parents de la classe | Portail (+ SMS optionnel) |
| NOT-PRI-06 | Saisie des notes non terminée à J-2 de la clôture | Maîtres concernés | Interne |
| NOT-PRI-07 | Sortie refusée (personne non autorisée) | Directeur, parents | SMS |
| NOT-PRI-08 | Convocation des parents (conseil, incident) | Parents | SMS + messagerie |
| NOT-PRI-09 | Résultats de l'examen de fin de cycle publiés | Parents des candidats | SMS |

## 8. Indicateurs (tableau de bord du directeur)
Effectifs par niveau, classe et sexe ; présents / absents du jour ; taux d'assiduité de la semaine ; avancement de la saisie par classe et séquence ; moyenne de classe et taux ≥ `passMark` par classe ; taux de recouvrement (montant dû, payé, par niveau) ; impayés par famille ; élèves en alerte (absences répétées ≥ `primary.alert.absences` = 3 demi-journées consécutives [H], moyenne en baisse) ; inscriptions de l'année vs N-1 ; taux de réussite à l'examen de fin de cycle ; documents en attente de signature.

## 9. Critères de succès
- SC-PRI-1 Une école de 6 classes et 300 élèves est paramétrée (année, périodes, niveaux, classes, référentiel, frais) en moins d'une demi-journée par le directeur.
- SC-PRI-2 Les bulletins d'une classe de 60 élèves sont générés en moins de 2 minutes après validation du conseil (hébergement mutualisé, `documents:process-jobs`).
- SC-PRI-3 L'appel d'une classe se fait hors ligne en moins d'une minute sur mobile.
- SC-PRI-4 Zéro règle officielle codée en dur : chaque seuil, barème, poids et libellé est lu d'un paramètre ou d'une version de règles (vérifié par un test d'architecture).
