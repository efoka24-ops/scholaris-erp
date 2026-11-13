<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoPeopleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;
use Throwable;

/** Crée la fiche minimale d'une personne et son compte « en attente d'activation ». */
final class AccountProvisioningService implements AccountProvisioner
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly PdoPeopleRepository $people,
        private readonly PdoUserRepository $users,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function provision(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale = 'fr', ?string $personId = null): array
    {
        if ($personId !== null) {
            // Fiche désignée explicitement : elle doit exister dans ce client et ne pas avoir de compte.
            if ($this->people->find($tenantId, $personId) === null) {
                throw HttpException::notFound('Personne introuvable.');
            }
            if ($this->users->findByPerson($tenantId, $personId) !== null) {
                throw HttpException::conflict('Cette personne a déjà un compte.');
            }
        }
        $errors = [];
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $errors['first_name'] = ['Le prénom est obligatoire (100 caractères au plus).'];
        }
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            $errors['last_name'] = ['Le nom est obligatoire (100 caractères au plus).'];
        }
        $normalizedPhone = $phone === null || trim($phone) === '' ? null : Identifier::normalizePhone($phone);
        if ($phone !== null && trim($phone) !== '' && $normalizedPhone === null) {
            $errors['phone'] = ['Saisissez un numéro de téléphone valide (+237…).'];
        }
        $normalizedEmail = $email === null || trim($email) === '' ? null : Identifier::normalizeEmail($email);
        if ($email !== null && trim($email) !== '' && $normalizedEmail === null) {
            $errors['email'] = ['Saisissez une adresse e-mail valide.'];
        }
        if ($normalizedPhone === null && $normalizedEmail === null && !isset($errors['phone']) && !isset($errors['email'])) {
            $errors['phone'] = ['Indiquez un numéro de téléphone ou une adresse e-mail pour permettre l\'activation du compte.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($normalizedPhone !== null && $this->users->phoneTaken($tenantId, $normalizedPhone)) {
            throw HttpException::conflict('Ce numéro de téléphone est déjà utilisé par un autre compte.');
        }
        if ($normalizedEmail !== null && $this->users->emailTaken($tenantId, $normalizedEmail)) {
            throw HttpException::conflict('Cette adresse e-mail est déjà utilisée par un autre compte.');
        }

        $locale = $locale === 'en' ? 'en' : 'fr';
        $now = $this->moment->iso();
        $personId ??= $this->existingPerson($tenantId, $firstName, $lastName, $normalizedPhone, $normalizedEmail);
        $linked = $personId !== null;
        $pdo = $this->connections->pdo();
        $pdo->beginTransaction();
        try {
            $personId ??= $this->people->insert($tenantId, $firstName, $lastName, $normalizedPhone, $normalizedEmail, $locale, $now);
            $userId = $this->users->insertPending($tenantId, $personId, $normalizedPhone, $normalizedEmail, $locale, $now);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();

            throw HttpException::conflict('Un compte existe déjà avec ce téléphone ou cette adresse e-mail.');
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
        $this->audit->append($tenantId, null, 'identity.account_created', 'user', $userId, null, ['person_id' => $personId, 'person_linked' => $linked]);

        return ['person_id' => $personId, 'user_id' => $userId, 'person_linked' => $linked];
    }

    /**
     * Fiche déjà enregistrée (garant inscrit par le secrétariat, par exemple) : même téléphone ou e-mail, mêmes prénom et
     * nom (casse et espaces ignorés), sans compte. Rattachement seulement s'il n'y en a qu'une : jamais de choix arbitraire.
     */
    private function existingPerson(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email): ?string
    {
        $key = static fn (string $first, string $last): string => mb_strtolower(trim($first)) . '|' . mb_strtolower(trim($last));
        $wanted = $key($firstName, $lastName);
        $matches = [];
        foreach ($this->people->withContact($tenantId, $phone, $email) as $candidate) {
            if ($key($candidate['first_name'], $candidate['last_name']) === $wanted && $this->users->findByPerson($tenantId, $candidate['id']) === null) {
                $matches[] = $candidate['id'];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
