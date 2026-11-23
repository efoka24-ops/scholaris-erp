# Décisions à prendre par l'utilisateur

Date : 2026-10-19. Chaque décision a une **valeur par défaut proposée**, utilisée si rien n'est tranché. Les questions déjà posées dans 003 (Q1 à Q22) et 004 (Q1 à Q23, Q-01 à Q-16) restent valables ; seules les plus urgentes sont reprises ici.

## A. Décisions nouvelles issues de l'audit
| N° | Prio | Décision | Défaut proposé |
|---|---|---|---|
| Q-A1 | Haute | `public_nursery` (et plus tard `public_primary`) doit-il apparaître au **catalogue public** et être ouvrable en essai par un visiteur ? | Non. Clés publiques masquées du catalogue public, ouvertes seulement par l'administrateur de plateforme ; statut `coming_soon` jusqu'à la recette |
| Q-A2 | Haute | Ordre de lancement : primaire **privé d'abord** (pilote rapide) ou **public d'abord** ? | Privé d'abord ; le public avance en parallèle sur le socle territorial (phase 5) |
| Q-A3 | Haute | Qui commite le travail public non commité (session propriétaire), et quand ? | La session propriétaire, avant toute nouvelle phase, après correction phpstan, `vue-tsc` et des gardes |
| Q-A4 | Haute | Comment distinguer une école **publique** d'une **privée** du même type (`primary`, `nursery`) ? | Par l'application ouverte au client (`public_primary` contre `primary`) ; aucun attribut d'établissement nouveau |
| Q-A5 | Haute | **Maternelle privée** : application distincte (`nursery`) qui réutilise les briques DEV et SOR, ou extension de la maternelle publique ? | Application `nursery` distincte ; sortie sécurisée et observation déplacées vers des contrats du socle partagés ; spécification courte à écrire |
| Q-A6 | Moyenne | Le **primaire privé** doit-il avoir la sortie sécurisée (Q13 de 003) en V1 ? | Non en V1 ; activable par niveau ensuite, sur le même contrat que la maternelle |
| Q-A7 | Moyenne | DOC-18, DOC-20 et DOC-21 doivent-ils passer par le catalogue `Documents` (numéro, QR, vérification) ? | Oui (RG-DOC-5) |
| Q-A8 | Moyenne | Référentiel d'observation de la maternelle : écriture réservée au niveau national (R21) ou ouverte au client ? | National seulement (RG-TER-6) ; le client lit |
| Q-A9 | Moyenne | Tarif et essai du primaire privé (Q22 de 003) et de la maternelle privée | Primaire : 60 000 XAF/mois, 90 jours. Maternelle : 30 000 XAF/mois, 90 jours (aligné sur le manifeste public) |
| Q-A10 | Basse | E-mail réel : activer le SMTP en production (aujourd'hui `MAIL_MAILER=log`) ? | Oui avant le pilote privé ; SMS par la passerelle HTTP déjà configurée (TeeeOTP) |

## B. Questions bloquantes reprises (ordre de demande)
| N° | Source | Sujet | Défaut |
|---|---|---|---|
| 1 | 004 Q23 | HTTPS avant toute donnée réelle d'élève | Données synthétiques seulement tant que HTTPS n'est pas actif |
| 2 | 003 DOC-1, Q2 | Bulletin réel du primaire (FR et EN) | Bulletin trimestriel + récapitulatif annuel, modèle éditable |
| 3 | 003 DOC-2, Q3, Q4, Q7 | Texte officiel d'évaluation (barème, périodes, compétences) | /20, 3 trimestres × 2 séquences, échelle à 3 niveaux, coefficients 1, tous marqués « exemple » |
| 4 | 003 DOC-3, Q5, Q6 | Curriculum (domaines, matières, compétences par niveau) | Saisie par l'école ; jeu `example_*` |
| 5 | 004 Q8, Q9 | Référentiels et règles de mention et de passage validés par l'Inspection générale | Règles du CDC marquées « exemple à valider » |
| 6 | 004 Q1 | Commanditaire et arrondissement pilote | Client « MINEDUB pilote », 50 écoles `SYN-*` |
| 7 | 004 Q3, Q10 | Formats du questionnaire statistique et des listes CEP/FSLC | Gabarits internes CSV/XLSX |
| 8 | 004 Q-05, Q-02, Q-09 | Restriction de garde, heure de fin des cours, appel vocal | Parent visé exclu des messages ; horaires de l'école ; SMS urgent sans voix |
| 9 | 003 Q11, Q16 | Examen de fin de cycle et rapports MINEDUB au privé | Liste exportable + saisie des résultats ; export effectifs et résultats |
| 10 | 004 Q19 | Hébergement au-delà du pilote public | Camoo pour le pilote, décision avant extension |
