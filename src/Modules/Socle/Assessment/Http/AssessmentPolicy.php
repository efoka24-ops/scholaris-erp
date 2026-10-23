<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Autorisations du module Assessment. Refus par défaut : sans permission explicite accordée par Identity, la réponse est 403. */
final class AssessmentPolicy
{
    public const READ = 'assessment.read';
    public const ENTER = 'assessment.enter';
    public const VALIDATE = 'assessment.validate';
    public const PUBLISH = 'assessment.publish';
    public const CORRECTION_APPROVE = 'assessment.correction.approve';
    public const COUNCIL = 'assessment.council.manage';
    public const DELIBERATION = 'assessment.deliberation.manage';
    public const DELIBERATION_SIGN = 'assessment.deliberation.sign';

    /** Toute permission du module donne la lecture des épreuves, grilles et statistiques. */
    private const READERS = [self::READ, self::ENTER, self::VALIDATE, self::PUBLISH, self::CORRECTION_APPROVE, self::COUNCIL, self::DELIBERATION];

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function can(Principal $principal, string $establishmentId, string ...$anyOf): bool
    {
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return true;
            }
        }

        return false;
    }

    public function canRead(Principal $principal, string $establishmentId): bool
    {
        return $this->can($principal, $establishmentId, ...self::READERS);
    }

    public function authorizeRead(Principal $principal, string $establishmentId): void
    {
        $this->authorize($principal, $establishmentId, ...self::READERS);
    }

    public function authorize(Principal $principal, string $establishmentId, string ...$anyOf): void
    {
        if (!$this->can($principal, $establishmentId, ...$anyOf)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit d\'effectuer cette opération sur les évaluations.');
        }
    }
}
