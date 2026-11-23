<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\PersonAccountLookup;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

final class PdoUserPersonLink extends PdoRepository implements UserPersonLink, PersonAccountLookup
{
    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        $row = $this->one('SELECT person_id FROM users WHERE tenant_id = ? AND id = ?', [$tenantId, $userId]);

        return $row === null ? null : (string) $row['person_id'];
    }

    public function hasAccount(string $tenantId, string $personId): bool
    {
        return $this->one('SELECT 1 AS x FROM users WHERE tenant_id = ? AND person_id = ? LIMIT 1', [$tenantId, $personId]) !== null;
    }
}
