// Contenu du guide d'utilisation, par menu. La page /guide n'affiche que les
// entrées dont l'utilisateur a la permission (et qui concernent son type
// d'établissement) : chaque acteur lit ainsi un guide adapté à son profil.

export interface GuideEntry {
  href: string;
  title: string;
  what: string;
  how: string[];
}
export interface GuideSection {
  section: string;
  entries: GuideEntry[];
}

export const USER_GUIDE: GuideSection[] = [
  {
    section: "Prise en main",
    entries: [
      {
        href: "/dashboard",
        title: "Tableau de bord",
        what: "Vue d'ensemble de votre établissement : effectifs, année académique active, indicateurs clés.",
        how: [
          "À la première connexion, changez votre mot de passe (Configuration → Mon profil).",
          "Vérifiez que l'année académique active et les périodes (séquences/trimestres) sont bien configurées.",
        ],
      },
      {
        href: "/settings/profile",
        title: "Mon profil",
        what: "Vos informations de compte et le changement de mot de passe.",
        how: ["Saisissez votre mot de passe actuel puis le nouveau (min. 8 caractères) et enregistrez."],
      },
    ],
  },
  {
    section: "Académique",
    entries: [
      {
        href: "/academics/structure",
        title: "Structure pédagogique",
        what: "L'arborescence de l'établissement : cycles, niveaux, filières/séries.",
        how: [
          "Créez d'abord les cycles, puis les niveaux (ex. 6ème→3ème), puis les classes.",
          "Le Supérieur gère des filières et départements ; le secondaire des séries (A, C, D…).",
        ],
      },
      {
        href: "/academics/classrooms",
        title: "Classes",
        what: "Les classes/groupes de l'établissement et leur capacité.",
        how: ["Créez une classe en la rattachant à un niveau, avec un code (ex. 3ème A) et une capacité."],
      },
      {
        href: "/academics/rooms",
        title: "Salles",
        what: "Les salles et laboratoires, utilisés par les emplois du temps.",
        how: ["Ajoutez chaque salle avec sa capacité ; elles seront proposées lors de la création des emplois du temps."],
      },
      {
        href: "/academics/subjects",
        title: "Matières / Modules",
        what: "Les disciplines enseignées et leurs coefficients (par série au lycée).",
        how: [
          "Créez chaque matière avec son coefficient et sa catégorie.",
          "Vous pouvez importer les matières en masse depuis un fichier Excel.",
        ],
      },
      {
        href: "/academics/teaching-units",
        title: "UE & EC (LMD)",
        what: "Unités d'Enseignement et Éléments Constitutifs pour le supérieur, avec crédits ECTS.",
        how: ["Créez les UE (avec crédits) par semestre, puis les EC rattachés à chaque UE."],
      },
      {
        href: "/academics/assignments",
        title: "Assignations",
        what: "L'affectation des enseignants aux matières et aux classes.",
        how: ["Reliez un enseignant à une matière pour une classe et une année données."],
      },
      {
        href: "/settings/academic-years",
        title: "Années académiques",
        what: "Les années scolaires et leurs périodes (séquences, trimestres, semestres).",
        how: [
          "Créez l'année, activez-la, puis créez ses périodes.",
          "Convention : Trimestre N = séquences 2N-1 et 2N (ex. T1 = Séq.1 + Séq.2).",
        ],
      },
    ],
  },
  {
    section: "Élèves / Étudiants",
    entries: [
      {
        href: "/students",
        title: "Élèves / Étudiants",
        what: "Les dossiers des apprenants et leurs parents.",
        how: [
          "Créez un élève manuellement, ou importez en masse via Excel (bouton Importer).",
          "À l'import, indiquez la colonne Classe_code : les classes absentes sont créées et l'élève inscrit automatiquement.",
          "Exports et impressions : liste CSV, listes de classe, cartes scolaires (QR) et étiquettes.",
        ],
      },
      {
        href: "/admissions",
        title: "Admissions",
        what: "Les demandes de pré-inscription déposées en ligne.",
        how: ["Étudiez chaque candidature puis acceptez / refusez / mettez en liste d'attente."],
      },
      {
        href: "/enrollments",
        title: "Inscriptions",
        what: "L'inscription des élèves dans les classes pour l'année active.",
        how: ["Inscrivez un élève dans une classe ; utilisez la réinscription en lot d'une classe vers une autre."],
      },
    ],
  },
  {
    section: "Notes & Bulletins",
    entries: [
      {
        href: "/grades/entry",
        title: "Saisie des notes",
        what: "La saisie des notes par matière, classe et période.",
        how: [
          "Sélectionnez classe + période + matière, saisissez les notes, puis verrouillez pour figer.",
          "Une note « absent » compte 0 (selon la règle d'absence configurée).",
        ],
      },
      {
        href: "/grades/calculations",
        title: "Calculs",
        what: "Le déclenchement du calcul des moyennes et rangs.",
        how: ["Lancez le calcul pour une classe/période : moyennes pondérées, rangs et mentions sont produits."],
      },
      {
        href: "/bulletins",
        title: "Bulletins / Relevés",
        what: "La génération des bulletins (secondaire) ou relevés/transcripts LMD (supérieur).",
        how: [
          "Générez pour toute une classe et une période, puis imprimez (bouton PDF → Enregistrer en PDF).",
          "Envoyez aux parents par email (si l'envoi d'emails est configuré).",
        ],
      },
    ],
  },
  {
    section: "Examens officiels",
    entries: [
      {
        href: "/exams",
        title: "Examens officiels",
        what: "CEP, BEPC, Probatoire, Baccalauréat et examens configurables.",
        how: [
          "Créez l'examen (période d'inscription, frais, conditions), puis inscrivez les candidats en lot.",
          "Après l'examen, importez les résultats : moyenne, décision, mention et classement sont calculés.",
          "Imprimez récépissés, liste officielle et tableau d'affichage ; exportez en CSV.",
        ],
      },
    ],
  },
  {
    section: "Rapports",
    entries: [
      {
        href: "/reports/level",
        title: "Rapport par niveau",
        what: "Les statistiques agrégées d'un niveau : moyennes, taux de réussite, distribution, comparaison des classes.",
        how: ["Choisissez le niveau et la période, générez, puis imprimez ou exportez."],
      },
    ],
  },
  {
    section: "Finance",
    entries: [
      {
        href: "/finance/dashboard",
        title: "Tableau de bord financier",
        what: "Le suivi des recouvrements et des impayés.",
        how: ["Consultez les indicateurs et filtrez par classe/niveau."],
      },
      {
        href: "/finance/fee-structures",
        title: "Grilles tarifaires",
        what: "Les frais de scolarité par niveau et leurs échéances.",
        how: ["Créez une grille par niveau ; elle sert à générer les factures des élèves."],
      },
      {
        href: "/finance/invoices",
        title: "Factures",
        what: "Les factures des élèves.",
        how: ["Générez les factures individuellement ou en lot pour une classe."],
      },
      {
        href: "/finance/payments",
        title: "Paiements",
        what: "L'enregistrement des paiements et l'impression des reçus.",
        how: [
          "Enregistrez un paiement (espèces, mobile money, virement, chèque).",
          "Imprimez le reçu (bouton Reçu) et partagez-le au parent.",
        ],
      },
    ],
  },
  {
    section: "Configuration",
    entries: [
      {
        href: "/settings/users",
        title: "Utilisateurs",
        what: "Les comptes du personnel de l'établissement.",
        how: ["Créez un compte, attribuez-lui un ou plusieurs rôles, communiquez ses identifiants."],
      },
      {
        href: "/settings/roles",
        title: "Rôles & Permissions",
        what: "Les rôles et les permissions associées.",
        how: ["Créez des rôles personnalisés et cochez les permissions ; assignez-les aux utilisateurs."],
      },
      {
        href: "/settings/calculation-engine",
        title: "Moteur de calcul",
        what: "Le paramétrage des moyennes, pondérations, mentions et arrondis.",
        how: ["Il est pré-configuré selon le type d'établissement ; ajustez pondérations et seuils si besoin."],
      },
      {
        href: "/settings/bulletin-groups",
        title: "Groupes de matières",
        what: "Les regroupements de matières (Groupe 1/2/3) affichés sur le bulletin.",
        how: ["Nommez les groupes et affectez chaque matière ; les sous-totaux apparaissent sur le bulletin."],
      },
      {
        href: "/settings/modules",
        title: "Modules & fonctionnalités",
        what: "L'activation des modules optionnels (cantine, internat, bibliothèque, transport…).",
        how: ["Cochez les modules à activer ; les menus correspondants apparaissent dans la barre latérale."],
      },
      {
        href: "/settings/establishment-requests",
        title: "Demandes d'établissement",
        what: "La validation des demandes de création déposées par les directeurs (Super Admin).",
        how: [
          "Validez une demande : l'établissement, le compte directeur et son rôle Admin sont créés automatiquement.",
          "Le mot de passe temporaire s'affiche (bouton Copier) pour le transmettre au directeur.",
        ],
      },
    ],
  },
];
