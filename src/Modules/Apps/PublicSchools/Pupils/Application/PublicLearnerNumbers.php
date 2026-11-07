<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumberProvider;

/**
 * Identifiant national de l'élève (EX-ELV-02, Q6), fourni au socle (`people.learner_number.providers.public_schools`). Gabarit en
 * paramètre `public_schools.learner_number.pattern` (défaut `{REGION}-{YEAR}-{SEQ}` : code de la région de l'école, année, séquence
 * annuelle par région sur `public_schools.learner_number.seq_width` chiffres, défaut 7). Attribué une seule fois par le socle.
 */
final class PublicLearnerNumbers implements LearnerNumberProvider
{
    private const DEFAULT_PATTERN = '{REGION}-{YEAR}-{SEQ}';
    private const DEFAULT_WIDTH = 7;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly SchoolRegistry $schools,
        private readonly TerritoryDirectory $territories,
        private readonly SettingsReader $settings,
        private readonly PupilClock $clock,
    ) {
    }

    public function supports(string $tenantId, string $establishmentId, string $personId): bool
    {
        return $this->schools->find($tenantId, $establishmentId) !== null;
    }

    public function next(string $tenantId, string $establishmentId, string $personId): string
    {
        $at = $this->clock->now();
        $region = $this->regionCode($tenantId, $establishmentId, $at);
        $year = substr($at, 0, 4);
        $sequence = $this->increment($tenantId, $region . '-' . $year);
        $pattern = $this->settings->value($tenantId, $establishmentId, 'public_schools.learner_number.pattern', self::DEFAULT_PATTERN);
        $pattern = is_string($pattern) && str_contains($pattern, '{SEQ}') ? $pattern : self::DEFAULT_PATTERN;
        $width = $this->settings->value($tenantId, $establishmentId, 'public_schools.learner_number.seq_width', self::DEFAULT_WIDTH);
        $width = is_int($width) && $width > 0 ? $width : self::DEFAULT_WIDTH;

        return strtr($pattern, ['{REGION}' => $region, '{YEAR}' => $year, '{SEQ}' => str_pad((string) $sequence, $width, '0', STR_PAD_LEFT)]);
    }

    private function regionCode(string $tenantId, string $establishmentId, string $at): string
    {
        $own = $this->territories->territoryOf($tenantId, $establishmentId, $at);
        if ($own !== null) {
            foreach ($this->territories->lineage($tenantId, $own->id, $at) as $node) {
                if ($node->type === 'region') {
                    return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $node->code));
                }
            }
        }

        throw HttpException::validation(['territory' => ['L\'école doit être rattachée à une région pour attribuer l\'identifiant national.']]);
    }

    private function increment(string $tenantId, string $scope): int
    {
        $pdo = $this->connections->pdo();
        if ($pdo->inTransaction()) {
            return $this->nextValue($tenantId, $scope);
        }
        $pdo->beginTransaction();
        try {
            $value = $this->nextValue($tenantId, $scope);
            $pdo->commit();
        } catch (\Throwable $exception) {
            try {
                $pdo->rollBack();
            } catch (PDOException) {
                // Transaction déjà terminée : l'erreur d'origine prime.
            }
            throw $exception;
        }

        return $value;
    }

    private function nextValue(string $tenantId, string $scope): int
    {
        $pdo = $this->connections->pdo();
        $update = $pdo->prepare('UPDATE pub_learner_sequences SET current_number = current_number + 1 WHERE tenant_id = ? AND scope_key = ?');
        $update->execute([$tenantId, $scope]);
        if ($update->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO pub_learner_sequences (id, tenant_id, scope_key, current_number) VALUES (?, ?, ?, 1)')
                    ->execute([(string) new Ulid(), $tenantId, $scope]);
            } catch (PDOException) {
                $update->execute([$tenantId, $scope]);
            }
        }
        $read = $pdo->prepare('SELECT current_number FROM pub_learner_sequences WHERE tenant_id = ? AND scope_key = ?');
        $read->execute([$tenantId, $scope]);

        return (int) $read->fetchColumn();
    }
}
