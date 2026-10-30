<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentWriter;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\People\Domain\EnrollmentStatus;
use Scholaris\Modules\Socle\People\Domain\PersonAttributes;
use Scholaris\Modules\Socle\People\Domain\RelationKind;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Réalisation de LearnerWriter sur PersonService, RelationService et EnrollmentWriter (mêmes règles que l'API). */
final class LearnerWriterService implements LearnerWriter
{
    private const CLOSING = [EnrollmentStatus::Transferred, EnrollmentStatus::Withdrawn];
    private const ONE_SCHOOL = [EnrollmentStatus::Active, EnrollmentStatus::Conditional, EnrollmentStatus::Suspended];

    public function __construct(
        private readonly PersonService $people,
        private readonly RelationService $relations,
        private readonly EnrollmentWriter $enrollments,
        private readonly PdoStore $store,
        private readonly PeopleClock $clock,
    ) {
    }

    public function createLearner(string $tenantId, array $draft, array $guardians, ?string $actorId, bool $confirmDifferent = false): array
    {
        $learner = $this->identity($draft, 'learner');
        $plans = [];
        foreach ($guardians as $index => $guardian) {
            $field = 'guardians.' . $index;
            $kind = RelationKind::tryFrom($guardian['kind']);
            if ($kind === null) {
                throw HttpException::validation([$field . '.kind' => ['Type de lien invalide : ' . implode(', ', RelationKind::values()) . '.']]);
            }
            $personId = $guardian['person_id'] ?? null;
            if (is_string($personId) && $personId !== '') {
                if ($this->people->find($tenantId, $personId) === null) {
                    throw HttpException::validation([$field . '.person_id' => ['Personne introuvable dans ce client.']]);
                }
                $plans[] = [$kind, $personId, null];
            } elseif (isset($guardian['identity']) && $guardian['identity'] !== []) {
                $plans[] = [$kind, null, $this->identity($guardian['identity'], $field . '.identity')];
            } else {
                throw HttpException::validation([$field => ['Indiquez person_id ou identity.']]);
            }
        }

        $duplicates = $confirmDifferent ? [] : $this->people->duplicates($tenantId, $learner);
        if ($duplicates !== []) {
            throw HttpException::conflict(sprintf(
                'Une personne existe peut-être déjà (fiche %s). Reprenez-la, ou confirmez qu\'il s\'agit d\'une personne différente.',
                $duplicates[0]['person']['id'],
            ));
        }

        return $this->store->transaction(function () use ($tenantId, $learner, $plans, $actorId): array {
            $learnerId = (string) $this->people->create($tenantId, $learner)['id'];
            $guardianIds = [];
            foreach ($plans as [$kind, $existingId, $identity]) {
                $guardianId = $existingId ?? (string) $this->people->create($tenantId, (array) $identity)['id'];
                $this->relations->add($tenantId, $learnerId, $guardianId, $kind, $actorId);
                $guardianIds[] = $guardianId;
            }

            return ['person_id' => $learnerId, 'guardian_ids' => $guardianIds];
        });
    }

    public function enroll(string $tenantId, string $establishmentId, string $personId, string $structureId, string $academicYearId, string $date, ?string $actorId): string
    {
        $this->assertDate($date, 'date');
        foreach ($this->store->where('enrollments', $tenantId, ['person_id' => $personId]) as $other) {
            if ($other['establishment_id'] !== $establishmentId && in_array(EnrollmentStatus::from((string) $other['status']), self::ONE_SCHOOL, true)) {
                throw HttpException::conflict('Cet élève a déjà une inscription en cours dans un autre établissement : clôturez-la (transfert) avant d\'inscrire.');
            }
        }

        $id = $this->enrollments->enrol($tenantId, $establishmentId, $personId, $structureId, $academicYearId, $actorId);
        $this->store->pdo()->prepare('UPDATE enrollments SET enrolled_on = ? WHERE tenant_id = ? AND id = ? AND enrolled_on IS NULL')
            ->execute([$date, $tenantId, $id]);

        return $id;
    }

    public function closeEnrollment(string $tenantId, string $enrollmentId, string $status, string $date): void
    {
        $target = EnrollmentStatus::tryFrom($status);
        if ($target === null || !in_array($target, self::CLOSING, true)) {
            throw HttpException::validation(['status' => ['Statut de clôture attendu : transferred ou withdrawn.']]);
        }
        $this->assertDate($date, 'date');
        $row = $this->store->find('enrollments', $tenantId, $enrollmentId) ?? throw HttpException::notFound('Inscription introuvable.');
        if (!in_array(EnrollmentStatus::from((string) $row['status']), self::ONE_SCHOOL, true)) {
            throw HttpException::conflict('Seule une inscription en cours peut être clôturée.');
        }

        $this->store->transaction(function () use ($tenantId, $enrollmentId, $target, $date): void {
            $this->store->update('enrollments', $tenantId, $enrollmentId, [
                'status' => $target->value,
                'closed_on' => $date,
                'documents_due_on' => null,
                'updated_at' => $this->clock->now(),
            ]);
            $this->store->pdo()->prepare('UPDATE schooling_history SET outcome = ? WHERE tenant_id = ? AND enrollment_id = ? AND outcome IS NULL')
                ->execute([$target->value, $tenantId, $enrollmentId]);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function identity(array $data, string $field): array
    {
        $unknown = array_diff(array_keys($data), PersonAttributes::FIELDS);
        [$values, $errors] = PersonAttributes::validate(array_diff_key($data, array_flip($unknown)), $this->clock->today());
        foreach ($unknown as $name) {
            $errors[(string) $name] = ['Champ non autorisé.'];
        }
        if ($errors !== []) {
            $prefixed = [];
            foreach ($errors as $name => $messages) {
                $prefixed[$field . '.' . $name] = $messages;
            }
            throw HttpException::validation($prefixed);
        }

        return $values;
    }

    private function assertDate(string $date, string $field): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw HttpException::validation([$field => ['Date au format AAAA-MM-JJ attendue.']]);
        }
    }
}
