<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use OSMPBF\Blob;
use OSMPBF\BlobHeader;
use OSMPBF\PrimitiveBlock;
use parallel\Future;
use parallel\Runtime;
use RuntimeException;

use function array_merge;
use function array_shift;
use function array_values;
use function count;
use function feof;
use function fopen;
use function fread;
use function gzuncompress;
use function is_file;
use function memory_get_peak_usage;
use function microtime;
use function number_format;
use function range;
use function round;
use function strlen;
use function unpack;

final class Parser
{
    /** @var resource */
    private $file;

    private int $maxMemoryUsage;

    /**
     * @var array<int, array{future: Future, runtime_index: int}>
     */
    private array $futures = [];

    private int $processedBlocks = 0;
    private int $processedNodes = 0;
    private int $processedTags = 0;

    /**
     * @var Runtime[]
     */
    private array $runtimePool = [];

    /**
     * @var array<int, array{id: int, lat: float, lon: float, tags: array<string, string>}>
     */
    private array $pendingNodes = [];

    private int $currentMemoryUsage = 0;

    public function __construct(
        string $pbfFile,
        private Writer $writer,
        private Logger $logger,
        private int $numThreads,
        private int $batchSize = 10_000,
        int $maxMemoryMB = 512,
    ) {
        if (!is_file($pbfFile)) {
            throw new InvalidArgumentException("PBF file not found: {$pbfFile}");
        }

        $fp = fopen($pbfFile, 'rb');

        if ($fp === false) {
            throw new RuntimeException("Failed to open PBF file: {$pbfFile}");
        }

        $this->file = $fp;
        $this->maxMemoryUsage = $maxMemoryMB * 1_024 * 1_024;

        $this->logger->__invoke("Using {$numThreads} threads, batch size {$batchSize}, max memory {$maxMemoryMB}MB");

        for ($i = 0; $i < $this->numThreads; $i++) {
            $this->runtimePool[] = new Runtime(__DIR__ . '/../vendor/autoload.php');
        }

        $this->logger->__invoke("Runtime pool initialized with {$this->numThreads} workers");
    }

    public function parse(): void
    {
        $this->skipHeader();

        $this->logger->__invoke('Header skipped, starting to parse blocks');

        $startTime = microtime(true);

        /** @var string[] */
        $blockQueue = [];

        $activeJobs = 0;
        $maxQueueSize = $this->numThreads * 2;
        $totalBlocksRead = 0;
        $blocksQueued = 0;
        $fileReadingComplete = false;

        /**
         * @var int[]
         */
        $availableRuntimes = range(0, $this->numThreads - 1);

        $runtimeInUse = [];

        while (true) {
            // STEP 1: Read blocks from file, with memory pressure check
            if (!$fileReadingComplete && $this->currentMemoryUsage < $this->maxMemoryUsage) {
                $blocksReadThisIteration = 0;
                $maxBlocksPerIteration = 50;

                while (
                    !feof($this->file)
                    && count($blockQueue) < $maxQueueSize
                    && $blocksReadThisIteration < $maxBlocksPerIteration
                    && $this->currentMemoryUsage < $this->maxMemoryUsage
                ) {
                    $header = $this->readBlobHeader();
                    $blob = $this->readBlob($header->getDatasize());
                    $totalBlocksRead++;
                    $blocksReadThisIteration++;

                    if (!$this->blockContainsNodes($blob)) {
                        $this->logger->__invoke("Reached ways/relations at block {$totalBlocksRead}, stop reading file");

                        $fileReadingComplete = true;

                        break;
                    }

                    $serializedBlob = $blob->serializeToString();
                    $blockQueue[] = $serializedBlob;
                    $this->currentMemoryUsage += strlen($serializedBlob);
                }
            }

            // STEP 2: Start new jobs with runtime reuse
            while (count($blockQueue) > 0 && count($availableRuntimes) > 0) {
                $blobData = array_shift($blockQueue);
                $this->currentMemoryUsage -= strlen($blobData);

                $runtimeIndex = array_shift($availableRuntimes);
                $runtime = $this->runtimePool[$runtimeIndex];

                $future = $this->processBlockAsyncWithRuntime($blobData, $runtime);
                $this->futures[] = ['future' => $future, 'runtime_index' => $runtimeIndex];
                $activeJobs++;
                $blocksQueued++;
                $runtimeInUse[$runtimeIndex] = true;
            }

            // STEP 3: Collect completed results (non-blocking)
            $this->collectCompletedResults($activeJobs, $availableRuntimes, $runtimeInUse);

            // STEP 4: Batch write nodes if we have enough
            if (count($this->pendingNodes) >= $this->batchSize) {
                $this->flushPendingNodes();
            }

            // STEP 5: Check if we should continue
            if ($fileReadingComplete && count($blockQueue) === 0 && $activeJobs === 0) {
                break;
            }
        }

        $this->flushPendingNodes();

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        $this->logger->__invoke('Parsing complete');
        $this->logger->__invoke("Total blocks read from file: {$totalBlocksRead}");
        $this->logger->__invoke("Total blocks queued for processing: {$blocksQueued}");
        $this->logger->__invoke("Processed blocks: {$this->processedBlocks}");
        $this->logger->__invoke("Processed nodes: {$this->processedNodes}");
        $this->logger->__invoke("Processed tags: {$this->processedTags}");
        $this->logger->__invoke('Nodes per second: ' . round($this->processedNodes / $duration));
        $this->logger->__invoke('Peak memory usage: ' . round(memory_get_peak_usage(true) / 1_024 / 1_024, 1) . 'MB');
        $this->logger->__invoke('Parsing time: ' . round($duration) . ' seconds');

        if ($this->processedBlocks !== $blocksQueued) {
            throw new RuntimeException("Processed blocks ({$this->processedBlocks}) != Queued blocks ({$blocksQueued})");
        }

        $this->logger->__invoke('All queued blocks processed successfully');
    }

    private function processBlockAsyncWithRuntime(
        string $blobData,
        Runtime $runtime,
    ): Future {
        return $runtime->run(function (string $serializedBlob): array {
            $nodes = [];

            $blob = new Blob();
            $blob->mergeFromString($serializedBlob);

            $data = gzuncompress($blob->getZlibData());

            $primitiveBlock = new PrimitiveBlock();
            $primitiveBlock->mergeFromString($data);

            $granularity = $primitiveBlock->getGranularity() ?: 100;
            $latOffset = $primitiveBlock->getLatOffset() ?: 0;
            $lonOffset = $primitiveBlock->getLonOffset() ?: 0;
            $stringTable = $primitiveBlock->getStringtable()->getS();
            $stringTableCount = count($stringTable ?? []);

            foreach ($primitiveBlock->getPrimitivegroup() as $group) {
                $denseNodes = $group->getDense();
                $ids = $denseNodes->getId();
                $lats = $denseNodes->getLat();
                $lons = $denseNodes->getLon();
                $keysVals = $denseNodes->getKeysVals();

                $nodeCount = count($ids);
                $keysValsCount = count($keysVals);

                $id = 0;
                $lat = 0;
                $lon = 0;
                $keysValsIndex = 0;

                for ($i = 0; $i < $nodeCount; $i++) {
                    $id += $ids[$i];
                    $lat += $lats[$i];
                    $lon += $lons[$i];

                    $nodeLat = ($latOffset + ($granularity * $lat)) * 1e-9;
                    $nodeLon = ($lonOffset + ($granularity * $lon)) * 1e-9;

                    $tags = [];

                    while ($keysValsIndex < $keysValsCount && $keysVals[$keysValsIndex] !== 0) {
                        $keyIndex = $keysVals[$keysValsIndex++];
                        $valIndex = $keysValsIndex < $keysValsCount ? $keysVals[$keysValsIndex++] : 0;

                        if ($keyIndex < $stringTableCount && $valIndex < $stringTableCount) {
                            $tags[$stringTable[$keyIndex]] = $stringTable[$valIndex];
                        }
                    }

                    if ($keysValsIndex < $keysValsCount && $keysVals[$keysValsIndex] === 0) {
                        $keysValsIndex++;
                    }

                    if (count($tags) > 0) {
                        $nodes[] = [
                            'id' => $id,
                            'lat' => $nodeLat,
                            'lon' => $nodeLon,
                            'tags' => $tags,
                        ];
                    }
                }
            }

            return $nodes;
        }, [$blobData]);
    }

    /**
     * @param int[] $availableRuntimes
     * @param array<int, bool> $runtimeInUse
     */
    private function collectCompletedResults(
        int &$activeJobs,
        array &$availableRuntimes,
        array &$runtimeInUse,
    ): void {
        $completedFutures = [];
        $completedCount = 0;

        foreach ($this->futures as $key => $futureData) {
            $future = $futureData['future'];
            $runtimeIndex = $futureData['runtime_index'];

            if (!$future->done()) {
                continue;
            }

            $completedFutures[] = $key;
            $activeJobs--;
            $completedCount++;

            $availableRuntimes[] = $runtimeIndex;
            unset($runtimeInUse[$runtimeIndex]);

            $nodes = $future->value();

            $this->processedBlocks++;
            $this->processedNodes += count($nodes);

            foreach ($nodes as $node) {
                $this->processedTags += count($node['tags']);
            }

            $this->pendingNodes = array_merge($this->pendingNodes, $nodes);

            if ($this->processedBlocks % 1_000 === 0) {
                $this->logger->__invoke("Processed {$this->processedBlocks} blocks, {$this->processedNodes} nodes, {$this->processedTags} tags, " . count($this->pendingNodes) . " pending nodes");
            }
        }

        foreach ($completedFutures as $key) {
            unset($this->futures[$key]);
        }

        if (count($completedFutures) > 0) {
            $this->futures = array_values($this->futures);
        }
    }

    private function flushPendingNodes(): void
    {
        if (count($this->pendingNodes) === 0) {
            return;
        }

        $this->writer->__invoke($this->pendingNodes);
        $this->pendingNodes = [];
    }

    private function skipHeader(): void
    {
        $header = $this->readBlobHeader();

        if ($header === null || $header->getType() !== 'OSMHeader') {
            throw new RuntimeException('Failed to read OSM header');
        }

        $this->readBlob($header->getDatasize());

        $this->logger->__invoke('OSM header found');
    }

    private function readBlobHeader(): ?BlobHeader
    {
        $headerSizeData = fread($this->file, 4);

        if (strlen($headerSizeData) < 4) {
            return null;
        }

        $headerSize = unpack('N', $headerSizeData)[1];
        $headerData = fread($this->file, $headerSize);

        if (strlen($headerData) < $headerSize) {
            return null;
        }

        $header = new BlobHeader();
        $header->mergeFromString($headerData);

        return $header;
    }

    private function readBlob(int $size): ?Blob
    {
        $blobData = fread($this->file, $size);

        if (strlen($blobData) < $size) {
            return null;
        }

        $blob = new Blob();
        $blob->mergeFromString($blobData);

        return $blob;
    }

    private function blockContainsNodes(Blob $blob): bool
    {
        $data = gzuncompress($blob->getZlibData());

        $primitiveBlock = new PrimitiveBlock();
        $primitiveBlock->mergeFromString($data);

        foreach ($primitiveBlock->getPrimitivegroup() as $group) {
            if ($group->getWays()->count() > 0 || $group->getRelations()->count() > 0) {
                return false;
            }
        }

        return true;
    }
}
