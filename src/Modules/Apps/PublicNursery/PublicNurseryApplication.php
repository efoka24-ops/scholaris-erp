<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery;

use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationDescriptor;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Application Maternelle publique : manifeste lu par le catalogue de la plateforme (clé `platform.applications.public_nursery`)
 * et vocabulaire de l'interface. Le reste du module est découpé en sous-dossiers au fil des lots.
 */
final class PublicNurseryApplication
{
    public const KEY = 'public_nursery';
    public const TARGET = 'nursery';
    public const VERSION = '0.1.0';
    public const LABEL = 'Maternelle publique';
    public const CONTAINER_KEY = 'platform.applications.public_nursery';

    /** @var array<string, string> */
    public const VOCABULARY = [
        'learner' => 'enfant',
        'grouping' => 'section',
        'period' => 'période',
        'document' => 'document',
    ];

    private function __construct()
    {
    }

    public static function manifest(): ApplicationManifest
    {
        return new ApplicationManifest(
            key: self::KEY,
            label: self::LABEL,
            target: self::TARGET,
            status: ApplicationManifest::AVAILABLE,
            version: self::VERSION,
            requiredModules: ['people', 'attendance', 'messaging', 'documents', 'organisation'],
            monthlyPriceXaf: 30000,
            trialDays: 90,
            graceDays: 15,
        );
    }

    public static function descriptor(): ApplicationDescriptor
    {
        return new ApplicationDescriptor(self::TARGET, self::LABEL, self::VOCABULARY);
    }
}
