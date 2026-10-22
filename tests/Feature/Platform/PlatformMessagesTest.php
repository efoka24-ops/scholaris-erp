<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleActivator;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Identity\RecordingSms;

/** Contrats ajoutés pour la plateforme : envoi direct des modèles `platform_*` (Messaging) et intégration d'un client (Organisation). */
final class PlatformMessagesTest extends TestCase
{
    private Kernel $kernel;
    private RecordingMail $mail;
    private RecordingSms $sms;

    protected function setUp(): void
    {
        $this->mail = new RecordingMail();
        $this->sms = new RecordingSms();
        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]), [MailGateway::class => $this->mail, SmsGateway::class => $this->sms]);
        $this->kernel->container()->get(Migrator::class)->migrate();
    }

    public function testChaqueModeleDePlateformeSeRendAvecSesVariablesAutorisees(): void
    {
        $notifier = $this->kernel->container()->get(DirectNotifier::class);
        $rows = $this->kernel->container()->get(ConnectionFactory::class)->pdo()
            ->query("SELECT DISTINCT template_key, locale FROM message_templates WHERE tenant_id = 'platform' AND template_key LIKE 'platform_%'")->fetchAll();
        self::assertCount(34, $rows, '17 modèles en français et en anglais (invitation d\'un administrateur, mode régularisation et déclaration de paiement compris).');

        $variables = [
            'first_name' => 'Awa', 'label' => 'IPES', 'establishment_name' => 'Institut', 'reference' => 'AB12CD34', 'link' => 'https://x.test',
            'details' => 'Motif', 'due_on' => '06/01/2027', 'offset' => 3, 'amount' => 60000, 'date' => '21/01/2027', 'receipt_number' => 'SCH-2027-000001',
        ];
        foreach ($rows as $row) {
            $result = $notifier->notify((string) $row['template_key'], (string) $row['locale'], 'awa@isl.test', '+237690000001', $variables);
            self::assertSame(DeliveryResult::SENT, $result->status, $row['template_key'] . ' ' . $row['locale'] . ' : ' . $result->reason);
        }
        self::assertNotEmpty($this->mail->sent);
        self::assertNotEmpty($this->sms->sent);
    }

    public function testVariableHorsListeBlancheOuSansContactBloque(): void
    {
        $notifier = $this->kernel->container()->get(DirectNotifier::class);

        self::assertSame(DeliveryResult::BLOCKED, $notifier->notify('platform_access_request_rejected', 'fr', 'a@b.test', null, ['password' => 'x'])->status);
        self::assertSame('no_contact', $notifier->notify('platform_access_request_rejected', 'fr', null, null, ['first_name' => 'A', 'establishment_name' => 'B', 'details' => 'C'])->reason);
        self::assertSame('template_missing', $notifier->notify('payment_receipt_inexistant', 'fr', 'a@b.test', null, [])->reason);
    }

    public function testIntegrationDUnClientEtActivationDesModules(): void
    {
        $provisioning = $this->kernel->container()->get(ClientProvisioning::class);
        $tenant = $provisioning->createTenant('Institut Polytechnique');
        $establishment = $provisioning->createEstablishment($tenant, 'Institut Polytechnique', 'higher');
        self::assertTrue($provisioning->tenantExists($tenant));
        self::assertFalse($provisioning->tenantExists('platform'));
        self::assertSame([$tenant], array_column($provisioning->tenants(), 'id'));

        $this->kernel->container()->get(ConnectionFactory::class)->pdo()->prepare('UPDATE establishments SET modules_enabled = ? WHERE id = ?')->execute(['["people"]', $establishment]);
        $this->kernel->container()->get(ModuleActivator::class)->enable($tenant, $establishment, ['billing', 'module-inconnu']);
        $availability = $this->kernel->container()->get(ModuleAvailability::class);
        self::assertTrue($availability->isEnabled($tenant, $establishment, 'people'));
        self::assertTrue($availability->isEnabled($tenant, $establishment, 'billing'));
        self::assertFalse($availability->isEnabled($tenant, $establishment, 'module-inconnu'));

        $this->expectException(\Scholaris\Core\Http\HttpException::class);
        $this->kernel->container()->get(ModuleActivator::class)->enable('autre-client', $establishment, ['billing']);
    }
}
