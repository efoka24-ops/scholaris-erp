<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use PDO;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;
use Tests\Support\AppTestCase;

/** T607 : le journal nominatif écrit aussi dans le journal Audit chaîné, sans contenu métier. */
final class ScopedAccessAuditTest extends AppTestCase
{
    public function testUnAccesNominatifPonctuelEstJournaliseParLeModuleAuditExistant(): void
    {
        $this->boot();
        $journal = $this->kernel->container()->get(NamedAccessJournal::class);

        $journal->record('tenant-fixture', 'e1', 'actor-fixture', 'learner-fixture', 'person-fixture', 'Contrôle des effectifs', '203.0.113.7', 'synthetic-device');

        $statement = $this->connections()->pdo()->prepare('SELECT * FROM audit_log WHERE tenant_id = ?');
        self::assertNotFalse($statement);
        $statement->execute(['tenant-fixture']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('identity.named_record.accessed', $row['action']);
        self::assertSame('learner-fixture', $row['object_type']);
        self::assertSame('person-fixture', $row['object_id']);
        self::assertSame('actor-fixture', $row['actor_id']);
        self::assertNull($row['before_json']);
        self::assertStringContainsString('Contrôle des effectifs', (string) $row['after_json']);
        self::assertTrue($this->kernel->container()->get(AuditLog::class)->verify('tenant-fixture')->intact);
    }
}
