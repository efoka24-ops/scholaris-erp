<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Allergies alimentaires : lecture et écriture limitées au seul champ `allergies`. */
interface FoodAllergies
{
    /**
     * @param list<string> $learnerIds
     * @return array<string, string|null> learnerId => allergies
     */
    public function forLearners(string $tenantId, array $learnerIds): array;

    public function declare(string $tenantId, string $learnerId, ?string $text, string $actorId): void;
}