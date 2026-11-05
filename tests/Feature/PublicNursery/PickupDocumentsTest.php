<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Core\Support\Ulid;
use Tests\Feature\PublicNursery\Support\PublicNurseryCase;

/**
 * C-106 (T727) : DOC-21 carte d'une personne autorisée et DOC-19 registre du jour émis par le module Documents du socle : numéro et QR,
 * page de vérification sans nom ni téléphone (RG-DOC-2), carte révoquée avec l'autorisation, refus pour une personne visée par une
 * restriction, registre réédité = ancien révoqué, droits et portée. Données fictives.
 */
final class PickupDocumentsTest extends PublicNurseryCase
{
    private string $child;
    private string $directorPerson;

    protected function setUp(): void
    {
        $this->bootPublicNursery();
        $this->grantAll('teacher', 'public_nursery.pickup.handover');
        $this->grantAll('director', 'public_schools.school.write', 'settings.manage');
        $this->child = (string) $this->person('Lina', 'Fictive')['id'];
        $this->enrol($this->child);
        $this->directorPerson = (string) $this->person('Dora', 'Directrice')['id'];
        $now = self::NOW;
        $this->connections()->pdo()->prepare('INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['director', self::T, $this->directorPerson, 'fr', 'active', $now, $now]);
    }

    private function authorize(string $name = 'Tante Rose', string $grant = 'permanent'): string
    {
        $body = ['learner_id' => $this->child, 'name' => $name, 'relationship' => 'tante', 'phone' => '+237670000999', 'grant_type' => $grant];
        if ($grant === 'day') {
            $body['valid_on'] = '2026-10-08';
        }

        return (string) $this->ok('POST', '/public-nursery/pickup/authorized-persons', 'teacher', $body)['id'];
    }

    public function testLaCarteEstEmiseUneFoisAvecQrEtLaVerificationNeMontreNiNomNiTelephone(): void
    {
        $pickup = $this->authorize();
        $card = $this->ok('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'teacher', ['locale' => 'fr']);
        self::assertSame('pickup_card', $card['kind']);
        self::assertNotSame('', $card['number']);
        self::assertSame('valid', $card['status']);
        self::assertFalse($card['already_issued']);

        $again = $this->ok('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'teacher', []);
        self::assertSame($card['id'], $again['id']);
        self::assertTrue($again['already_issued'], 'Une carte valide n’est émise qu’une fois.');

        $response = $this->kernel->handle(new ServerRequest('GET', $card['verification_path']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('Rose', $body, 'RG-DOC-2 : aucun nom d’autorisé.');
        self::assertStringNotContainsString('Fictive', $body, 'RG-DOC-2 : aucun nom d’enfant.');
        self::assertStringNotContainsString('670000999', $body, 'RG-DOC-2 : aucun téléphone.');
        $type = (string) $this->connections()->pdo()->query("SELECT d.type_code FROM documents d WHERE d.id = '" . $card['document_id'] . "'")->fetchColumn();
        self::assertSame('pn_doc21_pickup_card', $type);
    }

    public function testLeRetraitDeLAutorisationRevoqueLaCarteEtInterditUneNouvelle(): void
    {
        $pickup = $this->authorize();
        $card = $this->ok('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'teacher', []);
        $this->ok('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/revoke', 'teacher');

        self::assertSame('revoked', (string) $this->connections()->pdo()->query("SELECT status FROM pn_issued_documents WHERE id = '" . $card['id'] . "'")->fetchColumn());
        self::assertSame('revoked', (string) $this->connections()->pdo()->query("SELECT status FROM documents WHERE id = '" . $card['document_id'] . "'")->fetchColumn());
        self::assertSame(409, $this->code('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'teacher', self::T, self::E, []), 'Autorisation retirée : aucune carte.');
    }

    public function testUnePersonneViseeParUneRestrictionNeRecoitJamaisDeCarte(): void
    {
        $pickup = $this->authorize('Marc Visé');
        $this->ok('POST', '/public-nursery/pickup/restrictions', 'director', [
            'learner_id' => $this->child, 'restricted_name' => 'Marc Visé', 'restricted_pickup_id' => $pickup,
            'nature' => 'court_order', 'decision_reference' => 'JUG-FICTIF-1', 'valid_from' => '2026-10-01',
        ]);

        self::assertSame(409, $this->code('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'teacher', self::T, self::E, []));
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM pn_issued_documents')->fetchColumn());
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'public_nursery.pickup.card_refused'")->fetchColumn());
    }

    public function testLeRegistreDuJourEstEditeParLaDirectionEtUneRediffusionRevoqueLAncien(): void
    {
        $this->ok('POST', '/public-nursery/pickup/arrivals', 'teacher', ['learner_id' => $this->child, 'school_day' => '2026-10-08']);
        $first = $this->ok('POST', '/public-nursery/register/document', 'director', ['date' => '2026-10-08', 'locale' => 'fr']);
        self::assertSame('register', $first['kind']);
        $second = $this->ok('POST', '/public-nursery/register/document', 'director', ['date' => '2026-10-08', 'locale' => 'en']);
        self::assertNotSame($first['number'], $second['number']);

        $rows = $this->ok('GET', '/public-nursery/documents?kind=register', 'director');
        $statuses = array_column($rows, 'status', 'id');
        self::assertSame('revoked', $statuses[$first['id']]);
        self::assertSame('valid', $statuses[$second['id']]);

        self::assertSame(403, $this->code('POST', '/public-nursery/register/document', 'teacher', self::T, self::E, ['date' => '2026-10-08']), 'Le registre couvre toute l’école : direction seulement.');
        self::assertSame(422, $this->code('POST', '/public-nursery/register/document', 'director', self::T, self::E, ['date' => 'hier']));
        self::assertSame(422, $this->code('POST', '/public-nursery/register/document', 'director', self::T, self::E, ['date' => '2026-10-08', 'locale' => 'de']));
    }

    public function testLeParentTelechargeLePdfSeulementPourSonEnfant(): void
    {
        $pickup = $this->authorize();
        $card = $this->ok('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'teacher', []);

        $parent = $this->person('Parent', 'Fictif');
        $this->linkParent($this->child, $parent['id']);
        $this->grantAll('parent-user', 'public_nursery.pickup.handover');
        $this->connections()->pdo()->prepare('INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['parent-user', self::T, $parent['id'], 'fr', 'active', self::NOW, self::NOW]);

        $pdf = $this->kernel->handle(new ServerRequest('GET', '/api/v1/public-nursery/portal/documents/' . $card['document_id'] . '/pdf', ['Authorization' => 'Bearer parent-user:' . self::T, 'X-Establishment-Id' => self::E]));
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringContainsString('%PDF', (string) $pdf->getBody());

        $otherParent = $this->person('Autre', 'Parent');
        $otherChild = (string) $this->person('Autre', 'Enfant')['id'];
        $this->enrol($otherChild);
        $this->linkParent($otherChild, $otherParent['id']);
        $this->grantAll('other-parent-user', 'public_nursery.pickup.handover');
        $this->access->grant('other-parent-user', self::E);
        $this->connections()->pdo()->prepare('INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['other-parent-user', self::T, $otherParent['id'], 'fr', 'active', self::NOW, self::NOW]);
        self::assertSame(404, $this->code('GET', '/public-nursery/portal/documents/' . $card['document_id'] . '/pdf', 'other-parent-user', self::T, self::E));
    }

    public function testPorteeDesSectionsEtIsolationEntreClients(): void
    {
        $pickup = $this->authorize();
        $this->scopes->restrictToClasses('teacher', 'section-b');
        self::assertSame(404, $this->code('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'teacher', self::T, self::E, []), 'Hors de sa section.');
        $this->grantAll('other-director', 'public_nursery.pickup.handover');
        self::assertSame(404, $this->code('POST', '/public-nursery/pickup/authorized-persons/' . $pickup . '/card', 'other-director', 'tenant-other', 'est-other', []), 'Autorisation d’un autre client.');
        self::assertSame(404, $this->code('POST', '/public-nursery/pickup/authorized-persons/' . (string) new Ulid() . '/card', 'director', self::T, self::E, []));
    }
}
