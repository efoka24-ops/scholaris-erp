<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Base des tests de la messagerie interne. Client t1, établissement e1 :
 *  - parents : paula (personne par1, enfant kid1) et pierre (par2, enfant kid2) ;
 *  - enseignants : tom (tea1, suit kid1) et tina (tea2, suit kid2) ;
 *  - direction : dora (dir1) ; modérateur : mona (messaging.moderate seul).
 * Client t2, établissement e3 : bob (personne bpar), tous droits de la messagerie interne.
 */
abstract class ChatTestCase extends MessagingTestCase
{
    protected ChatWorld $world;
    protected ChatEnrollments $learners;

    protected function bootChat(): void
    {
        $this->world = new ChatWorld();
        $this->learners = new ChatEnrollments();
        $this->bootMessaging([
            UserPersonLink::class => $this->world,
            StaffDirectory::class => $this->world,
            TeachingScope::class => $this->world,
            DirectionDirectory::class => $this->world,
            DocumentCatalog::class => $this->world,
            EnrollmentDirectory::class => $this->learners,
        ]);

        foreach ([['par1', 'Paula', 'Foka'], ['par2', 'Pierre', 'Mbarga'], ['tea1', 'Tom', 'Ndi'], ['tea2', 'Tina', 'Eto'], ['dir1', 'Dora', 'Bello']] as [$id, $first, $last]) {
            $this->people->add('t1', $id, $first, $last, '+237670' . substr(str_pad((string) abs(crc32($id)), 6, '0'), 0, 6));
        }
        $this->people->add('t1', 'kid1', 'Kevin', 'Foka', null, null, 'fr', false);
        $this->people->add('t1', 'kid2', 'Karine', 'Mbarga', null, null, 'fr', false);
        $this->people->add('t2', 'bpar', 'Bob', 'Parent');
        $this->people->guarantor('t1', 'kid1', 'par1');
        $this->people->guarantor('t1', 'kid2', 'par2');

        foreach (['paula' => 'par1', 'pierre' => 'par2', 'tom' => 'tea1', 'tina' => 'tea2', 'dora' => 'dir1'] as $user => $person) {
            $this->world->user('t1', $user, $person);
            $this->access->grant($user, 'e1');
            $this->permissions->grant($user, 'messaging.chat');
        }
        $this->world->user('t1', 'mona', 'mod1');
        $this->access->grant('mona', 'e1');
        $this->permissions->grant('mona', 'messaging.moderate');
        $this->world->user('t2', 'bob', 'bpar');
        $this->permissions->grant('bob', 'messaging.chat', 'messaging.moderate');

        $this->learners->enrol('t1', 'e1', 'kid1');
        $this->learners->enrol('t1', 'e1', 'kid2');
        $this->world->teacher('t1', 'e1', 'tea1', ['kid1']);
        $this->world->teacher('t1', 'e1', 'tea2', ['kid2']);
        $this->world->direction('t1', 'e1', 'dir1');
        $this->world->document('t1', 'kid1', 'doc-kid1');
        $this->world->document('t1', 'kid2', 'doc-kid2');
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function conversationBody(array $override = []): array
    {
        return $override + [
            'subject' => 'Suivi de Kevin',
            'kind' => 'teacher_parent',
            'learner_id' => 'kid1',
            'participant_ids' => ['tea1'],
            'body' => 'Bonjour, puis-je vous rencontrer ?',
        ];
    }

    /** Ouvre une conversation (paula avec tom par défaut) et renvoie son identifiant. */
    protected function openConversation(string $user = 'paula', array $override = []): string
    {
        [$response, $body] = $this->api('POST', '/conversations', $user, 't1', 'e1', $this->conversationBody($override));
        self::assertSame(201, $response->getStatusCode(), json_encode($body));

        return (string) $body['data']['id'];
    }
}
