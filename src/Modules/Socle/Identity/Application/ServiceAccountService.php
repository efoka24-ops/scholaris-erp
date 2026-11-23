<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoServiceAccountRepository;

/**
 * Comptes de service (intégrations) : clé `sk_<préfixe>_<secret>` montrée une seule fois, stockée hachée,
 * rotation avec période de grâce de 24 h pendant laquelle l'ancienne clé reste valable.
 */
final class ServiceAccountService
{
    public const GRACE_SECONDS = 86400;

    public function __construct(
        private readonly PdoServiceAccountRepository $accounts,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    /**
     * @param list<string> $scopes permissions accordées au compte
     * @return array{id: string, key: string}
     */
    public function create(string $tenantId, string $name, array $scopes, ?string $actorId = null): array
    {
        $name = trim($name);
        if ($name === '' || $scopes === []) {
            throw HttpException::validation(['name' => ['Un nom et au moins une permission sont obligatoires.']]);
        }
        $prefix = self::randomToken(8);
        $key = $this->makeKey($prefix);
        $id = $this->accounts->insert($tenantId, $name, $scopes, $prefix, hash('sha256', $key), $this->moment->iso());
        $this->audit->append($tenantId, $actorId, 'identity.service_account_created', 'service_account', $id, null, ['name' => $name, 'scopes' => $scopes]);

        return ['id' => $id, 'key' => $key];
    }

    /** @return string la nouvelle clé (montrée une seule fois) */
    public function rotate(string $tenantId, string $id, ?string $actorId = null): string
    {
        $prefix = $this->accounts->prefixOf($tenantId, $id) ?? throw HttpException::notFound('Compte de service introuvable.');
        $key = $this->makeKey($prefix);
        $now = $this->moment->iso();
        $this->accounts->rotate($tenantId, $id, hash('sha256', $key), $this->moment->in(self::GRACE_SECONDS), $now);
        $this->audit->append($tenantId, $actorId, 'identity.service_account_rotated', 'service_account', $id);

        return $key;
    }

    public function revoke(string $tenantId, string $id, ?string $actorId = null): void
    {
        if (!$this->accounts->revoke($tenantId, $id, $this->moment->iso())) {
            throw HttpException::notFound('Compte de service introuvable.');
        }
        $this->audit->append($tenantId, $actorId, 'identity.service_account_revoked', 'service_account', $id);
    }

    /** @return array{id: string, tenant_id: string, scopes: list<string>}|null */
    public function authenticate(string $key): ?array
    {
        if (preg_match('/^sk_([A-Za-z0-9]{8})_[A-Za-z0-9]{32}$/', $key, $m) !== 1) {
            return null;
        }

        return $this->accounts->findByKey($m[1], hash('sha256', $key), $this->moment->iso());
    }

    private function makeKey(string $prefix): string
    {
        return 'sk_' . $prefix . '_' . self::randomToken(32);
    }

    private static function randomToken(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $token;
    }
}
