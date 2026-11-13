<?php

declare(strict_types=1);

namespace Tests\Unit\Secondary\Documents;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentIssuance;
use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentTypeCatalogue;
use Scholaris\Modules\Apps\Secondary\Documents\Application\TechnicalDocumentTypes;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\ModuleGate;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/** E3-F : DOC-21 à DOC-24 selon les modules et la capacité technique du profil (jamais le type), émis par les modules propriétaires. */
final class TechnicalDocumentTypesTest extends TestCase
{
    public function testLesQuatreDocumentsSontConditionnesParModulesEtCapaciteTechnique(): void
    {
        $types = (new TechnicalDocumentTypes())->types();

        self::assertSame(['DOC-21', 'DOC-22', 'DOC-23', 'DOC-24'], array_column($types, 'doc'));
        self::assertSame([Modules::INTERNSHIPS, Modules::INTERNSHIPS, Modules::WORKSHOPS, Modules::WORKSHOPS], array_column($types, 'module'));
        self::assertSame([Cycles::TEACHING_TECHNICAL, Cycles::TEACHING_TECHNICAL, Cycles::TEACHING_TECHNICAL, Cycles::TEACHING_TECHNICAL], array_column($types, 'requiredTeachingKind'));
        self::assertSame([false, false, false, false], array_column($types, 'direct'));
        self::assertSame([true, false, false, false], array_column($types, 'qr'));
        self::assertSame([
            'sec_doc21_internship_agreement',
            'sec_doc22_trainee_appraisal',
            'sec_doc23_workshop_sheet',
            'sec_doc24_material_stock',
        ], array_column($types, 'socleCode'));
    }

    public function testEmissionVerifieLeModuleEtResteDelegueeAuSousModuleProprietaire(): void
    {
        $issuer = new class () implements DocumentIssuer {
            public int $calls = 0;

            public function issue(string $tenantId, string $establishmentId, string $type, string $subjectPersonId, array $data, string $issuedBy, string $locale = 'fr'): IssuedDocument
            {
                ++$this->calls;

                return new IssuedDocument('id', 'number', 'token', 'valid', $type, $subjectPersonId);
            }

            public function revoke(string $tenantId, string $documentId, string $reason, string $revokedBy): void
            {
            }
        };

        $inactive = self::issuance([], ['technical'], $issuer);
        try {
            $inactive->issuable('tenant', 'establishment', TechnicalDocumentTypes::WORKSHOP_SHEET);
            self::fail('Le module inactif doit être refusé avant la capacité.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame(ModuleGate::CODE, $exception->extensions['code'] ?? null);
        }

        $generalProfile = self::issuance([Modules::WORKSHOPS], ['general'], $issuer);
        try {
            $generalProfile->issue('tenant', 'establishment', TechnicalDocumentTypes::WORKSHOP_SHEET, 'person', [], 'owner');
            self::fail('L’émission doit exiger la capacité technique du profil même si le module est actif.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame('profile_capability_not_offered', $exception->extensions['code'] ?? null);
        }

        $technicalProfile = self::issuance([Modules::WORKSHOPS], ['technical'], $issuer);
        self::assertSame('sec_doc23_workshop_sheet', $technicalProfile->issue('tenant', 'establishment', TechnicalDocumentTypes::WORKSHOP_SHEET, 'person', [], 'owner')->type);
        self::assertSame(1, $issuer->calls);
    }

    public function testCatalogueSignaleDisponibiliteSelonModulesEtCapaciteDuProfil(): void
    {
        $type = self::catalogue()->find(TechnicalDocumentTypes::WORKSHOP_SHEET);
        self::assertNotNull($type);
        self::assertFalse($type->isAvailableFor(self::profileValue([Modules::WORKSHOPS], ['general'])));
        self::assertTrue($type->isAvailableFor(self::profileValue([Modules::WORKSHOPS], ['technical'])));
        self::assertFalse($type->isAvailableFor(self::profileValue([], ['technical'])));
    }

    public function testLaMigrationDuSocleDeclareLesTypesEtLeursModelesFrEn(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE document_types (id CHAR(26), tenant_id VARCHAR(40), establishment_id VARCHAR(40), code VARCHAR(30), label VARCHAR(160), category VARCHAR(20), type_letter CHAR(1), number_template VARCHAR(80), qr_enabled SMALLINT, blockable SMALLINT, variables TEXT, signer_role VARCHAR(40), created_at VARCHAR(32), updated_at VARCHAR(32))');
        $pdo->exec('CREATE TABLE document_templates (id CHAR(26), tenant_id VARCHAR(40), establishment_id VARCHAR(40), type_code VARCHAR(30), locale VARCHAR(5), html TEXT, version INTEGER, validated_at VARCHAR(32), created_by VARCHAR(40), created_at VARCHAR(32))');
        $migration = dirname(__DIR__, 4) . '/database/migrations/202611070302_secondary_document_types_e3.sql';
        $pdo->exec((string) file_get_contents($migration));
        $rows = self::query($pdo, 'SELECT code, qr_enabled FROM document_types ORDER BY code')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([
            ['code' => 'sec_doc21_internship_agreement', 'qr_enabled' => 1],
            ['code' => 'sec_doc22_trainee_appraisal', 'qr_enabled' => 0],
            ['code' => 'sec_doc23_workshop_sheet', 'qr_enabled' => 0],
            ['code' => 'sec_doc24_material_stock', 'qr_enabled' => 0],
            ['code' => 'sec_doc30_payroll_payslip', 'qr_enabled' => 0],
        ], $rows);
        self::assertSame(10, (int) self::query($pdo, 'SELECT COUNT(*) FROM document_templates')->fetchColumn(), 'Un modèle FR et un modèle EN par type.');
        self::assertSame(0, (int) self::query($pdo, "SELECT COUNT(*) FROM document_templates WHERE html LIKE '%;%'")->fetchColumn(), 'Aucun point-virgule dans le HTML (découpage du Migrator).');
    }

    /**
     * @param list<string> $modules
     * @param list<string> $teachingKinds
     */
    private static function issuance(array $modules, array $teachingKinds, DocumentIssuer $issuer): DocumentIssuance
    {
        $profiles = self::profiles($modules, $teachingKinds);

        return new DocumentIssuance(self::catalogue(), $profiles, new ModuleGate($profiles), $issuer);
    }

    private static function catalogue(): DocumentTypeCatalogue
    {
        $container = new Container();
        $container->set(DocumentTypeCatalogue::PREFIX . 'test', [TechnicalDocumentTypes::class]);

        return new DocumentTypeCatalogue($container);
    }

    /**
     * @param list<string> $modules
     * @param list<string> $teachingKinds
     */
    private static function profiles(array $modules, array $teachingKinds): EstablishmentProfiles
    {
        return new class ($modules, $teachingKinds) implements EstablishmentProfiles {
            /**
     * @param list<string> $modules
     * @param list<string> $teachingKinds
     */
            public function __construct(private readonly array $modules, private readonly array $teachingKinds)
            {
            }

            public function forEstablishment(string $tenantId, string $establishmentId): EstablishmentProfile
            {
                return new EstablishmentProfile($tenantId, $establishmentId, 'profile-without-type-rules', 1, true, 'public', ['public'], ['first'], $this->teachingKinds, ['fr'], $this->modules, [], [], null, 'to_confirm');
            }
        };
    }

    /**
     * @param list<string> $modules
     * @param list<string> $teachingKinds
     */
    private static function profileValue(array $modules, array $teachingKinds): EstablishmentProfile
    {
        return new EstablishmentProfile('tenant', 'establishment', 'profile-without-type-rules', 1, true, 'public', ['public'], ['first'], $teachingKinds, ['fr'], $modules, [], [], null, 'to_confirm');
    }

    private static function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }
}
