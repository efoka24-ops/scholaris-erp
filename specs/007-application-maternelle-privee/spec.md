# Spécification : application Maternelle privée (`nursery`)

Date : 2026-10-20. Statut : **brouillon sans cahier des charges client**. Aucun code. Entrées : [../006-audit-primaire-maternelle/audit-prive.md](../006-audit-primaire-maternelle/audit-prive.md), [audit-maternelle-publique.md](../006-audit-primaire-maternelle/audit-maternelle-publique.md), [questions.md](../006-audit-primaire-maternelle/questions.md) (Q-A1 à Q-A10), [../003-application-primaire/](../003-application-primaire/) (modèle d'une application privée), [../004-applications-primaire-maternelle-publiques/maternelle/](../004-applications-primaire-maternelle-publiques/maternelle/) (règles propres à la maternelle), socle `specs/001-socle-plateforme/`.

Légende : **[F]** fait du socle ou choix d'architecture déjà pris ; **[H]** hypothèse paramétrable, « à confirmer » ([questions.md](questions.md)) ; **[R]** règle reprise de la maternelle publique (004), à confirmer pour le privé. **Aucun seuil, barème, durée ou texte réglementaire n'est posé ici** : chaque valeur citée est un paramètre à valeur par défaut, et une question.

## 1. Périmètre
- **Inclus (V1)** : organisation de l'école (année, trimestres, sections), dossier de l'enfant et inscription, personnes autorisées et **sortie sécurisée** (remise, refus, restrictions de garde, codes du jour, enfant non récupéré), arrivées et registre du jour, **suivi du développement sans note** (observation à trois niveaux, carnet de suivi trimestriel), **cahier de vie** avec consentement photo, besoins particuliers, allergies alimentaires, **passage vers le primaire** (décision et fiche de liaison), frais et paiements des familles (pension, inscription, garderie, cantine, transport), communication avec les familles, documents administratifs, tableau de bord du directeur.
- **Optionnel (Could)** : appel vocal réel pour l'enfant non récupéré, export tutelle, abonnement cantine suivi au repas.
- **Exclus** : tout calcul de moyenne, de rang ou de note (RG-MAT-1) ; dossier médical ; paie (socle Staff) ; comptabilité générale ; territoire public, inspection et statistiques MINEDUB (restent à `public_nursery`) ; primaire (application `primary`).
- **Cible** : établissement de type `nursery` d'un client privé (laïc ou confessionnel), seul ou dans un client qui possède aussi un établissement `primary` (« groupe scolaire privé », même promoteur) [F].
- **Distinction public/privé** : par l'application ouverte au client, `nursery` contre `public_nursery`, sans nouvel attribut d'établissement (Q-A4, défaut). Un client ne peut pas ouvrir les deux sur le même établissement.

## 2. Acteurs et rôles
Rôles nouveaux préfixés `nursery_` (migration de données `database/migrations/<date>_roles_nursery.sql`, jamais dans une migration existante) ; rôles du socle réutilisés sinon [H, MQ-12].
| Code | Rôle | Rôle socle / nouveau | Périmètre (`ScopeType`) | Permissions principales (`nursery.*`) |
|---|---|---|---|---|
| N01 | Promoteur / fondateur | `nursery_promoteur` | establishment | `nursery.dashboard.read`, `reporting.read`, lectures agrégées |
| N02 | Directrice / directeur | `directeur` (+ `nursery_directeur`) | establishment | `.pickup.read`, `.pickup.restrict`, `.register.read`, `.dev.validate`, `.transition.decide`, `.need.read`, `.settings.manage` |
| N03 | Éducatrice / enseignante de section | `nursery_educator` | `class` (sa section) | `.pickup.handover`, `.dev.observe`, `.lifebook.write`, `.need.report`, `.transition.opinion`, `attendance.take` |
| N04 | Aide maternelle / assistante | `nursery_assistant` | `class` (sa section) | `.pickup.handover`, `.arrival.record`, `attendance.take` |
| N05 | Secrétaire | `nursery_secretaire` | establishment | `people.manage`, `nursery.enrol`, `documents.issue`, `.pickup.manage` (guichet) |
| N06 | Économe / comptable | `comptable` | establishment | `billing.manage`, `.services.manage` |
| N07 | Caissier | `caissier` | establishment | `billing.cash` |
| N08 | Parent / tuteur | `parent` (socle) | ses enfants | `.pickup.self_manage`, `.lifebook.view`, `.allergy.declare`, consentements, paiement, messagerie |
| N09 | Personne autorisée | aucun compte | enfants désignés | identifiée par nom, téléphone, photo et code (comme R14 de 004) |
| N10 | Responsable de cantine | `nursery_cantine` (optionnel) | establishment | lecture des allergies de sa liste seulement |
| N11 | Administrateur fonctionnel du client | `super_admin` | tenant | `identity.manage`, `settings.manage`, `documents.template.manage` |
Différences avec le public : pas de rôle inspecteur ni de portée territoriale ; le directeur décide seul du passage (pas d'avis d'inspecteur par défaut, MQ-09) ; un promoteur lit les agrégats.

## 3. Parcours principaux
1. **Ouvrir l'application** : le client ouvre `nursery` (libre-service ou administrateur de plateforme), essai de 90 jours [H Q-A9] ; modules requis activés [F]. Statut `coming_soon` tant que la recette n'est pas faite.
2. **Préparer l'année** : le directeur crée l'année et ses trimestres, les sections (petite, moyenne, grande ou libellés propres à l'école), affecte éducatrices et aides (portée `class`), choisit le référentiel d'observation et règle les paramètres de sortie (horaires, délais d'alerte).
3. **Inscrire** : la secrétaire crée ou retrouve l'enfant (doublons du socle), saisit parents, allergies, consentements (photo, SMS), personnes autorisées initiales, section ; facture d'inscription et première tranche ; paiement au guichet ou mobile money.
4. **Accueillir et faire sortir** : l'aide fait l'appel du matin hors ligne, enregistre les arrivées ; en fin de journée l'éducatrice ou l'aide remet l'enfant **uniquement** à une personne autorisée valable ce jour (liste du jour avec photo) ; sinon refus et alerte.
5. **Suivre le développement** : l'éducatrice observe sans noter (enfant, domaine, item, niveau), publie des activités dans le cahier de vie, signale un besoin ; en fin de trimestre le carnet est rédigé, validé par la directrice puis publié aux parents.
6. **Communiquer et encaisser** : messages et annonces aux familles, relances d'échéance, reçus.
7. **Passer au primaire** : en fin d'année, décision de passage de chaque enfant de la dernière section, fiche de liaison signée, transfert vers l'école primaire du même client ou d'un autre client de la plateforme.
8. **Suivre** : le directeur voit effectifs, présents, enfants encore à l'école, alertes, recouvrement, avancement des observations.

## 4. User stories priorisées
Les critères d'acceptation reprennent ceux de 004 §3 quand la règle est identique ; ils restent **à confirmer** pour le privé.

### US1 (P1) · Remettre l'enfant seulement à une personne autorisée — EX-MAT-10, RG-MAT-2
**Acceptation** : 1) une remise cite l'identifiant d'une autorisation valable ce jour ou un code du jour validé ; **aucun champ « nom libre » ne remet l'enfant** ; 2) « personne non listée » → événement `refused`, enfant gardé, alerte immédiate aux parents et à la directrice, même si le parent a refusé les SMS non essentiels ; 3) autorisation révoquée, expirée ou d'un autre jour → 422 `authorization_not_valid` ; 4) toutes les voies d'écriture (API, lot hors ligne, saisie directeur) sont testées (SC-MAT-1).

### US2 (P1) · Restriction de garde — EX-MAT-11, RG-MAT-3
La directrice enregistre une décision de justice limitant la garde. **Acceptation** : 1) une personne visée ne reçoit pas l'enfant même si elle est autorisée ; 2) visible du personnel de la section et de la direction seulement, 404 pour le parent ; 3) la personne visée ne reçoit ni alerte ni carte ; 4) chaque consultation est journalisée.

### US3 (P1) · Enfant non récupéré — EX-MAT-12, RG-MAT-4
**Acceptation** : 1) enfant présent et non sorti à l'heure de fin + `nursery.pickup.late_after_minutes` → alerte aux parents ; 2) après `nursery.pickup.director_after_minutes` → alerte à la directrice ; 3) aucune alerte un jour de fermeture ni pour un enfant absent à l'appel ; 4) une alerte par niveau, par enfant et par jour (idempotent).

### US4 (P1) · Fonctionner hors ligne avec la liste du matin — EX-MAT-13, RG-MAT-5
Paquet de la section (présents, autorisés du jour avec vignette, restrictions, empreintes des codes, numéro de la directrice). **Acceptation** : remise et refus hors ligne rejoués sans doublon (`client_uuid`) ; autorisation révoquée après le chargement → événement accepté marqué `stale_authorization` + alerte ; paquet plus ancien que `nursery.pickup.offline_max_age_days` → mode « doute » (aucune remise sans appel à la directrice).

### US5 (P1) · Le parent tient la liste des autorisés — EX-MAT-09
Depuis le portail parent. **Acceptation** : un parent ne voit que ses enfants ; ajout actif tout de suite ou après validation de l'école (`nursery.pickup.parent_entries_need_validation`, MQ-05) ; un retrait révoque la carte ; carte avec numéro et QR dont la page publique n'affiche que type, école, date et statut.

### US6 (P1, Should) · Autorisation ponctuelle avec code — EX-MAT-14
Code à usage unique, valable le jour indiqué, haché, blocage après `nursery.pickup.code_max_attempts` essais erronés avec alerte.

### US7 (P1) · Registre des arrivées et sorties — EX-MAT-15
Registre du jour (arrivées, sorties, refus, alertes, « encore à l'école »), imprimable et exporté chaque soir.

### US8 (P1) · Ouvrir l'application et la payer — EX-MAT-01, EX-MAT-31
Essai, abonnement, suspension (423 en écriture) et fermeture (403) comme l'IPES. **Acceptation** : application suspendue → lecture et export seuls ; fermée → ni lecture ni écriture ; **toute route non GET est gardée** (test d'architecture).

### US9 (P1) · Inscrire et facturer — EX-MAT-05, EX-MAT-28
**Acceptation** : enfant inscrit avec facture d'inscription et première tranche ; remise fratrie ; paiement guichet et mobile ; reçu SMS.

### US10 (P2) · Observer le développement sans noter — EX-MAT-16, RG-MAT-1
**Acceptation** : niveau hors des trois codes paramétrés → 422 ; l'application n'expose **aucune moyenne, aucun total, aucun rang ni « non acquis »** ; l'éducatrice voit les enfants sans observation dans un domaine ce mois-ci (fréquence `nursery.dev.min_observations_per_domain_per_month`).

### US11 (P2) · Carnet de suivi trimestriel — EX-MAT-17
Préparé, rédigé en termes positifs, validé par la directrice, publié aux parents puis figé. **Acceptation** : non validé = invisible du parent ; terme de la liste `nursery.dev.discouraged_terms` → validation bloquée ; réédition « Duplicata » ou « Rectificatif » avec le même numéro ; PDF léger (cible à confirmer, MQ-14).

### US12 (P2, Should) · Cahier de vie avec consentement — EX-MAT-18, RG-MAT-6
**Acceptation** : une photo où figure un enfant sans consentement photo en vigueur n'est jamais servie aux parents ; le retrait d'un consentement la retire à la lecture suivante ; une photo non étiquetée ne se publie pas ; purge à la date de conservation (`nursery.lifebook.retention`, MQ-07).

### US13 (P2, Should) · Signaler un besoin particulier — EX-MAT-19
Visible de l'éducatrice, de la direction et des parents seulement ; statut ouvert, orienté, clos ; aucun champ médical libre au-delà d'une description courte.

### US14 (P2) · Garderie, cantine, transport — EX-MAT-29
Forfaits facturés par cycle ; allergies visibles de la section et du responsable de cantine.

### US15 (P3, Must) · Décider du passage — EX-MAT-21
Proposition de passage en section supérieure ; la grande section propose l'entrée au primaire ; avis de l'éducatrice ; maintien exceptionnel selon la règle choisie (MQ-09). Aucune règle officielle n'est codée.

### US16 (P3, Should) · Fiche de liaison et transfert — EX-MAT-22
Fiche (identité, parcours, points d'appui, besoins) signée par la directrice, émise comme document, lue par l'école primaire destinataire comme document de l'enfant. **Acceptation** : seuls des niveaux « acquis » ou « en cours » comme points d'appui ; aucune restriction de garde ni allergie copiée.

### US17 (P3, Should) · Allergies alimentaires — EX-MAT-20
Déclarées par le parent, visibles de la section et du responsable de cantine seulement.

## 5. Exigences (EX-MAT-nn)
Couverture : **C** socle tel quel, **R** reprise d'une brique de `PublicNursery` (par contrat partagé ou recopie, voir [plan.md](plan.md) §3), **P** extension du socle, **N** à créer pour `nursery`. Priorité MoSCoW.
| ID | Exigence | Prio | Couv. | Réalisation |
|---|---|---|---|---|
| EX-MAT-01 | L'application se déclare au catalogue, s'ouvre par client, respecte suspension et fermeture | Must | P | manifeste `platform.applications.nursery`, `WriteGate` |
| EX-MAT-02 | Établissement `nursery`, vocabulaire (enfant, section, trimestre, carnet de suivi), image de marque | Must | C | `EstablishmentType::Nursery`, `DefaultApplications`, branding |
| EX-MAT-03 | Années, trimestres, calendrier (congés, fermetures) | Must | C | `/academic-years`, `/periods`, `/calendar-events` |
| EX-MAT-04 | Sections créées comme classes, effectif maximal, affectation de l'éducatrice et de l'aide | Must | C | `/structures`, `/staff` (portée `class`) |
| EX-MAT-05 | Dossier de l'enfant, doublons, parents, contacts, consentements, santé chiffrée | Must | C | `/people`, `/consents`, `/people/{id}/health` |
| EX-MAT-06 | Inscription et réinscription en section, âge minimal en avertissement seulement | Must | C | `/enrollments`, `EnrolmentGate` si autorisation d'ouverture (MQ-13) |
| EX-MAT-07 | Rôles `nursery_*` et portée section | Must | P | migration de rôles, `RoleMatrix`, `ScopeResolver` |
| EX-MAT-08 | Paramètres de l'école | Must | C | `/settings`, clés `nursery.*` |
| EX-MAT-09 | Liste des autorisés par enfant tenue par parent, secrétaire et directrice (nom, lien, téléphone, photo ; permanente ou du jour) | Must | R | contrat partagé « autorisés » + `MediaStore` |
| EX-MAT-10 | Remise seulement par identifiant d'autorisation valable ou code validé ; refus sinon | Must | R | service de sortie partagé (voir plan §3) |
| EX-MAT-11 | Restrictions de garde, visibles section + directeur, bloquantes, consultation journalisée | Must | R | idem |
| EX-MAT-12 | Veille de non-récupération (parents, puis directeur), jour de fermeture exclu | Must | R | commande `nursery:pickup-watch` |
| EX-MAT-13 | Paquet du matin et lot hors ligne idempotent | Must | R | `sync.handlers.nursery.pickup` sur le point d'entrée commun si présent |
| EX-MAT-14 | Code à usage unique du jour, HMAC, essais limités | Should | R | idem |
| EX-MAT-15 | Registre quotidien et document imprimable | Must | R | `nursery:daily-register` |
| EX-MAT-16 | Observation à trois niveaux paramétrés, sans note ni rang, hors ligne | Must | R | service d'observation partagé |
| EX-MAT-17 | Carnet de suivi trimestriel validé puis publié | Must | R | idem + `Documents` |
| EX-MAT-18 | Cahier de vie filtré par consentement à chaque lecture, photos étiquetées | Should | R | idem + `ConsentChecker`, `MediaStore` |
| EX-MAT-19 | Besoin particulier signalé, sans diagnostic | Should | R | idem |
| EX-MAT-20 | Allergies alimentaires déclarées par le parent, vues de la section et de la cantine | Should | R | `FoodAllergies` du socle |
| EX-MAT-21 | Décision de passage de fin d'année | Must | N | `nu_transition_decisions`, `ConditionalPromotionPlanner` (PM7) |
| EX-MAT-22 | Fiche de liaison signée, transférée vers le primaire | Should | N | `Documents`, transfert d'inscription du socle |
| EX-MAT-23 | Appel quotidien (arrivée) par l'aide, hors ligne | Must | C | `attendance.mode=day_part` (PS3) |
| EX-MAT-24 | Absence signalée au parent immédiatement | Must | C | `AbsenteeDirectory`, `attendance:retry-absences` |
| EX-MAT-25 | Messages, annonces, messagerie modérée, SMS d'urgence en catégorie `essential` | Must | C | `/messages`, `/announcements`, `SafetyMessenger` |
| EX-MAT-26 | Portail parent : autorisés, carnet, cahier de vie, allergies, solde, paiement, messages | Must | P | `portals.sections.nursery` |
| EX-MAT-27 | Documents administratifs : certificat de scolarité, attestation, carte de l'enfant, carte des autorisés (QR) | Must | C/P | `/documents`, `DocumentIssuer`, `/public/verify/{token}` |
| EX-MAT-28 | Frais par section (inscription, pension par tranches, autres), échéancier, remise fratrie | Must | C | `/fee-items`, `/fee-schedules`, `SiblingDiscountResolver`, `billing.fee_kinds.nursery` |
| EX-MAT-29 | Garderie, cantine, transport facturés au forfait | Should | N | `nu_service_plans`, `nu_service_subscriptions`, `InvoiceIssuer::forCharges` |
| EX-MAT-30 | Encaissement guichet et mobile money, reçus, arrêté de caisse, relances | Must | C | `/cash/receipts`, `/payments`, `reminders:send` |
| EX-MAT-31 | Toute écriture gardée : suspendue 423, fermée 403 | Must | P | `WriteGate`, `GatedAction`, test d'architecture |
| EX-MAT-32 | Tableau de bord du directeur et indicateurs | Must | P | `reporting.dashboards.nursery`, `reporting.indicators.nursery` |
| EX-MAT-33 | Journal des consultations nominatives sensibles (restrictions, besoins, allergies) | Must | C | `Audit/Contracts/AuditLog` |
| EX-MAT-34 | Export client complet et droit d'accès aux tables `nu_*` | Must | C | `privacy.tenant_data_source.nursery`, `privacy.subject_data_source.nursery` |
| EX-MAT-35 | Reprise de données (enfants, parents, soldes d'ouverture) | Should | P | adaptation de l'import existant |
| EX-MAT-36 | Fermeture d'un jour (NOT-14) : veille de sortie suspendue | Must | R | calendrier du socle |
| EX-MAT-37 | Clôture d'année : passage, réinscriptions en brouillon, report des soldes | Must | P | `YearPromotion`, `organisation.closure.checks.nursery` |
| EX-MAT-38 | Export tutelle (privé) | Could | N | `integrations.export_types.nursery` (MQ-15) |
| EX-MAT-39 | Appel vocal réel pour l'enfant non récupéré | Could | P | `VoiceGateway` (désactivé par défaut) |
| EX-MAT-40 | Vocabulaire et écrans propres au client privé (aucun lien vers des écrans `public_nursery.*`) | Must | N | filtre par application ouverte |

**Synthèse** : 40 exigences ; **C** 13, **R** 13, **P** 9 (EX-MAT-27, C/P, comptée en P), **N** 5. Must 31, Should 7, Could 2. Les 13 reprises (R) sont l'enjeu de mutualisation de [plan.md](plan.md) §3.

## 6. Règles de gestion (RG-MAT-n)
### Faits (architecture) [F]
- RG-MAT-1 Aucune colonne ni route de note, moyenne, total ou rang dans les tables de suivi du développement ; test d'architecture.
- RG-MAT-2 Une remise n'existe qu'avec l'identifiant d'une autorisation valable ce jour ou d'un code du jour validé ; il n'existe aucun champ « nom libre » qui remette l'enfant.
- RG-MAT-3 Les restrictions de garde et les allergies ne figurent dans aucune liste générale, jamais au parent visé ni à la supervision ; lecture journalisée.
- RG-MAT-4 Toute table `nu_*` porte `tenant_id` et `establishment_id` ; un identifiant d'un autre client ou établissement répond 404.
- RG-MAT-5 Toute écriture passe par `WriteGate` (423 suspendu, 403 fermé) ; hors ligne : `client_uuid`, conflit 409 jamais écrasé.
- RG-MAT-6 Une photo n'est servie à un parent que si chaque enfant visible a un consentement photo en vigueur, vérifié à chaque lecture ; URL signées de courte durée.
- RG-MAT-7 Une éducatrice ou une aide ne voit et n'écrit que sa section (portée `class`) : hors périmètre, 404 en lecture, 403 journalisé en écriture.
- RG-MAT-8 Un document émis (carnet, fiche de liaison, carte) est figé, numéroté, vérifiable ; toute correction produit une nouvelle version.
- RG-MAT-9 Le canal de sécurité (refus de remise, enfant non récupéré) ne peut pas être désactivé par le parent (catégorie `essential`).
- RG-MAT-10 Une application `nursery` n'utilise que les `Contracts/` du socle et ne dépend d'aucune autre application.
### Hypothèses paramétrables [H] (toutes « à confirmer »)
- RG-MAT-11 Délais de la veille de sortie, heure de fin des cours, âge maximal du paquet hors ligne, nombre d'essais de code : paramètres `nursery.pickup.*` ; **aucune valeur par défaut codée** au-delà de celles de 004 reprises comme propositions (MQ-02, MQ-03).
- RG-MAT-12 Niveaux d'observation : trois codes paramétrés `nursery.dev.levels` (acquis, en cours, pas encore en proposition) ; libellés FR/EN paramétrables (MQ-08).
- RG-MAT-13 Fréquence d'observation par domaine et par mois : `nursery.dev.min_observations_per_domain_per_month` (MQ-08).
- RG-MAT-14 Conservation des photos et du cahier de vie : `nursery.lifebook.retention` (MQ-07).
- RG-MAT-15 Passage : proposition automatique en section supérieure ; maintien selon une règle paramétrée (`nursery.transition.*`, MQ-09).
- RG-MAT-16 Le carnet n'est jamais bloqué pour impayé par défaut (`nursery.progress_book.block_on_debt=false`) (MQ-10).
- RG-MAT-17 Les entrées du parent sont actives sans validation (`nursery.pickup.parent_entries_need_validation=false`) (MQ-05).

## 7. Documents produits
| Code | Document | Source | Statut |
|---|---|---|---|
| DOC-MAT-01 | Carnet de suivi trimestriel | Carnet validé | R/N (modèle à valider, MQ-14) |
| DOC-MAT-02 | Fiche de liaison vers le primaire | Décision de passage | N |
| DOC-MAT-03 | Carte des personnes autorisées (QR) | Autorisation | R |
| DOC-MAT-04 | Registre quotidien des arrivées et sorties | Journal de sortie | R |
| DOC-MAT-05 | Certificat de scolarité, attestation de fréquentation | Inscription | C (modèle) |
| DOC-MAT-06 | Carte de l'enfant avec photo et QR | Inscription validée | C (modèle) |
| DOC-MAT-07 | Reçu de paiement, relevé de compte famille | Billing | C |
| DOC-MAT-08 | Fiche de renseignements de l'enfant | Dossier | C (modèle) |
Tous passent par le catalogue `Documents` (numéro, QR, vérification, réédition, Q-A7 défaut « oui »).

## 8. Notifications (via Messaging, modèles en données)
| Code | Événement | Destinataires | Canal |
|---|---|---|---|
| NOT-MAT-01 | Absence constatée à l'appel | Parents | SMS (socle) |
| NOT-MAT-02 | Enfant non récupéré (niveau parent) | Parents | SMS urgent, appel si disponible |
| NOT-MAT-03 | Enfant non récupéré (niveau directeur) | Directrice | SMS urgent |
| NOT-MAT-04 | Sortie refusée, personne non autorisée | Parents, directrice | SMS essentiel |
| NOT-MAT-05 | Code d'autorisation ponctuelle | Parent | SMS |
| NOT-MAT-06 | Carnet de suivi disponible | Parents | SMS + portail |
| NOT-MAT-07 | Nouvelle entrée du cahier de vie | Parents de la section | Portail (+ SMS optionnel) |
| NOT-MAT-08 | Échéance de frais proche ou dépassée | Parents | SMS (`reminders:send`) |
| NOT-MAT-09 | Reçu de paiement | Payeur | SMS |
| NOT-MAT-10 | Besoin particulier signalé | Parents, directrice | Messagerie |
| NOT-MAT-11 | Fermeture d'un jour | Parents | SMS |

## 9. Indicateurs (tableau de bord du directeur)
Effectifs par section et sexe ; présents du jour ; enfants encore à l'école après l'heure de fin ; sorties refusées de la semaine ; alertes en cours ; avancement des observations par section et domaine (sans comparaison d'enfants) ; carnets en attente de validation ; recouvrement (dû, payé, par section) ; impayés par famille ; inscriptions de l'année contre N-1 ; consentements photo manquants ; besoins ouverts.

## 10. Critères de succès
- SC-MAT-1 **Zéro** événement de remise sans autorisation valable ou code validé : requête de contrôle quotidienne et test qui tente toutes les voies d'écriture.
- SC-MAT-2 100 % des refus produisent l'alerte à la directrice en moins de 1 minute en ligne, ou à la synchronisation hors ligne.
- SC-MAT-3 100 % des enfants présents ont, à la clôture du jour, une sortie, un maintien tracé ou une alerte.
- SC-MAT-4 Aucune photo d'enfant sans consentement servie par l'API (test sur toutes les routes de lecture).
- SC-MAT-5 Zéro seuil, délai ou barème codé en dur : tout vient d'un paramètre (test d'architecture).
- SC-MAT-6 Une école de 4 sections et 100 enfants est paramétrée (année, sections, référentiel, frais, autorisés initiaux) en moins d'une demi-journée par la directrice (cible à confirmer au pilote).
- SC-MAT-7 Contrôle de sortie d'un enfant en moins de 15 secondes sur un téléphone d'entrée de gamme (cible à mesurer au pilote).
