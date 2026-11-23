# 09. Pilotage : tableaux de bord, exports, reprise de données

## 9.1 Tableaux de bord

**Où** : **IPES > Tableaux de bord** (`/superieur/tableaux-de-bord`).

**Qui** : Promoteur (R01), Directeur général (R02), Directeur académique (R03), Directeur d'école (R04), Chef de département (R05), Scolarité (R07), Responsable de la discipline (R11), Comptable (R15). **Chacun ne voit que les chiffres de son périmètre** (par exemple, un directeur d'école voit son école, un chef de département son département) et seulement les tableaux que son rôle autorise (un comptable voit le recouvrement, pas la réussite).

### Les huit tableaux

| Tableau | Ce qu'il montre | Pour qui surtout | Fréquence conseillée |
|---|---|---|---|
| **Candidatures et inscriptions** | candidatures, admis et inscrits par école et filière ; entonnoir des candidatures ; inscrits par niveau | R01, R02, R04 | chaque jour en campagne |
| **Recouvrement des frais** | attendu, encaissé, reste dû, impayés échus, taux de recouvrement ; encaissements par canal | R01, R02, R15 | chaque jour |
| **Avancement de la saisie** | évaluations en brouillon, soumises, validées, verrouillées, publiées, en retard, par EC | R03, R04, R05 | chaque semaine en période d'examens |
| **Réussite et rattrapage** | taux de réussite par EC, par UE, par filière et niveau ; rattrapage | R03, R04 | après chaque délibération |
| **Assiduité** | séances, appels, présents, retards, absents, taux d'absences non justifiées par groupe et EC | R04, R11 | chaque semaine |
| **Vacations** | heures, HETD, brut, net et payé par département et par mois | R02, R05, R15 | chaque mois |
| **Examens nationaux BTS et HND** | candidats, résultats reçus, admis, ajournés, taux d'admission | R01, R02 | chaque année |
| **Effectifs MINESUP** | inscrits par sexe, âge, filière, niveau ; part des femmes | R02, R07 | chaque année |

### Utiliser un tableau

1. Sur l'index, cliquez sur **Ouvrir** à côté du tableau voulu.
2. Choisissez la période (**Du**, **Au**) ou l'**Année académique** selon le tableau, puis **Actualiser**.
3. Lisez les **cartes** en haut (par exemple **Attendu**, **Encaissé**, **Recouvrement**), le **graphique**, puis les tableaux détaillés.
4. « Calculé le … » indique l'heure du calcul.
5. **Tous les tableaux** ramène à l'index.

**Protection des personnes** : « Effectifs inférieurs à 5 masqués pour protéger les personnes ; une ligne voisine peut l'être aussi. » Une case affichant « < 5 » n'est pas une erreur.

## 9.2 Exports

### Depuis un tableau de bord

Sous chaque tableau détaillé, deux boutons : **CSV** et **Excel** (« Exporter le tableau « … » »). Le fichier CSV s'ouvre dans Excel ou LibreOffice. Si « Le format Excel n'est pas disponible sur ce serveur : utilisez le CSV. », prenez le CSV.

### Autres exports

- **Maquettes** : bouton d'export dans l'éditeur de maquette (chapitre 02).
- **Liste des candidats aux examens nationaux** : export au format MINESUP, filigrané et journalisé (chapitre 08).
- Les listes des écrans IPES proposent, quand c'est prévu, un bouton **Exporter**.
- Statistiques réglementaires générales de SCHOLARIS : menu **Rapports** > statistiques (`/rapports/statistiques`), pour les rôles qui ont la lecture des rapports.

Règles :
- Les **exports nominatifs** (avec des noms d'étudiants) sont réservés aux rôles habilités, **journalisés** et **filigranés** avec le nom de la personne qui exporte et la date.
- Un fichier exporté contient des données personnelles protégées par la loi n° 2024/017 : ne l'envoyez pas par WhatsApp ou e-mail personnel ; supprimez-le quand il ne sert plus.
- Quand l'abonnement est suspendu, la consultation et l'export restent possibles.

## 9.3 Reprise de données (démarrage de SCHOLARIS)

### Ce qu'il faut savoir d'abord

- **Il n'y a pas d'écran de reprise dans cette version.** La reprise se fait par l'interface technique (API `/api/v1/higher/migration`), **par l'équipe SCHOLARIS**, avec la scolarité, la direction d'école et le comptable de l'institut.
- La permission nécessaire (`higher.migration.manage`) est donnée au Directeur d'école (R04) et au Service de la scolarité (R07). Leur périmètre (école, département) limite les lignes qu'ils peuvent reprendre.
- La reprise est **limitée à l'année en cours et aux UE capitalisées**. Les années antérieures ne sont pas reprises : les anciens relevés restent sur papier ou numérisés à part.
- Les étudiants **mineurs** (moins de 18 ans) sont refusés par la reprise : inscrivez-les par l'écran **Inscriptions** avec leur garant.
- Les **fiches du personnel** doivent exister avant la reprise du personnel enseignant.

### Les cinq jeux de données, dans cet ordre

| N° | Jeu | Contenu | Qui prépare côté institut |
|---|---|---|---|
| 1 | Étudiants | étudiants en cours et leur inscription (matricule d'origine possible) | scolarité |
| 2 | UE capitalisées et dettes | UE déjà acquises, UE à repasser | scolarité, chefs de département |
| 3 | Soldes d'ouverture | sommes dues et échéanciers au démarrage | comptable |
| 4 | Personnel enseignant | enseignants et affectations | RH, chefs de département |
| 5 | Notes de l'année en cours | notes déjà obtenues, reprises comme verrouillées avec le motif « reprise » | scolarité, enseignants |

### Le déroulement

1. **Modèle** : l'équipe SCHOLARIS vous remet le modèle du jeu (CSV UTF-8 séparateur « ; », ou Excel).
2. **Remplissage** : l'institut remplit le fichier, sans ligne d'exemple, avec des données vérifiées. Ne l'envoyez que par le canal convenu avec l'équipe (jamais sur un groupe de discussion).
3. **Simulation obligatoire** : l'équipe lance une simulation. **Rien n'est écrit.** Un rapport ligne par ligne signale les erreurs, les doublons et les avertissements. Vous corrigez le fichier, et l'on recommence **jusqu'à zéro erreur**.
4. **Application** : avec la double authentification d'une personne habilitée. **Tout ou rien** : si une ligne pose problème, rien n'est appliqué. Relancer le même fichier ne crée pas de doublon (les lignes déjà reprises sont ignorées ; la clé est le matricule, sinon nom + prénom + date de naissance).
5. **Contrôle** : vérifiez environ 5 % des lignes dans les écrans (dossier étudiant, compte de scolarité, notes).
6. **Annulation** si le fichier était faux : l'équipe annule le lot, avec un motif, tant qu'aucune donnée dépendante n'existe (par exemple, on annule d'abord les notes et les soldes avant les étudiants).

**Attention aux soldes d'ouverture** : ils **ne s'annulent pas** (une facture émise ne se supprime pas). Le comptable relit la simulation avec le plus grand soin. Une erreur se corrige ensuite par une opération tracée sur le compte.

Tout (simulation, application, annulation) est inscrit au journal d'audit.
