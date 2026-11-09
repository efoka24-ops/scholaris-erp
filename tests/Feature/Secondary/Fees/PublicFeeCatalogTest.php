<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Fees;

use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T946, TST-06 (RG-FIN-2) : dans un établissement public [fees_public_catalog], un frais « reprographie » absent du catalogue des
 * frais autorisés est refusé (422 `fee_not_authorized`), une demande d'ajout est créée et journalisée ; le client la décide, puis
 * le frais passe. Le privé n'est pas concerné par le catalogue ; les frais d'examen ne sont jamais facturés (RG-FIN-3). Données fictives.
 */
final class PublicFeeCatalogTest extends SecondaryTestCase
{
    private const BURSAR = 'intendant-a';
    private const CLIENT_ADMIN = 'admin-fonctionnel-a';

    private string $leg;

    protected function setUp(): void
    {
        $this->bootSecondary();
        $this->leg = $this->factory->opened(self::TA, 'leg');
        $this->grant(self::TA, self::BURSAR, $this->leg, ['billing.read', 'billing.manage', 'secondary.billing.manage', 'secondary.fees.catalog', 'secondary.fees.read']);
        $this->grant(self::TA, self::CLIENT_ADMIN, $this->leg, ['secondary.profile.manage']);
    }

    private function q(string $sql): \PDOStatement
    {
        $statement = $this->pdo()->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }

    /**
     * @param array<array-key, mixed> $list
     * @return array<string, mixed>
     */
    private static function first(array $list): array
    {
        $item = array_values($list)[0] ?? null;
        self::assertIsArray($item);

        return $item;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function createFee(string $establishmentId, string $kind, string $name, int $amount): array
    {
        return $this->call('POST', '/fee-items', ['kind' => $kind, 'name' => $name, 'amount_xaf' => $amount], self::BURSAR, self::TA, $establishmentId);
    }

    public function testFraisReprographieHorsCatalogueRefuseAvecDemandeTraceeEtAudit(): void
    {
        [$status, $json] = $this->createFee($this->leg, 'other', 'Reprographie', 2000);

        self::assertSame(422, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('fee_not_authorized', $json['code'] ?? null);
        $requests = $this->q("SELECT fee_kind, label, amount, status, origin FROM se_fee_requests WHERE establishment_id = '" . $this->leg . "'")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['fee_kind' => 'other', 'label' => 'Reprographie', 'amount' => 2000, 'status' => 'pending', 'origin' => 'guard']], array_map(static fn (array $r): array => array_replace($r, ['amount' => (int) $r['amount']]), $requests));
        self::assertContains('secondary.fee.refused_out_of_catalog', $this->audit->actions());
        self::assertSame(0, (int) $this->q("SELECT COUNT(*) FROM fee_items WHERE establishment_id = '" . $this->leg . "'")->fetchColumn(), 'Rien n\'est créé.');

        [$again] = $this->createFee($this->leg, 'other', 'Reprographie', 2000);
        self::assertSame(422, $again);
        self::assertSame(1, (int) $this->q('SELECT COUNT(*) FROM se_fee_requests')->fetchColumn(), 'La demande en attente n\'est pas dupliquée.');
    }

    public function testLaDemandeAccepteeParLeClientOuvreLeFraisDansLaLimiteDuPlafond(): void
    {
        $this->createFee($this->leg, 'apee', 'APEE', 10000);
        $request = self::first($this->ok('GET', '/secondary/fees/requests?status=pending', null, self::BURSAR, self::TA, $this->leg));

        [$self] = $this->call('POST', '/secondary/fees/requests/' . $request['id'] . '/decide', ['decision' => 'accepted', 'reason' => 'Vote', 'legal_basis' => 'PV fictif'], SecondaryTestCase::ADMIN, self::TA, $this->leg);
        self::assertSame(403, $self, 'Sans le droit de tenue du catalogue.');
        [$noBasis] = $this->call('POST', '/secondary/fees/requests/' . $request['id'] . '/decide', ['decision' => 'accepted', 'reason' => 'Vote'], self::CLIENT_ADMIN, self::TA, $this->leg);
        self::assertSame(422, $noBasis, 'Texte de référence exigé.');

        $decided = $this->ok('POST', '/secondary/fees/requests/' . $request['id'] . '/decide', ['decision' => 'accepted', 'reason' => 'Vote de l\'assemblée', 'legal_basis' => 'PV d\'assemblée fictif n° 1'], self::CLIENT_ADMIN, self::TA, $this->leg);
        self::assertSame('accepted', $decided['status']);
        self::assertNotNull($decided['catalog_id']);

        [$ok] = $this->createFee($this->leg, 'apee', 'APEE', 10000);
        self::assertSame(201, $ok);
        [$over, $json] = $this->createFee($this->leg, 'apee', 'APEE majorée', 12000);
        self::assertSame(422, $over, 'Plafond du catalogue dépassé.');
        self::assertSame('fee_not_authorized', $json['code'] ?? null);

        $catalog = $this->ok('GET', '/secondary/fees/catalog', null, self::BURSAR, self::TA, $this->leg);
        self::assertCount(1, $catalog);
        self::assertSame(10000, self::first($catalog)['max_amount']);
    }

    public function testLIntendantNeTientPasLeCatalogueEtLAuteurNeDecidePas(): void
    {
        [$status] = $this->call('POST', '/secondary/fees/catalog', ['fee_kind' => 'apee', 'label' => 'APEE', 'legal_basis' => 'PV'], self::BURSAR, self::TA, $this->leg);
        self::assertSame(403, $status);

        $this->grant(self::TA, self::BURSAR, $this->leg, ['secondary.profile.manage']);
        $request = $this->ok('POST', '/secondary/fees/requests', ['fee_kind' => 'canteen', 'label' => 'Cantine', 'amount' => 15000, 'justification' => 'Service facultatif'], self::BURSAR, self::TA, $this->leg);
        [$own] = $this->call('POST', '/secondary/fees/requests/' . $request['id'] . '/decide', ['decision' => 'refused', 'reason' => 'Non'], self::BURSAR, self::TA, $this->leg);
        self::assertSame(403, $own, 'Séparation des tâches.');
    }

    /** C-117 : la personne qui tient le catalogue (secondary.profile.manage seul) ouvre l'écran Frais : aperçu, catalogue, demandes. */
    public function testLaTenueDuCatalogueOuvreLApercuDesFrais(): void
    {
        $overview = $this->ok('GET', '/secondary/fees/overview', null, self::CLIENT_ADMIN, self::TA, $this->leg);
        self::assertSame('public_catalog', $overview['mode']);
        $this->ok('GET', '/secondary/fees/catalog', null, self::CLIENT_ADMIN, self::TA, $this->leg);
        $this->ok('GET', '/secondary/fees/requests', null, self::CLIENT_ADMIN, self::TA, $this->leg);
    }

    public function testLeCatalogueDuClientValePourTousSesEtablissementsPublics(): void
    {
        $ces = $this->factory->opened(self::TA, 'ces');
        $this->grant(self::TA, self::BURSAR, $ces, ['billing.read', 'billing.manage']);
        $this->ok('POST', '/secondary/fees/catalog', ['fee_kind' => 'insurance', 'label' => 'Assurance scolaire', 'max_amount' => 1000, 'legal_basis' => 'Texte fictif', 'client_wide' => true, 'valid_from' => '2026-09-01'], self::CLIENT_ADMIN, self::TA, $this->leg);

        [$status] = $this->createFee($ces, 'insurance', 'Assurance scolaire', 1000);
        self::assertSame(201, $status);
    }

    public function testLePriveNEstPasSoumisAuCatalogueMaisNeFacturePasLesFraisDExamen(): void
    {
        $private = $this->factory->opened(self::TA, 'lycee_prive');
        $this->grant(self::TA, self::BURSAR, $private, ['billing.read', 'billing.manage']);

        self::assertSame(201, $this->createFee($private, 'other', 'Reprographie', 2000)[0]);
        [$exam, $json] = $this->createFee($private, 'exam_fee', 'Frais de BAC', 15000);
        self::assertSame(422, $exam, 'RG-FIN-3');
        self::assertSame('fee_not_authorized', $json['code'] ?? null);
        self::assertSame(0, (int) $this->q('SELECT COUNT(*) FROM se_fee_requests')->fetchColumn());
    }

    public function testRouteDuCatalogueSansLeModule409(): void
    {
        $private = $this->factory->opened(self::TA, 'lycee_prive');
        $this->grant(self::TA, self::BURSAR, $private, ['secondary.fees.read']);

        [$status, $json] = $this->call('GET', '/secondary/fees/catalog', null, self::BURSAR, self::TA, $private);
        self::assertSame(409, $status);
        self::assertSame('module_not_enabled', $json['code'] ?? null);
    }
}
