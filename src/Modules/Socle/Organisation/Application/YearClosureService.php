<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Infrastructure\ClosureRepository;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;
use Throwable;

/**
 * Clôture d'une année scolaire : contrôles préalables, demande, approbation à deux personnes avec double authentification
 * récente, puis exécution idempotente des étapes (archivage, report des soldes, passage en classe supérieure) et fermeture.
 *
 * Demande : pending_approval -> (approbation) approved_incomplete -> closed ; ou rejected. Une étape déjà faite n'est jamais refaite :
 * si une étape échoue, la demande reste approved_incomplete et l'approbation rejouée reprend là où elle s'est arrêtée.
 */
final class YearClosureService
{
    public const KIND = 'academic_year.close';
    public const SECOND_FACTOR_MAX_AGE = 600;
    private const PENDING = 'pending_approval';
    private const INCOMPLETE = 'approved_incomplete';
    private const CLOSED = 'closed';
    private const REJECTED = 'rejected';

    /** Contrôles attendus (clé => libellé). Sans fournisseur, un contrôle est « non évaluable » et listé. */
    public const EXPECTED = [
        'billing.unpaid' => 'Impayés',
        'assessment.grades_unlocked' => 'Notes non verrouillées',
        'assessment.deliberations_open' => 'Délibérations ouvertes',
    ];

    private const STEPS = ['archive', 'carry_over', 'promotion'];

    public function __construct(
        private readonly Repositories $repositories,
        private readonly ClosureRepository $closures,
        private readonly ClosureProviders $providers,
        private readonly AcademicYearService $years,
        private readonly Approvals $approvals,
        private readonly RecentSecondFactor $secondFactor,
        private readonly AuditLog $audit,
        private readonly OrgClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function check(string $tenantId, string $establishmentId, string $yearId): array
    {
        $this->year($tenantId, $establishmentId, $yearId);
        $findings = $this->findings($tenantId, $establishmentId, $yearId);
        $blocking = array_values(array_filter($findings, static fn (ClosureFinding $f): bool => $f->blocks()));
        $unknown = array_values(array_filter($findings, static fn (ClosureFinding $f): bool => !$f->evaluable));

        return [
            'academic_year_id' => $yearId,
            'ready' => $blocking === [],
            'blocking_count' => count($blocking),
            'not_evaluable_count' => count($unknown),
            'checks' => array_map(static fn (ClosureFinding $f): array => $f->toArray(), $findings),
            'closure' => $this->current($tenantId, $establishmentId, $yearId),
        ];
    }

    /** @return array<string, mixed>|null */
    public function current(string $tenantId, string $establishmentId, string $yearId): ?array
    {
        $row = $this->closures->latestForYear($tenantId, $establishmentId, $yearId);

        return $row === null ? null : $this->present($row);
    }

    /**
     * Demande la clôture. Rejouée tant qu'une demande est en cours, elle renvoie cette demande (aucun doublon).
     *
     * @return array{0: array<string, mixed>, 1: bool} la demande et vrai si elle vient d'être créée
     */
    public function request(string $tenantId, string $establishmentId, string $yearId, ?string $nextYearId, string $actorId): array
    {
        $year = $this->year($tenantId, $establishmentId, $yearId);
        if ($year['status'] === AcademicYearService::CLOSED) {
            throw HttpException::conflict('Cette année scolaire est déjà clôturée.');
        }
        $latest = $this->closures->latestForYear($tenantId, $establishmentId, $yearId);
        if ($latest !== null && in_array($latest['status'], [self::PENDING, self::INCOMPLETE], true)) {
            return [$this->present($latest), false];
        }

        if ($nextYearId !== null) {
            $next = $this->repositories->explicit('academic_years', $tenantId, $establishmentId)->find($nextYearId);
            if ($next === null || $nextYearId === $yearId) {
                throw HttpException::validation(['next_academic_year_id' => ['Année suivante introuvable dans cet établissement.']]);
            }
            if ($next['status'] !== AcademicYearService::OPEN || (string) $next['starts_on'] <= (string) $year['starts_on']) {
                throw HttpException::validation(['next_academic_year_id' => ['L\'année suivante doit être ouverte et postérieure à l\'année à clôturer.']]);
            }
        }

        $findings = $this->findings($tenantId, $establishmentId, $yearId);
        $this->assertNoBlocker($findings);

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $report = array_map(static fn (ClosureFinding $f): array => $f->toArray(), $findings);
        $this->closures->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'academic_year_id' => $yearId,
            'next_year_id' => $nextYearId, 'status' => self::PENDING, 'requested_by' => $actorId, 'requested_at' => $now,
            'check_report' => json_encode($report, JSON_THROW_ON_ERROR), 'steps' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $approvalId = $this->approvals->request($tenantId, self::KIND, 'academic_year', $yearId, 'Clôture de l\'année scolaire ' . $year['label'], $actorId);
        $this->closures->attachApproval($tenantId, $establishmentId, $id, $approvalId);

        return [$this->present($this->closures->find($tenantId, $establishmentId, $id) ?? []), true];
    }

    /**
     * Approuve la demande et exécute la clôture. Idempotent : sur une clôture terminée, renvoie son état sans rien refaire ;
     * sur une clôture interrompue, reprend aux étapes non terminées.
     *
     * @return array<string, mixed>
     */
    public function approve(string $tenantId, string $establishmentId, string $yearId, string $approverId): array
    {
        $year = $this->year($tenantId, $establishmentId, $yearId);
        $closure = $this->closures->latestForYear($tenantId, $establishmentId, $yearId)
            ?? throw HttpException::notFound('Aucune demande de clôture pour cette année scolaire.');
        if ($closure['status'] === self::CLOSED) {
            return $this->present($closure);
        }
        if ($closure['status'] === self::REJECTED) {
            throw HttpException::conflict('Cette demande de clôture a été refusée : déposez-en une nouvelle.');
        }
        if (!$this->secondFactor->hasRecent($tenantId, $approverId, self::SECOND_FACTOR_MAX_AGE)) {
            throw HttpException::forbidden('Une double authentification récente est exigée pour approuver la clôture d\'une année.');
        }

        $this->assertNoBlocker($this->findings($tenantId, $establishmentId, $yearId));

        $id = (string) $closure['id'];
        if ($closure['status'] === self::PENDING) {
            $this->approvals->approve($tenantId, (string) $closure['approval_id'], $approverId);
            $this->closures->update($tenantId, $establishmentId, $id, self::INCOMPLETE, $approverId, null, (string) $closure['steps'], $this->clock->now());
            $closure['status'] = self::INCOMPLETE;
            $closure['decided_by'] = $approverId;
        }

        $steps = $this->runSteps($tenantId, $establishmentId, $closure, $year, $approverId);

        if ($year['status'] !== AcademicYearService::CLOSED) {
            $this->years->close($tenantId, $establishmentId, $yearId, $approverId);
        }
        $now = $this->clock->now();
        $this->closures->update($tenantId, $establishmentId, $id, self::CLOSED, $approverId, $now, json_encode($steps, JSON_THROW_ON_ERROR), $now);

        return $this->present($this->closures->find($tenantId, $establishmentId, $id) ?? []);
    }

    /** @return array<string, mixed> */
    public function reject(string $tenantId, string $establishmentId, string $yearId, string $approverId): array
    {
        $this->year($tenantId, $establishmentId, $yearId);
        $closure = $this->closures->latestForYear($tenantId, $establishmentId, $yearId)
            ?? throw HttpException::notFound('Aucune demande de clôture pour cette année scolaire.');
        if ($closure['status'] !== self::PENDING) {
            throw HttpException::conflict('Seule une demande en attente peut être refusée.');
        }
        $this->approvals->reject($tenantId, (string) $closure['approval_id'], $approverId);
        $this->closures->update($tenantId, $establishmentId, (string) $closure['id'], self::REJECTED, $approverId, null, (string) $closure['steps'], $this->clock->now());

        return $this->present($this->closures->find($tenantId, $establishmentId, (string) $closure['id']) ?? []);
    }

    /** @return array<string, mixed> */
    private function year(string $tenantId, string $establishmentId, string $yearId): array
    {
        return $this->repositories->explicit('academic_years', $tenantId, $establishmentId)->findOrFail($yearId);
    }

    /** @return list<ClosureFinding> */
    private function findings(string $tenantId, string $establishmentId, string $yearId): array
    {
        $byKey = [];
        foreach ($this->providers->checks() as $check) {
            try {
                $byKey[$check->key()] = $check->evaluate($tenantId, $establishmentId, $yearId);
            } catch (Throwable) {
                $byKey[$check->key()] = ClosureFinding::notEvaluable($check->key(), self::EXPECTED[$check->key()] ?? $check->key(), 'La source du contrôle est en erreur : réessayez.');
            }
        }
        $out = [];
        foreach (self::EXPECTED as $key => $label) {
            $out[] = $byKey[$key] ?? ClosureFinding::notEvaluable($key, $label, 'Aucun module ne fournit encore ce contrôle.');
            unset($byKey[$key]);
        }

        return [...$out, ...array_values($byKey)];
    }

    /** @param list<ClosureFinding> $findings */
    private function assertNoBlocker(array $findings): void
    {
        $blocking = array_filter($findings, static fn (ClosureFinding $f): bool => $f->blocks());
        if ($blocking !== []) {
            throw HttpException::conflict('Clôture impossible : ' . implode(' ; ', array_map(
                static fn (ClosureFinding $f): string => sprintf('%s (%d)', $f->label, $f->blockingCount),
                $blocking,
            )) . '.');
        }
    }

    /**
     * @param array<string, mixed> $closure
     * @param array<string, mixed> $year
     * @return array<string, array<string, mixed>>
     */
    private function runSteps(string $tenantId, string $establishmentId, array $closure, array $year, string $actorId): array
    {
        /** @var array<string, array<string, mixed>> $steps */
        $steps = json_decode((string) $closure['steps'], true) ?: [];
        foreach (self::STEPS as $name) {
            if (in_array($steps[$name]['status'] ?? null, [ClosureStepResult::DONE, ClosureStepResult::UNAVAILABLE, ClosureStepResult::SKIPPED], true)) {
                continue;
            }
            try {
                $steps[$name] = match ($name) {
                    'archive' => $this->archive($tenantId, $establishmentId, $closure, $year)->toArray(),
                    'carry_over' => $this->carryOver($tenantId, $establishmentId, $closure)->toArray(),
                    default => $this->promotion($tenantId, $establishmentId, $closure)->toArray(),
                };
            } catch (Throwable $e) {
                $steps[$name] = ['status' => 'failed', 'summary' => mb_substr($e->getMessage(), 0, 200), 'counts' => []];
                $this->closures->update($tenantId, $establishmentId, (string) $closure['id'], self::INCOMPLETE, $actorId, null, json_encode($steps, JSON_THROW_ON_ERROR), $this->clock->now());
                $this->audit->append($tenantId, $actorId, 'academic_year.closure_step_failed', 'academic_year', (string) $closure['academic_year_id'], null, ['step' => $name, 'summary' => $steps[$name]['summary']]);

                throw HttpException::conflict(sprintf('Clôture interrompue à l\'étape « %s » : %s Relancez l\'approbation pour reprendre.', $name, (string) $steps[$name]['summary']));
            }
            $this->closures->update($tenantId, $establishmentId, (string) $closure['id'], self::INCOMPLETE, $actorId, null, json_encode($steps, JSON_THROW_ON_ERROR), $this->clock->now());
            $this->audit->append($tenantId, $actorId, 'academic_year.closure_step', 'academic_year', (string) $closure['academic_year_id'], null, ['step' => $name] + $steps[$name]);
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $closure
     * @param array<string, mixed> $year
     */
    private function archive(string $tenantId, string $establishmentId, array $closure, array $year): ClosureStepResult
    {
        $yearId = (string) $year['id'];
        if ($this->closures->archiveExists($tenantId, $establishmentId, $yearId)) {
            return new ClosureStepResult(ClosureStepResult::DONE, 'Archive déjà présente.');
        }
        $periods = array_values(array_filter(
            $this->repositories->explicit('periods', $tenantId, $establishmentId)->all(),
            static fn (array $period): bool => $period['academic_year_id'] === $yearId,
        ));
        $structures = $this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->all();
        $snapshot = [
            'year' => $year,
            'periods' => $periods,
            'structures' => array_map(static fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'] ?? null, 'kind' => $s['kind'] ?? null], $structures),
            'closure_id' => $closure['id'],
            'requested_by' => $closure['requested_by'],
            'checks' => json_decode((string) $closure['check_report'], true),
        ];
        $this->closures->insertArchive((string) new Ulid(), $tenantId, $establishmentId, $yearId, (string) $closure['id'], json_encode($snapshot, JSON_THROW_ON_ERROR), $this->clock->now());

        return new ClosureStepResult(ClosureStepResult::DONE, 'État de l\'année archivé.', ['periods' => count($periods), 'structures' => count($structures)]);
    }

    /** @param array<string, mixed> $closure */
    private function carryOver(string $tenantId, string $establishmentId, array $closure): ClosureStepResult
    {
        if ($closure['next_year_id'] === null) {
            return new ClosureStepResult(ClosureStepResult::SKIPPED, 'Aucune année suivante indiquée : aucun solde reporté.');
        }
        $providers = $this->providers->carryOvers();
        if ($providers === []) {
            return new ClosureStepResult(ClosureStepResult::UNAVAILABLE, 'Aucun module ne fournit le report des soldes.');
        }

        return $this->merge(array_map(
            fn ($provider): ClosureStepResult => $provider->carryOver($tenantId, $establishmentId, (string) $closure['academic_year_id'], (string) $closure['next_year_id']),
            $providers,
        ));
    }

    /** @param array<string, mixed> $closure */
    private function promotion(string $tenantId, string $establishmentId, array $closure): ClosureStepResult
    {
        if ($closure['next_year_id'] === null) {
            return new ClosureStepResult(ClosureStepResult::SKIPPED, 'Aucune année suivante indiquée : aucun passage en classe supérieure.');
        }
        $providers = $this->providers->promotersFor($tenantId, $establishmentId, $this->establishmentType($tenantId, $establishmentId));
        if ($providers === []) {
            return new ClosureStepResult(ClosureStepResult::UNAVAILABLE, 'Aucun module ne fournit le passage en classe supérieure.');
        }

        return $this->merge(array_map(
            fn ($provider): ClosureStepResult => $provider->promote($tenantId, $establishmentId, (string) $closure['academic_year_id'], (string) $closure['next_year_id']),
            $providers,
        ));
    }

    private function establishmentType(string $tenantId, string $establishmentId): string
    {
        return (string) ($this->repositories->explicit('establishments', $tenantId)->find($establishmentId)['type'] ?? '');
    }

    /** @param list<ClosureStepResult> $results */
    private function merge(array $results): ClosureStepResult
    {
        $counts = [];
        foreach ($results as $result) {
            foreach ($result->counts as $key => $value) {
                $counts[$key] = ($counts[$key] ?? 0) + $value;
            }
        }

        return new ClosureStepResult(
            ClosureStepResult::DONE,
            implode(' ', array_map(static fn (ClosureStepResult $r): string => $r->summary, $results)),
            $counts,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'academic_year_id' => $row['academic_year_id'] ?? null,
            'next_academic_year_id' => $row['next_year_id'] ?? null,
            'status' => $row['status'] ?? null,
            'approval_id' => $row['approval_id'] ?? null,
            'requested_by' => $row['requested_by'] ?? null,
            'requested_at' => $row['requested_at'] ?? null,
            'decided_by' => $row['decided_by'] ?? null,
            'closed_at' => $row['closed_at'] ?? null,
            'checks' => json_decode((string) ($row['check_report'] ?? '[]'), true),
            'steps' => (object) (json_decode((string) ($row['steps'] ?? '{}'), true) ?: []),
        ];
    }
}
