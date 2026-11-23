<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Workflows\Application\OverrideValidator;
use Scholaris\Modules\Socle\Workflows\Application\Resolution;
use Scholaris\Modules\Socle\Workflows\Contracts\Actor;
use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Lock;
use Scholaris\Modules\Socle\Workflows\Contracts\Step;

/**
 * Règle de résolution (défaut, surcharge, verrous, plancher posé par un niveau supérieur) et validation stricte des surcharges.
 * Circuit fictif de démonstration : demande (verrouillée), validation (acteur au choix, facultative), verrou (confirmation
 * obligatoire), publication (acteur au choix, confirmation par défaut).
 */
final class WorkflowsResolutionTest extends TestCase
{
    private function circuit(): Circuit
    {
        $two = static fn (): array => [new Actor('chef', 'Chef', 'Head', 'x.validate'), new Actor('dir', 'Directeur', 'Director', 'x.approve', 'role_dir')];

        return new Circuit('demo', 'cycle', 'Cycle', 'Cycle', [
            new Step('request', 'Demande', 'Request', [new Actor('agent', 'Agent', 'Agent', 'x.enter')], 'agent'),
            new Step('validate', 'Validation', 'Validation', $two(), 'chef', optional: true),
            new Step('lock', 'Verrou', 'Lock', $two(), 'chef', confirmation: true, confirmationMin: true, locks: [
                new Lock(Lock::CONFIRMATION, 'needs_confirmation', 'Confirmation obligatoire.', 'Confirmation mandatory.'),
            ]),
            new Step('publish', 'Publication', 'Publication', $two(), 'chef', confirmation: true),
        ], [new Lock(Lock::CIRCUIT, 'two_people', 'Deux personnes.', 'Two people.')]);
    }

    /** @param array<string, array<string, mixed>> $docs */
    private function resolve(array $docs = []): \Scholaris\Modules\Socle\Workflows\Contracts\ResolvedCircuit
    {
        return Resolution::resolve($this->circuit(), $docs);
    }

    public function testSansReglageLaResolutionRendExactementLesDefauts(): void
    {
        $resolved = $this->resolve();

        self::assertFalse($resolved->customised);
        foreach ($this->circuit()->steps as $step) {
            $r = $resolved->step($step->key);
            self::assertSame([$step->defaultActor, true, $step->confirmation, 'default', null], [$r->actor->key, $r->enabled, $r->recentConfirmation, $r->source, $r->enforcedBy], $step->key);
        }
        self::assertSame('x.approve', $this->resolve(['establishment' => ['steps' => ['publish' => ['actor' => 'dir']]]])->step('publish')->permission());
    }

    public function testUneSurchargeChangeLActeurLActivationEtLaConfirmation(): void
    {
        $resolved = $this->resolve(['establishment' => ['steps' => [
            'validate' => ['enabled' => false],
            'publish' => ['actor' => 'dir', 'recent_confirmation' => false],
        ]]]);

        self::assertTrue($resolved->customised);
        self::assertFalse($resolved->step('validate')->enabled);
        $publish = $resolved->step('publish');
        self::assertSame(['dir', 'x.approve', 'role_dir', false, 'establishment'], [$publish->actor->key, $publish->permission(), $publish->role(), $publish->recentConfirmation, $publish->source]);
        self::assertSame('chef', $resolved->step('lock')->actor->key, 'Une étape non réglée garde son défaut.');
    }

    public function testLesNiveauxSAppliquentDansLOrdrePlateformeClientEtablissement(): void
    {
        $resolved = $this->resolve([
            'platform' => ['steps' => ['publish' => ['actor' => 'dir']]],
            'client' => ['steps' => ['publish' => ['actor' => 'chef']]],
            'establishment' => ['steps' => ['publish' => ['recent_confirmation' => false]]],
        ]);
        $publish = $resolved->step('publish');

        self::assertSame(['chef', false, 'establishment'], [$publish->actor->key, $publish->recentConfirmation, $publish->source]);
    }

    public function testUnPlancherImposeParLaPlateformeNeSeLevePasParUnNiveauInferieur(): void
    {
        $resolved = $this->resolve([
            'platform' => ['steps' => ['publish' => ['recent_confirmation' => true, 'actor' => 'dir', 'enforced' => true]]],
            'client' => ['steps' => ['publish' => ['actor' => 'chef']]],
            'establishment' => ['steps' => ['publish' => ['recent_confirmation' => false, 'actor' => 'chef']]],
        ]);
        $publish = $resolved->step('publish');

        self::assertSame(['dir', true, 'platform', 'platform'], [$publish->actor->key, $publish->recentConfirmation, $publish->source, $publish->enforcedBy]);
    }

    public function testUneValeurStockeeQuiLeveraitUnVerrouEstIgnoree(): void
    {
        $resolved = $this->resolve(['establishment' => ['steps' => [
            'lock' => ['recent_confirmation' => false],
            'request' => ['actor' => 'dir', 'enabled' => false],
            'publish' => ['actor' => 'inconnu', 'enabled' => false],
        ]]]);

        self::assertTrue($resolved->step('lock')->recentConfirmation, 'La confirmation du verrou ne se lève jamais.');
        self::assertSame(['agent', true], [$resolved->step('request')->actor->key, $resolved->step('request')->enabled], 'Acteur unique et étape obligatoire.');
        self::assertSame(['chef', true], [$resolved->step('publish')->actor->key, $resolved->step('publish')->enabled]);
        self::assertFalse($resolved->customised);
    }

    /** @param array<string, mixed> $body @param array<string, array<string, mixed>> $above @return array<string, list<string>> */
    private function refused(array $body, string $level = 'establishment', array $above = []): array
    {
        try {
            (new OverrideValidator())->normalise($this->circuit(), $body, $level, $above);
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);

            return $e->errors;
        }
        self::fail('Surcharge acceptée à tort.');
    }

    public function testLaValidationAccepteLesAjustementsPermisEtNormalise(): void
    {
        $doc = (new OverrideValidator())->normalise($this->circuit(), ['steps' => [
            'validate' => ['enabled' => false], 'publish' => ['actor' => 'dir', 'recent_confirmation' => false], 'lock' => ['recent_confirmation' => true, 'actor' => 'dir'],
        ]], 'establishment', []);

        self::assertEquals(['validate' => ['enabled' => false], 'publish' => ['actor' => 'dir', 'recent_confirmation' => false], 'lock' => ['recent_confirmation' => true, 'actor' => 'dir']], $doc['steps']);
    }

    public function testLaValidationRefuseToutCeQuiLeveraitUnVerrou(): void
    {
        $errors = $this->refused(['steps' => [
            'request' => ['actor' => 'dir'], 'lock' => ['recent_confirmation' => false], 'publish' => ['enabled' => false],
        ]]);

        self::assertSame(['steps.request.actor', 'steps.lock.recent_confirmation', 'steps.publish.enabled'], array_keys($errors));
        self::assertSame('Confirmation obligatoire.', $errors['steps.lock.recent_confirmation'][0], 'Le motif du verrou est expliqué.');
    }

    public function testLaValidationRefuseLesChampsEtEtapesInconnusEtLesTypesInvalides(): void
    {
        $errors = $this->refused(['steps' => ['fantome' => ['actor' => 'chef'], 'validate' => ['actor' => 'inconnu', 'couleur' => 'rouge', 'enabled' => 'non']], 'extra' => 1]);

        self::assertEqualsCanonicalizing(['extra', 'steps.fantome', 'steps.validate.actor', 'steps.validate.couleur', 'steps.validate.enabled'], array_keys($errors));
        self::assertSame(['steps'], array_keys($this->refused(['steps' => []])));
        self::assertSame(['steps'], array_keys($this->refused(['a', 'b'])));
    }

    public function testSeulUnNiveauSuperieurPeutImposerEtUnNiveauInferieurNePeutPasLeContredire(): void
    {
        self::assertSame(['steps.publish.enforced'], array_keys($this->refused(['steps' => ['publish' => ['actor' => 'dir', 'enforced' => true]]])));
        self::assertSame(['steps.publish.enforced'], array_keys($this->refused(['steps' => ['publish' => ['enforced' => true]]], 'client')), 'Valeur imposée à préciser.');
        $doc = (new OverrideValidator())->normalise($this->circuit(), ['steps' => ['publish' => ['recent_confirmation' => true, 'enforced' => true]]], 'platform', []);
        self::assertSame(['publish' => ['recent_confirmation' => true, 'enforced' => true]], $doc['steps']);

        $above = ['platform' => $doc];
        $errors = $this->refused(['steps' => ['publish' => ['recent_confirmation' => false], 'validate' => ['enabled' => false]]], 'establishment', $above);
        self::assertSame(['steps.publish'], array_keys($errors));
        self::assertStringContainsString('imposée par la plateforme', $errors['steps.publish'][0]);
        self::assertSame(['steps.publish'], array_keys($this->refused(['steps' => ['publish' => ['recent_confirmation' => true]]], 'client', $above)));
    }
}
