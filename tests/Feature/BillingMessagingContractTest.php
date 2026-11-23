<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;

/**
 * Contrat entre Billing et Messaging : les modèles que Billing demande existent dans les deux langues
 * et n'utilisent que des variables que Billing transmet ET que Messaging autorise.
 * Sans cela, un reçu ou une relance serait bloqué silencieusement (`variable_not_allowed`, `missing_variable`).
 */
final class BillingMessagingContractTest extends TestCase
{
    /** Variables réellement transmises par Billing (ReceiptService, ReminderService, PaymentReferenceService). */
    private const SENT = [
        'billing.receipt' => ['receipt_number', 'amount', 'balance'],
        'billing.reminder' => ['amount', 'due_on', 'label', 'offset'],
        'billing.family_reference' => ['reference'],
    ];

    /** @var list<array<string, mixed>> */
    private array $templates = [];

    protected function setUp(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $statement = $kernel->container()->get(ConnectionFactory::class)->pdo()
            ->query("SELECT template_key, locale, channel, body FROM message_templates WHERE tenant_id = 'platform'");
        $this->templates = $statement->fetchAll();
    }

    public function testChaqueModeleDeBillingExisteEnFrancaisEtEnAnglaisParSms(): void
    {
        foreach (array_keys(self::SENT) as $key) {
            foreach (['fr', 'en'] as $locale) {
                $found = array_filter($this->templates, static fn (array $t): bool => $t['template_key'] === $key && $t['locale'] === $locale && $t['channel'] === 'sms');
                self::assertCount(1, $found, "Modèle $key ($locale, sms) manquant ou en double.");
            }
        }
    }

    public function testLesVariablesTransmisesSontAutoriseesParMessaging(): void
    {
        foreach (self::SENT as $key => $variables) {
            TemplateRenderer::assertVariablesAllowed(array_fill_keys($variables, 1));
            self::assertNotSame([], $variables, $key);
        }
        $this->addToAssertionCount(1);
    }

    public function testLesModelesNeDemandentPasDeVariableQueBillingNeTransmetPas(): void
    {
        foreach ($this->templates as $template) {
            $key = (string) $template['template_key'];
            if (!isset(self::SENT[$key])) {
                continue;
            }
            $missing = array_diff(TemplateRenderer::placeholders((string) $template['body']), self::SENT[$key]);
            self::assertSame([], array_values($missing), "Le modèle $key ({$template['locale']}) exige des variables que Billing ne transmet pas.");
        }
    }
}
