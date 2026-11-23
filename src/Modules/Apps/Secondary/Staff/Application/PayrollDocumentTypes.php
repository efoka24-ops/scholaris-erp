<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType as T;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * DOC-30 (état des vacations et bulletin de paie, CDC : préparé par l'intendant, sans QR) : module `payroll_simple`, émis seulement par
 * PayrollService au paiement (pas par la route des documents). Type et modèles FR/EN du socle :
 * `database/migrations/202611070302_secondary_document_types_e3.sql`.
 */
final class PayrollDocumentTypes implements DocumentTypeProvider
{
    public const PAYSLIP = 'payroll_payslip';

    public function types(): array
    {
        return [
            new T('DOC-30', self::PAYSLIP, 'sec_doc30_payroll_payslip', 'État des vacations et bulletin de paie', 'Teaching hours statement and payslip', false, T::SOURCE_CALLER, StaffAccess::PAYROLL_PREPARE, Modules::PAYROLL_SIMPLE, false),
        ];
    }
}
