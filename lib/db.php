<?php
/**
 * Filename: db.php
 * Description: PDO singleton wrapper for database operations
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/../config.php';

class Database
{
    /**
     * @var PDO|null Singleton PDO instance
     */
    private static ?PDO $instance = null;

    /**
     * Prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Returns the PDO singleton instance.
     * Reads connection parameters from config.php constants:
     *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
     *
     * @return PDO
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host    = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
            $port    = defined('DB_PORT') ? DB_PORT : 3306;
            $dbname  = defined('DB_NAME') ? DB_NAME : 'safari_traveller';
            $user    = defined('DB_USER') ? DB_USER : 'root';
            $pass    = defined('DB_PASS') ? DB_PASS : '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];

            self::$instance = new PDO($dsn, $user, $pass, $options);
        }

        return self::$instance;
    }

    /**
     * Execute a prepared statement and return the PDOStatement.
     *
     * @param string $sql    SQL query with placeholders
     * @param array  $params Parameters to bind
     * @return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $pdo  = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row from the database.
     *
     * @param string $sql    SQL query with placeholders
     * @param array  $params Parameters to bind
     * @return array|false   Associative array or false if no row found
     */
    public static function fetch(string $sql, array $params = []): array|false
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Fetch all rows from the database.
     *
     * @param string $sql    SQL query with placeholders
     * @param array  $params Parameters to bind
     * @return array         Array of associative arrays
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Insert an associative array into a table.
     *
     * @param string $table Table name
     * @param array  $data  Associative array of column => value
     * @return string       Last insert ID
     */
    public static function insert(string $table, array $data): string
    {
        $columns      = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $columnList      = implode(', ', array_map(fn($col) => "`{$col}`", $columns));
        $placeholderList = implode(', ', $placeholders);

        $sql = "INSERT INTO `{$table}` ({$columnList}) VALUES ({$placeholderList})";

        self::query($sql, array_values($data));

        return self::getInstance()->lastInsertId();
    }

    /**
     * Update rows in a table.
     *
     * @param string $table       Table name
     * @param array  $data        Associative array of column => value to set
     * @param string $where       WHERE clause (e.g. "id = ?")
     * @param array  $whereParams Parameters for the WHERE clause
     * @return int                Number of affected rows
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setClauses = [];
        $params     = [];

        foreach ($data as $column => $value) {
            $setClauses[] = "`{$column}` = ?";
            $params[]     = $value;
        }

        $setString = implode(', ', $setClauses);
        $sql       = "UPDATE `{$table}` SET {$setString} WHERE {$where}";

        $params = array_merge($params, $whereParams);

        $stmt = self::query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Delete rows from a table.
     *
     * @param string $table       Table name
     * @param string $where       WHERE clause (e.g. "id = ?")
     * @param array  $whereParams Parameters for the WHERE clause
     * @return int                Number of affected rows
     */
    public static function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql  = "DELETE FROM `{$table}` WHERE {$where}";
        $stmt = self::query($sql, $whereParams);

        return $stmt->rowCount();
    }

    /**
     * Count rows in a table.
     *
     * @param string $table  Table name
     * @param string $where  Optional WHERE clause (e.g. "status = ?")
     * @param array  $params Parameters for the WHERE clause
     * @return int           Row count
     */
    public static function count(string $table, string $where = '', array $params = []): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}`";

        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        $row = self::fetch($sql, $params);

        return (int) ($row['cnt'] ?? 0);
    }
}
