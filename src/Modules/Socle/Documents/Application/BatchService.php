<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use RuntimeException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Infrastructure\BatchRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\JobRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\TemplateRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\TypeRepository;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Génération en lot. `create()` enregistre le lot et ses lignes (titulaires bloqués pour impayé exclus, avec motif) ;
 * `process()` traite un morceau de lignes en attente : le worker `documents:process-jobs` l'appelle en boucle depuis
 * la file `document_jobs` (une tâche est mise en file à la création du lot). Chaque document a sa propre transaction : un échec n'arrête pas le lot.
 */
final class BatchService
{
    public const MAX_ITEMS = 2000;
    public const CHUNK = 50;
    public const EXCLUSION_REASON = 'Pension impayée : blocage financier paramétré pour les documents.';

    public function __construct(
        private readonly BatchRepository $batches,
        private readonly JobRepository $jobs,
        private readonly TypeRepository $types,
        private readonly TemplateRepository $templates,
        private readonly PersonDirectory $people,
        private readonly DebtorDirectory $debtors,
        private readonly DocumentService $documents,
        private readonly AuditLog $audit,
        private readonly DocumentClock $clock,
    ) {
    }

    /**
     * @param list<array{subject_person_id: string, data: array<string, scalar|null>}> $items
     * @return array<string, mixed> lot créé
     */
    public function create(string $tenantId, string $establishmentId, string $typeCode, string $locale, array $items, string $createdBy): array
    {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw HttpException::validation(['items' => ['Un lot contient de 1 à ' . self::MAX_ITEMS . ' titulaires.']]);
        }
        $type = $this->types->find($tenantId, $establishmentId, $typeCode)
            ?? throw HttpException::validation(['type' => ['Type de document inconnu : ' . $typeCode . '.']]);
        if ($this->templates->latestValidated($tenantId, $establishmentId, $typeCode, $locale) === null) {
            throw HttpException::validation(['type' => ['Aucun modèle validé pour ce type et cette langue.']]);
        }

        /** @var list<string> $declared */
        $declared = $type['variables'];
        $subjects = array_column($items, 'subject_person_id');
        if (count(array_unique($subjects)) !== count($subjects)) {
            throw HttpException::validation(['items' => ['Un titulaire figure plusieurs fois dans le lot.']]);
        }
        $known = array_map(static fn ($p): string => $p->id, $this->people->findMany($tenantId, $subjects));
        $missing = array_values(array_diff($subjects, $known));
        if ($missing !== []) {
            throw HttpException::validation(['items' => ['Personnes introuvables : ' . implode(', ', $missing) . '.']]);
        }
        foreach ($items as $item) {
            $unknown = array_diff(array_keys($item['data']), $declared);
            if ($unknown !== []) {
                throw HttpException::validation(['items' => ['Variables non déclarées : ' . implode(', ', $unknown) . '.']]);
            }
        }

        $id = (string) new Ulid();
        $excluded = 0;
        $rows = [];
        foreach ($items as $item) {
            $blocked = $type['blockable'] === true && $this->debtors->isBlocked($tenantId, $establishmentId, $item['subject_person_id'], 'documents');
            $excluded += $blocked ? 1 : 0;
            $rows[] = [
                'id' => (string) new Ulid(),
                'tenant_id' => $tenantId,
                'batch_id' => $id,
                'subject_person_id' => $item['subject_person_id'],
                'data_json' => json_encode($item['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => $blocked ? 'excluded' : 'pending',
                'reason' => $blocked ? self::EXCLUSION_REASON : null,
            ];
        }
        $total = count($items) - $excluded;

        $this->batches->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'type_code' => $typeCode,
            'locale' => $locale,
            'status' => $total === 0 ? 'done' : 'pending',
            'total' => $total,
            'processed' => 0,
            'failed' => 0,
            'excluded' => $excluded,
            'created_by' => $createdBy,
            'created_at' => $this->clock->now(),
            'finished_at' => $total === 0 ? $this->clock->now() : null,
        ]);
        foreach ($rows as $row) {
            $this->batches->insertItem($row);
        }
        if ($total > 0) {
            $this->jobs->enqueue($tenantId, $establishmentId, $id, $this->clock->now());
        }
        $this->audit->append($tenantId, $createdBy, 'document_batch.created', 'document_batch', $id, null, ['type' => $typeCode, 'total' => $total, 'excluded' => $excluded]);

        return $this->batches->find($tenantId, $id) ?? [];
    }

    /**
     * Traite au plus `$limit` lignes en attente et renvoie le lot à jour (progression traitées / total).
     *
     * @return array<string, mixed>
     */
    public function process(string $tenantId, string $batchId, int $limit = self::CHUNK): array
    {
        $batch = $this->batches->find($tenantId, $batchId) ?? throw HttpException::notFound('Lot introuvable.');
        if ($batch['status'] === 'done') {
            return $batch;
        }

        $done = 0;
        $failed = 0;
        foreach ($this->batches->itemsByStatus($tenantId, $batchId, 'pending', $limit) as $item) {
            try {
                /** @var array<string, scalar|null> $data */
                $data = (array) json_decode((string) $item['data_json'], true);
                $this->documents->issueVia(
                    $tenantId,
                    (string) $batch['establishment_id'],
                    (string) $batch['type_code'],
                    (string) $item['subject_person_id'],
                    $data,
                    (string) $batch['created_by'],
                    (string) $batch['locale'],
                    'batch',
                    function (string $documentId) use ($tenantId, $item): void {
                        if ($this->batches->finishItem($tenantId, (string) $item['id'], 'done', null, $documentId) === 0) {
                            throw new RuntimeException('Ligne déjà traitée par un autre worker.');
                        }
                    },
                );
                $done++;
            } catch (Throwable $e) {
                $reason = mb_substr($e instanceof HttpException ? $this->describe($e) : 'Erreur interne lors de la génération.', 0, 255);
                if ($this->batches->finishItem($tenantId, (string) $item['id'], 'failed', $reason, null) === 1) {
                    $failed++;
                }
            }
        }

        $finished = $this->batches->pendingCount($tenantId, $batchId) === 0;
        $this->batches->progress($tenantId, $batchId, $done + $failed, $failed, $finished ? 'done' : 'processing', $finished ? $this->clock->now() : null);
        if ($finished) {
            $this->audit->append($tenantId, (string) $batch['created_by'], 'document_batch.finished', 'document_batch', $batchId, null, ['failed' => (int) $batch['failed'] + $failed]);
        }

        return $this->batches->find($tenantId, $batchId) ?? [];
    }

    private function describe(HttpException $e): string
    {
        $details = [];
        foreach ($e->errors as $messages) {
            $details = array_merge($details, $messages);
        }

        return $details === [] ? $e->getMessage() : implode(' ', $details);
    }
}
