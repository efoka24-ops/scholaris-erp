# Guide d'utilisation de l'application IPES (Supérieur) de SCHOLARIS

Version du guide : octobre 2026. Ce guide décrit les écrans tels qu'ils existent dans la version en service.

## À qui s'adresse ce guide ?

À toutes les personnes d'un institut privé d'enseignement supérieur (IPES) du Cameroun qui utilisent SCHOLARIS :
la direction, la scolarité, le service des examens, la caisse et la comptabilité, les chefs de département, les enseignants,
les responsables des stages, de la discipline et des ressources humaines, les membres de jury, les étudiants et leurs garants (parents, tuteurs, entreprises).

Vous n'avez pas besoin de connaissances en informatique. Chaque tâche est expliquée pas à pas.

## Présentation en quelques mots

SCHOLARIS est un logiciel en ligne, accessible depuis un ordinateur ou un téléphone, à l'adresse :

**https://scholariserp.trugroup.cm**

L'application **IPES** (appelée aussi application « Supérieur ») gère toute la vie d'un étudiant :

1. l'organisation de l'institut (écoles, filières, autorisations MINESUP, maquettes) ;
2. les admissions et les inscriptions (avec le matricule) ;
3. les frais de scolarité, la caisse et le paiement par Orange Money ou MTN MoMo ;
4. l'emploi du temps, l'appel, le cahier de textes et les heures des enseignants ;
5. les notes, les délibérations, les relevés et les documents officiels avec QR de vérification ;
6. les stages, les soutenances, les examens nationaux BTS et HND, la tutelle universitaire ;
7. les tableaux de bord de la direction.

Dans le menu de gauche, l'application s'appelle **IPES**. Son accueil (adresse `/superieur`) affiche des cartes : chaque carte ouvre une rubrique. **Vous ne voyez que les cartes de votre rôle.**

## Sommaire

| N° | Chapitre | Contenu |
|---|---|---|
| 00 | [Sommaire](00-sommaire.md) | ce document |
| 01 | [Premiers pas](01-premiers-pas.md) | activer son compte, se connecter, double authentification, choisir son établissement, mot de passe oublié, sécurité |
| 02 | [Paramétrage](02-parametrage.md) | écoles, départements, filières, niveaux, groupes, autorisations MINESUP, tutelles, règlement des études, calendrier, maquettes |
| 03 | [Admissions et inscriptions](03-admissions-inscriptions.md) | campagnes, candidatures, pièces, décisions, inscription, matricule, contrat pédagogique, réinscription, statuts |
| 04 | [Finances](04-finances.md) | grilles de frais, comptes de scolarité, caisse, encaissement, bourses, échéanciers, tiers payeurs, reçus, attestation, quitus, paiement mobile |
| 05 | [Enseignement et présences](05-enseignement-presences.md) | enseignants, salles, affectations, emploi du temps, cahier de textes, heures, appel, assiduité, discipline, planning des examens |
| 06 | [Notes](06-notes.md) | évaluations, saisie (y compris hors ligne et par fichier), cycle de validation, corrections, requêtes, statistiques |
| 07 | [Délibérations et documents](07-deliberations-documents.md) | jurys, simulation, séance, PV, publication, rattrapage, relevés, documents DOC-01 à DOC-32, vérification QR |
| 08 | [Fin d'études](08-fin-d-etudes.md) | stages, soutenances, examens nationaux BTS/HND, tutelle, vacations |
| 09 | [Pilotage](09-pilotage.md) | tableaux de bord, exports, reprise de données |
| 10 | [Portails](10-portails.md) | espace étudiant, espace garant, membre de jury, espace enseignant |
| 11 | [Administration](11-administration.md) | utilisateurs, rôles, périmètres, activation des comptes, paramètres, abonnement, régularisation et déclaration de paiement, circuits de validation, import des personnes |
| 12 | [Fiches par rôle](12-roles.md) | les 23 rôles R01 à R23 : ce que chacun peut et ne peut pas faire |
| 13 | [FAQ et dépannage](13-faq-depannage.md) | questions fréquentes et messages d'erreur |
| 14 | [Glossaire](14-glossaire.md) | LMD, UE, EC, crédits, MGP, HETD, PV, DOC-xx… |

## Qui lit quoi ?

Tout le monde lit d'abord le chapitre **01 Premiers pas** et sa fiche de rôle dans le chapitre **12**.

| Rôle | Chapitres conseillés |
|---|---|
| R01 Promoteur | 01, 09, 04 (lecture), 12 |
| R02 Directeur général | 01, 02, 07, 08 (vacations, examens nationaux), 09, 12 |
| R03 Directeur académique | 01, 02 (règlement, calendrier, tutelles, maquettes), 07, 08 (tutelle), 09 |
| R04 Directeur d'école | 01, 02, 03, 05 (dérogations, dépassements), 06 (corrections), 07, 08 (vacations), 09 |
| R05 Chef de département | 01, 02 (maquettes), 05, 06, 07 (préparation des jurys), 08 (vacations), 09 |
| R06 Responsable de filière | 01, 02 (maquettes), 05 (emploi du temps), 08 (stages) |
| R07 Service de la scolarité | 01, 03, 05 (assiduité, délégués), 06 (publication), 07, 08 (examens nationaux, tutelle), 09 |
| R08 Service des examens | 01, 05 (planning des examens, remplacements), 06 (anonymat) |
| R09 Enseignant permanent, R10 Enseignant vacataire | 01, 05 (appel, cahier de textes), 06, 08 (vacations), 10 (espace enseignant) |
| R11 Responsable de la discipline | 01, 05 (discipline, assiduité), 09 |
| R12 Étudiant | 01, 10, 13 |
| R13 Garant | 01, 10, 13 |
| R14 Caissier | 01, 04 |
| R15 Comptable | 01, 04, 08 (vacations), 09 |
| R16 Responsable des stages | 01, 08 (stages et soutenances) |
| R17 Responsable RH | 01, 05 (dossiers enseignants), 08 (vacations) |
| R18 Bibliothécaire | 01, 12 (rôle sans écran pour l'instant) |
| R19 Responsable de la communication | 01, 12 |
| R20 Administrateur fonctionnel | 01, 02, 11, 13 |
| R21 Membre de jury | 01, 07, 10 (portail « Mes jurys ») |
| R22 Université de tutelle, R23 Inspection / auditeur | 01, 08 (tutelle), 12 |

## Conventions de lecture

- **Texte en gras** : un menu, un écran, un onglet ou un bouton, écrit exactement comme à l'écran. Exemple : cliquez sur **Valider l'inscription**.
- **IPES > Inscriptions** : chemin à suivre. Ici : ouvrez l'application **IPES** dans le menu, puis la carte **Inscriptions**.
- « Texte entre guillemets » : un message affiché par l'application.
- `/superieur/inscriptions` : la fin de l'adresse de l'écran, à taper après `https://scholariserp.trugroup.cm` si vous voulez y aller directement.
- **Qui** : les rôles qui peuvent faire la tâche (voir le chapitre 12). Si vous ne voyez pas un bouton, c'est en général que votre rôle ne le permet pas.
- **RG-xxx** : référence d'une règle de gestion du cahier des charges. Vous pouvez l'ignorer ; elle aide l'équipe SCHOLARIS si vous la citez.
- Les noms, numéros et montants des exemples sont **fictifs**.

## Trois règles à retenir

1. **Un compte = une personne.** Ne prêtez jamais votre mot de passe ni votre téléphone d'authentification.
2. **Rien ne se supprime en cachette.** Les notes, paiements et documents se corrigent par une opération tracée (motif, auteur, date), jamais par effacement.
3. **En cas d'erreur, notez la « Référence à communiquer au support »** affichée dans le message, l'heure et l'écran, puis prévenez l'administrateur de votre institut.

## Aide intégrée

L'application contient aussi une rubrique **IPES > Aide** (`/superieur/aide`) avec cinq fiches rapides : saisir les notes, faire l'appel, encaisser un paiement, inscrire un étudiant, publier les résultats.
