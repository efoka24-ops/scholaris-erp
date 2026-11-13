<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceAlerts;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;

/**
 * Fil des alertes d'assiduité d'une école (NOT-03, NOT-12, NOT-13). Le directeur voit tout ; l'inspection ne voit que les alertes qui
 * lui sont adressées (classe sans appel ou sans enseignant), jamais le nom d'un élève (RG-TER-2) : l'alerte d'absences répétées
 * est réservée à l'école.
 */
final class AlertController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly PdoAttendanceAlerts $alerts,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.attendance.read', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }

        if (!$perimeter->wholeSchool && !$perimeter->isTerritorial()) {
            return $this->responder->json(['data' => [], 'meta' => ['next_cursor' => null, 'per_page' => 0]]);
        }
        $kinds = [PdoAttendanceAlerts::NO_CALL, PdoAttendanceAlerts::UNCOVERED_CLASS];
        $levels = [PdoAttendanceAlerts::INSPECTION];
        if ($perimeter->wholeSchool) {
            $kinds[] = PdoAttendanceAlerts::REPEATED_ABSENCE;
            $levels[] = PdoAttendanceAlerts::DIRECTOR;
        }
        $kind = RequestInput::query($request, 'kind');
        if ($kind !== null) {
            $kinds = in_array($kind, $kinds, true) ? [$kind] : [];
        }
        $page = RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->alerts->list($principal->tenantId, $establishmentId, $kinds, $levels, $after, $limit),
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'kind' => (string) $row['kind'],
                'subject_id' => (string) $row['subject_id'],
                'period_key' => (string) $row['period_key'],
                'level' => (string) $row['level'],
                'recipients' => (int) $row['recipients'],
                'raised_on' => (string) $row['raised_on'],
            ],
        );

        return $this->responder->json($page);
    }
}
