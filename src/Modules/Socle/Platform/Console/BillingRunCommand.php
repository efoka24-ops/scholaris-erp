<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Console;

use Scholaris\Modules\Socle\Platform\Application\RegularisationRequests;
use Scholaris\Modules\Socle\Platform\Application\RegularisationService;
use Scholaris\Modules\Socle\Platform\Application\SubscriptionBilling;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;

/** `platform:billing-run [AAAA-MM-JJ]` : relances, impayés et suspensions des abonnements (une fois par jour ; rejouable sans effet double). */
final class BillingRunCommand
{
    public function __construct(
        private readonly SubscriptionBilling $billing,
        private readonly RegularisationService $regularisation,
        private readonly RegularisationRequests $declarations,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $date = isset($args[0]) ? trim($args[0], " \t\"'") : null;
        if ($date !== null && !Calendar::isDate($date)) {
            fwrite(STDERR, "Usage : artisan platform:billing-run [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->billing->run($date);
        $dueSoon = $this->regularisation->notifyDueSoon($result['date']);
        $expired = $this->declarations->expireStale();
        echo "Abonnements au {$result['date']} : {$result['reminders_sent']} relance(s) envoyée(s), {$result['reminders_undelivered']} non délivrée(s), "
            . "{$result['past_due']} passage(s) en impayé, {$result['suspended']} suspension(s), {$dueSoon} préavis d'échéance, {$expired} déclaration(s) de paiement expirée(s).\n";

        return 0;
    }
}
