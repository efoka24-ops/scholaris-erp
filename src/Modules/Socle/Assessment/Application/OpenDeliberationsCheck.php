<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;

/**
 * Contrôle « Délibérations ouvertes » de la clôture d'une année : délibérations (brouillon ou séance ouverte) portant sur
 * une période de l'année, c'est-à-dire une période dont des évaluations sont rattachées à cette année.
 */
final class OpenDeliberationsCheck implements ClosureCheck
{
    public const KEY = 'assessment.deliberations_open';
    public const LABEL = 'Délibérations ouvertes';

    public function __construct(private readonly Db $db)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding
    {
        $count = $this->db->int(
            "SELECT COUNT(*) FROM deliberations WHERE tenant_id = ? AND establishment_id = ? AND status IN ('draft', 'open')
               AND period_id IN (SELECT period_id FROM assessments WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ?)",
            [$tenantId, $establishmentId, $tenantId, $establishmentId, $academicYearId],
        );

        return ClosureFinding::of(self::KEY, self::LABEL, $count, $count > 0 ? sprintf('%d délibération(s) non clôturée(s).', $count) : null);
    }
}
