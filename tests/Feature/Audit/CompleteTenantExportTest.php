<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use PDO;
use Scholaris\Modules\Socle\Audit\Application\DataSources;
use Scholaris\Modules\Socle\Audit\Application\TenantExporter;
use Scholaris\Modules\Socle\Audit\Contracts\SubjectDataSource;
use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;
use Scholaris\Modules\Socle\People\Infrastructure\HealthCipher;
use Tests\Support\AppTestCase;
use ZipArchive;

/** T136 : export complet d'un client à partir des sources réelles des modules, volumétrie, isolation, effacement. */
final class CompleteTenantExportTest extends AppTestCase
{
    private const KEY = 'cle-de-test-pour-lexport-complet';

    protected function setUp(): void
    {
        $this->boot([], ['people' => ['secret_key' => self::KEY]]);
    }

    private function pdo(): PDO
    {
        return $this->connections()->pdo();
    }

    /** @param array<string, scalar|null> $row */
    private function insert(string $table, array $row): void
    {
        $this->pdo()->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
            ->execute(array_values($row));
    }

    private function seed(string $tenant, string $suffix): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->insert('people', ['id' => 'P' . $suffix, 'tenant_id' => $tenant, 'first_name' => 'Nadia' . $suffix, 'last_name' => 'Test', 'phone' => '+23760000' . $suffix, 'created_at' => $now]);
        $this->insert('users', ['id' => 'U' . $suffix, 'tenant_id' => $tenant, 'person_id' => 'P' . $suffix, 'phone' => '+23760000' . $suffix, 'status' => 'active', 'password_hash' => '$argon2id$SECRET-HASH-' . $suffix, 'two_factor_secret' => 'TOTP-SECRET-' . $suffix, 'created_at' => $now, 'updated_at' => $now]);
        $this->insert('access_tokens', ['id' => 'T' . $suffix, 'tenant_id' => $tenant, 'user_id' => 'U' . $suffix, 'device_id' => 'D' . $suffix, 'token_hash' => 'TOKEN-HASH-' . $suffix, 'expires_at' => $now, 'created_at' => $now]);
        $this->insert('devices', ['id' => 'D' . $suffix, 'tenant_id' => $tenant, 'user_id' => 'U' . $suffix, 'fingerprint' => 'FINGERPRINT-' . $suffix, 'name' => 'Telephone', 'last_seen_at' => $now, 'created_at' => $now]);
        $this->insert('person_contacts', ['id' => 'C' . $suffix, 'tenant_id' => $tenant, 'person_id' => 'P' . $suffix, 'kind' => 'phone', 'value' => '+23761111' . $suffix, 'created_at' => $now]);
        $cipher = new HealthCipher(self::KEY);
        $this->insert('health_records', ['id' => 'H' . $suffix, 'tenant_id' => $tenant, 'person_id' => 'P' . $suffix, 'payload_enc' => $cipher->encrypt((string) json_encode(['allergies' => 'arachide' . $suffix])), 'created_at' => $now, 'updated_at' => $now]);
        $this->insert('invoices', ['id' => 'I' . $suffix, 'tenant_id' => $tenant, 'establishment_id' => 'E' . $suffix, 'enrollment_id' => 'N' . $suffix, 'learner_id' => 'P' . $suffix, 'academic_year_id' => 'Y', 'status' => 'open', 'gross_xaf' => 100000, 'discount_xaf' => 0, 'net_xaf' => 100000, 'paid_xaf' => 0, 'balance_xaf' => 100000, 'created_at' => $now, 'updated_at' => $now]);
        $this->insert('conversations', ['id' => 'V' . $suffix, 'tenant_id' => $tenant, 'establishment_id' => 'E' . $suffix, 'subject' => 'Sujet', 'created_by' => 'P' . $suffix, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $this->insert('conversation_messages', ['id' => 'M' . $suffix, 'tenant_id' => $tenant, 'establishment_id' => 'E' . $suffix, 'conversation_id' => 'V' . $suffix, 'sender_id' => 'P' . $suffix, 'body' => 'Bonjour ' . $suffix, 'moderation_status' => 'visible', 'created_at' => $now]);
        $this->insert('settings', ['id' => 'S' . $suffix, 'scope_type' => 'tenant', 'tenant_id' => $tenant, 'setting_key' => 'school.name', 'value_json' => '"Ecole ' . $suffix . '"', 'updated_by' => 'U', 'created_at' => $now, 'updated_at' => $now]);
        $this->insert('settings', ['id' => 'K' . $suffix, 'scope_type' => 'tenant', 'tenant_id' => $tenant, 'setting_key' => 'smtp.password', 'value_json' => '"SMTP-SECRET-' . $suffix . '"', 'updated_by' => 'U', 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @return array<string, string> */
    private function export(string $tenant, ?string &$manifestOut = null): array
    {
        $file = tempnam(sys_get_temp_dir(), 'scx-test');
        self::assertNotFalse($file);
        $manifest = $this->kernel->container()->get(TenantExporter::class)->exportToFile($tenant, 'console', $file);
        $manifestOut = json_encode($manifest);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($file);

        return $files;
    }

    public function testLExportContientLesDonneesDeDeuxModulesAuMoins(): void
    {
        $this->seed('t1', '1');
        $files = $this->export('t1');

        foreach (['identity/users.json', 'people/people.json', 'people/health_records.json', 'billing/invoices.json', 'messaging/conversation_messages.json', 'organisation/settings.json', 'manifest.json'] as $name) {
            self::assertArrayHasKey($name, $files);
        }
        self::assertSame('Nadia1', json_decode($files['people/people.json'], true)[0]['first_name']);
        self::assertSame(100000, json_decode($files['billing/invoices.json'], true)[0]['net_xaf']);
        self::assertSame('Bonjour 1', json_decode($files['messaging/conversation_messages.json'], true)[0]['body']);
        // Sources vides : une table sans ligne donne un tableau vide valide.
        self::assertSame([], json_decode($files['staff/staff_members.json'], true));
        self::assertSame([], json_decode($files['reporting/report_schedules.json'], true));
        $manifest = json_decode($files['manifest.json'], true);
        self::assertSame(1, $manifest['sources']['billing']['invoices']);
        self::assertSame(0, $manifest['sources']['staff']['staff_members']);
    }

    public function testAucunSecretNEstExporte(): void
    {
        $this->seed('t1', '1');
        $all = implode("\n", $this->export('t1'));

        foreach (['SECRET-HASH', 'TOTP-SECRET', 'TOKEN-HASH', 'FINGERPRINT', 'SMTP-SECRET', 'password_hash', 'two_factor_secret', 'payload_enc'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $all, $forbidden);
        }
        self::assertStringContainsString('Ecole 1', $all);
    }

    public function testLaSanteEstDechiffreeDansLExportSeulement(): void
    {
        $this->seed('t1', '1');
        $files = $this->export('t1');
        $health = json_decode($files['people/health_records.json'], true);

        self::assertSame('arachide1', $health[0]['health']['allergies']);
        // Jamais dans les données d'une personne concernée.
        foreach ($this->subjectSources() as $source) {
            self::assertStringNotContainsString('arachide1', (string) json_encode($source->collect('t1', 'P1')), $source->name());
        }
    }

    public function testIsolationEntreClients(): void
    {
        $this->seed('t1', '1');
        $this->seed('t2', '2');
        $files = $this->export('t1');

        $all = implode("\n", $files);
        foreach (['Nadia2', 'arachide2', 'Bonjour 2', 'Ecole 2', '+237611112'] as $other) {
            self::assertStringNotContainsString($other, $all, $other);
        }
        self::assertStringContainsString('Nadia1', $all);
    }

    public function testToutesLesSourcesRestentSansColonneSecrete(): void
    {
        $sources = $this->kernel->container()->get(DataSources::class)->tenantSources();
        $names = array_map(static fn ($source): string => $source->name(), $sources);
        foreach (['identity', 'organisation', 'people', 'billing', 'messaging', 'documents', 'scheduling', 'attendance', 'assessment', 'staff', 'reporting', 'audit'] as $module) {
            self::assertContains($module, $names, 'Source manquante : ' . $module);
        }
        foreach ($sources as $source) {
            if (!$source instanceof TableExportSource) {
                continue;
            }
            $tables = (new \ReflectionMethod($source, 'tables'))->invoke($source);
            foreach ($tables as $table => $definition) {
                foreach ($definition['columns'] as $column) {
                    if ($column === 'ip_hash') {
                        continue;
                    }
                    self::assertDoesNotMatchRegularExpression('/(password|secret|token_hash|code_hash|key_hash|_hash$)/', $column, $source->name() . '.' . $table . '.' . $column);
                }
            }
        }
    }

    public function testVolumetrieDixMilleLignesMemoireBornee(): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $payload = str_repeat('x', 1000);
        $this->pdo()->beginTransaction();
        $insert = $this->pdo()->prepare('INSERT INTO person_contacts (id, tenant_id, person_id, kind, value, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < 10000; $i++) {
            $insert->execute([sprintf('C%07d', $i), 't1', 'P1', 'note', $payload . $i, $now]);
        }
        $this->pdo()->commit();

        gc_collect_cycles();
        $before = memory_get_usage();
        $peakBefore = memory_get_peak_usage();
        $file = tempnam(sys_get_temp_dir(), 'scx-vol');
        self::assertNotFalse($file);
        $manifest = $this->kernel->container()->get(TenantExporter::class)->exportToFile('t1', 'console', $file);
        $growth = max(memory_get_peak_usage() - max($peakBefore, $before), 0);

        self::assertSame(10000, $manifest['sources']['people']['person_contacts']);
        // 10 000 lignes de 1 Ko = plus de 10 Mo de JSON : la mémoire ne doit pas suivre.
        self::assertLessThan(12 * 1024 * 1024, $growth, 'Croissance du pic mémoire : ' . $growth);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        self::assertGreaterThan(10 * 1024 * 1024, $zip->statName('people/person_contacts.json')['size']);
        $json = json_decode((string) $zip->getFromName('people/person_contacts.json'), true, flags: JSON_THROW_ON_ERROR);
        $zip->close();
        unlink($file);
        self::assertCount(10000, $json);
        self::assertSame('C0000000', $json[0]['id']);
        self::assertSame('C0009999', $json[9999]['id']);
    }

    public function testEffaceEtAnonymiseLesDonneesDeLaPersonne(): void
    {
        $this->seed('t1', '1');
        $this->seed('t2', '1b');
        $subject = $this->subjectSources();

        foreach (['people', 'messaging', 'identity'] as $name) {
            self::assertArrayHasKey($name, $subject);
            self::assertNotSame([], array_filter($subject[$name]->collect('t1', 'P1')), $name);
            self::assertGreaterThan(0, $subject[$name]->anonymize('t1', 'P1'), $name);
        }

        $person = $this->pdo()->query("SELECT * FROM people WHERE id = 'P1'")->fetch();
        self::assertSame('Anonyme', $person['first_name']);
        self::assertNull($person['phone']);
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM health_records WHERE person_id = 'P1'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM person_contacts WHERE person_id = 'P1'")->fetchColumn());
        $message = $this->pdo()->query("SELECT body, sender_id FROM conversation_messages WHERE id = 'M1'")->fetch();
        self::assertSame('[message supprimé]', $message['body']);
        self::assertStringStartsWith('anon-', $message['sender_id']);
        $user = $this->pdo()->query("SELECT status, phone, two_factor_secret FROM users WHERE id = 'U1'")->fetch();
        self::assertSame('locked', $user['status']);
        self::assertNull($user['phone']);
        self::assertNull($user['two_factor_secret']);
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM access_tokens WHERE id = 'T1'")->fetchColumn());

        // L'autre client est intact.
        self::assertSame('Nadia1b', $this->pdo()->query("SELECT first_name FROM people WHERE id = 'P1b'")->fetchColumn());
        self::assertSame('Bonjour 1b', $this->pdo()->query("SELECT body FROM conversation_messages WHERE id = 'M1b'")->fetchColumn());
    }

    /** @return array<string, SubjectDataSource> */
    private function subjectSources(): array
    {
        $out = [];
        foreach ($this->kernel->container()->get(DataSources::class)->subjectSources() as $source) {
            $out[$source->name()] = $source;
        }

        return $out;
    }
}
