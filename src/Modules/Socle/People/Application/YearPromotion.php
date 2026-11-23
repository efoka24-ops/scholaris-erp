<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\PromotionPlanner;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/**
 * Passage en classe supérieure à la clôture d'une année. Pour chaque inscription active ou conditionnelle de l'année close,
 * si la personne est admise (contrat Assessment\AdmissionDirectory) et si le paramètre `people.promotion.next_structure`
 * (table « classe => classe supérieure », par établissement ou héritée) désigne une classe supérieure, un brouillon
 * d'inscription est créé dans l'année suivante, à valider ensuite comme toute inscription. Les personnes non admises
 * (redoublement), sans classe supérieure définie, déjà inscrites dans l'année suivante, ou dont la classe supérieure n'a pas
 * d'autorisation valide, ne sont pas promues et sont dénombrées. Idempotent : une personne déjà inscrite pour l'année suivante est ignorée.
 */
final class YearPromotion implements PromotionPlanner
{
    public const SETTING = 'people.promotion.next_structure';

    public function __construct(
        private readonly PdoStore $store,
        private readonly EnrollmentService $enrollments,
        private readonly AdmissionDirectory $admissions,
        private readonly SettingsReader $settings,
    ) {
    }

    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        $rows = $this->store->rows(
            "SELECT person_id, structure_id FROM enrollments WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ?
             AND status IN ('active', 'conditional') AND structure_id IS NOT NULL ORDER BY id",
            [$tenantId, $establishmentId, $fromYearId],
        );
        $admitted = array_flip($this->admissions->admittedPersonIds($tenantId, $establishmentId, $fromYearId));
        $raw = $this->settings->value($tenantId, $establishmentId, self::SETTING, []);
        $next = is_array($raw) ? $raw : [];

        $counts = ['promoted' => 0, 'repeating' => 0, 'no_next_class' => 0, 'already_enrolled' => 0, 'blocked' => 0];
        foreach ($rows as $row) {
            $personId = (string) $row['person_id'];
            $target = $next[(string) $row['structure_id']] ?? null;
            if (!isset($admitted[$personId])) {
                ++$counts['repeating'];
            } elseif (!is_string($target) || $target === '') {
                ++$counts['no_next_class'];
            } elseif ($this->store->where('enrollments', $tenantId, ['person_id' => $personId, 'establishment_id' => $establishmentId, 'academic_year_id' => $toYearId], 'id', 1) !== []) {
                ++$counts['already_enrolled'];
            } else {
                try {
                    $this->enrollments->createDraft($tenantId, $establishmentId, $personId, $target, $toYearId, null);
                    ++$counts['promoted'];
                } catch (HttpException) {
                    ++$counts['blocked'];
                }
            }
        }

        $summary = sprintf('%d apprenant(s) promu(s) en classe supérieure (brouillons d\'inscription créés).', $counts['promoted']);
        foreach ([
            'repeating' => '%d non admis (redoublement)',
            'no_next_class' => '%d sans classe supérieure définie',
            'already_enrolled' => '%d déjà inscrit(s) pour l\'année suivante',
            'blocked' => '%d dont la classe supérieure n\'a pas d\'autorisation valide',
        ] as $key => $label) {
            if ($counts[$key] > 0) {
                $summary .= ' ' . sprintf($label, $counts[$key]) . '.';
            }
        }

        return new ClosureStepResult(ClosureStepResult::DONE, $summary, $counts);
    }
}
