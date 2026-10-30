<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Internships\Application\CompanyService;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipAccess;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipResources;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;

/** Répertoire des entreprises d'accueil (EX-STG-01). */
final class CompanyController
{
    private const FILTERS = ['active', 'city', 'sector'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly InternshipAccess $access,
        private readonly InternshipStore $store,
        private readonly CompanyService $companies,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::READ);

        $page = Listing::of($request, $this->store, CompanyService::TABLE, $principal->tenantId, $establishmentId, self::FILTERS, 'name, id', ['1 = 1', []], InternshipResources::company(...));

        return $this->responder->json($page);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $data = self::fields(Body::of($request), true);

        return $this->responder->json(['data' => InternshipResources::company($this->companies->create($principal, $establishmentId, $data))], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $body = Body::of($request);
        $active = $body->has('active') ? $body->bool('active') : null;
        $data = self::fields($body, false);
        if ($active !== null) {
            $data['active'] = $active ? 1 : 0;
        }

        return $this->responder->json(['data' => InternshipResources::company($this->companies->update($principal, $establishmentId, Listing::arg($request, 'id'), $data))]);
    }

    /** @return array<string, scalar|null> */
    private static function fields(Body $body, bool $creating): array
    {
        $data = [];
        foreach (CompanyService::FIELDS as $field) {
            $required = $creating && $field === 'name';
            if ($required || $body->has($field)) {
                $data[$field] = $body->string($field, $field === 'address' ? 255 : 160, $required);
            }
        }
        if (isset($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $body->error('email', 'Adresse électronique invalide.');
        }
        $body->done();

        return $data;
    }
}
