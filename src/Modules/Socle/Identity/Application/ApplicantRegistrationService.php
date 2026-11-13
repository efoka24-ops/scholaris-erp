<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\ApplicantRegistration;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\LazyEstablishmentDirectory;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Inscription publique d'un candidat. Le parcours reprend l'activation existante : le compte reste « en attente » jusqu'à la
 * saisie du code reçu, puis le candidat choisit son mot de passe. Les compteurs d'essais et de renvoi sont ceux de
 * ActivationService, tenus par identifiant : la réponse ne dit jamais si le compte existait.
 */
final class ApplicantRegistrationService implements ApplicantRegistration
{
    /** Rôle de libre-service attribué au candidat (R12) : il n'ouvre que ses propres données. */
    public const ROLE = 'apprenant';

    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly ActivationService $activation,
        private readonly PdoUserRepository $users,
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly LazyEstablishmentDirectory $directory,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function register(string $tenantId, string $establishmentId, string $firstName, string $lastName, string $identifier, string $locale = 'fr'): array
    {
        $parsed = Identifier::fromInput($identifier);
        if (!$parsed->valid) {
            throw HttpException::validation(['identifier' => ['Saisissez un numéro de téléphone valide (+237…) ou une adresse e-mail valide.']]);
        }
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $errors = [];
        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $errors['first_name'] = ['Le prénom est obligatoire (100 caractères au plus).'];
        }
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            $errors['last_name'] = ['Le nom est obligatoire (100 caractères au plus).'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($this->directory->find($tenantId, $establishmentId) === null) {
            throw HttpException::notFound();
        }

        $existing = array_values(array_filter(
            $this->users->findByIdentifier($parsed),
            static fn (User $user): bool => $user->tenantId === $tenantId,
        ));
        if ($existing === []) {
            try {
                $ids = $this->provisioner->provision(
                    $tenantId,
                    $firstName,
                    $lastName,
                    $parsed->isEmail ? null : $parsed->value,
                    $parsed->isEmail ? $parsed->value : null,
                    $locale,
                );
                $this->grant($tenantId, $establishmentId, $ids['person_id']);
                $this->audit->append($tenantId, null, 'identity.applicant_registered', 'user', $ids['user_id'], null, [
                    'person_id' => $ids['person_id'], 'establishment_id' => $establishmentId, 'person_linked' => $ids['person_linked'] ?? false,
                ]);
            } catch (HttpException $e) {
                // Course entre deux inscriptions du même contact : on retombe sur le compte qui vient d'être créé, sans le dire.
                if ($e->status !== 409) {
                    throw $e;
                }
            }
        } elseif ($existing[0]->status === User::PENDING) {
            // Inscription abandonnée avant la saisie du code : le code est renvoyé ; le rôle est ajouté seulement si le compte ne
            // porte que des rôles de libre-service (aucune élévation d'un compte invité par l'établissement).
            if ($this->onlySelfService($tenantId, $existing[0]->personId)) {
                $this->grant($tenantId, $establishmentId, $existing[0]->personId);
            }
        }

        return $this->activation->request($parsed, null, false, $tenantId);
    }

    private function onlySelfService(string $tenantId, string $personId): bool
    {
        foreach ($this->assignments->activeForPerson($tenantId, $personId, $this->moment->iso()) as $assignment) {
            if ($assignment->role !== self::ROLE) {
                return false;
            }
        }

        return true;
    }

    private function grant(string $tenantId, string $establishmentId, string $personId): void
    {
        foreach ($this->assignments->activeForPerson($tenantId, $personId, $this->moment->iso()) as $assignment) {
            if ($assignment->role === self::ROLE && $assignment->establishmentId === $establishmentId) {
                return;
            }
        }
        $this->assignments->insert(new RoleAssignment(
            (string) new Ulid(),
            $tenantId,
            $personId,
            self::ROLE,
            ScopeType::Establishment,
            $establishmentId,
            $establishmentId,
            $this->moment->iso(),
            null,
            null,
            null,
            $this->moment->iso(),
        ));
    }
}
