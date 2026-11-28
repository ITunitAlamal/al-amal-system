<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * An error description that is safe to log, store or show: never the bound values of a query, PostgreSQL's DETAIL
 * text (it repeats the offending values), the database host, credentials, URL query strings, or long digit runs
 * (identity and phone numbers). A database error keeps only its SQLSTATE and the table, constraint and column names;
 * any other error keeps its class and a scrubbed message cut at 200 characters (OB-01).
 */
final class SafeErrorMessage
{
    private const LIMIT = 200;

    /** How far down the previous-exception chain a database error is looked for. */
    private const CHAIN = 5;

    private const IDENT = '[A-Za-z_][A-Za-z0-9_]*';

    public static function from(Throwable $e): string
    {
        $database = self::databaseError($e);
        if ($database !== null) {
            $sql = $database instanceof QueryException ? $database->getSql() : '';

            return self::describe(self::state($database), $sql, (string) ($database->getPrevious()?->getMessage() ?? $database->getMessage()));
        }
        if ($e instanceof ValidationException) {
            return self::scrub(implode(' ', array_merge(...array_values($e->errors()))));
        }
        if ($e instanceof HttpExceptionInterface) {
            return self::scrub($e->getMessage());
        }

        return class_basename($e).': '.self::scrub($e->getMessage());
    }

    /**
     * The same treatment for a message stored earlier as raw text (for example an old import_batches.error that holds
     * a full SQL statement with its values): shown or copied only in this safe form.
     */
    public static function text(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $stored, $match)) {
            $sql = preg_match('/\bSQL: (.*)$/s', $stored, $s) ? $s[1] : '';

            return self::describe($match[1], $sql, $stored);
        }

        return self::scrub($stored);
    }

    /** True when the error, or one it wraps, comes from the database driver. */
    public static function involvesDatabase(Throwable $e): bool
    {
        return self::databaseError($e) !== null;
    }

    /**
     * Log context without the trace (trace arguments can carry values): class, place, the first application frame
     * (file and line only; a QueryException is always thrown inside the framework) and SQLSTATE.
     *
     * @return array{exception: class-string, file: string, line: int, app_frame: ?string, sqlstate: ?string}
     */
    public static function context(Throwable $e): array
    {
        $database = self::databaseError($e);

        return ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine(), 'app_frame' => self::applicationFrame($e),
            'sqlstate' => $database ? self::state($database) : null];
    }

    /** The first frame outside vendor/, as "file:line"; arguments are never read. */
    private static function applicationFrame(Throwable $e): ?string
    {
        $vendor = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        foreach ($frames as $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : '';
            if ($file !== '' && ! str_contains(str_replace('/', DIRECTORY_SEPARATOR, $file), $vendor)) {
                return $file.':'.(int) ($frame['line'] ?? 0);
            }
        }

        return null;
    }

    private static function databaseError(Throwable $e): ?Throwable
    {
        for ($depth = 0, $current = $e; $current !== null && $depth < self::CHAIN; $depth++, $current = $current->getPrevious()) {
            if ($current instanceof QueryException || $current instanceof PDOException) {
                return $current;
            }
        }

        return null;
    }

    private static function state(Throwable $database): string
    {
        $info = $database instanceof QueryException || $database instanceof PDOException ? $database->errorInfo : null;
        $state = (string) ($info[0] ?? $database->getCode());

        return preg_match('/^[0-9A-Z]{5}$/', $state) ? $state : 'unknown';
    }

    /** Names only: the statement's own table, the violated constraint and the key or column names. */
    private static function describe(string $state, string $sql, string $message): string
    {
        $parts = ['Database error SQLSTATE '.$state];
        // Connection errors (class 08) name the server; nothing else is kept.
        if (! str_starts_with($state, '08')) {
            $table = preg_match('/^\s*(?:insert\s+into|update|delete\s+from|select\b.*?\bfrom)\s+"?('.self::IDENT.')"?/is', $sql, $m) ? $m[1]
                : (preg_match('/(?:relation|table) "('.self::IDENT.')"/', $message, $m) ? $m[1] : null);
            $constraint = preg_match('/constraint "('.self::IDENT.')"/', $message, $m) ? $m[1] : null;
            $columns = preg_match('/Key \(((?:'.self::IDENT.')(?:\s*,\s*'.self::IDENT.')*)\)=/', $message, $m) ? $m[1]
                : (preg_match('/column "('.self::IDENT.')"/', $message, $m) ? $m[1] : null);
            foreach (['table' => $table, 'constraint' => $constraint, 'columns' => $columns] as $label => $name) {
                if ($name !== null) {
                    $parts[] = $label.' '.$name;
                }
            }
        }

        return implode('; ', $parts);
    }

    private static function scrub(string $message): string
    {
        $message = preg_replace('/SQLSTATE\[([0-9A-Z]{5})\].*/s', 'SQLSTATE $1', $message) ?? '';
        $message = preg_replace('/\(Connection: .*$/s', '', $message) ?? '';
        $message = preg_replace('~(https?://[^\s?#]+)[?#]\S*~i', '$1', $message) ?? '';
        $message = preg_replace('/\b(pass(?:word)?|user_pass|pwd|token|secret|api_?key|key)\s*[=:]\s*\S+/i', '$1=***', $message) ?? '';
        $message = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '***', $message) ?? '';
        $message = preg_replace('/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]{6,}/u', '***', $message) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        return mb_substr($message, 0, self::LIMIT);
    }
}
