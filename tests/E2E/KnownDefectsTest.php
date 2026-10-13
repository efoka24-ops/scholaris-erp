<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Défauts constatés pendant la recette, rejoués par l'API réelle. Chaque test décrit le comportement ATTENDU : il échoue
 * tant que le défaut existe (voir specs/001-socle-plateforme/test-report.md). Exclure avec --exclude-group defaut-connu.
 */
#[Group('defaut-connu')]
final class KnownDefectsTest extends RealStackTestCase
{
    use BuildsSchool;

    protected function setUp(): void
    {
        $this->bootRealStack();
        $this->buildSchool();
    }

    /** D1 : un apprenant inscrit « sous condition » (pièces manquantes) est facturé mais absent du portail famille. */
    public function testD1LePortailFamilleMontreUnEnfantInscritSousCondition(): void
    {
        $parent = $this->provisionUser($this->admin, $this->school, 'Paul', 'Foka', 'parent');
        $family = $this->enrollLearner('Nadia', 'Foka', '2011-05-14', $parent['person_id'], false);
        $this->ok('POST', '/people/' . $family['learner'] . '/relations', $this->admin, ['related_person_id' => $parent['person_id'], 'kind' => 'parent'], $this->school);
        $item = (string) $this->ok('POST', '/fee-items', $this->admin, ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 300000], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/fee-schedules', $this->admin, ['fee_item_id' => $item, 'academic_year_id' => $this->year, 'amount_xaf' => 300000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 300000]]], $this->school, [], 201);
        $this->ok('POST', '/invoices', $this->admin, ['enrollment_id' => $family['enrollment']], $this->school, [], 201);

        $portal = $this->ok('GET', '/portal/family', $this->signIn($parent['login'], $parent['password']))['data'];

        self::assertSame(300000, $portal['family_balance_xaf'], 'La facture d\'un enfant inscrit sous condition doit apparaître au portail.');
        self::assertNotSame([], $portal['children'][0]['enrollments']);
    }

    /** D2 : le parent ne peut pas payer depuis le portail : le front appelle des routes réservées au personnel. */
    public function testD2UnParentPeutLancerUnPaiementMobileMoneyDepuisLePortail(): void
    {
        $parent = $this->provisionUser($this->admin, $this->school, 'Paul', 'Foka', 'parent');
        $this->enrollLearner('Nadia', 'Foka', '2011-05-14', $parent['person_id']);
        $token = $this->signIn($parent['login'], $parent['password']);

        // Mêmes appels que web/src/api/payments.ts (ensurePaymentReference puis requestMobilePayment).
        [$lookup] = $this->api('GET', '/payment-references?filter[guarantor_id]=' . $parent['person_id'], $token, null, $this->school);
        [$create] = $this->api('POST', '/payment-references', $token, ['guarantor_id' => $parent['person_id']], $this->school);
        [$pay] = $this->api('POST', '/payments', $token, ['channel' => 'orange_money', 'amount_xaf' => 50000, 'family_reference' => 'FAM-0001', 'payer_phone' => '+237670000123'], $this->school, ['Idempotency-Key' => 'recette-parent-0001']);

        self::assertSame([200, 201, 202], [$lookup->getStatusCode(), $create->getStatusCode(), $pay->getStatusCode()], 'Statuts reçus : ' . implode(', ', [$lookup->getStatusCode(), $create->getStatusCode(), $pay->getStatusCode()]));
    }

    /** D3 : créer le compte d'un garant déjà enregistré crée une seconde fiche personne (fiche unique par client, FR-006). */
    public function testD3OuvrirLeCompteDUnGarantExistantNeDupliquePasSaFiche(): void
    {
        $phone = '+237677555111';
        $this->ok('POST', '/people', $this->admin, ['first_name' => 'Paul', 'last_name' => 'Foka', 'phone' => $phone], $this->school, [], 201);
        [$account] = $this->api('POST', '/users', $this->admin, ['first_name' => 'Paul', 'last_name' => 'Foka', 'phone' => $phone], $this->school);

        $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM people WHERE phone = '" . $phone . "'")->fetchColumn();
        self::assertSame(1, $count, 'POST /users (' . $account->getStatusCode() . ') a créé une seconde fiche pour la même personne ; aucune route ne rattache un compte à une fiche existante.');
    }
}
