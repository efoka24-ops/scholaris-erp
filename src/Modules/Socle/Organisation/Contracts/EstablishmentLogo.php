<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Lecture du logo d'un établissement (documents officiels). Repli sur le logo du client s'il existe, sinon null. */
interface EstablishmentLogo
{
    public function find(string $tenantId, string $establishmentId): ?LogoImage;
}
