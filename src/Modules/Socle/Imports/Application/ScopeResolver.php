<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Application;

use Scholaris\Modules\Socle\Imports\Contracts\ScopeCodeResolver;
use Scholaris\Modules\Socle\Imports\Domain\PeopleColumns;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Périmètre d'une ligne : « établissement » (celui de l'import), « unité » (école, département…) ou « structure » (classe, filière…).
 * Le code du fichier est résolu CÔTÉ SERVEUR et toujours dans l'établissement de l'import : par les résolveurs de codes lisibles que
 * les applications enregistrent (ScopeCodeResolver), sinon comme identifiant d'unité ou de structure. Jamais d'accès à un autre
 * établissement ni à un autre client.
 */
final class ScopeResolver
{
    public const ESTABLISHMENT = 'establishment';
    public const ORG_UNIT = 'org_unit';
    public const STRUCTURE = 'structure';

    private const SYNONYMS = [
        self::ESTABLISHMENT => ['etablissement', 'establishment', 'ecole entiere', 'institut', 'tout'],
        self::ORG_UNIT => ['unite', 'org_unit', 'ecole', 'departement', 'department', 'faculte', 'service'],
        self::STRUCTURE => ['structure', 'classe', 'class', 'filiere', 'niveau', 'groupe', 'track', 'level', 'group'],
    ];

    /** @param iterable<ScopeCodeResolver> $resolvers */
    public function __construct(
        private readonly EstablishmentDirectory $establishments,
        private readonly OrgUnitTree $tree,
        private readonly iterable $resolvers = [],
    ) {
    }

    /** Type de périmètre désigné par la colonne « périmètre » (vide = établissement) ; null si le mot est inconnu. */
    public function typeOf(string $word): ?string
    {
        $normalized = str_replace('_', ' ', PeopleColumns::normalize($word));
        if ($normalized === '') {
            return self::ESTABLISHMENT;
        }
        foreach (self::SYNONYMS as $type => $words) {
            if (in_array($normalized, array_map(static fn (string $w): string => str_replace('_', ' ', $w), $words), true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array{id: ?string, error: ?string} identifiant du périmètre, ou message de refus
     */
    public function resolve(string $tenantId, string $establishmentId, string $type, string $code): array
    {
        $code = trim($code);
        if ($type === self::ESTABLISHMENT) {
            if ($code === '') {
                return ['id' => $establishmentId, 'error' => null];
            }
            $info = $this->establishments->find($tenantId, $establishmentId);
            if ($code === $establishmentId || ($info !== null && PeopleColumns::normalize($code) === PeopleColumns::normalize($info->name))) {
                return ['id' => $establishmentId, 'error' => null];
            }

            return ['id' => null, 'error' => 'Le code du périmètre « établissement » doit être vide ou désigner l\'établissement de cet import.'];
        }

        $label = $type === self::ORG_UNIT ? 'Unité' : 'Structure';
        if ($code === '') {
            return ['id' => null, 'error' => 'Le code du périmètre est obligatoire pour un périmètre « ' . ($type === self::ORG_UNIT ? 'unité' : 'structure') . ' ».'];
        }
        foreach ($this->resolvers as $resolver) {
            $id = $resolver->resolve($tenantId, $establishmentId, $type, $code);
            if ($id !== null && $this->exists($tenantId, $establishmentId, $type, $id)) {
                return ['id' => $id, 'error' => null];
            }
        }
        if (mb_strlen($code) <= 40 && $this->exists($tenantId, $establishmentId, $type, $code)) {
            return ['id' => $code, 'error' => null];
        }

        return ['id' => null, 'error' => $label . ' « ' . mb_substr($code, 0, 60) . ' » introuvable dans cet établissement.'];
    }

    private function exists(string $tenantId, string $establishmentId, string $type, string $id): bool
    {
        if ($type === self::ORG_UNIT) {
            return $this->tree->descendantsOf($tenantId, $establishmentId, $id) !== [];
        }

        return $this->tree->structure($tenantId, $establishmentId, $id) !== null;
    }
}
