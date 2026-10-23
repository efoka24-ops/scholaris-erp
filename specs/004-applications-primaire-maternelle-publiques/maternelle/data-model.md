# Maternelle publique : modèle de données

Date : 2026-10-15. Préfixe **`pn_`** (PublicNursery, cohérent avec `pp_` de `PublicPrimary`) ; `nu_` du CDC est laissé à la future maternelle privée `Nursery`.
Règles (HANDOVER §3) : ULID `CHAR(26)`, dates ISO `VARCHAR(32)`, jours `VARCHAR(10)` (AAAA-MM-JJ), `tenant_id` et `establishment_id` sur chaque table d'établissement, aucune clé étrangère, aucun AUTO_INCREMENT/ENGINE, aucun index sur TEXT, aucun mot réservé MySQL (on écrit `level_code`, `remark`, `origin`, `school_day`, `content_hash`).
Les personnes autorisées restent dans la table du socle `authorized_pickups` (lue et écrite par PM1).

Migrations : `src/Modules/Apps/PublicNursery/<Module>/Database/Migrations/AAAAMMJJHHMM_public_nursery_<module>.sql`.

## 1. SOR · `Pickup/Database/Migrations/202610200900_public_nursery_pickup.sql`

```sql
-- Journal unique des arrivées, remises, refus et alertes (source de DOC-19 et du contrôle O7).
CREATE TABLE pn_gate_events (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NULL,
    school_day VARCHAR(10) NOT NULL,
    event_type VARCHAR(24) NOT NULL,          -- arrival, handed_over, refused, kept_at_school, code_failed
    pickup_id CHAR(26) NULL,                  -- authorized_pickups.id, obligatoire pour handed_over
    code_id CHAR(26) NULL,                    -- pn_pickup_codes.id si remise par code
    declared_name VARCHAR(160) NULL,          -- nom déclaré par une personne refusée (jamais utilisé pour remettre)
    restriction_id CHAR(26) NULL,             -- restriction de garde touchée
    stale_authorization SMALLINT NOT NULL DEFAULT 0,
    origin VARCHAR(10) NOT NULL,              -- online, offline
    client_uuid VARCHAR(40) NULL,
    occurred_at VARCHAR(32) NOT NULL,         -- heure de l'appareil
    recorded_at VARCHAR(32) NOT NULL,         -- heure du serveur
    actor_id VARCHAR(40) NOT NULL,
    remark VARCHAR(500) NULL
);
CREATE UNIQUE INDEX pn_gate_events_client ON pn_gate_events (tenant_id, client_uuid);
CREATE INDEX pn_gate_events_day ON pn_gate_events (tenant_id, establishment_id, school_day, learner_id);

-- Une alerte par enfant, par jour et par niveau (idempotence de la veille EX-SOR-04).
CREATE TABLE pn_late_alerts (
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
CREATE UNIQUE INDEX pn_late_alerts_once ON pn_late_alerts (tenant_id, learner_id, school_day, alert_level);

-- Restrictions de garde (RG-SOR-2) : visibles du personnel de la section et du directeur seulement.
CREATE TABLE pn_custody_restrictions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    restricted_name VARCHAR(160) NOT NULL,
    restricted_person_id CHAR(26) NULL,       -- si la personne visée est un parent connu (People)
    restricted_pickup_id CHAR(26) NULL,       -- si elle figurait dans authorized_pickups
    nature VARCHAR(20) NOT NULL,              -- no_pickup, no_contact, other
    decision_reference VARCHAR(120) NOT NULL,
    decision_document_ref VARCHAR(120) NULL,  -- MediaRef (PM2) de la pièce numérisée
    valid_from VARCHAR(10) NOT NULL,
    valid_to VARCHAR(10) NULL,
    status VARCHAR(10) NOT NULL,              -- active, lifted
    recorded_by VARCHAR(40) NOT NULL,
    lifted_reason VARCHAR(500) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX pn_custody_learner ON pn_custody_restrictions (tenant_id, establishment_id, learner_id, status);

-- Codes à usage unique (EX-SOR-03), jamais en clair.
CREATE TABLE pn_pickup_codes (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    pickup_id CHAR(26) NOT NULL,              -- autorisation « day » créée via PM1
    code_hash VARCHAR(128) NOT NULL,          -- HMAC-SHA256(secret établissement, code)
    valid_on VARCHAR(10) NOT NULL,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL,              -- issued, used, blocked, expired
    used_event_id CHAR(26) NULL,
    created_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    used_at VARCHAR(32) NULL
);
CREATE INDEX pn_pickup_codes_day ON pn_pickup_codes (tenant_id, establishment_id, valid_on, learner_id);

-- Cartes DOC-21 : lien autorisation → document émis, révoqué avec elle.
CREATE TABLE pn_pickup_cards (
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
CREATE INDEX pn_pickup_cards_pickup ON pn_pickup_cards (tenant_id, pickup_id);

-- Lots hors ligne reçus (SOR et DEV) : idempotence et diagnostic.
CREATE TABLE pn_sync_batches (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    batch_uuid VARCHAR(40) NOT NULL,
    batch_kind VARCHAR(16) NOT NULL,          -- pickup, observation
    user_id VARCHAR(40) NOT NULL,
    device_ref VARCHAR(80) NULL,
    package_generated_at VARCHAR(32) NULL,    -- âge de la liste du matin utilisée
    items_count INTEGER NOT NULL,
    accepted_count INTEGER NOT NULL,
    duplicate_count INTEGER NOT NULL,
    rejected_count INTEGER NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    received_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_sync_batches_uuid ON pn_sync_batches (tenant_id, batch_uuid);
```

## 2. DEV · `Development/Database/Migrations/202610270900_public_nursery_development.sql`

```sql
-- Référentiel national versionné (EX-DEV-01) ; tenant = client national, pas d'établissement.
CREATE TABLE pn_dev_frames (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    frame_code VARCHAR(40) NOT NULL,
    version INTEGER NOT NULL,
    subsystem VARCHAR(2) NOT NULL,            -- fr, en
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    status VARCHAR(10) NOT NULL,              -- draft, active, retired
    validation_ref VARCHAR(120) NULL,         -- référence de validation par l'Inspection générale
    created_at VARCHAR(32) NOT NULL,
    activated_at VARCHAR(32) NULL
);
CREATE UNIQUE INDEX pn_dev_frames_version ON pn_dev_frames (tenant_id, frame_code, version);

CREATE TABLE pn_dev_domains (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(160) NOT NULL,
    label_en VARCHAR(160) NOT NULL,
    sort_order INTEGER NOT NULL
);
CREATE UNIQUE INDEX pn_dev_domains_code ON pn_dev_domains (tenant_id, frame_id, code);

CREATE TABLE pn_dev_items (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    code VARCHAR(40) NOT NULL,
    label_fr VARCHAR(300) NOT NULL,
    label_en VARCHAR(300) NOT NULL,
    section_levels VARCHAR(60) NOT NULL,      -- ex. "ps,ms,gs"
    sort_order INTEGER NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX pn_dev_items_code ON pn_dev_items (tenant_id, frame_id, code);

-- Observation (EX-DEV-02) : trois niveaux seulement, aucune colonne de note ni de rang (RG-DEV-1).
CREATE TABLE pn_observations (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    frame_id CHAR(26) NOT NULL,
    domain_id CHAR(26) NOT NULL,
    item_id CHAR(26) NOT NULL,
    level_code VARCHAR(12) NOT NULL,          -- acquired, in_progress, not_yet
    remark VARCHAR(500) NULL,
    observed_on VARCHAR(10) NOT NULL,
    observer_id VARCHAR(40) NOT NULL,
    client_uuid VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_observations_client ON pn_observations (tenant_id, client_uuid);
CREATE INDEX pn_observations_learner ON pn_observations (tenant_id, establishment_id, learner_id, observed_on);

-- Carnet trimestriel (EX-DEV-03, DOC-18) ; snapshot = niveaux figés à la validation.
CREATE TABLE pn_progress_books (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    period_id VARCHAR(40) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    status VARCHAR(12) NOT NULL,              -- draft, submitted, validated, published
    synthesis TEXT NULL,
    snapshot TEXT NULL,
    edition INTEGER NOT NULL DEFAULT 1,
    edition_label VARCHAR(12) NULL,           -- duplicate, rectified
    validated_by VARCHAR(40) NULL,
    validated_at VARCHAR(32) NULL,
    published_at VARCHAR(32) NULL,
    document_id VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_progress_books_period ON pn_progress_books (tenant_id, learner_id, period_id);

-- Banque de formulations positives (nationale).
CREATE TABLE pn_phrase_bank (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    domain_code VARCHAR(40) NULL,
    text_fr VARCHAR(300) NOT NULL,
    text_en VARCHAR(300) NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1
);

-- Cahier de vie (EX-DEV-04, RG-DEV-2, RG-DEV-3).
CREATE TABLE pn_lifebook_entries (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    structure_id VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NULL,
    activity_on VARCHAR(10) NOT NULL,
    author_id VARCHAR(40) NOT NULL,
    status VARCHAR(10) NOT NULL,              -- draft, published, archived
    published_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX pn_lifebook_entries_section ON pn_lifebook_entries (tenant_id, establishment_id, structure_id, activity_on);

CREATE TABLE pn_lifebook_media (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    entry_id CHAR(26) NOT NULL,
    media_ref VARCHAR(200) NOT NULL,          -- MediaRef (PM2)
    mime_type VARCHAR(40) NOT NULL,
    size_bytes INTEGER NOT NULL,
    masked_from_id CHAR(26) NULL,             -- version masquée d'un média retenu
    no_child_visible SMALLINT NOT NULL DEFAULT 0,
    tagging_done SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL,              -- pending_tags, withheld, publishable, purged
    purge_after VARCHAR(10) NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    purged_at VARCHAR(32) NULL
);
CREATE INDEX pn_lifebook_media_entry ON pn_lifebook_media (tenant_id, entry_id);
CREATE INDEX pn_lifebook_media_purge ON pn_lifebook_media (tenant_id, status, purge_after);

CREATE TABLE pn_lifebook_media_children (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    media_id CHAR(26) NOT NULL,
    learner_id CHAR(26) NOT NULL
);
CREATE UNIQUE INDEX pn_lifebook_media_child ON pn_lifebook_media_children (tenant_id, media_id, learner_id);
CREATE INDEX pn_lifebook_child_learner ON pn_lifebook_media_children (tenant_id, learner_id);

-- Besoin particulier (EX-DEV-05) : pas de diagnostic médical.
CREATE TABLE pn_need_reports (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    need_area VARCHAR(12) NOT NULL,           -- language, vision, hearing, other
    summary VARCHAR(500) NOT NULL,
    status VARCHAR(10) NOT NULL,              -- open, oriented, closed
    orientation_note VARCHAR(500) NULL,
    reported_by VARCHAR(40) NOT NULL,
    reported_at VARCHAR(32) NOT NULL,
    parents_notified_at VARCHAR(32) NULL,
    closed_at VARCHAR(32) NULL
);
CREATE INDEX pn_need_reports_learner ON pn_need_reports (tenant_id, establishment_id, learner_id, status);
```

## 3. TRA · `Transition/Database/Migrations/202611030900_public_nursery_transition.sql`

```sql
CREATE TABLE pn_transition_decisions (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    from_level VARCHAR(8) NOT NULL,           -- ps, ms, gs
    proposal VARCHAR(12) NOT NULL,            -- promote, to_primary
    decision VARCHAR(12) NULL,                -- promote, to_primary, retain
    educator_opinion VARCHAR(500) NULL,
    parent_request_ref VARCHAR(120) NULL,     -- obligatoire si retain
    parent_request_on VARCHAR(10) NULL,
    inspector_opinion VARCHAR(12) NULL,       -- favourable, unfavourable ; obligatoire si retain
    inspector_id VARCHAR(40) NULL,
    inspector_on VARCHAR(10) NULL,
    decided_by VARCHAR(40) NULL,
    decided_at VARCHAR(32) NULL,
    status VARCHAR(10) NOT NULL,              -- proposed, decided, applied
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_transition_year ON pn_transition_decisions (tenant_id, learner_id, school_year);

CREATE TABLE pn_liaison_sheets (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    learner_id CHAR(26) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    path_summary TEXT NULL,
    strengths TEXT NULL,
    needs TEXT NULL,
    status VARCHAR(10) NOT NULL,              -- draft, signed, sent
    signed_by VARCHAR(40) NULL,
    signed_at VARCHAR(32) NULL,
    document_id VARCHAR(40) NULL,
    target_establishment_id VARCHAR(40) NULL,
    sent_at VARCHAR(32) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX pn_liaison_year ON pn_liaison_sheets (tenant_id, learner_id, school_year);
```

## 4. Invariants testés
- `pn_gate_events.event_type = 'handed_over'` implique `pickup_id` non nul (valide ce jour au moment de `occurred_at`) **ou** `code_id` d'un code `used` ; vérifié par le service et par la commande `public-nursery:pickup-audit` (SC-1).
- `pn_observations.level_code` ∈ {`acquired`, `in_progress`, `not_yet`} ; test d'architecture : aucune colonne `pn_*` nommée `score`, `mark`, `grade`, `rank*`, `average`.
- Un média `publishable` a `tagging_done = 1` et tous ses enfants étiquetés ont un consentement écrit au moment de **chaque** lecture (sinon il n'est pas servi).
- `pn_transition_decisions.decision = 'retain'` implique `parent_request_ref` et `inspector_opinion` non nuls.
- Aucune table `pn_*` ne contient de champ médical ni de mention APEE.
