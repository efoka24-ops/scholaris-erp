# Plan d'exécution par vagues d'agents : application Primaire

Date : 2026-10-13. Modèle : `specs/002-application-ipes/wave-plan.md`. Entrées : [tasks.md](tasks.md), [socle-reuse.md](socle-reuse.md), [plan.md](plan.md), [questions.md](questions.md), `specs/001-socle-plateforme/agent-conventions.md`.

## Règles communes
1. **Maximum 3 agents simultanés**, y compris les agents IPES et front déjà actifs : le coordinateur compte les créneaux globalement. Une vague peut avoir plusieurs tours.
2. **Dossiers exclusifs** : un dossier n'a qu'un agent. `database/migrations/**`, `config/**`, `composer.*`, `src/Core/**` : un seul agent par vague (P0-3 en vague 0) ; les autres décrivent leur besoin. Migrations Primary : `src/Modules/Apps/Primary/<Module>/Database/Migrations/AAAAMMJJHHMM_primary_<module>_*.sql`.
3. **Un `routes.php` et un `definitions.php` racine** (P0-3, glob sur `Primary/*/routes.php` et `Primary/*/definitions.php`) : aucun autre agent ne les modifie.
4. **Contrats** : `Scholaris\Modules\Socle\<M>\Contracts\*` seulement ; contrat OpenAPI par sous-module `specs/001-socle-plateforme/contracts/openapi.d/primary-<module>.yaml`.
5. **Tests** : `tests/Feature/Primary/<Module>/`, `tests/Unit/Primary/<Module>/`, `tests/Support/Primary/` (P0-3), fixtures `tests/Fixtures/Primary/` (préfixées par module).
6. **Qualité avant rendu** : php-cs-fixer sur ses fichiers seulement, phpstan 0 erreur sans baseline, phpunit tout vert (état de départ : 5130 tests), front `vue-tsc`, `eslint`, `vitest`, `build` si le front est touché.
7. **Aucune règle officielle codée** : seuils, barèmes, poids, libellés viennent de `/settings` ou `/calculation-rules` ; `PrimaryConventionsTest` le vérifie.
8. **Front** : éditions ciblées de `router/routes.ts`, `AppShell`, `lib/permissions.ts`, `i18n/*` ; relire juste avant d'éditer.
9. **Rapport d'agent** : fichiers, routes, permissions `primary.*` utilisées, résultats réels des trois commandes, demandes de changement partagé.

## Ce qui démarre sans attendre l'utilisateur / ce qui est bloqué
| Démarre tout de suite | Bloqué (question) |
|-----------------------|-------------------|
| Vague 0 entière sauf T510 (Q13) et T511 (décision PS9) | Stratégie de calcul chiffrée T534 (Q3, Q4) |
| Vague 1 entière (référentiel, profils, frais, documents administratifs) | Bulletin T540-T541 (Q2, DOC-1, Q10) |
| Vague 2 : T530-T533, T535, T536 | Examen T550 (Q11), exports T552 (Q16) |
| Vague 3 : T543, T544 (cahier, portail) | Services T553 (Q12), sortie T554 (Q13) |
| Vague 4 : T551 (indicateurs) | Clôture T555 (Q10) |

---

# VAGUE 0 : extensions du socle et squelette Primary
| Agent | Tour | Dossiers exclusifs | Tâches | Critères d'acceptation |
|-------|------|--------------------|--------|------------------------|
| **P0-1 Organisation + Attendance** | a | `src/Modules/Socle/Organisation/**`, `src/Modules/Socle/Attendance/**`, `tests/**/{Organisation,Attendance}/**`, `openapi.d/{organisation,attendance}.yaml` | T500, T501 (T510 si Q13 = oui) | Séquence avec parent `semester` ou autre année → 422 ; séquence débordant du trimestre → 422 ; `CurrentPeriod::allAt('2026-10-15')` rend [séquence, trimestre] dans cet ordre ; mode `slot` : 100 % des tests Attendance existants inchangés ; mode `day_part` : deux appels (matin, après-midi) le même jour pour une classe acceptés, un 3e `morning` → 409 ; `sync` hors ligne idempotent par `client_uuid` ; synthèse : 3 absences matin + 1 après-midi = 4 demi-journées |
| **P0-2 Assessment** | a | `src/Modules/Socle/Assessment/**`, `tests/**/Assessment/**`, `openapi.d/assessment.yaml` | T502, T503, T504, T505 (T511 si PS9 retenu) | Sans stratégie agrégeante : résultats identiques au comportement actuel (tests existants verts) ; avec une stratégie de test : trimestre = agrégat de 2 séquences fourni ; échelle par défaut = 3 codes actuels ; échelle à 4 codes paramétrée acceptée, code inconnu → 422 ; préremplissage de `conduct` sans écraser une valeur saisie (test explicite) ; variables `primary_*` présentes dans le document émis, type lu de `assessment.report_card_type` |
| **P0-3 Squelette Primary + données partagées** | b (après tour a ou en parallèle si créneau libre ; ne dépend pas du tour a) | `src/Modules/Apps/Primary/` (fichiers racine, `Support/`, `Policies/`), `database/migrations/*_roles_primary.sql`, `database/migrations/*_primary_message_templates.sql`, `tests/Support/Primary/`, `tests/Fixtures/Primary/`, `tests/Architecture/PrimaryConventionsTest.php`, `tests/Isolation/PrimaryScopeTest.php`, `openapi.d/primary-core.yaml` | T506, T507, T508, T509 | `GET /public/applications` inchangé (catalogue fait foi) ; manifeste `platform.applications.primary` découvert ; `WriteGateTest` : `trial`, `active`, `past_due` écrivent, `suspended` lit et exporte (423 en écriture), `closed` ni l'un ni l'autre ; 5 rôles créés dans une **nouvelle** migration ; `RoleMatrixTest` : 1 cas par ligne de spec.md §2 ; maître d'une autre classe → 404 en lecture, 403 en écriture ; `PrimaryConventionsTest` échoue sur un `use` de socle hors Contracts, une table `pri_*` sans `tenant_id`, une route non GET hors `WriteGate`, un littéral de seuil dans `Grades/Domain` |

# VAGUE 1 : référentiel, inscriptions, frais, documents (prérequis : P0-3)
| Agent | Dossiers exclusifs (sous `src/Modules/Apps/Primary/`) | Tâches | Critères d'acceptation |
|-------|-------------------------------------------------------|--------|------------------------|
| **P1-1 Academic** | `Academic/`, `tests/**/Primary/Academic/`, `openapi.d/primary-academic.yaml` | T520, T521, T522 | Application d'un modèle sur une année vide : niveaux, classes, matières, domaines, compétences créés ; 2e application → 0 doublon ; copie d'année N → N+1 conserve codes et ordres ; matière en double sur un niveau → 422 ; deux niveaux finaux dans une même section → 422 ; export CSV/XLSX des référentiels ; le modèle de démonstration porte `origin = example` et le libellé « Exemple » |
| **P1-2 Enrolment + Billing + Documents** | `Enrolment/`, `Billing/`, `Documents/`, tests et openapi correspondants | T523, T524, T525 | Élève inscrit 2 années de suite au même niveau → `repeater = true` ; table de passage incohérente (niveau non final sans suite) → rapport d'erreurs ; facture d'inscription + 1re tranche émise par `InvoiceIssuer` avec remise fratrie appliquée au 2e enfant ; certificat de scolarité FR et EN émis et vérifiable par QR ; transfert : document émis, inscription close |
| **P1-3 Front référentiel** | `web/src/apps/primary/academic/**`, éditions ciblées partagées | T526 | `vue-tsc`, `eslint`, `vitest`, `build` verts ; Playwright : application d'un modèle puis affichage des niveaux |

# VAGUE 2 : évaluation, compétences, conduite, appel (prérequis : P0-1, P0-2, P1-1)
| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|-------|--------------------|--------|------------------------|
| **P2-1 Grades (saisie)** | `Grades/` (sauf `Grades/Domain/Strategy/`), tests, `openapi.d/primary-grades.yaml` | T530, T531, T532, T533 | Feuille de 40 élèves × 8 compétences enregistrée en un lot ; même `client_uuid` rejoué → aucun doublon ; version périmée → 409 sans écrasement ; après verrouillage, modification directe → 409, correction approuvée par le directeur → historique à 2 événements ; conduite reportée dans `council_lines.conduct` à la création du conseil ; règles d'un niveau trouvées pour une classe sans règle propre |
| **P2-2 Stratégie de calcul** | `Grades/Domain/Strategy/`, `tests/Unit/Primary/Strategy/` | T534 | **Avant réponses** : tests avec règles `example_*` fournies par le test (ex. 2 séquences 12 et 14, poids 1 → trimestre 13,00 ; coefficients 2 et 1, notes 10 et 16 → 12,00 ; arrondi 2) ; option inconnue → `optionErrors` non vide ; aucun littéral de seuil. **Après réponses Q3/Q4/Q7/Q10** : un test par exemple chiffré de DOC-1/DOC-2 |
| **P2-3 Appel + front saisie** | `Attendance/` (Primary), `web/src/apps/primary/grades/**`, `web/src/apps/primary/attendance/**` | T535, T536 | Ouverture de l'application → `attendance.mode = day_part` proposé ; appel hors ligne d'une classe de 40 synchronisé sans perte ; feuille de compétences mobile utilisable hors ligne (Playwright, mode avion simulé) |

# VAGUE 3 : conseil, bulletins, portail, cahier (prérequis : P2-1 ; T540-T541 bloqués par Q2)
| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|-------|--------------------|--------|------------------------|
| **P3-1 Bulletins** | `Grades/Application/ReportCard*`, `Documents/Templates/`, tests E2E bulletin | T540, T541, T542 | Conseil validé de 60 élèves → 60 bulletins en file, générés en < 2 min (SC-PRI-2) ; bulletin conforme champ à champ au modèle DOC-1 (liste de contrôle signée par l'utilisateur) ; `show_rank=false` → aucun rang imprimé ; duplicata = même contenu, nouveau numéro ; correction → nouvelle version, ancienne « remplacée » à la vérification publique ; SMS NOT-PRI-02 mis en file |
| **P3-2 ClassLife + Portals** | `ClassLife/`, `Portals/`, tests, openapi | T543, T544 | Entrée publiée visible des seuls parents de la classe ; accusé de lecture unique par parent ; portail famille : compétences publiées seulement après verrouillage ; enseignant : « à remplir » liste les feuilles incomplètes |
| **P3-3 Front conseil, cahier, portail** | `web/src/apps/primary/{council,journal,portal}/**` | T545 | Front vert ; Playwright : parent voit bulletin, cahier, solde (mobile 390 px) |

# VAGUE 4 : examen, pilotage, tutelle, services, clôture (prérequis : vague 2 ; plusieurs tâches bloquées)
| Agent | Dossiers exclusifs | Tâches | Critères d'acceptation |
|-------|--------------------|--------|------------------------|
| **P4-1 Exams + Tutelage** | `Exams/`, `Tutelage/`, tests, openapi | T550, T552 | Inscription en lot des élèves du niveau d'examen ; doublon → 409 ; retrait conservé dans l'historique ; import CSV des résultats : ligne invalide rejetée avec motif, autres acceptées ; taux de réussite = admis / présents ; export tutelle aux colonnes configurées |
| **P4-2 Dashboards + clôture** | `Dashboards/`, `Enrolment/Closure*`, tests | T551, T555 | Indicateurs exacts sur jeu de fixtures (effectifs par niveau et sexe, présence du jour, taux de saisie, recouvrement) ; `primary:alerts` signale un élève à 3 demi-journées consécutives d'absence, une seule fois ; clôture refusée si un conseil n'est pas validé ; passage : admis promus en brouillon, non admis non promus, niveau final non promu |
| **P4-3 Services + sortie + front pilotage** | `Services/`, `web/src/apps/primary/{dashboard,exams,services}/**` | T553, T554, T556 | (si Q12/Q13 = oui) abonnement facturé une fois par cycle ; remise à une personne non autorisée impossible, refus notifié ; front vert |

# VAGUE 5 : recette (prérequis : vagues 1 à 4)
| Agent | Tâches | Critères d'acceptation |
|-------|--------|------------------------|
| **P5-1 Recette API et E2E** | T560, T561, T563, T564 | 100 % des routes `/primary/*` dans `ApiContract` ; 8 parcours E2E verts ; export client contient les 14 tables `pri_*` ; suite complète verte sur MySQL réel |
| **P5-2 Recette front et charge** | T562, T565 | Playwright vert ; SC-PRI-1 à SC-PRI-3 mesurés et consignés |
| **P5-3 Mise en service** | T566, T570, T571 | `primary` passe à `available` seulement après accord écrit de l'utilisateur ; rapport de recette et HANDOVER à jour |

## Ordre récapitulatif
Tour 1 : P0-1, P0-2, P0-3 → Tour 2 : P1-1, P1-2, P1-3 → Tour 3 : P2-1, P2-2 (exemples), P2-3 → Tour 4 : P3-2, P4-2 (indicateurs), P3-1 dès réponse à Q2 → Tour 5 : P4-1, P4-3, P3-3 → Tour 6 : vague 5. Les tâches bloquées s'insèrent dès que la réponse arrive, sans attendre la fin d'une vague.
