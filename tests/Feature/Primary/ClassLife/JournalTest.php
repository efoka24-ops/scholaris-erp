<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\ClassLife;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Primary\PrimaryTestCase;

/** EX-PRI-38, 39 : cahier de liaison publié aux seuls parents de la classe, accusé unique, notification, compétences publiées. */
final class JournalTest extends PrimaryTestCase
{
    public function testPublicationAccuseEtIsolationDesFamilles(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $messenger = new RecordingMessenger();
        $link = new class () implements UserPersonLink {
            public function personIdForUser(string $tenantId, string $userId): ?string
            {
                return str_starts_with($userId, 'parent') ? 'p-' . substr($userId, 7) : null;
            }
        };
        $this->bootPrimary([EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people, Messenger::class => $messenger, UserPersonLink::class => $link]);
        $l = $this->makeLevel(self::TA, self::EA, 'CE1', 2);
        foreach ([1 => 0, 2 => 1] as $n => $classIndex) {
            $people->add(self::TA, "p-$n", "Parent$n", 'Test');
            $people->add(self::TA, "kid$n", "Enfant$n", 'Test');
            $people->link(self::TA, "p-$n", "kid$n");
            $enrollments->add(self::TA, "en$n", "kid$n", self::EA, $l['classes'][$classIndex], 'ay1');
        }
        $class = $l['classes'][0];
        $this->assign(self::TA, 'maitre', self::EA, 'primary.journal.write', Scope::CLASS_GROUP, $class);
        $this->assign(self::TA, 'parent-1', self::EA, 'self.read', Scope::ESTABLISHMENT);
        $this->assign(self::TA, 'parent-2', self::EA, 'self.read', Scope::ESTABLISHMENT);

        $entry = $this->ok('POST', "/primary/classes/$class/journal", ['title' => 'Devoir', 'body' => 'Page 12', 'entry_kind' => 'homework'], 'maitre');
        self::assertSame([], $this->ok('GET', '/primary/family/children/kid1/journal', null, 'parent-1')['items'], 'Brouillon invisible.');
        self::assertSame(403, $this->call('POST', "/primary/classes/{$l['classes'][1]}/journal", ['title' => 'x', 'body' => 'y'], 'maitre')[0]);

        $published = $this->ok('POST', "/primary/journal/{$entry['id']}/publish", null, 'maitre');
        self::assertSame(1, $published['notified']);
        self::assertSame(['p-1'], array_column($messenger->of('pri.not05.logbook_entry'), 'person'));
        self::assertSame(409, $this->call('PATCH', "/primary/journal/{$entry['id']}", ['title' => 'z'], 'maitre')[0]);

        self::assertCount(1, $this->ok('GET', '/primary/family/children/kid1/journal', null, 'parent-1')['items']);
        self::assertSame(404, $this->call('GET', '/primary/family/children/kid1/journal', null, 'parent-2')[0], 'Enfant d\'une autre famille.');
        self::assertSame(404, $this->call('POST', "/primary/journal/{$entry['id']}/seen", null, 'parent-2')[0], 'Autre classe.');
        $first = $this->ok('POST', "/primary/journal/{$entry['id']}/seen", null, 'parent-1');
        $second = $this->ok('POST', "/primary/journal/{$entry['id']}/seen", null, 'parent-1');
        self::assertSame($first['seen_at'], $second['seen_at']);
        $list = $this->ok('GET', "/primary/classes/$class/journal", null, 'maitre');
        self::assertSame(1, $list['items'][0]['seen_count']);
        self::assertSame([], $this->ok('GET', '/primary/family/children/kid1/competencies', null, 'parent-1')['periods']);
    }
}
