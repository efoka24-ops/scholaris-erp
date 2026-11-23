# Modèle de données : application Primaire (`pri_*`)

Date : 2026-10-13. Conventions (ADR-0001, agent-conventions) : SQL portable MySQL 8 + SQLite ; `id CHAR(26)` ULID (`Scholaris\Core\Support\Ulid`) ; `tenant_id VARCHAR(40)` et `establishment_id VARCHAR(40)` sur **toutes** les tables (comme le socle) ; dates `VARCHAR(32)` ISO 8601 Africa/Douala, jours `VARCHAR(10)` ; montants entiers XAF ; pas de clé étrangère, pas d'AUTO_INCREMENT/ENGINE ; une colonne par `ALTER TABLE` ; aucun index sur TEXT ; index composites < 3 072 octets ; **mots réservés interdits** (`rank`, `order`, `groups`, `range`, `condition`, `key`, `start`, `end`, `read`, `usage`, `system`…). Migrations : `src/Modules/Apps/Primary/<Module>/Database/Migrations/AAAAMMJJHHMM_primary_<module>_*.sql` (chargées par le Migrator).

## 1. Ce qui reste dans le socle (aucune copie)
Personnes, relations, santé, consentements (`people`, `person_relations`, `health_records`, `consents`) ; inscriptions (`enrollments`) ; années, périodes, structures, unités (`academic_years`, `periods` + `parent_id` PS1a, `pedagogical_structures`, `org_units`) ; matières (`subjects`) ; épreuves, notes, règles, résultats, conseils (`assessments`, `grades`, `calculation_rules`, `results`, `class_councils`, `council_lines`) ; présences (`attendance_sessions`, `attendance_records`, `authorized_pickups`, `pickup_handovers` PS8) ; frais (`fee_items`, `invoices`, `payments`…) ; documents ; messages. Primary référence ces lignes par leur identifiant (`*_id VARCHAR(40)` ou `CHAR(26)`), lues **uniquement par les Contracts**.

## 2. Correspondance des notions
| Notion du primaire | Stockage |
|--------------------|----------|
| Section francophone / anglophone | `org_units.type = 'section'` (socle) |
| Niveau (ex. 1re année) | `pedagogical_structures.kind = 'level'` + `pri_level_profiles` |
| Classe (ex. CE1 A) | `pedagogical_structures.kind = 'class'`, `parent_id` = niveau |
| Trimestre / séquence | `periods.kind = 'trimester' | 'sequence'`, séquence `parent_id` = trimestre (PS1a) |
| Matière | `subjects` (socle, via `CurriculumCatalog`) + `pri_level_subjects` |
| Domaine | `pri_domains` |
| Compétence | `pri_competencies` (ou socle si PS9) |
| Note chiffrée | `grades` (socle) |
| Évaluation de compétence | `pri_competency_marks` |
| Conduite de la période | `pri_conduct_marks` → `council_lines.conduct` (PS4) |
| Règles de calcul par niveau | `calculation_rules.structure_id` = niveau (socle), lignée `assessment.rule_scopes.primary` |

## 3. Tables `pri_*` (14)
Colonnes communes non répétées : `id CHAR(26) PK`, `tenant_id VARCHAR(40) NOT NULL`, `establishment_id VARCHAR(40) NOT NULL`, `created_at VARCHAR(32) NOT NULL`, `updated_at VARCHAR(32) NOT NULL`.

### Référentiel (module `Academic`)
1. **`pri_level_profiles`** : `level_structure_id VARCHAR(40) NOT NULL`, `section_org_unit_id VARCHAR(40) NULL`, `cycle_code VARCHAR(20) NULL`, `sort_order INTEGER NOT NULL`, `is_final_level INTEGER NOT NULL DEFAULT 0` (dernier niveau du cycle, RG-PRI-18), `is_exam_level INTEGER NOT NULL DEFAULT 0`, `language VARCHAR(5) NOT NULL DEFAULT 'fr'`. Unique `(tenant_id, establishment_id, level_structure_id)`.
2. **`pri_domains`** : `academic_year_id VARCHAR(40) NOT NULL`, `code VARCHAR(40) NOT NULL`, `label_fr VARCHAR(160) NOT NULL`, `label_en VARCHAR(160) NULL`, `sort_order INTEGER NOT NULL`, `external_key VARCHAR(80) NULL`, `active INTEGER NOT NULL DEFAULT 1`. Unique `(tenant_id, establishment_id, academic_year_id, code)`.
3. **`pri_level_subjects`** : `academic_year_id VARCHAR(40) NOT NULL`, `level_structure_id VARCHAR(40) NOT NULL`, `subject_id CHAR(26) NOT NULL` (socle), `domain_id CHAR(26) NOT NULL`, `evaluation_mode VARCHAR(12) NOT NULL` (`numeric`, `competency`, `both`), `sort_order INTEGER NOT NULL`, `active INTEGER NOT NULL DEFAULT 1`. Unique `(tenant_id, establishment_id, academic_year_id, level_structure_id, subject_id)` ; index `(tenant_id, level_structure_id)`.
4. **`pri_competencies`** : `academic_year_id VARCHAR(40) NOT NULL`, `domain_id CHAR(26) NOT NULL`, `subject_id CHAR(26) NULL`, `level_structure_id VARCHAR(40) NOT NULL`, `code VARCHAR(40) NOT NULL`, `label_fr VARCHAR(255) NOT NULL`, `label_en VARCHAR(255) NULL`, `sort_order INTEGER NOT NULL`, `external_key VARCHAR(80) NULL`, `active INTEGER NOT NULL DEFAULT 1`. Unique `(tenant_id, establishment_id, academic_year_id, level_structure_id, code)`.
5. **`pri_referential_templates`** : référentiels modèles importables (copie vers 2-4) : `code VARCHAR(40) NOT NULL`, `label VARCHAR(160) NOT NULL`, `origin VARCHAR(12) NOT NULL` (`example`, `official`, `custom`), `language VARCHAR(5) NOT NULL`, `payload TEXT NOT NULL` (JSON : domaines, matières, compétences par niveau), `version INTEGER NOT NULL`. `tenant_id` = `'platform'` pour les modèles fournis. Le modèle `example` est **étiqueté comme exemple**, jamais présenté comme officiel.

### Évaluation (module `Grades`)
6. **`pri_competency_marks`** : `person_id VARCHAR(40) NOT NULL`, `class_structure_id VARCHAR(40) NOT NULL`, `period_id VARCHAR(40) NOT NULL` (séquence ou trimestre selon `primary.competencies.period_kind`), `competency_id CHAR(26) NOT NULL`, `scale_code VARCHAR(20) NULL` (valeur de `assessment.qualitative_scale`, PS2), `absence_code VARCHAR(12) NULL`, `remark VARCHAR(255) NULL`, `status VARCHAR(12) NOT NULL` (`draft`, `locked`, `published`), `entered_by VARCHAR(40) NOT NULL`, `client_uuid VARCHAR(40) NULL` (hors ligne), `version INTEGER NOT NULL DEFAULT 1` (conflit 409, jamais d'écrasement). Unique `(tenant_id, person_id, period_id, competency_id)` ; unique `(tenant_id, client_uuid)`.
7. **`pri_competency_mark_events`** : historique : `mark_id CHAR(26) NOT NULL`, `previous_code VARCHAR(20) NULL`, `new_code VARCHAR(20) NULL`, `actor_id VARCHAR(40) NOT NULL`, `reason VARCHAR(255) NULL`, `occurred_at VARCHAR(32) NOT NULL`.
8. **`pri_conduct_marks`** : `person_id VARCHAR(40) NOT NULL`, `class_structure_id VARCHAR(40) NOT NULL`, `period_id VARCHAR(40) NOT NULL`, `scale_code VARCHAR(20) NOT NULL` (échelle `primary.conduct_scale`), `remark VARCHAR(255) NULL`, `entered_by VARCHAR(40) NOT NULL`. Unique `(tenant_id, person_id, period_id)`.

### Vie de classe (module `ClassLife`)
9. **`pri_class_journal`** : `class_structure_id VARCHAR(40) NOT NULL`, `entry_date VARCHAR(10) NOT NULL`, `entry_kind VARCHAR(12) NOT NULL` (`lesson`, `homework`, `liaison`), `subject_id CHAR(26) NULL`, `author_person_id VARCHAR(40) NOT NULL`, `title VARCHAR(160) NOT NULL`, `body TEXT NOT NULL`, `visible_to_parents INTEGER NOT NULL DEFAULT 1`, `published_at VARCHAR(32) NULL`. Index `(tenant_id, class_structure_id, entry_date)`.
10. **`pri_journal_receipts`** : accusé de lecture parent : `entry_id CHAR(26) NOT NULL`, `guardian_person_id VARCHAR(40) NOT NULL`, `seen_at VARCHAR(32) NOT NULL`. Unique `(tenant_id, entry_id, guardian_person_id)`.

### Examen de fin de cycle (module `Exams`)
11. **`pri_exam_sessions`** : `academic_year_id VARCHAR(40) NOT NULL`, `exam_code VARCHAR(20) NOT NULL` (paramétrable : ex. `cep`, `fslc`, `entrance_6e` — à confirmer Q11), `label VARCHAR(160) NOT NULL`, `level_structure_id VARCHAR(40) NOT NULL`, `registration_opens_on VARCHAR(10) NULL`, `registration_closes_on VARCHAR(10) NULL`, `fee_code VARCHAR(40) NULL` (`fee_items.code`), `status VARCHAR(12) NOT NULL` (`draft`, `open`, `closed`, `results`).
12. **`pri_exam_candidates`** : `session_id CHAR(26) NOT NULL`, `person_id VARCHAR(40) NOT NULL`, `enrollment_id VARCHAR(40) NOT NULL`, `candidate_number VARCHAR(40) NULL`, `registration_status VARCHAR(12) NOT NULL` (`registered`, `withdrawn`), `outcome VARCHAR(12) NOT NULL DEFAULT 'pending'` (`pending`, `passed`, `failed`, `absent`), `outcome_detail VARCHAR(160) NULL`, `invoice_id VARCHAR(40) NULL`, `recorded_by VARCHAR(40) NULL`, `recorded_at VARCHAR(32) NULL`. Unique `(tenant_id, session_id, person_id)`.

### Services (module `Services`, Could)
13. **`pri_service_plans`** : `service_kind VARCHAR(12) NOT NULL` (`canteen`, `daycare`, `transport`), `label VARCHAR(160) NOT NULL`, `fee_code VARCHAR(40) NOT NULL`, `billing_cycle VARCHAR(12) NOT NULL` (`term`, `month`, `year`), `capacity INTEGER NULL`, `route_label VARCHAR(160) NULL`, `active INTEGER NOT NULL DEFAULT 1`.
14. **`pri_service_subscriptions`** : `plan_id CHAR(26) NOT NULL`, `person_id VARCHAR(40) NOT NULL`, `academic_year_id VARCHAR(40) NOT NULL`, `starts_on VARCHAR(10) NOT NULL`, `ends_on VARCHAR(10) NULL`, `status VARCHAR(12) NOT NULL` (`active`, `suspended`, `ended`), `invoice_id VARCHAR(40) NULL`. Index `(tenant_id, person_id)`.

## 4. Paramètres (`/settings`, clés `primary.*`, défauts « à confirmer »)
| Clé | Défaut | Source |
|-----|--------|--------|
| `primary.sections` | `["fr"]` | Q1 |
| `primary.trimesters` | `3` | Q4 |
| `primary.sequences_per_trimester` | `2` | Q4 |
| `primary.period_weights` | `{}` (poids 1) | Q4 |
| `primary.competencies.enabled` | `true` | Q6 |
| `primary.competencies.period_kind` | `trimester` | Q6 |
| `primary.competencies.numeric_map` | `{}` (aucune conversion) | Q7 |
| `primary.conduct_scale` | `[very_good, good, fair, poor]` libellés FR/EN | Q14 |
| `primary.report_card.show_rank` | `true` | Q8 |
| `primary.report_card.per_sequence` | `false` | Q2 |
| `primary.report_card.block_on_debt` | `false` | Q17 |
| `primary.alert.absences` | `3` demi-journées consécutives | spec §8 |
| `primary.pickup.levels` | `[]` | Q13 |
| `assessment.qualitative_scale` (socle, PS2) | `acquired`, `in_progress`, `not_acquired` | Q7 |
| `attendance.mode` (socle, PS3) | `day_part` pour les établissements `primary` | Q9 |
| `people.promotion.next_structure` (socle) | saisi par l'école | Q10 |

Les règles de calcul (barème, note de passage, arrondi, coefficients, effet des absences, appréciations, décisions « passing ») sont des **versions de `/calculation-rules`** rattachées à chaque niveau, jamais des constantes.

## 5. Isolation et export
- Toute lecture passe par `Core\Tenancy\TenantTable` (ou équivalent) avec `tenant_id` et `establishment_id` ; le périmètre classe s'ajoute par `ScopedByClass`.
- Export client et demandes de personnes concernées : `privacy.tenant_data_source.primary` et `privacy.subject_data_source.primary` exposent les 14 tables (les données d'un élève : marques, conduite, accusés, candidatures, abonnements).
- Rétention : celle du socle ; aucune donnée de santé dans `pri_*`.
