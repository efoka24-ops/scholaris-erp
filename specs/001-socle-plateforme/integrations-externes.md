# Intégrations externes (T138)

État au 2026-10-09. **Rien n'est activé en production** : SMS et e-mail restent sur le journal, le paiement sur le simulateur, tant que les variables d'environnement ne sont pas posées et que `sungku:check` n'est pas vert.

## 1. Mobile money Sungku (Orange Money, MTN MoMo) : mise en service

Code existant : `src/Modules/Socle/Integrations/Infrastructure/Sungku/` (client, passerelle, vérification de webhook), route publique `POST /api/v1/webhooks/payments/sungku`.

### Prérequis côté Sungku (action humaine)
1. Un **projet Sungku propre à SCHOLARIS** (clé API et secret de webhook distincts de tout autre projet).
2. Déclarer chez Sungku l'**URL de webhook en HTTPS** : `https://<domaine public>/api/v1/webhooks/payments/sungku`. Le webhook **exige HTTPS** et un domaine joignable depuis Internet ; sur le site actuel (`http://scholariserp.trugroup.cm`) il faut donc d'abord un certificat TLS.
3. Obtenir de Sungku un webhook de test réel : le vocabulaire de statuts (`CONFIRMED`, `FAILED`, ...) est une hypothèse documentée dans `SungkuWebhook` (« À CONFIRMER »).
4. Vérifier si Sungku limite les appels par adresse IP (liste blanche côté serveur de l'hébergeur).

### Variables d'environnement (`.env` du serveur, jamais dans le dépôt)
```
APP_ENV=production
APP_URL=https://<domaine public>
PAYMENT_GATEWAY=simulator        # passer à « sungku » seulement à la dernière étape
SUNGKU_BASE_URL=https://sungku.trugroup.cm
SUNGKU_API_KEY=<clé du projet>
SUNGKU_WEBHOOK_SECRET=<32 caractères au moins>
```

### Procédure
1. Poser les variables ci-dessus en laissant `PAYMENT_GATEWAY=simulator`.
2. Lancer `php artisan sungku:check` : contrôle **sans appel réseau** (HTTPS de l'URL Sungku et de `APP_URL`, clé et secret présents sans être affichés, tolérance d'horodatage, URL de webhook à déclarer). Code de sortie 0 = prêt, 1 = écarts bloquants.
3. Déclarer l'URL de webhook affichée chez Sungku.
4. Passer `PAYMENT_GATEWAY=sungku`, tester un paiement de montant minimal avec un numéro réel, vérifier le reçu et le rapprochement.
5. En cas de problème : repasser `PAYMENT_GATEWAY=simulator` (retour immédiat, aucune migration).

Garde-fous déjà en code : montant calculé côté serveur, signature HMAC-SHA256 sur `<horodatage>.<corps brut>` comparée en temps constant, rejet hors tolérance (600 s), idempotence des événements, un succès HTTP ne vaut pas paiement (seul le webhook confirme), échec non prouvé (délai, 5xx, 429) jamais traité comme un échec.

## 2. cartescolaire.cm

**Aucune spécification d'API ni de format n'a été fournie.** Rien n'est inventé : le port `Integrations\Contracts\CartescolaireExporter` est implémenté par `CartescolaireFileExporter`, qui écrit un fichier **générique** (CSV ou JSON) :

- commande : `php artisan external:export cartescolaire <client> <établissement> <fichier>` ;
- colonnes dans `config/integrations.php` (`cartescolaire.columns` : en-tête écrit => champ source ; champs disponibles : `person_id`, `internal_number`, `last_name`, `first_name`, `birth_date`, `phone`, `email`, `enrollment_status`, `structure_id`, `academic_year_id`, `establishment_id`, `establishment_name`, `establishment_type`) ; format `csv` (UTF-8 avec BOM, séparateur `;`, cellules neutralisées contre l'injection de formule) ou `json` (variable `CARTESCOLAIRE_FORMAT`) ;
- seuls les élèves à inscription **active** de l'établissement sont exportés ; aucun champ de santé ni pièce d'identité n'est exposable.

### Informations à obtenir de cartescolaire.cm
- Existe-t-il une API (URL, authentification, quotas) ou seulement un dépôt de fichier / une saisie sur portail ?
- Liste exacte des colonnes obligatoires, leur ordre, leurs libellés, séparateur, encodage, format des dates, et format du matricule national / identifiant élève.
- Nomenclature des classes, séries, niveaux et filières attendue (SCHOLARIS exporte aujourd'hui l'identifiant interne de la structure : il faudra une table de correspondance fournie par l'organisme).
- Code officiel de l'établissement (code de la carte scolaire) : à stocker dans les réglages de l'établissement (aujourd'hui absent du modèle).
- Périodicité (rentrée, trimestre, annuelle), obligation de signature / cachet, procédure de correction des rejets.
- Cadre légal de transmission des données d'élèves mineurs (consentement, finalité) : à inscrire au registre des traitements.

## 3. Tutelle (ministère, délégation régionale ou départementale)

Même situation : **aucun format officiel fourni**. Port `Integrations\Contracts\TutelleExporter`, implémentation `TutelleFileExporter` (une ligne par établissement : effectif d'inscrits actifs, enseignants) :

- commande : `php artisan external:export tutelle <client> <établissement> <fichier>` ; colonnes dans `config/integrations.php` (`tutelle.columns`) ; champs : `establishment_id`, `establishment_name`, `establishment_type`, `enrolled_learners`, `teachers`.

### Informations à obtenir de la tutelle
- Quelle autorité exacte reçoit les données (ministère des Enseignements secondaires, de l'Éducation de base, de l'Enseignement supérieur, délégation régionale) et par quel canal.
- Modèle officiel des états statistiques (effectifs par classe, sexe, âge, nationalité ; personnel par grade/diplôme ; résultats aux examens) : SCHOLARIS ne fournit aujourd'hui que les effectifs globaux, des états détaillés demandent les colonnes exactes.
- Calendrier de remise, signataire, format de transmission, accusé de réception.
- Codes officiels (établissement, arrondissement, département, région) : le référentiel géographique du niveau ministère existe côté Organisation, la correspondance avec les codes officiels est à valider.

## 4. Export comptable

Déjà livré (module Billing, hors périmètre de cette tâche) : `GET /api/v1/billing/exports/{kind}` avec `journal`, `sales`, `discounts` (CSV). Le format d'un logiciel comptable particulier (plan comptable OHADA, journal importable) n'est pas spécifié : à confirmer avec le comptable du client.

## 5. SMS et e-mail

Adaptateurs réels déjà présents (`HttpSmsGateway` configurable par gabarit de corps, `SmtpMailGateway`), choisis par configuration (`SMS_GATEWAY=http`, `MAIL_GATEWAY=smtp`) ; le fournisseur SMS réel et ses paramètres restent à fournir. WhatsApp (Should) : non commencé.

## Ce qui dépend de tiers (T138 reste ouverte)
| Élément | Dépend de |
|---|---|
| Mise en service Sungku | clé API, secret, domaine HTTPS, webhook de test, confirmation des statuts |
| cartescolaire.cm | spécification d'API ou de fichier, codes officiels |
| Tutelle | modèle officiel des états, canal de transmission |
| SMS réel | choix du fournisseur et paramètres d'API |
| WhatsApp | compte Business et gabarits approuvés |
