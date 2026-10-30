<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Cahier de vie (EX-MAT-18, RG-MAT-6, SC-MAT-4) : publications d'une section avec des médias ÉTIQUETÉS (quels enfants figurent sur la
 * photo). Un média n'est jamais servi à un parent si l'un des enfants visibles n'a pas de consentement photo EN VIGUEUR, vérifié à
 * CHAQUE lecture : le retrait d'un consentement retire la photo à la lecture suivante. Un média non étiqueté ne se publie pas. Seul le
 * texte de la publication reste visible quand toutes les photos sont retenues. Conservation et purge : MediaRetention (nursery:purge-media) ;
 * consentement recueilli et révocable : PhotoConsentService. Toute lecture d'une photo est journalisée.
 */
final class LifeBookService
{
    public const ENTRIES = 'nu_lifebook_entries';
    public const MEDIA = 'nu_lifebook_media';
    public const MEDIA_CHILDREN = 'nu_lifebook_media_children';
    public const TEMPLATE_PUBLISHED = 'nursery.not07.lifebook';
    public const MEDIA_NAMESPACE = 'nursery';

    public function __construct(
        private readonly Store $store,
        private readonly SectionService $sections,
        private readonly ChildDirectory $children,
        private readonly MediaStore $media,
        private readonly ConsentChecker $consents,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data section_id, title, body?, activity_on?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $sectionId = (string) $data['section_id'];
        if (!$this->sections->exists($perimeter->tenantId, $perimeter->establishmentId, $sectionId)) {
            throw HttpException::notFound('Section introuvable.');
        }
        $this->access->assertWritable($principal, $perimeter, $sectionId, 'section', $sectionId);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::ENTRIES, $perimeter->tenantId, $perimeter->establishmentId, [
            'id' => $id, 'structure_id' => $sectionId, 'title' => (string) $data['title'], 'body' => isset($data['body']) ? (string) $data['body'] : null,
            'activity_on' => (string) ($data['activity_on'] ?? $this->clock->today()), 'author_id' => $principal->userId, 'status' => 'draft', 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'nursery.lifebook.created', 'lifebook_entry', $id, null, ['section_id' => $sectionId]);

        return $this->staffResource($this->entry($perimeter, $id, true));
    }

    /**
     * @param array<string, mixed> $changes title?, body?, activity_on?
     * @return array<string, mixed>
     */
    public function update(Principal $principal, Perimeter $perimeter, string $id, array $changes): array
    {
        $entry = $this->writableEntry($principal, $perimeter, $id);
        $this->expectDraft($entry);
        $values = array_intersect_key($changes, array_flip(['title', 'body', 'activity_on']));
        $this->store->update(self::ENTRIES, $perimeter->tenantId, $perimeter->establishmentId, $values + ['updated_at' => $this->clock->now()], ['id' => $id]);

        return $this->staffResource($this->entry($perimeter, $id, true));
    }

    /**
     * @param array<string, mixed> $data content_base64, children?: list<string>, no_child_visible?
     * @return array<string, mixed>
     */
    public function addMedia(Principal $principal, Perimeter $perimeter, string $entryId, array $data): array
    {
        $entry = $this->writableEntry($principal, $perimeter, $entryId);
        $this->expectDraft($entry);
        $content = base64_decode((string) $data['content_base64'], true);
        if ($content === false || $content === '') {
            throw HttpException::validation(['content_base64' => ['Image illisible (base64 attendu).']]);
        }
        $tagged = $this->taggedChildren($perimeter, (string) $entry['structure_id'], (array) ($data['children'] ?? []));
        $noChild = ($data['no_child_visible'] ?? false) === true;
        if ($noChild && $tagged !== []) {
            throw HttpException::validation(['no_child_visible' => ['Une photo « sans enfant visible » ne peut pas désigner d’enfant.']]);
        }
        try {
            $ref = $this->media->put($perimeter->tenantId, self::MEDIA_NAMESPACE, $entryId, $content);
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation(['content_base64' => [$e->getMessage()]]);
        }
        $id = (string) new Ulid();
        $this->store->insert(self::MEDIA, $perimeter->tenantId, $perimeter->establishmentId, [
            'id' => $id, 'entry_id' => $entryId, 'media_ref' => $ref->value, 'mime_type' => $this->mime($ref), 'size_bytes' => strlen($content),
            'no_child_visible' => $noChild ? 1 : 0, 'status' => $noChild || $tagged !== [] ? 'ready' : 'pending_tagging', 'created_at' => $this->clock->now(),
        ]);
        $this->setChildren($perimeter, $id, $tagged);
        $this->access->audit($principal, 'nursery.lifebook.media_added', 'lifebook_media', $id, null, ['entry_id' => $entryId, 'children' => count($tagged)]);

        return $this->staffResource($this->entry($perimeter, $entryId, true));
    }

    /**
     * Étiquette un média (qui figure sur la photo) ; remplace l'étiquetage précédent.
     *
     * @param array<mixed> $children
     * @return array<string, mixed>
     */
    public function tag(Principal $principal, Perimeter $perimeter, string $mediaId, array $children, bool $noChildVisible): array
    {
        $media = $this->store->find(self::MEDIA, $perimeter->tenantId, $perimeter->establishmentId, $mediaId) ?? throw HttpException::notFound('Média introuvable.');
        $entry = $this->writableEntry($principal, $perimeter, (string) $media['entry_id']);
        $this->expectDraft($entry);
        $tagged = $this->taggedChildren($perimeter, (string) $entry['structure_id'], $children);
        if ($noChildVisible && $tagged !== []) {
            throw HttpException::validation(['no_child_visible' => ['Une photo « sans enfant visible » ne peut pas désigner d’enfant.']]);
        }
        $this->setChildren($perimeter, $mediaId, $tagged);
        $this->store->update(self::MEDIA, $perimeter->tenantId, $perimeter->establishmentId, ['no_child_visible' => $noChildVisible ? 1 : 0, 'status' => $noChildVisible || $tagged !== [] ? 'ready' : 'pending_tagging'], ['id' => $mediaId]);

        return $this->staffResource($this->entry($perimeter, (string) $entry['id'], true));
    }

    /** @return array<string, mixed> */
    public function publish(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $entry = $this->writableEntry($principal, $perimeter, $id);
        $this->expectDraft($entry);
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        foreach ($this->store->select(self::MEDIA, $tenantId, $establishmentId, ['entry_id' => $id], 'created_at, id', 200) as $media) {
            $this->store->update(self::MEDIA, $tenantId, $establishmentId, ['status' => (string) $media['status'] === 'ready' ? 'published' : 'withheld'], ['id' => (string) $media['id']]);
        }
        $now = $this->clock->now();
        $this->store->update(self::ENTRIES, $tenantId, $establishmentId, ['status' => 'published', 'published_at' => $now, 'updated_at' => $now], ['id' => $id]);
        $this->access->audit($principal, 'nursery.lifebook.published', 'lifebook_entry', $id);
        $label = (string) $this->sections->findRaw($tenantId, $establishmentId, (string) $entry['structure_id'])['label'];
        $notified = [];
        foreach ($this->sections->childIds($tenantId, (string) $entry['structure_id']) as $childId) {
            foreach ($this->people->guarantorsOf($tenantId, $childId) as $guardian) {
                if (isset($notified[$guardian->id])) {
                    continue;
                }
                $notified[$guardian->id] = true;
                $this->messenger->send($tenantId, $establishmentId, $guardian->id, self::TEMPLATE_PUBLISHED, ['class_name' => $label, 'date' => (string) $entry['activity_on'], 'link' => ProgressBookService::PORTAL_LINK]);
            }
        }

        return $this->staffResource($this->entry($perimeter, $id, true));
    }

    /** @return array<string, mixed> */
    public function withdraw(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $entry = $this->writableEntry($principal, $perimeter, $id);
        if ((string) $entry['status'] !== 'published') {
            throw HttpException::conflict('Seule une publication en ligne se retire.');
        }
        $this->store->update(self::ENTRIES, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'withdrawn', 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.lifebook.withdrawn', 'lifebook_entry', $id);

        return $this->staffResource($this->entry($perimeter, $id, true));
    }

    /**
     * Publications du périmètre (personnel) : tous les médias, quel que soit le consentement.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function staffPage(Perimeter $perimeter, Paging $paging, ?string $sectionId): array
    {
        if ($sectionId !== null) {
            $this->sections->get($perimeter, $sectionId);
        }
        $rows = [];
        foreach ($this->store->select(self::ENTRIES, $perimeter->tenantId, $perimeter->establishmentId, $sectionId !== null ? ['structure_id' => $sectionId] : [], 'activity_on DESC, id', 1000) as $entry) {
            if ($perimeter->coversSection((string) $entry['structure_id'])) {
                $rows[] = $this->staffResource($entry);
            }
        }

        return $paging->slice($rows);
    }

    /** Octets d'un média pour le personnel de la section (étiquetage) ; chaque lecture est journalisée, une photo purgée n'existe plus. */
    public function staffMedia(Principal $principal, Perimeter $perimeter, string $mediaId): MediaContent
    {
        $media = $this->store->find(self::MEDIA, $perimeter->tenantId, $perimeter->establishmentId, $mediaId) ?? throw HttpException::notFound('Média introuvable.');
        $this->entry($perimeter, (string) $media['entry_id'], true);
        if ((string) $media['status'] === 'purged') {
            throw HttpException::notFound('Média introuvable.');
        }
        $this->access->audit($principal, 'nursery.lifebook.media_read', 'lifebook_media', $mediaId, null, ['reader' => 'staff']);

        return $this->bytes($perimeter->tenantId, (string) $media['media_ref']);
    }

    /**
     * Publications d'une section vues par un PARENT : texte toujours, médias seulement si chaque enfant visible a un consentement photo en vigueur.
     *
     * @return list<array<string, mixed>>
     */
    public function parentEntries(string $tenantId, string $establishmentId, string $sectionId): array
    {
        $out = [];
        foreach ($this->store->select(self::ENTRIES, $tenantId, $establishmentId, ['structure_id' => $sectionId, 'status' => 'published'], 'activity_on DESC, id', 60) as $entry) {
            $media = [];
            $withheld = 0;
            foreach ($this->store->select(self::MEDIA, $tenantId, $establishmentId, ['entry_id' => (string) $entry['id'], 'status' => 'published'], 'created_at, id', 100) as $row) {
                if ($this->servable($tenantId, $establishmentId, $row)) {
                    $media[] = ['id' => (string) $row['id'], 'mime_type' => (string) $row['mime_type']];
                } else {
                    ++$withheld;
                }
            }
            $out[] = [
                'id' => (string) $entry['id'], 'title' => (string) $entry['title'], 'body' => $entry['body'] !== null ? (string) $entry['body'] : null, 'activity_on' => (string) $entry['activity_on'],
                'media' => $media, 'withheld_media' => $withheld,
            ];
        }

        return $out;
    }

    /**
     * Octets d'un média pour un parent : 404 si la publication n'est pas en ligne, si elle n'est pas celle d'une section de ses enfants, ou
     * si l'un des enfants visibles n'a pas (ou plus) de consentement photo. Point de lecture UNIQUE des médias côté famille.
     *
     * @param list<string> $sectionIds sections de ses enfants
     */
    public function parentMedia(string $tenantId, string $establishmentId, array $sectionIds, string $mediaId, ?Principal $reader = null): MediaContent
    {
        $media = $this->store->find(self::MEDIA, $tenantId, $establishmentId, $mediaId) ?? throw HttpException::notFound('Média introuvable.');
        $entry = $this->store->find(self::ENTRIES, $tenantId, $establishmentId, (string) $media['entry_id']);
        if ($entry === null || (string) $entry['status'] !== 'published' || !in_array((string) $entry['structure_id'], $sectionIds, true)
            || (string) $media['status'] !== 'published' || !$this->servable($tenantId, $establishmentId, $media)) {
            throw HttpException::notFound('Média introuvable.');
        }
        if ($reader !== null) {
            $this->access->audit($reader, 'nursery.lifebook.media_read', 'lifebook_media', $mediaId, null, ['reader' => 'parent']);
        }

        return $this->bytes($tenantId, (string) $media['media_ref']);
    }

    /**
     * RG-MAT-6 : servable si aucun enfant n'est visible, ou si TOUS les enfants visibles ont un consentement photo actuellement en vigueur.
     *
     * @param array<string, mixed> $media
     */
    private function servable(string $tenantId, string $establishmentId, array $media): bool
    {
        if ((int) $media['no_child_visible'] === 1) {
            return true;
        }
        $visible = $this->store->select(self::MEDIA_CHILDREN, $tenantId, $establishmentId, ['media_id' => (string) $media['id']], 'id', 100);
        if ($visible === []) {
            return false; // jamais d'étiquetage : jamais servi
        }
        foreach ($visible as $row) {
            if (!$this->consents->hasConsent($tenantId, (string) $row['learner_id'], ConsentChecker::PHOTO)) {
                return false;
            }
        }

        return true;
    }

    private function bytes(string $tenantId, string $mediaRef): MediaContent
    {
        $ref = MediaRef::tryFrom($mediaRef) ?? throw HttpException::notFound('Média introuvable.');
        $url = $this->media->signedUrl($tenantId, $ref, 60, '/api/v1/nursery/lifebook/media');
        preg_match('/[?&]expires=(\d+)/', $url, $expires);
        preg_match('/[?&]signature=([^&]+)/', $url, $signature);

        return $this->media->readSigned($tenantId, $ref, (int) ($expires[1] ?? 0), rawurldecode($signature[1] ?? ''));
    }

    /**
     * @param array<mixed> $children
     * @return list<string>
     */
    private function taggedChildren(Perimeter $perimeter, string $sectionId, array $children): array
    {
        $ids = array_values(array_unique(array_map('strval', $children)));
        foreach ($ids as $childId) {
            if ($this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, $childId) !== $sectionId) {
                throw HttpException::validation(['children' => ['Un enfant désigné n’est pas inscrit dans cette section.']]);
            }
        }

        return $ids;
    }

    /** @param list<string> $children */
    private function setChildren(Perimeter $perimeter, string $mediaId, array $children): void
    {
        $this->store->delete(self::MEDIA_CHILDREN, $perimeter->tenantId, $perimeter->establishmentId, ['media_id' => $mediaId]);
        foreach ($children as $childId) {
            $this->store->insert(self::MEDIA_CHILDREN, $perimeter->tenantId, $perimeter->establishmentId, ['id' => (string) new Ulid(), 'media_id' => $mediaId, 'learner_id' => $childId]);
        }
    }

    /** @return array<string, mixed> 404 hors périmètre ou hors établissement */
    private function entry(Perimeter $perimeter, string $id, bool $checkScope): array
    {
        $entry = $this->store->find(self::ENTRIES, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Publication introuvable.');
        if ($checkScope) {
            $this->access->assertReadable($perimeter, (string) $entry['structure_id']);
        }

        return $entry;
    }

    /** @return array<string, mixed> */
    private function writableEntry(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $entry = $this->store->find(self::ENTRIES, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Publication introuvable.');
        $this->access->assertWritable($principal, $perimeter, (string) $entry['structure_id'], 'lifebook_entry', $id);

        return $entry;
    }

    /** @param array<string, mixed> $entry */
    private function expectDraft(array $entry): void
    {
        if ((string) $entry['status'] !== 'draft') {
            throw HttpException::conflict('Seul un brouillon se modifie : retirez la publication d’abord.');
        }
    }

    private function mime(MediaRef $ref): string
    {
        return match (strtolower(pathinfo($ref->segments()[2], PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'image/jpeg',
        };
    }

    /** @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function staffResource(array $entry): array
    {
        $media = [];
        foreach ($this->store->select(self::MEDIA, (string) $entry['tenant_id'], (string) $entry['establishment_id'], ['entry_id' => (string) $entry['id']], 'created_at, id', 200) as $row) {
            $children = array_map(static fn (array $c): string => (string) $c['learner_id'], $this->store->select(self::MEDIA_CHILDREN, (string) $entry['tenant_id'], (string) $entry['establishment_id'], ['media_id' => (string) $row['id']], 'id', 100));
            $media[] = ['id' => (string) $row['id'], 'mime_type' => (string) $row['mime_type'], 'status' => (string) $row['status'], 'no_child_visible' => (int) $row['no_child_visible'] === 1, 'children' => $children];
        }

        return [
            'id' => (string) $entry['id'], 'section_id' => (string) $entry['structure_id'], 'title' => (string) $entry['title'], 'body' => $entry['body'] !== null ? (string) $entry['body'] : null,
            'activity_on' => (string) $entry['activity_on'], 'status' => (string) $entry['status'], 'published_at' => $entry['published_at'], 'media' => $media,
        ];
    }
}
