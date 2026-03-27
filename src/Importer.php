<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use parallel\Runtime;
use PDO;
use Pdo\Mysql;

use function preg_match;
use function str_replace;
use function unlink;

final class Importer
{
    private string $dsn;
    private string $dsnBase;

    public function __construct(
        private Logger $logger,
        string $dbHost,
        int $dbPort,
        private string $dbUser,
        private string $dbPass,
        private string $dbName,
    ) {
        if (preg_match('/[^a-zA-Z0-9_]/', $dbName) === 1) {
            throw new InvalidArgumentException(
                'Database name must contain only alphanumeric characters and underscores',
            );
        }

        $this->dsnBase = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
        $this->dsn = "{$this->dsnBase};dbname={$dbName}";
    }

    public function createDatabase(): void
    {
        $pdo = new PDO(
            dsn: $this->dsnBase,
            username: $this->dbUser,
            password: $this->dbPass,
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $name = $this->dbName;

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->logger->__invoke("Database '{$name}' ready");
    }

    public function createTables(): void
    {
        $this->logger->__invoke('Creating tables');

        $pdo = new PDO(
            dsn: $this->dsn,
            username: $this->dbUser,
            password: $this->dbPass,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                Mysql::ATTR_LOCAL_INFILE => true,
            ],
        );

        $pdo->prepare('DROP TABLE IF EXISTS `node`')->execute();
        $pdo->prepare('DROP TABLE IF EXISTS `tag`')->execute();

        $pdo->prepare(<<<SQL
CREATE TABLE `node` (
    `node_id` BIGINT UNSIGNED NOT NULL,
    `lat` DECIMAL(8, 6) NOT NULL,
    `lon` DECIMAL(9, 6) NOT NULL
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL)->execute();

        $pdo->prepare(<<<SQL
CREATE TABLE `tag` (
    `node_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `value` VARCHAR(200) NOT NULL
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL)->execute();
    }

    /**
     * @param list<string> $nodeFiles
     * @param list<string> $tagFiles
     */
    public function import(
        array $nodeFiles,
        array $tagFiles,
    ): void {
        $this->logger->__invoke('Importing nodes and tags');

        $importFn = static function (string $dsn, string $user, string $pass, string $table, array $files): void {
            $pdo = new PDO(
                dsn: $dsn,
                username: $user,
                password: $pass,
                options: [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    Mysql::ATTR_LOCAL_INFILE => true,
                ],
            );

            foreach ($files as $file) {
                $escapedFile = str_replace("'", "\\'", $file);

                $pdo->exec(<<<SQL
LOAD DATA LOCAL INFILE '{$escapedFile}'
INTO TABLE `{$table}`
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
SQL);

                unlink($file);
            }
        };

        $nodeRuntime = new Runtime();
        $tagRuntime = new Runtime();

        $nodeFuture = $nodeRuntime->run($importFn, [$this->dsn, $this->dbUser, $this->dbPass, 'node', $nodeFiles]);
        $tagFuture = $tagRuntime->run($importFn, [$this->dsn, $this->dbUser, $this->dbPass, 'tag', $tagFiles]);

        $nodeFuture?->value();
        $tagFuture?->value();

        $this->logger->__invoke('Import completed');
    }

    public function index(): void
    {
        $this->logger->__invoke('Creating indexes');

        $indexFn = static function (string $dsn, string $user, string $pass, string $sql): void {
            $pdo = new PDO(
                dsn: $dsn,
                username: $user,
                password: $pass,
                options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            $pdo->prepare($sql)->execute();
        };

        $nodeRuntime = new Runtime();
        $tagRuntime = new Runtime();

        $nodeFuture = $nodeRuntime->run($indexFn, [$this->dsn, $this->dbUser, $this->dbPass, <<<SQL
ALTER TABLE `node`
ADD PRIMARY KEY (`node_id`),
ADD KEY idx_lat_lon (`lat`, `lon`)
SQL]);

        $tagFuture = $tagRuntime->run($indexFn, [$this->dsn, $this->dbUser, $this->dbPass, <<<SQL
ALTER TABLE `tag`
ADD KEY `idx_node_id` (`node_id`),
ADD KEY `idx_name_value_node_id` (`name`, `value`, `node_id`)
SQL]);

        $nodeFuture?->value();
        $tagFuture?->value();

        $this->logger->__invoke('Indexing completed');
    }
}
