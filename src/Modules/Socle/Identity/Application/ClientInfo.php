<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Psr\Http\Message\ServerRequestInterface;

/** Ce que l'on sait de l'appareil appelant : adresse IP, agent utilisateur, identifiant d'appareil fourni par le client. */
final class ClientInfo
{
    public function __construct(
        public readonly ?string $ip,
        public readonly string $userAgent,
        public readonly ?string $deviceId = null,
        public readonly ?string $location = null,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request, ?string $deviceId = null): self
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return new self(
            is_string($ip) ? $ip : null,
            mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
            $deviceId !== null && $deviceId !== '' ? mb_substr($deviceId, 0, 128) : null,
            null,
        );
    }

    /** Empreinte stable de l'appareil : l'identifiant fourni par l'application, à défaut l'agent utilisateur. */
    public function fingerprint(): string
    {
        return hash('sha256', 'device|' . ($this->deviceId ?? $this->userAgent));
    }

    /** Nom lisible de l'appareil, ex. « Chrome sur Windows ». */
    public function deviceName(): string
    {
        $ua = $this->userAgent;
        if ($ua === '') {
            return 'Appareil inconnu';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'okhttp') || str_contains($ua, 'Dart/') => 'Application mobile',
            default => 'Navigateur',
        };
        $system = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : $browser . ' sur ' . $system;
    }
}
