<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

/** Permissions du paiement des vacations (déjà déclarées par la migration des rôles Higher) et paramètres lus. */
final class VacationPermissions
{
    /** Lecture des états du périmètre : R01, R02, R04, R05, R15, R17. */
    public const READ = ['higher.vacation.read', 'higher.vacation.validate', 'higher.vacation.validate_school', 'higher.vacation.pay', 'higher.hours.validate'];
    /** « Mes vacations » : enseignant permanent ou vacataire (R09, R10). */
    public const READ_OWN = ['higher.vacation.read_own'];
    /** Établir l'état mensuel à partir des heures validées : chef de département (R05) ou comptable (R15). */
    public const PREPARE = ['higher.hours.validate', 'higher.vacation.pay'];
    /** Visa de l'école (R04, decisions.md C12). */
    public const VALIDATE_SCHOOL = ['higher.vacation.validate_school'];
    /** Approbation de la direction (R02). */
    public const APPROVE = ['higher.vacation.validate'];
    /** Paiement par virement ou mobile money (R15). */
    public const PAY = ['higher.vacation.pay'];
    /** Rejet d'un état non payé : l'un des valideurs ou le comptable. */
    public const REJECT = ['higher.vacation.validate_school', 'higher.vacation.validate', 'higher.vacation.pay'];
    /** Émission de l'état (DOC-25) : chef de département, directeur d'école ou comptable. */
    public const STATEMENT_DOCUMENT = ['higher.hours.validate', 'higher.vacation.validate_school', 'higher.vacation.pay'];

    /** Retenues : liste de {code, label, percent} ; vide par défaut (aucune retenue, à confirmer : Q21). */
    public const DEDUCTIONS = 'higher.vacation.deductions';
    /** Approbation de la direction après le visa de l'école (actif par défaut) ; inactif = l'état visé est approuvé. */
    public const DIRECTION_APPROVAL = 'higher.vacation.direction_approval';

    /** Modèles du Messenger (NOT-14, SMS ou e-mail selon le canal disponible). */
    public const NOT14_HOURS = 'higher.not14.hours_validated';
    public const NOT14_PAID = 'higher.not14.vacation_paid';

    public const PAYMENT_METHODS = ['bank_transfer', 'mobile_money'];

    private function __construct()
    {
    }
}
