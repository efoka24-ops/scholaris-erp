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

/** Périodes (année, trimestres, séquences, semestres) d'une année scolaire de l'établissement actif. */
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
        $kind = PeriodKind::from((string) $merged['kind']);
        if (!$kind->allowedFor($type)) {
            $this->fail(['kind' => [$type === EstablishmentType::Higher
                ? 'Un établissement d\'enseignement supérieur n\'utilise que des semestres.'
                : 'Les semestres sont réservés à l\'enseignement supérieur.']]);
        }

        if ($merged['starts_on'] < $year['starts_on'] || $merged['ends_on'] > $year['ends_on'] || $merged['ends_on'] < $merged['starts_on']) {
            $this->fail(['starts_on' => ['La période doit être comprise dans l\'année scolaire (du ' . $year['starts_on'] . ' au ' . $year['ends_on'] . ').']]);
        }

        $siblings = $this->repositories->request('periods')->all(['academic_year_id' => (string) $merged['academic_year_id']]);
        $selfId = $existing === null ? null : (string) $existing['id'];
        if ($kind === PeriodKind::Year) {
            foreach ($siblings as $other) {
                if ($other['kind'] === PeriodKind::Year->value && (string) $other['id'] !== $selfId) {
                    $this->fail(['kind' => ['Une seule période « année » par année scolaire.']]);
                }
            }
        }

        $this->checkParent($kind, $merged);
        if ($selfId !== null) {
            $this->checkChildren($kind, $merged, $selfId, $siblings);
        }

        return $changes;
    }

    /**
     * Rattachement : séquence → trimestre (obligatoire), trimestre → année (facultatif), année et semestre sans parent.
     *
     * @param array<string, mixed> $merged
     */
    private function checkParent(PeriodKind $kind, array $merged): void
    {
        $parentId = $merged['parent_id'] ?? null;
        if ($parentId === null) {
            if ($kind->requiresParent()) {
                $this->fail(['parent_id' => ['Une séquence doit être rattachée à un trimestre.']]);
            }

            return;
        }
        $parentKind = $kind->parentKind();
        $parent = $this->repositories->request('periods')->find((string) $parentId);
        if ($parentKind === null || $parent === null || $parent['academic_year_id'] !== $merged['academic_year_id'] || $parent['kind'] !== $parentKind->value
            || $merged['starts_on'] < $parent['starts_on'] || $merged['ends_on'] > $parent['ends_on']) {
            $this->fail(['parent_id' => [match ($kind) {
                PeriodKind::Trimester => 'Un trimestre ne se rattache qu\'à la période « année » de la même année scolaire et reste dans ses dates.',
                PeriodKind::Year, PeriodKind::Semester => 'Cette période ne se rattache à aucune autre période.',
                PeriodKind::Sequence => 'Une séquence doit appartenir à un trimestre de la même année et rester dans ses dates.',
            }]]);
        }
    }

    /**
     * Une période déjà parente garde sa nature et contient toujours ses périodes rattachées.
     *
     * @param array<string, mixed> $merged
     * @param list<array<string, mixed>> $siblings périodes de la même année scolaire
     */
    private function checkChildren(PeriodKind $kind, array $merged, string $selfId, array $siblings): void
    {
        foreach ($siblings as $child) {
            if (!isset($child['parent_id']) || (string) $child['parent_id'] !== $selfId) {
                continue;
            }
            if (PeriodKind::from((string) $child['kind'])->parentKind() !== $kind) {
                $this->fail(['kind' => ['Cette période a des périodes rattachées : sa nature ne peut plus changer.']]);
            }
            if ((string) $child['starts_on'] < $merged['starts_on'] || (string) $child['ends_on'] > $merged['ends_on']) {
                $this->fail(['starts_on' => ['Les périodes rattachées doivent rester comprises dans les dates de cette période.']]);
            }
        }
    }
}
