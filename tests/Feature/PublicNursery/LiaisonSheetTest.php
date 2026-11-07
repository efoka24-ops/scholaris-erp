<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\LiaisonSheetService;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\TransitionService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Tests\Support\Organisation\OrganisationTestCase;

final class LiaisonSheetTest extends OrganisationTestCase
{
    public function testLaFicheDoc20NeRetientQueLesPointsDappuiAttendusetNimporteNiRestrictionNiAllergie(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'liaison-sheet-test-secret';
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-dev', 'est-dev', 'Maternelle publique pilote', 'nursery');
        $learner = $this->kernel->container()->get(PersonService::class)->create('tenant-dev', [
            'first_name' => 'Lina',
            'last_name' => 'Meka',
            'birth_date' => '2021-01-01',
        ]);
        $this->seedFrameCatalog('tenant-dev');

        $transition = $this->kernel->container()->get(TransitionService::class)->propose('tenant-dev', 'est-dev', (string) $learner['id'], '2026-2027', 'gs', 'Prête pour le primaire.');
        $obs = $this->kernel->container()->get(ObservationService::class);
        $obs->record('tenant-dev', 'est-dev', [
            'learner_id' => (string) $learner['id'],
            'structure_id' => 'section-gs',
            'frame_id' => 'frame-1',
            'domain_id' => 'domain-lang',
            'item_id' => 'item-speak',
            'level_code' => 'acquired',
            'remark' => 'S’exprime avec assurance.',
            'observed_on' => '2027-03-12',
            'observer_id' => 'teacher',
        ]);
        $obs->record('tenant-dev', 'est-dev', [
            'learner_id' => (string) $learner['id'],
            'structure_id' => 'section-gs',
            'frame_id' => 'frame-1',
            'domain_id' => 'domain-social',
            'item_id' => 'item-share',
            'level_code' => 'in_progress',
            'remark' => 'Partage de mieux en mieux le matériel.',
            'observed_on' => '2027-03-13',
            'observer_id' => 'teacher',
        ]);
        $obs->record('tenant-dev', 'est-dev', [
            'learner_id' => (string) $learner['id'],
            'structure_id' => 'section-gs',
            'frame_id' => 'frame-1',
            'domain_id' => 'domain-motor',
            'item_id' => 'item-balance',
            'level_code' => 'not_yet',
            'remark' => 'Continuer les jeux d’équilibre.',
            'observed_on' => '2027-03-14',
            'observer_id' => 'teacher',
        ]);
        $this->kernel->container()->get(FoodAllergies::class)->declare('tenant-dev', (string) $learner['id'], 'arachides', 'parent');
        $this->connections()->pdo()->prepare(
            'INSERT INTO pn_custody_restrictions (id, tenant_id, establishment_id, learner_id, restricted_name, restricted_person_id, restricted_pickup_id, nature, decision_reference, decision_document_ref, valid_from, valid_to, status, recorded_by, lifted_reason, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, NULL, ?, NULL, ?, ?, NULL, ?, ?)',
        )->execute([(string) new Ulid(), 'tenant-dev', 'est-dev', (string) $learner['id'], 'Parent interdit', 'court_order', 'JUG-002', '2027-03-01', 'active', 'director', '2027-03-01T08:00:00+01:00', '2027-03-01T08:00:00+01:00']);

        $service = $this->kernel->container()->get(LiaisonSheetService::class);
        $draft = $service->saveDraft('tenant-dev', 'est-dev', (string) $learner['id'], '2026-2027');
        $signed = $service->sign('tenant-dev', 'est-dev', (string) $learner['id'], '2026-2027', 'director');
        $html = $service->html($signed, 'Lina Meka', 'fr');

        self::assertSame(['to_primary', 'gs'], [$transition['proposal'], $transition['from_level']]);
        self::assertStringContainsString('Langage : S’exprimer', (string) $draft['strengths']);
        self::assertStringContainsString('Socialisation : Partager', (string) $draft['strengths']);
        self::assertStringNotContainsString('Motricité : Garder l’équilibre', (string) $draft['strengths']);
        self::assertStringContainsString('Poursuivre : Motricité : Garder l’équilibre', (string) $draft['needs']);
        self::assertSame('signed', $signed['status']);
        self::assertStringNotContainsString('arachides', $html);
        self::assertStringNotContainsString('Parent interdit', $html);
    }

    private function seedFrameCatalog(string $tenantId): void
    {
        $pdo = $this->connections()->pdo();
        $pdo->prepare('INSERT INTO pn_dev_frames (id, tenant_id, frame_code, version, subsystem, label_fr, label_en, status, validation_ref, created_at, activated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)')
            ->execute(['frame-1', $tenantId, 'R21', 1, 'R2', 'Référentiel', 'Framework', 'active', '2027-03-01T08:00:00+01:00', '2027-03-01T08:00:00+01:00']);
        $pdo->prepare('INSERT INTO pn_dev_domains (id, tenant_id, frame_id, code, label_fr, label_en, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['domain-lang', $tenantId, 'frame-1', 'lang', 'Langage', 'Language', 1]);
        $pdo->prepare('INSERT INTO pn_dev_domains (id, tenant_id, frame_id, code, label_fr, label_en, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['domain-social', $tenantId, 'frame-1', 'social', 'Socialisation', 'Social skills', 2]);
        $pdo->prepare('INSERT INTO pn_dev_domains (id, tenant_id, frame_id, code, label_fr, label_en, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['domain-motor', $tenantId, 'frame-1', 'motor', 'Motricité', 'Motor skills', 3]);
        $pdo->prepare('INSERT INTO pn_dev_items (id, tenant_id, frame_id, domain_id, code, label_fr, label_en, section_levels, sort_order, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute(['item-speak', $tenantId, 'frame-1', 'domain-lang', 'speak', 'S’exprimer', 'Speak', 'gs', 1]);
        $pdo->prepare('INSERT INTO pn_dev_items (id, tenant_id, frame_id, domain_id, code, label_fr, label_en, section_levels, sort_order, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute(['item-share', $tenantId, 'frame-1', 'domain-social', 'share', 'Partager', 'Share', 'gs', 2]);
        $pdo->prepare('INSERT INTO pn_dev_items (id, tenant_id, frame_id, domain_id, code, label_fr, label_en, section_levels, sort_order, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute(['item-balance', $tenantId, 'frame-1', 'domain-motor', 'balance', 'Garder l’équilibre', 'Balance', 'gs', 3]);
    }
}
