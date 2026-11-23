<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use DI\Container;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Billing\Contracts\FeeItemDraft;
use Scholaris\Modules\Socle\Billing\Contracts\FeeItemGuard;

/** Gardes de frais déclarées dans le conteneur sous `billing.fee_item_guards.*` (SS8), appelées dans l'ordre des clés. */
final class FeeItemGuards
{
    public const PREFIX = 'billing.fee_item_guards.';
    public const CODE = 'fee_not_authorized';

    /** @var list<FeeItemGuard>|null */
    private ?array $guards = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return string|null premier motif de refus, ou null si toutes les gardes laissent passer */
    public function refusal(FeeItemDraft $draft): ?string
    {
        foreach ($this->all() as $guard) {
            $reason = $guard->refusal($draft);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** Refus → 422 au code stable `fee_not_authorized`. */
    public function assertAllowed(FeeItemDraft $draft): void
    {
        $reason = $this->refusal($draft);
        if ($reason !== null) {
            throw new HttpException(
                422,
                'Frais non autorisé',
                $reason,
                'https://api.scholaris.cm/errors/fee-not-authorized',
                ['kind' => [$reason]],
                extensions: ['code' => self::CODE],
            );
        }
    }

    /** @return list<FeeItemGuard> */
    private function all(): array
    {
        if ($this->guards !== null) {
            return $this->guards;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $guard = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($guard instanceof FeeItemGuard) {
                    $found[$guard::class] = $guard;
                }
            }
        }

        return $this->guards = array_values($found);
    }
}
