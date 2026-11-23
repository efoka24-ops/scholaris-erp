<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumbering;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumberProvider;
use Scholaris\Modules\Socle\People\Infrastructure\ListProviders;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Attribution unique de l'identifiant d'élève : la mise à jour ne réussit que si la colonne est encore vide. */
final class LearnerNumberingService implements LearnerNumbering
{
    public function __construct(
        private readonly PdoStore $store,
        private readonly ListProviders $providers,
        private readonly PeopleClock $clock,
    ) {
    }

    public function find(string $tenantId, string $personId): ?string
    {
        $person = $this->store->find('people', $tenantId, $personId);
        $number = $person['learner_number'] ?? null;

        return is_string($number) && $number !== '' ? $number : null;
    }

    public function assign(string $tenantId, string $personId, string $establishmentId): string
    {
        $person = $this->store->find('people', $tenantId, $personId) ?? throw HttpException::notFound('Personne introuvable.');
        if (is_string($person['learner_number'] ?? null) && $person['learner_number'] !== '') {
            throw HttpException::conflict('Un identifiant d\'élève est déjà attribué : il ne peut pas être modifié.');
        }

        $number = null;
        foreach ($this->providers->all(LearnerNumberProvider::PREFIX, LearnerNumberProvider::class) as $provider) {
            if ($provider->supports($tenantId, $establishmentId, $personId)) {
                $number = $provider->next($tenantId, $establishmentId, $personId);
                break;
            }
        }
        if ($number === null) {
            throw HttpException::validation(['learner_number' => ['Aucun fournisseur d\'identifiant d\'élève n\'est configuré pour cet établissement.']]);
        }
        if ($number === '' || mb_strlen($number) > 40) {
            throw HttpException::validation(['learner_number' => ['Identifiant d\'élève invalide (40 caractères maximum).']]);
        }

        try {
            $statement = $this->store->pdo()->prepare(
                'UPDATE people SET learner_number = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND learner_number IS NULL',
            );
            $statement->execute([$number, $this->clock->now(), $tenantId, $personId]);
        } catch (PDOException $e) {
            if (str_starts_with((string) $e->getCode(), '23') || str_contains($e->getMessage(), 'UNIQUE')) {
                throw HttpException::conflict('Cet identifiant d\'élève est déjà attribué à une autre personne.');
            }
            throw $e;
        }
        if ($statement->rowCount() !== 1) {
            throw HttpException::conflict('Un identifiant d\'élève est déjà attribué : il ne peut pas être modifié.');
        }

        return $number;
    }
}
