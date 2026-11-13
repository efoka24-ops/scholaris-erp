<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Documents\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/**
 * Révoque la carte DOC-21 d'une autorisation retirée (T727 : « révoquée au retrait »). Classe à part pour que le retrait d'une autorisation
 * (AuthorizedPersonsService) n'ait aucune dépendance vers l'émission des documents. Sans effet s'il n'y a pas de carte valide.
 */
final class CardRevoker
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly DocumentIssuer $issuer,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    public function revokeFor(string $tenantId, string $establishmentId, string $pickupId, string $reason, string $actorId): bool
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT id, document_id FROM ' . PickupDocuments::TABLE . ' WHERE tenant_id = ? AND establishment_id = ? AND kind = ? AND source_id = ? AND status = ?',
        );
        $query->execute([$tenantId, $establishmentId, PickupDocuments::CARD, $pickupId, IssuedDocument::VALID]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $this->issuer->revoke($tenantId, (string) $row['document_id'], $reason, $actorId);
            $this->connections->pdo()->prepare('UPDATE ' . PickupDocuments::TABLE . ' SET status = ?, revoked_at = ?, revoke_reason = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
                ->execute([IssuedDocument::REVOKED, $this->clock->now(), mb_substr($reason, 0, 255), $tenantId, $establishmentId, (string) $row['id']]);
        }

        return $rows !== [];
    }
}
