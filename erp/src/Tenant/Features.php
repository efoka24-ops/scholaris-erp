<?php

declare(strict_types=1);

namespace Scholaris\Tenant;

/**
 * Fonctionnalites actives pour un etablissement.
 *
 * Le type d'etablissement determine ce qui existe : une ecole primaire n'a ni
 * series, ni credits ECTS, ni baccalaureat. Ces notions ne sont pas seulement
 * masquees dans le menu, elles sont refusees en route : un utilisateur qui
 * devinerait l'URL obtiendrait une page introuvable, comme si la
 * fonctionnalite n'existait pas — ce qui est le cas chez lui.
 *
 * L'Admin peut activer les fonctionnalites marquees configurables. Son choix
 * est conserve dans tenants.config_json, sous la cle "features".
 */
final class Features
{
    private const STATE_ON = 'on';

    private const STATE_OFF = 'off';

    private const STATE_OPTIONAL = 'opt';

    /** @var array<string, mixed> */
    private array $matrix;

    private string $type;

    /** @var array<string, bool> Choix explicites de l'etablissement. */
    private array $overrides;

    /**
     * @param  array<string, mixed>  $matrix
     * @param  array<string, bool>  $overrides
     */
    public function __construct(array $matrix, string $type, array $overrides = [])
    {
        $this->matrix = $matrix;
        $this->type = $this->normaliseType($type);
        $this->overrides = $overrides;
    }

    /**
     * Construit depuis la ligne tenant.
     *
     * @param  array<string, mixed>|null  $tenant
     */
    public static function forTenant(string $basePath, ?array $tenant): self
    {
        $matrix = require rtrim($basePath, '/\\').'/database/feature-matrix.php';

        if ($tenant === null) {
            // Aucun etablissement : un compte de plateforme n'a pas de
            // fonctionnalites scolaires. Le type le plus complet sert de base,
            // mais rien de scolaire ne lui est propose de toute facon.
            return new self($matrix, 'SUPERIEUR');
        }

        $config = [];

        if (is_string($tenant['config_json'] ?? null) && $tenant['config_json'] !== '') {
            $decoded = json_decode((string) $tenant['config_json'], true);
            $config = is_array($decoded) ? $decoded : [];
        }

        $overrides = [];

        foreach ($config['features'] ?? [] as $key => $value) {
            if (is_string($key)) {
                $overrides[$key] = (bool) $value;
            }
        }

        return new self($matrix, (string) ($tenant['type'] ?? 'COLLEGE'), $overrides);
    }

    /**
     * Les types historiques du schema pointent vers un type de la matrice.
     */
    private function normaliseType(string $type): string
    {
        $type = strtoupper($type);

        if (isset($this->matrix['features']['structure.cycles'][$type])) {
            return $type;
        }

        return $this->matrix['aliases'][$type] ?? 'COLLEGE';
    }

    public function type(): string
    {
        return $this->type;
    }

    public function typeLabel(): string
    {
        return $this->matrix['types'][$this->type] ?? $this->type;
    }

    /**
     * Etat brut d'une fonctionnalite pour ce type : on, off ou opt.
     *
     * Une cle inconnue vaut "on" : une fonctionnalite non encore inscrite dans
     * la matrice ne doit pas disparaitre silencieusement de l'interface.
     */
    public function state(string $key): string
    {
        return $this->matrix['features'][$key][$this->type] ?? self::STATE_ON;
    }

    public function enabled(string $key): bool
    {
        $state = $this->state($key);

        if ($state === self::STATE_ON) {
            return true;
        }

        if ($state === self::STATE_OFF) {
            // Inexistant pour ce type : aucun reglage ne peut l'activer, sans
            // quoi un directeur de primaire pourrait s'ouvrir le baccalaureat.
            return false;
        }

        return $this->overrides[$key] ?? false;
    }

    public function disabled(string $key): bool
    {
        return ! $this->enabled($key);
    }

    public function isOptional(string $key): bool
    {
        return $this->state($key) === self::STATE_OPTIONAL;
    }

    /**
     * Fonctionnalites que l'Admin peut activer ou desactiver, avec leur etat.
     *
     * @return array<string, array{name: string, enabled: bool}>
     */
    public function optional(): array
    {
        $result = [];

        foreach ($this->matrix['features'] as $key => $states) {
            if (($states[$this->type] ?? null) !== self::STATE_OPTIONAL) {
                continue;
            }

            $result[$key] = [
                'name' => $this->matrix['names'][$key] ?? $key,
                'enabled' => $this->enabled($key),
            ];
        }

        return $result;
    }

    /**
     * Fonctionnalites actives d'office pour ce type, a titre d'information.
     *
     * @return list<string>
     */
    public function alwaysOn(): array
    {
        $result = [];

        foreach ($this->matrix['features'] as $key => $states) {
            if (($states[$this->type] ?? null) === self::STATE_ON) {
                $result[] = $this->matrix['names'][$key] ?? $key;
            }
        }

        return $result;
    }

    /**
     * Terme adapte au type d'etablissement.
     *
     * Un centre de formation parle d'apprenants et de modules ; conserver
     * « eleves » et « matieres » partout sonnerait faux pour ses utilisateurs.
     */
    public function label(string $key, string $default): string
    {
        return $this->matrix['labels'][$this->type][$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function types(): array
    {
        return $this->matrix['types'];
    }
}
