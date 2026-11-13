<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Messages\Infrastructure\PdoMessageBatches;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Messaging\Contracts\TerritorialAnnouncer;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Annonces de l'inspection ou de la délégation à toutes les écoles d'un territoire (T640, EX-COM-05, NOT-11 à NOT-13). Le socle publie
 * l'annonce dans chaque établissement rattaché au territoire (descendants compris) et dans aucun autre ; ici on contrôle que le
 * territoire visé est celui de l'affectation de l'auteur ou l'un de ses descendants (RG-TER-2), puis on journalise l'annonce.
 */
final class AnnouncementService
{
    public function __construct(
        private readonly TerritorialAnnouncer $announcer,
        private readonly TerritoryDirectory $territories,
        private readonly PdoMessageBatches $log,
        private readonly AuditLog $audit,
        private readonly MessageClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input territory_id, title, body, expires_at
     * @return array{id: string, territory_id: string, title: string, establishments_count: int}
     */
    public function announce(Principal $principal, Perimeter $perimeter, array $input): array
    {
        $tenantId = $principal->tenantId;
        $territoryId = is_string($input['territory_id'] ?? null) ? trim($input['territory_id']) : '';
        if ($territoryId === '') {
            throw HttpException::validation(['territory_id' => ['Le territoire visé est requis.']]);
        }
        $expires = $input['expires_at'] ?? null;
        if ($expires !== null && (!is_string($expires) || strtotime($expires) === false)) {
            throw HttpException::validation(['expires_at' => ['Date ISO 8601 attendue.']]);
        }
        $now = $this->clock->now();
        if ($this->territories->find($tenantId, $territoryId, $now) === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        $lineage = array_map(static fn ($node): string => $node->id, $this->territories->lineage($tenantId, $territoryId, $now));
        if (!$perimeter->isTerritorial() || array_intersect($lineage, $perimeter->territoryIds) === []) {
            $this->audit->append($tenantId, $principal->userId, 'public_schools.scope.denied', 'territory_announcement', $territoryId, null, ['establishment_id' => $perimeter->establishmentId]);
            throw HttpException::forbidden('Cette annonce sort de votre territoire.');
        }

        $title = is_string($input['title'] ?? null) ? $input['title'] : '';
        $body = is_string($input['body'] ?? null) ? $input['body'] : '';
        $establishments = $this->announcer->announce($tenantId, $territoryId, $title, $body, $principal->userId, is_string($expires) ? $expires : null);
        $id = $this->log->insertAnnouncement($tenantId, $territoryId, trim($title), count($establishments), $principal->userId, $now);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.announcement.published', 'territory_announcement', $id, null, [
            'territory_id' => $territoryId, 'establishments' => count($establishments),
        ]);

        return ['id' => $id, 'territory_id' => $territoryId, 'title' => trim($title), 'establishments_count' => count($establishments)];
    }
}
