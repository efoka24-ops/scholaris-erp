<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Workshops;

use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Monde des tests Workshops : établissement ouvert de la fabrique, classe du 1er cycle (spécialité technique quand
 * l'enseignement est technique), élèves inscrits par une doublure d'inscriptions. Données fictives.
 */
abstract class WorkshopWorld extends SecondaryTestCase
{
    protected const YEAR = 'annee-2026';
    protected const SETUP = ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.profile.read'];
    protected const PERMISSIONS = [
        'secondary.workshops.read', 'secondary.workshops.manage', 'secondary.workshops.session',
        'secondary.stock.manage', 'secondary.workshops.costs.read', 'secondary.safety.manage', 'secondary.internships.manage',
    ];

    protected WorkshopEnrollments $enrollments;

    protected function setUp(): void
    {
        $this->enrollments = new WorkshopEnrollments();
        $this->bootSecondary([EnrollmentDirectory::class => $this->enrollments]);
    }

    /** Établissement ouvert avec les droits d'atelier et de paramétrage pour l'administrateur. */
    protected function school(string $code, string $tenantId = self::TA, string $userId = self::ADMIN): string
    {
        $school = $this->factory->opened($tenantId, $code);
        $this->grant($tenantId, $userId, $school, array_merge(self::SETUP, self::PERMISSIONS));

        return $school;
    }

    /**
     * Classe du 1er cycle ; en technique, avec la première spécialité de 1er cycle proposée à l'établissement.
     *
     * @param list<string> $learners élèves inscrits dans la classe pour l'année
     */
    protected function firstCycleClass(string $school, array $learners = [], string $tenantId = self::TA, string $userId = self::ADMIN): string
    {
        $levels = $this->ok('POST', '/secondary/setup/levels/examples', [], $userId, $tenantId, $school);
        $level = $levels[0];
        $body = ['label' => 'Classe fictive ' . $level['code'], 'level_id' => $level['id'], 'academic_year_id' => self::YEAR];
        if (($level['teaching_kind'] ?? 'general') === 'technical') {
            foreach ($this->service(StreamDirectory::class)->forEstablishment($tenantId, $school) as $stream) {
                if ($stream->teachingKind === 'technical' && $stream->cycleCode === 'first') {
                    $body['stream_id'] = $this->ok('POST', '/secondary/setup/streams', ['stream_ref_id' => $stream->id], $userId, $tenantId, $school)['id'];
                    break;
                }
            }
        }
        $classId = (string) $this->ok('POST', '/secondary/setup/classes', $body, $userId, $tenantId, $school)['structure_id'];
        foreach ($learners as $personId) {
            $this->enrollments->add($tenantId, $personId, $school, $classId, self::YEAR);
        }

        return $classId;
    }

    /**
     * Fiche d'exemple du CDC (5 critères 4/4/6/4/2 sur 20, plafond d'exemple 8) : valeurs fournies par le test, jamais par le code.
     *
     * @return array<string, mixed>
     */
    protected function exampleSheet(string $school, string $workshopId): array
    {
        return $this->ok('POST', '/secondary/workshops/sheets', [
            'workshop_id' => $workshopId, 'title' => 'TP exemple', 'objective' => 'Exercice fictif', 'materials' => ['Matière A'], 'tools' => ['Outil A'],
            'safety_rules' => ['Port des EPI requis'], 'criteria' => [
                ['code' => 'preparation', 'label' => 'Préparation', 'points' => 4], ['code' => 'safety', 'label' => 'Sécurité', 'points' => 4],
                ['code' => 'execution', 'label' => 'Exécution', 'points' => 6], ['code' => 'quality', 'label' => 'Qualité', 'points' => 4], ['code' => 'time', 'label' => 'Temps', 'points' => 2],
            ], 'base_score' => 20, 'safety_cap' => 8,
        ], self::ADMIN, self::TA, $school);
    }
}

/** Inscriptions fictives à l'année exacte de la classe. */
final class WorkshopEnrollments implements EnrollmentDirectory
{
    /** @var array<string, EnrollmentInfo> */
    private array $items = [];

    public function add(string $tenantId, string $personId, string $establishmentId, string $classId, string $yearId): void
    {
        $id = 'en-' . $tenantId . '-' . $personId . '-' . $classId;
        $this->items[$id] = new EnrollmentInfo($id, $tenantId, $personId, $establishmentId, $classId, $yearId, EnrollmentInfo::ACTIVE);
    }

    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
    {
        $enrollment = $this->items[$enrollmentId] ?? null;

        return $enrollment !== null && $enrollment->tenantId === $tenantId ? $enrollment : null;
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return array_values(array_filter($this->items, static fn (EnrollmentInfo $item): bool => $item->tenantId === $tenantId && $item->personId === $personId));
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $item): string => $item->personId, array_filter($this->items, static fn (EnrollmentInfo $item): bool => $item->tenantId === $tenantId && $item->structureId === $structureId)));
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return array_values(array_map(static fn (EnrollmentInfo $item): string => $item->personId, array_filter($this->items, static fn (EnrollmentInfo $item): bool => $item->tenantId === $tenantId && $item->establishmentId === $establishmentId)));
    }
}
