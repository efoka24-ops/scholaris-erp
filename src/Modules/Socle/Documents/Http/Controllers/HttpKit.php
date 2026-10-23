<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;

/** Dépendances communes des contrôleurs du module, regroupées pour l'injection. */
final class HttpKit
{
    public function __construct(
        public readonly JsonResponder $responder,
        public readonly TenantContext $context,
        public readonly DocumentPolicy $policy,
        public readonly ResponseFactoryInterface $responses,
        public readonly StreamFactoryInterface $streams,
    ) {
    }
}
