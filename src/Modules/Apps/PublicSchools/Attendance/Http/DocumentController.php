<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AttendanceDocuments;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;

/**
 * Émission des PDF DOC-06 (cahier d'appel mensuel) et DOC-22 (synthèse de présence des enseignants) par le directeur de l'école ;
 * le PDF se télécharge ensuite par le module Documents (`GET /documents/{id}/preview`, permission documents.read du directeur).
 */
final class DocumentController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly AttendanceDocuments $documents,
    ) {
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->director($principal, $establishmentId, 'public_schools.attendance.write');
        [$month, $locale] = self::input($request);

        return $this->responder->json(['data' => $this->documents->register($principal, $establishmentId, RequestInput::attribute($request, 'id'), $month, $locale)], 201);
    }

    public function presence(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->director($principal, $establishmentId, 'public_schools.teacher_presence.write');
        [$month, $locale] = self::input($request);

        return $this->responder->json(['data' => $this->documents->presence($principal, $establishmentId, $month, $locale)], 201);
    }

    private function director(\Scholaris\Core\Security\Principal $principal, string $establishmentId, string $permission): void
    {
        if (!$this->scope->perimeter($principal, $permission, $establishmentId)->wholeSchool) {
            $this->scope->assertWritable($principal, Perimeter::none($principal->tenantId, $establishmentId, $permission), null, 'attendance_document', $establishmentId);
        }
    }

    /** @return array{0: string, 1: string} */
    private static function input(ServerRequestInterface $request): array
    {
        $body = RequestInput::body($request);
        $month = $body['month'] ?? null;
        if (!is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::validation(['month' => ['Mois au format AAAA-MM requis.']]);
        }
        $locale = ($body['locale'] ?? 'fr') === 'en' ? 'en' : 'fr';

        return [$month, $locale];
    }
}
