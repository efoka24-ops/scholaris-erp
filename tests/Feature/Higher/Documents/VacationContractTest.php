<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Documents;

use Tests\Support\Higher\Vacations\VacationsTestCase;

/** DOC-24 contrat de vacation : émis depuis l'état approuvé (payable) ou payé, jamais avant l'approbation de la direction. */
final class VacationContractTest extends VacationsTestCase
{
    protected function setUp(): void
    {
        $this->bootVacations();
        $this->tst11();
    }

    public function testContratEmisDepuisEtatApprouve(): void
    {
        $id = (string) $this->prepare()['id'];
        self::assertSame(409, $this->call('POST', '/vacations/statements/' . $id . '/contract', self::CHEF)[0], 'Brouillon : pas de contrat.');
        $this->call('POST', '/vacations/statements/' . $id . '/validate-school', self::DIRECTOR);
        self::assertSame(409, $this->call('POST', '/vacations/statements/' . $id . '/contract', self::DIRECTOR)[0], 'Visé par l\'école mais pas approuvé.');
        $this->call('POST', '/vacations/statements/' . $id . '/approve', self::DG);

        self::assertSame(201, $this->call('POST', '/vacations/statements/' . $id . '/contract', self::DIRECTOR)[0]);
        $issued = $this->fakes->issued[0];
        self::assertSame('DOC-24', $issued['code']);
        self::assertSame(['43,5', '261 000'], [$issued['data']['hetd_total'], $issued['data']['gross_amount']]);
        self::assertArrayNotHasKey('holder_name', $issued['data']);
        self::assertSame('doc-1', $this->call('GET', '/vacations/statements/' . $id, self::DIRECTOR)[1]['contract_document_id']);
    }
}
