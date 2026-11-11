# Guide d'utilisation de l'application Secondaire de SCHOLARIS

Version du guide : novembre 2026. Ce guide décrit les écrans tels qu'ils existent dans la version en service. Les captures d'écran ont été prises sur un lycée fictif, avec des données fictives.

## À qui s'adresse ce guide ?

À toutes les personnes d'un collège ou d'un lycée du Cameroun (public ou privé, général ou technique) qui utilisent SCHOLARIS : le chef d'établissement (proviseur ou principal), le censeur, le surveillant général, l'intendant ou économe, le caissier, le secrétariat de la scolarité, les enseignants et professeurs principaux, le conseiller d'orientation, le chef des travaux, l'infirmier et les responsables des services, ainsi que les parents et les élèves pour leurs espaces.

Vous n'avez pas besoin de connaissances en informatique. Chaque tâche est expliquée pas à pas.

## Présentation en quelques mots

SCHOLARIS est un logiciel en ligne, accessible depuis un ordinateur ou un téléphone, à l'adresse :

**https://scholariserp.trugroup.cm**

L'application **Secondaire** gère toute l'année scolaire d'un établissement d'enseignement secondaire :

1. l'ouverture et le paramétrage (type d'établissement, cycles, séries, classes, matières, coefficients, calendrier) ;
2. les inscriptions, le matricule et la carte scolaire ;
3. les frais, les factures, la caisse et le paiement par Orange Money ou MTN MoMo ;
4. l'emploi du temps, le cahier de textes, l'appel et la discipline ;
5. les notes de séquence, le conseil de classe et les bulletins ;
6. l'orientation, les examens officiels, les ateliers et les stages ;
7. le personnel, la paie, les services (infirmerie, cantine, transport, internat) ;
8. les tableaux de bord, les documents officiels et les espaces des parents et des élèves.

Dans le menu de gauche, l'application s'appelle **Secondaire**. Son accueil (adresse `/secondaire`) affiche une carte par rubrique. **Vous ne voyez que les cartes de votre rôle**, et seulement celles des modules activés pour votre établissement.

## Sommaire

| N° | Chapitre | Contenu |
|---|---|---|
| 01 | [Premiers pas](01-premiers-pas.md) | activer son compte, se connecter, choisir l'établissement et le rôle, langue, accueil |
| 02 | [Ouverture et paramétrage](02-ouverture-parametrage.md) | profil et modules, niveaux, séries, classes, matières, coefficients et volumes, autorisations d'ouverture, trimestres et séquences, comptes |
| 03 | [Inscriptions](03-inscriptions.md) | inscription, garant financier, matricule, préinscription, réinscription, transferts, import, carte scolaire, reprise des données |
| 04 | [Frais et encaissement](04-frais.md) | catalogue des frais autorisés, frais, échéancier, facture, caisse, reçu, situation financière, privé |
| 05 | [Emploi du temps et cahier de textes](05-emploi-du-temps-cahier.md) | affectations, créneaux, génération, remplacements, séances, progressions, couverture, visas |
| 06 | [Appel et discipline](06-appel-discipline.md) | appel par heure (téléphone), absences, barème, sanctions, conseil de discipline |
| 07 | [Notes de séquence](07-notes.md) | cycle des notes, saisie, import, validation, verrouillage, relevé, corrections |
| 08 | [Conseil de classe et bulletins](08-conseil-bulletins.md) | règles, conseil, décisions, émission et aperçu des bulletins |
| 09 | [Orientation](09-orientation.md) | vœux, avis du conseiller, décision, recours |
| 10 | [Examens officiels](10-examens-officiels.md) | sessions, candidats, pièces, résultats |
| 11 | [Ateliers et stages](11-ateliers-stages.md) | enseignement technique : ateliers, sécurité, matière d'œuvre, stages |
| 12 | [Personnel et paie](12-personnel-paie.md) | statuts du personnel, heures, vacations, bulletins de paie |
| 13 | [Services](13-services.md) | infirmerie, cantine, transport, internat, bibliothèque |
| 14 | [Tableaux de bord](14-tableaux-de-bord.md) | objectifs O1 à O7, effectifs, pédagogie, finances, rapports |
| 15 | [Portails parent, élève et enseignant](15-portails.md) | espace famille et paiement mobile, espace élève, espace enseignant |
| 16 | [Documents](16-documents.md) | certificats, cartes, exeat, listes, attestations, vérification par QR |
| 17 | [Rôles : qui fait quoi](17-roles.md) | ce que chaque rôle peut faire |
| 18 | [Questions fréquentes](18-faq.md) | problèmes courants et solutions |

## Qui lit quoi ?

Tout le monde lit d'abord le chapitre **01 Premiers pas** et le chapitre **17 Rôles**.

| Rôle | Chapitres conseillés |
|---|---|
| Chef d'établissement | 02, 03, 07, 08, 12, 14, 16 |
| Censeur | 05, 07, 08, 10 |
| Surveillant général | 06, 08 |
| Intendant ou économe | 04, 12, 13, 14 |
| Caissier | 04 |
| Secrétariat de la scolarité | 03, 10, 16 |
| Enseignant | 05, 06, 07, 15 |
| Professeur principal | 07, 08, 15 |
| Conseiller d'orientation | 09 |
| Chef des travaux | 11 |
| Infirmier, responsables des services | 13 |
| Administrateur fonctionnel | 02, 04, 17 |
| Parent, élève | 01, 15, 18 |

## Conventions de lecture

- **Texte en gras** : un menu, un écran, un onglet ou un bouton, écrit exactement comme à l'écran. Exemple : cliquez sur **Inscrire**.
- **Secondaire > Notes** : chemin à suivre. Ici : ouvrez **Secondaire** dans le menu de gauche, puis la carte **Notes**.
- « Texte entre guillemets » : un message affiché par l'application.
- `/secondaire/notes` : la fin de l'adresse de l'écran, à taper après `https://scholariserp.trugroup.cm` pour y aller directement.
- **Qui** : les rôles qui peuvent faire la tâche. Si vous ne voyez pas un bouton, c'est en général que votre rôle ne le permet pas.
- Les noms, numéros et montants des exemples sont **fictifs**.

## Trois règles à retenir

1. **Un compte = une personne.** Ne prêtez jamais votre mot de passe.
2. **Rien ne se supprime en cachette.** Notes, paiements, coefficients et documents se corrigent par une opération tracée (motif, auteur, date), jamais par effacement.
3. **Aucun élève n'est privé d'inscription, de cours ou d'évaluation pour impayé.**
