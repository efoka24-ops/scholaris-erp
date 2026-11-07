# Questions pour l'utilisateur : application École primaire

Date : 2026-10-13. Chaque question a une **valeur par défaut paramétrable proposée**, toujours marquée **« à confirmer »** : elle permet de construire sans coder de règle officielle en dur (tout passe par `/settings` ou `/calculation-rules`). Les priorités : **P0** bloque le bulletin ou le modèle de données ; **P1** bloque une fonction Must ; **P2** affine ; **P3** périmètre optionnel.

## A. Documents et informations à obtenir (à joindre dans `specs/003-application-primaire/sources/`)
| # | Document | Pourquoi | Priorité |
|---|----------|----------|----------|
| DOC-1 | Un ou plusieurs **bulletins réels** d'école primaire (francophone et anglophone si les deux), vierges ou anonymisés, pour chaque niveau s'ils diffèrent | Mise en page, rubriques, calcul visible, signatures | P0 |
| DOC-2 | **Texte officiel** (MINEDUB) sur l'évaluation au primaire : barème, échelle des compétences, calcul des moyennes, appréciations, rang | Règles de la stratégie `primary` | P0 |
| DOC-3 | **Programmes / curriculum** officiels : liste des domaines, matières (disciplines) et compétences par niveau et sous-système | Référentiel par défaut | P0 |
| DOC-4 | **Calendrier scolaire** officiel de l'année en cours (trimestres, séquences ou évaluations, congés) | Périodes par défaut | P1 |
| DOC-5 | Règles de **passage de classe et de redoublement** (seuils, conseil des maîtres, nombre de redoublements permis) | Clôture d'année | P1 |
| DOC-6 | Règlement de l'**examen de fin de cycle** (CEP, FSLC, éventuellement concours d'entrée en 6e / Form 1) : inscription, pièces, frais, résultats | Module examens | P1 |
| DOC-7 | Modèle de **règlement intérieur** / grille de conduite d'une école cible | Échelle de conduite, sanctions | P2 |
| DOC-8 | Formats de **rapports à la tutelle** (statistiques de rentrée, effectifs, résultats ; inspection d'arrondissement, délégation) | Exports `TutelleExporter` | P2 |
| DOC-9 | **Grille de frais** d'une école cible (inscription, pension par tranche, APEE, tenue, fournitures, cantine, transport, examen) | Natures de frais et échéanciers par défaut | P1 |
| DOC-10 | Autres documents émis (certificat de scolarité, attestation de fréquentation, exeat/transfert, carte scolaire, livret scolaire) | Catalogue de documents | P2 |

## B. Questions priorisées
| # | Prio | Question | Valeur par défaut proposée (paramétrable) | Statut |
|---|------|----------|-------------------------------------------|--------|
| Q1 | P0 | L'application doit-elle gérer le sous-système **francophone**, **anglophone**, ou **les deux** dans le même établissement ? Bulletins bilingues ? | Les deux, chaque section = `org_unit` de type `section` ; bulletin dans la langue de la section ; paramètre `primary.sections` = `["fr"]` | à confirmer |
| Q2 | P0 | Quel est le **modèle de bulletin** officiel (rubriques, domaines, compétences, notes, moyenne, rang, appréciation, conduite, absences, décision, signatures) ? Un bulletin par séquence, par trimestre, annuel, ou plusieurs ? | Bulletin trimestriel + récapitulatif annuel au 3e trimestre, modèle éditable (`/document-templates`) ; bulletin de séquence désactivé (`primary.report_card.per_sequence=false`) | à confirmer |
| Q3 | P0 | **Barème** de notation par niveau (sur 10, sur 20, autre ?) et **calcul** : moyenne simple ou pondérée, coefficients par matière, arrondi, épreuves de la séquence (devoir, composition, oral) et leur poids | `scale` par niveau = 20, `weighting=coefficient` avec coefficients 1 (= moyenne simple), `rounding=2`, poids des épreuves 1 ; piste de l'archive « sur 10 jusqu'au CE1 » **non appliquée** | à confirmer |
| Q4 | P0 | **Découpage de l'année** : combien de trimestres, combien de séquences (ou évaluations) par trimestre ? La moyenne trimestrielle est-elle la moyenne des séquences ? La moyenne annuelle celle des trimestres ? | 3 trimestres × 2 séquences ; trimestre = moyenne des séquences, année = moyenne des trimestres, pondérations 1 (`primary.period_weights`) | à confirmer |
| Q5 | P0 | **Liste des niveaux** et de leur ordre (et regroupement en cycles / « niveaux » I, II, III s'il existe) pour chaque sous-système | Aucune liste codée ; l'établissement saisit ses niveaux ; un jeu de démonstration étiqueté « exemple » est fourni dans les fixtures | à confirmer |
| Q6 | P0 | **Matières et domaines** par niveau ; l'évaluation est-elle **par compétence** (APC), par matière, ou les deux ? | Les deux : matières notées regroupées en domaines ; compétences optionnelles par matière (`primary.competencies.enabled=true`) | à confirmer |
| Q7 | P0 | **Échelle des compétences** et **appréciations** : niveaux (ex. Acquis / En cours / Non acquis, ou 4 niveaux), libellés FR/EN, seuils de note correspondants le cas échéant | Échelle à 3 niveaux reprenant les codes du socle (`acquired`, `in_progress`, `not_acquired`), libellés paramétrables, **aucun seuil numérique** par défaut | à confirmer |
| Q8 | P1 | Le **rang** figure-t-il au bulletin du primaire ? Ex æquo ? Qui préside le **conseil** de fin de période (conseil des maîtres) ? | Rang affiché (`primary.report_card.show_rank=true`), ex æquo au même rang ; conseil présidé par le directeur | à confirmer |
| Q9 | P1 | **Assiduité** : l'appel se fait-il par demi-journée (matin / après-midi), par jour, ou par cours ? Les absences s'expriment-elles en demi-journées ou en heures sur le bulletin ? Retards ? | Appel par demi-journée, absences en demi-journées, retards comptés ; notification au parent à l'absence | à confirmer |
| Q10 | P1 | **Passage de classe** : seuil de moyenne annuelle, décision du conseil, redoublement limité par cycle, passage automatique dans certains niveaux ? | Proposition calculée = « admis » si moyenne annuelle ≥ `passMark` (10/20 par défaut), décision finale par le conseil ; aucune limite de redoublement | à confirmer |
| Q11 | P1 | **Examen de fin de cycle** (CEP / FSLC, concours d'entrée en 6e / Form 1) : l'école inscrit-elle ses candidats dans l'application ? Saisie des résultats officiels ? Examen blanc ? | Inscription des candidats, liste exportable, saisie des résultats (admis / ajourné), examen blanc = évaluation ordinaire | à confirmer |
| Q12 | P2 | **Cantine, garderie, transport** : à gérer en V1 ? Facturation au forfait, au repas, au trajet ? | V1 : forfaits facturés (natures `canteen`, `daycare`, `transport`) ; suivi quotidien hors V1 | à confirmer |
| Q13 | P2 | **Sortie sécurisée** (remise de l'enfant à une personne autorisée, comme en maternelle) au primaire ? Jusqu'à quel niveau ? | Activable par niveau (`primary.pickup.levels`), désactivée par défaut | à confirmer |
| Q14 | P2 | **Conduite** : grille officielle ? Avertissements, blâmes, tableau d'honneur, encouragements au primaire ? | Échelle de conduite à 4 niveaux paramétrables (Très bonne, Bonne, Passable, Mauvaise, comme la maquette secondaire) ; distinctions désactivées | à confirmer |
| Q15 | P2 | **Matricule** : format imposé (par le ministère, par l'école) ? | Matricule du socle (`MAT`) inchangé ; fournisseur `primary` non enregistré | à confirmer |
| Q16 | P2 | **Rapports à la tutelle** (MINEDUB : inspection, délégation) : lesquels, quel format, quelle fréquence ? | Export d'effectifs par niveau et sexe, et de résultats par niveau, CSV/XLSX à colonnes configurables | à confirmer |
| Q17 | P2 | **Frais** : natures (inscription, pension par tranche, APEE, tenue, fournitures, examen), remise fratrie, blocage du bulletin si impayé ? | Natures `registration`, `tuition`, `other` + `pta_fee`, `uniform`, `supplies`, `exam_fee` ; remise fratrie du socle ; **aucun blocage** du bulletin (`primary.report_card.block_on_debt=false`) | à confirmer |
| Q18 | P2 | **Rôles** : qui fait quoi dans une école primaire cible (directeur, adjoint, maître, secrétaire, économe/intendant, surveillant, fondateur/promoteur) ? Un maître a-t-il une seule classe ? | Rôles proposés dans [spec.md](spec.md) §2 ; un maître titulaire par classe, intervenants possibles (anglais, EPS) | à confirmer |
| Q19 | P3 | **Cahier de textes / cahier de liaison** numérique attendu ? Photos d'activités (comme le cahier de vie maternelle) ? | Cahier de liaison texte (Should), photos désactivées | à confirmer |
| Q20 | P3 | **Portail élève** : utile au primaire (enfants jeunes) ou uniquement portail parent ? | Portail parent seul ; portail élève désactivé | à confirmer |
| Q21 | P3 | Écoles **publiques** ou **privées** (laïques, confessionnelles) visées ? Autorisation d'ouverture à contrôler à l'inscription ? | Privées ; contrôle de l'autorisation par niveau activé comme pour Higher (`EnrolmentGate`) | à confirmer |
| Q22 | P3 | **Abonnement** : prix et essai du primaire identiques à l'IPES (60 000 XAF/mois, 90 jours) ? | Valeurs du catalogue actuel | à confirmer |

## C. Ordre de demande conseillé
1. DOC-1, DOC-2, DOC-3 et Q1 à Q7 (une seule séance) : débloquent le bulletin, la stratégie de calcul et le référentiel.
2. DOC-4, DOC-5, DOC-6, DOC-9 et Q8 à Q11, Q17, Q18 : débloquent clôture d'année, examens, frais, rôles.
3. Le reste au fil de l'eau ; les valeurs par défaut suffisent pour construire.
