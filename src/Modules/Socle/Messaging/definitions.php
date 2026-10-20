<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;

use Psr\Container\ContainerInterface;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Messaging\Application\AudienceResolver;
use Scholaris\Modules\Socle\Messaging\Application\MessagingClock;
use Scholaris\Modules\Socle\Messaging\Application\MessengerImpl;
use Scholaris\Modules\Socle\Messaging\Application\SafetyMessengerImpl;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\Messaging\Contracts\VoiceGateway;
use Scholaris\Modules\Socle\Messaging\Infrastructure\RoleBasedDirectionDirectory;
use Scholaris\Modules\Socle\Messaging\Infrastructure\DisabledVoiceGateway;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Liaisons du module Messaging. AbsenteeDirectory n'est pas lié ici : le module Attendance le fournira
 * (sans lui, le ciblage « absents du jour » ne désigne personne). DirectionDirectory s'appuie sur les rôles d'Identity
 * (directeur, directeur adjoint) ; sans titulaire de ces rôles, aucune conversation « direction-parent » n'est possible.
 */
return [
    Messenger::class => autowire(MessengerImpl::class),
    SafetyMessenger::class => autowire(SafetyMessengerImpl::class),
    \Scholaris\Modules\Socle\Messaging\Contracts\OptOutRegistry::class => autowire(\Scholaris\Modules\Socle\Messaging\Infrastructure\PdoOptOutRegistry::class),
    \Scholaris\Modules\Socle\Messaging\Contracts\TerritorialAnnouncer::class => autowire(\Scholaris\Modules\Socle\Messaging\Application\TerritorialAnnouncerService::class),
    VoiceGateway::class => autowire(DisabledVoiceGateway::class),
    \Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier::class => autowire(\Scholaris\Modules\Socle\Messaging\Application\PlatformDirectNotifier::class),
    DirectionDirectory::class => autowire(RoleBasedDirectionDirectory::class),
    AudienceResolver::class => factory(static fn (ContainerInterface $c): AudienceResolver => new AudienceResolver(
        $c->get(PersonDirectory::class),
        $c->get(EnrollmentDirectory::class),
        $c->get(DebtorDirectory::class),
        $c->get(MessagingClock::class),
        $c->has(AbsenteeDirectory::class) ? $c->get(AbsenteeDirectory::class) : null,
    )),

    'privacy.tenant_data_source.messaging' => \DI\autowire(\Scholaris\Modules\Socle\Messaging\Infrastructure\PrivacyExportSource::class),
    'privacy.subject_data_source.messaging' => \DI\autowire(\Scholaris\Modules\Socle\Messaging\Infrastructure\PrivacySubjectSource::class),
];
