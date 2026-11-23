-- Journal des relances SMS du secondaire (E4-B) : NOT-07 échéance à venir et NOT-08 tranche en retard [fees_private], NOT-10 frais
-- d'examen non constatés sur cartescolaire.cm avant la date limite. Une relance n'est jamais envoyée deux fois pour le même objet
-- (tranche et décalage, ou session d'examen et candidat) au même destinataire. Aucun montant ni donnée de santé n'est stocké ici.

CREATE TABLE se_fee_notices (
    id CHAR(26) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(40) NOT NULL,
    establishment_id VARCHAR(40) NOT NULL,
    notice_code VARCHAR(8) NOT NULL,
    object_ref VARCHAR(120) NOT NULL,
    learner_id VARCHAR(40) NOT NULL,
    recipient_person_id VARCHAR(40) NOT NULL,
    delivery_status VARCHAR(12) NOT NULL,
    created_at VARCHAR(32) NOT NULL
);

CREATE UNIQUE INDEX se_fee_notices_once ON se_fee_notices (tenant_id, establishment_id, notice_code, object_ref, recipient_person_id);
