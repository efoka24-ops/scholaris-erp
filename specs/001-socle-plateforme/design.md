# Design UI : synthèse de la maquette

Source : artefact « SCHOLARIS ERP — Design UI » (https://claude.ai/artifact/UrSmfST12cW9tuJRfgSRZL), 6 écrans datés du 8 octobre 2026. Contenu relu depuis les fichiers publiés ; l'artefact est un canevas Design, donc le lien affiche « Loading… » hors de l'éditeur.

## Écrans

| # | Écran | Taille | Contenu | Exigences couvertes |
|---|-------|--------|---------|---------------------|
| 01 | Lanceur « Mes établissements » | 1440 | Barre noire : logo, sélecteur d'établissement (« Tout le groupe »), sélecteur de rôle, langue FR/EN, notifications, avatar. Tuiles par établissement (type, période en cours, 2 KPI, lien), tuile « Vue consolidée » noire réservée à la direction de groupe, bloc « À traiter » (remboursement à valider, PV à signer, notes à saisir), bloc « Mes enfants » avec « Tout payer en une fois » | RG-IHM-2/3/7, EX-S01-06, EX-S04-07, EX-S13-02 |
| 02 | Tableau de bord IPES (Direction) | 1440 | Menu latéral (Tableau de bord, Inscriptions, Étudiants, Filières & UE, Emplois du temps, Présences, Évaluations & jurys, Frais & paiements, Vacations, Documents, Communication, Paramètres) ; alerte d'autorisation de filière qui expire ; KPI (étudiants, recouvrement, assiduité, vacations) ; recouvrement par école interne et par canal ; calendrier académique ; dernières inscriptions (dossier incomplet, doublon possible) | EX-S02-05, EX-S03-04/05, EX-S12-01, EX-S10 |
| 03 | Frais et paiements : Caisse | 1440 | Caisse ouverte (fonds, espèces encaissées, arrêt) ; formulaire d'encaissement par référence de paiement (dû par enfant, modes, règle d'affectation affichée) ; KPI (reçus du jour, à rapprocher, écart de caisse) ; journal du jour (reçu numéroté `R-IPES-26-04821`, statut Rapproché / En caisse / Sans référence / Annulation 2ᵉ agent requis) | EX-S04-05/06/10/11/13 |
| 04 | Mobile parent | 390 | Solde famille, échéance, « Tout payer » (Orange Money / MoMo), carte par enfant (moyenne, absences, rang), cahier de vie maternelle, réunion parents, barre Accueil / Paiements / Bulletins / Messages | EX-S11-02, RG-IHM-7 |
| 05 | Mobile enseignant : Appel hors-ligne | 390 | Bandeau hors-ligne, appel P/A/R par élève, compteurs, « Valider l'appel (42/42) », 0 appel en attente d'envoi | EX-S08-01, EX-S11-05 |
| 06 | Fondations du design system | 1200 | Couleurs, typographie, vocabulaire par application, boutons, statuts, champs, carte KPI | RG-IHM-5/6 |

## Fondations (v0.1)

- **Style** : noir et blanc, angles droits, bordure 1 px, aucune ombre, cibles tactiles ≥ 44 px.
- **Police** : Archivo (400 à 800). Échelle : Display 44, Titre 24, KPI 34, Corps 15, Surtitre 12 en capitales.
- **Couleurs** : Encre `#000`, Surface `#FFF`, Fond `#F4F4F1`, bordure `#DCDCD8`, texte secondaire `#595959`, Succès `#0A7A3E`, Attention `#B34700`, **accent d'établissement** par défaut `#F26B1D` (paramétrable). Texte toujours noir sur l'accent pour tenir 4,5:1 (NF-08, WCAG AA).
- **Statuts** : étiquettes à bord fin ; vert (À jour, Rapproché), orange (Impayé, Doublon possible), neutre (En caisse), noir plein (Validation requise).
- **Vocabulaire** (RG-IHM-5) :

| Terme | Maternelle | Primaire | Secondaire | Supérieur |
|-------|------------|----------|------------|-----------|
| Apprenant | enfant | élève | élève | étudiant |
| Regroupement | section | classe | classe | filière |
| Période | trimestre | séquence | trimestre | semestre |
| Document | carnet de suivi | bulletin APC | bulletin | relevé |

## Implications pour le plan et les tâches

1. **Accent par établissement** : `establishments.branding` doit porter `accent` (hex), logo, initiale ; l'API `/me/contexts` renvoie ces valeurs. Contrôle de contraste côté serveur : l'accent saisi doit garder 4,5:1 avec du texte noir (tâche T152).
2. **Vocabulaire** : fournir un dictionnaire par type d'établissement (`ApplicationDescriptor`, T058) exposé à l'interface (T153).
3. **Lanceur** : `/me/contexts` doit renvoyer pour chaque tuile deux KPI par rôle et la période en cours ; endpoint « À traiter » agrégeant approbations, signatures et saisies en attente (T154).
4. **Vue groupe** : tuile et tableau consolidés réservés au rôle direction de groupe (T133).
5. **Caisse** : journal du jour avec statuts, arrêté de caisse, rapprochement (T082, T088) ; référence de paiement par famille (T075).
6. **Autorisations de filière** : alerte d'expiration sur le tableau de bord (T155).
7. **Hors-ligne** : bandeau d'état, file d'envoi visible, compteur « appels en attente » (T139).
8. **Incohérence à signaler** : l'écran 01 montre des rôles Parent et Enseignant pour la même personne dans le même groupe, conforme à P1. Les maquettes ne couvrent ni connexion/OTP/2FA, ni inscription, ni saisie des notes, ni documents : à concevoir avant les phases correspondantes.
