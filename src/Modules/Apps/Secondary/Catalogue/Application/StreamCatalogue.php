<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Catalogue\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;
use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamInfo;
use Scholaris\Modules\Apps\Secondary\Catalogue\Infrastructure\PdoStreamCatalogue;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;

/**
 * Table de référence des séries et spécialités (catalogue-series-minesec.md §6 et §7, EX-SEC-06, Q22) :
 *  - la plateforme tient la liste (amorcée avec le catalogue MINESEC « à confirmer »), ajoute une ligne (source obligatoire),
 *    la renomme, la retire ou la remet, la marque confirmée ; chaque changement incrémente sa version et laisse une trace ;
 *  - un établissement voit les lignes de la plateforme non retirées et les siennes, filtrées par les cycles et enseignements de
 *    SON profil ; il ajoute une ligne propre (source obligatoire), masque ou renomme une ligne POUR LUI SEUL, avec motif et trace.
 * Rien n'est jamais supprimé. Aucune règle propre à un type : seul le profil (cycles, enseignements) filtre.
 */
final class StreamCatalogue implements StreamDirectory
{
    public const ORIGIN_MINESEC = 'minesec';
    public const ORIGIN_PLATFORM = 'platform';
    public const ORIGIN_ESTABLISHMENT = 'establishment';
    public const CONFIRMATIONS = ['to_confirm', 'confirmed', 'custom'];
    public const TRACKS = ['GEN', 'STT', 'IND'];
    public const SCOPE_PLATFORM = 'platform';
    public const SCOPE_ESTABLISHMENT = 'establishment';

    public function __construct(
        private readonly PdoStreamCatalogue $rows,
        private readonly EstablishmentProfiles $profiles,
        private readonly SecondaryClock $clock,
    ) {
    }

    public function forEstablishment(string $tenantId, string $establishmentId): array
    {
        $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        $rows = $this->rows->establishmentRows($tenantId, $establishmentId, [], self::within($profile), false, [], 2000, 0);
        $streams = [];
        foreach ($rows as $row) {
            $streams[] = self::info($row);
        }

        return $streams;
    }

    public function find(string $tenantId, string $establishmentId, string $id): ?StreamInfo
    {
        $row = $this->rows->findForEstablishment($tenantId, $establishmentId, $id);

        return $row === null ? null : self::info($row);
    }

    /**
     * Page de la vue d'un établissement. `profileOnly` : seulement les cycles et enseignements du profil (défaut) ; une dimension
     * vide du profil (type « autre » pas encore réglé) ne filtre pas.
     *
     * @param array<string, string> $filters
     * @param list<array{0: string, 1: string}> $sorts
     * @return list<array<string, mixed>>
     */
    public function establishmentPage(string $tenantId, string $establishmentId, array $filters, array $sorts, bool $profileOnly, bool $includeHidden, int $limit, int $offset): array
    {
        $within = $profileOnly ? self::within($this->profiles->forEstablishment($tenantId, $establishmentId)) : [];
        $resources = [];
        foreach ($this->rows->establishmentRows($tenantId, $establishmentId, $filters, $within, $includeHidden, $sorts, $limit, $offset) as $row) {
            $resources[] = self::resource($row);
        }

        return $resources;
    }

    /** @return array<string, mixed> ligne vue par l'établissement, avec son historique (plateforme et établissement) */
    public function establishmentShow(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->rows->findForEstablishment($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Série ou spécialité introuvable.');

        return self::resource($row) + ['history' => $this->history($id, $tenantId, $establishmentId)];
    }

    /**
     * Ajout propre à un établissement (origine `establishment`, source obligatoire) ; le cycle doit être offert par le profil.
     *
     * @param array<string, mixed> $values validées par le contrôleur
     * @return array<string, mixed>
     */
    public function addForEstablishment(string $tenantId, string $establishmentId, array $values, string $by): array
    {
        $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        $errors = [];
        $cycle = (string) $values['cycle_code'];
        if ($profile->cycles !== [] && !$profile->offersCycle($cycle)) {
            $errors['cycle_code'] = ['Ce cycle n\'est pas offert par le profil de l\'établissement.'];
        }
        $section = (string) ($values['section_code'] ?? 'fr');
        if ($this->rows->keyExists(PdoStreamCatalogue::PLATFORM, PdoStreamCatalogue::PLATFORM, $section, (string) $values['code'], $cycle)) {
            $errors['code'] = ['Ce code existe déjà pour ce cycle dans la liste de la plateforme : masquez-le ou renommez-le plutôt.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->create($tenantId, $establishmentId, $values, self::ORIGIN_ESTABLISHMENT, $by);

        return $this->establishmentShow($tenantId, $establishmentId, $id);
    }

    /**
     * Masquer, afficher ou renommer pour CET établissement. Ligne de la plateforme : surcharge propre à l'établissement ; ligne
     * propre : modification directe. Motif obligatoire, une trace par changement.
     *
     * @param array<string, mixed> $values label_fr, label_en, hidden, reason (validées)
     * @return array<string, mixed>
     */
    public function changeForEstablishment(string $tenantId, string $establishmentId, string $id, array $values, string $by): array
    {
        $row = $this->rows->findForEstablishment($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Série ou spécialité introuvable.');
        $reason = (string) $values['reason'];
        $this->rows->transaction(function () use ($row, $tenantId, $establishmentId, $id, $values, $reason, $by): void {
            if ($row['tenant_id'] === PdoStreamCatalogue::PLATFORM) {
                $this->changeOverride($row, $tenantId, $establishmentId, $values, $reason, $by);

                return;
            }
            $this->changeRow($row, $id, $tenantId, $establishmentId, $values, $reason, $by);
        });

        return $this->establishmentShow($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, string> $filters
     * @param list<array{0: string, 1: string}> $sorts
     * @return list<array<string, mixed>>
     */
    public function platformPage(array $filters, array $sorts, int $limit, int $offset): array
    {
        $resources = [];
        foreach ($this->rows->platformRows($filters, $sorts, $limit, $offset) as $row) {
            $resources[] = self::resource($row);
        }

        return $resources;
    }

    /** @return array<string, mixed> */
    public function platformShow(string $id): array
    {
        $row = $this->rows->findPlatform($id) ?? throw HttpException::notFound('Série ou spécialité introuvable.');

        return self::resource($row) + ['history' => $this->history($id, PdoStreamCatalogue::PLATFORM, PdoStreamCatalogue::PLATFORM)];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function addForPlatform(array $values, string $by): array
    {
        $section = (string) ($values['section_code'] ?? 'fr');
        if ($this->rows->keyExists(PdoStreamCatalogue::PLATFORM, PdoStreamCatalogue::PLATFORM, $section, (string) $values['code'], (string) $values['cycle_code'])) {
            throw HttpException::validation(['code' => ['Ce code existe déjà pour ce cycle dans la liste de la plateforme.']]);
        }
        $id = $this->create(PdoStreamCatalogue::PLATFORM, PdoStreamCatalogue::PLATFORM, $values, self::ORIGIN_PLATFORM, $by);

        return $this->platformShow($id);
    }

    /**
     * Renommer, retirer ou remettre, marquer confirmée une ligne de la plateforme (version incrémentée, motif et trace).
     *
     * @param array<string, mixed> $values label_fr, label_en, hidden, confirmation, reason
     * @return array<string, mixed>
     */
    public function changeForPlatform(string $id, array $values, string $by): array
    {
        $row = $this->rows->findPlatform($id) ?? throw HttpException::notFound('Série ou spécialité introuvable.');
        $this->rows->transaction(function () use ($row, $id, $values, $by): void {
            $this->changeRow($row, $id, PdoStreamCatalogue::PLATFORM, PdoStreamCatalogue::PLATFORM, $values, (string) $values['reason'], $by);
        });

        return $this->platformShow($id);
    }

    /** @param array<string, mixed> $values */
    private function create(string $tenantId, string $establishmentId, array $values, string $origin, string $by): string
    {
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $teaching = (string) $values['teaching_kind'];
        $row = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'section_code' => (string) ($values['section_code'] ?? 'fr'),
            'code' => (string) $values['code'],
            'cycle_code' => (string) $values['cycle_code'],
            'stream_kind' => $teaching === Cycles::TEACHING_GENERAL ? 'series' : 'specialty',
            'teaching_kind' => $teaching,
            'track_code' => (string) ($values['track_code'] ?? ($teaching === Cycles::TEACHING_GENERAL ? 'GEN' : '')),
            'field_label' => isset($values['field_label']) ? (string) $values['field_label'] : null,
            'label_fr' => (string) $values['label_fr'],
            'label_en' => isset($values['label_en']) ? (string) $values['label_en'] : null,
            'origin' => $origin,
            'source_ref' => (string) $values['source_ref'],
            'confirmation' => 'custom',
            'hidden' => 0,
            'version_no' => 1,
            'created_by' => $by,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($row['track_code'] === '') {
            throw HttpException::validation(['track_code' => ['Filière obligatoire pour l\'enseignement technique (STT ou IND).']]);
        }
        $this->rows->transaction(function () use ($row, $id, $tenantId, $establishmentId, $by, $now): void {
            $this->rows->insert($row);
            $this->log($id, $tenantId, $establishmentId, 'created', null, ['code' => $row['code'], 'cycle_code' => $row['cycle_code'], 'label_fr' => $row['label_fr'], 'source_ref' => $row['source_ref']], null, $by, $now);
        });

        return $id;
    }

    /**
     * Surcharge d'un établissement sur une ligne de la plateforme.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $values
     */
    private function changeOverride(array $row, string $tenantId, string $establishmentId, array $values, string $reason, string $by): void
    {
        $refId = (string) $row['id'];
        $now = $this->clock->now();
        $override = $this->rows->override($tenantId, $establishmentId, $refId);
        $current = [
            'hidden' => (int) ($override['hidden'] ?? 0) === 1,
            'label_fr' => $override['label_fr'] ?? null,
            'label_en' => $override['label_en'] ?? null,
        ];
        $next = $current;
        if (array_key_exists('hidden', $values)) {
            $next['hidden'] = (bool) $values['hidden'];
        }
        foreach (['label_fr', 'label_en'] as $field) {
            if (array_key_exists($field, $values)) {
                $next[$field] = $values[$field];
            }
        }
        if ($next === $current) {
            return;
        }
        $version = (int) ($override['version_no'] ?? 0) + 1;
        $data = ['hidden' => $next['hidden'] ? 1 : 0, 'label_fr' => $next['label_fr'], 'label_en' => $next['label_en'], 'version_no' => $version, 'updated_at' => $now];
        if ($override === null) {
            $this->rows->insertOverride(['id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'stream_ref_id' => $refId] + $data + ['created_at' => $now]);
        } else {
            $this->rows->updateOverride((string) $override['id'], $tenantId, $establishmentId, $data);
        }
        $this->logDiff($refId, $tenantId, $establishmentId, $current, $next, $reason, $by, $now);
    }

    /**
     * Modification directe d'une ligne (plateforme par l'administrateur de plateforme, ou ligne propre de l'établissement).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $values
     */
    private function changeRow(array $row, string $id, string $tenantId, string $establishmentId, array $values, string $reason, string $by): void
    {
        $now = $this->clock->now();
        $current = ['hidden' => (int) $row['hidden'] === 1, 'label_fr' => $row['label_fr'], 'label_en' => $row['label_en'], 'confirmation' => $row['confirmation']];
        $next = $current;
        if (array_key_exists('hidden', $values)) {
            $next['hidden'] = (bool) $values['hidden'];
        }
        foreach (['label_fr', 'label_en', 'confirmation'] as $field) {
            if (array_key_exists($field, $values)) {
                $next[$field] = $values[$field];
            }
        }
        if (!is_string($next['label_fr']) || trim($next['label_fr']) === '') {
            throw HttpException::validation(['label_fr' => ['Le libellé français d\'une ligne ne peut pas être vidé.']]);
        }
        if ($next === $current) {
            return;
        }
        $this->rows->update($id, $tenantId, $establishmentId, [
            'hidden' => $next['hidden'] ? 1 : 0, 'label_fr' => $next['label_fr'], 'label_en' => $next['label_en'], 'confirmation' => $next['confirmation'],
            'version_no' => (int) $row['version_no'] + 1, 'updated_at' => $now,
        ]);
        $this->logDiff($id, $tenantId, $establishmentId, $current, $next, $reason, $by, $now);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $next
     */
    private function logDiff(string $refId, string $tenantId, string $establishmentId, array $current, array $next, string $reason, string $by, string $now): void
    {
        if ($current['hidden'] !== $next['hidden']) {
            $this->log($refId, $tenantId, $establishmentId, $next['hidden'] ? 'hidden' : 'shown', ['hidden' => $current['hidden']], ['hidden' => $next['hidden']], $reason, $by, $now);
        }
        if ($current['label_fr'] !== $next['label_fr'] || $current['label_en'] !== $next['label_en']) {
            $this->log($refId, $tenantId, $establishmentId, 'renamed', ['label_fr' => $current['label_fr'], 'label_en' => $current['label_en']], ['label_fr' => $next['label_fr'], 'label_en' => $next['label_en']], $reason, $by, $now);
        }
        if (array_key_exists('confirmation', $current) && $current['confirmation'] !== $next['confirmation']) {
            $this->log($refId, $tenantId, $establishmentId, 'confirmation', ['confirmation' => $current['confirmation']], ['confirmation' => $next['confirmation']], $reason, $by, $now);
        }
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $new
     */
    private function log(string $refId, string $tenantId, string $establishmentId, string $kind, ?array $previous, array $new, ?string $reason, string $by, string $now): void
    {
        $this->rows->logChange([
            'id' => (string) new Ulid(),
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'stream_ref_id' => $refId,
            'change_kind' => $kind,
            'previous_value' => $previous === null ? null : json_encode($previous, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_value' => json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'changed_by' => $by,
            'created_at' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function history(string $refId, string $tenantId, string $establishmentId): array
    {
        $history = [];
        foreach ($this->rows->changes($refId, $tenantId, $establishmentId) as $change) {
            $history[] = [
                'scope' => $change['tenant_id'] === PdoStreamCatalogue::PLATFORM ? self::SCOPE_PLATFORM : self::SCOPE_ESTABLISHMENT,
                'change_kind' => $change['change_kind'],
                'previous_value' => is_string($change['previous_value']) ? json_decode($change['previous_value'], true) : null,
                'new_value' => json_decode((string) $change['new_value'], true),
                'reason' => $change['reason'],
                'changed_by' => $change['changed_by'],
                'created_at' => $change['created_at'],
            ];
        }

        return $history;
    }

    /** @return array<string, list<string>> filtre du profil : une dimension vide ne filtre pas */
    private static function within(EstablishmentProfile $profile): array
    {
        $within = [];
        if ($profile->cycles !== []) {
            $within['cycle_code'] = $profile->cycles;
        }
        if ($profile->teachingKinds !== []) {
            $within['teaching_kind'] = $profile->teachingKinds;
        }

        return $within;
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): StreamInfo
    {
        $resource = self::resource($row);

        return new StreamInfo(
            (string) $resource['id'],
            (string) $resource['scope'],
            (string) $resource['section_code'],
            (string) $resource['code'],
            (string) $resource['cycle_code'],
            (string) $resource['stream_kind'],
            (string) $resource['teaching_kind'],
            (string) $resource['track_code'],
            (string) $resource['label_fr'],
            (bool) $resource['hidden'],
            (string) $resource['confirmation'],
        );
    }

    /**
     * Ressource publique : libellé et masquage EFFECTIFS (surcharge de l'établissement appliquée), libellé de référence conservé.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        $overrideLabel = isset($row['override_label_fr']) && is_string($row['override_label_fr']) && $row['override_label_fr'] !== '' ? $row['override_label_fr'] : null;
        $overrideLabelEn = isset($row['override_label_en']) && is_string($row['override_label_en']) && $row['override_label_en'] !== '' ? $row['override_label_en'] : null;

        return [
            'id' => $row['id'],
            'scope' => $row['tenant_id'] === PdoStreamCatalogue::PLATFORM ? self::SCOPE_PLATFORM : self::SCOPE_ESTABLISHMENT,
            'section_code' => $row['section_code'],
            'code' => $row['code'],
            'cycle_code' => $row['cycle_code'],
            'stream_kind' => $row['stream_kind'],
            'teaching_kind' => $row['teaching_kind'],
            'track_code' => $row['track_code'],
            'field_label' => $row['field_label'],
            'label_fr' => $overrideLabel ?? $row['label_fr'],
            'label_en' => $overrideLabelEn ?? $row['label_en'],
            'reference_label_fr' => $row['label_fr'],
            'renamed' => $overrideLabel !== null || $overrideLabelEn !== null,
            'hidden' => (int) $row['hidden'] === 1 || (int) ($row['override_hidden'] ?? 0) === 1,
            'origin' => $row['origin'],
            'source_ref' => $row['source_ref'],
            'confirmation' => $row['confirmation'],
            'version_no' => (int) $row['version_no'] + (int) ($row['override_version'] ?? 0),
        ];
    }
}
