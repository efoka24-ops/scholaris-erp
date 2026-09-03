<?php

declare(strict_types=1);

namespace Scholaris\Database;

use InvalidArgumentException;
use Scholaris\Tenant\TenantContext;

/**
 * Constructeur de requetes filtre par etablissement.
 *
 * Point central de l'isolation multi-etablissement : toute requete sur une
 * table scopee recoit automatiquement "tenant_id = :__tenant". Il n'existe pas
 * de chemin permettant d'ecrire une lecture sans ce filtre, sinon en appelant
 * TenantContext::global() de facon explicite.
 *
 * Les valeurs ne sont jamais concatenees dans le SQL : elles passent toutes par
 * des parametres lies. Les identifiants (tables, colonnes) sont valides contre
 * une expression stricte avant d'etre inseres, car ils ne peuvent pas etre lies.
 */
final class Table
{
    private Connection $connection;

    private TenantContext $tenant;

    private string $table;

    private bool $tenantScoped;

    /** @var list<string> */
    private array $wheres = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private string $columns = '*';

    private int $bindingCounter = 0;

    /** Tables sans colonne tenant_id : referentiels globaux et tables pivot. */
    private const UNSCOPED_TABLES = [
        'permissions',
        'roles',
        'role_permissions',
        'user_roles',
        'student_parents',
        'audit_logs',
        'establishment_requests',
        'migrations',
        'sessions',
        'periods',
    ];

    public function __construct(Connection $connection, TenantContext $tenant, string $table)
    {
        $this->connection = $connection;
        $this->tenant = $tenant;
        $this->table = self::identifier($table);
        $this->tenantScoped = ! in_array($table, self::UNSCOPED_TABLES, true);
    }

    /**
     * Valide un identifiant SQL. Les noms de tables et de colonnes ne peuvent
     * pas etre lies en parametre : les filtrer est la seule protection.
     */
    private static function identifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Identifiant SQL invalide : {$name}");
        }

        return $name;
    }

    private function quote(string $identifier): string
    {
        $identifier = self::identifier($identifier);

        return $this->connection->driver() === 'mysql' ? "`{$identifier}`" : "\"{$identifier}\"";
    }

    private function bind(mixed $value): string
    {
        $placeholder = 'p'.(++$this->bindingCounter);
        $this->bindings[$placeholder] = $value;

        return ':'.$placeholder;
    }

    /**
     * @param  list<string>  $columns
     */
    public function select(array $columns): self
    {
        $this->columns = implode(', ', array_map(fn (string $c) => $this->quote($c), $columns));

        return $this;
    }

    public function where(string $column, mixed $value, string $operator = '='): self
    {
        if (! in_array($operator, ['=', '!=', '<', '<=', '>', '>=', 'LIKE'], true)) {
            throw new InvalidArgumentException("Operateur non autorise : {$operator}");
        }

        $this->wheres[] = sprintf('%s %s %s', $this->quote($column), $operator, $this->bind($value));

        return $this;
    }

    public function whereNull(string $column, bool $null = true): self
    {
        $this->wheres[] = $this->quote($column).($null ? ' IS NULL' : ' IS NOT NULL');

        return $this;
    }

    /**
     * @param  list<mixed>  $values
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            // Un IN vide ne doit jamais devenir "pas de filtre" : la condition
            // est rendue impossible pour que la requete retourne zero ligne.
            $this->wheres[] = '1 = 0';

            return $this;
        }

        $placeholders = array_map(fn (mixed $value) => $this->bind($value), $values);
        $this->wheres[] = sprintf('%s IN (%s)', $this->quote($column), implode(', ', $placeholders));

        return $this;
    }

    /**
     * Recherche textuelle sur plusieurs colonnes. Le terme est echappe pour que
     * % et _ saisis par l'utilisateur restent des caracteres litteraux.
     *
     * @param  list<string>  $columns
     */
    public function search(string $term, array $columns): self
    {
        if (trim($term) === '' || $columns === []) {
            return $this;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term));
        $parts = [];

        foreach ($columns as $column) {
            $parts[] = sprintf('%s LIKE %s', $this->quote($column), $this->bind('%'.$escaped.'%'));
        }

        $this->wheres[] = '('.implode(' OR ', $parts).')';

        return $this;
    }

    /** Exclut les lignes supprimees logiquement. */
    public function notDeleted(): self
    {
        return $this->whereNull('deleted_at');
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $this->orders[] = $this->quote($column).' '.$direction;

        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = max($limit, 0);
        $this->offset = max($offset, 0);

        return $this;
    }

    /**
     * Assemble la clause WHERE, filtre d'etablissement compris.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function compileWhere(): array
    {
        $conditions = $this->wheres;
        $bindings = $this->bindings;

        if ($this->tenantScoped && ! $this->tenant->isGlobal()) {
            $conditions[] = $this->quote('tenant_id').' = :__tenant';
            $bindings['__tenant'] = $this->tenant->requireId();
        }

        $sql = $conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions);

        return [$sql, $bindings];
    }

    private function compileTail(): string
    {
        $sql = '';

        if ($this->orders !== []) {
            $sql .= ' ORDER BY '.implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            // Entiers deja normalises par limit() : leur interpolation est sure,
            // et MySQL refuse un parametre lie a cet emplacement.
            $sql .= sprintf(' LIMIT %d OFFSET %d', $this->limit, $this->offset ?? 0);
        }

        return $sql;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        [$where, $bindings] = $this->compileWhere();
        $sql = sprintf('SELECT %s FROM %s%s%s', $this->columns, $this->quote($this->table), $where, $this->compileTail());

        return $this->connection->select($sql, $bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $this->limit(1);
        [$where, $bindings] = $this->compileWhere();
        $sql = sprintf('SELECT %s FROM %s%s%s', $this->columns, $this->quote($this->table), $where, $this->compileTail());

        return $this->connection->selectOne($sql, $bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->where('id', $id)->first();
    }

    public function count(): int
    {
        [$where, $bindings] = $this->compileWhere();
        $sql = sprintf('SELECT COUNT(*) FROM %s%s', $this->quote($this->table), $where);

        return (int) $this->connection->scalar($sql, $bindings);
    }

    public function sum(string $column): float
    {
        [$where, $bindings] = $this->compileWhere();
        $sql = sprintf('SELECT COALESCE(SUM(%s), 0) FROM %s%s', $this->quote($column), $this->quote($this->table), $where);

        return (float) $this->connection->scalar($sql, $bindings);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Insere une ligne et renvoie son identifiant. Le tenant_id est ajoute
     * automatiquement si la table est scopee et que la valeur n'est pas fournie.
     *
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): string
    {
        if ($this->tenantScoped && ! array_key_exists('tenant_id', $values)) {
            $values['tenant_id'] = $this->tenant->requireId();
        }

        if (! array_key_exists('id', $values)) {
            $values['id'] = self::uuid();
        }

        $columns = [];
        $placeholders = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $columns[] = $this->quote((string) $column);
            $placeholders[] = ':'.self::identifier((string) $column);
            $bindings[(string) $column] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($this->table),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->connection->execute($sql, $bindings);

        return (string) $values['id'];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        [$where, $bindings] = $this->compileWhere();

        if ($where === '') {
            // Un UPDATE sans condition reecrirait toute la table, tous
            // etablissements confondus. Refus systematique.
            throw new InvalidArgumentException('UPDATE sans condition refuse.');
        }

        $assignments = [];

        foreach ($values as $column => $value) {
            $placeholder = 'set_'.self::identifier((string) $column);
            $assignments[] = sprintf('%s = :%s', $this->quote((string) $column), $placeholder);
            $bindings[$placeholder] = $value;
        }

        $sql = sprintf('UPDATE %s SET %s%s', $this->quote($this->table), implode(', ', $assignments), $where);

        return $this->connection->execute($sql, $bindings);
    }

    /** Suppression logique : le schema ne prevoit aucune suppression physique. */
    public function softDelete(): int
    {
        return $this->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function delete(): int
    {
        [$where, $bindings] = $this->compileWhere();

        if ($where === '') {
            throw new InvalidArgumentException('DELETE sans condition refuse.');
        }

        return $this->connection->execute(
            sprintf('DELETE FROM %s%s', $this->quote($this->table), $where),
            $bindings
        );
    }

    /**
     * UUID v4. Les identifiants sont generes par l'application et non par la
     * base, pour rester identiques entre MySQL et SQLite.
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
