<?php

namespace seniopath\orm;

use PDO;
use PDOException;
use InvalidArgumentException;
use LogicException;
use Exception;
use ReflectionClass;

class Database {
    private static ?Database $instance = null;
    private PDO $connection;
    private string $driver;

    private function __construct(array $config) {
        $rawDriver = strtolower($config['driver'] ?? 'sqlite');
        $this->driver = in_array($rawDriver, ['postgres', 'pgsql'], true) ? 'pgsql' : $rawDriver;
        
        switch ($this->driver) {
            case 'sqlite':
                $dsn = "sqlite:" . ($config['database'] ?? ':memory:');
                $username = null;
                $password = null;
                break;
            case 'mysql':
                $host = $config['host'] ?? '127.0.0.1';
                $port = $config['port'] ?? 3306;
                $db   = $config['database'] ?? '';
                $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
                $username = $config['username'] ?? 'root';
                $password = $config['password'] ?? '';
                break;
            case 'pgsql':
                $host = $config['host'] ?? '127.0.0.1';
                $port = $config['port'] ?? 5432;
                $db   = $config['database'] ?? '';
                $dsn  = "pgsql:host={$host};port={$port};dbname={$db}";
                $username = $config['username'] ?? 'postgres';
                $password = $config['password'] ?? '';
                break;
            default:
                throw new InvalidArgumentException("Nieobsługiwany sterownik bazy danych: {$this->driver}");
        }

        try {
            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException("Błąd połączenia z bazą danych: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    public static function connect(array $config): self {
        self::$instance = new self($config);
        return self::$instance;
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            throw new LogicException("Baza danych nie została zainicjowana. Wywołaj najpierw Database::connect().");
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    public function getDriver(): string {
        return $this->driver;
    }
}

abstract class Model {
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static array $schema = [];
    protected static array $rules = [];
    protected array $attributes = [];
public static function count(): int {
        return static::query()->count();
    }
    public function __construct(array $attributes = []) {
        $this->attributes = $attributes;
    }

    public function __get(string $name) {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, $value): void {
        $this->attributes[$name] = $value;
    }

    public function fill(array $attributes): self {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function toArray(): array {
        return $this->attributes;
    }

    public static function getTableName(): string {
        if (!empty(static::$table)) {
            return static::$table;
        }
        $className = (new ReflectionClass(static::class))->getShortName();
        return strtolower($className) . 's';
    }

    public static function getPrimaryKey(): string {
        return static::$primaryKey;
    }

    protected static function getPdo(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function createTable(): void {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        $driver = $db->getDriver();
        $table = static::getTableName();
        $pk = static::$primaryKey;

        $columnsDef = [];

        foreach (static::$schema as $column => $def) {
            $type = strtoupper($def['type'] ?? 'VARCHAR');
            $nullable = !empty($def['nullable']) ? 'NULL' : 'NOT NULL';
            $isPk = ($column === $pk) || !empty($def['primary']);
            $isAutoIncrement = !empty($def['autoincrement']) || ($isPk && in_array($type, ['INT', 'INTEGER'], true));
            $isUnique = !empty($def['unique']);
            $default = isset($def['default']) ? "DEFAULT '" . addslashes($def['default']) . "'" : '';

            if ($isPk && $isAutoIncrement) {
                if ($driver === 'sqlite') {
                    $columnsDef[] = "{$column} INTEGER PRIMARY KEY AUTOINCREMENT";
                    continue;
                } elseif ($driver === 'mysql') {
                    $columnsDef[] = "{$column} INT AUTO_INCREMENT PRIMARY KEY";
                    continue;
                } elseif ($driver === 'pgsql') {
                    $columnsDef[] = "{$column} SERIAL PRIMARY KEY";
                    continue;
                }
            }

            $sqlType = match ($type) {
                'STRING', 'VARCHAR' => 'VARCHAR(255)',
                'TEXT' => 'TEXT',
                'INT', 'INTEGER' => 'INTEGER',
                'FLOAT', 'DOUBLE' => ($driver === 'pgsql') ? 'DOUBLE PRECISION' : 'FLOAT',
                'BOOLEAN', 'BOOL' => ($driver === 'sqlite') ? 'INTEGER' : (($driver === 'mysql') ? 'TINYINT(1)' : 'BOOLEAN'),
                'DATETIME', 'TIMESTAMP' => ($driver === 'sqlite') ? 'TEXT' : 'DATETIME',
                default => $type
            };

            $colQuery = "{$column} {$sqlType}";
            if ($isPk) {
                $colQuery .= " PRIMARY KEY";
            } else {
                $colQuery .= " {$nullable}";
            }

            if ($isUnique) {
                $colQuery .= " UNIQUE";
            }

            if ($default !== '') {
                $colQuery .= " {$default}";
            }

            $columnsDef[] = trim($colQuery);
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $columnsDef) . ")";
        $pdo->exec($sql);
    }

    public static function dropTable(): void {
        $pdo = static::getPdo();
        $table = static::getTableName();
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }

    public function getRules(): array {
        $rules = static::$rules;
        $pk = static::$primaryKey;
        $currentId = $this->attributes[$pk] ?? null;

        foreach ($rules as $field => &$fieldRules) {
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            foreach ($fieldRules as &$rule) {
                if ($rule === 'unique') {
                    $table = static::getTableName();
                    $rule = "unique:{$table},{$field}," . ($currentId ?? '') . ",{$pk}";
                }
            }
        }

        return $rules;
    }

    public function validate(): bool {
        $rules = $this->getRules();
        if (empty($rules)) {
            return true;
        }

        $validator = Validator::make($this->attributes, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator->getErrors());
        }

        return true;
    }

    public static function query(): QueryBuilder {
        return new QueryBuilder(static::class, static::getTableName());
    }

    public static function where(string $column, $operator = null, $value = null): QueryBuilder {
        $args = func_get_args();
        return static::query()->where(...$args);
    }

    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder {
        return static::query()->orderBy($column, $direction);
    }

    public static function limit(int $limit): QueryBuilder {
        return static::query()->limit($limit);
    }

    public static function all(): array {
        return static::query()->get();
    }

    public static function find($id): ?static {
        return static::query()->where(static::$primaryKey, '=', $id)->first();
    }

    public function save(bool $validate = true): bool {
        if ($validate) {
            $this->validate();
        }

        $pk = static::$primaryKey;
        if (isset($this->attributes[$pk]) && $this->attributes[$pk] !== null && $this->attributes[$pk] !== '') {
            if (static::find($this->attributes[$pk])) {
                return $this->update();
            }
        }
        return $this->insert();
    }

    protected function insert(): bool {
        $pdo = static::getPdo();
        $table = static::getTableName();

        $columns = array_keys($this->attributes);
        if (empty($columns)) {
            return false;
        }

        $fields = implode(', ', $columns);
        $placeholders = implode(', ', array_map(fn($col) => ":{$col}", $columns));

        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        
        $success = $stmt->execute($this->attributes);
        if ($success && empty($this->attributes[static::$primaryKey])) {
            $lastId = $pdo->lastInsertId();
            if ($lastId !== false && $lastId !== '0') {
                $this->attributes[static::$primaryKey] = is_numeric($lastId) ? (int)$lastId : $lastId;
            }
        }
        return $success;
    }

    protected function update(): bool {
        $pdo = static::getPdo();
        $table = static::getTableName();
        $pk = static::$primaryKey;

        $fields = array_filter(array_keys($this->attributes), fn($col) => $col !== $pk);
        if (empty($fields)) {
            return true;
        }

        $setClause = implode(', ', array_map(fn($col) => "{$col} = :{$col}", $fields));

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$pk} = :{$pk}";
        $stmt = $pdo->prepare($sql);
        
        return $stmt->execute($this->attributes);
    }

    public function delete(): bool {
        $pk = static::$primaryKey;
        if (!isset($this->attributes[$pk])) {
            return false;
        }

        $pdo = static::getPdo();
        $table = static::getTableName();

        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$pk} = :pk");
        return $stmt->execute(['pk' => $this->attributes[$pk]]);
    }
}

class QueryBuilder {
    protected string $modelClass;
    protected string $table;
    protected array $wheres = [];
    protected array $orders = [];
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;
    protected array $bindings = [];

    public function __construct(string $modelClass, string $table) {
        $this->modelClass = $modelClass;
        $this->table = $table;
    }

    public function where(string $column, $operator = null, $value = null): self {
        $numArgs = func_num_args();
        if ($numArgs === 2) {
            $value = $operator;
            $operator = '=';
        }

        $paramName = 'w_' . count($this->bindings) . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
        $this->wheres[] = "{$column} {$operator} :{$paramName}";
        $this->bindings[$paramName] = $value;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = "{$column} {$direction}";
        return $this;
    }

    public function limit(int $limit): self {
        $this->limitValue = $limit;
        return $this;
    }

    public function offset(int $offset): self {
        $this->offsetValue = $offset;
        return $this;
    }

    public function get(): array {
        $sql = "SELECT * FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }

        if (!empty($this->orders)) {
            $sql .= " ORDER BY " . implode(', ', $this->orders);
        }

        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }

        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->bindings);

        $results = [];
        while ($row = $stmt->fetch()) {
            $results[] = new $this->modelClass($row);
        }

        return $results;
    }

    public function first() {
        $results = $this->limit(1)->get();
        return $results[0] ?? null;
    }

    public function count(): int {
        $sql = "SELECT COUNT(*) as total FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }

        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $res = $stmt->fetch();

        return (int) ($res['total'] ?? 0);
    }
}

class ValidationException extends Exception {
    protected array $errors = [];

    public function __construct(array $errors, string $message = "Błąd walidacji danych.") {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array {
        return $this->errors;
    }
}

class Validator {
    protected array $data = [];
    protected array $rules = [];
    protected array $errors = [];

    public function __construct(array $data, array $rules) {
        $this->data = $data;
        $this->rules = $rules;
    }

    public static function make(array $data, array $rules): self {
        return new self($data, $rules);
    }

    public function passes(): bool {
        $this->errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            $value = $this->data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $ruleName = $rule;
                $params = [];

                if (str_contains($rule, ':')) {
                    [$ruleName, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                if ($ruleName !== 'required' && ($value === null || $value === '')) {
                    continue;
                }

                $this->validateRule($field, $value, $ruleName, $params);
            }
        }

        return empty($this->errors);
    }

    public function fails(): bool {
        return !$this->passes();
    }

    public function getErrors(): array {
        return $this->errors;
    }

    protected function addError(string $field, string $message): void {
        $this->errors[$field][] = $message;
    }

    protected function validateRule(string $field, $value, string $rule, array $params): void {
        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    $this->addError($field, "Pole {$field} jest wymagane.");
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "Pole {$field} musi być poprawnym adresem e-mail.");
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, "Pole {$field} musi być liczbą.");
                }
                break;

            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, "Pole {$field} musi być liczbą całkowitą.");
                }
                break;

            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, "Pole {$field} musi być ciągiem znaków.");
                }
                break;

            case 'min':
                $min = (float)($params[0] ?? 0);
                if (is_numeric($value) && (float)$value < $min) {
                    $this->addError($field, "Wartość pola {$field} musi wynosić co najmniej {$min}.");
                } elseif (is_string($value) && mb_strlen($value) < $min) {
                    $this->addError($field, "Pole {$field} musi mieć co najmniej {$min} znaków.");
                }
                break;

            case 'max':
                $max = (float)($params[0] ?? 0);
                if (is_numeric($value) && (float)$value > $max) {
                    $this->addError($field, "Wartość pola {$field} nie może przekraczać {$max}.");
                } elseif (is_string($value) && mb_strlen($value) > $max) {
                    $this->addError($field, "Pole {$field} nie może mieć więcej niż {$max} znaków.");
                }
                break;

            case 'in':
                if (!in_array((string)$value, $params, true)) {
                    $allowed = implode(', ', $params);
                    $this->addError($field, "Pole {$field} musi mieć jedną z wartości: {$allowed}.");
                }
                break;

            case 'regex':
                $pattern = $params[0] ?? '//';
                if (!preg_match($pattern, (string)$value)) {
                    $this->addError($field, "Format pola {$field} jest nieprawidłowy.");
                }
                break;

            case 'unique':
                $table = $params[0] ?? null;
                $column = $params[1] ?? $field;
                $exceptId = $params[2] ?? null;
                $pkColumn = $params[3] ?? 'id';

                if ($table) {
                    $pdo = Database::getInstance()->getConnection();
                    $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$column} = :val";
                    $bindings = ['val' => $value];

                    if ($exceptId !== null && $exceptId !== '') {
                        $sql .= " AND {$pkColumn} != :except_id";
                        $bindings['except_id'] = $exceptId;
                    }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($bindings);
                    $row = $stmt->fetch();

                    if ($row && (int)$row['count'] > 0) {
                        $this->addError($field, "Wartość pola {$field} jest już zajęta.");
                    }
                }
                break;

            default:
                throw new InvalidArgumentException("Nieznana reguła walidacji: {$rule}");
        }
    }
}
