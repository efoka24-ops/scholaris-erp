<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Console;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

/** Purge des médias du cahier de vie : supprime le fichier et sa ligne à la date d'échéance. */
final class PurgeMediaCommand
{
    public const NAME = 'public-nursery:purge-media';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly MediaStore $mediaStore,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $today = new \DateTimeImmutable('today');
        foreach ($args as $arg) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) === 1) {
                $today = new \DateTimeImmutable($arg);
            } else {
                fwrite(STDERR, "Usage : public-nursery:purge-media [AAAA-MM-JJ]\n");

                return 2;
            }
        }

        $threshold = $today->format('Y-m-d');
        $statement = $this->connections->pdo()->prepare(
            'SELECT id, tenant_id, media_ref FROM pn_lifebook_media WHERE purge_after <= ? ORDER BY tenant_id, id',
        );
        $statement->execute([$threshold]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $purged = 0;
        $pdo = $this->connections->pdo();
        foreach ($rows as $row) {
            $tenantId = (string) $row['tenant_id'];
            $mediaRef = new MediaRef((string) $row['media_ref']);
            $pdo->prepare('DELETE FROM pn_lifebook_media_children WHERE tenant_id = ? AND media_id = ?')->execute([$tenantId, $row['id']]);
            $pdo->prepare('DELETE FROM pn_lifebook_media WHERE tenant_id = ? AND id = ?')->execute([$tenantId, $row['id']]);
            $this->mediaStore->delete($tenantId, $mediaRef);
            ++$purged;
        }

        echo sprintf("Purge des médias : %d ligne(s) supprimée(s) à la date %s.\n", $purged, $threshold);

        return 0;
    }
}
