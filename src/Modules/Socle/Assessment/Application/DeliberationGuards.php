<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationContext;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;

/**
 * Exécute les gardes de délibération (S8) : celles du socle (activées par paramètres) et celles enregistrées par les
 * applications. Calcule aussi l'empreinte SHA-256 des décisions d'une délibération (contenu normalisé, indépendant
 * de la base : MySQL rend des chaînes, SQLite des nombres).
 */
final class DeliberationGuards
{
    /** @param list<DeliberationGuard> $guards */
    public function __construct(
        private readonly ResultRepository $repo,
        private readonly array $guards = [],
    ) {
    }

    /**
     * Refuse l'opération au premier refus d'une garde.
     *
     * @param array<string, mixed> $deliberation ligne de la table deliberations
     * @throws HttpException
     */
    public function assert(string $stage, Principal $principal, array $deliberation, ?string $reason = null): void
    {
        $this->assertContext($this->context($stage, $principal, $deliberation, $reason));
    }

    /** Garde d'ouverture : la délibération n'existe pas encore. */
    public function assertOpen(Principal $principal, string $establishmentId, string $structureId, string $periodId, string $session, ?string $presidentId): void
    {
        $this->assertContext(new DeliberationContext(
            DeliberationGuard::OPEN,
            $principal->tenantId,
            $establishmentId,
            $principal->userId,
            null,
            $structureId,
            $periodId,
            $session,
            null,
            $presidentId,
        ));
    }

    /** @param array<string, mixed> $deliberation */
    public function after(string $stage, Principal $principal, array $deliberation, ?string $reason = null): void
    {
        $context = $this->context($stage, $principal, $deliberation, $reason);
        foreach ($this->guards as $guard) {
            $guard->after($context);
        }
    }

    /** Empreinte SHA-256 des décisions d'une délibération (en-tête, puis lignes triées par personne). */
    public function fingerprint(string $tenantId, string $establishmentId, string $deliberationId): ?string
    {
        $row = $this->repo->deliberation($tenantId, $establishmentId, $deliberationId);
        if ($row === null) {
            return null;
        }
        $lines = [];
        foreach ($this->repo->deliberationLines($tenantId, $deliberationId) as $line) {
            $lines[(string) $line['person_id']] = [
                (string) $line['person_id'],
                $line['average'] === null ? null : number_format((float) $line['average'], 2, '.', ''),
                (int) $line['credits_earned'],
                (int) $line['credits_total'],
                $line['decision'] === null ? null : (string) $line['decision'],
                $line['reason'] === null ? null : (string) $line['reason'],
            ];
        }
        ksort($lines, SORT_STRING);
        $content = [
            'deliberation' => (string) $row['id'],
            'structure' => (string) $row['structure_id'],
            'period' => (string) $row['period_id'],
            'session' => (string) ($row['exam_session'] ?? AssessmentService::NORMAL),
            'rule_version' => (int) $row['rule_version'],
            'lines' => array_values($lines),
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertContext(DeliberationContext $context): void
    {
        foreach ($this->guards as $guard) {
            foreach ($guard->refusals($context) as $refusal) {
                throw match ($refusal->status) {
                    403 => HttpException::forbidden($refusal->message),
                    422 => HttpException::validation(['deliberation' => [$refusal->message]]),
                    default => HttpException::conflict($refusal->message),
                };
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function context(string $stage, Principal $principal, array $row, ?string $reason): DeliberationContext
    {
        $members = $this->decode($row['members_present'] ?? '[]');
        $signatures = [];
        foreach ($this->decode($row['signatures'] ?? '[]') as $signature) {
            if (is_array($signature) && isset($signature['person_id'])) {
                $signatures[] = ['person_id' => (string) $signature['person_id'], 'user_id' => (string) ($signature['user_id'] ?? ''), 'signed_at' => (string) ($signature['signed_at'] ?? '')];
            }
        }

        return new DeliberationContext(
            $stage,
            $principal->tenantId,
            (string) $row['establishment_id'],
            $principal->userId,
            (string) $row['id'],
            (string) $row['structure_id'],
            (string) $row['period_id'],
            (string) ($row['exam_session'] ?? AssessmentService::NORMAL),
            (string) $row['status'],
            (string) $row['president_id'],
            array_values(array_map('strval', array_filter($members, 'is_string'))),
            $signatures,
            isset($row['minutes_fingerprint']) ? (string) $row['minutes_fingerprint'] : null,
            $this->fingerprint($principal->tenantId, (string) $row['establishment_id'], (string) $row['id']),
            (int) ($row['minutes_version'] ?? 0),
            $reason,
        );
    }

    /** @return array<int|string, mixed> */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
