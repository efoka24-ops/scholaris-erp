<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Messaging\Infrastructure\ChatRepository;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Throwable;

/**
 * Messagerie interne modérée. Règle de périmètre : une conversation lie UN parent (garant d'un apprenant inscrit dans
 * l'établissement, consentement de communication donné) à des enseignants de cet apprenant (kind teacher_parent) ou à
 * la direction (kind direction_parent). Le périmètre est recontrôlé à chaque envoi : un consentement retiré ou un
 * enseignant qui ne suit plus l'apprenant ferme la conversation en écriture.
 *
 * Les messages masqués par un modérateur restent en base (preuve) mais ne sont plus servis aux participants.
 * Les notifications ne contiennent jamais le texte du message.
 */
final class ChatService
{
    public const TEACHER_PARENT = 'teacher_parent';
    public const DIRECTION_PARENT = 'direction_parent';
    public const KINDS = [self::TEACHER_PARENT, self::DIRECTION_PARENT];

    public const OPEN = 'open';
    public const ARCHIVED = 'archived';
    public const VISIBLE = 'visible';
    public const HIDDEN = 'hidden';

    public const PARENT = 'parent';
    public const TEACHER = 'teacher';
    public const DIRECTION = 'direction';

    public const MAX_PARTICIPANTS = 4;
    public const MAX_ATTACHMENTS = 5;

    public function __construct(
        private readonly Store $store,
        private readonly ChatRepository $chat,
        private readonly UserPersonLink $users,
        private readonly PersonDirectory $persons,
        private readonly EnrollmentDirectory $enrollments,
        private readonly StaffDirectory $staff,
        private readonly TeachingScope $teaching,
        private readonly DirectionDirectory $direction,
        private readonly ConsentChecker $consents,
        private readonly DocumentCatalog $documents,
        private readonly Messenger $messenger,
        private readonly MessagingClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Personne derrière le compte connecté. */
    public function personOf(string $tenantId, string $userId): string
    {
        return $this->users->personIdForUser($tenantId, $userId)
            ?? throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : messagerie indisponible.');
    }

    /**
     * Rôle d'une personne dans une conversation portant sur cet apprenant, null si elle est hors périmètre.
     */
    public function roleOf(string $tenantId, string $establishmentId, string $personId, string $learnerId, string $kind): ?string
    {
        foreach ($this->persons->guarantorsOf($tenantId, $learnerId) as $guarantor) {
            if ($guarantor->id === $personId) {
                return self::PARENT;
            }
        }
        if ($kind === self::TEACHER_PARENT) {
            $member = $this->staff->find($tenantId, $personId);
            if ($member !== null && $member->status === 'active'
                && in_array($personId, $this->staff->teacherIdsIn($tenantId, $establishmentId), true)
                && in_array($learnerId, $this->teaching->learnerIdsOf($tenantId, $personId), true)) {
                return self::TEACHER;
            }
        }
        if ($kind === self::DIRECTION_PARENT && in_array($personId, $this->direction->directionPersonIds($tenantId, $establishmentId), true)) {
            return self::DIRECTION;
        }

        return null;
    }

    /**
     * Interlocuteurs que la personne peut joindre au sujet d'un apprenant (assistance à la création).
     *
     * @return list<array{person_id: string, name: string, chat_role: string, kind: string}>
     */
    public function contacts(string $tenantId, string $establishmentId, string $actorPersonId, string $learnerId): array
    {
        $this->assertEnrolled($tenantId, $establishmentId, $learnerId);
        $contacts = [];
        $guarantors = $this->persons->guarantorsOf($tenantId, $learnerId);
        $actorIsParent = in_array($actorPersonId, array_map(static fn ($p): string => $p->id, $guarantors), true);

        if ($actorIsParent) {
            $candidates = [
                self::TEACHER_PARENT => array_values(array_filter(
                    $this->staff->teacherIdsIn($tenantId, $establishmentId),
                    fn (string $id): bool => $this->roleOf($tenantId, $establishmentId, $id, $learnerId, self::TEACHER_PARENT) === self::TEACHER,
                )),
                self::DIRECTION_PARENT => $this->direction->directionPersonIds($tenantId, $establishmentId),
            ];
            $roles = [self::TEACHER_PARENT => self::TEACHER, self::DIRECTION_PARENT => self::DIRECTION];
            foreach ($candidates as $kind => $ids) {
                foreach ($this->persons->findMany($tenantId, $ids) as $person) {
                    $contacts[] = ['person_id' => $person->id, 'name' => $person->fullName(), 'chat_role' => $roles[$kind], 'kind' => $kind];
                }
            }

            return $contacts;
        }

        foreach ([self::TEACHER_PARENT, self::DIRECTION_PARENT] as $kind) {
            if ($this->roleOf($tenantId, $establishmentId, $actorPersonId, $learnerId, $kind) === null) {
                continue;
            }
            foreach ($guarantors as $parent) {
                if ($this->consents->hasConsent($tenantId, $parent->id, ConsentChecker::COMMUNICATION)) {
                    $contacts[] = ['person_id' => $parent->id, 'name' => $parent->fullName(), 'chat_role' => self::PARENT, 'kind' => $kind];
                }
            }
        }

        return $contacts;
    }

    /**
     * @param array<string, mixed> $data subject, kind, learner_id, participant_ids, body, attachments
     * @return array<string, mixed> la conversation créée
     */
    public function create(string $tenantId, string $establishmentId, string $actorPersonId, array $data): array
    {
        $kind = (string) $data['kind'];
        $learnerId = (string) $data['learner_id'];
        $this->assertEnrolled($tenantId, $establishmentId, $learnerId);

        $people = array_values(array_unique([$actorPersonId, ...array_map('strval', (array) $data['participant_ids'])]));
        if (count($people) > self::MAX_PARTICIPANTS) {
            throw HttpException::validation(['participant_ids' => [sprintf('Une conversation compte %d participants au plus.', self::MAX_PARTICIPANTS)]]);
        }
        $known = $this->persons->findMany($tenantId, $people);
        $roles = [];
        foreach ($people as $personId) {
            $role = $this->roleOf($tenantId, $establishmentId, $personId, $learnerId, $kind);
            if ($role === null || !in_array($personId, array_map(static fn ($p): string => $p->id, $known), true)) {
                // Même message pour « inconnu » et « hors périmètre » : on ne révèle pas l'existence d'une personne.
                throw HttpException::validation(['participant_ids' => ['Interlocuteur hors de votre périmètre pour cet apprenant.']]);
            }
            $roles[$personId] = $role;
        }
        $parents = array_keys(array_filter($roles, static fn (string $r): bool => $r === self::PARENT));
        if (count($parents) !== 1 || count($roles) < 2) {
            throw HttpException::validation(['participant_ids' => ['Une conversation réunit un parent de l\'apprenant et au moins un enseignant ou membre de la direction.']]);
        }
        if (!$this->consents->hasConsent($tenantId, $parents[0], ConsentChecker::COMMUNICATION)) {
            throw HttpException::validation(['participant_ids' => ['Le parent n\'a pas donné son consentement aux communications.']]);
        }
        $attachments = $this->attachments($tenantId, $learnerId, array_values((array) ($data['attachments'] ?? [])));

        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $messageId = $this->chat->transactional(function () use ($tenantId, $establishmentId, $actorPersonId, $data, $kind, $learnerId, $roles, $attachments, $id, $now): string {
            $this->store->insert('conversations', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'establishment_id' => $establishmentId,
                'subject' => $data['subject'],
                'created_by' => $actorPersonId,
                'status' => self::OPEN,
                'kind' => $kind,
                'learner_id' => $learnerId,
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($roles as $personId => $role) {
                $this->store->insert('conversation_participants', [
                    'id' => (string) new Ulid(),
                    'tenant_id' => $tenantId,
                    'establishment_id' => $establishmentId,
                    'conversation_id' => $id,
                    'person_id' => (string) $personId,
                    'chat_role' => $role,
                    'created_at' => $now,
                ]);
            }

            return $this->insertMessage($tenantId, $establishmentId, $id, $actorPersonId, (string) $data['body'], $attachments, $now);
        });
        $this->chat->markRead($tenantId, $id, $actorPersonId, $messageId);
        $this->notify($tenantId, $establishmentId, $id, $actorPersonId);

        return $this->conversation($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function conversation(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->find('conversations', $tenantId, $establishmentId, $id)
            ?? throw HttpException::notFound('Conversation introuvable.');
    }

    /**
     * Conversation visible par cette personne : participante, ou modératrice (`$moderator`). Sinon 404.
     *
     * @return array{conversation: array<string, mixed>, participant: array<string, mixed>|null}
     */
    public function visibleTo(string $tenantId, string $establishmentId, string $id, string $personId, bool $moderator): array
    {
        $conversation = $this->conversation($tenantId, $establishmentId, $id);
        $participant = $this->chat->participant($tenantId, $id, $personId);
        if ($participant === null && !$moderator) {
            throw HttpException::notFound('Conversation introuvable.');
        }

        return ['conversation' => $conversation, 'participant' => $participant];
    }

    /** @return list<array<string, mixed>> participants avec leur nom */
    public function participants(string $tenantId, string $conversationId): array
    {
        $rows = $this->chat->participants($tenantId, $conversationId);
        $names = [];
        foreach ($this->persons->findMany($tenantId, array_map(static fn (array $r): string => (string) $r['person_id'], $rows)) as $person) {
            $names[$person->id] = $person->fullName();
        }

        return array_map(static fn (array $r): array => [
            'person_id' => (string) $r['person_id'],
            'name' => $names[(string) $r['person_id']] ?? null,
            'chat_role' => (string) $r['chat_role'],
        ], $rows);
    }

    /** Marque comme lus les messages jusqu'à $messageId pour ce participant. */
    public function markRead(string $tenantId, string $conversationId, string $personId, string $messageId): void
    {
        $this->chat->markRead($tenantId, $conversationId, $personId, $messageId);
    }

    /**
     * @param array<string, mixed> $data body, attachments
     * @return array<string, mixed> le message posté
     */
    public function post(string $tenantId, string $establishmentId, string $actorPersonId, string $conversationId, array $data): array
    {
        ['conversation' => $conversation, 'participant' => $participant] = $this->visibleTo($tenantId, $establishmentId, $conversationId, $actorPersonId, false);
        if ($participant === null) {
            throw HttpException::notFound('Conversation introuvable.');
        }
        if ($conversation['status'] !== self::OPEN) {
            throw HttpException::conflict('Cette conversation est archivée.');
        }
        $this->assertStillAllowed($tenantId, $establishmentId, $conversation);
        $attachments = $this->attachments($tenantId, (string) $conversation['learner_id'], array_values((array) ($data['attachments'] ?? [])));

        $now = $this->clock->iso();
        $messageId = $this->insertMessage($tenantId, $establishmentId, $conversationId, $actorPersonId, (string) $data['body'], $attachments, $now);
        $this->store->update('conversations', $tenantId, $establishmentId, $conversationId, ['last_message_at' => $now, 'updated_at' => $now]);
        $this->chat->markRead($tenantId, $conversationId, $actorPersonId, $messageId);
        $this->notify($tenantId, $establishmentId, $conversationId, $actorPersonId);

        return $this->message($tenantId, $establishmentId, $conversationId, $messageId);
    }

    /** @return array<string, mixed> */
    public function message(string $tenantId, string $establishmentId, string $conversationId, string $messageId): array
    {
        $row = $this->store->find('conversation_messages', $tenantId, $establishmentId, $messageId);
        if ($row === null || $row['conversation_id'] !== $conversationId) {
            throw HttpException::notFound('Message introuvable.');
        }

        return $row;
    }

    /** @return array<string, mixed> le signalement */
    public function report(string $tenantId, string $establishmentId, string $actorPersonId, string $conversationId, string $messageId, string $reason): array
    {
        $this->visibleTo($tenantId, $establishmentId, $conversationId, $actorPersonId, false);
        $message = $this->message($tenantId, $establishmentId, $conversationId, $messageId);
        if ($message['sender_id'] === $actorPersonId) {
            throw HttpException::validation(['message_id' => ['Vous ne pouvez pas signaler votre propre message.']]);
        }
        if ($this->chat->reportExists($tenantId, $messageId, $actorPersonId)) {
            throw HttpException::conflict('Vous avez déjà signalé ce message.');
        }
        $id = (string) new Ulid();
        $this->store->insert('conversation_reports', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'reported_by' => $actorPersonId,
            'reason' => $reason,
            'status' => 'open',
            'created_at' => $this->clock->iso(),
        ]);

        return $this->store->find('conversation_reports', $tenantId, $establishmentId, $id) ?? [];
    }

    /**
     * Masque un message (modération).
     *
     * @return array<string, mixed> le message masqué
     */
    public function hide(string $tenantId, string $establishmentId, string $moderatorUserId, string $conversationId, string $messageId, string $reason): array
    {
        $this->conversation($tenantId, $establishmentId, $conversationId);
        $message = $this->message($tenantId, $establishmentId, $conversationId, $messageId);
        if ($message['moderation_status'] === self::HIDDEN) {
            throw HttpException::conflict('Ce message est déjà masqué.');
        }
        $now = $this->clock->iso();
        $this->store->update('conversation_messages', $tenantId, $establishmentId, $messageId, [
            'moderation_status' => self::HIDDEN,
            'moderated_by' => $moderatorUserId,
            'moderated_at' => $now,
            'moderation_reason' => $reason,
        ]);
        $this->chat->resolveOpenReports($tenantId, $establishmentId, $messageId, 'actioned', $moderatorUserId, $now);

        return $this->message($tenantId, $establishmentId, $conversationId, $messageId);
    }

    /**
     * Rétablit un message masqué.
     *
     * @return array<string, mixed>
     */
    public function restore(string $tenantId, string $establishmentId, string $moderatorUserId, string $conversationId, string $messageId): array
    {
        $this->conversation($tenantId, $establishmentId, $conversationId);
        $message = $this->message($tenantId, $establishmentId, $conversationId, $messageId);
        if ($message['moderation_status'] !== self::HIDDEN) {
            throw HttpException::conflict('Ce message n\'est pas masqué.');
        }
        $this->store->update('conversation_messages', $tenantId, $establishmentId, $messageId, [
            'moderation_status' => self::VISIBLE,
            'moderated_by' => $moderatorUserId,
            'moderated_at' => $this->clock->iso(),
            'moderation_reason' => null,
        ]);

        return $this->message($tenantId, $establishmentId, $conversationId, $messageId);
    }

    /**
     * Classe un signalement sans suite.
     *
     * @return array<string, mixed>
     */
    public function dismiss(string $tenantId, string $establishmentId, string $moderatorUserId, string $reportId): array
    {
        $report = $this->store->find('conversation_reports', $tenantId, $establishmentId, $reportId)
            ?? throw HttpException::notFound('Signalement introuvable.');
        if ($report['status'] !== 'open') {
            throw HttpException::conflict('Ce signalement est déjà traité.');
        }
        $changes = ['status' => 'dismissed', 'resolved_by' => $moderatorUserId, 'resolved_at' => $this->clock->iso()];
        $this->store->update('conversation_reports', $tenantId, $establishmentId, $reportId, $changes);

        return $changes + $report;
    }

    /** @return array<string, mixed> */
    public function archive(string $tenantId, string $establishmentId, string $conversationId): array
    {
        $conversation = $this->conversation($tenantId, $establishmentId, $conversationId);
        if ($conversation['status'] === self::ARCHIVED) {
            throw HttpException::conflict('Cette conversation est déjà archivée.');
        }
        $now = $this->clock->iso();
        $this->store->update('conversations', $tenantId, $establishmentId, $conversationId, ['status' => self::ARCHIVED, 'archived_at' => $now, 'updated_at' => $now]);

        return $this->conversation($tenantId, $establishmentId, $conversationId);
    }

    private function assertEnrolled(string $tenantId, string $establishmentId, string $learnerId): void
    {
        foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->status === EnrollmentInfo::ACTIVE) {
                return;
            }
        }

        throw HttpException::validation(['learner_id' => ['Apprenant inconnu ou non inscrit dans cet établissement.']]);
    }

    /** @param array<string, mixed> $conversation */
    private function assertStillAllowed(string $tenantId, string $establishmentId, array $conversation): void
    {
        $learnerId = (string) $conversation['learner_id'];
        $kind = (string) $conversation['kind'];
        $allowed = true;
        foreach ($this->chat->participants($tenantId, (string) $conversation['id']) as $participant) {
            $role = $this->roleOf($tenantId, $establishmentId, (string) $participant['person_id'], $learnerId, $kind);
            if ($role === null) {
                $allowed = false;
            } elseif ($role === self::PARENT && !$this->consents->hasConsent($tenantId, (string) $participant['person_id'], ConsentChecker::COMMUNICATION)) {
                $allowed = false;
            }
        }
        if (!$allowed) {
            throw HttpException::forbidden('Cette conversation n\'est plus autorisée : périmètre ou consentement modifié.');
        }
    }

    /**
     * @param list<mixed> $ids
     * @return list<array{document_id: string, number: string, type: string}>
     */
    private function attachments(string $tenantId, string $learnerId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $available = [];
        foreach ($this->documents->validFor($tenantId, $learnerId, 100) as $document) {
            $available[$document->id] = ['document_id' => $document->id, 'number' => $document->number, 'type' => $document->type];
        }
        $out = [];
        foreach (array_unique(array_map('strval', $ids)) as $id) {
            if (!isset($available[$id])) {
                throw HttpException::validation(['attachments' => ['Document inconnu, révoqué ou sans lien avec cet apprenant.']]);
            }
            $out[] = $available[$id];
        }

        return $out;
    }

    /** @param list<array{document_id: string, number: string, type: string}> $attachments */
    private function insertMessage(string $tenantId, string $establishmentId, string $conversationId, string $senderId, string $body, array $attachments, string $now): string
    {
        $id = (string) new Ulid();
        $this->store->insert('conversation_messages', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'body' => $body,
            'attachments' => $attachments === [] ? null : json_encode($attachments, JSON_THROW_ON_ERROR),
            'moderation_status' => self::VISIBLE,
            'created_at' => $now,
        ]);

        return $id;
    }

    /** Prévient les autres participants (sans le texte). Un échec de notification ne bloque jamais le message. */
    private function notify(string $tenantId, string $establishmentId, string $conversationId, string $senderPersonId): void
    {
        foreach ($this->chat->participants($tenantId, $conversationId) as $participant) {
            $personId = (string) $participant['person_id'];
            if ($personId === $senderPersonId) {
                continue;
            }
            try {
                $this->messenger->send($tenantId, $establishmentId, $personId, 'chat_new_message');
            } catch (Throwable $e) {
                $this->logger->warning('Notification de messagerie non envoyée', ['conversation' => $conversationId, 'error' => $e::class]);
            }
        }
    }
}
