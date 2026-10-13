# Contrat d'API — conventions `/api/v1`

| Sujet | Règle |
|-------|-------|
| Préfixe | `/api/v1` ; retrait d'une version majeure après 12 mois de préavis |
| Contexte | En-tête `X-Establishment-Id` obligatoire pour toute route métier ; absent ou non autorisé → 403 |
| Authentification | Sanctum : cookie (web) ou `Authorization: Bearer` (mobile) |
| Langue | `Accept-Language: fr | en` |
| Pagination | `?cursor=…&per_page=50` (max 200) ; réponse `data`, `links`, `meta.next_cursor` |
| Filtres, tri | `filter[champ]=…`, `sort=-created_at` ; liste blanche par ressource |
| Idempotence | `Idempotency-Key` obligatoire sur `POST /payments`, `POST /cash/receipts`, `POST /documents` ; rejeu → même réponse |
| Montants | entiers XAF (`"amount": 31600`) |
| Dates | ISO 8601 avec fuseau (`2026-10-08T09:00:00+01:00`) |
| Erreurs | RFC 9457 `application/problem+json` : `type`, `title`, `status`, `detail`, `errors`, `trace_id` |
| Limitation | Throttle par utilisateur, IP et route sensible (connexion, OTP, paiement) → 429 + `Retry-After` |
| Webhooks entrants | Signature HMAC, IP autorisée, `event_id` unique (rejeu ignoré) |

## Ressources principales (première passe)

| Service | Ressources |
|---------|------------|
| S01 | `POST /auth/login`, `/auth/otp/verify`, `/auth/2fa/*`, `GET /me`, `GET /me/contexts`, `POST /me/context`, `DELETE /me/sessions`, `/roles`, `/role-assignments`, `/service-accounts` |
| S02 | `/tenants`, `/establishments`, `/org-units`, `/academic-years`, `/periods`, `/structures`, `/settings`, `/authorizations`, `/rooms` |
| S03 | `/people`, `/people/{id}/relations`, `/people/{id}/documents`, `/consents`, `/enrollments` |
| S04 | `/fee-items`, `/fee-schedules`, `/invoices`, `/payments`, `/receipts`, `/cash-sessions`, `/reversals`, `/reconciliations`, `POST /webhooks/payments/{provider}` |
| S05 | `/message-templates`, `/messages`, `/announcements` |
| S06 | `/document-templates`, `/documents`, `/document-batches`, `GET /public/verify/{token}` (sans authentification) |
| S07 | `/calendar-events`, `/timetable-slots`, `/timetable-changes` |
| S08 | `/attendance-sessions`, `/attendance-records`, `/incidents` |
| S09 | `/subjects`, `/assessments`, `/grades`, `/calculation-rules`, `/results`, `/deliberations` |
| S10 | `/staff`, `/vacation-statements` |
| S12/S13 | `/dashboards/{role}`, `/audit-log`, `/data-requests`, `/tenant-exports` |

Exemple d'erreur : voir CDC, partie « Conception des API ». Le schéma OpenAPI 3.1 est généré par `dedoc/scramble` et exposé sur `/docs/api` (environnements non publics).
