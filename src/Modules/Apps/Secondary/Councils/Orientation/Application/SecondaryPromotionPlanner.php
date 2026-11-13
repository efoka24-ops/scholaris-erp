<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\PromotionPlans;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentRegistry;
use Scholaris\Modules\Apps\Secondary\SecondaryApplication;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\ConditionalPromotionPlanner;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Passage de fin d'année d'un établissement secondaire (`organisation.closure.promotion.secondary`, T905, EX-SEC-14), qui remplace
 * le passage générique du socle pour le type `secondary` d'un client qui a ouvert l'application. Pour chaque élève inscrit dans
 * l'année close :
 *  - exclu définitif par un conseil de discipline (Enrolment) : « excluded », aucune classe ;
 *  - non admis par le conseil de fin d'année (AdmissionDirectory) : « repeat », même classe proposée ;
 *  - admis : classe supérieure du paramètre `people.promotion.next_structure` (même table que le socle) ; si l'orientation a fixé une
 *    série ou spécialité (décision du conseil, ou recours accepté) et que cette classe ne la porte pas, la classe du même niveau qui
 *    la porte (Setup) est retenue ; sans classe possible : « promote » sans classe, à placer par la scolarité.
 * Le résultat est rangé dans `se_promotion_plans` (contrat PromotionPlans) : la réinscription reste un acte de la scolarité
 * (RG-ELV-4, garde d'inscription, frais), rien n'est inscrit d'office. Idempotent ; une ligne appliquée n'est plus modifiée.
 */
final class SecondaryPromotionPlanner implements ConditionalPromotionPlanner, PromotionPlans
{
    public const CONTAINER_KEY = 'organisation.closure.promotion.secondary';
    private const TABLE = 'se_promotion_plans';
    private const NEXT = 'people.promotion.next_structure';
    private const LIVE = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL];

    public function __construct(
        private readonly Store $store,
        private readonly EnrollmentDirectory $enrollments,
        private readonly AdmissionDirectory $admissions,
        private readonly ClassProfiles $classes,
        private readonly StudentRegistry $students,
        private readonly SettingsReader $settings,
        private readonly ApplicationAccess $applications,
        private readonly SecondaryClock $clock,
    ) {
    }

    public function establishmentTypes(): array
    {
        return ['secondary'];
    }

    public function appliesTo(string $tenantId, string $establishmentId): bool
    {
        return $this->applications->allowsReads($tenantId, SecondaryApplication::KEY);
    }

    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        if (!$this->applications->allowsWrites($tenantId, SecondaryApplication::KEY)) {
            return new ClosureStepResult(ClosureStepResult::SKIPPED, 'Application Enseignement secondaire suspendue ou fermée : aucun passage préparé.');
        }
        $raw = $this->settings->value($tenantId, $establishmentId, self::NEXT, []);
        $next = is_array($raw) ? $raw : [];
        $admitted = array_flip($this->admissions->admittedPersonIds($tenantId, $establishmentId, $fromYearId));
        $targetYear = $this->classes->forYear($tenantId, $establishmentId, $toYearId);
        $counts = [self::PROMOTE => 0, self::REPEAT => 0, self::EXCLUDED => 0, 'no_next_class' => 0, 'oriented' => 0, 'already_applied' => 0];
        foreach ($this->enrolled($tenantId, $establishmentId, $fromYearId) as $personId => $classId) {
            $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'from_year_id' => $fromYearId], 'id', 1)[0] ?? null;
            if ($existing !== null && $existing['plan_status'] === 'applied') {
                ++$counts['already_applied'];
                continue;
            }
            $stream = null;
            $target = null;
            $profile = $this->students->profile($tenantId, $establishmentId, $personId);
            if ($profile !== null && $profile->finalExclusionOn !== null && $profile->readmissionYearId !== $toYearId) {
                [$outcome, $basis] = [self::EXCLUDED, 'discipline'];
            } elseif (!isset($admitted[$personId])) {
                [$outcome, $basis, $target] = [self::REPEAT, 'council', $classId];
            } else {
                [$outcome, $basis] = [self::PROMOTE, 'council'];
                $mapped = $next[$classId] ?? null;
                $target = is_string($mapped) && $mapped !== '' ? $mapped : null;
                $stream = $this->orientedStream($tenantId, $establishmentId, $personId, $fromYearId);
                if ($stream !== null) {
                    $basis = 'orientation';
                    ++$counts['oriented'];
                    $target = $this->classForStream($tenantId, $establishmentId, $target, $stream, $targetYear);
                }
                $target === null && ++$counts['no_next_class'];
            }
            ++$counts[$outcome];
            $this->save($tenantId, $establishmentId, $existing, $personId, $fromYearId, $toYearId, $classId, $outcome, $target, $stream, $basis);
        }
        $summary = sprintf(
            'Passage préparé : %d admis (dont %d selon l’orientation, %d sans classe d’accueil), %d redoublement(s), %d exclusion(s) ; la réinscription reste à faire par la scolarité.',
            $counts[self::PROMOTE],
            $counts['oriented'],
            $counts['no_next_class'],
            $counts[self::REPEAT],
            $counts[self::EXCLUDED],
        );

        return new ClosureStepResult(ClosureStepResult::DONE, $summary, $counts);
    }

    public function forYear(string $tenantId, string $establishmentId, string $fromYearId): array
    {
        return array_map(static fn (array $row): array => [
            'person_id' => (string) $row['person_id'], 'from_class_id' => (string) $row['from_class_id'], 'outcome' => (string) $row['outcome'],
            'target_class_id' => $row['target_class_id'] === null ? null : (string) $row['target_class_id'],
            'stream_id' => $row['stream_id'] === null ? null : (string) $row['stream_id'], 'basis' => (string) $row['basis'], 'plan_status' => (string) $row['plan_status'],
        ], $this->store->select(self::TABLE, $tenantId, $establishmentId, ['from_year_id' => $fromYearId], 'from_class_id, person_id', 10000));
    }

    /** @return array<string, string> élève => classe de l'année close (inscription vivante) */
    private function enrolled(string $tenantId, string $establishmentId, string $fromYearId): array
    {
        $out = [];
        foreach (array_values(array_unique($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId))) as $personId) {
            foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
                if ($enrollment->tenantId === $tenantId && $enrollment->establishmentId === $establishmentId && $enrollment->academicYearId === $fromYearId
                    && $enrollment->structureId !== null && in_array($enrollment->status, self::LIVE, true)) {
                    $out[$personId] = $enrollment->structureId;
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** Série ou spécialité retenue pour l'année suivante : dossier d'orientation (décision ou recours accepté), sinon dernière décision. */
    private function orientedStream(string $tenantId, string $establishmentId, string $personId, string $fromYearId): ?string
    {
        $case = $this->store->select('se_orientations', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $fromYearId], 'created_at DESC', 1)[0] ?? null;
        if ($case !== null && is_string($case['final_stream_id'] ?? null) && $case['final_stream_id'] !== '') {
            return (string) $case['final_stream_id'];
        }
        $decision = $this->store->select('se_orientation_decisions', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $fromYearId], 'created_at DESC', 1)[0] ?? null;

        return $decision === null ? null : (string) $decision['stream_id'];
    }

    /**
     * @param list<ClassProfileInfo> $targetYear
     */
    private function classForStream(string $tenantId, string $establishmentId, ?string $mapped, string $streamId, array $targetYear): ?string
    {
        $profile = $mapped === null ? null : $this->classes->find($tenantId, $establishmentId, $mapped);
        if ($profile !== null && $profile->streamId === $streamId) {
            return $mapped;
        }
        foreach ($targetYear as $class) {
            if ($class->streamId === $streamId && ($profile === null || $class->levelCode === $profile->levelCode)) {
                return $class->structureId;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $existing */
    private function save(string $tenantId, string $establishmentId, ?array $existing, string $personId, string $fromYearId, string $toYearId, string $classId, string $outcome, ?string $target, ?string $stream, string $basis): void
    {
        $now = $this->clock->now();
        $values = ['to_year_id' => $toYearId, 'from_class_id' => $classId, 'outcome' => $outcome, 'target_class_id' => $target, 'stream_id' => $stream, 'basis' => $basis, 'updated_at' => $now];
        if ($existing !== null) {
            $this->store->update(self::TABLE, $tenantId, $establishmentId, $values, ['id' => (string) $existing['id']]);

            return;
        }
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $values + [
            'id' => (string) new Ulid(), 'person_id' => $personId, 'from_year_id' => $fromYearId, 'plan_status' => 'planned', 'created_at' => $now,
        ]);
    }
}
