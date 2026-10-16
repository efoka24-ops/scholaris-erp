<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;

/**
 * Répertoire des entreprises d'accueil (EX-STG-01) : partagé par l'établissement (une entreprise accueille des étudiants de
 * plusieurs écoles), lisible par qui lit les stages, géré par les responsables des stages et de filière.
 */
final class CompanyService
{
    public const TABLE = 'hi_internship_companies';
    public const FIELDS = ['name', 'sector', 'city', 'address', 'phone', 'email', 'contact_name'];

    public function __construct(
        private readonly InternshipStore $store,
        private readonly InternshipAccess $access,
        private readonly InternshipClock $clock,
    ) {
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, ['id' => $id] + $data + [
            'active' => 1, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'higher.internship.company_created', 'hi_internship_company', $id, null, $data);

        return $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $data): array
    {
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $before = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Entreprise introuvable.');
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $data + ['updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'higher.internship.company_updated', 'hi_internship_company', $id, $before, $data);

        return $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id);
    }
}
