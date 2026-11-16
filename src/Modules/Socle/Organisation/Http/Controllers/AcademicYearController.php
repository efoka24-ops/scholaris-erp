<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Organisation\Application\AcademicYearService;
use Scholaris\Modules\Socle\Organisation\Application\OrgClock;
use Scholaris\Modules\Socle\Organisation\Domain\PeriodKind;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Http\Requests\AcademicYearRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\AcademicYearResource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/** Années scolaires de l'établissement actif ; chaque établissement a les siennes et les clôture seul. */
final class AcademicYearController extends ScopedCrudController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        Repositories $repositories,
        OrganisationPolicy $policy,
        OrgClock $clock,
    ) {
        parent::__construct($responder, $context, $repositories, $policy, $clock);
    }

    protected function table(): string
    {
        return 'academic_years';
    }

    protected function objectType(): string
    {
        return 'academic_year';
    }

    protected function validator(): ValidatedRequest
    {
        return new AcademicYearRequest();
    }

    protected function resource(): Resource
    {
        return new AcademicYearResource();
    }

    protected function filters(): array
    {
        return ['status', 'label'];
    }

    protected function sorts(): array
    {
        return ['starts_on', 'ends_on', 'label'];
    }

    protected function guardUpdate(array $existing): void
    {
        if ($existing['status'] === AcademicYearService::CLOSED) {
            throw HttpException::conflict('Une année scolaire clôturée ne peut plus être modifiée.');
        }
    }

    protected function check(array $changes, array $merged, ?array $existing): array
    {
        if ($merged['ends_on'] <= $merged['starts_on']) {
            $this->fail(['ends_on' => ['La date de fin doit être postérieure à la date de début.']]);
        }
        foreach ($this->repository()->all() as $other) {
            if ($existing !== null && $other['id'] === $existing['id']) {
                continue;
            }
            if ($merged['starts_on'] <= $other['ends_on'] && $other['starts_on'] <= $merged['ends_on']) {
                $this->fail(['starts_on' => ['Ces dates chevauchent l\'année scolaire « ' . $other['label'] . ' » de cet établissement.']]);
            }
        }

        if ($existing === null) {
            return $changes + ['status' => AcademicYearService::OPEN];
        }
        // Réduire l'année ne laisse aucune période en dehors : il faut d'abord modifier (ou supprimer) la période.
        foreach ($this->repositories->request('periods')->all(['academic_year_id' => (string) $existing['id']], 'starts_on') as $period) {
            $field = $period['starts_on'] < $merged['starts_on'] ? 'starts_on' : ($period['ends_on'] > $merged['ends_on'] ? 'ends_on' : null);
            if ($field !== null) {
                $this->fail([$field => ['La période « ' . PeriodKind::from((string) $period['kind'])->label((int) $period['ordinal']) . ' » (du '
                    . PeriodController::day((string) $period['starts_on']) . ' au ' . PeriodController::day((string) $period['ends_on'])
                    . ') sortirait de l\'année : modifiez d\'abord ses dates.']]);
            }
        }

        return $changes;
    }
}
