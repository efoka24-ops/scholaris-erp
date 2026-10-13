# Exigences d'API déduites des maquettes : personnes, frais, messages, documents

Suite de [design-api-requirements.md](design-api-requirements.md). Source : écrans Inscription, InscriptionPieces, Frais (caisse), Parent (mobile), DocRegistre, Verification, Bulletin, Releve. Le front n'est pas construit : ce sont des exigences de contrat d'API.

## Personnes et inscription (S03, écrans Inscription et InscriptionPieces)
- **Inscription en 5 étapes** : 1 Identité ; 2 Parents et garant ; 3 Filière ; 4 Pièces ; 5 Frais et paiement. Un **brouillon** est enregistré en continu (« Brouillon enregistré à 10:24 ») : l'inscription a un état `draft` avant validation et un taux de complétude (« Dossier 72 % complet »).
- **Champs d'identité** : nom, prénoms, date de naissance, lieu de naissance, sexe, nationalité, téléphone (identifiant du compte), e-mail (facultatif), langue des documents (fr/en), matricule national (facultatif, cartescolaire.cm), photo d'identité.
- **Détection de doublons** à la saisie de l'identité : « Une personne existe peut-être déjà dans le groupe ». Le candidat doublon est renvoyé avec l'indication « 3 critères sur 3 » (nom, date de naissance, téléphone) et son historique résumé (« Ancienne élève du Collège Les Palmiers (Tle D, 2025-2026) · même téléphone »). Deux actions : « C'est la même personne : reprendre sa fiche » (conserve l'historique de scolarité et le compte unique) ou « Personne différente » (confirmation explicite, journalisée). La création d'un doublon sans confirmation est refusée (409 avec la fiche existante).
- **Consentements** (horodatés, révocables) : communication par SMS et WhatsApp ; utilisation de la photo ; accès des parents aux résultats (étudiant majeur). Ils pilotent le module Messaging et les portails.
- **Filière et niveau** (étape 3, via Organisation) : chaque choix indique places prises/places totales (« 38 / 60 places »), l'état de l'autorisation (« Autorisée » ou « Fermée : autorisation expirée le 30/09/2026 », non sélectionnable), la section (FR/EN) et le régime (jour/soir).
- **Pièces du dossier** (étape 4) : liste avec états `reçu`, `expire bientôt` (« Expire 11/2026 »), `manquant`, compteur « 5 / 7 » et jauge ; PDF, JPG ou PNG, **5 Mo maximum**, analyse antivirus (port à brancher plus tard, refus par défaut des types dangereux), stockage hors racine web, accès par URL signée temporaire ; « les pièces manquantes peuvent être fournies sous 30 jours » (paramètre `enrolment.documents_grace_days`).
- **Frais et paiement** (étape 5, via Billing) : voir plus bas ; trois issues : « Encaisser au guichet maintenant », « Envoyer la demande de paiement mobile », « Valider sans paiement (pré-inscription) ».
- Parent multi-enfants : un parent/garant lié à plusieurs apprenants de plusieurs établissements du même client voit tous ses enfants.

## Frais et paiements (S04, écrans Frais et Parent)
- **Référence de paiement unique par famille** (« FAM-0587 ») valable pour Orange Money, MTN MoMo et le guichet ; envoyée au garant par SMS. Un paiement saisi par référence est réparti automatiquement entre les enfants (« FAM-0412 · Famille Foka » : Yann 35 000, Nadia 0, « Dû par la famille 35 000 FCFA »).
- **Affectation automatique** : retards d'abord, puis échéances les plus anciennes (règle paramétrable) ; le texte affiché : « Affectation automatique : retards d'abord, puis échéances les plus anciennes. Reçu numéroté envoyé par SMS au parent. »
- **Modes de paiement** : espèces, Orange Money, MTN MoMo, virement/carte.
- **Reçu** numéroté sans trou, par établissement et par année, au format `R-<CODE ÉTABLISSEMENT>-<AA>-<5 chiffres>` (ex. `R-IPES-26-04821`), PDF et SMS immédiats.
- **Caisse** : « Caisse n°2 ouverte · A. Bello · depuis 07:42 », fonds de caisse (50 000), espèces encaissées du jour, « Arrêter la caisse » (arrêté avec écart nominatif). Indicateurs : « Aujourd'hui 4,82 M · 143 reçus émis », « À rapprocher 7 · mobile money sans référence », « Écart de caisse 0 · dernier arrêté hier 17:05 ».
- **Journal du jour** (onglets Journal du jour, Mobile money, Annulations) : colonnes reçu, heure, payeur, mode, montant, statut. Statuts : `Rapproché`, `En caisse`, `Sans référence` (mobile money sans référence famille, à rapprocher), `Annulation · 2nd agent requis`. Un montant négatif apparaît pour une annulation en attente. L'annulation est une écriture inverse motivée, validée par un second agent (module Audit\Approvals).
- **Mobile parent** : solde famille agrégé tous établissements (« 35 000 FCFA · échéance 03/11 »), bouton « Tout payer » (mobile money), détail par enfant (« 35 000 dus », « À jour »).
- Remises : fratrie y compris entre établissements (« Remise fratrie (frère au Collège) -25 000 »), bourses, exonération validée. Montants **entiers en XAF**.
- Blocages d'impayé paramétrables (résultats, documents, réinscription), jamais l'accès aux cours ni la composition, jamais certificat de scolarité, reçu, relevé de compte, attestation de paiement.
- Relances paramétrables avant et après échéance (par défaut J-7, J+1, J+15).

## Messages (S05)
- Modèles bilingues avec variables (`:receipt_number`, `:amount`…) ; canaux SMS, e-mail, notification ; ciblage : une personne, une classe, un niveau, un établissement, les impayés, les absents du jour.
- Respect du consentement de communication et des plages horaires (7 h à 21 h par défaut, hors messages urgents) : un message hors plage est mis en file jusqu'à 7 h.
- Comptage des SMS par établissement et par mois (facturation) ; suivi de délivrance par destinataire.
- Exemples de messages issus des maquettes : reçu de paiement ; relance de tranche ; « absent · parent notifié à la synchro » (présences) ; référence de paiement famille ; demande de paiement mobile.

## Documents officiels (S06, écrans DocRegistre, Verification, Bulletin, Releve)
- **Génération en lot** : choix du document (ex. « Bulletin trimestriel · Trimestre 1 »), des classes, de la langue (FR/EN), de la version du modèle (« v3 · validé 02/10 ») ; **élèves exclus du lot** avec le motif (pension impayée, blocage paramétré : « 4 élèves exclus du lot ») et lien pour voir la liste ; bouton « Générer 124 bulletins » ; **file d'attente** avec progression (« 86 / 140 »), estimation de fin et notification à la fin.
- **Registre des documents émis** : numéro unique, document, bénéficiaire, émis par (« Lot · J. Ateba », « Demande en ligne », « Guichet »), date, statut (`Valide`, `Révoqué · remplacé`), actions Aperçu / Motif ; filtres Tous, Bulletins, Certificats, Révoqués. Un document n'est jamais supprimé : il est révoqué avec un motif et, si besoin, remplacé.
- **Numéro** : `<CODE ÉTABLISSEMENT>-<AA>-<LETTRE DE TYPE>-<6 chiffres>` dans la maquette (ex. `CLP-26-B-004812`) ; le gabarit est **configurable par type de document** (le CDC IPES utilise `REL-EC-2026-000315`). Numérotation continue sans trou par établissement, type et année.
- **QR et vérification publique** (route publique, limitée en débit) : jeton aléatoire de 128 bits non séquentiel ; réponse « Document authentique » avec type, établissement, titulaire **masqué** (« F••• Nadia »), année, numéro, date d'émission, empreinte courte (« 9f3e…a21c ») ; autres états : `Révoqué` (remplacé, motif affiché) et `Introuvable` (« le document n'a pas été émis par SCHOLARIS »). Aucune note affichée ; « la moyenne n'est affichée qu'au titulaire connecté ». Consultation journalisée.
- **Modèles** : HTML par établissement et par langue, versionnés (un document réimprimé garde la version de son émission), variables autorisées déclarées (liste blanche), « Duplicata » avec date de réimpression sur une réimpression, signataires et cachet par établissement. Les modèles de bulletin et de relevé (maquettes Bulletin et Releve) seront fournis par les applications ; le socle fournit le moteur et un modèle de test.
- **Impression** : PDF via le port PdfRenderer (simulateur pour l'instant) ; lot traité par file (table `jobs`).

## Ton
Français soutenu, phrases courtes, messages d'erreur disant quoi faire.
