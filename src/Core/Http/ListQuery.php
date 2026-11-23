<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Paramètres de liste normalisés : pagination par curseur (50 par défaut, 200 maximum),
 * filtres `filter[champ]=valeur` et tri `sort=champ,-autre` limités à une liste blanche déclarée par la ressource.
 */
final class ListQuery
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    /**
     * @param array<string, string> $filters
     * @param list<array{0: string, 1: 'asc'|'desc'}> $sorts
     */
    private function __construct(
        public readonly int $perPage,
        public readonly ?string $cursor,
        public readonly array $filters,
        public readonly array $sorts,
    ) {
    }

    /**
     * @param list<string> $allowedFilters
     * @param list<string> $allowedSorts
     */
    public static function fromRequest(ServerRequestInterface $request, array $allowedFilters = [], array $allowedSorts = []): self
    {
        $params = $request->getQueryParams();
        $errors = [];

        $perPage = self::DEFAULT_PER_PAGE;
        if (isset($params['per_page'])) {
            $value = filter_var($params['per_page'], FILTER_VALIDATE_INT);
            if ($value === false || $value < 1 || $value > self::MAX_PER_PAGE) {
                $errors['per_page'] = ['per_page doit être un entier entre 1 et ' . self::MAX_PER_PAGE . '.'];
            } else {
                $perPage = $value;
            }
        }

        $cursor = null;
        if (isset($params['cursor']) && is_string($params['cursor']) && $params['cursor'] !== '') {
            if (preg_match('/^[A-Za-z0-9_\-=]{1,255}$/', $params['cursor']) !== 1) {
                $errors['cursor'] = ['Curseur invalide.'];
            } else {
                $cursor = $params['cursor'];
            }
        }

        $filters = [];
        foreach ((array) ($params['filter'] ?? []) as $field => $value) {
            if (!in_array($field, $allowedFilters, true) || !is_scalar($value)) {
                $errors['filter[' . $field . ']'] = ['Filtre non autorisé.'];
                continue;
            }
            $filters[$field] = (string) $value;
        }

        $sorts = [];
        if (isset($params['sort']) && is_string($params['sort']) && $params['sort'] !== '') {
            foreach (explode(',', $params['sort']) as $item) {
                $direction = str_starts_with($item, '-') ? 'desc' : 'asc';
                $field = ltrim($item, '-');
                if (!in_array($field, $allowedSorts, true)) {
                    $errors['sort'] = ['Tri non autorisé : ' . $field . '.'];
                    continue;
                }
                $sorts[] = [$field, $direction];
            }
        }

        if ($errors !== []) {
            throw HttpException::validation($errors, 'Paramètres de liste invalides.');
        }

        return new self($perPage, $cursor, $filters, $sorts);
    }

    public static function encodeCursor(string $lastId): string
    {
        return rtrim(strtr(base64_encode($lastId), '+/', '-_'), '=');
    }

    public static function decodeCursor(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
