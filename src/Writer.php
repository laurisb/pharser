<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

use function fopen;
use function fwrite;
use function str_replace;

final class Writer
{
    /** @var resource */
    private $fpNodes;

    /** @var resource */
    private $fpTags;

    public function __construct(
        string $nodeCsvFile,
        string $tagCsvFile,
    ) {
        $fpNodes = fopen($nodeCsvFile, 'w');
        $fpTags = fopen($tagCsvFile, 'w');

        if ($fpNodes === false) {
            throw new RuntimeException("Failed to open node CSV file for writing: {$nodeCsvFile}");
        }

        if ($fpTags === false) {
            throw new RuntimeException("Failed to open tag CSV file for writing: {$tagCsvFile}");
        }

        $this->fpNodes = $fpNodes;
        $this->fpTags = $fpTags;
    }

    /**
     * @param array<int, array{id: int, lat: float, lon: float, tags: array<string, string>}> $nodes
     */
    public function __invoke(array $nodes): void
    {
        $nodeBuffer = '';
        $tagBuffer = '';

        foreach ($nodes as $node) {
            $nodeBuffer .= "{$node['id']}\t{$node['lat']}\t{$node['lon']}\n";

            foreach ($node['tags'] as $name => $value) {
                $value = str_replace(
                    search: ["\n", "\t"],
                    replace: ' ',
                    subject: $value,
                );

                $tagBuffer .= "{$node['id']}\t{$name}\t{$value}\n";
            }
        }

        fwrite($this->fpNodes, $nodeBuffer);
        fwrite($this->fpTags, $tagBuffer);
    }
}
