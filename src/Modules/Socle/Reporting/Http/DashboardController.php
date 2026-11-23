<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Reporting\Application\IndicatorService;
use Scholaris\Modules\Socle\Reporting\Application\ProviderRegistry;
use Scholaris\Modules\Socle\Reporting\Domain\DashboardKind;

/**
 * GET /dashboards/{kind}. Route sans établissement imposé (option tenant=false) : la vue groupe n'en a pas, les autres
 * lisent X-Establishment-Id et le contrôlent ici comme le fait le noyau.
 */
final class DashboardController
{
    public const READ = 'reporting.read';
    public const GROUP_READ = 'reporting.group.read';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentAccess $access,
        private readonly EstablishmentDirectory $establishments,
        private readonly IndicatorService $indicators,
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $kind = DashboardKind::tryFrom((string) $request->getAttribute('kind'));
        $definition = $kind === null ? $this->registry->dashboard((string) $request->getAttribute('kind')) : null;
        if ($kind === null && $definition === null) {
            throw HttpException::notFound('Tableau de bord inconnu : direction, censeur ou groupe.');
        }

        if ($definition === null && $kind === DashboardKind::Groupe) {
            if (!$this->permissions->can($principal, self::GROUP_READ, null)) {
                throw HttpException::forbidden('La vue consolidée est réservée à la direction de groupe.');
            }

            return $this->responder->json(['data' => $this->indicators->build($kind, $principal->tenantId, null)]);
        }

        $establishmentId = trim($request->getHeaderLine('X-Establishment-Id'));
        if ($establishmentId === '') {
            throw HttpException::badRequest('L\'en-tête X-Establishment-Id est obligatoire.');
        }
        if (!$this->access->canAccess($principal, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas accès à cet établissement.');
        }
        if (!$this->permissions->can($principal, $definition?->permission() ?? self::READ, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les tableaux de bord de cet établissement.');
        }
        if ($this->establishments->find($principal->tenantId, $establishmentId) === null) {
            throw HttpException::notFound('Établissement introuvable.');
        }

        if ($definition !== null) {
            // Tableau de bord d'application (S21) : école interne facultative (org_unit_id).
            $unit = $request->getQueryParams()['org_unit_id'] ?? null;
            if ($unit !== null && (!is_string($unit) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $unit) !== 1)) {
                throw HttpException::validation(['org_unit_id' => ['Identifiant invalide.']]);
            }

            return $this->responder->json(['data' => $this->indicators->buildDefinition($definition, $principal->tenantId, $establishmentId, $unit)]);
        }

        return $this->responder->json(['data' => $this->indicators->build($kind, $principal->tenantId, $establishmentId)]);
    }
}
