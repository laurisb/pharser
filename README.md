# PHARser - Fast OpenStreetMap Node Importer for MySQL

PHARser is a fast and efficient tool that extracts node coordinates and tag data from OpenStreetMap PBF files and loads them into MySQL database, enabling location-based searches, geographic and statistical analysis using SQL queries.

Works with full Planet OSM datasets and regional extracts.

## How it works

PHARser works in three steps:

* **Parsing** - Reads the compressed map data file and extracts node coordinates and associated tag data
* **Importing** - Loads the extracted data into the database
* **Indexing** - Optimizes the database for optimal query performance

This creates two database tables:

* `node` - Contains node ID, latitude and longitude coordinates
* `tag` - Contains node ID, tag key and value

## Requirements

### Software requirements

* **Operating system** - Windows, Linux or macOS
* **PHP** - Latest PHP (version 8.4) is recommended, with `protobuf`, `parallel` and `pdo_mysql` extensions enabled
* **MySQL** - Latest MySQL (version 9) is recommended, with local data loading enabled (`local_infile=1`)

### Data requirements

Download OpenStreetMap data files from:

* [Planet OpenStreetMap](https://planet.openstreetmap.org/) - Complete world data (very large, 80+ GB)
* [Geofabrik](https://download.geofabrik.de/) - Regional extracts (smaller, continent/country/state specific)

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

Create a database:

```sql
CREATE DATABASE `osm` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## Usage

### Basic usage

```bash
php pharser.phar /path/to/file.osm.pbf --db-host=localhost --db-port=3306 --db-user=root --db-pass=hunter2 --db-name=osm
```

### Advanced options

```bash
php pharser.phar --help
```

## Data analysis examples

### Statistical analysis

```sql
-- Find the most common tags
SELECT `name`, COUNT(*) AS `cnt`
FROM `tag`
GROUP BY `name`
ORDER BY `cnt` DESC, `name` ASC;
```

```sql
-- Find all possible values for a specific tag
SELECT `value`, COUNT(*) AS `cnt`
FROM `tag`
WHERE `name` = 'amenity'
GROUP BY `value`
ORDER BY `cnt` DESC, `value` ASC;
```

### Location-based queries

```sql
-- Restaurants in Manhattan, New York
SELECT `nodeId`, `name`, `value`
FROM `tag`
WHERE `nodeId` IN (
    SELECT `nodeId`
    FROM `tag`
    WHERE `name` = 'amenity' AND `value` = 'restaurant'
) AND `nodeId` IN (
    SELECT `nodeId`
    FROM `node`
    WHERE `lat` BETWEEN 40.7000 AND 40.8000 AND `lon` BETWEEN -74.0200 AND -73.9300
)
ORDER BY `nodeId` ASC, `name` ASC;
```

## Performance optimization

PHARser works faster on newer, more powerful computers. Processing time depends on:

* **File size** - Larger regions take longer
* **Computer speed** - Newer CPUs process faster
* **Available memory** - More RAM speeds up database operations

Optimize performance by configuring:

* **PHP** - Increase memory limit, enable `opcache`, disable `xdebug` and unnecessary extensions
* **MySQL** - Tune InnoDB buffer pool, optimize I/O threads, increase redo log capacity, disable double-write buffer and binary logging

## Benchmarks

Reference benchmarks for the parsing stage:

| Region          | File Size | Processing Time |
|-----------------|-----------|-----------------|
| **Latvia**      | 125 MB    | 6 seconds       |
| **Netherlands** | 1 GB      | 1.5 minutes     |
| **Germany**     | 4 GB      | 3 minutes       |
| **USA**         | 10 GB     | 8 minutes       |
| **Europe**      | 30 GB     | 25 minutes      |
| **Planet OSM**  | 80 GB     | 1 hour          |

Import and indexing times vary greatly depending on hardware, database configuration, and file size.

```log
16:23:06.721 Starting to parse: C:/Users/Lauris/Desktop/latvia-latest.osm.pbf
16:23:06.727 Using 3 threads, batch size 10000, max memory 512MB [+0.006s]
16:23:06.730 Runtime pool initialized with 3 workers [+0.003s]
16:23:06.731 OSM header found [+0.001s]
16:23:06.731 Header skipped, starting to parse blocks
16:23:09.568 Processed 1000 blocks, 310737 nodes, 920302 tags, 7649 pending nodes [+2.837s]
16:23:13.096 Reached ways/relations at block 1855, stop reading file [+3.528s]
16:23:13.138 Parsing complete [+0.042s]
16:23:13.139 Total blocks read from file: 1855
16:23:13.139 Total blocks queued for processing: 1854
16:23:13.139 Processed blocks: 1854
16:23:13.139 Processed nodes: 729923
16:23:13.139 Processed tags: 3034263
16:23:13.139 Nodes per second: 113924
16:23:13.139 Peak memory usage: 62MB
16:23:13.139 Parsing time: 6 seconds
16:23:13.139 All queued blocks processed successfully
16:23:13.140 Creating tables
16:23:13.173 Importing nodes [+0.033s]
16:23:16.930 Importing tags [+3.757s]
16:23:29.552 Import completed [+12.623s]
16:23:29.553 Cleaning up
16:23:29.553 Creating index: 1/4
16:23:30.942 Creating index: 2/4 [+1.389s]
16:23:32.161 Creating index: 3/4 [+1.219s]
16:23:44.032 Creating index: 4/4 [+11.871s]
16:23:49.317 Indexing completed [+5.285s]
```

## Technical notes

* **Data format** - Only works with standard OpenStreetMap PBF files
* **Compression** - Files must use zlib compression (standard for OSM data)
* **File quality** - PBF files must be well-formed (not corrupted)
* **Error handling** - Minimal error handling for maximum performance

## Changelog

* **2025-07-03** - Initial release `v1.0.0`

## License

[MIT](LICENSE.md)
