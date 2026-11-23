<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Abandon scolaire (EX-ELV-06) : déclaré par le directeur ou présumé après une absence continue de `public_schools.dropout.days`
 * jours ouvrés (défaut 20, à confirmer Q11), suivi de la relance de la famille puis du résultat. Aucune sanction automatique et
 * aucune radiation : l'abandon ne ferme pas l'inscription.
 */
final class DropoutService
{
    public const KIND_DECLARED = 'declared';
    public const KIND_PRESUMED = 'presumed';
    public const FOLLOWUP_TEMPLATE = 'public_dropout_followup';

    private const DEFAULT_DAYS = 20;
    /** Jours non ouvrés (samedi et dimanche, ISO 8601). */
    private const WEEKEND = ['6', '7'];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnrollmentDirectory $enrollments,
        private readonly DailyPresence $presence,
        private readonly SettingsReader $settings,
        private readonly PupilClock $clock,
        private readonly GuardianNotifier $notifier,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws HttpException 404 élève inconnu de l'école, 409 abandon déjà ouvert
     */
    public function declareDropout(string $tenantId, string $establishmentId, string $personId, ?string $note, string $actorId): array
    {
        if (!in_array($personId, $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId), true)) {
            throw HttpException::notFound('Élève introuvable dans cette école.');
        }
        if ($note !== null && mb_strlen($note) > 255) {
            throw HttpException::validation(['note' => ['Note de 255 caractères au plus.']]);
        }

        return $this->open($tenantId, $establishmentId, $personId, self::KIND_DECLARED, $this->clock->today(), $actorId, $note) ?? throw HttpException::conflict('Un abandon est déjà ouvert pour cet élève.');
    }

    /** @return array<string, mixed> */
    public function contact(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->require($tenantId, $establishmentId, $id);
        if ($row['outcome'] !== null) {
            throw HttpException::conflict('Ce dossier d\'abandon est déjà résolu.');
        }
        $this->connections->pdo()->prepare('UPDATE pub_dropouts SET family_contacted_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute([$this->clock->now(), $tenantId, $establishmentId, $id]);

        return $this->require($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function resolve(string $tenantId, string $establishmentId, string $id, string $outcome): array
    {
        if (preg_match('/^[a-z][a-z_]{0,15}$/', $outcome) !== 1) {
            throw HttpException::validation(['outcome' => ['Résultat attendu : code en minuscules de 16 caractères au plus (ex. returned, transferred, confirmed).']]);
        }
        $row = $this->require($tenantId, $establishmentId, $id);
        if ($row['outcome'] !== null) {
            throw HttpException::conflict('Ce dossier d\'abandon est déjà résolu.');
        }
        $this->connections->pdo()->prepare('UPDATE pub_dropouts SET outcome = ?, resolved_on = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute([$outcome, $this->clock->today(), $tenantId, $establishmentId, $id]);

        return $this->require($tenantId, $establishmentId, $id);
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId, bool $openOnly): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM pub_dropouts WHERE tenant_id = ? AND establishment_id = ?' . ($openOnly ? ' AND outcome IS NULL' : '') . ' ORDER BY detected_on DESC, id DESC',
        );
        $query->execute([$tenantId, $establishmentId]);

        return array_values(array_map(static fn (array $row): array => self::present($row), $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /**
     * Abandons présumés d'une école : élèves absents à tous les appels validés des derniers jours ouvrés (samedi et dimanche exclus ;
     * un jour sans appel validé interrompt la série). Ouvre le dossier, relance les garants et prévient le directeur.
     *
     * @return list<string> identifiants des élèves dont un abandon présumé vient d'être ouvert
     */
    public function detectPresumed(string $tenantId, string $establishmentId, ?DateTimeImmutable $today = null): array
    {
        $configured = $this->settings->value($tenantId, $establishmentId, 'public_schools.dropout.days', self::DEFAULT_DAYS);
        $days = is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_DAYS;
        $candidates = null;
        $day = $today ?? $this->clock->moment();
        $counted = 0;
        for ($guard = 0; $counted < $days && $guard < $days * 3; ++$guard) {
            if (!in_array($day->format('N'), self::WEEKEND, true)) {
                $absent = $this->presence->absentOn($tenantId, $establishmentId, $day->format('Y-m-d'));
                $candidates = $candidates === null ? $absent : array_values(array_intersect($candidates, $absent));
                if ($candidates === []) {
                    return [];
                }
                ++$counted;
            }
            $day = $day->sub(new DateInterval('P1D'));
        }
        if ($counted < $days || $candidates === null) {
            return [];
        }

        $opened = [];
        $enrolled = $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId);
        foreach ($candidates as $personId) {
            if (!in_array($personId, $enrolled, true)) {
                continue;
            }
            $row = $this->open($tenantId, $establishmentId, $personId, self::KIND_PRESUMED, ($today ?? $this->clock->moment())->format('Y-m-d'), null, null);
            if ($row === null) {
                continue;
            }
            $opened[] = $personId;
            $this->followUp($tenantId, $establishmentId, $row);
        }

        return $opened;
    }

    /**
     * @return array<string, mixed>|null null si un abandon est déjà ouvert pour l'élève
     */
    private function open(string $tenantId, string $establishmentId, string $personId, string $kind, string $detectedOn, ?string $actorId, ?string $note): ?array
    {
        $existing = $this->connections->pdo()->prepare(
            'SELECT 1 FROM pub_dropouts WHERE tenant_id = ? AND establishment_id = ? AND person_id = ? AND outcome IS NULL LIMIT 1',
        );
        $existing->execute([$tenantId, $establishmentId, $personId]);
        if ($existing->fetchColumn() !== false) {
            return null;
        }
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare(
            'INSERT INTO pub_dropouts (id, tenant_id, establishment_id, person_id, kind, detected_on, family_contacted_at, outcome, declared_by, note, resolved_on) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, NULL)',
        )->execute([$id, $tenantId, $establishmentId, $personId, $kind, $detectedOn, $actorId, $note]);

        return $this->require($tenantId, $establishmentId, $id);
    }

    /** @param array<string, mixed> $row */
    private function followUp(string $tenantId, string $establishmentId, array $row): void
    {
        $gateway = $this->notifier->gateway();
        if ($gateway === null) {
            return;
        }
        $template = $this->settings->value($tenantId, $establishmentId, 'public_schools.messages.dropout_followup', self::FOLLOWUP_TEMPLATE);
        $template = is_string($template) ? $template : self::FOLLOWUP_TEMPLATE;
        try {
            $reached = $gateway->notifyGuardians($tenantId, $establishmentId, (string) $row['person_id'], $template, ['since' => (string) $row['detected_on']]);
            $gateway->notifyDirector($tenantId, $establishmentId, $template, ['since' => (string) $row['detected_on']]);
            if ($reached > 0) {
                $this->connections->pdo()->prepare('UPDATE pub_dropouts SET family_contacted_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
                    ->execute([$this->clock->now(), $tenantId, $establishmentId, $row['id']]);
            }
        } catch (\Throwable) {
            // La relance échouée reste visible : family_contacted_at est vide, le directeur peut contacter la famille lui-même.
        }
    }

    /** @return array<string, mixed> */
    private function require(string $tenantId, string $establishmentId, string $id): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_dropouts WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::present($row) : throw HttpException::notFound('Dossier d\'abandon introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'person_id' => (string) $row['person_id'],
            'kind' => (string) $row['kind'],
            'detected_on' => (string) $row['detected_on'],
            'family_contacted_at' => $row['family_contacted_at'],
            'outcome' => $row['outcome'],
            'resolved_on' => $row['resolved_on'],
            'declared_by' => $row['declared_by'],
            'note' => $row['note'],
        ];
    }
}
