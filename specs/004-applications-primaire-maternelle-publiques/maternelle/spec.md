# Spécification : application Maternelle publique (`PublicNursery`)

Feature : 004, partie Maternelle. Date : 2026-10-15. Statut : brouillon pour validation.
Source qui fait foi : [../cdc-public.md](../cdc-public.md). Couverture du socle : [couverture-socle.md](couverture-socle.md). Questions : [questions.md](questions.md).
Partie commune (ECO, ELV, CLA, PRE, PER, COM, APE, STA, INS, CAN, territoires) : `../primaire/` ; référencée par code, jamais respécifiée ici.

## 1. Périmètre
- **Inclus** : DEV (suivi du développement, cahier de vie), SOR (arrivées et sorties), TRA (passage vers le primaire) ; exigences marquées [M] des modules communs (EX-COM-04 personnes autorisées, EX-CAN-03 allergies) ; rôles R11, R12, R14 ; règles d'évaluation de la maternelle ; DOC-18 à DOC-21 ; NOT-06, NOT-07, et le comportement maternelle de NOT-14 ; consentement photo et conservation (RG-DEV-2, RG-DEV-3) ; restriction de garde (RG-SOR-2) ; groupe scolaire vu depuis la maternelle.
- **Exclus** : tout ce que porte le commun (inscription, appel, classes, personnel, APEE, statistiques, inspection, cantine hors allergies, territoires, synchronisation générique API-04, USSD) ; dossier médical (exclu par le CDC) ; la maternelle privée (`src/Modules/Apps/Nursery`, vide).
- **Cible** : établissement de type `nursery` d'un client public (MINEDUB ou programme pilote), seul ou lié à un établissement `primary` dans un groupe scolaire.
- **Règles d'évaluation** : valeurs par défaut du CDC, paramétrables **au niveau national seulement**, à valider par l'Inspection générale avant mise en service.

## 2. Acteurs
| CDC | Rôle proposé | Portée | Permissions principales |
|---|---|---|---|
| R09 Directeur d'école | rôle « directeur public » du commun | établissement (les deux établissements d'un groupe scolaire) | `public_nursery.pickup.read`, `.pickup.restrict`, `.register.read`, `.dev.validate`, `.transition.decide`, `.need.read` |
| R11 Éducatrice ou enseignant de maternelle | `pn_educator` | `class` (sa section) | `.pickup.handover`, `.dev.observe`, `.lifebook.write`, `.need.report`, `.transition.opinion` |
| R12 Aide maternelle | `pn_assistant` | `class` (sa section) | `.pickup.handover`, `.arrival.record` ; appel via PRE |
| R13 Parent ou tuteur | `parent` (socle) | ses enfants | `.pickup.self_manage`, `.lifebook.view`, `.allergy.declare`, consentements |
| R14 Personne autorisée | aucun compte | enfants désignés | identifiée par nom, téléphone, photo et code |
| R07 Inspecteur | rôle du commun | territoire | `.transition.inspector_opinion` (maintien exceptionnel), agrégats seulement sinon |
| R21 Administrateur fonctionnel | rôle du commun | national | `.settings.manage` (référentiel, paramètres) |

## 3. User stories priorisées

### US1 (P1, lot P1) · Remettre l'enfant seulement à une personne autorisée — EX-SOR-02, RG-SOR-1, NOT-07, TST-06, O7
L'éducatrice ou l'aide choisit l'enfant puis une personne dans la liste des autorisés du jour (photo affichée) et confirme la remise. Si la personne présente n'est pas dans la liste, elle choisit « personne non listée » : la sortie est refusée, l'enfant reste, les parents et le directeur sont alertés.
**Acceptation**
1. Étant donné une autorisation permanente active pour « Awa N. », quand l'aide confirme la remise à Awa, alors un événement `handed_over` est enregistré avec l'heure, l'agent et l'identifiant de l'autorisation.
2. Étant donné qu'aucune autorisation ne correspond, quand l'aide signale « personne non listée » (nom déclaré facultatif), alors un événement `refused` est enregistré, **aucune remise n'est possible pour cette personne**, NOT-07 part aux parents (SMS, appel si disponible) et au directeur, même si le parent a refusé les SMS non essentiels (RG-COM-2).
3. Une autorisation révoquée, expirée ou datée d'un autre jour n'apparaît pas dans la liste et l'API refuse toute remise qui la cite (422 `authorization_not_valid`).
4. Une remise ne peut être enregistrée **que** par un identifiant d'autorisation valide ce jour ou un code du jour validé ; il n'existe aucun champ « nom libre » qui remette l'enfant.

### US2 (P1) · Restriction de garde — RG-SOR-2
Le directeur enregistre une décision de justice limitant un droit de garde (personne visée, nature, référence de la décision, pièce numérisée, période). Elle est visible du seul personnel de la section et du directeur.
**Acceptation** : 1) une personne visée par une restriction active ne peut pas recevoir l'enfant, même si elle figure dans la liste des autorisés (refus + alerte au directeur) ; 2) un parent ou un rôle de supervision qui consulte l'enfant ne voit pas la restriction (404 sur la ressource, absente des listes) ; 3) la personne visée ne reçoit jamais NOT-07 ni la carte DOC-21 (Q-05) ; 4) chaque consultation est journalisée.

### US3 (P1) · Enfant non récupéré — EX-SOR-04, NOT-06
À l'heure de fin des cours + 30 minutes (paramètre), tout enfant arrivé et ni remis ni gardé en connaissance de cause déclenche l'appel des parents (vocal si disponible, sinon SMS urgent) ; s'il n'est toujours pas récupéré après un second délai (paramètre, défaut 15 min, à confirmer), le directeur est alerté.
**Acceptation** : 1) enfant présent, aucune sortie à H+30 → NOT-06 aux parents, événement `late_alert_parent` ; 2) à H+30+15 → alerte directeur, événement `late_alert_director` ; 3) aucune alerte un jour de fermeture (NOT-14) ni pour un enfant absent à l'appel ; 4) une seule alerte par niveau et par enfant et par jour (relances idempotentes).

### US4 (P1) · Fonctionner hors ligne avec la liste du matin — RG-SOR-3
Le téléphone de la section télécharge le matin le paquet de la section (enfants présents, autorisés valables ce jour avec vignette photo, restrictions, empreintes des codes du jour, numéros d'urgence du directeur). Les remises et refus sont enregistrés hors ligne avec un `client_uuid` et l'heure de l'appareil, puis synchronisés.
**Acceptation** : 1) sans réseau, la remise à une personne de la liste est possible et affichée « en attente d'envoi » ; 2) un refus hors ligne propose d'appeler le directeur (lien `tel:`) et d'envoyer un SMS natif prérempli ; NOT-07 serveur part à la synchronisation ; 3) un lot rejoué deux fois ne crée aucun doublon ; 4) si l'autorisation a été révoquée après le chargement du matin, l'événement est accepté mais marqué `stale_authorization` et le directeur est alerté ; 5) un paquet plus ancien que le paramètre `pickup.offline_max_age_days` bascule l'écran en mode « doute » (aucune remise sans appel au directeur).

### US5 (P1) · Le parent tient la liste des personnes autorisées — EX-SOR-01, EX-COM-04, DOC-21
Depuis le portail parent sur téléphone, le parent ajoute, consulte et retire les personnes autorisées (nom, lien, téléphone, photo). Le directeur peut le faire au guichet pour un parent sans smartphone.
**Acceptation** : 1) un parent ne voit et ne modifie que les autorisations de ses enfants (403/404 sinon) ; 2) un ajout par le parent est actif immédiatement (Q-03 : validation par l'école en option) ; 3) un retrait révoque aussi la carte DOC-21 correspondante ; 4) DOC-21 porte un numéro unique et un QR dont la page de vérification n'affiche que type, école, date et statut (RG-DOC-2).

### US6 (P1, Should) · Autorisation ponctuelle avec code — EX-SOR-03
Le parent crée depuis le portail (ou par SMS selon Q-04) une autorisation du jour pour une personne nommée ; l'application génère un code à usage unique envoyé par SMS au parent, qui le transmet. À la sortie, l'agent saisit le code.
**Acceptation** : 1) code valable le jour indiqué seulement, une seule remise ; 2) 5 essais erronés pour un enfant bloquent la saisie de code pour la journée et alertent le directeur ; 3) le code n'est jamais stocké en clair.

### US7 (P1) · Registre des arrivées et sorties — EX-SOR-05, DOC-19
Le directeur consulte le registre du jour (arrivées, accompagnant facultatif, sorties, refus, alertes) et l'imprime ou l'exporte (DOC-19, produit automatiquement chaque jour).
**Acceptation** : le registre du jour liste chaque enfant présent avec une sortie, un refus suivi d'une sortie, ou « encore à l'école » ; DOC-19 n'a ni signataire ni QR.

### US8 (P2) · Observer le développement sans noter — EX-DEV-01, EX-DEV-02, RG-DEV-1
L'éducatrice saisit en un geste : enfant, domaine, item, niveau (acquis, en cours, pas encore), commentaire facultatif, sur téléphone et hors ligne.
**Acceptation** : 1) toute valeur de niveau hors des trois codes est refusée (422) ; 2) l'application n'expose ni moyenne, ni total, ni rang, ni « non acquis » ; 3) l'éducatrice voit les enfants sans observation dans un domaine ce mois-ci (fréquence par défaut : une par domaine et par mois).

### US9 (P2) · Carnet de suivi trimestriel — EX-DEV-03, DOC-18
En fin de trimestre, l'application prépare pour chaque enfant le carnet (dernier niveau par item observé, commentaires) ; l'éducatrice rédige la synthèse en termes positifs (banque de formulations) ; le directeur valide ; le carnet est publié aux parents (portail, PDF < 200 Ko) puis figé.
**Acceptation** : 1) un carnet non validé n'est pas visible du parent ; 2) une formulation de la liste des termes à éviter (paramètre) bloque la validation avec un message ; 3) une réédition porte « Duplicata » ou « Rectificatif » et garde le numéro (RG-DOC-5).

### US10 (P2, Should) · Cahier de vie avec consentement — EX-DEV-04, RG-DEV-2, RG-DEV-3
L'éducatrice publie une activité de la section (texte, photos). Pour chaque photo, elle indique les enfants visibles ou « aucun enfant reconnaissable ».
**Acceptation** : 1) **une photo où figure un enfant sans consentement photo écrit en vigueur n'est jamais servie aux parents** : elle reste `withheld` jusqu'au dépôt d'une version masquée ; 2) le retrait d'un consentement retire immédiatement les photos concernées (filtre à chaque lecture) ; 3) une photo non étiquetée ne peut pas être publiée ; 4) la purge supprime fichiers et lignes à la date de fin de conservation (paramètre, Q-07).

### US11 (P2, Should) · Signaler un besoin particulier — EX-DEV-05
L'éducatrice signale un besoin (langage, vue, audition, autre) au directeur et aux parents, pour orientation, sans diagnostic médical.
**Acceptation** : le signalement est visible de l'éducatrice, du directeur et des parents de l'enfant seulement ; il porte un statut (ouvert, orienté, clos) ; aucun champ médical libre au-delà d'une description courte.

### US12 (P3, Must) · Décider du passage — EX-TRA-01
En fin d'année, l'application propose le passage automatique en section supérieure (ou en SIL/Class 1 depuis la grande section) ; l'éducatrice donne son avis ; un maintien exceptionnel exige une demande des parents et l'avis de l'inspecteur.
**Acceptation** : 1) un maintien sans demande parentale enregistrée ni avis de l'inspecteur est refusé (422) ; 2) les décisions alimentent le passage de fin d'année de l'établissement maternelle public seulement (PM7).

### US13 (P3, Should) · Fiche de liaison et transfert — EX-TRA-02, EX-TRA-03, DOC-20
Pour chaque enfant de grande section, l'éducatrice prépare la fiche de liaison (identité, parcours, points d'appui, besoins) ; le directeur la signe ; elle suit l'enfant vers l'école primaire du groupe scolaire ou une autre école de la plateforme.
**Acceptation** : 1) la fiche reprend seulement des niveaux « acquis » ou « en cours » comme points d'appui et des besoins formulés positivement ; 2) l'école primaire destinataire la lit comme document émis de l'enfant ; 3) aucune donnée de restriction de garde ni d'allergie n'est copiée dans la fiche (minimisation ; les allergies suivent par le dossier santé du socle).

### US14 (P3, Should) · Allergies alimentaires — EX-CAN-03
Le parent déclare les allergies alimentaires de son enfant ; elles s'affichent sur la liste de la section pour l'éducatrice, l'aide et le responsable de cantine seulement.
**Acceptation** : un rôle non concerné (enseignant d'une autre section, APEE, supervision) ne les voit pas ; aucune autre donnée de santé n'est exposée.

## 4. Exigences fonctionnelles (testables)
| ID interne | Réf. CDC | Exigence | Priorité CDC | Lot |
|---|---|---|---|---|
| FR-M01 | EX-SOR-01, EX-COM-04 | Liste d'autorisés par enfant tenue par parent et directeur ; champs nom, lien, téléphone, photo ; permanente ou du jour | Must | P1 |
| FR-M02 | EX-SOR-02, RG-SOR-1 | Remise par identifiant d'autorisation valide ce jour ou code validé ; refus sinon | Must | P1 |
| FR-M03 | EX-SOR-02, NOT-07 | Refus → alerte immédiate parents et directeur, canal de sécurité non désactivable | Must | P1 |
| FR-M04 | RG-SOR-2 | Restrictions de garde, visibles section + directeur ; bloquent la remise | Must (règle) | P1 |
| FR-M05 | EX-SOR-04, NOT-06 | Veille de non-récupération : parents à H+30 (paramètre), directeur ensuite | Must | P1 |
| FR-M06 | RG-SOR-3 | Paquet du matin et lot hors ligne idempotent (`client_uuid`) | Must (règle) | P1 |
| FR-M07 | EX-SOR-03 | Code à usage unique du jour, haché, 5 essais | Should | P1 |
| FR-M08 | EX-SOR-05, DOC-19 | Registre quotidien et DOC-19 automatique | Must | P1 |
| FR-M09 | DOC-21 | Carte des autorisés avec QR, révoquée avec l'autorisation | (catalogue) | P1 |
| FR-M10 | EX-DEV-01 | Référentiel national versionné domaines/items | Must | P2 |
| FR-M11 | EX-DEV-02, RG-DEV-1 | Observation à trois niveaux, sans note ni rang | Must | P2 |
| FR-M12 | EX-DEV-03, DOC-18 | Carnet trimestriel validé par le directeur puis publié | Must | P2 |
| FR-M13 | EX-DEV-04, RG-DEV-2 | Cahier de vie filtré par consentement à chaque lecture | Should | P2 |
| FR-M14 | RG-DEV-3 | Purge des photos à échéance | Must (règle) | P2 |
| FR-M15 | EX-DEV-05 | Signalement d'un besoin particulier | Should | P2 |
| FR-M16 | EX-TRA-01 | Décision de passage, maintien exceptionnel encadré | Must | P3 |
| FR-M17 | EX-TRA-02, DOC-20 | Fiche de liaison signée | Should | P3 |
| FR-M18 | EX-TRA-03 | Fiche jointe au transfert vers le primaire | Should | P3 |
| FR-M19 | EX-CAN-03 | Allergies alimentaires déclarées par le parent, vues de la section | Should | P3 |
| FR-M20 | NOT-14 | Jour de fermeture : veille SOR suspendue, aucune alerte | Must (commun) | P1 |
| FR-M21 | RG-TER-2, journalisation | Toute consultation nominative par la supervision journalisée | Must | P1 |

## 5. Critères de succès
- **SC-1 (O7)** : **zéro** événement `handed_over` sans autorisation valide ou code validé, vérifié par une requête de contrôle quotidienne (`public-nursery:pickup-audit`) et par un test qui tente toutes les voies d'écriture (API, lot hors ligne, saisie directeur).
- **SC-2** : 100 % des refus produisent NOT-07 au directeur en moins de 1 minute en ligne, ou à la synchronisation hors ligne.
- **SC-3** : 100 % des enfants présents ont, à la clôture du jour, une sortie, un maintien à l'école tracé, ou une alerte NOT-06.
- **SC-4** : contrôle de sortie d'un enfant en moins de 15 secondes sur un téléphone Android 8, 2 Go (cible à mesurer au pilote).
- **SC-5** : aucune photo d'enfant sans consentement servie par l'API (test sur toutes les routes de lecture du cahier de vie).
- **SC-6** : au moins une observation par domaine et par enfant chaque mois dans 80 % des sections du pilote (cible à confirmer, Q-11).
- **SC-7** : carnet DOC-18 PDF de moins de 200 Ko (RG-DOC-6).

## 6. Attentes envers le commun (à porter par `../primaire/`)
| # | Module | Attente | Utilisée par |
|---|---|---|---|
| A1 | ECO | Fiche d'école avec cycle « groupe scolaire » et **lien entre l'établissement maternelle et l'établissement primaire** (même directeur, infrastructures partagées) | US12, US13, documents |
| A2 | ECO | **Horaires de l'école** lisibles par contrat (fin des cours par jour et par flux, demi-journée) ; RG-TER-6 : l'école ne modifie que ses horaires | US3 |
| A3 | ELV | Inscription en maternelle, âge 4 ans par défaut (RG-ELV-3, avertissement seulement), identifiant national dès le préscolaire (EX-ELV-02), transfert électronique (EX-ELV-05) acceptant une pièce jointe (DOC-20) | US13 |
| A4 | CLA | Sections (petite, moyenne, grande ; Pre-Nursery, Nursery 1, Nursery 2) créées comme classes ; affectation de l'éducatrice et de l'aide avec portée `class` | rôles R11, R12 |
| A5 | PRE | Appel quotidien par demi-journée faisable par l'aide maternelle (R12), hors ligne ; présence du jour lisible par contrat (la veille SOR ne vise que les présents) | US3, US7 |
| A6 | COM | Canal de sécurité qui ignore le refus des SMS non essentiels (RG-COM-2) et canal vocal (EX-COM-02) : si le commun les porte, PM3 et PM4 sont fusionnés avec lui | US1, US3 |
| A7 | COM | NOT-14 émis avec la date de fermeture lisible par contrat (événement ou paramètre du jour) | FR-M20 |
| A8 | Transverse | Garde d'écriture « application suspendue → 423, fermée → 403 » et fournisseur de types de documents partagés par les applications publiques (`Shared/Public` ou équivalent), en-têtes nationaux (République, ministère, délégations, inspection, école) bilingues | toutes les écritures, DOC-18 à 21 |
| A9 | Transverse | Synchronisation hors ligne (API-04) : si un point d'entrée commun de lots signés existe, SOR et DEV y enregistrent un gestionnaire ; sinon ils gardent leurs routes `/public-nursery/sync/*` | US4, US8 |
| A10 | Rôles | Rôles R07, R09, R21 et leurs portées territoriales ; R09 affecté aux deux établissements d'un groupe scolaire | toutes |
| A11 | CAN | Responsable de cantine (R17) désigné comme « personnel concerné » pour les allergies | US14 |
| A12 | Plateforme | Manifeste distinct `public_nursery` (ou réutilisation de la clé `nursery`, Q-01) et moyen de distinguer un établissement public d'un privé | PM7, manifeste |

## 7. Hypothèses
- H1 Les rôles R11 et R12 sont propres à la maternelle et portés par cette application.
- H2 La liste des autorisés reste dans la table du socle `authorized_pickups` (une seule source) ; l'application y écrit par le contrat PM1.
- H3 Le référentiel d'observation est saisi par l'administrateur national à partir du document officiel du préscolaire ; l'application ne livre que l'exemple du CDC marqué « à valider ».
- H4 Un téléphone par section ; plusieurs agents s'y connectent chacun avec son code (CDC, « Comptes partagés interdits »).
