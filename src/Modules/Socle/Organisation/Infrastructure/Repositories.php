<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use InvalidArgumentException;

/** Accès aux dépôts des tables du module, dans le contexte de la requête ou pour un client et un établissement précis. */
final class Repositories
{
    /** table => porte establishment_id */
    private const TABLES = [
        'establishments' => false,
        'org_units' => true,
        'academic_years' => true,
        'periods' => true,
        'pedagogical_structures' => true,
        'authorizations' => true,
        'rooms' => true,
    ];

    public function __construct(private readonly TableFactory $tables)
    {
    }

    public function request(string $table): ResourceRepository
    {
        return new ResourceRepository($this->tables->request($table, $this->byEstablishment($table)));
    }

    public function explicit(string $table, string $tenantId, ?string $establishmentId = null): ResourceRepository
    {
        return new ResourceRepository($this->tables->explicit($tenantId, $establishmentId, $table, $this->byEstablishment($table)));
    }

    private function byEstablishment(string $table): bool
    {
        return self::TABLES[$table] ?? throw new InvalidArgumentException('Table inconnue : ' . $table);
    }
}
