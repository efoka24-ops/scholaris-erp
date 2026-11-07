<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Domain\Majority;

final class PdoBirthInfo implements BirthInfo
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function birthDate(string $tenantId, string $personId): ?string
    {
        $rows = $this->store->rows('SELECT birth_date FROM people WHERE tenant_id = ? AND id = ?', [$tenantId, $personId]);
        $value = $rows[0]['birth_date'] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function isAdult(string $tenantId, string $personId, string $atDate): ?bool
    {
        $birth = $this->birthDate($tenantId, $personId);

        return $birth === null ? null : Majority::isAdult($birth, $atDate);
    }
}
