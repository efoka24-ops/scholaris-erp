<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;
use function DI\get;

use Scholaris\Modules\Socle\Organisation\Application\DefaultApplications;
use Scholaris\Modules\Socle\Organisation\Application\SettingsService;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Infrastructure\PdoEnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Infrastructure\PdoEstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Infrastructure\PdoModuleAvailability;

/** Liaisons du module Organisation : contrats publics => implémentations. */
return [
    EstablishmentDirectory::class => autowire(PdoEstablishmentDirectory::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory::class => autowire(\Scholaris\Modules\Socle\Organisation\Infrastructure\PdoTerritoryDirectory::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod::class => autowire(\Scholaris\Modules\Socle\Organisation\Infrastructure\PdoCurrentPeriod::class),
    EnrolmentGate::class => autowire(PdoEnrolmentGate::class),
    ModuleAvailability::class => autowire(PdoModuleAvailability::class),
    SettingsReader::class => get(SettingsService::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter::class => get(SettingsService::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\TerritorySettings::class => autowire(\Scholaris\Modules\Socle\Organisation\Application\TerritorySettingsService::class),
    // Contrats « application » (Supérieur, vague 0) : écriture des structures (D1), autorisations (D2), gardes d'inscription (D3),
    // paramètres par unité (D6), arbre de l'établissement (S15).
    \Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter::class => get(\Scholaris\Modules\Socle\Organisation\Application\StructureWriterService::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry::class => get(\Scholaris\Modules\Socle\Organisation\Application\AuthorizationRegistryService::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards::class => get(PdoEnrolmentGate::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader::class => get(SettingsService::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree::class => get(\Scholaris\Modules\Socle\Organisation\Infrastructure\PdoOrgUnitTree::class),
    ApplicationRegistry::class => factory(static fn (): ApplicationRegistry => DefaultApplications::registry()),
    // Plateforme (module Platform) : intégration d'un client et activation des modules requis par une application.
    \Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning::class => get(\Scholaris\Modules\Socle\Organisation\Application\ClientOnboarding::class),
    \Scholaris\Modules\Socle\Organisation\Contracts\ModuleActivator::class => get(\Scholaris\Modules\Socle\Organisation\Application\ClientOnboarding::class),

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.organisation' => \DI\autowire(\Scholaris\Modules\Socle\Organisation\Infrastructure\PrivacyExportSource::class),
];
