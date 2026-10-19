<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\People\Application\BirthDateWriterService;
use Scholaris\Modules\Socle\People\Application\EnrollmentWriterService;
use Scholaris\Modules\Socle\People\Application\YearPromotion;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;
use Scholaris\Modules\Socle\People\Contracts\BirthDateWriter;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentWriter;
use Scholaris\Modules\Socle\People\Contracts\FileScanner;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\People\Domain\UrlSigner;
use Scholaris\Modules\Socle\People\Infrastructure\FileAttachmentStore;
use Scholaris\Modules\Socle\People\Infrastructure\FileStorage;
use Scholaris\Modules\Socle\People\Infrastructure\HealthCipher;
use Scholaris\Modules\Socle\People\Infrastructure\HeuristicFileScanner;
use Scholaris\Modules\Socle\People\Infrastructure\PdoBirthInfo;
use Scholaris\Modules\Socle\People\Infrastructure\PdoConsentChecker;
use Scholaris\Modules\Socle\People\Infrastructure\PdoEnrollmentDirectory;
use Scholaris\Modules\Socle\People\Infrastructure\PdoPersonDirectory;
use Scholaris\Modules\Socle\People\Infrastructure\Secrets;
use Scholaris\Modules\Socle\People\Infrastructure\StaffTeachingScope;

/**
 * Liaisons du module People. Réglages facultatifs : `people.secret_key` (à défaut, variable d'environnement APP_KEY),
 * `people.storage_path` (à défaut storage/uploads, hors racine web), `people.link_ttl` (secondes, 300 par défaut).
 */
return [
    PersonDirectory::class => autowire(PdoPersonDirectory::class),
    EnrollmentDirectory::class => autowire(PdoEnrollmentDirectory::class),
    EnrollmentWriter::class => autowire(EnrollmentWriterService::class),
    BirthDateWriter::class => autowire(BirthDateWriterService::class),
    ConsentChecker::class => autowire(PdoConsentChecker::class),
    BirthInfo::class => autowire(PdoBirthInfo::class),
    \Scholaris\Modules\Socle\People\Contracts\PersonDemographics::class => autowire(\Scholaris\Modules\Socle\People\Infrastructure\PdoPersonDemographics::class),
    TeachingScope::class => autowire(StaffTeachingScope::class),
    FileScanner::class => autowire(HeuristicFileScanner::class),
    AttachmentStore::class => autowire(FileAttachmentStore::class),

    HealthCipher::class => factory(static fn (Config $config): HealthCipher => new HealthCipher(Secrets::key($config))),
    UrlSigner::class => factory(static fn (Config $config): UrlSigner => new UrlSigner(Secrets::key($config))),
    FileStorage::class => factory(static function (Config $config): FileStorage {
        $path = (string) $config->get('people.storage_path', '');

        return new FileStorage($path !== '' ? $path : dirname(__DIR__, 4) . '/storage/uploads');
    }),

    // Clôture d'année (contrat sortant de l'Organisation) : passage en classe supérieure des admis.
    'organisation.closure.promotion.people' => [YearPromotion::class],

    'privacy.tenant_data_source.people' => \DI\autowire(\Scholaris\Modules\Socle\People\Infrastructure\PrivacyExportSource::class),
    'privacy.subject_data_source.people' => \DI\autowire(\Scholaris\Modules\Socle\People\Infrastructure\PrivacySubjectSource::class),
];
