<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\GuarantorLinker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;
use Tests\Support\People\PeopleTestCase;

/** C-118 : rattachement d'un garant financier (parent déjà connu par son téléphone, sinon nouvelle personne), cloisonné par client. */
final class GuarantorLinkerTest extends PeopleTestCase
{
    private GuarantorLinker $linker;

    protected function setUp(): void
    {
        $this->bootPeople();
        $this->linker = $this->kernel->container()->get(GuarantorLinker::class);
    }

    public function testUnParentDejaConnuEstRattacheParSonTelephone(): void
    {
        $learner = $this->person('Issa', 'Fictif');
        $parent = $this->person('Aissatou', 'Fictive', ['phone' => '+237699000101']);

        $linked = $this->linker->attachFinancialGuarantor('t1', (string) $learner['id'], '699 00 01 01', [], 'u1');
        self::assertSame(['person_id' => (string) $parent['id'], 'created' => false, 'already_linked' => false], $linked);
        $again = $this->linker->attachFinancialGuarantor('t1', (string) $learner['id'], '+237699000101', [], 'u1');
        self::assertTrue($again['already_linked'], 'Un second rattachement ne crée pas de double lien.');

        /** @var PersonDirectory $directory */
        $directory = $this->kernel->container()->get(PersonDirectory::class);
        self::assertSame([(string) $learner['id']], array_map(static fn (PersonSummary $p): string => $p->id, $directory->learnersOf('t1', (string) $parent['id'])));
        self::assertSame([(string) $parent['id']], array_map(static fn (PersonSummary $p): string => $p->id, $this->linker->financialGuarantorsOf('t1', (string) $learner['id'])));
    }

    public function testUnTelephoneInconnuCreeLeGarantAvecSonIdentite(): void
    {
        $learner = $this->person('Issa', 'Fictif');
        $linked = $this->linker->attachFinancialGuarantor('t1', (string) $learner['id'], '677000202', ['last_name' => 'Fictif', 'first_name' => 'Moussa'], 'u1');
        self::assertTrue($linked['created']);
        self::assertSame($linked['person_id'], $this->linker->personIdByPhone('t1', '+237 677 000 202'));

        try {
            $this->linker->attachFinancialGuarantor('t1', (string) $learner['id'], '677000303', [], 'u1');
            self::fail('Sans personne connue, le nom du garant est exigé.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        try {
            $this->linker->attachFinancialGuarantor('t1', (string) $learner['id'], '12', [], 'u1');
            self::fail('Téléphone invalide.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testPlusieursPersonnesAuMemeTelephoneSontAmbigues(): void
    {
        $learner = $this->person('Issa', 'Fictif');
        $this->person('Un', 'Parent', ['phone' => '699000404']);
        $this->person('Autre', 'Parent', ['phone' => '+237699000404']);
        try {
            $this->linker->attachFinancialGuarantor('t1', (string) $learner['id'], '699000404', [], 'u1');
            self::fail('Deux personnes portent ce téléphone.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testLeRattachementEstCloisonneParClient(): void
    {
        $learner = $this->person('Issa', 'Fictif');
        $foreignParent = $this->person('Etranger', 'Parent', ['phone' => '699000505'], 't2');
        $foreignLearner = $this->person('Eleve', 'Etranger', [], 't2');

        // Le parent d'un autre client porte ce téléphone : il n'est jamais trouvé, une personne du client t1 est créée.
        self::assertNull($this->linker->personIdByPhone('t1', '699000505'));
        $linked = $this->linker->attachFinancialGuarantor('t1', (string) $learner['id'], '699000505', ['last_name' => 'Local'], 'u1');
        self::assertTrue($linked['created']);
        self::assertNotSame((string) $foreignParent['id'], $linked['person_id']);

        // L'élève d'un autre client est inconnu (422), et ses garants restent invisibles.
        try {
            $this->linker->attachFinancialGuarantor('t1', (string) $foreignLearner['id'], '699000505', ['last_name' => 'Local'], 'u1');
            self::fail('Élève d\'un autre client.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame([], $this->linker->financialGuarantorsOf('t2', (string) $learner['id']));
    }
}
