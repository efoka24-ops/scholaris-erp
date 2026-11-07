<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Throwable;

/**
 * NOT-14 (heures validées, vacation payée) à l'enseignant par le Messenger du socle : SMS ou e-mail selon le canal disponible,
 * consentement et plages horaires appliqués par lui. Un échec d'envoi n'interrompt jamais l'opération métier.
 */
final class VacationNotices
{
    public function __construct(private readonly Messenger $messenger, private readonly LoggerInterface $logger)
    {
    }

    public static function amount(int $xaf): string
    {
        return number_format($xaf, 0, ',', ' ');
    }

    public static function hetd(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 2, ',', ' '), '0'), ',');
    }

    /** @param array<string, mixed> $statement */
    public function hoursValidated(array $statement): void
    {
        $this->send($statement, VacationPermissions::NOT14_HOURS, [
            'label' => (string) $statement['period_month'],
            'details' => self::hetd((int) $statement['hetd_milli']),
            'amount' => self::amount((int) $statement['gross_amount']),
        ]);
    }

    /** @param array<string, mixed> $statement */
    public function paid(array $statement): void
    {
        $this->send($statement, VacationPermissions::NOT14_PAID, [
            'label' => (string) $statement['period_month'],
            'amount' => self::amount((int) $statement['net_amount']),
            'payment_reference' => (string) $statement['payment_reference'],
        ]);
    }

    /**
     * @param array<string, mixed> $statement
     * @param array<string, scalar> $variables
     */
    private function send(array $statement, string $template, array $variables): void
    {
        try {
            $this->messenger->send((string) $statement['tenant_id'], (string) $statement['establishment_id'], (string) $statement['person_id'], $template, $variables, ['sms', 'email']);
        } catch (Throwable $e) {
            $this->logger->warning('NOT-14 non envoyée : ' . $e->getMessage());
        }
    }
}
