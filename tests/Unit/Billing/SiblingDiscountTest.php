<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Billing\Application\SiblingDiscountResolver;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;

/** Remise de fratrie : les frères et soeurs sont reconnus d'un établissement à l'autre, à l'intérieur d'un même client. */
final class SiblingDiscountTest extends TestCase
{
    private InMemoryPeople $people;
    private InMemoryEnrollments $enrollments;
    private SiblingDiscountResolver $resolver;

    protected function setUp(): void
    {
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->resolver = new SiblingDiscountResolver($this->people, $this->enrollments);

        $this->people->add('t1', 'g1', 'Parent', 'Foka');
        foreach (['a-aine', 'b-cadet', 'c-benjamin'] as $learner) {
            $this->people->add('t1', $learner, $learner, 'Foka');
            $this->people->link('t1', 'g1', $learner);
        }
    }

    public function testLeFrereDuCollegeFaitBeneficierLeCadetDuPrimaire(): void
    {
        $this->enrollments->add('t1', 'en-a', 'a-aine', 'college');
        $this->enrollments->add('t1', 'en-b', 'b-cadet', 'primaire');

        self::assertFalse($this->resolver->isEligible('t1', 'a-aine'), 'Le premier enfant n\'a pas de remise.');
        self::assertTrue($this->resolver->isEligible('t1', 'b-cadet'), 'Le cadet, dans un autre établissement, en bénéficie.');
    }

    public function testUnEnfantUniqueOuUnFrereSansInscriptionActiveNaPasDeRemise(): void
    {
        $this->enrollments->add('t1', 'en-b', 'b-cadet', 'primaire');
        $this->enrollments->add('t1', 'en-a', 'a-aine', 'college', status: 'withdrawn');

        self::assertFalse($this->resolver->isEligible('t1', 'b-cadet'));
    }

    public function testLaRemiseNeTraverseJamaisLesClients(): void
    {
        $this->people->add('t2', 'g2', 'Autre', 'Parent');
        $this->people->add('t2', 'z-autre', 'Z', 'Autre');
        $this->people->link('t2', 'g2', 'z-autre');
        $this->enrollments->add('t1', 'en-a', 'a-aine', 'college');
        $this->enrollments->add('t2', 'en-z', 'z-autre', 'x1');
        $this->enrollments->add('t1', 'en-c', 'c-benjamin', 'primaire');

        self::assertFalse($this->resolver->isEligible('t2', 'z-autre'));
        self::assertTrue($this->resolver->isEligible('t1', 'c-benjamin'));
    }
}
