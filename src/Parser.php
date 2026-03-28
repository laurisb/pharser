<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use parallel\Channel;
use parallel\Channel\Error\Closed;
use parallel\Runtime;
use parallel\Sync;
use RuntimeException;
use Throwable;

use function count;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function gzuncompress;
use function is_file;
use function is_resource;
use function is_string;
use function max;
use function mb_convert_encoding;
use function mb_substr;
use function memory_get_peak_usage;
use function microtime;
use function number_format;
use function round;
use function str_replace;
use function strlen;
use function uniqid;
use function unpack;

final class Parser
{
    /** @var resource */
    private $file;

    /** @var list<string> */
    private array $nodeCsvFiles = [];

    /** @var list<string> */
    private array $tagCsvFiles = [];

    public function __construct(
        string $pbfFile,
        private string $nodeCsvBase,
        private string $tagCsvBase,
        private Logger $logger,
        private int $numThreads,
        private bool $skipMetadata,
    ) {
        if (!is_file($pbfFile)) {
            throw new InvalidArgumentException("PBF file not found: {$pbfFile}");
        }

        $fp = fopen($pbfFile, 'rb');

        if ($fp === false) {
            throw new RuntimeException("Failed to open PBF file: {$pbfFile}");
        }

        $this->file = $fp;

        $this->logger->__invoke('Using ' . number_format($numThreads) . " parser threads");
    }

    public function __destruct()
    {
        if (!is_resource($this->file)) {
            return;
        }

        fclose($this->file);
    }

    /**
     * @return list<string>
     **/
    public function getNodeCsvFiles(): array
    {
        return $this->nodeCsvFiles;
    }

    /**
     * @return list<string>
     **/
    public function getTagCsvFiles(): array
    {
        return $this->tagCsvFiles;
    }

    public function parse(): void
    {
        $channelId = uniqid('pharser_', true);
        $blockChannel = Channel::make("{$channelId}_blocks", $this->numThreads * 16);
        $stopFlag = new Sync(false);
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        $numThreads = $this->numThreads;
        $skipMetadata = $this->skipMetadata;
        $nodeCsvFiles = [];
        $tagCsvFiles = [];

        for ($i = 0; $i < $numThreads; $i++) {
            $nodeCsvFiles[] = "{$this->nodeCsvBase}.{$i}";
            $tagCsvFiles[] = "{$this->tagCsvBase}.{$i}";

            $this->nodeCsvFiles[] = $nodeCsvFiles[$i];
            $this->tagCsvFiles[] = $tagCsvFiles[$i];
        }

        $workerFutures = [];

        for ($i = 0; $i < $numThreads; $i++) {
            $runtime = new Runtime($autoloadPath);

            $workerFutures[] = $runtime->run(
                static function (
                    Channel $blockCh,
                    Sync $stop,
                    string $nodeCsvPath,
                    string $tagCsvPath,
                    bool $skipMetadata,
                ): array {
                    $fpNodes = fopen($nodeCsvPath, 'w');
                    $fpTags = fopen($tagCsvPath, 'w');

                    $processedBlocks = 0;
                    $processedNodes = 0;
                    $processedTags = 0;
                    $skippedTags = 0;
                    $errors = 0;
                    $waysFound = false;

                    while (true) {
                        try {
                            $blobData = $blockCh->recv();
                        } catch (Closed) {
                            break;
                        } catch (Throwable) {
                            $errors++;

                            break;
                        }

                        if ($blobData === null) {
                            break;
                        }

                        if ($waysFound) {
                            continue;
                        }

                        if (!is_string($blobData)) {
                            continue;
                        }

                        $zlibData = BinaryParser::extractZlibData($blobData);

                        unset($blobData);

                        $rawData = gzuncompress($zlibData);

                        unset($zlibData);

                        if ($rawData === false) {
                            continue;
                        }

                        $block = BinaryParser::parsePrimitiveBlock($rawData);

                        unset($rawData);

                        $hasWays = false;
                        $strings = $block['strings'];
                        $stCount = count($strings);
                        $stringsKey = [];
                        $stringsVal = [];

                        foreach ($strings as $s) {
                            $clean = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
                            $stringsKey[] = mb_substr($clean, 0, 50, 'UTF-8');

                            $stringsVal[] = str_replace(
                                ["\n", "\t"],
                                [' ', ' '],
                                mb_substr($clean, 0, 200, 'UTF-8'),
                            );
                        }

                        unset($strings);

                        $granularity = $block['granularity'];
                        $latOffset = $block['lat_offset'];
                        $lonOffset = $block['lon_offset'];
                        $nodeBuffer = '';
                        $tagBuffer = '';

                        foreach ($block['groups'] as $group) {
                            if ($group['type'] === 'ways' || $group['type'] === 'relations') {
                                $hasWays = true;

                                break;
                            }

                            if ($group['type'] !== 'dense') {
                                continue;
                            }

                            $ids = $group['ids'];
                            $lats = $group['lats'];
                            $lons = $group['lons'];
                            $keysVals = $group['keysVals'];
                            $nodeCount = count($ids);
                            $keysValsCount = count($keysVals);
                            $id = 0;
                            $lat = 0;
                            $lon = 0;
                            $keysValsIdx = 0;

                            for ($n = 0; $n < $nodeCount; $n++) {
                                $id += $ids[$n];
                                $lat += $lats[$n];
                                $lon += $lons[$n];

                                $tagCount = 0;
                                $tagPart = '';

                                while ($keysValsIdx < $keysValsCount && $keysVals[$keysValsIdx] !== 0) {
                                    $keyIdx = $keysVals[$keysValsIdx++];
                                    $valIdx = $keysValsIdx < $keysValsCount ? $keysVals[$keysValsIdx++] : 0;

                                    if ($keyIdx >= $stCount || $valIdx >= $stCount) {
                                        continue;
                                    }

                                    if ($skipMetadata && TagFilter::shouldSkip($stringsKey[$keyIdx])) {
                                        $skippedTags++;

                                        continue;
                                    }

                                    $tagCount++;
                                    $tagPart .= "{$id}\t{$stringsKey[$keyIdx]}\t{$stringsVal[$valIdx]}\n";
                                }

                                if ($keysValsIdx < $keysValsCount) {
                                    $keysValsIdx++;
                                }

                                if ($tagCount <= 0) {
                                    continue;
                                }

                                $processedNodes++;
                                $processedTags += $tagCount;
                                $nodeLat = ($latOffset + ($granularity * $lat)) * 1e-9;
                                $nodeLon = ($lonOffset + ($granularity * $lon)) * 1e-9;
                                $nodeBuffer .= "{$id}\t{$nodeLat}\t{$nodeLon}\n";
                                $tagBuffer .= $tagPart;
                            }
                        }

                        if ($nodeBuffer !== '' && is_resource($fpNodes)) {
                            fwrite($fpNodes, $nodeBuffer);
                        }

                        if ($tagBuffer !== '' && is_resource($fpTags)) {
                            fwrite($fpTags, $tagBuffer);
                        }

                        unset($block);

                        if ($hasWays) {
                            $stop->set(true);

                            $waysFound = true;

                            continue;
                        }

                        $processedBlocks++;
                    }

                    if (is_resource($fpNodes) && is_resource($fpTags)) {
                        fclose($fpNodes);
                        fclose($fpTags);
                    }

                    return [
                        'blocks' => $processedBlocks,
                        'nodes' => $processedNodes,
                        'tags' => $processedTags,
                        'skippedTags' => $skippedTags,
                        'errors' => $errors,
                    ];
                },
                [$blockChannel, $stopFlag, $nodeCsvFiles[$i], $tagCsvFiles[$i], $skipMetadata],
            );
        }

        $this->skipHeader();

        $this->logger->__invoke('Header skipped, starting to parse blocks');

        $startTime = microtime(true);

        $totalBlocksRead = 0;
        $lastProgressTime = $startTime;

        while (!feof($this->file)) {
            if ((bool) $stopFlag->get()) {
                $this->logger->__invoke(
                    'Reached ways/relations at block ' . number_format($totalBlocksRead) . ', stop reading file',
                );
                break;
            }

            $header = $this->readBlobHeader();

            if ($header === null) {
                break;
            }

            $blobData = $this->readBlob($header['datasize']);

            if ($blobData === null) {
                break;
            }

            $totalBlocksRead++;

            $blockChannel->send($blobData);

            $now = microtime(true);

            if ($now - $lastProgressTime < 30.0) {
                continue;
            }

            $lastProgressTime = $now;
            $this->logger->__invoke('Read ' . number_format($totalBlocksRead) . ' blocks from file');
        }

        $blockChannel->close();

        $processedBlocks = 0;
        $processedNodes = 0;
        $processedTags = 0;
        $skippedTags = 0;
        $processedErrors = 0;

        foreach ($workerFutures as $future) {
            if ($future === null) {
                continue;
            }

            /** @var array{blocks: int, nodes: int, tags: int, skippedTags: int, errors: int} $stats */
            $stats = $future->value();
            $processedBlocks += $stats['blocks'];
            $processedNodes += $stats['nodes'];
            $processedTags += $stats['tags'];
            $skippedTags += $stats['skippedTags'];
            $processedErrors += $stats['errors'];
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $nodesPerSecond = number_format(round($processedNodes / max($duration, 0.001)));
        $peakMemory = number_format(memory_get_peak_usage(true) / 1_024 / 1_024, 1);

        $this->logger->__invoke('Parsing complete');
        $this->logger->__invoke('Blocks processed: ' . number_format($processedBlocks));
        $this->logger->__invoke('Nodes: ' . number_format($processedNodes));
        $this->logger->__invoke('Tags: ' . number_format($processedTags));

        if ($skippedTags > 0) {
            $this->logger->__invoke('Skipped tags: ' . number_format($skippedTags));
        }

        $this->logger->__invoke('Nodes per second: ' . $nodesPerSecond);
        $this->logger->__invoke('Peak memory: ' . $peakMemory . ' MB');
        $this->logger->__invoke('Worker errors: ' . number_format($processedErrors));
        $this->logger->__invoke('Parsing time: ' . number_format(round($duration)) . ' seconds');
    }

    private function skipHeader(): void
    {
        $header = $this->readBlobHeader();

        if ($header === null || $header['type'] !== 'OSMHeader') {
            throw new RuntimeException('Failed to read OSM header');
        }

        $this->readBlob($header['datasize']);

        $this->logger->__invoke('OSM header found');
    }

    /**
     * @return array{type: string, datasize: int}|null
     */
    private function readBlobHeader(): ?array
    {
        $headerSizeData = fread($this->file, 4);

        if ($headerSizeData === false || strlen($headerSizeData) < 4) {
            return null;
        }

        $unpacked = unpack('N', $headerSizeData);

        if ($unpacked === false) {
            return null;
        }

        /** @var int<1, max> $headerSize */
        $headerSize = $unpacked[1];
        $headerData = fread($this->file, $headerSize);

        if ($headerData === false || strlen($headerData) < $headerSize) {
            return null;
        }

        return BinaryParser::parseBlobHeader($headerData);
    }

    private function readBlob(int $size): ?string
    {
        if ($size < 1) {
            return null;
        }

        $blobData = fread($this->file, $size);

        if ($blobData === false || strlen($blobData) < $size) {
            return null;
        }

        return $blobData;
    }
}
