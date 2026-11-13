<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Application;

/** Permissions et paramètres des présences et de la discipline de l'IPES. */
final class AttendancePermissions
{
    /** Lecture de l'assiduité (R02, R04, R05, R06, R07, R08, R11). */
    public const READ = ['higher.attendance.read'];
    /** Alertes, transmission des non-autorisés, justificatifs et épreuves de remplacement (scolarité R07). */
    public const MANAGE = ['attendance.justify'];
    /** Réception des listes et programmation des épreuves de remplacement (service des examens R08). */
    public const EXAMS = ['higher.exam.manage'];
    /** Levée de l'interdiction de composer : dérogation du directeur d'école (R04). */
    public const LIFT = ['higher.enrolment.derogate'];
    /** Sanctions (R11). */
    public const SANCTION = ['attendance.discipline.manage'];
    /** Conseil de discipline (R11). */
    public const COUNCIL = ['higher.council.manage'];
    public const DISCIPLINE_READ = ['attendance.discipline.manage', 'higher.council.manage'];

    public const MAX_UNJUSTIFIED = 'attendance.max_unjustified_percent';
    public const ALERT_UNJUSTIFIED = 'attendance.alert_unjustified_percent';
    public const NATIONAL_MIN_PRESENCE = 'higher.national_exam.min_attendance_percent';

    public const SANCTION_KINDS = ['warning', 'reprimand', 'temporary_exclusion', 'permanent_exclusion'];

    private function __construct()
    {
    }
}
