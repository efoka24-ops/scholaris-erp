<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Promotion\Application;

use Scholaris\Modules\Apps\PublicPrimary\Promotion\Infrastructure\PdoPromotion;
use Scholaris\Modules\Apps\PublicSchools\Contracts\CensusSectionProvider;

/**
 * Section « fin d'année » du questionnaire statistique (EX-STA-06, T653) fournie par la Primaire publique : promus, promus avec
 * remédiation, redoublants et décisions en attente, par niveau. Effectifs seulement (aucun nom).
 */
final class EndOfYearCensusSection implements CensusSectionProvider
{
    public function __construct(private readonly PdoPromotion $repository)
    {
    }

    public function sectionKey(): string
    {
        return 'primary_promotion';
    }

    public function title(string $locale): string
    {
        return $locale === 'en' ? 'End of year: promotion and repetition' : 'Fin d\'année : passage et redoublement';
    }

    public function values(string $tenantId, string $establishmentId, string $yearLabel): array
    {
        $values = [];
        foreach ($this->repository->ofClass($tenantId, $establishmentId, $yearLabel, null) as $row) {
            $key = $row['decision_state'] === 'final' ? (string) $row['decision'] : 'pending';
            $field = $key . '.' . strtolower((string) $row['level_code']);
            $values[$field] = (int) ($values[$field] ?? 0) + 1;
        }
        ksort($values);

        return $values;
    }

    public function controls(string $tenantId, string $establishmentId, string $yearLabel, array $values): array
    {
        $anomalies = [];
        foreach ($values as $field => $value) {
            if (str_starts_with($field, 'pending.') && is_int($value) && $value > 0) {
                $anomalies[] = 'primary_promotion.pending_decisions';
                break;
            }
        }

        return $anomalies;
    }
}
