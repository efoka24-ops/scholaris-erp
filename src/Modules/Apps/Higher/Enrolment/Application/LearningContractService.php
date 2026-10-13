<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumUnitInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\EnrolmentStore;

/**
 * Inscription pédagogique et contrat pédagogique (EX-INS-02, T421) sur la version de maquette mémorisée par l'inscription
 * (RG-MAQ-1) : UE obligatoires d'office ; UE optionnelles choisies par groupe de choix (« N parmi M », decisions.md R14) ;
 * UE capitalisées reportées sans être repassées et UE en dette de l'année N inscrites d'office (RG-INS-4) ; équivalences de
 * transfert reportées comme capitalisées.
 */
final class LearningContractService
{
    public const CONTRACTS = 'hi_learning_contracts';
    public const ITEMS = 'hi_learning_contract_items';
    public const NORMAL = 'normal';
    public const OPTIONAL = 'optional';
    public const DEBT = 'debt';
    public const CAPITALIZED = 'capitalized';

    public function __construct(
        private readonly EnrolmentStore $store,
        private readonly CurriculumDirectory $curricula,
        private readonly EnrolmentClock $clock,
    ) {
    }

    /**
     * Contrat initial (ou reconstruit après un changement de filière) : remplace le précédent s'il n'est pas arrêté.
     *
     * @param array<string, mixed> $enrolment
     * @param list<string> $capitalizedCodes UE acquises reportées (redoublement, équivalences)
     * @param list<array{code: string, title: string, credits: int, semester: ?int, level_id: ?string, unit_id: ?string}> $debts
     */
    public function build(array $enrolment, array $capitalizedCodes, array $debts): void
    {
        $tenantId = (string) $enrolment['tenant_id'];
        $establishmentId = (string) $enrolment['establishment_id'];
        $enrolmentId = (string) $enrolment['id'];
        $now = $this->clock->now();
        $existing = $this->contract($tenantId, $establishmentId, $enrolmentId);
        if ($existing !== null) {
            $this->store->delete(self::ITEMS, $tenantId, $establishmentId, ['contract_id' => $existing['id']]);
            $this->store->delete(self::CONTRACTS, $tenantId, $establishmentId, ['id' => $existing['id']]);
        }
        $contractId = (string) new Ulid();
        $this->store->insert(self::CONTRACTS, $tenantId, $establishmentId, [
            'id' => $contractId, 'enrolment_id' => $enrolmentId, 'curriculum_id' => $enrolment['curriculum_id'],
            'curriculum_version' => $enrolment['curriculum_version'], 'status' => 'draft', 'finalized_by' => null, 'finalized_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $capitalized = array_flip($capitalizedCodes);
        foreach ($this->units($enrolment) as $unit) {
            if ($unit->choiceGroup !== null && !isset($capitalized[$unit->code])) {
                continue;
            }
            $this->addItem($enrolment, $contractId, $unit->id, $unit->code, $unit->title, $unit->credits, $unit->semesterOrdinal, isset($capitalized[$unit->code]) ? self::CAPITALIZED : self::NORMAL, $unit->choiceGroup, (string) $enrolment['level_id']);
        }
        foreach ($debts as $debt) {
            $this->addItem($enrolment, $contractId, $debt['unit_id'], $debt['code'], $debt['title'], $debt['credits'], $debt['semester'], self::DEBT, null, $debt['level_id']);
        }
    }

    /**
     * Choix des UE optionnelles (par groupe de choix : au plus `choice_count`, exactement à l'arrêt du contrat).
     *
     * @param array<string, mixed> $enrolment
     * @param list<string> $optionalUnitIds
     */
    public function choose(array $enrolment, array $optionalUnitIds, bool $finalize, string $actorId): void
    {
        $tenantId = (string) $enrolment['tenant_id'];
        $establishmentId = (string) $enrolment['establishment_id'];
        $contract = $this->contract($tenantId, $establishmentId, (string) $enrolment['id']) ?? throw HttpException::notFound('Contrat pédagogique introuvable.');
        if ($contract['status'] === 'final') {
            throw HttpException::conflict('Contrat pédagogique arrêté : aucun changement.');
        }
        $groups = [];
        $byId = [];
        foreach ($this->units($enrolment) as $unit) {
            if ($unit->choiceGroup !== null) {
                $groups[$unit->choiceGroup] = $unit->choiceCount ?? 1;
                $byId[$unit->id] = $unit;
            }
        }
        $chosen = [];
        $errors = [];
        foreach (array_unique($optionalUnitIds) as $unitId) {
            $unit = $byId[$unitId] ?? null;
            if ($unit === null) {
                $errors[] = 'UE ' . $unitId . ' : pas une UE à choix de la maquette de l\'inscription.';
                continue;
            }
            $chosen[(string) $unit->choiceGroup][] = $unit;
        }
        $capitalized = [];
        foreach ($this->store->select(self::ITEMS, $tenantId, $establishmentId, ['contract_id' => $contract['id'], 'item_origin' => self::CAPITALIZED]) as $item) {
            if ($item['choice_group'] !== null) {
                $capitalized[(string) $item['choice_group']] = ($capitalized[(string) $item['choice_group']] ?? 0) + 1;
            }
        }
        foreach ($groups as $group => $count) {
            $selected = count($chosen[$group] ?? []) + ($capitalized[$group] ?? 0);
            if ($selected > $count || ($finalize && $selected !== $count)) {
                $errors[] = sprintf('Groupe de choix %s : %d UE à choisir, %d choisie(s).', $group, $count, $selected);
            }
        }
        if ($errors !== []) {
            throw HttpException::validation(['optional_unit_ids' => $errors], 'Choix des UE optionnelles invalide.');
        }
        $this->store->transaction(function () use ($tenantId, $establishmentId, $contract, $chosen, $enrolment, $finalize, $actorId): void {
            $this->store->delete(self::ITEMS, $tenantId, $establishmentId, ['contract_id' => $contract['id'], 'item_origin' => self::OPTIONAL]);
            foreach ($chosen as $units) {
                foreach ($units as $unit) {
                    $this->addItem($enrolment, (string) $contract['id'], $unit->id, $unit->code, $unit->title, $unit->credits, $unit->semesterOrdinal, self::OPTIONAL, $unit->choiceGroup, (string) $enrolment['level_id']);
                }
            }
            $this->store->update(self::CONTRACTS, $tenantId, $establishmentId, [
                'status' => $finalize ? 'final' : 'draft', 'finalized_by' => $finalize ? $actorId : null,
                'finalized_at' => $finalize ? $this->clock->now() : null, 'updated_at' => $this->clock->now(),
            ], ['id' => $contract['id']]);
        });
    }

    /**
     * Contrat avec ses UE, crédits par origine et UE à choix disponibles.
     *
     * @param array<string, mixed> $enrolment
     * @return array<string, mixed>
     */
    public function show(array $enrolment): array
    {
        $tenantId = (string) $enrolment['tenant_id'];
        $establishmentId = (string) $enrolment['establishment_id'];
        $contract = $this->contract($tenantId, $establishmentId, (string) $enrolment['id']) ?? throw HttpException::notFound('Contrat pédagogique introuvable.');
        $items = [];
        $credits = [self::NORMAL => 0, self::OPTIONAL => 0, self::DEBT => 0, self::CAPITALIZED => 0];
        foreach ($this->store->select(self::ITEMS, $tenantId, $establishmentId, ['contract_id' => $contract['id']], 'semester_ordinal, unit_code') as $item) {
            $credits[(string) $item['item_origin']] += (int) $item['credits'];
            $items[] = [
                'curriculum_unit_id' => $item['curriculum_unit_id'], 'unit_code' => $item['unit_code'], 'unit_title' => $item['unit_title'],
                'credits' => (int) $item['credits'], 'semester_ordinal' => $item['semester_ordinal'] === null ? null : (int) $item['semester_ordinal'],
                'origin' => $item['item_origin'], 'choice_group' => $item['choice_group'], 'source_level_id' => $item['source_level_id'],
            ];
        }
        $options = [];
        foreach ($this->units($enrolment) as $unit) {
            if ($unit->choiceGroup !== null) {
                $options[] = ['curriculum_unit_id' => $unit->id, 'code' => $unit->code, 'title' => $unit->title, 'credits' => $unit->credits, 'choice_group' => $unit->choiceGroup, 'choice_count' => $unit->choiceCount];
            }
        }

        return [
            'id' => $contract['id'], 'enrolment_id' => $enrolment['id'], 'curriculum_id' => $contract['curriculum_id'],
            'curriculum_version' => $contract['curriculum_version'] === null ? null : (int) $contract['curriculum_version'], 'status' => $contract['status'],
            'finalized_at' => $contract['finalized_at'], 'items' => $items, 'credits_by_origin' => $credits,
            'credits_to_take' => $credits[self::NORMAL] + $credits[self::OPTIONAL] + $credits[self::DEBT], 'choices' => $options,
        ];
    }

    /**
     * UE d'une version de maquette, pour retrouver l'intitulé et les crédits des dettes (UE de l'année N).
     *
     * @return list<CurriculumUnitInfo>
     */
    public function unitsOf(string $tenantId, string $establishmentId, ?string $curriculumId): array
    {
        return $curriculumId === null ? [] : $this->curricula->units($tenantId, $establishmentId, $curriculumId);
    }

    /** @return array<string, mixed>|null */
    private function contract(string $tenantId, string $establishmentId, string $enrolmentId): ?array
    {
        return $this->store->select(self::CONTRACTS, $tenantId, $establishmentId, ['enrolment_id' => $enrolmentId], 'id', 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $enrolment
     * @return list<CurriculumUnitInfo>
     */
    private function units(array $enrolment): array
    {
        return $this->unitsOf((string) $enrolment['tenant_id'], (string) $enrolment['establishment_id'], isset($enrolment['curriculum_id']) ? (string) $enrolment['curriculum_id'] : null);
    }

    /** @param array<string, mixed> $enrolment */
    private function addItem(array $enrolment, string $contractId, ?string $unitId, string $code, string $title, int $credits, ?int $semester, string $origin, ?string $choiceGroup, ?string $levelId): void
    {
        $this->store->insert(self::ITEMS, (string) $enrolment['tenant_id'], (string) $enrolment['establishment_id'], [
            'id' => (string) new Ulid(), 'contract_id' => $contractId, 'enrolment_id' => $enrolment['id'], 'curriculum_unit_id' => $unitId,
            'unit_code' => $code, 'unit_title' => $title, 'credits' => $credits, 'semester_ordinal' => $semester, 'item_origin' => $origin,
            'choice_group' => $choiceGroup, 'source_level_id' => $levelId, 'created_at' => $this->clock->now(),
        ]);
    }
}
