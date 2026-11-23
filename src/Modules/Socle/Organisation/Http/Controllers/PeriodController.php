<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Organisation\Application\AcademicYearService;
use Scholaris\Modules\Socle\Organisation\Application\OrgClock;
use Scholaris\Modules\Socle\Organisation\Application\PeriodUsageProviders;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUse;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\PeriodKind;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Http\Requests\PeriodRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\PeriodResource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/**
 * Périodes (année, trimestres, séquences, semestres) d'une année scolaire de l'établissement actif.
 * Règles : une période reste dans son année (et dans sa période parente) ; deux périodes de même nature d'une même année ne se
 * chevauchent pas (les natures différentes s'imbriquent : séquences dans un trimestre, trimestres dans l'année) ; une période
 * utilisée par un module (contrat PeriodUsage) ne se supprime pas et ne laisse pas sortir ses éléments datés.
 */
final class PeriodController extends ScopedCrudController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        Repositories $repositories,
        OrganisationPolicy $policy,
        OrgClock $clock,
        private readonly PeriodUsageProviders $usages,
    ) {
        parent::__construct($responder, $context, $repositories, $policy, $clock);
    }

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

    /**
     * Suppression d'une période libre de l'établissement actif (organisation.manage). Refus 409 si l'année est clôturée, si des
     * périodes y sont rattachées, ou si un module l'utilise déjà (épreuves, notes, conseils, délibérations, bulletins…).
     */
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, true);
        $id = $this->id($request);
        $period = $this->repository()->findOrFail($id);
        $year = $this->repositories->request('academic_years')->find((string) $period['academic_year_id']);
        if ($year !== null && $year['status'] === AcademicYearService::CLOSED) {
            throw HttpException::conflict('L\'année scolaire est clôturée : ses périodes ne peuvent plus être supprimées.');
        }
        $name = self::name($period);
        foreach ($this->repository()->all(['academic_year_id' => (string) $period['academic_year_id']]) as $child) {
            if (isset($child['parent_id']) && (string) $child['parent_id'] === $id) {
                throw HttpException::conflict('La période « ' . $name . ' » a des périodes rattachées (' . self::name($child) . '…) : supprimez-les ou rattachez-les ailleurs d\'abord.');
            }
        }
        $uses = $this->usages->uses($this->context->tenantId(), $this->context->establishmentId(), $id);
        if ($uses !== []) {
            throw HttpException::conflict('La période « ' . $name . ' » est déjà utilisée (' . self::summary($uses) . ') : elle ne peut pas être supprimée. Modifiez plutôt ses dates.');
        }

        $this->repository()->delete($id);
        $this->context->audit($request, 'period.deleted', 'period', $id, $this->resource()->toArray($period), null);

        return $this->responder->json(['data' => ['id' => $id, 'deleted' => true]]);
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

        if ($merged['ends_on'] < $merged['starts_on']) {
            $this->fail(['ends_on' => ['La date de fin ne peut pas précéder la date de début.']]);
        }
        if ($merged['starts_on'] < $year['starts_on'] || $merged['ends_on'] > $year['ends_on']) {
            $field = $merged['starts_on'] < $year['starts_on'] ? 'starts_on' : 'ends_on';
            $this->fail([$field => ['La période doit être comprise dans l\'année scolaire ' . $year['label'] . ' (du ' . self::day((string) $year['starts_on']) . ' au ' . self::day((string) $year['ends_on']) . ').']]);
        }

        $siblings = $this->repositories->request('periods')->all(['academic_year_id' => (string) $merged['academic_year_id']]);
        $selfId = $existing === null ? null : (string) $existing['id'];
        foreach ($siblings as $other) {
            if ((string) $other['id'] === $selfId || $other['kind'] !== $kind->value) {
                continue;
            }
            if ($kind === PeriodKind::Year) {
                $this->fail(['kind' => ['Une seule période « année » par année scolaire.']]);
            }
            if ((int) $other['ordinal'] === (int) $merged['ordinal']) {
                throw HttpException::conflict('Le numéro ' . $merged['ordinal'] . ' est déjà pris par « ' . self::name($other) . ' » dans cette année scolaire.');
            }
            if ($merged['starts_on'] <= $other['ends_on'] && $other['starts_on'] <= $merged['ends_on']) {
                $this->fail(['starts_on' => ['Ces dates chevauchent « ' . self::name($other) . ' » (du ' . self::day((string) $other['starts_on']) . ' au ' . self::day((string) $other['ends_on']) . ') : modifiez d\'abord cette période.']]);
            }
        }

        $this->checkParent($kind, $merged);
        if ($selfId !== null) {
            $this->checkChildren($kind, $merged, $selfId, $siblings);
            $this->checkDatedUses($merged, $existing);
        }

        return $changes;
    }

    /** Signale, après un changement de dates, que la période contient déjà des données (épreuves, notes…). */
    protected function updateMeta(array $existing, array $row): array
    {
        if ($existing['starts_on'] === $row['starts_on'] && $existing['ends_on'] === $row['ends_on']) {
            return [];
        }
        $uses = $this->usages->uses($this->context->tenantId(), $this->context->establishmentId(), (string) $row['id']);

        return $uses === [] ? [] : ['warnings' => ['Cette période est déjà utilisée (' . self::summary($uses) . ') : ses nouvelles dates s\'appliquent aussi à ces données.']];
    }

    /**
     * Une période utilisée ne peut pas laisser hors de ses nouvelles dates les éléments datés qu'elle contient.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $existing
     */
    private function checkDatedUses(array $merged, array $existing): void
    {
        if ($merged['starts_on'] === $existing['starts_on'] && $merged['ends_on'] === $existing['ends_on']) {
            return;
        }
        foreach ($this->usages->uses($this->context->tenantId(), $this->context->establishmentId(), (string) $existing['id']) as $use) {
            if ($use->firstOn !== null && $use->firstOn < $merged['starts_on']) {
                $this->fail(['starts_on' => [$this->outside($use)]]);
            }
            if ($use->lastOn !== null && $use->lastOn > $merged['ends_on']) {
                $this->fail(['ends_on' => [$this->outside($use)]]);
            }
        }
    }

    private function outside(PeriodUse $use): string
    {
        return 'La période contient ' . $use->describe() . ' datée(s) du ' . self::day((string) $use->firstOn) . ' au ' . self::day((string) $use->lastOn)
            . ' : ces dates les feraient sortir de la période.';
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
                $this->fail(['starts_on' => ['Les périodes rattachées doivent rester comprises dans les dates de cette période (« ' . self::name($child) . ' » du '
                    . self::day((string) $child['starts_on']) . ' au ' . self::day((string) $child['ends_on']) . ').']]);
            }
        }
    }

    /** @param array<string, mixed> $period */
    private static function name(array $period): string
    {
        return PeriodKind::from((string) $period['kind'])->label((int) $period['ordinal']);
    }

    /** @param list<PeriodUse> $uses */
    private static function summary(array $uses): string
    {
        return implode(', ', array_map(static fn (PeriodUse $use): string => $use->describe(), $uses));
    }

    /** AAAA-MM-JJ → JJ/MM/AAAA. */
    public static function day(string $iso): string
    {
        $parts = explode('-', substr($iso, 0, 10));

        return count($parts) === 3 ? $parts[2] . '/' . $parts[1] . '/' . $parts[0] : $iso;
    }
}
