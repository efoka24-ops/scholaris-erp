<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use InvalidArgumentException;
use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;
use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Règles de calcul versionnées par année (T120). Une version publiée n'est jamais modifiée : une nouvelle version la
 * remplace pour les calculs suivants. Les règles de base viennent des paramètres de l'établissement (assessment.*).
 * Une version peut être rattachée à une structure (filière, programme : S5) ; elle prime alors, pour cette structure
 * et celles qui en dépendent (RuleScopes), sur la version de l'année. Les numéros de version restent uniques par année.
 */
final class RuleService
{
    public function __construct(
        private readonly Db $db,
        private readonly ResultRepository $results,
        private readonly AssessmentSettings $settings,
        private readonly AssessmentClock $clock,
        private readonly AuditLog $audit,
        private readonly RuleScopes $scopes,
        private readonly RuleStrategies $strategies,
    ) {
    }

    /**
     * Règles en vigueur : dernière version de la structure (ou de la plus proche structure parente déclarée), sinon
     * dernière version de l'année sans structure, sinon règles de base de l'établissement.
     */
    public function activeRules(string $tenantId, string $establishmentId, ?string $yearId, ?string $structureId = null): RuleSet
    {
        $base = $this->settings->baseRules($tenantId, $establishmentId);
        if ($yearId === null) {
            return $base;
        }
        $row = null;
        if ($structureId !== null) {
            foreach ($this->scopes->scopesOf($tenantId, $establishmentId, $structureId) as $scope) {
                $row = $this->results->latestRules($tenantId, $establishmentId, $yearId, $scope);
                if ($row !== null) {
                    break;
                }
            }
        }
        $row ??= $this->results->latestRules($tenantId, $establishmentId, $yearId);
        if ($row === null) {
            return $base;
        }
        $definition = json_decode((string) $row['definition'], true);

        try {
            return $base->with(is_array($definition) ? $definition : [], (int) $row['version']);
        } catch (InvalidArgumentException) {
            return $base->withLenient(is_array($definition) ? $definition : [], (int) $row['version']);
        }
    }

    /**
     * Libellés des décisions qui valent admission pour l'année : règles de base et toutes les versions de l'année.
     *
     * @return list<string>
     */
    public function passingLabels(string $tenantId, string $establishmentId, string $yearId): array
    {
        $base = $this->settings->baseRules($tenantId, $establishmentId);
        $labels = $base->passingLabels();
        foreach ($this->results->rulesOfYear($tenantId, $establishmentId, $yearId) as $row) {
            $definition = json_decode((string) $row['definition'], true);
            $labels = [...$labels, ...$base->withLenient(is_array($definition) ? $definition : [], (int) $row['version'])->passingLabels()];
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param array<string, mixed> $data academic_year_id, label, definition, structure_id
     * @return array<string, mixed>
     */
    public function publish(string $tenantId, string $establishmentId, string $actorId, array $data): array
    {
        $yearId = (string) $data['academic_year_id'];
        $structureId = isset($data['structure_id']) ? (string) $data['structure_id'] : null;
        $base = $this->settings->baseRules($tenantId, $establishmentId);
        try {
            $effective = $base->with((array) $data['definition'], 0);
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['definition' => [$e->getMessage()]]);
        }
        $this->assertStrategy($effective);

        $id = (string) new Ulid();
        try {
            $this->db->transaction(function () use ($tenantId, $establishmentId, $actorId, $data, $yearId, $structureId, $id): void {
                $latest = $this->results->latestVersion($tenantId, $establishmentId, $yearId);
                $this->results->insert('calculation_rules', [
                    'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'academic_year_id' => $yearId,
                    'structure_id' => $structureId, 'version' => $latest + 1, 'label' => $data['label'],
                    'definition' => json_encode($data['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_by' => $actorId, 'created_at' => $this->clock->now(),
                ]);
            });
        } catch (PDOException $e) {
            if ($this->db->isUniqueViolation($e)) {
                throw HttpException::conflict('Une autre version vient d\'être publiée. Rechargez les règles puis recommencez.');
            }
            throw $e;
        }
        $row = $this->results->rules($tenantId, $establishmentId, $id) ?? [];
        $row['effective'] = $effective->with([], (int) $row['version'])->toArray();
        $this->audit->append($tenantId, $actorId, 'assessment.rules.published', 'calculation_rules', $id, null, ['academic_year_id' => $yearId, 'structure_id' => $structureId, 'version' => $row['version']]);

        return $row;
    }

    /** @return array<string, mixed> */
    public function find(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->results->rules($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Version de règles introuvable.');
        $row['effective'] = $this->activeFor($tenantId, $establishmentId, $row)->toArray();

        return $row;
    }

    /** La stratégie nommée doit être enregistrée et accepter ses options. */
    private function assertStrategy(RuleSet $rules): void
    {
        if (!$this->strategies->has($rules->strategy)) {
            throw HttpException::validation(['definition' => [sprintf('Stratégie de calcul inconnue : « %s ». Stratégies disponibles : %s.', $rules->strategy, implode(', ', $this->strategies->keys()))]]);
        }
        $strategy = $this->strategies->find($rules->strategy);
        $errors = $strategy === null ? [] : $strategy->optionErrors($rules->strategyOptions);
        if ($errors !== []) {
            throw HttpException::validation(['definition' => array_map(static fn (string $e): string => 'strategy_options : ' . $e, $errors)]);
        }
    }

    /** @param array<string, mixed> $row */
    private function activeFor(string $tenantId, string $establishmentId, array $row): RuleSet
    {
        $definition = json_decode((string) $row['definition'], true);

        return $this->settings->baseRules($tenantId, $establishmentId)->withLenient(is_array($definition) ? $definition : [], (int) $row['version']);
    }
}
