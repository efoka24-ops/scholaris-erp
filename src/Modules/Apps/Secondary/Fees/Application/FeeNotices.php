<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\ExamFeeStatus;
use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamCandidateDirectory;
use Scholaris\Modules\Apps\Secondary\SecondaryApplication;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\OpenedEstablishments;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Throwable;

/**
 * Relances SMS du secondaire (CDC NOT-07, NOT-08, NOT-10), tâche planifiée `secondary:fee-notices` (une fois par jour, un jour manqué
 * est rattrapé) :
 *  - NOT-07 « échéance à venir » et NOT-08 « tranche en retard » [fees_private] : tranches non soldées des factures du socle de chaque
 *    élève inscrit, aux décalages `secondary.fees.notice_offsets` (négatif ou nul : NOT-07, positif : NOT-08), aux garants. Seulement
 *    si la relance générique du socle est coupée (`billing.reminder_offsets` = liste vide, préréglage du privé) : jamais deux SMS ;
 *  - NOT-10 « frais d'examen non payés sur cartescolaire.cm » [official_exams] : `secondary.fees.exam_fee_notice_days` jours avant la
 *    date limite d'inscription d'une session ouverte, pour chaque candidat dont aucun paiement n'est constaté (ExamFeeStatus), aux
 *    garants et à l'élève. L'établissement n'encaisse jamais ces frais (RG-FIN-3) : le message ne donne aucun montant.
 * Un client dont l'application est suspendue ou fermée est sauté. Journal `se_fee_notices` : un même objet n'est jamais relancé deux
 * fois au même destinataire ; un échec d'envoi n'est pas journalisé (nouvel essai le lendemain).
 */
final class FeeNotices
{
    public const NOT07 = 'NOT-07';
    public const NOT08 = 'NOT-08';
    public const NOT10 = 'NOT-10';
    public const TEMPLATE_NOT07 = 'sec.not07.fees_due';
    public const TEMPLATE_NOT08 = 'sec.not08.fees_overdue';
    public const TEMPLATE_NOT10 = 'sec.not10.exam_fees_unpaid';
    private const TABLE = 'se_fee_notices';

    public function __construct(
        private readonly OpenedEstablishments $opened,
        private readonly EstablishmentProfiles $profiles,
        private readonly ApplicationAccess $applications,
        private readonly FeesSettings $settings,
        private readonly EnrollmentDirectory $enrollments,
        private readonly LearnerInvoices $learnerInvoices,
        private readonly InvoiceIssuer $invoices,
        private readonly ExamCandidateDirectory $exams,
        private readonly ExamFeeStatus $examFees,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly Messenger $messenger,
        private readonly Store $store,
        private readonly SecondaryClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{establishments: int, not07: int, not08: int, not10: int, skipped_socle_reminders: int, skipped_tenants: int} */
    public function run(?string $today = null): array
    {
        $today ??= $this->clock->today();
        $result = ['establishments' => 0, 'not07' => 0, 'not08' => 0, 'not10' => 0, 'skipped_socle_reminders' => 0, 'skipped_tenants' => 0];
        $skipped = [];
        foreach ($this->opened->all() as $scope) {
            $tenantId = $scope['tenant_id'];
            $establishmentId = $scope['establishment_id'];
            if (!$this->applications->allowsWrites($tenantId, SecondaryApplication::KEY)) {
                $skipped[$tenantId] = true;
                continue;
            }
            try {
                $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
            } catch (Throwable) {
                continue;
            }
            ++$result['establishments'];
            if ($profile->isActive(Modules::FEES_PRIVATE)) {
                if ($this->settings->value($tenantId, $establishmentId, 'billing.reminder_offsets', null) === []) {
                    [$due, $late] = $this->installmentNotices($tenantId, $establishmentId, $today);
                    $result['not07'] += $due;
                    $result['not08'] += $late;
                } else {
                    ++$result['skipped_socle_reminders'];
                }
            }
            if ($profile->isActive(Modules::OFFICIAL_EXAMS)) {
                $result['not10'] += $this->examFeeNotices($tenantId, $establishmentId, $today);
            }
        }
        $result['skipped_tenants'] = count($skipped);

        return $result;
    }

    /** @return array{0: int, 1: int} NOT-07 et NOT-08 envoyées */
    private function installmentNotices(string $tenantId, string $establishmentId, string $today): array
    {
        $offsets = $this->offsets($tenantId, $establishmentId);
        $catchUp = FeesDefaults::NOTICE_CATCH_UP_DAYS;
        $sent = [0, 0];
        foreach (array_values(array_unique($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId))) as $learnerId) {
            foreach ($this->learnerInvoices->idsFor($tenantId, $establishmentId, $learnerId) as $invoiceId) {
                $invoice = $this->invoices->find($tenantId, $establishmentId, $invoiceId);
                if ($invoice === null || $invoice->subjectId !== $learnerId) {
                    continue;
                }
                foreach ($invoice->installments as $installment) {
                    $left = $installment['amount_xaf'] - $installment['paid_xaf'];
                    if ($left <= 0) {
                        continue;
                    }
                    foreach ($offsets as $offset) {
                        $target = self::shift($installment['due_on'], $offset);
                        if ($target > $today || $target < self::shift($today, -$catchUp)) {
                            continue;
                        }
                        $late = $offset > 0;
                        $child = $this->people->find($tenantId, $learnerId);
                        $variables = [
                            'child_name' => $child === null ? '' : $child->fullName(), 'label' => $installment['label'],
                            'amount' => number_format($left, 0, ',', ' '), 'due_on' => $installment['due_on'],
                        ] + ($late ? [] : ['offset' => abs($offset)]);
                        $count = $this->notify($tenantId, $establishmentId, $late ? self::NOT08 : self::NOT07, $installment['id'] . ':' . $offset, $learnerId, $late ? self::TEMPLATE_NOT08 : self::TEMPLATE_NOT07, $variables, false);
                        $sent[$late ? 1 : 0] += $count;
                    }
                }
            }
        }

        return $sent;
    }

    private function examFeeNotices(string $tenantId, string $establishmentId, string $today): int
    {
        $days = $this->examNoticeDays($tenantId, $establishmentId);
        $from = self::shift($today, $days - FeesDefaults::NOTICE_CATCH_UP_DAYS);
        $to = self::shift($today, $days);
        $sent = 0;
        foreach ($this->exams->sessionsWithDeadlineBetween($tenantId, $establishmentId, $from, $to) as $session) {
            $left = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($session['registration_deadline']))->format('%r%a');
            foreach ($session['person_ids'] as $personId) {
                if ($this->examFees->seen($tenantId, $establishmentId, $personId, $session['exam_code'])) {
                    continue;
                }
                $child = $this->people->find($tenantId, $personId);
                $sent += $this->notify($tenantId, $establishmentId, self::NOT10, $session['session_id'] . ':' . $personId, $personId, self::TEMPLATE_NOT10, [
                    'exam_label' => $session['exam_label'], 'child_name' => $child === null ? '' : $child->fullName(),
                    'due_on' => $session['registration_deadline'], 'offset' => max(0, $left),
                ], true);
            }
        }

        return $sent;
    }

    /** @param array<string, scalar> $variables */
    private function notify(string $tenantId, string $establishmentId, string $code, string $objectRef, string $learnerId, string $template, array $variables, bool $alsoLearner): int
    {
        if (in_array($code, [self::NOT07, self::NOT08], true)) {
            $establishment = $this->establishments->find($tenantId, $establishmentId);
            $variables['establishment_name'] = $establishment === null ? '' : $establishment->name;
        }
        $recipients = [];
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
            $recipients[$guardian->id] = true;
        }
        if ($alsoLearner) {
            $recipients[$learnerId] = true;
        }
        $sent = 0;
        foreach (array_keys($recipients) as $recipient) {
            $recipient = (string) $recipient;
            $where = ['notice_code' => $code, 'object_ref' => $objectRef, 'recipient_person_id' => $recipient];
            if ($this->store->count(self::TABLE, $tenantId, $establishmentId, $where) > 0) {
                continue;
            }
            try {
                $result = $this->messenger->send($tenantId, $establishmentId, $recipient, $template, $variables, ['sms']);
            } catch (Throwable $e) {
                $this->logger->warning('Relance ' . $code . ' non envoyée : ' . $e->getMessage(), ['object' => $objectRef]);

                continue;
            }
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, $where + [
                'id' => (string) new Ulid(), 'learner_id' => $learnerId, 'delivery_status' => $result->status, 'created_at' => $this->clock->now(),
            ]);
            $result->status !== DeliveryResult::BLOCKED && ++$sent;
        }

        return $sent;
    }

    /** @return list<int> */
    private function offsets(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.fees.notice_offsets', null);
        $list = is_array($raw) ? $raw : FeesDefaults::NOTICE_OFFSETS;
        $out = [];
        foreach ($list as $offset) {
            if (is_int($offset) && abs($offset) <= FeesDefaults::MAX_NOTICE_OFFSET) {
                $out[] = $offset;
            }
        }

        return array_values(array_unique($out));
    }

    private function examNoticeDays(string $tenantId, string $establishmentId): int
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.fees.exam_fee_notice_days', null);

        return is_int($raw) && $raw >= 1 && $raw <= FeesDefaults::MAX_NOTICE_OFFSET ? $raw : FeesDefaults::EXAM_FEE_NOTICE_DAYS;
    }

    private static function shift(string $date, int $days): string
    {
        $moment = new DateTimeImmutable($date);

        return ($days >= 0 ? $moment->add(new DateInterval('P' . $days . 'D')) : $moment->sub(new DateInterval('P' . -$days . 'D')))->format('Y-m-d');
    }
}
