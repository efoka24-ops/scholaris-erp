# 12. Fiches par rôle (R01 à R23)

Chaque personne reçoit un ou plusieurs **rôles**, chacun avec un **périmètre** (tout l'institut, une école, un département, une filière ou un groupe) : voir chapitre 11. Le rôle dit **ce que** vous pouvez faire ; le périmètre dit **sur quoi**. Si un écran ou un bouton manque, vérifiez d'abord votre rôle actif dans la barre du haut.

Légende : **2FA** = double authentification obligatoire pour ce rôle (chapitre 01).

## Vue d'ensemble

| Code | Rôle | Périmètre habituel | 2FA |
|---|---|---|---|
| R01 | Promoteur | institut | non |
| R02 | Directeur général | client (tout le groupe) | oui |
| R03 | Directeur académique | institut | oui |
| R04 | Directeur d'école | une école | oui |
| R05 | Chef de département | un département | non |
| R06 | Responsable de filière | une filière | non |
| R07 | Service de la scolarité | une école | non |
| R08 | Service des examens | une école | non |
| R09 | Enseignant permanent | ses groupes / filières | oui |
| R10 | Enseignant vacataire | ses groupes / filières | non (mais il a aussi R09) |
| R11 | Responsable de la discipline | une école | non |
| R12 | Étudiant | lui-même | non |
| R13 | Garant | ses étudiants | non |
| R14 | Caissier | institut ou école | oui |
| R15 | Comptable | institut ou école | oui |
| R16 | Responsable des stages | filière ou école | non |
| R17 | Responsable des ressources humaines | institut | non |
| R18 | Bibliothécaire | institut | non |
| R19 | Responsable de la communication | institut | non |
| R20 | Administrateur fonctionnel | institut | non |
| R21 | Membre de jury | filière ou groupe, durée de la session | non |
| R22 | Université de tutelle (externe) | ses filières, 90 jours au plus | non |
| R23 | Inspection / auditeur (externe) | périmètre accordé, 90 jours au plus | non |

---

## R01 Promoteur

**Mission** : le fondateur suit la santé de l'institut, sans intervenir dans la gestion courante.

**Peut** : consulter les tableaux de bord, les finances, les admissions, les inscriptions, les maquettes, l'emploi du temps, les présences, les notes, les délibérations, les vacations et les documents ; consulter les statistiques (menu **Rapports**).

**Ne peut pas** : saisir ou modifier quoi que ce soit (pas de saisie de notes, pas d'encaissement, pas de décision).

**Écrans** : **IPES > Tableaux de bord** ; toutes les rubriques IPES en lecture.

**Tâches fréquentes** : lire chaque jour **Recouvrement des frais** et, en campagne, **Candidatures et inscriptions** ; chaque année **Examens nationaux BTS et HND** (chapitre 09).

## R02 Directeur général

**Mission** : piloter l'institut, valider les paramètres sensibles, signer les documents institutionnels.

**Peut** : tout consulter ; **publier les maquettes** ; **approuver les états de vacation** ; gérer les modèles de documents ; signer les documents institutionnels (attestations de réussite DOC-11, attestations de fin de formation DOC-12, listes MINESUP) ; **exporter la liste des candidats** aux examens nationaux ; il reçoit les alertes (réouverture de délibération, modification d'une liste après export, autorisations qui expirent).

**Ne peut pas** : saisir des notes, encaisser, décider d'une admission, présider un jury (sauf rôle supplémentaire).

**Écrans** : **Tableaux de bord**, **Maquettes**, **Vacations**, **Examens nationaux**, **Documents officiels**, toutes les rubriques en lecture.

**Tâches fréquentes** : publier les maquettes (chapitre 02, 2.6) ; **Approuver (direction)** les états de vacation (chapitre 08, 8.5) ; suivre les tableaux de bord (chapitre 09).

## R03 Directeur académique

**Mission** : maquettes, calendrier, supervision des délibérations, relations avec l'université de tutelle.

**Peut** : **publier les maquettes** ; **modifier un règlement figé** (avec motif et 2FA) ; gérer le **calendrier académique** ; gérer les **tutelles** et faire les **transmissions** à l'université ; **rouvrir une délibération signée** (motivée, tracée) ; tout consulter dans son domaine (admissions, inscriptions, emplois du temps, présences, notes, délibérations, stages, tableaux de bord).

**Ne peut pas** : saisir des notes, valider des inscriptions, encaisser, constituer un jury.

**Écrans** : **Paramétrage académique** (Calendrier, Tutelles, Règlement des études), **Maquettes**, **Délibérations** (lecture, réouverture), **Tutelle**, **Tableaux de bord**.

**Tâches fréquentes** : chapitre 02 (2.3 à 2.6), chapitre 07 (7.12), chapitre 08 (8.4).

## R04 Directeur d'école

**Mission** : diriger une école de l'institut et valider ses décisions académiques.

**Peut** : gérer le **paramétrage** de son école (structure, autorisations, règlement en brouillon, calendrier) ; saisir les **maquettes** ; **décider des admissions** (et des dépassements de places) ; **valider les inscriptions**, y compris **sous réserve financière** ; accorder les **dérogations** (3e inscription, levée d'une interdiction de composer) ; enregistrer une décision de passage ; consulter les emplois du temps ; autoriser les **dépassements d'heures** ; **2e validation des corrections de notes** ; **présider les jurys** (ouvrir, clôturer la séance, points de jury) ; **viser les états de vacation** ; **signer les documents de l'école** et la convention de stage côté école ; participer à la reprise de données.

**Ne peut pas** : saisir les notes ; signer un PV de délibération **sans** le rôle Membre de jury (R21) ; publier les résultats (c'est la scolarité) ; encaisser.

**Écrans** : **Paramétrage académique**, **Autorisations MINESUP**, **Règlement des études**, **Maquettes**, **Admissions**, **Inscriptions**, **Notes** (Corrections), **Délibérations**, **Documents officiels**, **Vacations**, **Conventions de stage**, **Tableaux de bord**.

**Tâches fréquentes** : chapitre 03 (3.2 décision, 3.3 réserve financière, 3.4 dérogation) ; chapitre 06 (6.7) ; chapitre 07 (7.5) ; chapitre 08 (8.5 visa).

## R05 Chef de département

**Mission** : organiser l'enseignement de son département : qui enseigne quoi, quand, combien d'heures ; contrôler les notes.

**Peut** : **affecter les enseignants** ; gérer l'**emploi du temps**, les salles et les délégués ; saisir les **maquettes** ; **contre-signer le cahier de textes** et **valider les heures** du mois ; établir les états de vacation ; **valider et verrouiller les notes**, renvoyer une saisie à l'enseignant ; **1re validation des corrections** ; traiter les **requêtes** des étudiants ; **préparer les délibérations** (jury, présents, simulation, rattrapage, décisions annuelles, relevés) ; consulter tableaux de bord, stages, admissions et inscriptions de son département.

**Ne peut pas** : saisir les notes à la place de l'enseignant ; publier les notes ; valider une correction qu'il a lui-même demandée.

**Écrans** : **Enseignement** (toutes sections), **Présences et discipline > Assiduité**, **Notes** (toutes sections), **Délibérations**, **Vacations**, **Maquettes**, **Tableaux de bord**.

**Tâches fréquentes** : chapitre 05 (5.3, 5.4, 5.7, 5.8) ; chapitre 06 (6.6 à 6.9) ; chapitre 07 (7.2 à 7.4, 7.10).

## R06 Responsable de filière

**Mission** : suivi pédagogique d'une filière, emplois du temps, stages.

**Peut** : gérer l'**emploi du temps** de sa filière et les délégués ; saisir les **maquettes** ; gérer les **stages** (conventions, entreprises, validation du sujet de mémoire) ; consulter le cahier de textes, les présences, les notes et les inscriptions de sa filière.

**Ne peut pas** : affecter les enseignants, valider les notes, décider des admissions.

**Écrans** : **Enseignement > Emploi du temps**, **Délégués de groupe**, **Maquettes**, **Conventions de stage**.

**Tâches fréquentes** : chapitre 05 (5.4, 5.5) ; chapitre 08 (8.1).

## R07 Service de la scolarité

**Mission** : le dossier de chaque étudiant, de la candidature à la carte d'étudiant ; préparation des délibérations et des examens nationaux.

**Peut** : gérer les **campagnes** et **candidatures** (pièces, frais de dossier, notes de concours, classement) ; **créer et valider les inscriptions**, gérer le contrat pédagogique, les statuts et les changements ; gérer les personnes ; gérer l'emploi du temps et les délégués ; justifier les absences, **envoyer les alertes d'assiduité** et transmettre les listes au service des examens, demander les épreuves de remplacement ; **publier les notes** aux étudiants ; **préparer les délibérations** et **publier les résultats** ; émettre les **documents officiels** et les relevés ; gérer la **liste des candidats aux examens nationaux** et l'exporter ; faire les **transmissions à la tutelle** ; consulter les finances et les tableaux de bord ; participer à la reprise de données.

**Ne peut pas** : décider d'une admission ; valider sous réserve financière ; encaisser ; saisir ou verrouiller des notes.

**Écrans** : **Admissions**, **Inscriptions**, **Documents officiels**, **Notes** (publication, suivi), **Délibérations**, **Présences et discipline**, **Enseignement > Emploi du temps**, **Examens nationaux**, **Tutelle**, **Frais et paiements** (lecture), **Tableaux de bord**.

**Tâches fréquentes** : chapitre 03 en entier ; chapitre 05 (5.9, 5.10) ; chapitre 06 (6.6 publication) ; chapitre 07 (7.2 à 7.11, 7.13) ; chapitre 08 (8.3, 8.4).

## R08 Service des examens

**Mission** : calendrier des épreuves, anonymat, convocations, surveillance.

**Peut** : gérer le **planning des examens** (épreuves, salles, surveillants, publication, convocations, feuille d'émargement) ; **lever l'anonymat** des copies ; programmer les **épreuves de remplacement** ; accuser réception des **listes de non-autorisés à composer** ; contre-signer le cahier de textes ; consulter notes, présences, emploi du temps et documents.

**Ne peut pas** : saisir ou valider les notes ; délibérer.

**Écrans** : **Enseignement > Planning des examens**, **Présences et discipline > Assiduité** et **Épreuves de remplacement**, **Notes** (lecture, levée d'anonymat).

**Tâches fréquentes** : chapitre 05 (5.9, 5.10, 5.12) ; chapitre 06 (6.3 copies anonymes).

## R09 Enseignant permanent

**Mission** : faire cours, faire l'appel, tenir le cahier de textes, évaluer.

**Peut** : faire l'**appel** (même hors ligne) ; consigner et signer le **cahier de textes** ; créer ses **évaluations**, **saisir et importer les notes**, **soumettre** ; demander une **correction** ; consulter son emploi du temps, les maquettes et ses états de vacation.

**Ne peut pas** : voir ou saisir les EC et groupes qui ne lui sont pas affectés (refus 403, tentative journalisée) ; valider ou verrouiller ses propres notes ; modifier une note verrouillée sans demande de correction.

**Écrans** : **Mon espace enseignant**, **Appel**, **Enseignement > Emploi du temps** et **Cahier de textes**, **Notes**, **Mes vacations**.

**Tâches fréquentes** : chapitre 05 (5.6, 5.7) ; chapitre 06 (6.2 à 6.7) ; chapitre 10 (10.4).

## R10 Enseignant vacataire

**Mission** : comme l'enseignant permanent, avec le suivi de ses heures et paiements.

**Fonctionnement** : le vacataire reçoit **les deux rôles** R09 et R10. R10 n'ajoute que **Mes vacations** (états mensuels, HETD, montant, paiement).

**Ne peut pas** : comme R09. Ses heures ne sont payées que si les séances sont émargées, consignées et contre-signées, et le solde d'un EC attend la saisie complète de ses notes.

**Tâches fréquentes** : chapitre 08 (8.5) ; chapitre 10 (10.4).

## R11 Responsable de la discipline

**Mission** : absences, retards, sanctions, conseil de discipline.

**Peut** : prononcer les **sanctions** (avertissement, blâme, exclusion temporaire) et émettre leur notification ; **convoquer et tenir le conseil de discipline**, signer son PV ; consulter l'assiduité et le tableau de bord **Assiduité**.

**Ne peut pas** : prononcer seul une exclusion définitive (le conseil seulement, après PV signé) ; modifier le statut d'inscription (la scolarité le reporte).

**Écrans** : **Présences et discipline > Discipline** et **Assiduité**, **Tableaux de bord**.

**Tâches fréquentes** : chapitre 05 (5.11) ; chapitre 09.

## R12 Étudiant

**Mission** : suivre sa scolarité et ses paiements.

**Peut** : consulter son inscription, son compte, ses reçus et documents ; **payer** par Orange Money ou MTN MoMo ; consulter ses **notes publiées** et ses **résultats** ; déposer une **requête** dans le délai ; suivre son **stage** et déposer son mémoire ; demander un document.

**Ne peut pas** : voir les notes avant publication ; voir les données d'un autre étudiant ; modifier quoi que ce soit dans son dossier (il s'adresse à la scolarité).

**Écrans** : **Mon espace étudiant**, **Mes notes**, **Mes résultats**, **Mon stage**.

**Tâches fréquentes** : chapitre 10 (10.1).

## R13 Garant

**Mission** : suivre et payer la scolarité d'un ou plusieurs étudiants.

**Peut** : voir la **situation financière** et les reçus de ses étudiants, et **payer** ; voir les **notes et absences** seulement si l'étudiant majeur a donné son accord.

**Ne peut pas** : voir les notes sans consentement ; agir sur le dossier.

**Écrans** : **Espace garant**.

**Tâches fréquentes** : chapitre 10 (10.2).

## R14 Caissier

**Mission** : encaisser et remettre les reçus.

**Peut** : ouvrir et arrêter sa **caisse** ; **encaisser** en espèces, mobile money, virement ; consulter les comptes de scolarité.

**Ne peut pas** : ouvrir un compte, accorder une bourse, modifier une grille, annuler un paiement (le comptable le fait avec un second agent), émettre le quitus.

**Écrans** : **Frais et paiements** (caisse), **IPES > Frais et paiements > Comptes de scolarité**.

**Tâches fréquentes** : chapitre 04 (4.4).

## R15 Comptable

**Mission** : frais, bourses, échéanciers, documents financiers, paiement des vacations.

**Peut** : gérer les **grilles de frais**, **ouvrir les comptes**, demander et valider (pour un collègue) les **bourses** et **échéanciers**, gérer les **tiers payeurs** ; émettre l'**attestation de paiement** et le **quitus** ; encaisser les paiements autres qu'en espèces ; établir les états de vacation et **enregistrer leur paiement** ; consulter le paramétrage, les admissions, les inscriptions, le cahier de textes (heures), les documents et les tableaux de bord.

**Ne peut pas** : valider sa propre demande de bourse ou d'échéancier ; supprimer un paiement ou une facture ; encaisser des espèces sans être aussi caissier.

**Écrans** : **Frais et paiements** (Grilles, Comptes, Tiers payeurs), **Vacations**, **Documents officiels**, **Tableaux de bord**.

**Tâches fréquentes** : chapitre 04 (4.2, 4.3, 4.5 à 4.8, 4.11) ; chapitre 08 (8.5).

## R16 Responsable des stages

**Mission** : stages et soutenances.

**Peut** : gérer les **entreprises** et les **conventions** (émission, signatures étudiant et entreprise, suivi, attestation, mémoire) ; **programmer les soutenances**, constituer le jury, convoquer ; consulter les inscriptions.

**Ne peut pas** : signer la convention pour l'école (directeur d'école) ; émettre le quitus.

**Écrans** : **Conventions de stage**, **Entreprises**, **Soutenances**.

**Tâches fréquentes** : chapitre 08 (8.1, 8.2).

## R17 Responsable des ressources humaines

**Mission** : dossiers du personnel et contrats de vacation.

**Peut** : gérer les **fiches du personnel** (menu **Personnel**) et les **dossiers enseignants** (**Enseignement > Enseignants**) ; gérer les **contrats de vacation** (menu **Personnel** > vacations) ; consulter les vacations.

**Ne peut pas** : valider les heures, payer les vacations.

**Écrans** : **Personnel**, **Enseignement > Enseignants**, **Vacations** (lecture).

**Tâches fréquentes** : chapitre 05 (5.1) ; chapitre 08 (8.5).

## R18 Bibliothécaire

**Mission** : prévue pour la gestion de la bibliothèque (option).

**État actuel** : le rôle existe mais **n'a aucune permission ni aucun écran** dans cette version. Ne l'attribuez pas pour l'instant.

## R19 Responsable de la communication

**Mission** : messages et annonces aux étudiants, garants et personnel.

**Peut** : utiliser le menu général **Messages et annonces** (lire, envoyer, gérer les modèles de messages).

**Ne peut pas** : consulter les notes, les finances ou les dossiers dans l'IPES.

**Écrans** : **Messages et annonces**. Pas de rubrique IPES dédiée.

## R20 Administrateur fonctionnel

**Mission** : préparer l'application pour toute l'équipe : comptes, rôles, paramètres, modèles.

**Peut** : créer les personnes et leurs comptes, **attribuer et retirer les rôles** ; régler les **paramètres** ; gérer les **modèles de documents** ; gérer le **paramétrage académique** (structure, autorisations MINESUP, règlement en brouillon, calendrier).

**Ne peut pas** : saisir des notes, encaisser, décider d'admissions ; gérer les années et les établissements (super administrateur) ; payer l'abonnement.

**Écrans** : **Administration > Utilisateurs et rôles** et **Paramètres**, **IPES > Paramétrage académique**, **Autorisations MINESUP**, **Règlement des études**, calendrier.

**Tâches fréquentes** : chapitre 11 ; chapitre 02 (2.1, 2.2, 2.5).

## R21 Membre de jury

**Mission** : siéger à un jury de délibération ou de soutenance pour une session donnée.

**Peut** : voir ses jurys dans **Mes jurys** ; **signer le procès-verbal** (avec 2FA) s'il était présent ; consulter les délibérations ; noter les soutenances où il siège.

**Ne peut pas** : modifier les notes ; publier les résultats. Son rôle prend fin avec la session.

**Écrans** : **Mes jurys**, **Délibérations** (lecture), **Soutenances** (**Mes jurys uniquement**).

**Tâches fréquentes** : chapitre 07 (7.6) ; chapitre 10 (10.3).

## R22 Université de tutelle (externe)

**Mission** : consulter, en lecture seule, les inscrits des filières placées sous sa tutelle.

**État actuel** : l'écran **Tutelle > Accès de l'université** existe mais l'accès est **fermé dans cette version** (« L'accès de l'université n'est pas ouvert pour cet établissement. »). Les échanges passent par les transmissions faites par l'institut (chapitre 08, 8.4). Si l'accès est ouvert un jour : compte nominatif, 90 jours au plus, lecture seule, chaque consultation journalisée.

## R23 Inspection / auditeur (externe)

**Mission** : contrôle par une autorité ou un auditeur, sur un périmètre et une durée limités (90 jours au plus).

**État actuel** : le rôle porte une permission de lecture d'audit, mais **aucun écran IPES dédié** n'existe dans cette version. Pour une inspection, l'institut fournit les exports et documents nécessaires (chapitre 09) ; contactez l'équipe SCHOLARIS si un accès direct est demandé.
