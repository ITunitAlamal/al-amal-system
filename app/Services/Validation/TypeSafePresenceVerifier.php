<?php

namespace App\Services\Validation;

use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Validation\DatabasePresenceVerifierInterface;

/**
 * The "exists" and "unique" checks of every form, made safe for PostgreSQL.
 *
 * PostgreSQL refuses to compare an integer column with text such as "abc" (SQLSTATE 22P02), so a wrong
 * value in a select or a crafted request turned into a server error instead of a field message. A value
 * that is not a whole number can never match an integer column, so it is answered "no match" without a
 * query and the form shows the usual validation message.
 */
final class TypeSafePresenceVerifier implements DatabasePresenceVerifierInterface
{
    /** @var array<string, array<string, bool>> table => column => holds whole numbers */
    private array $integerColumns = [];

    private ?string $connection = null;

    public function __construct(
        private readonly DatabasePresenceVerifierInterface $verifier,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        if (! $this->fits($collection, $column, $value) || ! $this->conditionsFit($collection, $extra)) {
            return 0;
        }

        if ($excludeId !== null && $excludeId !== 'NULL' && ! $this->fits($collection, $idColumn ?? 'id', $excludeId)) {
            $excludeId = null;
        }

        return $this->verifier->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        if (! $this->conditionsFit($collection, $extra)) {
            return 0;
        }

        $values = array_values(array_filter($values, fn ($value): bool => $this->fits($collection, $column, $value)));

        return $values === [] ? 0 : $this->verifier->getMultiCount($collection, $column, $values, $extra);
    }

    public function setConnection($connection)
    {
        $this->connection = $connection;
        $this->verifier->setConnection($connection);
    }

    /**
     * Extra "where" conditions of a rule (column => value, NULL, NOT_NULL or !value) must fit their columns too.
     */
    private function conditionsFit(string $collection, array $extra): bool
    {
        foreach ($extra as $column => $value) {
            if ($value instanceof Closure || ! is_string($column) || $value === 'NULL' || $value === 'NOT_NULL') {
                continue;
            }
            $plain = is_string($value) && str_starts_with($value, '!') ? substr($value, 1) : $value;
            if (! $this->fits($collection, $column, $plain)) {
                return false;
            }
        }

        return true;
    }

    private function fits(string $collection, string $column, mixed $value): bool
    {
        if (! $this->isIntegerColumn($collection, $column)) {
            return true;
        }

        return is_int($value) || (is_string($value) && preg_match('/^-?[0-9]{1,18}$/D', $value) === 1);
    }

    private function isIntegerColumn(string $collection, string $column): bool
    {
        $table = str_contains($collection, '.') ? substr($collection, strrpos($collection, '.') + 1) : $collection;

        if (! isset($this->integerColumns[$table])) {
            $this->integerColumns[$table] = [];
            $rows = $this->db->connection($this->connection)->select(
                'select column_name, data_type from information_schema.columns where table_schema = current_schema() and table_name = ?',
                [$table]
            );
            foreach ($rows as $row) {
                $this->integerColumns[$table][$row->column_name] = in_array($row->data_type, ['bigint', 'integer', 'smallint'], true);
            }
        }

        return $this->integerColumns[$table][$column] ?? false;
    }
}
