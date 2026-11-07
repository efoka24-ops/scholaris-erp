# Data Model — Socle SCHOLARIS

Conventions : clés primaires ULID ; `tenant_id` sur toute table métier (indexé en tête des index composés) ; `created_at`, `updated_at`, `deleted_at` (suppression logique selon durées de conservation) ; montants en entiers XAF ; dates en `Africa/Douala` (UTC+1 fixe, sans heure d'été), fuseau de l'application.

## S01 Identité
- **users** : id, tenant_id, person_id, phone?, email?, password, locale, status (pending, active, locked), locked_until, failed_attempts, two_factor_secret, two_factor_confirmed_at. Unique (tenant_id, phone), unique (tenant_id, email).
- **one_time_codes** : id, user_id, channel, code_hash, expires_at (5 min), attempts (max 3), consumed_at.
- **personal_access_tokens** (Sanctum), **devices** (user_id, fingerprint, last_seen_at, trusted).
- **role_assignments** : id, tenant_id, person_id, role, scope_type (tenant, establishment, org_unit, class), scope_id, starts_at, ends_at, delegated_by?
- **service_accounts** : id, tenant_id, name, scopes, key_hash, rotated_at.

## S02 Organisation
- **tenants** : id, name, status, plan.
- **establishments** : id, tenant_id, type (nursery, primary, secondary, higher), name, branding (json), modules_enabled (json).
- **org_units** : id, establishment_id, parent_id, type, name.
- **academic_years** : id, establishment_id, label, starts_on, ends_on, status (open, closed) ; **periods** : id, academic_year_id, kind, ordinal, starts_on, ends_on.
- **pedagogical_structures** : id, establishment_id, org_unit_id, kind (level, class, group, track), name, capacity.
- **authorizations** : id, establishment_id, structure_id, reference, valid_from, valid_to.
- **settings** : id, scope_type (platform, tenant, establishment), scope_id, key, value (json), updated_by ; vue « effective » calculée par héritage ; historique dans `audit_log`.
- **rooms** : id, establishment_id, name, capacity, equipment.

## S03 Personnes
- **people** : id, tenant_id, civil identity, birth_date, gender, locale, photo_path ; champs sensibles chiffrés.
- **person_contacts**, **person_relations** (person_id, related_id, type : parent, guardian, financial_guarantor, authorized_pickup, emergency), **person_identifiers** (kind : internal, national ; establishment_id?).
- **person_documents** : id, person_id, kind, path, expires_at, status.
- **health_records** : person_id, allergies, doctor_contact (chiffré, rôles restreints).
- **consents** : id, person_id, purpose, granted_at, revoked_at.
- **enrollments** : id, person_id, establishment_id, structure_id, academic_year_id, status, internal_number.
- **schooling_history** : issue de `enrollments` + décisions.

## S04 Frais et paiements
- **fee_items** : id, establishment_id, kind, name, amount.
- **fee_schedules** : id, fee_item_id, structure_id?, academic_year_id, total.
- **installments** : id, schedule_id, due_on, amount.
- **invoices / invoice_lines** : id, enrollment_id, schedule_id, status, balance.
- **discounts** : id, kind (sibling, staff, merit, waiver), rule (json), approved_by.
- **payment_references** : id, payer_person_id or person_id, reference unique.
- **payments** : id, establishment_id?, payer_id, channel (orange_money, mtn_momo, card, transfer, cash), amount, external_reference unique, idempotency_key unique, status (pending, confirmed, failed, reversed), received_at.
- **allocations** : id, payment_id, installment_id, amount, rule_version.
- **receipts** : id, payment_id, establishment_id, year, number (sans trou), pdf_path, sms_sent_at.
- **cash_sessions** : id, establishment_id, cashier_id, opened_at, closed_at, expected, counted, variance.
- **reversals** : id, payment_id, reason, requested_by, approved_by (≠ requested_by), approved_at.
- **reminders** : id, rule, schedule, status.
- **number_sequences** : tenant_id, establishment_id, kind, year, last (verrou transactionnel).

## S05 Communication
- **message_templates** : id, tenant_id, key, locale, channel, body.
- **messages** : id, establishment_id, template_id, audience (json), status, scheduled_at.
- **message_deliveries** : id, message_id, person_id, channel, provider_ref, status, delivered_at.
- **sms_usage** : establishment_id, month, count.
- **announcements**, **conversations / conversation_messages** (Should).

## S06 Documents
- **document_templates** : id, establishment_id, type, locale, html, version.
- **documents** : id, establishment_id, template_id, subject_person_id, number unique, qr_token unique, status (valid, revoked), version, issued_by, issued_at, file_path.
- **document_batches** : id, establishment_id, status, total, done.

## S07 Calendrier
- **calendar_events** : id, establishment_id, kind, starts_at, ends_at, title.
- **timetable_slots** : id, establishment_id, structure_id, teacher_id, room_id, subject_id, weekday, start, end, valid_from, valid_to ; contrainte de non-chevauchement vérifiée par service.
- **timetable_changes** : slot_id, date, kind (cancelled, moved, replaced), replacement.

## S08 Présences
- **attendance_sessions** : id, slot_id, date, taken_by, client_uuid unique (idempotence hors-ligne).
- **attendance_records** : session_id, person_id, status (present, absent, late), justified, justification_id.
- **incidents / sanctions** : id, person_id, kind, scale_ref, status, validated_by.

## S09 Évaluation
- **subjects** (matières/UE), **subject_elements**, avec coefficients ou crédits.
- **assessments** : id, subject_id, period_id, kind, weight, max, status (draft, locked, published).
- **grades** : id, assessment_id, person_id, value, status, entered_by, client_uuid.
- **grade_events** : id, grade_id, old_value, new_value, author, reason, at, approved_by? (historique, append-only).
- **calculation_rules** : id, establishment_id, academic_year_id, version, definition (json déclaratif).
- **results** : id, person_id, period_id, average, rank, credits, decision, rule_version.
- **deliberations** : id, structure_id, period_id, minutes_document_id, status.

## S10 Personnel
- **staff_members** : id, person_id, status ; **contracts**, **assignments** (establishment_id, role).
- **teaching_logs** (cahier de textes) : id, slot_id, date, hours, content ; **vacation_rates** : grade, establishment_id, rate ; **vacation_statements** : id, staff_id, period, hours, status (draft, validated, paid), validated_by.
- **leaves**, **payroll_items** (Could).

## S13 Audit
- **audit_log** : id, tenant_id, actor_id, action, object_type, object_id, before, after, ip, device, at, prev_hash, hash. Append-only.
- **approvals** : id, kind, subject_type, subject_id, requested_by, approved_by (≠ requested_by), status, reason.
- **data_requests** : id, person_id, kind (export, rectify, erase), status.
- **tenant_exports** : id, tenant_id, status, path, requested_at.

## S14 Intégrations
- **integration_accounts** : id, tenant_id, provider, config (chiffré).
- **webhook_events** : id, provider, event_id unique, payload, signature_ok, processed_at.
- **idempotency_keys** : key, route, request_hash, response, expires_at.

## Transitions d'état
- Note : `draft → locked → published` ; `locked → (demande motivée + approbation) → corrected`.
- Paiement : `pending → confirmed | failed` ; `confirmed → reversed` (écriture inverse, second validateur).
- Compte : `pending → active → locked → active`.
- Document : `valid → revoked`.
- Année : `open → closed`.
