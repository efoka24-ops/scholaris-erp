<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\GuarantorLinker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Domain\Names;
use Scholaris\Modules\Socle\People\Domain\PersonAttributes;
use Scholaris\Modules\Socle\People\Domain\RelationKind;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Réalisation de GuarantorLinker (C-118) sur PersonService et RelationService : mêmes contrôles que les routes `/people`. */
final class GuarantorLinkerService implements GuarantorLinker
{
    public function __construct(
        private readonly PdoStore $store,
        private readonly PersonService $people,
        private readonly RelationService $relations,
        private readonly PersonDirectory $directory,
        private readonly PeopleClock $clock,
    ) {
    }

    public function attachFinancialGuarantor(string $tenantId, string $learnerId, string $phone, array $identity, ?string $actorId): array
    {
        if ($this->people->find($tenantId, $learnerId) === null) {
            throw HttpException::validation(['learner_id' => ['Élève introuvable dans ce client.']]);
        }
        $clean = $this->phone($phone);

        return $this->store->transaction(function () use ($tenantId, $learnerId, $clean, $identity, $actorId): array {
            $personId = $this->personIdByPhone($tenantId, $clean, $learnerId);
            $created = false;
            if ($personId === null) {
                $lastName = trim((string) ($identity['last_name'] ?? ''));
                $firstName = trim((string) ($identity['first_name'] ?? ''));
                [$values, $errors] = PersonAttributes::validate(
                    ['last_name' => $lastName, 'first_name' => $firstName === '' ? $lastName : $firstName, 'phone' => $clean],
                    $this->clock->today(),
                );
                if ($errors !== []) {
                    throw HttpException::validation(array_combine(
                        array_map(static fn (string|int $field): string => 'identity.' . $field, array_keys($errors)),
                        array_values($errors),
                    ));
                }
                $personId = (string) $this->people->create($tenantId, $values)['id'];
                $created = true;
            }
            $kind = RelationKind::FinancialGuarantor;
            $linked = $this->store->count('person_relations', $tenantId, ['person_id' => $learnerId, 'related_person_id' => $personId, 'kind' => $kind->value]) > 0;
            if (!$linked) {
                $this->relations->add($tenantId, $learnerId, $personId, $kind, $actorId);
            }

            return ['person_id' => $personId, 'created' => $created, 'already_linked' => $linked];
        });
    }

    public function personIdByPhone(string $tenantId, string $phone, ?string $exceptPersonId = null): ?string
    {
        $key = Names::phoneKey($this->phone($phone));
        $matches = [];
        foreach ($this->store->rows('SELECT id, phone FROM people WHERE tenant_id = ? AND phone LIKE ?', [$tenantId, '%' . substr((string) $key, -9)]) as $row) {
            if ($row['id'] !== $exceptPersonId && Names::phoneKey((string) $row['phone']) === $key) {
                $matches[] = (string) $row['id'];
            }
        }
        if (count($matches) > 1) {
            throw HttpException::conflict('Plusieurs personnes portent ce téléphone : rattachez le garant depuis sa fiche.');
        }

        return $matches[0] ?? null;
    }

    public function financialGuarantorsOf(string $tenantId, string $learnerId): array
    {
        $rows = $this->store->rows(
            "SELECT related_person_id FROM person_relations WHERE tenant_id = ? AND person_id = ? AND kind = 'financial_guarantor'",
            [$tenantId, $learnerId],
        );

        return $this->directory->findMany($tenantId, array_map(static fn (array $r): string => (string) $r['related_person_id'], $rows));
    }

    private function phone(string $phone): string
    {
        [$values, $errors] = PersonAttributes::validate(['phone' => $phone], $this->clock->today(), true);
        if ($errors !== [] || !is_string($values['phone'] ?? null)) {
            throw HttpException::validation(['phone' => ['Numéro de téléphone invalide (8 à 15 chiffres, « + » facultatif).']]);
        }

        return $values['phone'];
    }
}
