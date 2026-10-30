<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\AnnualMentions;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Transfert vers le secondaire (EX-EXA-07, Should ; Q18 : l'application Secondaire n'existe pas encore pour le pilote) : dossier JSON
 * signé (HMAC-SHA256, clé du serveur `sync.secret_key` ou APP_KEY, dérivée pour cet usage, jamais dans le dépôt), importable plus tard.
 * Contenu minimal : identité, identifiant national, école, résultat de l'examen, mentions annuelles par discipline (parcours) ;
 * aucune donnée APEE (RG-ELV-1), aucune situation particulière, aucune donnée de santé.
 */
final class SecondaryTransferService
{
    public const FORMAT = 'scholaris.public_primary.secondary_transfer.v1';

    public function __construct(
        private readonly PdoExams $repository,
        private readonly ExamAccess $access,
        private readonly ExamSupport $support,
        private readonly SchoolRegistry $schools,
        private readonly AnnualMentions $mentions,
        private readonly Config $config,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input target_label? (collège d'accueil, texte libre)
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, string $candidateId, array $input): array
    {
        $this->access->director($principal, $establishmentId, 'pp_exam_candidate', $candidateId);
        $errors = [];
        $target = Input::text($input['target_label'] ?? null, 160, 'target_label', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $tenantId = $principal->tenantId;
        $row = $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? throw HttpException::notFound();
        if ($row['candidate_state'] === 'withdrawn') {
            throw HttpException::conflict('Candidat retiré.');
        }
        $session = $this->support->session($tenantId, (string) $row['session_id']);
        $candidate = $this->support->candidate($tenantId, $row);
        $school = $this->schools->find($tenantId, $establishmentId);
        $subsystem = $school?->subsystem === 'anglophone' ? 'anglophone' : 'francophone';
        $mentions = $row['level_code'] === null ? [] : $this->mentions->annual($tenantId, $establishmentId, (string) $row['person_id'], (string) $session['year_label'], (string) $row['level_code'], $subsystem);
        $dossier = [
            'format' => self::FORMAT, 'issued_at' => $this->clock->now(), 'target_label' => $target,
            'school' => ['establishment_id' => $establishmentId, 'official_code' => $school?->officialCode, 'subsystem' => $school?->subsystem],
            'pupil' => [
                'person_id' => (string) $row['person_id'], 'learner_number' => $candidate['learner_number'], 'last_name' => $candidate['last_name'],
                'first_name' => $candidate['first_name'], 'sex' => $candidate['sex'], 'birth_date' => $candidate['birth_date'],
            ],
            'exam' => [
                'code' => (string) $session['exam_code'], 'year_label' => (string) $session['year_label'], 'candidate_number' => $row['candidate_number'],
                'outcome' => $row['result_published_at'] !== null ? $row['outcome'] : null,
            ],
            'annual_mentions' => array_map(static fn (array $line): array => ['discipline_code' => $line['discipline_code'], 'label_fr' => $line['label_fr'], 'label_en' => $line['label_en'], 'mention' => $line['mention']], $mentions),
        ];
        $json = json_encode($dossier, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $json, $this->key());
        $id = $this->repository->insert('pp_exam_transfers', [
            'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'candidate_id' => $candidateId, 'person_id' => (string) $row['person_id'],
            'dossier' => $json, 'signature' => $signature, 'target_label' => $target, 'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.secondary_transfer', 'pp_exam_candidate', $candidateId, null, ['transfer_id' => $id]);

        return self::resource($this->repository->latestTransfer($tenantId, $candidateId) ?? throw HttpException::notFound());
    }

    /** @return array<string, mixed> */
    public function latest(Principal $principal, string $establishmentId, string $candidateId): array
    {
        $this->access->director($principal, $establishmentId, 'pp_exam_candidate', $candidateId);
        $this->repository->candidate($principal->tenantId, $establishmentId, $candidateId) ?? throw HttpException::notFound();

        return self::resource($this->repository->latestTransfer($principal->tenantId, $candidateId) ?? throw HttpException::notFound('Aucun dossier de transfert.'));
    }

    /** Vrai si la signature correspond au dossier (vérification à l'import par l'application Secondaire). */
    public function verify(string $json, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $json, $this->key()), $signature);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'candidate_id' => (string) $row['candidate_id'], 'target_label' => $row['target_label'], 'created_at' => (string) $row['created_at'],
            'dossier' => (array) json_decode((string) $row['dossier'], true), 'dossier_json' => (string) $row['dossier'], 'signature' => (string) $row['signature'], 'algorithm' => 'HMAC-SHA256',
        ];
    }

    private function key(): string
    {
        $secret = (string) $this->config->get('sync.secret_key', '');
        if ($secret === '') {
            $secret = (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
        }
        if ($secret === '') {
            throw new HttpException(500, 'Configuration incomplète', 'Aucune clé de signature : définissez APP_KEY (ou sync.secret_key) dans l\'environnement.');
        }

        return hash_hmac('sha256', self::FORMAT, $secret);
    }
}
