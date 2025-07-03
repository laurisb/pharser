<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Importer
{
    private PDO $pdo;

    public function __construct(
        private Logger $logger,
        private string $nodeCsvFile,
        private string $tagCsvFile,
        string $dbHost,
        int $dbPort,
        string $dbUser,
        string $dbPass,
        string $dbName,
    ) {
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

        $this->pdo = new PDO(
            dsn: $dsn,
            username: $dbUser,
            password: $dbPass,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_LOCAL_INFILE => true,
            ],
        );
    }

    public function createTables(): void
    {
        $this->logger->__invoke('Creating tables');

        $this->pdo->prepare('DROP TABLE IF EXISTS `node`')->execute();
        $this->pdo->prepare('DROP TABLE IF EXISTS `tag`')->execute();

        $this->pdo->prepare(<<<SQL
CREATE TABLE `node` (
    `nodeId` BIGINT UNSIGNED NOT NULL,
    `lat` DECIMAL(8, 6) NOT NULL,
    `lon` DECIMAL(9, 6) NOT NULL
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL)->execute();

        $this->pdo->prepare(<<<SQL
CREATE TABLE `tag` (
    `nodeId` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `value` VARCHAR(200) NOT NULL
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL)->execute();
    }

    public function import(): void
    {
        $this->logger->__invoke('Importing nodes');

        $this->pdo->exec(<<<SQL
LOAD DATA LOCAL INFILE '{$this->nodeCsvFile}'
INTO TABLE `node`
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
SQL);

        $this->logger->__invoke('Importing tags');

        $this->pdo->exec(<<<SQL
LOAD DATA LOCAL INFILE '{$this->tagCsvFile}'
INTO TABLE `tag`
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
SQL);

        $this->logger->__invoke('Import completed');
    }

    public function index(): void
    {
        $this->logger->__invoke('Creating index: 1/4');

        $this->pdo->prepare(<<<SQL
ALTER TABLE `node`
ADD PRIMARY KEY (`nodeId`)
SQL)->execute();

        $this->logger->__invoke('Creating index: 2/4');

        $this->pdo->prepare(<<<SQL
ALTER TABLE `node`
ADD KEY idx_lat_lon (`lat`, `lon`);
SQL)->execute();

        $this->logger->__invoke('Creating index: 3/4');

        $this->pdo->prepare(<<<SQL
ALTER TABLE `tag`
ADD KEY idx_name_value_nodeid (`name`, `value`, `nodeId`);
SQL)->execute();

        $this->logger->__invoke('Creating index: 4/4');

        $this->pdo->prepare(<<<SQL
ALTER TABLE `tag`
ADD KEY idx_nodeid_name_value (`nodeId`, `name`, `value`);
SQL)->execute();

        $this->logger->__invoke('Indexing completed');
    }
}
