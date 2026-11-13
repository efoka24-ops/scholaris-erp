<?php

declare(strict_types=1);

namespace Tests\Support\Audit;

use Scholaris\Modules\Socle\Audit\Contracts\SubjectDataSource;
use Scholaris\Modules\Socle\Audit\Contracts\TenantDataSource;

/** Source factice d'un autre module, avec données fictives uniquement. */
final class FakeModuleSource implements TenantDataSource, SubjectDataSource
{
    /** @var list<string> */
    public array $anonymized = [];

    public function name(): string
    {
        return 'people';
    }

    public function export(string $tenantId): array
    {
        return ['persons' => [['id' => 'p1', 'tenant' => $tenantId, 'name' => 'Nadia Test']]];
    }

    public function collect(string $tenantId, string $personId): array
    {
        return ['persons' => [['id' => $personId, 'name' => 'Nadia Test']]];
    }

    public function anonymize(string $tenantId, string $personId): int
    {
        $this->anonymized[] = $tenantId . ':' . $personId;

        return 1;
    }
}
