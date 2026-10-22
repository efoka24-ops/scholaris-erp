<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Tests\Support\Workflows\WorkflowsTestCase;

/**
 * Routes /workflows : liste avec défauts, valeurs effectives et verrous ; surcharge (confirmation récente, motif, 422 explicatif
 * sur tout verrou levé) ; retour au défaut ; historique ; isolation entre clients ; réglage depuis la fiche client de la plateforme
 * et plancher posé pour tous les clients.
 */
final class WorkflowsApiTest extends WorkflowsTestCase
{
    private const GRADES = '/workflows/higher/grades_lifecycle';

    protected function setUp(): void
    {
        $this->bootWorkflows();
    }

    /** @param array<string, mixed> $steps */
    private function put(array $steps, string $reason = 'Le chef de département est absent en période d\'examen.', string $user = self::WF_ADMIN, string $path = self::GRADES): array
    {
        return $this->call('PUT', $path, ['steps' => $steps, 'reason' => $reason], $user);
    }

    /** @param array<string, mixed> $view @return array<string, mixed> */
    private function step(array $view, string $key): array
    {
        foreach ($view['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        self::fail('Étape absente : ' . $key);
    }

    public function testLaListeDonneLesCircuitsDeLIpesAvecDefautsEtVerrous(): void
    {
        $list = $this->ok('GET', '/workflows', null, self::WF_ADMIN);
        $keys = array_map(static fn (array $c): string => $c['application'] . '/' . $c['key'], $list);

        self::assertSame(['billing/billing_reversal', 'higher/admissions_decision', 'higher/billing_scholarship', 'higher/grades_correction', 'higher/grades_lifecycle', 'organisation/year_closure'], $keys);
        $grades = $list[4];
        self::assertFalse($grades['customised']);
        self::assertNull($grades['override']);
        self::assertGreaterThanOrEqual(4, count($grades['invariants']));
        $lock = $this->step($grades, 'lock');
        self::assertSame(['actor' => 'chef_dept', 'enabled' => true, 'recent_confirmation' => true], $lock['default']);
        self::assertSame([true, false, false], [$lock['editable']['actor'], $lock['editable']['enabled'], $lock['editable']['recent_confirmation']]);
        self::assertContains('lock_needs_confirmation', array_column($lock['locks'], 'code'), 'Verrou expliqué.');
        self::assertTrue($this->step($grades, 'validate')['editable']['enabled']);
        self::assertCount(4, $this->ok('GET', '/workflows/higher', null, self::WF_ADMIN));
        self::assertSame(1, count($this->ok('GET', '/workflows?application=organisation', null, self::WF_ADMIN)));
        self::assertSame('Marks: entry, validation, locking and publication', $this->ok('GET', self::GRADES, null, self::WF_ADMIN)['label_en']);
        self::assertSame(404, $this->call('GET', '/workflows/higher/inconnu', null, self::WF_ADMIN)[0]);
        self::assertSame(404, $this->call('GET', '/workflows/autre/grades_lifecycle', null, self::WF_ADMIN)[0]);
    }

    public function testSansPermissionOuAutreClientRien(): void
    {
        self::assertSame(403, $this->call('GET', '/workflows', null, self::ADMIN)[0], 'Un administrateur sans workflows.manage.');
        self::assertSame(403, $this->call('GET', '/workflows', null, self::WF_ADMIN, self::TB, self::EB)[0], 'Un utilisateur d\'un autre client.');
        self::assertSame(403, $this->call('PUT', self::GRADES, ['steps' => ['validate' => ['enabled' => false]], 'reason' => 'Motif valable.'], self::ADMIN)[0]);
    }

    public function testToutEnregistrementExigeLaConfirmationRecenteEtUnMotif(): void
    {
        [$status, $problem] = $this->put(['validate' => ['enabled' => false]]);
        self::assertSame([403, 'https://api.scholaris.cm/errors/second-factor-required'], [$status, $problem['type']]);

        $this->confirm();
        [$status, $problem] = $this->put(['validate' => ['enabled' => false]], 'ok');
        self::assertSame(422, $status);
        self::assertArrayHasKey('reason', $problem['errors']);
        self::assertSame(422, $this->call('PUT', self::GRADES, ['steps' => ['validate' => ['enabled' => false]]], self::WF_ADMIN)[0], 'Motif absent.');
        self::assertSame(422, $this->call('PUT', self::GRADES, [], self::WF_ADMIN)[0]);
        $this->confirm(self::T, self::WF_ADMIN);
        self::assertSame(200, $this->put(['validate' => ['enabled' => false]])[0]);
    }

    public function testSurchargeVersionEtHistoriqueAvecAuteurDateEtMotif(): void
    {
        $this->confirm();
        [$status, $body] = $this->put(['validate' => ['enabled' => false], 'publish' => ['actor' => 'examens']]);
        $view = $body['data'];

        self::assertSame(200, $status);
        self::assertTrue($view['customised']);
        self::assertFalse($this->step($view, 'validate')['effective']['enabled']);
        self::assertSame(['examens', 'establishment'], [$this->step($view, 'publish')['effective']['actor'], $this->step($view, 'publish')['effective']['source']]);
        self::assertSame(1, $view['override']['version']);
        self::assertSame(self::WF_ADMIN, $view['override']['updated_by']);

        $second = $this->put(['validate' => ['enabled' => false], 'publish' => ['actor' => 'examens', 'recent_confirmation' => false]], 'Publication sans nouvelle confirmation.')[1]['data'];
        self::assertSame(2, $second['override']['version']);
        self::assertSame($second['override']['version'], $this->put(['validate' => ['enabled' => false], 'publish' => ['actor' => 'examens', 'recent_confirmation' => false]])[1]['data']['override']['version'], 'Un réglage identique ne crée pas de version.');

        $history = $this->ok('GET', self::GRADES . '/history', null, self::WF_ADMIN);
        self::assertSame([2, 1], array_column($history, 'version'));
        self::assertSame(['set', 'set'], array_column($history, 'kind'));
        self::assertSame('Publication sans nouvelle confirmation.', $history[0]['reason']);
        self::assertSame(self::WF_ADMIN, $history[0]['changed_by']);
        self::assertNull($history[1]['before']);
        self::assertSame('examens', $history[0]['after']['steps']['publish']['actor']);
        self::assertContains('workflow.changed', array_column($this->audit->entries, 'action'));
    }

    public function testRevenirAuDefautEstJournaliseEtIdempotent(): void
    {
        $this->confirm();
        $this->put(['validate' => ['enabled' => false]]);
        self::assertSame(403, $this->call('DELETE', self::GRADES, ['reason' => 'Retour au circuit standard.'], self::ADMIN)[0]);

        $view = $this->ok('DELETE', self::GRADES, ['reason' => 'Retour au circuit standard.'], self::WF_ADMIN);
        self::assertFalse($view['customised']);
        self::assertNull($view['override']);
        self::assertSame(200, $this->call('DELETE', self::GRADES, ['reason' => 'Déjà au défaut.'], self::WF_ADMIN)[0], 'Idempotent : déjà au défaut.');
        $history = $this->ok('GET', self::GRADES . '/history', null, self::WF_ADMIN);
        self::assertSame(['reset', 'set'], array_column($history, 'kind'));
        self::assertNull($history[0]['after']);
        self::assertContains('workflow.reset', array_column($this->audit->entries, 'action'));
    }

    public function testToutVerrouLeveEstRefuseAvecUneExplicationEnFrancais(): void
    {
        $this->confirm();
        $cases = [
            'confirmation du verrouillage' => [['lock' => ['recent_confirmation' => false]], 'steps.lock.recent_confirmation', 'confirmation récente'],
            'étape obligatoire' => [['lock' => ['enabled' => false]], 'steps.lock.enabled', 'obligatoire'],
            'acteur unique' => [['submit' => ['actor' => 'examens']], 'steps.submit.actor', 'enseignant'],
            'acteur hors liste' => [['publish' => ['actor' => 'caissier']], 'steps.publish.actor', 'Fonction non autorisée'],
            'étape inconnue' => [['gommage' => ['enabled' => false]], 'steps.gommage', 'inconnue'],
            'champ inconnu' => [['publish' => ['droit' => 'x.y']], 'steps.publish.droit', 'inconnu'],
        ];
        foreach ($cases as $label => [$steps, $field, $needle]) {
            [$status, $problem] = $this->put($steps);
            self::assertSame(422, $status, $label);
            self::assertArrayHasKey($field, $problem['errors'], $label);
            self::assertStringContainsStringIgnoringCase($needle, $problem['errors'][$field][0], $label);
        }
        self::assertSame(422, $this->put(['validate' => ['enforced' => true, 'enabled' => false]])[0], 'Un établissement n\'impose rien à un autre niveau.');
        self::assertFalse($this->ok('GET', self::GRADES, null, self::WF_ADMIN)['customised'], 'Aucun refus n\'a enregistré quoi que ce soit.');
        $closure = $this->put(['approve' => ['recent_confirmation' => false]], 'Allègement demandé.', self::WF_ADMIN, '/workflows/organisation/year_closure');
        self::assertSame(422, $closure[0], 'La clôture d\'année reste entièrement verrouillée.');
    }

    public function testLesReglagesDUnClientNeSontJamaisVusNiModifiesParUnAutre(): void
    {
        $this->confirm();
        $this->put(['validate' => ['enabled' => false]]);
        $this->confirm(self::TB, self::WF_ADMIN_B);

        $other = $this->ok('GET', self::GRADES, null, self::WF_ADMIN_B, self::TB, self::EB);
        self::assertFalse($other['customised'], 'Le client B garde les valeurs par défaut.');
        self::assertSame([], $this->ok('GET', self::GRADES . '/history', null, self::WF_ADMIN_B, self::TB, self::EB));
        self::assertSame(403, $this->call('GET', self::GRADES, null, self::WF_ADMIN, self::TB, self::EB)[0], 'A ne lit pas B.');
        self::assertSame(403, $this->call('PUT', self::GRADES, ['steps' => ['validate' => ['enabled' => false]], 'reason' => 'Tentative inter-client.'], self::WF_ADMIN, self::T, self::EB)[0], 'Établissement d\'un autre client.');
        $this->ok('PUT', self::GRADES, ['steps' => ['publish' => ['actor' => 'examens']], 'reason' => 'Réglage du client B.'], self::WF_ADMIN_B, self::TB, self::EB);
        self::assertSame('scolarite', $this->step($this->ok('GET', self::GRADES, null, self::WF_ADMIN), 'publish')['effective']['actor'], 'Le réglage de B ne touche pas A.');
    }

    public function testLeSuperAdminDeLaPlateformeRegleUnClientDepuisSaFiche(): void
    {
        [$status] = $this->platform('GET', '/platform/clients/' . self::T . '/workflows', null, 'intrus');
        self::assertSame(403, $status, 'Compte sans permission de plateforme.');
        self::assertSame(403, $this->call('GET', '/platform/clients/' . self::T . '/workflows', null, self::WF_ADMIN)[0], 'Un administrateur de client n\'entre pas dans /platform.');
        self::assertSame(404, $this->platform('GET', '/platform/clients/inconnu/workflows')[0]);

        [$status, $body] = $this->platform('GET', '/platform/clients/' . self::T . '/workflows');
        self::assertSame(200, $status);
        self::assertCount(6, $body['data']);
        self::assertSame('client', $body['data'][0]['level']);

        $put = ['steps' => ['publish' => ['actor' => 'examens', 'enforced' => true]], 'reason' => 'Politique du groupe.'];
        self::assertSame(403, $this->platform('PUT', '/platform/clients/' . self::T . '/workflows/higher/grades_lifecycle', $put)[0], 'Confirmation récente requise.');
        $this->secondFactor->validate('platform', self::PLATFORM_ADMIN);
        [$status, $body] = $this->platform('PUT', '/platform/clients/' . self::T . '/workflows/higher/grades_lifecycle', $put);
        self::assertSame(200, $status);
        self::assertSame('examens', $this->step($body['data'], 'publish')['effective']['actor']);

        $mine = $this->ok('GET', self::GRADES, null, self::WF_ADMIN);
        self::assertSame(['examens', 'client', 'client'], [$this->step($mine, 'publish')['effective']['actor'], $this->step($mine, 'publish')['effective']['source'], $this->step($mine, 'publish')['effective']['enforced_by']]);
        self::assertSame('client', $this->step($mine, 'publish')['imposed_by']);
        self::assertFalse($this->step($mine, 'publish')['editable']['actor']);

        $this->confirm();
        [$status, $problem] = $this->put(['publish' => ['actor' => 'scolarite']]);
        self::assertSame(422, $status, 'L\'établissement ne lève pas ce que le client impose.');
        self::assertStringContainsString('imposée', $problem['errors']['steps.publish'][0]);
        self::assertSame(200, $this->put(['validate' => ['enabled' => false]])[0], 'Les autres étapes restent libres.');

        $other = $this->ok('GET', self::GRADES, null, self::WF_ADMIN_B, self::TB, self::EB);
        self::assertSame('scolarite', $this->step($other, 'publish')['effective']['actor'], 'Le réglage du client A ne touche pas le client B.');

        $reset = $this->platform('DELETE', '/platform/clients/' . self::T . '/workflows/higher/grades_lifecycle', ['reason' => 'Fin de la politique.']);
        self::assertSame(200, $reset[0]);
        self::assertSame('scolarite', $this->step($this->ok('GET', self::GRADES, null, self::WF_ADMIN), 'publish')['effective']['actor']);
        self::assertSame(['reset', 'set'], array_column($this->platform('GET', '/platform/clients/' . self::T . '/workflows/higher/grades_lifecycle/history')[1]['data'], 'kind'));
    }

    public function testLePlancherDeLaPlateformeVautPourTousLesClients(): void
    {
        $this->secondFactor->validate('platform', self::PLATFORM_ADMIN);
        $floor = ['steps' => ['publish' => ['recent_confirmation' => true, 'enforced' => true]], 'reason' => 'Plancher de sécurité de la plateforme.'];
        self::assertSame(200, $this->platform('PUT', '/platform/workflows/higher/grades_lifecycle', $floor)[0]);

        foreach ([[self::T, self::E, self::WF_ADMIN], [self::TB, self::EB, self::WF_ADMIN_B]] as [$tenant, $establishment, $admin]) {
            $this->confirm($tenant, $admin);
            $view = $this->ok('GET', self::GRADES, null, $admin, $tenant, $establishment);
            self::assertSame('platform', $this->step($view, 'publish')['imposed_by']);
            [$status, $problem] = $this->call('PUT', self::GRADES, ['steps' => ['publish' => ['recent_confirmation' => false]], 'reason' => 'Je veux lever le plancher.'], $admin, $tenant, $establishment);
            self::assertSame(422, $status);
            self::assertStringContainsString('plateforme', $problem['errors']['steps.publish'][0]);
        }
        self::assertSame(403, $this->platform('PUT', '/platform/workflows/higher/grades_lifecycle', $floor, 'intrus')[0]);
    }

    public function testLesCircuitsEffectifsSontLisiblesParToutLePersonnelSansLesMotifs(): void
    {
        self::assertSame(403, $this->call('GET', '/workflows', null, self::ADMIN)[0], 'La liste complète reste réservée à workflows.manage.');

        $default = $this->ok('GET', '/workflows/effective', null, self::ADMIN);
        self::assertSame('billing:billing_reversal', $default[0]['id']);
        self::assertSame([false], array_unique(array_column($default, 'customised')));
        $approval = $default[0]['steps'][1];
        self::assertSame('default', $approval['source']);
        self::assertSame(['key' => 'comptable', 'permission' => 'billing.reversal.approve', 'role' => null], $approval['actor']);

        $this->confirm();
        self::assertSame(200, $this->put(['publish' => ['actor' => 'examens']])[0]);
        $after = $this->ok('GET', '/workflows/effective', null, self::ADMIN);
        $grades = array_values(array_filter($after, static fn (array $c): bool => $c['id'] === 'higher:grades_lifecycle'))[0];
        self::assertTrue($grades['customised']);
        self::assertSame('higher.exam.manage', array_values(array_filter($grades['steps'], static fn (array $s): bool => $s['key'] === 'publish'))[0]['actor']['permission']);
        self::assertStringNotContainsString('Le chef de département est absent', json_encode($after, JSON_THROW_ON_ERROR), 'Aucun motif exposé.');

        $other = $this->ok('GET', '/workflows/effective', null, self::WF_ADMIN_B, self::TB, self::EB);
        $gradesB = array_values(array_filter($other, static fn (array $c): bool => $c['id'] === 'higher:grades_lifecycle'))[0];
        self::assertFalse($gradesB['customised'], 'Le réglage du client A ne se lit pas chez le client B.');
    }
}
