# 07. Délibérations et documents officiels

Ce chapitre décrit la délibération d'un semestre (jury, simulation, séance, procès-verbal, publication), le rattrapage, les décisions de fin d'année, les relevés, puis tous les documents officiels (DOC-01 à DOC-32) et leur vérification par QR.

**Où** : **IPES > Délibérations** (`/superieur/deliberation/jurys`). Onglets : **Jurys**, **Décisions annuelles**, **Relevés**, **Mes jurys**. Et **IPES > Documents officiels** (`/superieur/documents`).

**Qui fait quoi**

| Tâche | Qui |
|---|---|
| Constituer le jury, relever les présents, lancer la simulation, liste du rattrapage, décisions annuelles, relevés | Chef de département (R05), Scolarité (R07) |
| Présider : ouvrir et clôturer la séance, accorder les points de jury | Directeur d'école (R04), président du jury ; R05 et R07 peuvent aussi conduire la séance |
| Signer le procès-verbal | Membres du jury présents ayant le rôle **Membre de jury** (R21), président compris |
| Publier les résultats | Scolarité (R07) |
| Rouvrir une délibération signée | Directeur académique (R03) |
| Consulter | Direction (R01, R02, R03) |

**Important** : la signature du PV exige le rôle **Membre de jury** (R21). Le directeur d'école qui préside doit donc **aussi** recevoir ce rôle pour la session (chapitre 11), sinon le bouton **Signer le procès-verbal** ne s'affiche pas pour lui.

## 7.1 Les étapes d'une délibération

```
Constitué → Simulé → En séance → Clôturé → PV signé → Publié
                                           (Rouvert si réouverture motivée)
```

Conditions à connaître :
- **RG-DEL-1** : la séance ne s'ouvre que si **toutes les notes** des EC concernés sont **verrouillées** (chapitre 06).
- **RG-DEL-2** : points de jury plafonnés (par défaut 0,5 point sur la moyenne de semestre), motivés, listés nominativement dans le PV.
- **RG-DEL-3** : **aucune publication avant la signature du PV par le président.**
- **RG-DEL-4** : après signature, notes et décisions sont figées ; seule une réouverture motivée par le directeur académique permet de les changer.

## 7.2 Constituer le jury

**Où** : **Délibérations > Jurys** (écran **Jurys de délibération**). **Qui** : R05, R07.

1. Formulaire **Constituer un jury** : **Groupe**, **Année académique**, **Semestre**, **Session** (**Session normale** ou **Session de rattrapage**).
2. **Président du jury** ; **Membres (hors président)** : « Identifiants des personnes, séparés par des virgules. Le jury compte au moins deux membres, président compris. »
3. **Quorum (membres présents)** et **Ordre du jour**.
4. Cliquez sur **Constituer le jury**. État **Constitué**.

Cliquez sur **Ouvrir** pour afficher la fiche du jury. Elle contient la **Composition**, la **Présence des membres**, les **Travaux du jury** (**Préparation et simulation**, **Rattrapage**, **Documents du jury**, **Séance de délibération**) et la zone **Séance** (signature et publication).

## 7.3 Relever les présents

Le jour du jury, dans **Présence des membres** :
1. Cochez **Présent** pour chaque membre présent.
2. Cliquez sur **Enregistrer les présents**.
3. Lisez la ligne « 4 présent(s) sur 5 · quorum 3 » : **Quorum atteint**, ou « Quorum non atteint : la séance ne peut pas délibérer. »

Seuls les membres présents signeront le PV.

## 7.4 Préparation et simulation

**Où** : bouton **Préparation et simulation** (écran **Préparation de la délibération**).

« Simulez les résultats sans rien écrire ni publier, repérez les cas limites, puis accordez les points de jury. »

1. Cliquez sur **Lancer la simulation** (R05, R07). Le tableau affiche pour chaque étudiant : **Moyenne**, **MGP**, **Crédits**, **Mention**, **Proposition** (décision proposée), **Rang**, **Points de jury**, **Cas limite**.
2. Le résumé indique « 35 étudiant(s) · 4 cas limite(s) ». Un cas limite est un étudiant « à 0,3 point du seuil 10 ». Cochez **Cas limites seulement** pour les voir.
3. Si des notes ne sont pas verrouillées : « Des notes ne sont pas encore verrouillées : la simulation reste indicative. »
4. **Essayer des options de règles** (facultatif) : marge des cas limites, note de validation d'une UE, plafond des points de jury, note éliminatoire d'UE. « Ces options ne servent qu'à la simulation : rien n'est publié ni enregistré. »
5. **Points de jury** : sur un étudiant, **Accorder des points**, saisissez les **Points** et le **Motif** (obligatoire : « Les points de jury sont motivés : indiquez le motif. »). 0 retire les points. Si le plafond est dépassé : « Plafond atteint : 0,5 point(s) accordé(s) sur 1 demandé(s). »

La ligne « Règles LMD, version 2 » rappelle le règlement appliqué (chapitre 02).

### Comment le calcul est fait (LMD, valeurs par défaut)

- Note d'EC = 40 % contrôle continu + 60 % examen.
- Moyenne d'UE = moyenne des EC pondérée par leurs crédits ; UE **acquise** si moyenne ≥ 10.
- **Compensation** : une UE sous 10 est acquise si la moyenne du semestre est ≥ 10, sauf note éliminatoire (aucune UE sous 7 par défaut).
- Une UE acquise est **capitalisée** (définitivement acquise avec ses crédits).
- Mentions : Passable de 10 à moins de 12, Assez bien de 12 à moins de 14, Bien de 14 à moins de 16, Très bien à partir de 16.
- **MGP** (moyenne générale pondérée en points) : chaque UE reçoit une lettre et des points (exemple : 10 à moins de 11 = C = 2,00) selon la grille de la tutelle.

BTS et HND : note de matière = 40 % moyenne des devoirs + 60 % examen ; moyenne semestrielle par coefficients ; passage en 2e année si moyenne annuelle ≥ 10 ; rang dans le groupe (ex æquo au même rang).

## 7.5 La séance de délibération

**Où** : bouton **Séance de délibération** de la fiche du jury (`…/seance`). **Qui** : président et préparateurs (R04, R05, R07).

1. **Ouvrir la séance** : « La séance reprend la composition du jury et les présents enregistrés ; toutes les notes doivent être verrouillées (RG-DEL-1). » État **En séance** ; « Présents : 4 sur 5 ».
2. Le jury examine les **Décisions** (colonnes **Étudiant**, **Proposition**, **Décision**) et les cas particuliers.
3. **Clôturer la séance** : « La clôture fige les décisions et établit le procès-verbal (empreinte SHA-256) ; une double authentification récente est exigée. » Message : « Séance clôturée : le procès-verbal peut être signé depuis la fiche du jury. »

## 7.6 Signer le procès-verbal

**Qui** : chaque membre présent ayant le rôle Membre de jury (R21), président compris. **Où** : fiche du jury, zone **Séance**, ou portail **Mes jurys** (chapitre 10).

1. Ouvrez le jury. Si le PV n'existe pas encore : « Le procès-verbal s'établit à la clôture de la séance : rien à signer pour l'instant. »
2. Vérifiez l'**Empreinte du PV** affichée (début du code et version).
3. Cliquez sur **Signer le procès-verbal**. « La signature exige une double authentification récente. Seuls les membres présents signent. »
4. Message « Votre signature est enregistrée. » La composition affiche **A signé** ou **Non signé** pour chacun.

L'empreinte garantit que le PV n'a pas été modifié après les signatures : toute différence ultérieure est signalée.

## 7.7 Publier les résultats

**Qui** : Scolarité (R07). **Où** : fiche du jury, bouton **Publier les résultats**.

« La publication exige le PV signé par le président et une double authentification récente. Les étudiants sont prévenus. »

1. Vérifiez que le président a signé.
2. Cliquez sur **Publier les résultats**, confirmez votre code si demandé.
3. Message « Résultats publiés. » Les étudiants voient leurs résultats dans **Mes résultats** et **Mes notes** (chapitre 10), sauf blocage financier actif sur la consultation des notes.

Si le PV n'est pas signé, la publication est refusée (« Le procès-verbal n'est pas encore signé (RG-DEL-3). »).

## 7.8 Documents du jury

**Où** : bouton **Documents du jury**. « Grille de délibération, procès-verbal et liste des résultats, établis d'après la simulation et les décisions. »

- **Grille de délibération (DOC-14)** : document de travail pour la séance.
- **Procès-verbal (DOC-15)** : le PV officiel.
- **Liste des résultats (DOC-16)** : à afficher ; seulement après la signature du PV.

Cliquez sur **Émettre** à côté du document. Message « Document … émis. » et « Code de vérification : … ». « Lancez d'abord la simulation : ses lignes alimentent les documents. »

## 7.9 Rattrapage

**Où** : bouton **Rattrapage** (fiche d'un jury de **session normale**). « Étudiants convoqués par élément constitutif (EC) non validé d'une UE non acquise. »

1. La liste est proposée par EC : « EC MAT101 · UE UE12 », avec la **Note** de chaque étudiant.
2. Ajustez si besoin, puis **Enregistrer la liste**, ou **Enregistrer et convoquer (SMS et e-mail)** (DOC-13). Message : « Liste enregistrée · 12 convocation(s) envoyée(s). »
3. Après les épreuves (notes de nature **Rattrapage** saisies et verrouillées, chapitre 06), constituez un jury en **Session de rattrapage** et refaites les étapes 7.3 à 7.7.

Règle par défaut : la note de rattrapage remplace l'examen ; le contrôle continu est conservé.

## 7.10 Décisions de fin d'année

**Où** : onglet **Décisions annuelles** (écran **Décisions de fin d'année**). **Qui** : R05, R07.

« Passage, crédits cumulés et dérogations, calculés à partir des semestres signés de l'année. »

1. Choisissez le **Groupe** et les **Semestres de l'année** (« Par exemple : S1, S2. »).
2. Cliquez sur **Aperçu** (« Aperçu : rien n'est encore enregistré. »). Colonnes : moyenne, **Crédits cumulés**, **Décision**, **Dérogation**, **UE manquantes**, **Dossier de diplôme**.
3. Cochez **Dérogation** pour un étudiant autorisé à titre exceptionnel (deuxième redoublement).
4. Cliquez sur **Enregistrer les décisions** (« 35 décision(s) enregistrée(s). »).

Règles par défaut (60 crédits par an) : 60 crédits → **Admis** ; 45 à 59 → **Admis avec dettes** (UE manquantes inscrites d'office l'année suivante) ; moins de 45 → **Redouble** (UE acquises capitalisées) ; deuxième redoublement du même niveau → **Exclu** pour insuffisance, sauf dérogation. En dernière année de licence, 180 crédits cumulés → admis, et le dossier de diplôme peut être transmis à la tutelle (chapitre 08).

Ces décisions conditionnent la réinscription (chapitre 03).

## 7.11 Relevés de notes

**Où** : onglet **Relevés** (écran **Relevés de notes semestriels**). **Qui** : R05, R07.

« Émission du relevé semestriel (DOC-09), possible une fois le PV du semestre signé. »

1. Choisissez l'**Étudiant**, le **Semestre** et la **Langue du relevé** (Français ou English).
2. Cliquez sur **Émettre le relevé**. Message « Relevé REL-EC-2026-000315 émis. »
3. La liste **Relevés émis** donne le **Numéro**, la date et l'état.

Le relevé contient l'en-tête de l'école (logo, n° d'autorisation, tutelle en LMD), l'identité, le détail par UE et EC (note, moyenne d'UE, lettre, points, résultat « acquise », « acquise par compensation » ou « non acquise », session), la synthèse (moyenne, MGP, crédits, décision, mention) et le pied avec numéro et QR : « Seul l'original signé ou vérifié en ligne fait foi ».

## 7.12 Rouvrir une délibération signée

**Qui** : Directeur académique (R03) seulement. Exceptionnel : à utiliser pour une erreur avérée.

1. Ouvrez la **Séance de délibération** du jury.
2. Cliquez sur **Rouvrir la séance**, saisissez le **Motif de la réouverture** (« Au moins 10 caractères. Le motif est tracé et le directeur général est alerté. »).
3. Confirmez avec la double authentification. État **Rouvert**.
4. Faites les corrections nécessaires (chapitre 06), puis clôturez de nouveau : un **nouveau PV** est établi et doit être signé à nouveau.

Le « Dernier motif de réouverture » reste affiché sur la séance.

## 7.13 Documents officiels : émettre, dupliquer, annuler

**Où** : **IPES > Documents officiels** (écran **Documents officiels**). « Catalogue, émission, registre, duplicata et annulation des documents de l'IPES. Chaque document porte un numéro unique et un code QR de vérification. »

### Avant tout : le profil documentaire de l'école

**Où** : **Documents officiels > Profil documentaire de l'école** (`/superieur/documents/profil`).

« Aucun profil pour cette école : renseignez-le avant d'émettre des documents. »
1. Choisissez l'école.
2. Renseignez **Code de l'école dans le numéro** (1 à 10 majuscules ou chiffres, par exemple EC), **Nom affiché**, **Texte d'en-tête**, **Adresse du logo (https://)**, **Couleur principale** (format #RRGGBB), **Langue par défaut**.
3. Vérifiez l'**Aperçu de l'en-tête**, puis **Enregistrer une nouvelle version**.

Chaque enregistrement crée une version ; les documents émis gardent la version de leur émission. Les signataires sont ceux du rôle prévu par le catalogue (directeur d'école, directeur général) ; leur nom et leur cachet se règlent dans les modèles de documents du socle (administrateur).

### Émettre un document

**Qui** : Scolarité (R07), Comptable (R15) pour les documents financiers.

1. Section **Émettre un document** : choisissez le **Document** dans le catalogue, le **Bénéficiaire** (recherche par nom ou téléphone), l'**École** et la **Langue du document** (par défaut : **Langue de la filière**).
2. Cliquez sur **Émettre**. Message « Document émis : n° EC-…. »

Certains documents ne s'émettent qu'à partir de leur écran (relevé après PV signé, quitus si solde nul, convention de stage…) ; le tableau 7.15 indique où.

### Registre, duplicata, annulation

Le **Registre des documents émis** se filtre par **Type de document** et **État** (**Valide**, **Annulé**, **Remplacé**).

- **Duplicata** : réimprime le document avec la mention « Duplicata » et la date ; le numéro d'origine est conservé (« Duplicatas : 2 »).
- **Annuler** : pour un document émis par erreur. « Un document émis par erreur est annulé, jamais supprimé ; le motif est affiché sur la page de vérification. » Saisissez le **Motif de l'annulation** et, si un document correct le remplace, son identifiant (**Remplacé par**). **Confirmer l'annulation**.

Règles : numéro unique jamais réutilisé, du type `REL-EC-2026-000315` (code du document, code d'école, année, numéro d'ordre) ; langue française ou anglaise ; en cas d'impayé, certains documents peuvent être bloqués (**Bloqué en cas d'impayé**), d'autres jamais (**Jamais bloqué** : certificat de scolarité, reçu, relevé de compte, attestation de paiement).

## 7.14 Vérifier un document (QR)

Toute personne (employeur, autre université, administration) peut vérifier un document, **sans compte** :

1. Scannez le **QR** du document avec un téléphone, ou ouvrez `https://scholariserp.trugroup.cm/verification` et saisissez le **Code de vérification du document** (imprimé sous le QR). Dans l'application, **Documents officiels > Vérifier un document** fait la même chose.
2. Cliquez sur **Vérifier**.
3. Résultats possibles :
   - **Document authentique** (« Émis et toujours valide ») : type, établissement, titulaire, année, n° unique, date d'émission, empreinte ;
   - **Document révoqué** avec « Motif : … » ;
   - « Remplacé par le document n° … » ;
   - **Document introuvable** : « Ce document n'a pas été émis par SCHOLARIS : numéro inconnu. » (probable faux).
4. « Comparez avec le papier présenté » : nom, numéro et date doivent correspondre exactement.

La page **n'affiche jamais les notes**. Elle est limitée en nombre de consultations : « Trop de vérifications à la suite. Patientez une minute puis réessayez. »

## 7.15 Les 32 documents (DOC-01 à DOC-32)

| Code | Document | À quoi il sert | Où il est produit | Signataire | QR |
|---|---|---|---|---|---|
| DOC-01 | Accusé de réception de candidature | Confirme au candidat que son dossier complet est reçu | automatiquement au dépôt complet de la candidature (chapitre 03) | cachet électronique | non |
| DOC-02 | Convocation au concours | Convoque le candidat à l'épreuve | fiche de la campagne, **Convoquer les candidats** | directeur d'école | oui |
| DOC-03 | Lettre de décision d'admission | Notifie la décision (admis, refusé…) | automatiquement à la décision | directeur d'école | oui |
| DOC-04 | Fiche d'inscription et contrat pédagogique | Récapitule l'inscription et les UE à suivre | fiche de l'inscription, **Émettre** | étudiant et scolarité | non |
| DOC-05 | Carte d'étudiant | Pièce d'identité de l'étudiant dans l'institut | fiche de l'inscription, **Émettre** | directeur d'école | oui |
| DOC-06 | Certificat de scolarité | Atteste l'inscription (bourses, administrations) | **Documents officiels** ; l'étudiant peut le demander | directeur d'école | oui |
| DOC-07 | Emploi du temps | Planning de la semaine | **Emploi du temps imprimable** | aucun | non |
| DOC-08 | Liste d'émargement d'examen | Feuille de présence signée en salle | **Planning des examens**, **Feuille d'émargement** | surveillants | non |
| DOC-09 | Relevé de notes semestriel | Résultats officiels du semestre | **Délibérations > Relevés**, après PV signé | directeur d'école | oui |
| DOC-10 | Relevé annuel et cumulatif | Résultats de l'année et crédits cumulés | **Documents officiels**, après délibération annuelle et PV signé | directeur d'école | oui |
| DOC-11 | Attestation de réussite (licence, master) | Atteste la réussite en attendant le diplôme délivré par l'université de tutelle | **Documents officiels**, après délibération finale et quitus | directeur général | oui |
| DOC-12 | Attestation de fin de formation (BTS, HND) | Atteste la fin de la formation interne ; ce n'est **pas** le diplôme national | **Examens nationaux** (fiche de session) ou **Documents officiels** | directeur général | oui |
| DOC-13 | Convocation aux examens ou au rattrapage | Informe l'étudiant de la date et de la salle | **Planning des examens** ; **Rattrapage** | aucun (automatique) | non |
| DOC-14 | Grille de délibération | Document de travail du jury | **Documents du jury** | aucun | non |
| DOC-15 | PV de délibération | Procès-verbal officiel des décisions | **Documents du jury** | président et membres | oui |
| DOC-16 | Liste des résultats à afficher | Affichage public des résultats | **Documents du jury**, après PV signé | directeur d'école | oui |
| DOC-17 | Liste et dossiers des candidats à l'examen national | Dossier transmis au MINESUP | **Examens nationaux** (fiche de candidature, export) | directeur général | non |
| DOC-18 | Listes des inscrits pour la tutelle | Liste transmise à l'université de tutelle | **Tutelle > Transmissions** ou **Documents officiels** | directeur général | non |
| DOC-19 | Requête de note et décision | Trace écrite d'une contestation et de sa réponse | **Documents officiels** (après traitement dans **Notes > Requêtes**) | étudiant puis chef de département | non |
| DOC-20 | Convention de stage | Contrat tripartite étudiant, entreprise, école | **Stages**, fiche de la convention, **Émettre la convention (DOC-20)** | étudiant, entreprise, directeur d'école | oui |
| DOC-21 | Convocation à la soutenance | Convoque l'étudiant et le jury | **Soutenances**, **Convoquer** | directeur d'école | non |
| DOC-22 | PV de soutenance | Note et décision du jury de soutenance | **Soutenances**, **Établir le PV (DOC-22)** | membres du jury | oui |
| DOC-23 | Fiche d'évaluation du stagiaire | Appréciation du tuteur en entreprise | **Documents officiels** ; suivi de la convention (« Fiche d'évaluation ») | tuteur | non |
| DOC-24 | Contrat de vacation | Contrat de l'enseignant vacataire | **Documents officiels** ; contrats gérés par les RH (menu **Personnel**) | enseignant et directeur général | non |
| DOC-25 | État mensuel des vacations | Heures et montants du mois d'un enseignant | **Vacations**, **Émettre l'état (DOC-25)** | chef de département, directeur d'école | non |
| DOC-26 | Bulletin de paiement de vacation | Preuve du paiement à l'enseignant | **Vacations**, **Émettre le bulletin (DOC-26)** | comptable | non |
| DOC-27 | Reçu de paiement | Preuve d'un paiement de frais | automatiquement à chaque encaissement (caisse) ; non émis depuis **Documents officiels** | automatique | oui |
| DOC-28 | Relevé de compte et échéancier | Situation financière détaillée de l'étudiant | **Documents officiels** | aucun | non |
| DOC-29 | Attestation de paiement | Atteste les sommes payées | compte de scolarité, **Émettre l'attestation de paiement (DOC-29)** | comptable | oui |
| DOC-30 | Quitus financier | Atteste que l'étudiant ne doit plus rien | compte de scolarité, **Émettre le quitus financier (DOC-30)**, si solde nul | comptable | oui |
| DOC-31 | Notification de sanction disciplinaire | Informe l'étudiant d'une sanction | **Discipline**, **Émettre la notification** | directeur d'école | oui |
| DOC-32 | Statistiques annuelles d'effectifs MINESUP | Effectifs à transmettre au ministère | **Documents officiels** ; chiffres visibles dans le tableau de bord **Effectifs MINESUP** | directeur général | non |

Règle légale : l'application **ne délivre jamais** de document intitulé « BTS » ou « HND » (ces diplômes sont délivrés par l'État) ni de diplôme de licence ou master (délivré par l'université de tutelle).
