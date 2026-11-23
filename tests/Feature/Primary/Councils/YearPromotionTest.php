<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Councils;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Application\YearPromotion;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Tests\Support\Primary\FakeClassResults;
use Tests\Support\Primary\PrimaryTestCase;

/**
 * T555 (E2E) : clôture d'année du primaire. Les décisions du conseil validé du dernier trimestre alimentent People\YearPromotion
 * (moteur unique du passage) : les admis sont promus en brouillon d'inscription dans la classe suivante de la table de passage, les
 * non-admis ne le sont pas. Élèves et inscriptions RÉELS du socle (données fictives).
 */
final class YearPromotionTest extends PrimaryTestCase
{
    public function testAdmisPromusEnBrouillonRedoublantsNonPromus(): void
    {
        $results = new FakeClassResults();
        // Autorisation d'ouverture des niveaux supposée valide (hors sujet ici).
        $gate = new class () implements EnrolmentGate {
            public function canEnrol(string $tenantId, string $establishmentId, string $structureId, \DateTimeInterface $on): bool
            {
                return true;
            }
        };
        $this->bootPrimary([ClassResults::class => $results, EnrolmentGate::class => $gate], '2027-06-01');
        $terms = $this->seedYear(self::TA, self::EA);
        $now = '2026-09-01T08:00:00+01:00';
        $this->service(ConnectionFactory::class)->pdo()->prepare('INSERT INTO academic_years (id, tenant_id, establishment_id, label, starts_on, ends_on, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['AY-2027', self::TA, self::EA, '2027-2028', '2027-09-06', '2028-06-10', 'open', $now, $now]);
        $first = $this->makeLevel(self::TA, self::EA, 'CE1', 1);
        $second = $this->makeLevel(self::TA, self::EA, 'CE2', 1);
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $first['level'], 'sort_order' => 1]);
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $second['level'], 'sort_order' => 2]);
        [$c1, $c2] = [$first['classes'][0], $second['classes'][0]];

        $writer = $this->service(LearnerWriter::class);
        $ids = [];
        foreach (['Ada', 'Bello'] as $name) {
            $created = $writer->createLearner(self::TA, ['first_name' => $name, 'last_name' => 'SYN-Fictif', 'birth_date' => '2018-02-01', 'gender' => 'female'], [
                ['kind' => 'financial_guarantor', 'identity' => ['first_name' => 'Parent', 'last_name' => 'SYN-' . $name, 'phone' => '677000' . strlen($name) . '11']],
            ], 'admin');
            $ids[$name] = $created['person_id'];
            $writer->enroll(self::TA, self::EA, $created['person_id'], $c1, self::YEAR, '2026-09-07', 'admin');
        }
        $results->averages[$terms[3]['id']] = [$ids['Ada'] => 13.0, $ids['Bello'] => 7.5];

        $council = $this->ok('POST', '/primary/councils', ['class_id' => $c1, 'period_id' => $terms[3]['id'], 'starts_on' => $terms[3]['starts_on']]);
        $proposals = array_column($council['lines'], 'proposal', 'person_id');
        self::assertSame(['admitted', 'adjourned'], [$proposals[$ids['Ada']], $proposals[$ids['Bello']]]);
        $this->ok('POST', "/primary/councils/{$council['id']}/validate");
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'people.promotion.next_structure', [$c1 => $c2], self::DIRECTOR);

        $preview = $this->ok('GET', '/primary/closure/promotion-preview?academic_year_id=' . self::YEAR);
        self::assertSame([1, 1, 0], [$preview['totals']['admitted'], $preview['totals']['adjourned'], $preview['totals']['no_next_class']]);

        $step = $this->service(YearPromotion::class)->promote(self::TA, self::EA, self::YEAR, 'AY-2027');
        self::assertSame(1, $step->counts['promoted']);
        self::assertSame(1, $step->counts['repeating']);
        $drafts = $this->service(ConnectionFactory::class)->pdo()->prepare('SELECT person_id, structure_id, status FROM enrollments WHERE tenant_id = ? AND academic_year_id = ?');
        $drafts->execute([self::TA, 'AY-2027']);
        $rows = $drafts->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'Seul l\'admis est promu.');
        self::assertSame([$ids['Ada'], $c2, 'draft'], [$rows[0]['person_id'], $rows[0]['structure_id'], $rows[0]['status']]);
        self::assertSame(0, $this->service(YearPromotion::class)->promote(self::TA, self::EA, self::YEAR, 'AY-2027')->counts['promoted'], 'Idempotent.');
    }
}
