<?php
/**
 * File: app/core/DB.php
 * Description: Singleton PDO wrapper for SQLite. All Pulse DB access flows through here. Prepared statements only.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:05 SAST
 * Modified: 2026-05-14 09:05 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:05) — initial creation
 */

class DB
{
    private static ?DB $instance = null;
    private static ?string $dbPathOverride = null;
    private PDO $pdo;
    private string $dbPath;

    private function __construct()
    {
        $this->dbPath = self::resolveDbPath();
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public static function configure(string $dbPath): void
    {
        self::$dbPathOverride = $dbPath;
        self::$instance = null;
    }

    public static function getInstance(): DB
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private static function resolveDbPath(): string
    {
        if (self::$dbPathOverride !== null) {
            return self::$dbPathOverride;
        }
        return dirname(__DIR__, 2) . '/data/pulse.db';
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->dbPath;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
