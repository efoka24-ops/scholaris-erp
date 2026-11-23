<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use DI\Container;

/**
 * Types de frais permis (S10) : ceux du socle, plus ceux qu'enregistrent les applications dans le conteneur sous une clé de liste
 * `billing.fee_kinds.<application>` (valeur : liste de codes, ex. ['application_fee', 'national_exam', 'defense']). Une liaison de
 * liste par application : plusieurs applications s'ajoutent sans s'écraser.
 */
final class FeeKinds
{
    public const BASE = ['registration', 'tuition', 'other'];
    public const PREFIX = 'billing.fee_kinds.';
    public const FORMAT = '/^[a-z][a-z0-9_]{1,19}$/';

    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $kinds = self::BASE;
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $kind) {
                if (is_string($kind) && preg_match(self::FORMAT, $kind) === 1 && !in_array($kind, $kinds, true)) {
                    $kinds[] = $kind;
                }
            }
        }

        return $this->cache = $kinds;
    }

    /** @return list<string> cibles possibles d'une remise : chaque type de frais, ou « all » */
    public function discountTargets(): array
    {
        return ['tuition', 'registration', 'other', ...array_slice($this->all(), count(self::BASE)), 'all'];
    }
}
