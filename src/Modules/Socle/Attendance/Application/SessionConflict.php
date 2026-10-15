<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Http\HttpException;

/** Conflit 409 : la version du serveur et celle de l'appareil sont toutes deux restituées, rien n'est résolu automatiquement. */
final class SessionConflict extends HttpException
{
    /**
     * @param array<string, mixed> $serverVersion
     * @param array<string, mixed> $clientVersion
     */
    public function __construct(string $detail, public readonly array $serverVersion, public readonly array $clientVersion)
    {
        parent::__construct(409, 'Conflit', $detail, 'https://api.scholaris.cm/errors/conflict');
    }

    /** @return array<string, mixed> */
    public function toProblem(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'status' => 409,
            'detail' => $this->getMessage(),
            'server_version' => $this->serverVersion,
            'client_version' => $this->clientVersion,
        ];
    }
}
