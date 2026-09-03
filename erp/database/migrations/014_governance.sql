-- Gouvernance : activation des comptes, instruction des dossiers, modeles de
-- courrier, annonces, assistance.
--
-- Cinq manques constates a la lecture de la specification, et qui tiennent
-- tous a la meme chose : ce qui se passe entre le moment ou un compte est cree
-- et celui ou il sert reellement.

-- --- Activation d'un compte ------------------------------------------------
--
-- Un mot de passe provisoire transmis par courrier a circule en clair : il ne
-- doit pas rester en service. Le compte porte donc l'obligation de le changer,
-- et le lien d'activation expire — un lien valable indefiniment retrouve dans
-- une boite six mois plus tard vaut acces permanent.

ALTER TABLE users ADD COLUMN must_change_password TINYINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN activation_token VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN activation_expires_at DATETIME NULL;
ALTER TABLE users ADD COLUMN activated_at DATETIME NULL;
ALTER TABLE users ADD COLUMN mfa_confirmed_at DATETIME NULL;

CREATE INDEX users_activation_token_index ON users (activation_token);

-- --- Instruction des dossiers ----------------------------------------------
--
-- Un dossier incomplet n'a pas a etre refuse : il lui manque une piece. Sans
-- moyen de la demander, le Super Admin refusait ou laissait tramer, et le
-- demandeur ne savait pas quoi corriger.

ALTER TABLE establishment_requests ADD COLUMN legal_authorization VARCHAR(255) NULL;
ALTER TABLE establishment_requests ADD COLUMN accreditation_number VARCHAR(120) NULL;
ALTER TABLE establishment_requests ADD COLUMN reviewed_at DATETIME NULL;
ALTER TABLE establishment_requests ADD COLUMN activated_at DATETIME NULL;

CREATE TABLE establishment_request_messages (
    id CHAR(36) NOT NULL PRIMARY KEY,
    request_id CHAR(36) NOT NULL,
    -- Qui parle : 'ADMIN' pour la plateforme, 'APPLICANT' pour le demandeur.
    -- L'auteur cote demandeur n'a pas de compte, d'ou l'absence de user_id.
    author_side VARCHAR(20) NOT NULL,
    author_user_id CHAR(36) NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT erm_request_fk FOREIGN KEY (request_id) REFERENCES establishment_requests (id)
);

CREATE INDEX erm_request_index ON establishment_request_messages (request_id, created_at);

-- --- Modeles de courrier ----------------------------------------------------
--
-- Les textes vivaient dans le code : les corriger supposait un deploiement, et
-- personne hors de l'equipe technique ne pouvait relire ce qui partait au nom
-- de la plateforme.

CREATE TABLE email_templates (
    id CHAR(36) NOT NULL PRIMARY KEY,
    -- Cle de l'evenement declencheur : request.acknowledge, request.approved...
    template_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    -- Variables admises, pour que l'ecran les rappelle a qui edite.
    variables TEXT NULL,
    updated_by CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE UNIQUE INDEX email_templates_key_unique ON email_templates (template_key);

-- --- Annonces de la plateforme ---------------------------------------------

CREATE TABLE announcements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    -- INFO, MAINTENANCE ou SECURITE : le ton de l'affichage en depend.
    level VARCHAR(20) NOT NULL DEFAULT 'INFO',
    -- Fenetre d'affichage. Une annonce de maintenance annoncee pour mardi n'a
    -- rien a faire a l'ecran le mercredi.
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    is_published TINYINT NOT NULL DEFAULT 0,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE INDEX announcements_window_index ON announcements (is_published, starts_at, ends_at);

-- --- Assistance -------------------------------------------------------------
--
-- Les etablissements ecrivaient par WhatsApp : rien ne restait, et deux
-- personnes traitaient la meme demande sans le savoir.

CREATE TABLE support_tickets (
    id CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id CHAR(36) NULL,
    opened_by CHAR(36) NULL,
    reference VARCHAR(20) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    -- OPEN, PENDING (en attente du demandeur), RESOLVED, CLOSED
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    priority VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    closed_at DATETIME NULL
);

CREATE UNIQUE INDEX support_tickets_reference_unique ON support_tickets (reference);
CREATE INDEX support_tickets_status_index ON support_tickets (status, updated_at);

CREATE TABLE support_messages (
    id CHAR(36) NOT NULL PRIMARY KEY,
    ticket_id CHAR(36) NOT NULL,
    author_user_id CHAR(36) NULL,
    author_side VARCHAR(20) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT support_messages_ticket_fk FOREIGN KEY (ticket_id) REFERENCES support_tickets (id)
);

CREATE INDEX support_messages_ticket_index ON support_messages (ticket_id, created_at);
