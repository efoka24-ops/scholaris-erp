<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Contracts;

/** Un élément d'un lot : type, identifiant client (idempotence) et charge utile décodée du JSON. */
final class BatchItem
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $kind,
        public readonly string $clientUuid,
        public readonly array $payload,
    ) {
    }
}
