<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

/** Permissions de l'enseignement (RoleMatrix, migration des rôles) et paramètres lus. */
final class TeachingPermissions
{
    /** Emploi du temps : lecture (R02, R04, R05, R06, R07, R08, R09). */
    public const TIMETABLE_READ = ['higher.timetable.read', 'scheduling.manage'];
    /** Pose et modification des créneaux, salles, reports (R05, R06, R07). */
    public const TIMETABLE_MANAGE = ['scheduling.manage'];
    /** Affectations par le chef de département (R05), limitées à son département par le périmètre. */
    public const ASSIGN = ['higher.assignment.manage'];
    public const ASSIGN_READ = ['higher.assignment.manage', 'higher.timetable.read', 'higher.session_log.read', 'higher.hours.validate'];
    /** Dossier enseignant : RH (R17, staff.manage) ou chef de département. */
    public const TEACHER_MANAGE = ['staff.manage', 'higher.assignment.manage'];
    public const TEACHER_READ = ['staff.manage', 'staff.read', 'higher.assignment.manage', 'higher.timetable.read'];
    /** Cahier de textes (R09, R10 : ses affectations). */
    public const LOG_WRITE = ['higher.session_log.write'];
    public const LOG_READ = ['higher.session_log.read', 'higher.session_log.validate', 'higher.hours.validate'];
    /** Contre-signature par un surveillant (hors délégué du groupe). */
    public const COUNTERSIGN = ['higher.session_log.validate', 'higher.exam.manage'];
    /** Validation mensuelle des heures (R05). */
    public const HOURS_VALIDATE = ['higher.hours.validate'];
    /** Accord écrit pour heures hors volume (RG-ENS-4, R04). */
    public const OVERRUN_APPROVE = ['higher.vacation.validate_school'];
    /** Planning des examens (R08). */
    public const EXAM_MANAGE = ['higher.exam.manage'];
    public const EXAM_READ = ['higher.exam.manage', 'higher.timetable.read'];
    /** Délégués de groupe. */
    public const DELEGATE_MANAGE = ['scheduling.manage', 'higher.assignment.manage'];

    /** Début de la plage du soir (cours du soir, EX-EDT-03). */
    public const EVENING_FROM = 'higher.timetable.evening_from';
    /** RG-ENS-5 activée par défaut. */
    public const HOLD_UNTIL_GRADES = 'higher.vacation.hold_until_grades';

    public const KINDS = ['cm', 'td', 'tp', 'other'];

    private function __construct()
    {
    }
}
