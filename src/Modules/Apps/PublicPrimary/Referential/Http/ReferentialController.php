<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Referential\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\UnitInfo;
use Scholaris\Modules\Apps\PublicPrimary\Referential\Application\ReferentialService;
use Scholaris\Modules\Apps\PublicPrimary\Referential\Infrastructure\PdoReferential;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryScope;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;

/**
 * Référentiel national (EX-EVA-01), unités d'apprentissage (EX-EVA-02) et paramètres effectifs de la Primaire publique. Lecture : tout
 * acteur de l'évaluation ou du passage. Écriture : `public_primary.referential.manage` seulement (Inspection générale R04,
 * administrateur fonctionnel R21 : RG-TER-6, l'école ne modifie pas le référentiel).
 */
final class ReferentialController
{
    public const MANAGE = 'public_primary.referential.manage';
    public const READERS = [
        self::MANAGE, 'public_primary.evaluation.write', 'public_primary.evaluation.validate', 'public_primary.promotion.write',
        'public_primary.promotion.validate', 'public_primary.promotion.opinion',
    ];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PrimaryScope $scope,
        private readonly ReferentialService $service,
        private readonly PdoReferential $repository,
        private readonly Rules $rules,
    ) {
    }

    public function frames(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->reader($request);
        $subsystem = Input::query($request, 'subsystem');
        $level = Input::query($request, 'level_code');

        return $this->responder->json(Input::page(
            $request,
            fn (?string $after, int $limit): array => $this->repository->listFrames($principal->tenantId, $subsystem, $level, $after, $limit),
            fn (array $row): array => ReferentialService::frameResource($this->repository->frame($principal->tenantId, (string) $row['id']) ?? throw HttpException::notFound()),
        ));
    }

    public function frame(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->reader($request);
        $frame = $this->repository->frame($principal->tenantId, Input::attribute($request, 'id')) ?? throw HttpException::notFound();

        return $this->responder->json(['data' => ReferentialService::frameResource($frame)]);
    }

    public function createFrame(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->manager($request);

        return $this->responder->json(['data' => ReferentialService::frameResource($this->service->createFrame($principal, Input::body($request)))], 201);
    }

    public function importExample(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->manager($request);
        $body = Input::body($request);
        $errors = [];
        $subsystem = $body['subsystem'] ?? null;
        if (!is_string($subsystem) || !in_array($subsystem, ReferentialService::SUBSYSTEMS, true)) {
            $errors['subsystem'][] = 'Valeur attendue : ' . implode(', ', ReferentialService::SUBSYSTEMS) . '.';
        }
        $level = Input::text($body['level_code'] ?? null, 16, 'level_code', $errors, true);
        $validFrom = $body['valid_from'] ?? null;
        if ($validFrom !== null && !Input::isDate($validFrom)) {
            $errors['valid_from'][] = 'Date AAAA-MM-JJ attendue.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $frames = $this->service->importExample($principal, (string) $subsystem, (string) $level, is_string($validFrom) ? $validFrom : null);

        return $this->responder->json(['data' => array_map(static fn ($frame): array => ReferentialService::frameResource($frame), $frames)], 201);
    }

    public function units(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->reader($request);
        $year = Input::requiredQuery($request, 'year_label', 'Année scolaire AAAA-AAAA requise.');
        $level = Input::requiredQuery($request, 'level_code', 'Niveau requis.');
        $units = $this->repository->unitsOf($principal->tenantId, $year, $level);

        return $this->responder->json(['data' => array_map(static fn (UnitInfo $unit): array => ReferentialService::unitResource($unit), $units)]);
    }

    public function saveUnit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->manager($request);

        return $this->responder->json(['data' => ReferentialService::unitResource($this->service->saveUnit($principal, Input::body($request)))], 201);
    }

    public function generateUnits(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->manager($request);
        $units = $this->service->generateUnits($principal, Input::establishment($request), Input::body($request));

        return $this->responder->json(['data' => array_map(static fn (UnitInfo $unit): array => ReferentialService::unitResource($unit), $units)], 201);
    }

    /** Paramètres effectifs de la Primaire publique, avec valeur de départ, statut (officiel, CDC, à confirmer) et source. */
    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->reader($request);

        return $this->responder->json(['data' => $this->rules->describe($principal->tenantId, Input::establishment($request))]);
    }

    private function reader(ServerRequestInterface $request): Principal
    {
        $principal = Input::principal($request);
        if ($this->scope->first($principal, Input::establishment($request), ...self::READERS)->isEmpty()) {
            throw HttpException::notFound();
        }

        return $principal;
    }

    private function manager(ServerRequestInterface $request): Principal
    {
        $principal = Input::principal($request);
        if ($this->scope->reach($principal, self::MANAGE, Input::establishment($request))->isEmpty()) {
            throw HttpException::forbidden('Le référentiel national se règle au niveau national (RG-TER-6) : permission ' . self::MANAGE . ' requise.');
        }

        return $principal;
    }
}
