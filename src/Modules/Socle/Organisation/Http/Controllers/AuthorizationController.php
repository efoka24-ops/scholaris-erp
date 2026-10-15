<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Organisation\Application\AuthorizationExpiryService;
use Scholaris\Modules\Socle\Organisation\Application\AuthorizationRegistryService;
use Scholaris\Modules\Socle\Organisation\Application\OrgClock;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Http\Requests\AuthorizationRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\AuthorizationResource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/** Registre des autorisations administratives des structures, avec alerte d'expiration. */
final class AuthorizationController extends ScopedCrudController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        Repositories $repositories,
        OrganisationPolicy $policy,
        OrgClock $clock,
        private readonly AuthorizationExpiryService $expiry,
        private readonly AuthorizationRegistryService $registry,
    ) {
        parent::__construct($responder, $context, $repositories, $policy, $clock);
    }

    protected function table(): string
    {
        return 'authorizations';
    }

    protected function objectType(): string
    {
        return 'authorization';
    }

    protected function validator(): ValidatedRequest
    {
        return new AuthorizationRequest();
    }

    protected function resource(): Resource
    {
        return new AuthorizationResource();
    }

    protected function filters(): array
    {
        return ['structure_id'];
    }

    protected function sorts(): array
    {
        return ['valid_to', 'valid_from', 'reference'];
    }

    protected function immutable(): array
    {
        return ['structure_id'];
    }

    protected function check(array $changes, array $merged, ?array $existing): array
    {
        if ($merged['valid_to'] < $merged['valid_from']) {
            $this->fail(['valid_to' => ['La fin de validité ne peut pas précéder le début.']]);
        }
        if ($existing === null && $this->repositories->request('pedagogical_structures')->find((string) $merged['structure_id']) === null) {
            $this->fail(['structure_id' => ['Structure introuvable dans cet établissement.']]);
        }
        if (array_key_exists('covered_levels', $changes)) {
            $levels = $changes['covered_levels'];
            if (is_array($levels)) {
                $errors = $this->registry->coveredLevelErrors($this->context->tenantId(), $this->context->establishmentId(), array_values(array_map('strval', $levels)));
                if ($errors !== []) {
                    $this->fail($errors);
                }
            }
            $changes['covered_levels'] = is_array($levels) ? json_encode(array_values($levels), JSON_THROW_ON_ERROR) : null;
        }

        return $changes;
    }

    /** GET /authorizations/expiring?days=30 */
    public function expiring(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, false);

        $days = AuthorizationExpiryService::DEFAULT_DAYS;
        $raw = $request->getQueryParams()['days'] ?? null;
        if ($raw !== null) {
            $days = filter_var($raw, FILTER_VALIDATE_INT);
            if ($days === false || $days < 1 || $days > 365) {
                $this->fail(['days' => ['Le nombre de jours doit être un entier entre 1 et 365.']]);
            }
        }

        return $this->responder->json([
            'data' => $this->expiry->expiring($this->context->tenantId(), $this->context->establishmentId(), $days),
            'meta' => ['days' => $days],
        ]);
    }
}
