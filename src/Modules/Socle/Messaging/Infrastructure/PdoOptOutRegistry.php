<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use InvalidArgumentException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Messaging\Application\MessagingClock;
use Scholaris\Modules\Socle\Messaging\Contracts\OptOutRegistry;
use Scholaris\Modules\Socle\Messaging\Domain\Channel;

final class PdoOptOutRegistry implements OptOutRegistry
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly MessagingClock $clock,
    ) {
    }

    public function optOut(string $tenantId, string $personId, string $channel): void
    {
        $this->assertChannel($channel);
        if ($this->isOptedOut($tenantId, $personId, $channel)) {
            return;
        }
        $this->connections->pdo()->prepare('INSERT INTO messaging_opt_outs (tenant_id, person_id, channel, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$tenantId, $personId, $channel, $this->clock->iso()]);
    }

    public function optIn(string $tenantId, string $personId, string $channel): void
    {
        $this->assertChannel($channel);
        $this->connections->pdo()->prepare('DELETE FROM messaging_opt_outs WHERE tenant_id = ? AND person_id = ? AND channel = ?')
            ->execute([$tenantId, $personId, $channel]);
    }

    public function isOptedOut(string $tenantId, string $personId, string $channel): bool
    {
        $statement = $this->connections->pdo()->prepare('SELECT 1 FROM messaging_opt_outs WHERE tenant_id = ? AND person_id = ? AND channel = ? LIMIT 1');
        $statement->execute([$tenantId, $personId, $channel]);

        return $statement->fetchColumn() !== false;
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, Channel::all(), true)) {
            throw new InvalidArgumentException('Canal inconnu.');
        }
    }
}
