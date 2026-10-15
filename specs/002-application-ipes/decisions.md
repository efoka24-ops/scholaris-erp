# Décisions IPES : réponses du CDC, questions restantes, écarts et contradictions

Date : 2026-10-10. Sources : [cdc-ipes.md](cdc-ipes.md) (extrait fidèle, **tronqué après NOT-11** : NOT-12 et suivantes, conception technique, API spécifiques, sécurité propre, cycles de vie, tests d'acceptation TST, feuille de route, risques et questions ouvertes du CDC ne sont pas reçus), [socle-reuse-A.md](socle-reuse-A.md), [socle-reuse-B.md](socle-reuse-B.md), maquette `Releve.html` (lecture seule), décisions de l'utilisateur ([platform-applications.md](../001-socle-plateforme/platform-applications.md)).

Légende : **CDC** = réponse tirée du texte reçu ; **U** = décision de l'utilisateur ; **déduit** = lecture raisonnable du CDC, à confirmer si l'enjeu est fort ; **à confirmer** = absent du CDC, valeur par défaut paramétrable proposée (jamais codée en dur : clé `higher.*` lue par `SettingsReader`).

## Décisions de l'utilisateur (priment sur le CDC)
- U1 Un client (tenant) = une institution IPES ; plusieurs IPES = plusieurs clients ; une institution a plusieurs écoles internes (`org_unit` de type `school`). Mapping : IPES = un établissement `higher` ; école = `org_unit` `school` ; département = `org_unit` `department` (parent = école) ; filière = `pedagogical_structures.kind = 'track'` rattachée à l'`org_unit` du département.
- U2 Implémentation : PHP 8.1 sans framework (ADR-0001), `src/Modules/Apps/Higher`, PHPUnit, XLSX via le `XlsxWriter` maison (écriture seule), rôles via le socle. Le CDC écrit « PHP 8.4, Laravel 13, app/Modules » : écart volontaire.
- U3 2FA forcée seulement pour l'administrateur de plateforme ; pour les rôles IPES, la 2FA récente (`RecentSecondFactor`) n'est exigée que pour les opérations sensibles listées dans le CDC (verrouillage, signature, publication, réouverture, export liste nationale, modification de règlement figé).
- U4 Changements du socle D1 à D21 et S1 à S21 autorisés, y compris la migration partagée des rôles.

## (a) Réponses du CDC aux questions ouvertes des analyses A et B

### Module A
| Q | Question | Réponse | Référence |
|---|----------|---------|-----------|
| Q1 | Groupe d'écoles = tenant ? | Non : un client = une IPES, plusieurs écoles internes | U1, RG-ORG-2/3 |
| Q2 | Autorisation MINESUP par niveau ou par filière ? | Par filière **et niveaux autorisés** ; la garde teste (filière, niveau, année) | EX-ACA-03, RG-ACA-1 |
| Q3 | Directeur académique = directeur d'école ? | Non : rôle distinct **R03** ; valide la modification du règlement figé et rouvre une délibération signée | R03, RG-ACA-3, EX-DEL-08 |
| Q4 | Plusieurs tutelles actives par filière ? | Une seule université de tutelle par filière LMD **et par année académique** ; un changement ne modifie pas les années passées | RG-ORG-8, EX-ACA-04 (déduit pour l'unicité) |
| Q6 | Concours en V1 ? | Should : lot I1 mais après les Must (EX-ADM-05) | EX-ADM-05 |
| Q9 | Matricule : quelle année, stable ? | Format défaut `AA + code école + code filière + n° d'ordre sur 4 chiffres` (ex. `26EC-CF-0042`), unique, **définitif, jamais réattribué** donc inchangé par un changement de filière ; l'année d'entrée est retenue par défaut (déduit : « AA » de la première inscription) | RG-INS-1 |
| Q10 | Limite d'inscriptions consécutives | Défaut **2** dans un même niveau, dérogation du directeur d'école (voir contradiction C6) | RG-INS-5 |
| Q12 | « Sous réserve financière » = `conditional` ? | Non : statut distinct d'EX-INS-04 posé par le directeur d'école (RG-INS-2) ; on le porte par l'indicateur `financial_hold`, distinct du `conditional` du socle (pièces) | EX-INS-04, RG-INS-2 |
| Q17 | Import Excel en V1 ? | Should (EX-MAQ-05) : import/export + copie d'année ; voir restante R12 pour le format d'entrée | EX-MAQ-05 |
| Q20 | Génération automatique d'emploi du temps | Absente du CDC reçu (EX-EDT-01 à 06 ne la citent pas) : exclue | EX-EDT |
| Q23 | Qui contre-signe ? | Le délégué ou le surveillant ; mode d'authentification restant (R9) | EX-ENS-05 |
| Q25 | Enseignant multi-établissements | Dossier avec « établissement d'origine » et autorisation de l'employeur (fonction publique) ; client = une IPES, donc pas de cumul inter-client | EX-ENS-01, U1 |
| Q27 | Non autorisé à composer : et au rattrapage ? | Note 0 à l'examen ; **rattrapage possible si le règlement l'autorise** (paramètre) | RG-PRE-1 |
| Q1-Q29 divers | Valeurs 30 %, 25 %, 30 jours, 90/30/7 jours | 30 % (RG-PRE-1), alerte 25 % (NOT-07), « admis sous réserve » 30 jours (RG-ADM-2), alertes d'expiration 90/30/7 (EX-ACA-07) | CDC |

### Module B
| Question | Réponse | Référence |
|----------|---------|-----------|
| Pondération CC/examen : 30/70 (maquette), 40/60 (CDC) ou tutelle ? | **40/60 = F1**, par filière, modifiable par EC ; remplaçable par le règlement de la tutelle. La maquette (30/70) est un exemple (voir M1, M2) | « Règles de calcul », F1 |
| Format du numéro de document | `REL-EC-2026-000315` : code document, code école, année, ordre sur 6 chiffres, unique, non réutilisable. `IPES-26-R-001204` (maquette) est écarté | RG-DOC-1 |
| Éliminatoire : par EC ou par UE ? | **Par UE** : « aucune UE sous 7 » (défaut), 8 dans le scénario B ; distinct du seuil de compensation de semestre (moyenne de semestre ≥ 10) | « Règles de calcul » |
| Sens de NA, FR, ABJ, ABI | NA = non autorisé à composer (0) ; FR = fraude (0 + saisine du conseil de discipline) ; ABJ = épreuve de remplacement ou rattrapage ; ABI = 0 | RG-NOT-5, EX-NOT-03 |
| Points de jury : par UE, semestre, année ? | **Sur la moyenne de semestre**, plafond défaut 0,5, motivés, listés nominativement dans le PV | RG-DEL-2 |
| Rattrapage : remplace ou s'ajoute ? Plafond ? | La note de rattrapage **remplace l'examen**, le CC est conservé ; plafond après rattrapage : aucun (option : moyenne d'UE plafonnée à 10) | « Règles de calcul » ; exemple scénario B |
| Qui peut accorder des points de jury, quorum | Le jury (session : membres, quorum, décisions motivées) ; quorum en paramètre du socle `assessment.quorum_percent` | EX-DEL-03 |
| Double validation des corrections | Chef de département puis directeur d'école, sur demande motivée avec pièce ; correction = nouvelle valeur liée, jamais d'écrasement | EX-NOT-06, RG-NOT-2 |
| Verrouillage : cycle | brouillon, soumise, validée (chef de département), verrouillée, publiée | EX-NOT-05 |
| Blocages financiers : quoi ? | Au choix : consultation des notes, délivrance des documents (sauf certificat de scolarité), réinscription ; jamais cours ni composition. RG-DOC-7 ajoute reçu, relevé de compte, attestation de paiement (voir C8) | RG-FIN-5, RG-DOC-7 |
| Relances | J-7, J+1, J+15 (paramètres) | RG-FIN-3, NOT-08/09 |
| Affectation d'un paiement | Frais d'inscription, puis tranches échues (plus ancienne d'abord), puis à venir | RG-FIN-2 |
| Remises | Sur la pension seulement (sauf paramètre), réparties sur les tranches restantes | RG-FIN-1 |
| Frais de dossier remboursables ? | Non, sauf annulation de campagne ; abandon avant une date paramétrée : grille de l'école | RG-ADM-4, RG-INS-6 |
| Quitus financier | Seulement si solde nul (soutenance, attestation de réussite, transfert) | RG-FIN-6 |
| Candidats à l'examen national | Seuls les inscrits de l'année de sortie dans la spécialité autorisée ; aucun externe ; modification après export tracée et signalée au DG ; jamais de document intitulé « BTS » ou « HND » | RG-EXN-1/2/3 |
| Frais d'examen national | Collectés dans le délai du calendrier MINESUP, sinon pas sur la liste | RG-FIN-4 |
| Soutenance programmable si... ; jury | Convention signée, attestation de stage, mémoire déposé, quitus ; jury d'au moins 3 membres (paramètre) dont l'encadreur, qui ne préside pas | RG-STG-1/2 |
| Dossier de diplôme à la tutelle | Seulement après PV de délibération finale signé et quitus ; DOC-11 mentionne la tutelle | RG-TUT-1/2 |
| Accès tutelle R22 | « Si accès ouvert » : Could (EX-TUT-04), lecture limitée à ses filières avec journal | EX-TUT-04, R22 |
| Page de vérification | type, nom de l'étudiant, date d'émission, statut (valide, annulé, remplacé), **sans les notes** | RG-DOC-2 |
| Duplicata, annulation, version de modèle, langue | « Duplicata » + date, numéro d'origine conservé ; annulé jamais supprimé, motif affiché ; réimpression avec la version d'émission ; FR et EN, défaut = langue d'enseignement de la filière | RG-DOC-4/5/6/8 |
| Émission des relevés | Seulement après signature du PV | RG-DOC-3 |
| Signataire par document | Colonne « signataire » du tableau DOC-01 à DOC-32 (directeur d'école, DG, président du jury, comptable, etc.) | Tableau des documents |
| Les 23 rôles, R01 | R01 = **Promoteur/fondateur** (et non administrateur de plateforme : hypothèse de B corrigée) ; R03 = Directeur académique ; liste complète R01-R23 | Rôles |
| Cumul de rôles | Une affectation = rôle + périmètre ; plusieurs affectations par personne | RG-ORG-2 |
| Sélecteur école | « Toutes les écoles » pour qui a plusieurs écoles, absent s'il n'y en a qu'une ; logo, couleurs, signataires suivent l'école | RG-ORG-3/4 |
| Surcharge par école | Hérite de l'établissement, surcharge visible et tracée | RG-ORG-5 |
| Double cursus | Un compte, un matricule, deux parcours | RG-ORG-6 |
| Régime d'une filière | Un seul ; changer de régime = nouvelle filière | RG-ORG-7 |
| Fréquence des tableaux de bord | Quotidien (campagne, recouvrement), hebdomadaire (assiduité), mensuel (vacations), annuel (BTS/HND, MINESUP) ; destinataires nommés | POR et PIL |
| Dépendance Excel | Pas de bibliothèque externe : `XlsxWriter` du socle (écriture) ; export `?format=xlsx` déjà disponible sur toutes les listes | U2 |
| Notifications NOT-01 à NOT-11 | Texte reçu ; NOT-12 et suivantes **non reçues** : ne pas inventer (publication, relance J-7... à lire de nouveau dans le CDC complet) | cdc-ipes.md |
| Calendrier académique | Valeurs 2026-2027 par école et par année | Calendrier type |
| Exemples chiffrés | Marie N. (A et B), échéancier 410 000, vacations 261 000 : transcrits dans [wave-plan.md](wave-plan.md) comme tests (vérifiés arithmétiquement : 346,60 ; 11,55 ; 2,40 ; 12,09) | CDC |

## (b) Questions RESTANTES (absentes du CDC reçu), valeur par défaut paramétrable « à confirmer »

Priorité : P1 bloque une vague, P2 bloque un module, P3 confort. Chaque défaut est une clé `higher.*` (HigherDefaultsSeeder) ou un réglage du socle.

| # | Pr | Question | Défaut proposé (à confirmer) | Clé / lieu |
|---|----|----------|------------------------------|------------|
| R1 | P1 | Formats des listes MINESUP BTS et HND (colonnes, encodage, gabarit PDF) | Modèle d'export configurable ; défaut CSV `;` UTF-8 et XLSX via `XlsxWriter` avec colonnes : matricule, nom, prénoms, naissance, sexe, spécialité, année | `higher.minesup.export.{bts,hnd}.columns` |
| R2 | P1 | Formats des listes d'inscrits par université de tutelle | Même mécanisme, un modèle par tutelle | `higher.tutelage.export.<tutelle>` |
| R3 | P2 | Canal de transmission à la tutelle | Fichier exporté téléchargé et journalisé (aucun envoi automatique) | `higher.tutelage.channel=file` |
| R4 | P2 | Accès direct de la tutelle (R22) en V1 | Non : hors V1 (Could) ; rôle déclaré mais sans route active | `higher.tutelage.portal_enabled=false` |
| R5 | P1 | Type de signature électronique des PV | Signature simple : confirmation par 2FA récente + empreinte SHA-256 + cachet du socle ; qualifiée hors périmètre | `higher.signature.level=simple` |
| R6 | P2 | Retenues sur vacations (IRPP, CAC, autres) | Aucune retenue ; montant brut seul (261 000) ; retenues = liste paramétrable du comptable | `higher.vacation.deductions=[]` |
| R7 | P1 | Taux horaire par grade et niveau (L, M, BTS, HND) | Table vide à remplir par l'institut ; défaut fictif 6 000 FCFA/HETD (exemple du CDC) pour tests seulement | `higher.vacation.rates[grade][level]` |
| R8 | P2 | Plafond des bourses et remises cumulées | 50 % de la pension, validation du comptable au-delà de 30 % | `billing.discount_cap_percent=50` |
| R9 | P2 | Authentification de la contre-signature (délégué, surveillant) | Code de séance (QR de séance du socle) saisi par le contre-signataire identifié | `higher.session.countersign=qr` |
| R10 | P1 | Base du seuil de 30 % : séances prévues ou tenues ? | Séances **tenues** (appel validé, annulées/reportées exclues) ; alerte à 25 %, interdiction à 30 % ; réévalué à la clôture du semestre sur le total prévu | `attendance.base=held` (D20) |
| R11 | P2 | Délai de dépôt d'un justificatif | 7 jours après l'absence (aligné sur la requête de note) | `attendance.justification_days=7` |
| R12 | P2 | Import Excel (lecture) : `XlsxWriter` n'écrit que | V1 : import CSV ; import XLSX seulement si une lecture est ajoutée au socle (S14) ; à confirmer | `higher.import.formats=[csv]` |
| R13 | P2 | Tolérance sur les crédits d'une maquette | Exactement 30 par semestre en LMD, entiers ; autres régimes sans contrôle de crédits | `higher.curriculum.credits_per_semester=30` , `tolerance=0` |
| R14 | P2 | UE optionnelles : règle « N parmi M » | Un groupe d'options (`choice_group`) avec minimum et maximum d'UE à choisir ; l'étudiant choisit à l'inscription pédagogique | modèle `hi_units.choice_group` |
| R15 | P3 | Heures par crédit | 1 crédit = 25 heures de travail total ; contrôle d'avertissement seulement | `higher.curriculum.hours_per_credit=25` |
| R16 | P2 | Concours, transferts (équivalences), import Excel en V1 | Concours : Should, après les Must d'I1 ; transferts : Should, équivalences saisies par commission sans algorithme ; import CSV | tâches T234, T240 |
| R17 | P2 | Compte du candidat avant admission | Compte créé à la soumission (e-mail ou téléphone vérifié), limitation de débit sur le formulaire public | ADM |
| R18 | P3 | Rétention des données des candidats refusés | 12 mois puis anonymisation via le module de confidentialité | `higher.admission.retention_months=12` |
| R19 | P3 | Barème de concours | Fourni par l'école (coefficients par épreuve), défaut une note unique /20 | `hi_contests` |
| R20 | P3 | Jours fériés du Cameroun, plages jour/soir/alternance | Calendrier fourni par l'école via `/calendar-events` ; plages en paramètres | S07 |
| R21 | P2 | Présences en alternance (entreprise) | Non comptées dans l'appel ; période en entreprise traitée comme stage | PRE |
| R22 | P3 | Plafonds légaux d'heures (par jour/mois) | Aucun plafond ; le plafond mensuel de catégorie du socle s'applique | S10 |
| R23 | P2 | Barème et grille de lettres définitifs, règlement de chaque tutelle | Grille du CDC (A+ 4,00 à F 0,00) en défaut, remplaçable par programme et année | `calculation_rules` par programme (S5) |
| R24 | P2 | Taux de bourse mérite/personnel/fratrie/partenariat | Mérite 20 % (exemple CDC) ; autres taux vides ; fratrie : pas de défaut (le socle a 25 000 FCFA fixes pour le secondaire, à ne pas reprendre) | `higher.scholarship.rates` |
| R25 | P3 | Part reversée à la tutelle (EX-TUT-05) | Désactivé en V1 (Should) | `higher.tutelage.fee_share` |
| R26 | P2 | Pondération mémoire / soutenance / tuteur | Soutenance seule, saisie par le jury ; autres pondérations en paramètre | `higher.defense.weights` |
| R27 | P3 | Contrôle de similarité des mémoires | Hors V1 (option Should, service externe) | STG |
| R28 | P2 | « Année de sortie » d'une filière en alternance | Dernier niveau du programme | EXN |
| R29 | P2 | Un seul rattrapage par EC ? | Un seul par EC et par année | `higher.resit.max_per_unit=1` |
| R30 | P2 | Identifiant de connexion de l'étudiant | Matricule ou e-mail ; téléphone si vérifié | Identity |
| R31 | P3 | Durée des comptes R22 et R23 | 90 jours renouvelables (`role_assignments.ends_at`) | Identity |
| R32 | P2 | Partage des notes avec le garant d'un étudiant mineur | Le garant voit toujours (RG-INS-7 ne fixe le choix que pour le majeur) | People |
| R33 | P2 | Sensibilité 2FA des rôles IPES | `is_sensitive` pour R02, R03, R04, R15 ; opérations sensibles du CDC exigent la 2FA récente | migration des rôles |
| R34 | P3 | Définitions : taux de réussite, recouvrement, fraîcheur | Réussite = admis ÷ inscrits évalués en session normale ; recouvrement = encaissé ÷ facturé net ; instantané nocturne | PIL |
| R35 | P1 | Textes officiels : modèles de la tutelle et du MINESUP (relevé, PV, attestations, convention) | Modèles de travail fidèles à la maquette, en-têtes à champs `[...]` à renseigner ; aucun texte légal inventé | DOC |
| R36 | P2 | Génération de masse : 1 000 relevés en 10 minutes (NF-04 du socle, hors CDC reçu) | File par lots de 50 du socle, à mesurer | DOC |
| R37 | P1 | NOT-12 et suivantes, API spécifiques, TST-xx, risques du CDC | Fournir la fin du CDC ; en attendant, ne coder que NOT-01 à NOT-11 et les TST dérivés des exemples reçus | cdc-ipes.md |
| R38 | P2 | Changement de tutelle en cours d'année : nouvelles inscriptions seulement | Appliqué aux nouvelles inscriptions de l'année suivante | RG-ORG-8 |

## (c) Écarts entre le CDC et les analyses
- E1 **Assiduité 70 % (examen national BTS/HND) contre 30 % d'absences (composition)**. Pas la même grandeur. 70 % (CDC « assiduité ≥ 70 % ») est un taux de présence sur la formation, sans précision de base ; 30 % (RG-PRE-1) est un taux d'absences **non justifiées par EC**. Équivalence seulement si l'on convient que « présence = 100 % − absences non justifiées » et que la base est la même : par EC pour l'un, globale pour l'autre. Décision : deux indicateurs distincts (T426), calculés par `EcAssiduityCalculator` ; défaut du 70 % : (séances tenues − absences non justifiées) ÷ séances tenues sur l'année, à confirmer (R10).
- E2 Analyse B posait l'éliminatoire « par EC » ; le CDC la définit **par UE** (aucune UE sous 7). Les analyses A/B décrivaient un plancher d'EC pour Marie N. : inexact.
- E3 Analyse B suppose R01 = administrateur de plateforme ; le CDC : R01 = promoteur/fondateur. L'administrateur de plateforme est un rôle du module Platform, hors R01-R23.
- E4 Les analyses demandaient « PhpSpreadsheet/maatwebsite » ; décision : `XlsxWriter` maison, pas de nouvelle dépendance.
- E5 Analyse A : « directeur académique = directeur d'école ? » ; le CDC : R03 distinct.
- E6 Analyse B : « seuils de passage 60/45 » absents de spec.md ; le CDC les donne (60 crédits admis, 45 à 59 dettes, moins de 45 redouble).
- E7 Analyse B : « format du reçu » `R-IPES-26-04821` (socle) contre RG-DOC-1 : DOC-27 est qualifié « automatique, socle » dans le tableau ; défaut : le reçu garde le numéro du socle, à confirmer (voir C8).
- E8 Plan 002 : « 15 notifications » (NOT-01 à NOT-15) ; le CDC reçu s'arrête à NOT-11, EX-ACA-07 (alertes d'expiration) est donc supposé NOT-15 par les analyses sans confirmation.
- E9 Le socle `YearPromotion` ne connaît que admis/non admis ; le CDC impose trois issues plus exclusion (D8, S6).
- E10 Le socle accepte 11 paramètres de règles ; les formules F1 à F7, MGP, lettres, éliminatoire par UE, rattrapage remplaçant l'examen demandent une stratégie de calcul enfichable (S5, S6, S7).

## (d) Contradictions internes du CDC et de la maquette Relevé à signaler
CDC :
- C1 Matrice : R04 a « L » sur Notes alors que EX-NOT-06 exige sa validation des corrections (V attendu). R03 est absent des colonnes de la matrice alors qu'il valide règlement et réouverture. R05 « É » sur Délibérations contre « V(président) » pour R04 sans définir qui préside (RG : le directeur d'école préside les jurys, R04).
- C2 Écriture contre rôle : R01 « aucune saisie de notes » mais la matrice lui donne L sur presque tout : cohérent ; en revanche aucune colonne pour R08 (examens), R11, R16-R20 : la matrice est partielle.
- C3 Note « au quart de point » (RG-NOT-1) contre résultats F1 à deux décimales (10,20) : cohérent seulement si le quart s'applique aux **saisies** (CC, examen) et non aux moyennes. À fixer : arrondi au centième à chaque étape.
- C4 F3 divise par 30 (constante) alors que F2 divise par Σ crédits : différent si le semestre n'a pas 30 crédits (BTS/HND, Master). Défaut : F3 = Σ(note EC × crédits EC) ÷ crédits du semestre (30 en LMD).
- C5 Scénario A : « compensation entre UE si moyenne de semestre ≥ 10 » ; scénario B : « éliminatoire 8 : pas de compensation » : l'éliminatoire est un paramètre, pas une règle ; OK, mais l'exemple B parle d'« UE acquise par compensation après rattrapage » alors que l'UE2 vaut 9,80 ≥ 8 : cohérent (≥ éliminatoire et semestre ≥ 10).
- C6 RG-INS-5 « inscriptions consécutives dans un même niveau : défaut 2 » contre « 2e redoublement du même niveau → exclu » : deux inscriptions = un seul redoublement ; un 2e redoublement suppose trois inscriptions. À trancher : défaut retenu = **2 inscriptions autorisées** (RG-INS-5), la 3e est une dérogation ou l'exclusion pour insuffisance.
- C7 RG-DEL-1 (ouverture si toutes les notes sont verrouillées) contre « délibération provisoire en janvier » du calendrier : une délibération provisoire n'est possible que sur notes verrouillées ; PV et relevé restent conditionnés à la signature (RG-DOC-3).
- C8 RG-FIN-5 n'excepte du blocage que le certificat de scolarité ; RG-DOC-7 excepte aussi reçu, relevé de compte, attestation de paiement. Retenu : l'union des deux listes (RG-DOC-7 plus large).
- C9 DOC-27 « reçu (automatique, socle) » avec QR, alors que RG-DOC-1 impose un numéro `code document-code école-année-ordre`, que le socle ne fournit pas pour ses reçus (`R-<CODE>-<AA>-<n>`).
- C10 « BTS/HND » : l'application ne délivre jamais de document intitulé BTS/HND (RG-EXN-3) mais DOC-12 « Attestation de fin de formation BTS/HND » porte ces sigles dans son titre dans le tableau : le titre du modèle doit être « Attestation de fin de formation » avec la spécialité en variable (garde T293 à tester).
- C11 Calendrier : examens S1 en janvier, délibération provisoire ; relevés « après signature du PV » : un relevé provisoire n'existe pas. Les inscriptions N+1 (sept-oct) exigent la décision de l'année N (RG-INS-3) alors que la délibération annuelle est en juillet : cohérent, mais le rattrapage de juin doit être clos avant.
- C12 EX-ENS-06 : validation mensuelle par le chef de département, DOC-25 signé « chef de département, directeur d'école » ; matrice : R04 « V(école) » pour les vacations, R02 « V » : trois niveaux de validation non ordonnés. Défaut : chef de département (heures) puis directeur d'école (état), R02 en lecture et validation exceptionnelle.
- C13 Mention « Passable [10;12[ » à 11,55 (scénario A) cohérente ; mais mention « Très bien ≥ 16 » et lettre A+ ≥ 16 : bornes alignées. Pas de mention sous 10 : pas de mention si non admis.
- C14 Le plan 002 annonce « 22 exigences fonctionnelles, 19 routes spécifiques, 15 notifications », non vérifiables avec le CDC tronqué.

Maquette `Releve.html` (comparée au CDC) :
- M1 Pondération **CC 30 % / examen 70 %** contre 40/60 (F1) du CDC.
- M2 Barème de lettres A (16-20), B (13-15,99), C (10-12,99), D (8-9,99), E (< 8) avec sous-lettres (A-, B+, B-, C+) contre la grille du CDC (A+, A, B+, B, B-, C+, C, C-, D+, D, F) ; ex. 12,00 est « C+ » dans la maquette, « B- » selon le CDC ; 15,00 « A- » contre « A ».
- M3 Numéro `IPES-26-R-001204` contre `REL-EC-2026-000315` (RG-DOC-1) ; matricule `IPES-25-0712` contre `25EC-CF-0712` (RG-INS-1).
- M4 La maquette n'a ni colonne « points » ni MGP ni « résultat : acquise par compensation », ni école interne ni n° d'autorisation de la filière (placeholder `[N° D'ARRÊTÉ]` pour l'établissement), ni crédits possibles par UE, ni la mention de langue FR/EN : le corps du DOC-09 du CDC est plus riche.
- M5 Pied : « Seul l'original numérique vérifié fait foi » contre « Seul l'original signé ou vérifié en ligne fait foi » (CDC).
- M6 Arithmétique non conforme au CDC : l'UE « Fiscalité » vaut (11,65 × 3 + 12,30 × 3) ÷ 6 = 11,98 (maquette : 12,00) ; « Comptabilité approfondie » 14,45 (maquette 14,50) ; « Droit des sociétés » 0,3 × 14 + 0,7 × 12,5 = 12,95 (maquette 13,00) ; moyenne pondérée 13,28 (maquette 13,30). Valeurs de démonstration, non utilisables comme jeu de test.
- M7 Notes d'examen « 11,3 » et « 11,5 » ne sont pas au quart de point (RG-NOT-1).
- M8 La maquette porte « Admise » (accord de genre) et un parcours « Licence 2 » semestre 3 ; le CDC ne définit pas le genre dans les décisions (variable `gender` à prévoir).
- M9 Crédits cumulés « 90 / 180 » : le cumul n'apparaît pas dans le corps du relevé du CDC (synthèse : moyenne de semestre, MGP, crédits acquis/possibles, décision, mention).
