<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;
use Scholaris\Modules\Socle\Messaging\Domain\Audience;
use Scholaris\Modules\Socle\Messaging\Domain\Recipient;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Transforme une audience en destinataires (personnes du client uniquement, sans doublon).
 * Impayés et absents : les messages vont aux garants de l'apprenant, avec les variables de l'enfant.
 */
final class AudienceResolver
{
    private const CHUNK = 500;

    public function __construct(
        private readonly PersonDirectory $persons,
        private readonly EnrollmentDirectory $enrollments,
        private readonly DebtorDirectory $debtors,
        private readonly MessagingClock $clock,
        private readonly ?AbsenteeDirectory $absentees = null,
    ) {
    }

    /** @return list<Recipient> */
    public function resolve(string $tenantId, string $establishmentId, Audience $audience): array
    {
        return match ($audience->type) {
            Audience::PERSON => $this->known($tenantId, [(string) $audience->id]),
            Audience::STRUCTURE, Audience::LEVEL => $this->known($tenantId, $this->enrollments->personIdsInStructure($tenantId, (string) $audience->id)),
            Audience::ESTABLISHMENT => $this->known($tenantId, $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId)),
            Audience::ARREARS => $this->viaGuarantors(
                $tenantId,
                $this->debtors->learnerIdsInArrears($tenantId, $establishmentId),
                fn (string $learnerId): array => ['balance' => $this->debtors->balanceXaf($tenantId, $establishmentId, $learnerId)],
            ),
            Audience::ABSENT_TODAY => $this->viaGuarantors(
                $tenantId,
                $this->absentees?->absentLearnerIds($tenantId, $establishmentId, $this->clock->today()) ?? [],
                fn (): array => ['absence_date' => $this->clock->today()],
            ),
            default => [],
        };
    }

    /**
     * Nombre de destinataires et petit échantillon (prénom et initiale du nom, jamais de contact).
     *
     * @return array{count: int, sample: list<array{person_id: string, display_name: string}>}
     */
    public function preview(string $tenantId, string $establishmentId, Audience $audience): array
    {
        $recipients = $this->resolve($tenantId, $establishmentId, $audience);
        $head = array_slice($recipients, 0, 3);
        $people = $this->persons->findMany($tenantId, array_map(static fn (Recipient $r): string => $r->personId, $head));
        $byId = [];
        foreach ($people as $person) {
            $byId[$person->id] = $person;
        }

        $sample = [];
        foreach ($head as $recipient) {
            $person = $byId[$recipient->personId] ?? null;
            $sample[] = [
                'person_id' => $recipient->personId,
                'display_name' => $person === null ? '' : trim($person->firstName . ' ' . mb_substr($person->lastName, 0, 1) . '.'),
            ];
        }

        return ['count' => count($recipients), 'sample' => $sample];
    }

    /**
     * @param list<string> $personIds
     * @return list<Recipient>
     */
    private function known(string $tenantId, array $personIds): array
    {
        $recipients = [];
        foreach (array_chunk(array_values(array_unique($personIds)), self::CHUNK) as $chunk) {
            foreach ($this->persons->findMany($tenantId, $chunk) as $person) {
                $recipients[$person->id] = new Recipient($person->id);
            }
        }

        return array_values($recipients);
    }

    /**
     * @param list<string> $learnerIds
     * @param callable(string): array<string, int|string> $perLearner variables propres à chaque enfant ; « balance » est additionné
     * @return list<Recipient>
     */
    private function viaGuarantors(string $tenantId, array $learnerIds, callable $perLearner): array
    {
        $learnerIds = array_values(array_unique($learnerIds));
        $names = [];
        foreach (array_chunk($learnerIds, self::CHUNK) as $chunk) {
            foreach ($this->persons->findMany($tenantId, $chunk) as $learner) {
                $names[$learner->id] = $learner;
            }
        }

        /** @var array<string, array{children: list<string>, vars: array<string, int|string>}> $groups */
        $groups = [];
        foreach ($learnerIds as $learnerId) {
            $learner = $names[$learnerId] ?? null;
            if (!$learner instanceof PersonSummary) {
                continue;
            }
            $vars = $perLearner($learnerId);
            foreach ($this->persons->guarantorsOf($tenantId, $learnerId) as $guarantor) {
                $group = $groups[$guarantor->id] ?? ['children' => [], 'vars' => []];
                $group['children'][] = $learner->firstName;
                foreach ($vars as $name => $value) {
                    $previous = $group['vars'][$name] ?? null;
                    $group['vars'][$name] = is_int($value) && is_int($previous) ? $previous + $value : $value;
                }
                $groups[$guarantor->id] = $group;
            }
        }

        $recipients = [];
        foreach ($groups as $guarantorId => $group) {
            $recipients[] = new Recipient((string) $guarantorId, ['child_name' => implode(', ', array_unique($group['children']))] + $group['vars']);
        }

        return $recipients;
    }
}
