<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Pickup;

/** EX-MAT-09 : personnes autorisées tenues par la secrétaire et la directrice ; portée de section ; demandes de parent à valider. */
final class AuthorizedPersonsTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup();
    }

    public function testLaSecretaireAjouteListeEtRetireUneAutorisation(): void
    {
        $id = $this->authorize($this->ada['child'], 'Tante Rose (exemple)');
        $list = $this->ok('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'secretaire-a');
        self::assertSame([$id], array_column($list, 'id'));
        self::assertSame('Tante Rose (exemple)', $list[0]['name']);
        self::assertArrayNotHasKey('photo_ref', $list[0]);

        $revoked = $this->ok('POST', '/nursery/pickup/authorized-persons/' . $id . '/revoke', [], 'secretaire-a');
        self::assertSame('revoked', $revoked['status']);
        self::assertSame([], $this->ok('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'secretaire-a'));
        self::assertContains('nursery.pickup.revoked', $this->audit->actions());
    }

    public function testUneAutorisationDuJourNEstListeeQueCeJourLa(): void
    {
        $this->authorize($this->ada['child'], 'Voisine (exemple)', 'day', '2026-10-21');
        self::assertCount(1, $this->ok('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'] . '&date=2026-10-21', null, 'secretaire-a'));
        self::assertCount(0, $this->ok('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'] . '&date=2026-10-22', null, 'secretaire-a'));
    }

    public function testValidationDesChampsEtRefusDeLaListeSansEnfant(): void
    {
        self::assertSame(422, $this->code('GET', '/nursery/pickup/authorized-persons', null, 'secretaire-a'));
        self::assertSame(422, $this->code('POST', '/nursery/pickup/authorized-persons', ['learner_id' => $this->ada['child'], 'name' => 'X', 'relationship' => 'oncle', 'grant_type' => 'jour'], 'secretaire-a'));
        self::assertSame(422, $this->code('POST', '/nursery/pickup/authorized-persons', ['learner_id' => $this->ada['child'], 'name' => 'X', 'relationship' => 'oncle', 'grant_type' => 'day'], 'secretaire-a'));
        self::assertSame(422, $this->code('POST', '/nursery/pickup/authorized-persons', [], 'secretaire-a'));
    }

    public function testUneEducatriceNeGerePasLaListeEtVoitSeulementSaSection(): void
    {
        $id = $this->authorize($this->ada['child']);
        self::assertSame(403, $this->code('POST', '/nursery/pickup/authorized-persons', ['learner_id' => $this->ada['child'], 'name' => 'X', 'relationship' => 'oncle', 'grant_type' => 'permanent'], 'educatrice-a'));
        self::assertSame(403, $this->code('POST', '/nursery/pickup/authorized-persons/' . $id . '/revoke', [], 'educatrice-a'));
        self::assertSame(200, $this->code('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'educatrice-a'));
        self::assertSame(404, $this->code('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'educatrice-b'));
    }

    public function testUnAutreClientNeVoitNiNeRetireLesAutorisations(): void
    {
        $id = $this->authorize($this->ada['child']);
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/pickup/authorized-persons/' . $id . '/revoke', [], 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/pickup/authorized-persons', ['learner_id' => $this->ada['child'], 'name' => 'X', 'relationship' => 'oncle', 'grant_type' => 'permanent'], 'directrice-b', self::TB, self::EB));
    }

    public function testLaPhotoEstDeposeParMediaStoreEtJamaisRenduDansLaListe(): void
    {
        $body = ['learner_id' => $this->ada['child'], 'name' => 'Tante Photo (exemple)', 'relationship' => 'tante', 'grant_type' => 'permanent', 'photo_base64' => base64_encode('jpeg-fictif')];
        $created = $this->ok('POST', '/nursery/pickup/authorized-persons', $body, 'secretaire-a');
        self::assertTrue($created['has_photo']);
        self::assertCount(1, $this->world->media->files);
        self::assertSame(422, $this->code('POST', '/nursery/pickup/authorized-persons', ['photo_base64' => '***'] + $body, 'secretaire-a'));
    }

    public function testLAjoutDUnParentEstActifSansValidationParDefautEtAttendLEcoleSiLeParametreLExige(): void
    {
        // Par défaut (donnée plateforme) : actif tout de suite.
        $direct = $this->ok('POST', '/nursery/portal/pickups', ['learner_id' => $this->ada['child'], 'name' => 'Tante Parent (exemple)', 'relationship' => 'tante', 'grant_type' => 'permanent'], 'parent-' . $this->ada['child']);
        self::assertSame('active', $direct['status']);

        $this->setSetting(self::TA, self::EA, 'nursery.pickup.parent_entries_need_validation', true);
        $pending = $this->ok('POST', '/nursery/portal/pickups', ['learner_id' => $this->ada['child'], 'name' => 'Cousin (exemple)', 'relationship' => 'cousin', 'grant_type' => 'permanent'], 'parent-' . $this->ada['child']);
        self::assertSame('pending', $pending['status']);
        self::assertCount(1, $this->ok('GET', '/nursery/pickup/requests', null, 'secretaire-a'));
        self::assertCount(1, $this->ok('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'secretaire-a'), 'Rien n’est actif avant validation.');

        $decision = $this->ok('POST', '/nursery/pickup/requests/' . $pending['request_id'] . '/approve', [], 'secretaire-a');
        self::assertSame('approved', $decision['status']);
        self::assertCount(2, $this->ok('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'secretaire-a'));
        self::assertSame(409, $this->code('POST', '/nursery/pickup/requests/' . $pending['request_id'] . '/reject', [], 'secretaire-a'));
    }
}
