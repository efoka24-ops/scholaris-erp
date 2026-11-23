<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Domain\Instant;

/**
 * Rattachement daté d'établissements à un territoire (PP1, RG-TER-4) : l'ancien lien est clos à la date d'effet,
 * jamais réécrit ; répéter un rattachement déjà ouvert ne change rien.
 */
final class EstablishmentTerritoryService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly TerritoryDirectory $territories,
    ) {
    }

    /**
     * @param list<string> $establishmentIds
     * @return array{territory_id: string, valid_from: string, establishment_ids: list<string>}
     */
    public function assign(string $tenantId, string $territoryId, array $establishmentIds, string $validFrom): array
    {
        $from = Instant::utc($validFrom);
        if ($this->territories->find($tenantId, $territoryId, $from) === null) {
            throw HttpException::notFound('Territoire introuvable à cette date.');
        }
        $ids = array_values(array_unique($establishmentIds));
        sort($ids);
        $this->assertEstablishmentsExist($tenantId, $ids);

        $pdo = $this->connections->pdo();
        $now = (new \DateTimeImmutable('now'))->format(DATE_ATOM);
        $pdo->beginTransaction();
        try {
            foreach ($ids as $establishmentId) {
                $this->link($pdo, $tenantId, $territoryId, $establishmentId, $from, $now);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        return ['territory_id' => $territoryId, 'valid_from' => $from, 'establishment_ids' => $ids];
    }

    private function link(PDO $pdo, string $tenantId, string $territoryId, string $establishmentId, string $from, string $now): void
    {
        $statement = $pdo->prepare(
            'SELECT id, territory_id, valid_from, valid_to FROM organisation_establishment_territories '
            . 'WHERE tenant_id = ? AND establishment_id = ? ORDER BY valid_from DESC LIMIT 1',
        );
        $statement->execute([$tenantId, $establishmentId]);
        $latest = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($latest)) {
            if ($latest['valid_to'] === null) {
                if ((string) $latest['territory_id'] === $territoryId) {
                    return;
                }
                if ($from <= (string) $latest['valid_from']) {
                    throw HttpException::conflict('Le rattachement ne peut pas réécrire le passé : la date d\'effet doit suivre le lien actuel.');
                }
                $pdo->prepare('UPDATE organisation_establishment_territories SET valid_to = ? WHERE tenant_id = ? AND id = ? AND valid_to IS NULL')
                    ->execute([$from, $tenantId, $latest['id']]);
            } elseif ($from < (string) $latest['valid_to']) {
                throw HttpException::conflict('Le rattachement chevauche une période déjà clôturée.');
            }
        }

        $pdo->prepare(
            'INSERT INTO organisation_establishment_territories (id, tenant_id, establishment_id, territory_id, valid_from, valid_to, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, NULL, ?)',
        )->execute([(string) new Ulid(), $tenantId, $establishmentId, $territoryId, $from, $now]);
    }

    /** @param list<string> $ids */
    private function assertEstablishmentsExist(string $tenantId, array $ids): void
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT id FROM establishments WHERE tenant_id = ? AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
        );
        $statement->execute([$tenantId, ...$ids]);
        $found = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($ids, $found) !== []) {
            throw HttpException::notFound('Établissement introuvable.');
        }
    }
}
