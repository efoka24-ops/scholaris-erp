<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Portals\Student;

use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccountView;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/**
 * Section « higher_finance » des portails du socle (S20) : comptes de scolarité de l'IPES, échéancier, reçus. Affichée à
 * l'étudiant et au garant, même sans consentement (le garant voit toujours la situation financière, RG-INS-7).
 */
final class FinanceSection implements PortalSection
{
    public function __construct(private readonly StudentAccounts $accounts)
    {
    }

    public function key(): string
    {
        return 'higher_finance';
    }

    public function portals(): array
    {
        return [self::LEARNER, self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        $accounts = $this->accounts->forPerson($context->tenantId, $context->subjectPersonId);
        if ($accounts === []) {
            return null;
        }

        return [
            'balance_xaf' => array_sum(array_map(static fn (StudentAccountView $a): int => $a->balanceXaf, $accounts)),
            'up_to_date' => array_filter($accounts, static fn (StudentAccountView $a): bool => !$a->upToDate) === [],
            'accounts' => array_map(static fn (StudentAccountView $a): array => [
                'account_id' => $a->id, 'academic_year_id' => $a->academicYearId, 'balance_xaf' => $a->balanceXaf,
                'installments' => $a->installments, 'receipts' => $a->receipts,
            ], $accounts),
        ];
    }
}
