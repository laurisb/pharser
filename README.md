# PHARser - Fast OpenStreetMap Node Importer for MySQL

PHARser is a fast and efficient tool that extracts node coordinates and tag data from OpenStreetMap PBF files and loads them into a MySQL database, enabling location-based searches and geospatial and statistical analyses using SQL queries.

It works with full Planet OSM datasets and regional extracts.

## How it works

PHARser runs in three stages:

* **Parsing** - Reads the compressed map data file and extracts node coordinates and tag data; each worker thread writes directly to its own pair of CSV files, with no shared state between workers
* **Importing** - Loads node and tag CSV files into the database; nodes and tags are imported concurrently in separate threads
* **Indexing** - Builds node and tag indexes in parallel, then removes temporary files

This creates two database tables:

* `node` - Contains node ID, latitude and longitude coordinates
* `tag` - Contains node ID, tag name and value

## Requirements

### Software requirements

* **Operating system** - Windows, Linux or macOS
* **PHP** - PHP 8.5 is recommended, with `parallel` and `pdo_mysql` extensions enabled
* **MySQL** - MySQL 9 is recommended, with local data loading enabled (`local_infile=1`)

### Data requirements

Download OpenStreetMap data files from:

* [Planet OpenStreetMap](https://planet.openstreetmap.org/) - Complete world data (very large, 80+ GB)
* [Geofabrik](https://download.geofabrik.de/) - Regional extracts (smaller, continent/country/state-specific)

### Storage requirements

Ensure sufficient disk space for:

* The original PBF file
* Temporary files during processing
* Final database tables

## Installation

Download the latest `pharser.phar` from the [releases page](https://github.com/laurisb/pharser/releases/latest).

```bash
# Download the PHAR file
curl -L -o pharser.phar https://github.com/laurisb/pharser/releases/latest/download/pharser.phar

# Make it executable (for Linux/macOS users)
chmod +x pharser.phar

# Verify installation
php pharser.phar --version
```

## Usage

### Basic usage

```bash
php pharser.phar --db-host=localhost --db-port=3306 --db-user=root --db-pass=hunter2 --db-name=osm /path/to/file.osm.pbf
```

The `--threads` option increases parallelism (default: 2):

```bash
php pharser.phar --threads=4 /path/to/file.osm.pbf
```

The `--skip-indexing` option skips index creation (useful when indexes will be added later):

```bash
php pharser.phar --skip-indexing /path/to/file.osm.pbf
```

### Advanced options

```bash
php pharser.phar --help
```

## Data analysis examples

### Statistical analysis

```sql
-- Count how many times each tag name appears
CREATE TABLE `tag_name_summary` (
    `name` VARCHAR(50)  NOT NULL,
    `cnt` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`name`)
);

INSERT INTO `tag_name_summary`
SELECT `name`, COUNT(`node_id`)
FROM `tag`
GROUP BY `name`;
```

```sql
-- Count how many times each exact name/value pair appears
CREATE TABLE `tag_name_value_summary` (
    `name` VARCHAR(50)  NOT NULL,
    `value` VARCHAR(200) NOT NULL,
    `cnt` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`name`, `value`)
);

INSERT INTO `tag_name_value_summary`
SELECT `name`, `value`, COUNT(`node_id`)
FROM `tag`
GROUP BY `name`, `value`;
```

```sql
-- Count how many times each tag value appears, regardless of name
CREATE TABLE `tag_value_summary` (
    `value` VARCHAR(200) NOT NULL,
    `cnt` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`value`)
);

INSERT INTO `tag_value_summary`
SELECT `value`, COUNT(`node_id`)
FROM `tag`
GROUP BY `value`;
```

```sql
-- For each tag name, show how often each value appears and what share of that name it represents
CREATE TABLE `tag_value_distribution_summary` (
    `name` VARCHAR(50)  NOT NULL,
    `value` VARCHAR(200) NOT NULL,
    `cnt` INT UNSIGNED NOT NULL,
    `pct` DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (`name`, `value`),
    INDEX `idx_name_pct` (`name`, `pct`)
);

INSERT INTO tag_value_distribution_summary
SELECT
    `name`,
    `value`,
    COUNT(`node_id`) AS `cnt`,
    ROUND(
        COUNT(`node_id`) * 100.00
        / SUM(COUNT(`node_id`)) OVER (PARTITION BY `name`),
        2
    ) AS `pct`
FROM `tag`
GROUP BY `name`, `value`;
```

```sql
-- Group tag names that contain ':' by the part before ':' and count usage
CREATE TABLE `tag_namespace_summary` (
    `namespace` VARCHAR(50)  NOT NULL,
    `distinct_names` INT UNSIGNED NOT NULL,
    `total_uses` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`namespace`)
);

INSERT INTO `tag_namespace_summary`
SELECT
    SUBSTRING_INDEX(`name`, ':', 1) AS `namespace`,
    COUNT(DISTINCT `name`) AS `distinct_names`,
    COUNT(*) AS `total_uses`
FROM `tag`
WHERE `name` LIKE '%:%'
GROUP BY `namespace`;
```

```sql
-- Count how many tags each node has
CREATE TABLE `node_tag_count_summary` (
    `node_id` BIGINT UNSIGNED NOT NULL,
    `tag_count` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`node_id`),
    INDEX `idx_count` (`tag_count`)
);

INSERT INTO `node_tag_count_summary`
SELECT
    `node_id`,
    COUNT(*) AS `tag_count`
FROM `tag`
GROUP BY `node_id`;
```

### Location-based queries

```sql
-- All tags for restaurant nodes in Manhattan, New York
SELECT `node_id`, `name`, `value`
FROM `tag`
WHERE `node_id` IN (
    SELECT `node_id`
    FROM `tag`
    WHERE `name` = 'amenity' AND `value` = 'restaurant'
) AND `node_id` IN (
    SELECT `node_id`
    FROM `node`
    WHERE `lat` BETWEEN 40.7000 AND 40.8000 AND `lon` BETWEEN -74.0200 AND -73.9300
)
ORDER BY `node_id` ASC, `name` ASC;
```

The example above uses standard coordinate range queries on lat and lon instead of spatial functions and spatial indexes. PHARser omits spatial columns and indexes by default because they increase import time and storage overhead; they can be added later when the workload benefits from them.

If high-performance spatial queries are needed:

* Add a `geom` column `ALTER TABLE node ADD COLUMN geom POINT SRID 4326 NULL;`
* Backfill it with `UPDATE node SET geom = ST_SRID(POINT(lon, lat), 4326);`
* Add a spatial index: `ALTER TABLE node ADD SPATIAL INDEX idx_geom (geom);`
* Then use [spatial functions](https://dev.mysql.com/doc/refman/9.6/en/spatial-function-reference.html) in queries

## Performance optimization

PHARser works faster on newer, more powerful computers. Processing time depends on:

* **File size** - Larger regions take longer
* **Computer speed** - Newer CPUs process faster
* **Available memory** - More RAM speeds up database operations

Optimize performance by configuring:

* **PHP** - Increase memory limit, enable `opcache`, disable `xdebug` and unnecessary extensions
* **MySQL** - Tune InnoDB buffer pool, optimize I/O threads, increase redo log capacity, disable the doublewrite buffer and binary logging

## Benchmarks

Import and indexing times vary significantly based on hardware, database configuration, and file size.

The figures below are for reference only and were measured in March 2026 on a PC with 24 CPU threads and 64 GB of RAM, running Windows 11.

| Region          | File size | Parsing time | Import time | Indexing time | Total time | Nodes       | Tags        |
|-----------------|-----------|--------------|-------------| --------------|------------|-------------|-------------|
| **Latvia**      | 130 MB    | 1s           | 16s         | 12s           | 30s        | 762,893     | 3,131,971   |
| **Germany**     | 4.4 GB    | 1m 6s        | 7m 25s      | 5m 47s        | 14m 18s    | 21,210,917  | 78,603,353  |
| **Europe**      | 31.7 GB   | 10m 31s      | 50m 19s     | 50m 26s       | 1h 51m 17s | 149,112,705 | 510,720,116 |
| **Planet OSM**  | 85.4 GB   | 29m 40s      | 1h 33m 50s  | 1h 40m 41s    | 3h 41m 39s | 283,656,315 | 981,956,523 |

## Technical notes

* **Data format** - Works only with standard OpenStreetMap PBF files
* **Compression** - Files must use zlib compression (standard for OSM data)
* **Protobuf parsing** - Uses a custom binary protobuf parser tuned for the OSM PBF schema
* **File quality** - PBF files must be well-formed (not corrupted)

## Changelog

* **2026-03-26** - Parser overhaul `v2.0.0`
* **2025-07-03** - Initial release `v1.0.0`

## License

[MIT](LICENSE.md)
