<?php

declare(strict_types=1);

/**
 * Matrice des fonctionnalites par type d'etablissement.
 *
 * Principe : un etablissement ne voit que ce qui le concerne. Un directeur de
 * primaire ne doit jamais croiser « UE/EC », « GPA », « Baccalaureat » ou
 * « Series » dans son interface — ces notions n'existent pas chez lui.
 *
 * Trois etats possibles :
 *   'on'  affiche et actif
 *   'off' inexistant pour ce type : menu invisible, route refusee
 *   'opt' configurable, masque par defaut, activable dans les parametres
 *
 * Le tableau 'labels' porte les termes adaptes : un centre de formation parle
 * d'apprenants et de modules la ou une ecole parle d'eleves et de matieres.
 *
 * Les types plus fins que ceux du schema (COLLEGE, LYCEE_GENERAL,
 * LYCEE_TECHNIQUE, CENTRE_FORMATION) sont ceux de la matrice officielle ;
 * 'aliases' fait le pont avec les valeurs historiques du schema.
 */

// Raccourcis de lisibilite : la matrice compte plus de cent lignes.
$on = 'on';
$off = 'off';
$opt = 'opt';

return [
    'types' => [
        'PRIMAIRE' => 'Ecole primaire',
        'COLLEGE' => 'College',
        'LYCEE_GENERAL' => 'Lycee general',
        'LYCEE_TECHNIQUE' => 'Lycee technique',
        'CENTRE_FORMATION' => 'Centre de formation',
        'SUPERIEUR' => 'Enseignement superieur',
    ],

    // Types historiques du schema, rattaches au type de matrice equivalent.
    'aliases' => [
        'SECONDAIRE' => 'COLLEGE',
        'TECHNIQUE' => 'LYCEE_TECHNIQUE',
        'FORMATION_PRO' => 'CENTRE_FORMATION',
    ],

    /**
     * Terminologie adaptee. Toute valeur absente reprend celle par defaut.
     */
    'labels' => [
        'PRIMAIRE' => [],
        'COLLEGE' => [],
        'LYCEE_GENERAL' => [],
        'LYCEE_TECHNIQUE' => [],
        'CENTRE_FORMATION' => [
            'students' => 'Apprenants',
            'student' => 'apprenant',
            'subjects' => 'Modules',
            'subject' => 'module',
            'classrooms' => 'Groupes',
            'classroom' => 'groupe',
            'periods' => 'Semestres',
        ],
        'SUPERIEUR' => [
            'students' => 'Etudiants',
            'student' => 'etudiant',
            'periods' => 'Semestres',
        ],
    ],

    /**
     * Etat de chaque fonctionnalite par type.
     *
     * L'ordre suit la matrice officielle : structure, matieres et evaluation,
     * periodes, examens, bulletins, inscriptions, vie scolaire, finances et RH,
     * reporting.
     */
    'features' => [
        // ── 1. Structure academique ──────────────────────────────────────────
        'structure.cycles' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $on],
        'structure.programs' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'structure.departments' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $on],
        'structure.classrooms' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'structure.groups' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $opt],
        'structure.sections' => ['PRIMAIRE' => $opt, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'structure.rooms' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],

        // ── 2. Matieres et evaluation ────────────────────────────────────────
        'subjects.list' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'subjects.teaching_units' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $on],
        'subjects.coefficients' => ['PRIMAIRE' => $off, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'subjects.credits' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'subjects.hours_cm_td_tp' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'subjects.categories' => ['PRIMAIRE' => $off, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'subjects.eliminatory' => ['PRIMAIRE' => $off, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $opt, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'evaluation.competencies' => ['PRIMAIRE' => $on, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],

        // ── 3. Periodes et moteur de calcul ──────────────────────────────────
        'periods.sequences' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'periods.trimesters' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $off],
        'periods.semesters' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'calc.weighted_average' => ['PRIMAIRE' => $off, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'calc.simple_average' => ['PRIMAIRE' => $on, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $off],
        'calc.cc_exam_weighting' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'calc.resit' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'calc.mentions' => ['PRIMAIRE' => $off, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'calc.gpa' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],

        // ── 4. Examens officiels ─────────────────────────────────────────────
        'exams.official' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $opt],
        'exams.cep' => ['PRIMAIRE' => $on, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'exams.bepc' => ['PRIMAIRE' => $off, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'exams.probatoire' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'exams.baccalaureat' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'exams.cap_bep' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $off],
        'exams.bts_dut' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'exams.entrance' => ['PRIMAIRE' => $off, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'exams.oral_resit' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'exams.mock' => ['PRIMAIRE' => $opt, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'exams.custom' => ['PRIMAIRE' => $opt, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $opt, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'exams.series' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],

        // ── 5. Bulletins et documents ────────────────────────────────────────
        'bulletins.sequential' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'bulletins.trimestral' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $off],
        'bulletins.annual' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'bulletins.semester_transcript' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'bulletins.attestation' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'bulletins.certificate' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'bulletins.diploma' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'bulletins.school_card' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'bulletins.rank' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $opt],
        'bulletins.class_council' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],

        // ── 6. Inscriptions ──────────────────────────────────────────────────
        'enrollment.public_preregistration' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'enrollment.series_field' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'enrollment.repeater' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $on],
        'enrollment.boarding' => ['PRIMAIRE' => $opt, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'enrollment.import' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'enrollment.transfer' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'enrollment.internships' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],

        // ── 7. Vie scolaire ──────────────────────────────────────────────────
        'life.timetable' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'life.attendance' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'life.discipline' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $opt],
        'life.health' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $opt],
        'life.clubs' => ['PRIMAIRE' => $opt, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'life.catering' => ['PRIMAIRE' => $on, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $opt, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'life.boarding_house' => ['PRIMAIRE' => $off, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'life.transport' => ['PRIMAIRE' => $on, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $opt, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'life.library' => ['PRIMAIRE' => $opt, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'life.assets' => ['PRIMAIRE' => $opt, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'life.textbook' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $opt],

        // ── 8. Finances et RH ────────────────────────────────────────────────
        'finance.fees' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'finance.payments' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'finance.catering_fees' => ['PRIMAIRE' => $on, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $opt, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'finance.boarding_fees' => ['PRIMAIRE' => $off, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $opt],
        'finance.transport_fees' => ['PRIMAIRE' => $on, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $opt, 'LYCEE_TECHNIQUE' => $opt, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'finance.exam_fees' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $on],
        'finance.syscohada' => ['PRIMAIRE' => $opt, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'hr.payroll' => ['PRIMAIRE' => $opt, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'hr.leaves' => ['PRIMAIRE' => $opt, 'COLLEGE' => $opt, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'hr.cnps_dipe' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],

        // ── 9. Reporting ─────────────────────────────────────────────────────
        'report.dashboard' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'report.by_level' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'report.by_series' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'report.by_program' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
        'report.exam_success' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $opt, 'SUPERIEUR' => $opt],
        'report.minedub' => ['PRIMAIRE' => $on, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'report.minesec' => ['PRIMAIRE' => $off, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $off],
        'report.minesup' => ['PRIMAIRE' => $off, 'COLLEGE' => $off, 'LYCEE_GENERAL' => $off, 'LYCEE_TECHNIQUE' => $off, 'CENTRE_FORMATION' => $off, 'SUPERIEUR' => $on],
        'report.export' => ['PRIMAIRE' => $on, 'COLLEGE' => $on, 'LYCEE_GENERAL' => $on, 'LYCEE_TECHNIQUE' => $on, 'CENTRE_FORMATION' => $on, 'SUPERIEUR' => $on],
    ],

    /**
     * Libelles lisibles des fonctionnalites configurables, pour l'ecran de
     * parametrage. Seules celles-ci sont proposees a l'Admin.
     */
    'names' => [
        'structure.departments' => 'Departements',
        'structure.groups' => 'Groupes TD / TP',
        'structure.sections' => 'Sections francophone / anglophone',
        'subjects.coefficients' => 'Coefficients des matieres',
        'subjects.credits' => 'Credits ECTS',
        'subjects.hours_cm_td_tp' => 'Heures CM / TD / TP',
        'subjects.eliminatory' => 'Matieres eliminatoires',
        'periods.trimesters' => 'Trimestres',
        'periods.semesters' => 'Semestres',
        'calc.weighted_average' => 'Moyenne ponderee par coefficients',
        'calc.simple_average' => 'Moyenne simple',
        'calc.cc_exam_weighting' => 'Ponderation controle continu / examen',
        'calc.resit' => 'Session de rattrapage',
        'calc.mentions' => 'Mentions',
        'calc.gpa' => 'Calcul du GPA',
        'exams.official' => 'Examens officiels',
        'exams.bts_dut' => 'BTS / DUT',
        'exams.entrance' => "Concours d'entree",
        'exams.mock' => 'Examens blancs',
        'exams.custom' => 'Examens configurables',
        'bulletins.trimestral' => 'Bulletin trimestriel',
        'bulletins.annual' => 'Bulletin annuel',
        'bulletins.diploma' => 'Diplome',
        'bulletins.rank' => 'Rang sur le bulletin',
        'enrollment.boarding' => 'Regime internat',
        'enrollment.transfer' => 'Mutation et transfert',
        'life.discipline' => 'Discipline',
        'life.health' => 'Sante scolaire',
        'life.clubs' => 'Clubs et associations',
        'life.catering' => 'Cantine',
        'life.boarding_house' => 'Internat',
        'life.transport' => 'Transport scolaire',
        'life.library' => 'Bibliotheque',
        'life.assets' => 'Patrimoine',
        'life.textbook' => 'Cahier de textes',
        'finance.catering_fees' => 'Frais de cantine',
        'finance.boarding_fees' => "Frais d'internat",
        'finance.transport_fees' => 'Frais de transport',
        'finance.exam_fees' => "Frais d'examen",
        'finance.syscohada' => 'Comptabilite SYSCOHADA',
        'hr.payroll' => 'Paie et salaires',
        'hr.leaves' => 'Conges',
        'report.exam_success' => 'Taux de reussite aux examens',
    ],
];
