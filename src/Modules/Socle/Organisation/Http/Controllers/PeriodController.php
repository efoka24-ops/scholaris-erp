<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Application\AcademicYearService;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\PeriodKind;
use Scholaris\Modules\Socle\Organisation\Http\Requests\PeriodRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\PeriodResource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;

/** Périodes (trimestres, séquences, semestres) d'une année scolaire de l'établissement actif. */
final class PeriodController extends ScopedCrudController
{
    protected function table(): string
    {
        return 'periods';
    }

    protected function objectType(): string
    {
        return 'period';
    }

    protected function validator(): ValidatedRequest
    {
        return new PeriodRequest();
    }

    protected function resource(): Resource
    {
        return new PeriodResource();
    }

    protected function filters(): array
    {
        return ['academic_year_id', 'kind'];
    }

    protected function sorts(): array
    {
        return ['ordinal', 'starts_on', 'kind'];
    }

    protected function immutable(): array
    {
        return ['academic_year_id'];
    }

    protected function check(array $changes, array $merged, ?array $existing): array
    {
        // L'année doit appartenir à l'établissement actif (le dépôt est borné au client et à l'établissement).
        $year = $this->repositories->request('academic_years')->find((string) $merged['academic_year_id']);
        if ($year === null) {
            $this->fail(['academic_year_id' => ['Année scolaire introuvable dans cet établissement.']]);
        }
        if ($year['status'] === AcademicYearService::CLOSED) {
            throw HttpException::conflict('L\'année scolaire est clôturée : ses périodes ne peuvent plus être modifiées.');
        }

        $type = EstablishmentType::from((string) $this->context->activeEstablishment()['type']);
        if (!PeriodKind::from((string) $merged['kind'])->allowedFor($type)) {
            $this->fail(['kind' => [$type === EstablishmentType::Higher
                ? 'Un établissement d\'enseignement supérieur n\'utilise que des semestres.'
                : 'Les semestres sont réservés à l\'enseignement supérieur.']]);
        }

        if ($merged['starts_on'] < $year['starts_on'] || $merged['ends_on'] > $year['ends_on'] || $merged['ends_on'] < $merged['starts_on']) {
            $this->fail(['starts_on' => ['La période doit être comprise dans l\'année scolaire (du ' . $year['starts_on'] . ' au ' . $year['ends_on'] . ').']]);
        }

        $parentId = $merged['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = $this->repositories->request('periods')->find((string) $parentId);
            if ($parent === null || $parent['academic_year_id'] !== $merged['academic_year_id'] || $parent['kind'] !== 'trimester'
                || $merged['kind'] !== 'sequence' || $merged['starts_on'] < $parent['starts_on'] || $merged['ends_on'] > $parent['ends_on']) {
                $this->fail(['parent_id' => ['Une séquence doit appartenir à un trimestre de la même année et rester dans ses dates.']]);
            }
        } elseif ($merged['kind'] === 'sequence') {
            $this->fail(['parent_id' => ['Une séquence doit être rattachée à un trimestre.']]);
        }

        return $changes;
    }

}
