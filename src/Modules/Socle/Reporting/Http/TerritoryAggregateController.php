<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Reporting\Application\ReportingClock;
use Scholaris\Modules\Socle\Reporting\Application\TerritoryAggregateReader;

/**
 * GET /dashboards/territories/{territory} (PP4) : agrégats masqués d'un territoire. Route sans établissement imposé : un rôle
 * de niveau client lit tout territoire du client ; un rôle territorial ou d'établissement ne lit un territoire que si la
 * permission lui est acquise dans chacun de ses établissements (le territoire est inclus dans son périmètre).
 */
final class TerritoryAggregateController
{
    public const READ = 'reporting.territory_aggregates.read';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly TerritoryDirectory $territories,
        private readonly TerritoryAggregateReader $reader,
        private readonly ReportingClock $clock,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $territoryId = (string) $request->getAttribute('territory');
        $date = $request->getQueryParams()['date'] ?? null;
        if ($date !== null && (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1)) {
            throw HttpException::validation(['date' => ['Date attendue au format AAAA-MM-JJ.']]);
        }
        $at = $this->clock->iso();
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $territoryId) !== 1 || $this->territories->find($principal->tenantId, $territoryId, $at) === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        if (!$this->allowed($principal, $territoryId, $at)) {
            throw HttpException::forbidden('Ce territoire est hors de votre périmètre.');
        }

        return $this->responder->json(['data' => $this->reader->read($principal->tenantId, $territoryId, $date)]);
    }

    private function allowed(Principal $principal, string $territoryId, string $at): bool
    {
        if ($this->permissions->can($principal, self::READ, null)) {
            return true;
        }
        $establishments = $this->territories->establishmentsIn($principal->tenantId, $territoryId, $at);
        foreach ($establishments as $establishmentId) {
            if (!$this->permissions->can($principal, self::READ, $establishmentId)) {
                return false;
            }
        }

        return $establishments !== [];
    }
}
