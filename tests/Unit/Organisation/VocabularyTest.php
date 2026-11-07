<?php

declare(strict_types=1);

namespace Tests\Unit\Organisation;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Organisation\Application\DefaultApplications;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationDescriptor;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationRegistry;

final class VocabularyTest extends TestCase
{
    public function testLeVocabulaireDesQuatreApplicationsSuitLaMaquette(): void
    {
        $registry = DefaultApplications::registry();

        self::assertSame(
            ['learner' => 'enfant', 'grouping' => 'section', 'period' => 'trimestre', 'document' => 'carnet de suivi'],
            $registry->for('nursery')?->vocabulary,
        );
        self::assertSame(
            ['learner' => 'élève', 'grouping' => 'classe', 'period' => 'séquence', 'document' => 'bulletin APC'],
            $registry->for('primary')?->vocabulary,
        );
        self::assertSame(
            ['learner' => 'élève', 'grouping' => 'classe', 'period' => 'trimestre', 'document' => 'bulletin'],
            $registry->for('secondary')?->vocabulary,
        );
        self::assertSame(
            ['learner' => 'étudiant', 'grouping' => 'filière', 'period' => 'semestre', 'document' => 'relevé'],
            $registry->for('higher')?->vocabulary,
        );
        self::assertCount(4, $registry->all());
    }

    public function testLeRegistreEstExtensible(): void
    {
        $registry = new ApplicationRegistry();
        self::assertNull($registry->for('higher'));

        $registry->register(new ApplicationDescriptor('higher', 'Supérieur', ['learner' => 'étudiant']));
        $registry->register(new ApplicationDescriptor('higher', 'IPES', ['learner' => 'apprenant']));

        self::assertSame('IPES', $registry->for('higher')?->label, 'Le dernier enregistrement l\'emporte.');
        self::assertCount(1, $registry->all());
    }
}
