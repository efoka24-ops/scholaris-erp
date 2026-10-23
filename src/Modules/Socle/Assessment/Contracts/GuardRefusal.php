<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/** Refus d'une garde de délibération : statut HTTP (403 droit ou second facteur, 409 état) et message en français. */
final class GuardRefusal
{
    public function __construct(
        public readonly int $status,
        public readonly string $message,
    ) {
    }

    public static function conflict(string $message): self
    {
        return new self(409, $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }
}
