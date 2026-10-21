<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\ClassProfileService;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

final class ClassController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly ScopedByTerritory $scope,
        private readonly ClassRoster $classes,
        private readonly ClassProfileService $profiles,
        private readonly SettingsReader $settings,
    ) {
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishment = self::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.pupils.write', $establishment);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : public_schools.pupils.write.');
        }
        $body = self::body($request);
        $created = $this->profiles->create($principal->tenantId, $establishment, $principal->userId, $body);

        return $this->responder->json(['data' => $created], 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishment = self::attribute($request, 'establishment_id');
        if (!$this->permissions->can($principal, 'public_schools.pupils.read', $establishment)) {
            throw HttpException::notFound();
        }
        $yearId = $request->getQueryParams()['academic_year_id'] ?? null;
        if (!is_string($yearId) || trim($yearId) === '') {
            throw HttpException::validation(['academic_year_id' => ['Champ requis.']]);
        }
        $perimeter = $this->scope->perimeter($principal, 'public_schools.pupils.read', $establishment);
        $threshold = $this->settings->value($principal->tenantId, $establishment, 'public_schools.class_size.alert', 60);
        $threshold = is_int($threshold) && $threshold > 0 ? $threshold : 60;
        $rows = [];
        foreach ($this->classes->classesOf($principal->tenantId, $establishment, $yearId) as $class) {
            if (!$this->scope->covers($perimeter, $class->structureId)) {
                continue;
            }
            $count = $this->classes->headcount($principal->tenantId, $establishment, $class->structureId, date('Y-m-d'));
            $rows[] = self::resource($class) + ['headcount' => $count, 'capacity_alert' => $count > $threshold];
        }

        return $this->responder->json(['data' => $rows]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishment = self::attribute($request, 'establishment_id');
        $structureId = self::attribute($request, 'id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.pupils.read', $establishment);
        $this->scope->assertReadable($perimeter, $structureId);
        $class = $this->classes->find($principal->tenantId, $establishment, $structureId);
        if ($class === null) {
            throw HttpException::notFound('Classe introuvable.');
        }

        return $this->responder->json(['data' => self::resource($class)]);
    }

    /** @return array<string, mixed> */
    private static function resource(\Scholaris\Modules\Apps\PublicSchools\Contracts\ClassInfo $class): array
    {
        return [
            'structure_id' => $class->structureId,
            'establishment_id' => $class->establishmentId,
            'label' => $class->label,
            'level_codes' => $class->levelCodes,
            'shift' => $class->shift,
            'homeroom_staff_id' => $class->homeroomStaffId,
        ];
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            throw HttpException::validation(['body' => ['JSON attendu.']]);
        }

        return $body;
    }

    private static function attribute(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : throw HttpException::badRequest('Paramètre manquant.');
    }
}
