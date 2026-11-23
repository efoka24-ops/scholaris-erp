-- Application secondaire, sous-module CarteScolaire (T828, T829). cartescolaire.cm (MINESEC) n'offre AUCUNE interface publique
-- (recherche-cartescolaire-series-prix.md §2) : l'intégration se fait par fichiers seulement (RG-CSC-1), jamais d'appel réseau.
--  - se_cs_students : matricule unique de chaque élève de l'établissement (EX-CSC-01, RG-INS-1) ; pas de ligne ou national_id
--    NULL = « en attente » ; mismatch = écart d'identité signalé (RG-CSC-2), corrigé par la scolarité après vérification.
--    Le matricule n'apparaît jamais dans une adresse : les routes utilisent l'identifiant interne de la personne.
--  - se_cs_imports : fichiers importés (matricules ou relevé de paiements de l'économe) ; empreinte SHA-256 : un fichier rejoué
--    rend le même import (idempotent) ; rapport des lignes rejetées.
--  - se_cs_discrepancies : écarts à corriger (absent du site, absent de l'application, identité, conflit de matricule, ambigu).
--  - se_cs_payments : paiements constatés (contributions, frais d'examen) ; référence unique par établissement (doublon ignoré).
-- Portable MySQL 8 et SQLite : pas de clé étrangère, pas d'AUTO_INCREMENT, aucun mot réservé ; montants entiers en XAF.

CREATE TABLE se_cs_students (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    person_id VARCHAR(40) NOT NULL,
    national_id VARCHAR(40) NULL,
    id_status VARCHAR(12) NOT NULL,
    origin VARCHAR(12) NOT NULL,
    recorded_at VARCHAR(32) NULL,
    recorded_by VARCHAR(40) NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_cs_students_person ON se_cs_students (tenant_id, establishment_id, person_id);
CREATE UNIQUE INDEX se_cs_students_national ON se_cs_students (tenant_id, establishment_id, national_id);

CREATE TABLE se_cs_imports (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    import_kind VARCHAR(12) NOT NULL,
    file_name VARCHAR(160) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    rows_total INTEGER NOT NULL,
    rows_ok INTEGER NOT NULL,
    rows_rejected INTEGER NOT NULL,
    rows_duplicate INTEGER NOT NULL,
    rows_unknown INTEGER NOT NULL,
    rejected_rows TEXT NOT NULL,
    imported_by VARCHAR(40) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_cs_imports_hash ON se_cs_imports (tenant_id, establishment_id, import_kind, file_hash);

CREATE TABLE se_cs_discrepancies (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    import_id CHAR(26) NULL,
    person_id VARCHAR(40) NULL,
    national_id VARCHAR(40) NULL,
    discrepancy_kind VARCHAR(20) NOT NULL,
    detail VARCHAR(255) NOT NULL,
    remote_label VARCHAR(255) NULL,
    resolved_at VARCHAR(32) NULL,
    resolved_by VARCHAR(40) NULL,
    resolution_note VARCHAR(255) NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE INDEX se_cs_discrepancies_open ON se_cs_discrepancies (tenant_id, establishment_id, resolved_at, discrepancy_kind);

CREATE TABLE se_cs_payments (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    import_id CHAR(26) NOT NULL,
    national_id VARCHAR(40) NOT NULL,
    payment_kind VARCHAR(20) NOT NULL,
    exam_code VARCHAR(20) NULL,
    amount INTEGER NOT NULL,
    paid_on VARCHAR(10) NOT NULL,
    external_ref VARCHAR(80) NOT NULL,
    matched_person_id VARCHAR(40) NULL,
    match_status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_cs_payments_ref ON se_cs_payments (tenant_id, establishment_id, external_ref);
CREATE INDEX se_cs_payments_national ON se_cs_payments (tenant_id, establishment_id, national_id);
