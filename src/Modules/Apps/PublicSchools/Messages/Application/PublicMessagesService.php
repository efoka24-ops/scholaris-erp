<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Application;

use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Implémentation du contrat PublicMessages (T640) au-dessus du Messenger du socle : le consentement, les désinscriptions et les
 * plages horaires sont appliqués par le socle ; les messages « essentiels » (fermeture de l'école, sécurité : RG-COM-2) sont les
 * modèles de plateforme de catégorie `essential`, dont la liste est `public_schools.messages.essential_templates`. Une panne de
 * messagerie ne remonte jamais à l'appelant : le destinataire n'est simplement pas compté.
 */
final class PublicMessagesService implements PublicMessages
{
    /** Directeur de l'école publique (R09) : rôle de la migration des rôles des écoles publiques. */
    public const DIRECTOR_ROLES = ['pub_school_director'];

    private const DEFAULT_ESSENTIAL = ['pub.not14.parent'];

    public function __construct(
        private readonly Messenger $messenger,
        private readonly PersonDirectory $people,
        private readonly ClassRoster $classes,
        private readonly RoleDirectory $roles,
        private readonly EstablishmentDirectory $establishments,
        private readonly SettingsReader $settings,
        private readonly MessageClock $clock,
    ) {
    }

    public function isEssential(string $tenantId, string $templateKey): bool
    {
        return in_array($templateKey, $this->essentialTemplates($tenantId), true);
    }

    public function notifyGuardians(string $tenantId, string $establishmentId, string $pupilPersonId, string $templateKey, array $variables = []): int
    {
        $child = $this->people->find($tenantId, $pupilPersonId);
        if ($child === null) {
            return 0;
        }
        $variables += ['child_name' => $child->fullName(), 'establishment_name' => $this->establishmentName($tenantId, $establishmentId)];
        $reached = 0;
        foreach ($this->people->guarantorsOf($tenantId, $pupilPersonId) as $guardian) {
            if ($this->deliver($tenantId, $establishmentId, $guardian->id, $templateKey, $variables)) {
                ++$reached;
            }
        }

        return $reached;
    }

    public function notifyClass(string $tenantId, string $establishmentId, string $structureId, string $templateKey, array $variables = []): int
    {
        $class = $this->classes->find($tenantId, $establishmentId, $structureId);
        if ($class === null) {
            return 0;
        }
        $variables += ['class_name' => $class->label];
        $reached = 0;
        foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $this->clock->today()) as $pupilId) {
            $reached += $this->notifyGuardians($tenantId, $establishmentId, $pupilId, $templateKey, $variables);
        }

        return $reached;
    }

    public function notifyDirector(string $tenantId, string $establishmentId, string $templateKey, array $variables = []): bool
    {
        $variables += ['establishment_name' => $this->establishmentName($tenantId, $establishmentId)];
        $reached = false;
        foreach ($this->roles->personIdsWithRole($tenantId, $establishmentId, self::DIRECTOR_ROLES) as $personId) {
            $reached = $this->deliver($tenantId, $establishmentId, $personId, $templateKey, $variables) || $reached;
        }

        return $reached;
    }

    /** @param array<string, scalar> $variables */
    private function deliver(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables): bool
    {
        try {
            $result = $this->messenger->send($tenantId, $establishmentId, $personId, $templateKey, $variables, [], $this->isEssential($tenantId, $templateKey));
        } catch (Throwable) {
            return false;
        }

        return $result->status !== DeliveryResult::BLOCKED;
    }

    /** @return list<string> */
    private function essentialTemplates(string $tenantId): array
    {
        $value = $this->settings->value($tenantId, null, 'public_schools.messages.essential_templates', self::DEFAULT_ESSENTIAL);

        return is_array($value) ? array_values(array_map('strval', $value)) : self::DEFAULT_ESSENTIAL;
    }

    private function establishmentName(string $tenantId, string $establishmentId): string
    {
        $found = $this->establishments->findMany($tenantId, [$establishmentId]);

        return $found === [] ? '' : $found[0]->name;
    }
}
