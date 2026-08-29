<?php

declare(strict_types=1);

namespace Scholaris\Platform;

use Scholaris\Support\Cameroon;

/**
 * Perimetre d'un compte de pilotage.
 *
 * L'habilitation reposait sur deux niveaux — un role, une permission — et sur
 * l'appartenance a un etablissement. Cela suffit pour une ecole, pas pour une
 * plateforme nationale : un delegue regional a exactement les memes permissions
 * de lecture qu'un administrateur national. Ce qui les distingue n'est pas ce
 * qu'ils peuvent faire, mais l'etendue sur laquelle ils le font.
 *
 *   Utilisateur  ->  Role  ->  Permission  ->  Perimetre
 *
 * Le perimetre se traduit en une condition SQL appliquee a la table des
 * etablissements. Toutes les lectures de pilotage passent par elle : un ecran
 * qui l'oublierait montrerait le pays entier a un delegue regional, et le
 * defaut ne se verrait que le jour ou quelqu'un s'en apercevrait.
 */
final class Scope
{
    public const PLATFORM = 'PLATFORM';

    public const REGION = 'REGION';

    public const DEPARTMENT = 'DEPARTMENT';

    public const TENANT = 'TENANT';

    private string $type;

    private ?string $value;

    private function __construct(string $type, ?string $value)
    {
        $this->type = $type;
        $this->value = $value;
    }

    /** Perimetre national : aucune restriction. */
    public static function platform(): self
    {
        return new self(self::PLATFORM, null);
    }

    public static function region(string $region): self
    {
        return new self(self::REGION, $region);
    }

    public static function department(string $department): self
    {
        return new self(self::DEPARTMENT, $department);
    }

    public static function tenant(string $tenantId): self
    {
        return new self(self::TENANT, $tenantId);
    }

    /**
     * Perimetre d'un compte, deduit de ses colonnes.
     *
     * Un compte de plateforme sans perimetre declare est national : c'est le
     * cas du Super Admin d'origine, et retomber sur un perimetre vide le
     * priverait de tout au lieu de tout lui donner.
     *
     * @param  array<string, mixed>|null  $user
     */
    public static function forUser(?array $user): self
    {
        if ($user === null) {
            return self::platform();
        }

        $type = $user['scope_type'] ?? null;
        $value = $user['scope_value'] ?? null;

        if ($type === self::REGION && is_string($value) && Cameroon::isRegion($value)) {
            return self::region($value);
        }

        if ($type === self::DEPARTMENT && is_string($value) && $value !== '') {
            return self::department($value);
        }

        // Un compte rattache a un etablissement ne voit que le sien, quel que
        // soit son role : c'est la regle d'isolation deja tenue par Table.
        $tenantId = $user['tenant_id'] ?? null;

        if (is_string($tenantId) && $tenantId !== '') {
            return self::tenant($tenantId);
        }

        return self::platform();
    }

    public function type(): string
    {
        return $this->type;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function isNational(): bool
    {
        return $this->type === self::PLATFORM;
    }

    /** Libelle affichable, pour dire a l'ecran ce que l'on regarde. */
    public function label(): string
    {
        return match ($this->type) {
            self::REGION => 'Region '.Cameroon::regionName($this->value),
            self::DEPARTMENT => 'Departement '.($this->value ?? ''),
            self::TENANT => 'Un etablissement',
            default => 'Ensemble du territoire',
        };
    }

    /**
     * Condition SQL restreignant la table des etablissements.
     *
     * L'alias est passe par l'appelant : les requetes de pilotage nomment
     * tantot « t », tantot « tenants ».
     *
     * @return array{sql: string, params: array<string, string>}
     */
    public function condition(string $alias = 't'): array
    {
        return match ($this->type) {
            self::REGION => [
                'sql' => $alias.'.region = :scope_region',
                'params' => ['scope_region' => (string) $this->value],
            ],
            self::DEPARTMENT => [
                'sql' => 'LOWER('.$alias.'.department) = :scope_department',
                'params' => ['scope_department' => mb_strtolower((string) $this->value)],
            ],
            self::TENANT => [
                'sql' => $alias.'.id = :scope_tenant',
                'params' => ['scope_tenant' => (string) $this->value],
            ],
            // Une condition toujours vraie plutot qu'une chaine vide : elle
            // s'insere sans que chaque appelant ait a tester le cas national.
            default => ['sql' => '1 = 1', 'params' => []],
        };
    }

    /**
     * Meme condition, exprimee sur un identifiant d'etablissement porte par une
     * autre table — une facture, une note, un compte.
     *
     * @return array{sql: string, params: array<string, string>}
     */
    public function conditionOnTenantColumn(string $column): array
    {
        if ($this->isNational()) {
            return ['sql' => '1 = 1', 'params' => []];
        }

        if ($this->type === self::TENANT) {
            return [
                'sql' => $column.' = :scope_tenant',
                'params' => ['scope_tenant' => (string) $this->value],
            ];
        }

        $inner = $this->condition('st');

        return [
            'sql' => $column.' IN (SELECT st.id FROM tenants st WHERE '.$inner['sql'].')',
            'params' => $inner['params'],
        ];
    }

    /** Un etablissement donne entre-t-il dans le perimetre ? */
    public function covers(array $tenant): bool
    {
        return match ($this->type) {
            self::REGION => ($tenant['region'] ?? null) === $this->value,
            self::DEPARTMENT => mb_strtolower((string) ($tenant['department'] ?? ''))
                === mb_strtolower((string) $this->value),
            self::TENANT => (string) ($tenant['id'] ?? '') === (string) $this->value,
            default => true,
        };
    }
}
