# Maternelle privée : modèle de données

Date : 2026-10-20. Préfixe **`nu_`** (Nursery privé, réservé par 004) pour les tables propres à l'application ; **`ey_`** (Early Years) pour les tables partagées si l'option A du [plan.md](plan.md) §3 est retenue (MQ-01). `pn_` reste à `PublicNursery` et n'est lu par aucune autre application.
Règles (HANDOVER §3) : ULID `CHAR(26)`, dates ISO `VARCHAR(32)`, jours `VARCHAR(10)`, `tenant_id` et `establishment_id` sur chaque table d'établissement, aucune clé étrangère, aucun AUTO_INCREMENT ni ENGINE, aucun index sur TEXT, une colonne par `ALTER TABLE`, aucun mot réservé MySQL (`level_code`, `remark`, `origin`, `school_day`, `sort_order`, `starts_on`, `ends_on`). Pas de colonne de note, de moyenne, de total ni de rang (RG-MAT-1).
Les personnes autorisées restent dans la table du socle `authorized_pickups` (source unique). Valeurs de paramètres : [plan.md](plan.md) §7, jamais dans le schéma.

## 0. Où vit chaque table selon l'option
| Domaine | Option A (défaut) | Repli B (recopie) |
|---|---|---|
| Journal de sortie, alertes, restrictions, codes, cartes, lots | `ey_pickup_*` (modules `Socle/PickupGate`) | `nu_gate_events`, `nu_late_alerts`, `nu_custody_restrictions`, `nu_pickup_codes`, `nu_pickup_cards`, `nu_sync_batches` (même colonnes) |
| Référentiel, observations, carnet, cahier de vie, besoins | `ey_*` (module `Socle/EarlyYears`) | `nu_dev_*`, `nu_observations`, `nu_progress_books`, `nu_lifebook_*`, `nu_need_reports` |
| Passage, fiche de liaison, services | `nu_*` (propres) | idem |
Les colonnes sont **identiques** pour A et B ; seul le préfixe change (suffixe `pickup_` ou `dev_`). La migration des données publiques `pn_*` vers `ey_*` est de la responsabilité de l'agent propriétaire de NS1 et NS2, avec contrôle de comptage ligne à ligne (T1011 pour la sortie, T1012 pour le développement).
Dans la suite, les tables sont écrites avec le préfixe de l'option A (`ey_`).

## 1. Sortie sécurisée · `Socle/PickupGate/Database/Migrations/<date>_pickup_gate.sql` (option A)
```sql
-- Journal unique des arrivées, remises, refus et alertes.
CREATE TABLE ey_pickup_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    app_key VARCHAR(24) NOT NULL,             -- nursery, public_nursery : application qui a écrit
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NULL,
    school_day VARCHAR(10) NOT NULL,
    event_type VARCHAR(24) NOT NULL,          -- arrival, handed_over, refused, kept_at_school, code_failed
    pickup_id CHAR(26) NULL,                  -- authorized_pickups.id, obligatoire pour handed_over
    code_id CHAR(26) NULL,
    declared_name VARCHAR(160) NULL,          -- jamais utilisé pour remettre
    restriction_id CHAR(26) NULL,
    stale_authorization SMALLINT NOT NULL DEFAULT 0,
    origin VARCHAR(10) NOT NULL,              -- online, offline
    client_uuid VARCHAR(40) NULL,
    occurred_at VARCHAR(32) NOT NULL,
    recorded_at VARCHAR(32) NOT NULL,
    actor_id VARCHAR(40) NOT NULL,
    remark VARCHAR(500) NULL
);
CREATE UNIQUE INDEX ey_pickup_events_client ON ey_pickup_events (tenant_id, client_uuid);
CREATE INDEX ey_pickup_events_day ON ey_pickup_events (tenant_id, establishment_id, school_day, learner_id);

CREATE TABLE ey_late_alerts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_day VARCHAR(10) NOT NULL,
    alert_level VARCHAR(10) NOT NULL,         -- parent, director
    sent_at VARCHAR(32) NOT NULL,
    delivery_ref VARCHAR(40) NULL,
    resolved_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX ey_late_alerts_once ON ey_late_alerts (tenant_id, learner_id, school_day, alert_level);

CREATE TABLE ey_custody_restrictions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    restricted_name VARCHAR(160) NOT NULL,
    restricted_person_id CHAR(26) NULL,
    restricted_pickup_id CHAR(26) NULL,
    nature VARCHAR(20) NOT NULL,              -- no_pickup, no_contact, other
    decision_reference VARCHAR(120) NOT NULL,
    decision_document_ref VARCHAR(120) NULL,
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    status VARCHAR(10) NOT NULL,              -- active, lifted
    recorded_by VARCHAR(40) NOT NULL,
    lifted_reason VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX ey_custody_learner ON ey_custody_restrictions (tenant_id, establishment_id, learner_id, status);

CREATE TABLE ey_pickup_codes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    pickup_id CHAR(26) NOT NULL,
    code_hash VARCHAR(128) NOT NULL,          -- HMAC-SHA256(secret établissement, code), jamais en clair
    valid_on VARCHAR(10) NOT NULL,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL,              -- issued, used, blocked, expired
    used_event_id CHAR(26) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    used_at VARCHAR(32) NULL
);
CREATE INDEX ey_pickup_codes_day ON ey_pickup_codes (tenant_id, establishment_id, valid_on, learner_id);

CREATE TABLE ey_pickup_cards (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    pickup_id CHAR(26) NOT NULL,
    document_id VARCHAR(40) NOT NULL,
    status VARCHAR(10) NOT NULL,              -- valid, revoked
    created_at VARCHAR(32) NOT NULL,
    revoked_at VARCHAR(32) NULL
);
CREATE INDEX ey_pickup_cards_pickup ON ey_pickup_cards (tenant_id, pickup_id);

CREATE TABLE ey_sync_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_uuid VARCHAR(40) NOT NULL,
    batch_kind VARCHAR(16) NOT NULL,          -- pickup, observation
    user_id VARCHAR(40) NOT NULL,
    device_ref VARCHAR(80) NULL,
    package_generated_at VARCHAR(32) NULL,
    items_count INTEGER NOT NULL,
    accepted_count INTEGER NOT NULL,
    duplicate_count INTEGER NOT NULL,
    rejected_count INTEGER NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    received_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX ey_sync_batches_uuid ON ey_sync_batches (tenant_id, batch_uuid);
```
Changements par rapport à `pn_*` : colonne `app_key` ajoutée (qui a écrit), aucune autre. La colonne ne porte pas de logique ; elle sert à l'audit.

## 2. Développement · `Socle/EarlyYears/Database/Migrations/<date>_early_years.sql` (option A)
Colonnes identiques à `pn_dev_frames`, `pn_dev_domains`, `pn_dev_items`, `pn_observations`, `pn_progress_books`, `pn_lifebook_entries`, `pn_lifebook_media`, `pn_need_reports` de [004 data-model](../004-applications-primaire-maternelle-publiques/maternelle/data-model.md), renommées `ey_dev_frames`, `ey_dev_domains`, `ey_dev_items`, `ey_observations`, `ey_progress_books`, `ey_lifebook_entries`, `ey_lifebook_media`, `ey_need_reports`, avec deux écarts :
- chaque table de **contenu d'établissement** garde `tenant_id` et `establishment_id` ; `app_key VARCHAR(24) NOT NULL` est ajouté aux observations, carnets, entrées de cahier de vie et besoins ;
- le **référentiel** (`ey_dev_frames`, `domains`, `items`) porte `scope VARCHAR(10) NOT NULL` (`platform` : livré par la plateforme, marqué à valider ; `tenant` : copie adaptée par l'école privée ; `national` : réservé à `public_nursery`) et `origin VARCHAR(10) NOT NULL` (`example`, `official`, `custom`). Un référentiel `official` exige `validation_ref`. L'école privée ne modifie qu'une copie `tenant` (MQ-08).
Contraintes conservées : `level_code` limité aux codes de `nursery.dev.levels` (validation applicative, pas de seuil codé) ; aucune colonne `score`, `rank`, `total`, `average` (test d'architecture) ; `client_uuid` unique par client pour les observations ; photos du cahier de vie : référence opaque `MediaRef` (`MediaStore`), statut `pending_tagging`, `ready`, `withheld`, `published`, `purged`.

## 3. Tables propres à `Nursery` · `Nursery/<Module>/Database/Migrations/`

### 3.1 Transition · `Transition/Database/Migrations/<date>_nursery_transition.sql`
```sql
CREATE TABLE nu_transition_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    from_structure_id VARCHAR(40) NOT NULL,
    proposal VARCHAR(16) NOT NULL,            -- promote, to_primary
    decision VARCHAR(16) NULL,                -- promote, to_primary, retain
    educator_opinion VARCHAR(500) NULL,
    educator_id VARCHAR(40) NULL,
    decision_reason VARCHAR(500) NULL,
    parent_request_ref VARCHAR(120) NULL,     -- exigé seulement si la règle MQ-09 l'impose
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    status VARCHAR(12) NOT NULL,              -- proposed, opined, decided, applied
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_transition_once ON nu_transition_decisions (tenant_id, learner_id, school_year);

CREATE TABLE nu_liaison_sheets (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    path_summary VARCHAR(1000) NULL,
    strengths VARCHAR(1000) NULL,             -- niveaux « acquis » ou « en cours » seulement
    needs VARCHAR(1000) NULL,                 -- formulés positivement
    status VARCHAR(10) NOT NULL,              -- draft, signed, issued
    document_id VARCHAR(40) NULL,
    signed_by VARCHAR(40) NULL,
    signed_at VARCHAR(32) NULL,
    destination_establishment_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_liaison_once ON nu_liaison_sheets (tenant_id, learner_id, school_year);
```
Aucune colonne de restriction de garde ni d'allergie dans la fiche (minimisation).

### 3.2 Services · `Services/Database/Migrations/<date>_nursery_services.sql`
```sql
CREATE TABLE nu_service_plans (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    kind VARCHAR(12) NOT NULL,                -- daycare, canteen, transport
    label VARCHAR(160) NOT NULL,
    fee_item_id VARCHAR(40) NULL,             -- poste de frais du socle (Billing)
    billing_cycle VARCHAR(12) NOT NULL,       -- month, term, year
    active SMALLINT NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL
);
CREATE INDEX nu_service_plans_kind ON nu_service_plans (tenant_id, establishment_id, kind);

CREATE TABLE nu_service_subscriptions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    plan_id CHAR(26) NOT NULL,
    starts_on VARCHAR(10) NOT NULL,
    ends_on VARCHAR(10) NULL,
    status VARCHAR(10) NOT NULL,              -- active, ended
    last_invoiced_cycle VARCHAR(12) NULL,     -- évite la double facturation d'un cycle
    created_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX nu_service_sub_once ON nu_service_subscriptions (tenant_id, learner_id, plan_id, starts_on);
```
Le montant n'est pas dans cette table : il vient du poste de frais du socle (`fee_item_id`).

### 3.3 Registre et paramètres
Pas de table propre : le registre du jour est calculé depuis `ey_pickup_events` et rendu par `Documents` ; les paramètres `nursery.*` sont dans le `/settings` du socle. L'**historique d'exemple** (fixtures) est dans `tests/Fixtures/Nursery/`, préfixé par module.

## 4. Données de plateforme (pas du code de socle)
- `database/migrations/<date>_roles_nursery.sql` : rôles `nursery_promoteur`, `nursery_directeur`, `nursery_educator`, `nursery_assistant`, `nursery_secretaire`, `nursery_cantine` et `role_permissions` des permissions `nursery.*` (une nouvelle migration ; miroir `web/src/lib/permissions.ts` et test de parité).
- `database/migrations/<date>_message_templates_nursery.sql` : modèles bilingues NOT-MAT-01 à 11 avec les seules variables de `TemplateRenderer::ALLOWED` (variables manquantes à ajouter par le propriétaire de Messaging).
- Catalogue : la ligne `nursery` reste `coming_soon` ; le passage à `available` est une migration de données séparée (T1096), faite seulement après accord écrit.
- Horodatage de toute migration : ≤ date de fusion, ordre des dépendances conservé.

## 5. Export et droit d'accès
`privacy.tenant_data_source.nursery` couvre toutes les tables `nu_*` et, par l'option A, les lignes `ey_*` du client ; `privacy.subject_data_source.nursery` rend ce que l'application détient sur un enfant ou une personne autorisée. Les photos sont exportées par `MediaStore`.

## 6. Volumétrie indicative (à mesurer au pilote)
Une école de 100 enfants : environ 100 événements de sortie par jour scolaire, 1 000 à 3 000 observations par trimestre, 20 à 60 entrées de cahier de vie par trimestre. Index de la section 1 suffisants à cette échelle ; pas de partitionnement.
