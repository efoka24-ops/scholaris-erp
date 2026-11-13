<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;

/** Extensions des règles (S1, S6, S9) : défauts identiques au socle historique, validation stricte, vue publique. */
final class RuleSetExtensionsTest extends TestCase
{
    public function testLesDefautsRestentCeuxDuSocle(): void
    {
        $rules = RuleSet::defaults();

        self::assertSame('standard', $rules->strategy);
        self::assertSame(['Admis', 'Admis par compensation', 'Rattrapage', 'Ajourné'], $rules->decisionLabels());
        self::assertSame([RuleEngine::ADMITTED, RuleEngine::COMPENSATED], $rules->passingLabels());
        self::assertSame('excluded', $rules->absenceEffect('justified'));
        self::assertSame('zero', $rules->absenceEffect('unjustified'));
        self::assertSame('excluded', $rules->with(['unjustified_absence' => 'excluded'], 1)->absenceEffect('unjustified'));
        self::assertSame('excluded', $rules->absenceEffect('inconnu'));
    }

    public function testEtiquettesDeDecisionParametrables(): void
    {
        $rules = RuleSet::defaults()->with(['decision_labels' => ['admitted' => 'Admise', 'repeat' => 'Redouble']], 1);

        self::assertSame('Admise', $rules->decisionLabel('admitted'));
        self::assertSame(['Admise', 'Admis par compensation'], $rules->passingLabels());
        self::assertContains('Redouble', $rules->decisionLabels());

        $engine = new RuleEngine();
        $result = $engine->compute($rules, [[
            'subject_id' => 'm', 'kind' => 'subject', 'coefficient' => 1.0, 'credits' => 0,
            'groups' => [['coefficient' => 1.0, 'entries' => [['value' => 15.0, 'max' => 20.0, 'weight' => 1.0, 'absence' => null]]]],
        ]]);
        self::assertSame('Admise', $result['deliberation_proposal']);
    }

    public function testEffetsDeclaratifsDesCodesDAbsence(): void
    {
        $rules = RuleSet::defaults()->with(['absence_effects' => ['na' => 'zero', 'fr' => 'zero', 'justified' => 'retake']], 1);
        $subject = static fn (?string $absence): array => [[
            'subject_id' => 'm', 'kind' => 'subject', 'coefficient' => 1.0, 'credits' => 0,
            'groups' => [['coefficient' => 1.0, 'entries' => [
                ['value' => 16.0, 'max' => 20.0, 'weight' => 1.0, 'absence' => null],
                ['value' => null, 'max' => 20.0, 'weight' => 1.0, 'absence' => $absence],
            ]]],
        ]];
        $engine = new RuleEngine();

        self::assertSame(8.0, $engine->compute($rules, $subject('na'))['average'], 'NA vaut zéro.');
        self::assertSame(8.0, $engine->compute($rules, $subject('fr'))['average'], 'FR vaut zéro.');
        self::assertSame(16.0, $engine->compute($rules, $subject('justified'))['average'], 'retake : exclue en attendant le remplacement.');
    }

    public function testValidationDesNouvellesRegles(): void
    {
        foreach ([
            ['strategy' => 'LMD!'],
            ['strategy_options' => [1, 2]],
            ['decision_labels' => ['admitted' => '']],
            ['decision_labels' => ['admitted' => 'Ajourné']],
            ['absence_effects' => ['na' => 'double']],
            ['absence_effects' => ['code_beaucoup_trop_long' => 'zero']],
        ] as $definition) {
            try {
                RuleSet::defaults()->with($definition, 1);
                self::fail('Définition acceptée à tort : ' . json_encode($definition));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLaVuePubliqueEstReversible(): void
    {
        $rules = RuleSet::defaults()->with(['strategy' => 'lmd', 'strategy_options' => ['eliminatory' => 8], 'decision_labels' => ['repeat' => 'Redouble'], 'absence_effects' => ['na' => 'zero'], 'unjustified_absence' => 'excluded', 'pass_mark' => 9], 3);
        $public = $rules->toCalculationRules();
        $back = RuleSet::fromCalculationRules($public);

        self::assertSame(8.0, $public->number('eliminatory', 7.0));
        self::assertSame('Redouble', $public->label('repeat'));
        self::assertTrue($public->isPassing('Admis'));
        self::assertSame(['strategy' => 'lmd', 'pass_mark' => 9.0, 'unjustified' => 'excluded', 'na' => 'zero', 'labels' => $rules->decisionLabels()], [
            'strategy' => $back->strategy, 'pass_mark' => $back->passMark, 'unjustified' => $back->absenceEffect('unjustified'), 'na' => $back->absenceEffect('na'), 'labels' => $back->decisionLabels(),
        ]);
    }

    public function testEnSessionDeRattrapageLaNoteDeRattrapageRemplaceLeGroupe(): void
    {
        $engine = new RuleEngine();
        $subject = [[
            'subject_id' => 'm', 'kind' => 'subject', 'coefficient' => 1.0, 'credits' => 0,
            'groups' => [['coefficient' => 1.0, 'entries' => [
                ['value' => 6.0, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'session' => 'normal'],
                ['value' => 12.0, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'session' => 'resit'],
            ]]],
        ]];

        self::assertSame(12.0, $engine->compute(RuleSet::defaults(), $subject)['average']);
    }
}
