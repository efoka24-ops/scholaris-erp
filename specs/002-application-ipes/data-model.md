# Data Model : Application IPES

Conventions : celles de 001 (ULID, `tenant_id`, `establishment_id`, montants XAF entiers, dates Africa/Douala (UTC+1 fixe, sans heure d'été)). Toutes les tables sont préfixées `hi_`. Les liens vers les personnes, paiements, documents et notes génériques passent par les identifiants des services publics du socle (pas de jointure vers leurs tables internes).

| Table | Champs clés | Règles / remarques |
|-------|-------------|--------------------|
| `hi_schools` | code, name, director_person_id, branding override | École interne ; hérite des paramètres de l'établissement (RG-ORG-5) |
| `hi_departments` | school_id, name, head_person_id | |
| `hi_programs` | school_id, department_id, code, name, regime (lmd, bts, hnd, certificate), mode (day, evening, alternance), language, duration | Régime non modifiable (RG-ORG-7) |
| `hi_authorizations` | program_id, reference, decided_on, levels, valid_to, document_id | Filière sans autorisation valide : pas d'inscription (RG-ACA-1) |
| `hi_tutelages` | program_id, university, academic_year_id, convention_document_id, contacts | LMD obligatoire par année (RG-ACA-2, RG-ORG-8) |
| `hi_regulations` | program_id, academic_year_id, parameters (JSON), version, frozen_at | Figé à l'ouverture des inscriptions (RG-ACA-3) |
| `hi_curricula` | program_id, level, academic_year_id, version, status (draft, published), tutelle_reference | 30 crédits par semestre en LMD (RG-MAQ-2) |
| `hi_units` | curriculum_id, semester, code, name, type (fundamental, transversal, optional, internship, project), credits | UE |
| `hi_elements` | unit_id, code, name, credits, coefficient, cm_hours, td_hours, tp_hours, personal_hours, national_exam_subject | EC |
| `hi_campaigns` | program_id, level, opens_on, closes_on, seats, application_fee, required_documents, prerequisites | |
| `hi_applications` | campaign_id, person_id, status (draft, submitted, complete, evaluating, admitted, conditional, waitlisted, refused, enrolled), decision_reason, conditional_until | RG-ADM-1 à 4 |
| `hi_enrolments` | person_id, program_id, level, academic_year_id, group_id, status, matricule, financial_hold | Matricule unique définitif (RG-INS-1) |
| `hi_learning_contracts` | enrolment_id, units_enrolled, units_capitalized, units_in_debt | Report automatique (RG-INS-4) |
| `hi_groups` | program_id, level, name, mode, capacity, delegate_person_id | |
| `hi_assignments` | staff_id, element_id, group_id, type (cm, td, tp), planned_hours | Affectation par chef de département |
| `hi_sessions_log` | assignment_id, held_on, start, end, content, homework, teacher_signed_at, countersigned_by, countersigned_at | Base de calcul des vacations (RG-ENS-1) |
| `hi_vacation_statements` | staff_id, period, hetd_total, rate, gross_amount, status (draft, validated, paid) | HETD = 1,5 CM + 1 TD + 0,75 TP (paramètres) |
| `hi_assessments` | element_id, kind (continuous, practical, exam, resit), held_on, scale, weight, status | |
| `hi_marks` | assessment_id, enrolment_id, value, absence_code (ABJ, ABI, NA, FR), status (draft, submitted, validated, locked, published), version | Contrôle de version optimiste |
| `hi_mark_changes` | mark_id, old_value, new_value, reason, evidence_document_id, requested_by, validated_by_head, validated_by_director | Demandeur ≠ validateur (RG-NOT-2) |
| `hi_juries` | program_id, academic_year_id, session, members, quorum, president_person_id | |
| `hi_deliberations` | jury_id, scope (semester, year, final), status (prepared, simulated, in_session, signed, published, reopened), minutes_hash, signed_at | Ouverture seulement si toutes notes verrouillées (RG-DEL-1) |
| `hi_results` | enrolment_id, scope, average, letter, points, credits_acquired, decision, mention, rule_version | Figés après signature (RG-DEL-4) |
| `hi_internships` | enrolment_id, company, tutor, supervisor_person_id, convention_document_id, completion_document_id, thesis_document_id | |
| `hi_defenses` | internship_id, held_at, room, jury_members, grade, minutes_document_id | RG-STG-1/2 |
| `hi_national_sessions` | type (bts, hnd), specialty, year, calendar, fee | |
| `hi_candidates` | session_id, enrolment_id, status (eligible, complete, exported, convened, resulted), candidate_number, result | RG-EXN-1/2/3 |
| `hi_tutelage_transmissions` | tutelage_id, kind (lists, marks, minutes, diploma_file), sent_at, status | RG-TUT-2 |

## Contrat d'application déclaré au socle

| Élément | Valeur |
|---------|--------|
| Type d'établissement servi | `higher` |
| Vocabulaire | étudiant, filière, semestre, unité d'enseignement (voir [design.md](../001-socle-plateforme/design.md)) |
| Structure | écoles, départements, filières, niveaux L1 à M2, BTS 1-2, HND 1-2, groupes |
| Règles d'évaluation (S09) | crédits, compensation, capitalisation, rattrapage, grille de lettres ; règles BTS/HND par matières et coefficients |
| Modèles de documents (S06) | DOC-01 à DOC-32 |
| Rôles propres | directeur d'école, chef de département, responsable de filière, scolarité, examens, jury, vacataire, tutelle, auditeur (R04 à R23) |
| Modules métier | stages et soutenances, examens nationaux, tutelle |
| Tableaux de bord | recouvrement par école, taux de réussite par UE, vacations, saisie des notes |
| Notifications | NOT-01 à NOT-15 |

## États (résumé)

- Candidature : brouillon → soumise → complète → en évaluation → admis | admis sous réserve | liste d'attente | refusé → inscrit
- Inscription : en cours → inscrit (ou sous réserve financière) → suspendu | abandon | exclu | transféré | diplômé
- Notes : brouillon → soumise → validée → verrouillée → publiée ; verrouillée → correction demandée → verrouillée
- Délibération : préparée → simulée → en séance → signée → publiée ; réouverture motivée possible
- Candidat national : éligible → dossier complet → exporté → convoqué → résultat importé
