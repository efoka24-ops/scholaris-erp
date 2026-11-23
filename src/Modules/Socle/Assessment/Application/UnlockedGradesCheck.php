<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;

/**
 * Contrôle « Notes non verrouillées » de la clôture d'une année : évaluations de l'année dont l'état permet encore la
 * saisie ou la modification des notes (états « éditables » du cycle de vie propre à l'établissement).
 */
final class UnlockedGradesCheck implements ClosureCheck
{
    public const KEY = 'assessment.grades_unlocked';
    public const LABEL = 'Notes non verrouillées';

    public function __construct(private readonly Db $db, private readonly AssessmentSettings $settings)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding
    {
        $editable = $this->settings->lifecycle($tenantId, $establishmentId)->editable;
        if ($editable === []) {
            return ClosureFinding::of(self::KEY, self::LABEL, 0);
        }
        $count = $this->db->int(
            'SELECT COUNT(*) FROM assessments WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? AND status IN (' . implode(', ', array_fill(0, count($editable), '?')) . ')',
            [$tenantId, $establishmentId, $academicYearId, ...$editable],
        );

        return ClosureFinding::of(self::KEY, self::LABEL, $count, $count > 0 ? sprintf('%d évaluation(s) dont les notes ne sont pas verrouillées.', $count) : null);
    }
}
