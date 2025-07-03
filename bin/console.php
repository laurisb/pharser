<?php

declare(strict_types=1);

use App\Importer;
use App\Logger;
use App\Parser;
use App\Writer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require_once __DIR__ . '/../vendor/autoload.php';

(new SingleCommandApplication())
    ->setName('PHARser')
    ->setVersion('1.0.0')
    ->addArgument(
        name: 'pbf',
        mode: InputArgument::REQUIRED,
        description: 'Path to the PBF file',
    )
    ->addOption(
        name: 'db-host',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'MySQL host',
        default: 'localhost',
    )
    ->addOption(
        name: 'db-port',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'MySQL port',
        default: 3306,
    )
    ->addOption(
        name: 'db-user',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'MySQL username',
        default: 'root',
    )
    ->addOption(
        name: 'db-pass',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'MySQL password',
        default: '',
    )
    ->addOption(
        name: 'db-name',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'MySQL database name',
        default: 'osm',
    )
    ->addOption(
        name: 'threads',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'Number of threads to use for parsing',
        default: 3,
    )
    ->addOption(
        name: 'batch-size',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'Size of each batch for processing',
        default: 10_000,
    )
    ->addOption(
        name: 'memory-limit',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'Maximum memory limit in MB',
        default: 512,
    )
    ->addOption(
        name: 'skip-indexing',
        mode: InputOption::VALUE_OPTIONAL,
        description: 'Skip index creation',
        default: 0,
    )
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        try {
            $pbfFile = str_replace('\\', '/', (string) $input->getArgument('pbf'));
            $nodeCsvFile = $pbfFile . '.nodes.csv';
            $tagCsvFile = $pbfFile . '.tags.csv';
            $logger = new Logger($output);

            $importer = new Importer(
                logger: $logger,
                nodeCsvFile: $nodeCsvFile,
                tagCsvFile: $tagCsvFile,
                dbHost: (string) $input->getOption('db-host'),
                dbPort: (int) $input->getOption('db-port'),
                dbUser: (string) $input->getOption('db-user'),
                dbPass: (string) $input->getOption('db-pass'),
                dbName: (string) $input->getOption('db-name'),
            );

            $logger->__invoke("Starting to parse: {$pbfFile}");

            $parser = new Parser(
                pbfFile: $pbfFile,
                writer: new Writer($nodeCsvFile, $tagCsvFile),
                logger: $logger,
                numThreads: (int) $input->getOption('threads'),
                batchSize: (int) $input->getOption('batch-size'),
                maxMemoryMB: (int) $input->getOption('memory-limit'),
            );

            $parser->parse();
            $importer->createTables();
            $importer->import();

            $logger->__invoke('Cleaning up');

            unlink($nodeCsvFile);
            unlink($tagCsvFile);

            $skipIndexing = (bool) $input->getOption('skip-indexing');

            if ($skipIndexing) {
                $logger->__invoke('Skipping index creation');

                return Command::SUCCESS;
            }

            $importer->index();
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    })
    ->run();
