<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;
use Tests\Support\People\PeopleTestCase;

/** Les contrats du dossier Contracts sont fournis par le module et respectent le cloisonnement par client. */
final class ContractsTest extends PeopleTestCase
{
    protected function setUp(): void
    {
        $this->bootPeople();
    }

    public function testLesContratsSontLies(): void
    {
        $container = $this->kernel->container();

        self::assertInstanceOf(PersonDirectory::class, $container->get(PersonDirectory::class));
        self::assertInstanceOf(EnrollmentDirectory::class, $container->get(EnrollmentDirectory::class));
        self::assertInstanceOf(ConsentChecker::class, $container->get(ConsentChecker::class));
    }

    public function testAnnuaireDesPersonnes(): void
    {
        /** @var PersonDirectory $directory */
        $directory = $this->kernel->container()->get(PersonDirectory::class);
        $learner = $this->person('Nadia', 'Foka', ['phone' => '677123456', 'email' => 'n@x.cm', 'locale' => 'en']);
        $parent = $this->person('Jean', 'Foka');
        $guardian = $this->person('Tante', 'Foka');
        $stranger = $this->person('Paul', 'Ndi', [], 't2');
        $this->api('POST', '/people/' . $learner['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'financial_guarantor']);
        $this->api('POST', '/people/' . $learner['id'] . '/relations', body: ['related_person_id' => $guardian['id'], 'kind' => 'guardian']);

        $summary = $directory->find('t1', (string) $learner['id']);
        self::assertInstanceOf(PersonSummary::class, $summary);
        self::assertSame(['Nadia Foka', '677123456', 'n@x.cm', 'en'], [$summary->fullName(), $summary->phone, $summary->email, $summary->locale]);
        self::assertNull($directory->find('t2', (string) $learner['id']), 'Une personne d\'un autre client reste invisible.');
        self::assertCount(1, $directory->findMany('t1', [(string) $learner['id'], (string) $stranger['id']]));
        self::assertSame([], $directory->findMany('t1', []));

        $guarantors = array_map(static fn (PersonSummary $p): string => $p->id, $directory->guarantorsOf('t1', (string) $learner['id']));
        sort($guarantors);
        $expected = [(string) $parent['id'], (string) $guardian['id']];
        sort($expected);
        self::assertSame($expected, $guarantors, 'Garants financiers et tuteurs.');
        self::assertSame([], $directory->guarantorsOf('t2', (string) $learner['id']));
        self::assertSame([(string) $learner['id']], array_map(static fn (PersonSummary $p): string => $p->id, $directory->learnersOf('t1', (string) $parent['id'])));
        self::assertSame([], $directory->learnersOf('t1', (string) $guardian['id']), 'Seul le garant financier a des apprenants à charge.');
    }
}
