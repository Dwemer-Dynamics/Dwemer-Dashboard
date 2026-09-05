<?php

// Automatic backup preferences belong to CHIM's metadata, even while viewing another mod.
class DashboardBackupSettings
{
    private $conn;
    public function __construct($conn = null)
    {
        $this->conn = $conn ?? @pg_connect('host=localhost port=5432 dbname=dwemer user=dwemer password=dwemer connect_timeout=3', PGSQL_CONNECT_FORCE_NEW);
        if (!$this->conn) throw new RuntimeException('Backup settings are unavailable.');
    }
    public static function shared(): self
    {
        static $settings;
        return $settings ??= new self();
    }
    public function quote(string $value): string { return pg_escape_literal($this->conn, $value); }
    public function execQuery(string $sql)
    {
        $result = @pg_query($this->conn, $sql);
        if (!$result) throw new RuntimeException('Could not update backup settings.');
        return $result;
    }
    public function fetchOne(string $sql) { return pg_fetch_assoc($this->execQuery($sql)); }
    public function fetchAll(string $sql): array { return pg_fetch_all($this->execQuery($sql)) ?: []; }
    public function upsertRowOnConflict(string $table, array $row, string $key): void
    {
        if ($table !== 'chim_meta.settings' || $key !== 'key') throw new RuntimeException('Invalid backup settings table.');
        $result = @pg_query_params($this->conn, 'INSERT INTO chim_meta.settings(key,value) VALUES($1,$2) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$row['key'], $row['value']]);
        if (!$result) throw new RuntimeException('Could not save backup settings.');
    }
}
