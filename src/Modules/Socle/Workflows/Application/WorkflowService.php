<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Lock;
use Scholaris\Modules\Socle\Workflows\Contracts\ResolvedCircuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Step;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowResolver;
use Scholaris\Modules\Socle\Workflows\Infrastructure\WorkflowStore;

/**
 * Circuits de validation configurables : résolution (WorkflowResolver, utilisée par les applications), lecture pour l'écran
 * d'administration, surcharge, retour au défaut et historique. Trois niveaux de réglage (plateforme, client, établissement),
 * chacun versionné et journalisé avec son auteur, sa date et son motif ; chaque modification est aussi portée au journal d'audit
 * inaltérable du client.
 */
final class WorkflowService implements WorkflowResolver
{
    public const HISTORY_LIMIT = 50;
    public const REASON_MIN = 5;
    public const REASON_MAX = 500;
    /** Durée de vie, en secondes, d'une résolution gardée en mémoire (les services de notes la demandent à chaque ligne). */
    private const MEMO_SECONDS = 5.0;

    /** @var array<string, array{0: float, 1: ResolvedCircuit}> */
    private array $memo = [];

    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly WorkflowStore $store,
        private readonly OverrideValidator $validator,
        private readonly AuditLog $audit,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    public function resolve(string $tenantId, string $establishmentId, string $application, string $circuit): ResolvedCircuit
    {
        $key = $tenantId . '|' . $establishmentId . '|' . $application . '|' . $circuit;
        $now = microtime(true);
        if (isset($this->memo[$key]) && $now - $this->memo[$key][0] < self::MEMO_SECONDS) {
            return $this->memo[$key][1];
        }
        $definition = $this->registry->find($application, $circuit) ?? throw new \LogicException('Circuit non déclaré : ' . $application . '/' . $circuit);
        $resolved = Resolution::resolve($definition, $this->docs($this->store->layers($tenantId, $establishmentId, $application, $circuit)));
        $this->memo[$key] = [$now, $resolved];

        return $resolved;
    }

    /**
     * Circuits EFFECTIFS de l'établissement, forme minimale pour adapter les écrans (boutons) : qui valide à chaque étape,
     * étape activée ou non, confirmation récente. Aucun motif, auteur ni historique : lisible par tout le personnel.
     *
     * @return list<array<string, mixed>>
     */
    public function effective(string $tenantId, string $establishmentId): array
    {
        $type = $this->typeOf(WorkflowStore::ESTABLISHMENT, $tenantId, $establishmentId);
        $out = [];
        foreach ($this->registry->all() as $circuit) {
            if ($type !== null && !$circuit->appliesTo($type)) {
                continue;
            }
            $resolved = $this->resolve($tenantId, $establishmentId, $circuit->application, $circuit->key);
            $steps = [];
            foreach ($resolved->steps as $step) {
                $steps[] = [
                    'key' => $step->key, 'enabled' => $step->enabled, 'recent_confirmation' => $step->recentConfirmation, 'source' => $step->source,
                    'actor' => ['key' => $step->actor->key, 'permission' => $step->actor->permission, 'role' => $step->actor->role],
                ];
            }
            $out[] = ['id' => $circuit->application . ':' . $circuit->key, 'application' => $circuit->application, 'key' => $circuit->key, 'customised' => $resolved->customised, 'steps' => $steps];
        }

        return $out;
    }

    /**
     * Circuits applicables, avec défauts, valeurs effectives et verrous.
     *
     * @param string $level niveau édité : establishment (établissement actif), client (fiche client) ou platform
     * @return list<array<string, mixed>>
     */
    public function list(string $level, string $tenantId, ?string $establishmentId, ?string $application = null): array
    {
        $type = $this->typeOf($level, $tenantId, $establishmentId);
        $out = [];
        foreach ($this->registry->all() as $circuit) {
            if (($application !== null && $circuit->application !== $application) || ($type !== null && !$circuit->appliesTo($type))) {
                continue;
            }
            $out[] = $this->view($circuit, $level, $tenantId, $establishmentId);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function show(string $level, string $tenantId, ?string $establishmentId, string $application, string $circuit): array
    {
        return $this->view($this->circuit($level, $tenantId, $establishmentId, $application, $circuit), $level, $tenantId, $establishmentId);
    }

    /**
     * @param mixed $raw corps de la requête
     * @return array<string, mixed>
     */
    public function override(string $level, string $tenantId, ?string $establishmentId, string $application, string $circuit, mixed $raw, mixed $reason, string $actorId, ?string $ip): array
    {
        $definition = $this->circuit($level, $tenantId, $establishmentId, $application, $circuit);
        $reason = $this->reason($reason);
        [$ownTenant, $ownEstablishment] = $this->key($level, $tenantId, $establishmentId);
        $docs = $this->docs($this->store->layers($tenantId, $establishmentId, $application, $circuit));
        $doc = $this->validator->normalise($definition, $raw, $level, $docs);

        $existing = $this->store->find($level, $ownTenant, $ownEstablishment, $application, $circuit);
        $before = $existing === null ? null : $this->decode((string) $existing['doc_json']);
        if ($before === $doc) {
            return $this->view($definition, $level, $tenantId, $establishmentId);
        }
        $this->store->transaction(function () use ($level, $ownTenant, $ownEstablishment, $application, $circuit, $doc, $before, $existing, $reason, $actorId, $ip): void {
            $version = $this->store->lastVersion($level, $ownTenant, $ownEstablishment, $application, $circuit) + 1;
            $now = $this->now();
            $json = (string) json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($existing === null) {
                $this->store->insert('workflow_overrides', [
                    'id' => (string) new Ulid(), 'scope_level' => $level, 'tenant_id' => $ownTenant, 'establishment_id' => $ownEstablishment,
                    'app_key' => $application, 'circuit_key' => $circuit, 'doc_json' => $json, 'version_no' => $version,
                    'change_reason' => $reason, 'updated_by' => $actorId, 'updated_at' => $now,
                ]);
            } else {
                $this->store->update((string) $existing['id'], $json, $version, $reason, $actorId, $now);
            }
            $this->journal('set', $level, $ownTenant, $ownEstablishment, $application, $circuit, $version, $before, $doc, $reason, $actorId, $now, $ip);
        });

        return $this->view($definition, $level, $tenantId, $establishmentId);
    }

    /**
     * Retour au défaut à ce niveau (idempotent). Les niveaux supérieurs ne sont jamais touchés.
     *
     * @return array<string, mixed>
     */
    public function reset(string $level, string $tenantId, ?string $establishmentId, string $application, string $circuit, mixed $reason, string $actorId, ?string $ip): array
    {
        $definition = $this->circuit($level, $tenantId, $establishmentId, $application, $circuit);
        $reason = $this->reason($reason);
        [$ownTenant, $ownEstablishment] = $this->key($level, $tenantId, $establishmentId);
        $existing = $this->store->find($level, $ownTenant, $ownEstablishment, $application, $circuit);
        if ($existing !== null) {
            $this->store->transaction(function () use ($level, $ownTenant, $ownEstablishment, $application, $circuit, $existing, $reason, $actorId, $ip): void {
                $version = $this->store->lastVersion($level, $ownTenant, $ownEstablishment, $application, $circuit) + 1;
                $this->store->delete((string) $existing['id']);
                $this->journal('reset', $level, $ownTenant, $ownEstablishment, $application, $circuit, $version, $this->decode((string) $existing['doc_json']), null, $reason, $actorId, $this->now(), $ip);
            });
        }

        return $this->view($definition, $level, $tenantId, $establishmentId);
    }

    /** @return list<array<string, mixed>> */
    public function history(string $level, string $tenantId, ?string $establishmentId, string $application, string $circuit): array
    {
        $this->circuit($level, $tenantId, $establishmentId, $application, $circuit);
        [$ownTenant, $ownEstablishment] = $this->key($level, $tenantId, $establishmentId);
        $out = [];
        foreach ($this->store->history($level, $ownTenant, $ownEstablishment, $application, $circuit, self::HISTORY_LIMIT) as $row) {
            $out[] = [
                'version' => (int) $row['version_no'], 'kind' => (string) $row['change_kind'],
                'before' => $row['before_json'] === null ? null : $this->decode((string) $row['before_json']),
                'after' => $row['after_json'] === null ? null : $this->decode((string) $row['after_json']),
                'reason' => (string) $row['change_reason'], 'changed_by' => (string) $row['changed_by'], 'changed_at' => (string) $row['changed_at'],
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function view(Circuit $circuit, string $level, string $tenantId, ?string $establishmentId): array
    {
        $rows = $this->store->layers($tenantId, $level === WorkflowStore::ESTABLISHMENT ? $establishmentId : null, $circuit->application, $circuit->key);
        $docs = $this->docs($rows);
        $levels = $this->levelsUpTo($level);
        $above = array_slice(Resolution::LEVELS, 0, (int) array_search($level, Resolution::LEVELS, true));
        $resolved = Resolution::resolve($circuit, $docs, $levels);
        $own = $rows[$level] ?? null;
        $steps = [];
        foreach ($circuit->steps as $step) {
            $current = $resolved->step($step->key);
            $imposed = Resolution::enforcedBy($step->key, $docs, $above);
            $steps[] = [
                'key' => $step->key, 'label_fr' => $step->labelFr, 'label_en' => $step->labelEn, 'optional' => $step->optional,
                'default' => ['actor' => $step->defaultActor, 'enabled' => true, 'recent_confirmation' => $step->confirmation],
                'effective' => [
                    'actor' => $current->actor->key, 'enabled' => $current->enabled, 'recent_confirmation' => $current->recentConfirmation,
                    'source' => $current->source, 'enforced_by' => $current->enforcedBy,
                ],
                'actors' => array_map(static fn ($a): array => $a->toArray(), $step->actors),
                'editable' => [
                    'actor' => $imposed === null && $step->actorEditable(),
                    'enabled' => $imposed === null && $step->enabledEditable(),
                    'recent_confirmation' => $imposed === null && $step->confirmationEditable(),
                ],
                'imposed_by' => $imposed,
                'locks' => $this->locks($step, $imposed),
            ];
        }

        return [
            'id' => $circuit->application . ':' . $circuit->key, 'application' => $circuit->application, 'key' => $circuit->key, 'label_fr' => $circuit->labelFr, 'label_en' => $circuit->labelEn,
            'level' => $level, 'customised' => $resolved->customised, 'steps' => $steps,
            'invariants' => array_map(static fn (Lock $l): array => $l->toArray(), $circuit->invariants),
            'override' => $own === null ? null : [
                'version' => (int) $own['version_no'], 'reason' => (string) $own['change_reason'], 'updated_by' => (string) $own['updated_by'],
                'updated_at' => (string) $own['updated_at'], 'steps' => $this->decode((string) $own['doc_json'])['steps'] ?? [],
            ],
        ];
    }

    /** @return list<array<string, string>> */
    private function locks(Step $step, ?string $imposed): array
    {
        $locks = array_map(static fn (Lock $l): array => $l->toArray(), $step->locks);
        $has = static fn (string $aspect): bool => $step->hasLock($aspect);
        if (count($step->actors) <= 1 && !$has(Lock::ACTOR)) {
            $locks[] = (new Lock(Lock::ACTOR, 'single_actor', 'L\'application ne prévoit qu\'une seule fonction pour valider cette étape.', 'The application allows a single role to validate this step.'))->toArray();
        }
        if ($step->confirmationMin && !$has(Lock::CONFIRMATION)) {
            $locks[] = (new Lock(Lock::CONFIRMATION, 'confirmation_required', 'La confirmation récente est obligatoire à cette étape.', 'Recent confirmation is mandatory at this step.'))->toArray();
        }
        if (!$step->optional && !$has(Lock::ENABLED)) {
            $locks[] = (new Lock(Lock::ENABLED, 'mandatory_step', 'Cette étape est obligatoire : elle ne peut pas être désactivée.', 'This step is mandatory and cannot be turned off.'))->toArray();
        }
        if ($imposed !== null) {
            $locks[] = (new Lock('step', 'imposed', 'Cette étape est imposée par un niveau supérieur (' . ($imposed === 'platform' ? 'la plateforme' : 'l\'administration de votre organisation') . ').', 'This step is imposed by a higher level (' . ($imposed === 'platform' ? 'the platform' : 'your organisation\'s administration') . ').'))->toArray();
        }

        return $locks;
    }

    /** @return list<string> */
    private function levelsUpTo(string $level): array
    {
        return array_slice(Resolution::LEVELS, 0, (int) array_search($level, Resolution::LEVELS, true) + 1);
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function docs(array $rows): array
    {
        $docs = [];
        foreach ($rows as $level => $row) {
            $docs[$level] = $this->decode((string) $row['doc_json']);
        }

        return $docs;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $doc = json_decode($json, true);

        return is_array($doc) ? $doc : [];
    }

    /** @return array{0: string, 1: string} client et établissement de la ligne du niveau édité */
    private function key(string $level, string $tenantId, ?string $establishmentId): array
    {
        return match ($level) {
            WorkflowStore::PLATFORM => [WorkflowStore::PLATFORM_TENANT, ''],
            WorkflowStore::CLIENT => [$tenantId, ''],
            default => [$tenantId, $establishmentId ?? throw new \LogicException('Établissement requis.')],
        };
    }

    private function typeOf(string $level, string $tenantId, ?string $establishmentId): ?string
    {
        if ($level !== WorkflowStore::ESTABLISHMENT || $establishmentId === null) {
            return null;
        }
        $establishment = $this->establishments->find($tenantId, $establishmentId) ?? throw HttpException::notFound('Établissement introuvable.');

        return $establishment->type;
    }

    private function circuit(string $level, string $tenantId, ?string $establishmentId, string $application, string $circuit): Circuit
    {
        $definition = $this->registry->find($application, $circuit) ?? throw HttpException::notFound('Circuit de validation introuvable.');
        $type = $this->typeOf($level, $tenantId, $establishmentId);
        if ($type !== null && !$definition->appliesTo($type)) {
            throw HttpException::notFound('Circuit de validation introuvable.');
        }

        return $definition;
    }

    private function reason(mixed $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw HttpException::validation(['reason' => [sprintf('Motif obligatoire (de %d à %d caractères).', self::REASON_MIN, self::REASON_MAX)]]);
        }

        return $reason;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function journal(string $kind, string $level, string $tenantId, string $establishmentId, string $application, string $circuit, int $version, ?array $before, ?array $after, string $reason, string $actorId, string $now, ?string $ip): void
    {
        $this->memo = [];
        $this->store->insert('workflow_changes', [
            'id' => (string) new Ulid(), 'scope_level' => $level, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId,
            'app_key' => $application, 'circuit_key' => $circuit, 'version_no' => $version, 'change_kind' => $kind,
            'before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'change_reason' => $reason, 'changed_by' => $actorId, 'changed_at' => $now,
        ]);
        $this->audit->append($tenantId, $actorId, $kind === 'set' ? 'workflow.changed' : 'workflow.reset', 'workflow', $application . '/' . $circuit, $before, [
            'level' => $level, 'establishment_id' => $establishmentId === '' ? null : $establishmentId, 'version' => $version, 'reason' => $reason, 'doc' => $after,
        ], $ip);
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
