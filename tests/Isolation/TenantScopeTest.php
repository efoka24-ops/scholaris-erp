<?php

declare(strict_types=1);

namespace Tests\Isolation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Tenancy\MissingTenantContext;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Core\Tenancy\TenantTable;

/** Barrière applicative d'isolation : le filtre tenant est obligatoire et refuse par défaut. */
final class TenantScopeTest extends TestCase
{
    private ConnectionFactory $connections;
    private TenantContext $context;

    protected function setUp(): void
    {
        $this->connections = new ConnectionFactory(Config::fromArray(['database' => ['driver' => 'sqlite', 'database' => ':memory:']]));
        $this->connections->pdo()->exec('CREATE TABLE notes (id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40), label VARCHAR(80))');
        $this->context = new TenantContext();

        $this->context->set('t1', 'e1');
        $this->table()->insert(['id' => 'n1', 'label' => 'client 1']);
        $this->context->set('t2', 'e1');
        $this->table()->insert(['id' => 'n2', 'label' => 'client 2']);
        $this->context->clear();
    }

    private function table(): TenantTable
    {
        return (new TenantTable($this->connections, $this->context, 'notes'))->forEstablishment();
    }

    public function testSansContexteToutAccesEstRefuse(): void
    {
        $this->expectException(MissingTenantContext::class);
        $this->table()->select();
    }

    public function testUnClientNeVoitQueSesLignes(): void
    {
        $this->context->set('t1', 'e1');
        self::assertSame(['n1'], array_column($this->table()->select(), 'id'));

        $this->context->set('t2', 'e1');
        self::assertSame(['n2'], array_column($this->table()->select(), 'id'));
    }

    public function testLIdentifiantDUneLigneDUnAutreClientDonne404(): void
    {
        $this->context->set('t1', 'e1');

        self::assertNull($this->table()->find('n2'));
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->table()->findOrFail('n2');
    }

    public function testUnAutreEtablissementDuMemeClientNEstPasVisible(): void
    {
        $this->context->set('t1', 'e2');

        self::assertSame([], $this->table()->select());
    }

    public function testUneModificationNAtteintJamaisUnAutreClient(): void
    {
        $this->context->set('t1', 'e1');

        self::assertSame(0, $this->table()->update(['label' => 'piraté'], ['id' => 'n2']));
        self::assertSame(0, $this->table()->delete(['id' => 'n2']));

        $this->context->set('t2', 'e1');
        self::assertSame('client 2', $this->table()->find('n2')['label']);
    }

    public function testLeClientEstImposeALInsertionMemeSiLAppelantEssaieAutreChose(): void
    {
        $this->context->set('t1', 'e1');
        $this->table()->insert(['id' => 'n3', 'label' => 'x', 'tenant_id' => 't2']);

        $this->context->set('t2', 'e1');
        self::assertNull($this->table()->find('n3'));
    }

    public function testUpdateEtDeleteSansConditionSontRefuses(): void
    {
        $this->context->set('t1', 'e1');

        $this->expectException(InvalidArgumentException::class);
        $this->table()->delete([]);
    }

    public function testIdentifiantsSqlSuspectsSontRefuses(): void
    {
        $this->context->set('t1', 'e1');

        $this->expectException(InvalidArgumentException::class);
        $this->table()->select(['label; DROP TABLE notes' => 'x']);
    }

    public function testNomDeTableSuspectEstRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TenantTable($this->connections, $this->context, 'notes; --');
    }
}
