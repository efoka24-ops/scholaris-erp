<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Documents;

use Scholaris\Core\Database\ConnectionFactory;
use Tests\Feature\Nursery\Development\DevelopmentTestCase;

/**
 * DOC-MAT-01, 03, 04 par le module Documents du socle : numéro, contenu figé, QR (sauf registre), révocation de la carte avec
 * l'autorisation, refus pour une personne visée par une restriction, portail du parent, isolation.
 */
final class DocumentsTest extends DevelopmentTestCase
{
    protected function setUp(): void
    {
        $this->bootDevelopment();
        $this->grant(self::TA, self::DIRECTOR, self::EA, 'documents.issue');
    }

    private function publishedBook(): string
    {
        $this->ok('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired', 'observed_on' => '2026-10-15'], 'educatrice-a');
        $id = (string) $this->ok('POST', '/nursery/progress-books', ['learner_id' => $this->ada['child']], 'educatrice-a')['id'];
        $this->ok('PUT', '/nursery/progress-books/' . $id, ['synthesis' => 'Une période riche en découvertes (exemple).'], 'educatrice-a');
        $this->ok('POST', '/nursery/progress-books/' . $id . '/submit', [], 'educatrice-a');
        $this->ok('POST', '/nursery/progress-books/' . $id . '/validate', []);

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function socleDocument(string $id): ?array
    {
        $statement = $this->service(ConnectionFactory::class)->pdo()->prepare('SELECT * FROM documents WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function testLeCarnetNeSImprimeQuePublieEtUneSeuleFois(): void
    {
        $id = $this->publishedBook();
        self::assertSame(409, $this->code('POST', '/nursery/progress-books/' . $id . '/document', []), 'Validé mais pas publié : pas de document.');
        $this->ok('POST', '/nursery/progress-books/' . $id . '/publish', []);
        $document = $this->ok('POST', '/nursery/progress-books/' . $id . '/document', ['locale' => 'en']);
        self::assertSame('progress_book', $document['kind']);
        self::assertFalse($document['already_issued']);
        self::assertNotSame('', $document['number']);
        $row = $this->socleDocument((string) $document['document_id']);
        self::assertNotNull($row);
        self::assertSame('nu_progress_book', $row['type_code']);
        self::assertSame('en', $row['locale']);
        self::assertStringContainsString('Une période riche en découvertes', (string) $row['content'], 'Contenu figé à l’émission.');
        $again = $this->ok('POST', '/nursery/progress-books/' . $id . '/document', []);
        self::assertTrue($again['already_issued']);
        self::assertSame($document['number'], $again['number'], 'Un carnet émis n’est jamais renuméroté (réédition par le socle).');
    }

    public function testSeuleLaDirectionEmetLeCarnetEtUnAutreClientNeLeVoitPas(): void
    {
        $id = $this->publishedBook();
        $this->ok('POST', '/nursery/progress-books/' . $id . '/publish', []);
        self::assertSame(403, $this->code('POST', '/nursery/progress-books/' . $id . '/document', [], 'educatrice-a'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('POST', '/nursery/progress-books/' . $id . '/document', [], 'directrice-b', self::TB, self::EB));
        $this->ok('POST', '/nursery/progress-books/' . $id . '/document', []);
        self::assertSame([], $this->ok('GET', '/nursery/documents', null, 'directrice-b', self::TB, self::EB));
    }

    public function testLaCarteTombeAvecLAutorisation(): void
    {
        $pickup = $this->authorize($this->ada['child']);
        $card = $this->ok('POST', '/nursery/pickup/authorized-persons/' . $pickup . '/card', [], 'secretaire-a');
        self::assertSame('pickup_card', $card['kind']);
        $row = $this->socleDocument((string) $card['document_id']);
        self::assertNotNull($row);
        self::assertStringNotContainsString('+237600000010', (string) $row['content'], 'La carte ne porte jamais le téléphone.');
        $this->ok('POST', '/nursery/pickup/authorized-persons/' . $pickup . '/revoke', [], 'secretaire-a');
        $listed = $this->ok('GET', '/nursery/documents?kind=pickup_card');
        self::assertSame('revoked', $listed[0]['status']);
        self::assertSame('revoked', $this->socleDocument((string) $card['document_id'])['status'] ?? null, 'Vérification publique : révoquée.');
        self::assertSame(409, $this->code('POST', '/nursery/pickup/authorized-persons/' . $pickup . '/card', [], 'secretaire-a'), 'Aucune carte pour une autorisation retirée.');
    }

    public function testAucuneCartePourUnePersonneViseeParUneRestriction(): void
    {
        $pickup = $this->authorize($this->ada['child'], 'Personne visée (fictive)');
        $this->ok('POST', '/nursery/pickup/restrictions', [
            'learner_id' => $this->ada['child'], 'restricted_name' => 'Personne visée (fictive)', 'nature' => 'no_pickup',
            'decision_reference' => 'JUG-2026-FICTIF-001', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31',
        ]);
        self::assertSame(409, $this->code('POST', '/nursery/pickup/authorized-persons/' . $pickup . '/card', [], 'secretaire-a'));
        self::assertContains('nursery.pickup.card_refused', $this->audit->actions());
    }

    public function testLeRegistreDuJourEstReeditableEtLAncienRevoque(): void
    {
        $this->world->people->account(self::TA, self::DIRECTOR, $this->directorPerson);
        $first = $this->ok('POST', '/nursery/register/document', ['date' => '2026-10-21']);
        self::assertSame('exit_register', $first['kind']);
        $row = $this->socleDocument((string) $first['document_id']);
        self::assertStringContainsString('Ada', (string) ($row['content'] ?? ''));
        $second = $this->ok('POST', '/nursery/register/document', ['date' => '2026-10-21']);
        self::assertNotSame($first['number'], $second['number']);
        self::assertSame('revoked', $this->socleDocument((string) $first['document_id'])['status'] ?? null);
        self::assertSame(403, $this->code('POST', '/nursery/register/document', [], 'educatrice-a'), 'Registre de toute l’école : direction seulement.');
    }

    public function testLeParentNeVoitQueLesDocumentsDeSonEnfantJamaisLeRegistre(): void
    {
        $id = $this->publishedBook();
        $this->ok('POST', '/nursery/progress-books/' . $id . '/publish', []);
        $this->ok('POST', '/nursery/progress-books/' . $id . '/document', []);
        $this->world->people->account(self::TA, self::DIRECTOR, $this->directorPerson);
        $this->ok('POST', '/nursery/register/document', []);
        $mine = $this->ok('GET', '/nursery/portal/documents?learner_id=' . $this->ada['child'], null, 'parent-' . $this->ada['child']);
        self::assertSame(['progress_book'], array_column($mine, 'kind'));
        self::assertSame(404, $this->code('GET', '/nursery/portal/documents?learner_id=' . $this->ada['child'], null, 'parent-' . $this->bao['child']));
        self::assertSame([], $this->ok('GET', '/nursery/documents', null, 'educatrice-b'), 'Hors section : aucun document visible.');
    }
}
