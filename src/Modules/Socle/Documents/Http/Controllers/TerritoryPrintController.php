<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Documents\Application\TerritoryPrintService;
use Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile;
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;

/**
 * GET /document-batches/territory-print?territory_id=&type=&issued_from=&profile= (PP15) : un PDF réunissant les documents
 * valides d'un type émis par les écoles d'un territoire. Route sans établissement imposé ; périmètre contrôlé par le service.
 */
final class TerritoryPrintController extends BaseController
{
    public function __construct(
        HttpKit $kit,
        private readonly TerritoryPrintService $service,
        private readonly AccessibleEstablishments $accessible,
        private readonly PermissionChecker $permissions,
    ) {
        parent::__construct($kit);
    }

    public function print(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        // Refus avant toute validation : la permission doit être acquise dans au moins un établissement accessible.
        $readable = array_filter(
            $this->accessible->idsFor($principal->tenantId, $principal->userId),
            fn (string $id): bool => $this->permissions->can($principal, TerritoryPrintService::READ, $id),
        );
        if ($readable === [] && !$this->permissions->can($principal, TerritoryPrintService::READ, null)) {
            throw HttpException::forbidden('Impression territoriale non autorisée.');
        }
        $query = $request->getQueryParams();
        $errors = [];
        $territory = $query['territory_id'] ?? null;
        if (!is_string($territory) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $territory) !== 1) {
            $errors['territory_id'] = ['Identifiant de territoire obligatoire.'];
        }
        $type = $query['type'] ?? null;
        if (!is_string($type) || preg_match('/^[a-z][a-z0-9_]{1,29}$/', $type) !== 1) {
            $errors['type'] = ['Code de type invalide.'];
        }
        $from = $query['issued_from'] ?? null;
        if ($from !== null && (!is_string($from) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1)) {
            $errors['issued_from'] = ['Date attendue au format AAAA-MM-JJ.'];
        }
        $profile = $query['profile'] ?? LightPdfProfile::LIGHT;
        if (!is_string($profile) || !in_array($profile, LightPdfProfile::PROFILES, true)) {
            $errors['profile'] = ['Valeur attendue : ' . implode(', ', LightPdfProfile::PROFILES) . '.'];
        }
        if ($errors !== [] || !is_string($territory) || !is_string($type) || !is_string($profile)) {
            throw HttpException::validation($errors);
        }

        $result = $this->service->print($principal, $territory, $type, is_string($from) ? $from : null, $profile);

        return $this->binary($result['pdf'], 'application/pdf', 'impression-' . $territory . '-' . $type . '.pdf')
            ->withHeader('X-Document-Count', (string) $result['count']);
    }
}
