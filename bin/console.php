<?php

declare(strict_types=1);

use App\Importer;
use App\Logger;
use App\Parser;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

use function getenv;
use function shell_exec;
use function trim;

use const PHP_OS_FAMILY;

require __DIR__ . '/../vendor/autoload.php';

$detectedThreads = match (PHP_OS_FAMILY) {
    'Windows' => (int) getenv('NUMBER_OF_PROCESSORS'),
    'Darwin' => (int) trim((string) shell_exec('sysctl -n hw.logicalcpu')),
    default => (int) trim((string) shell_exec('nproc')),
};

$defaultThreads = $detectedThreads > 0 ? $detectedThreads : 1;

(new SingleCommandApplication())
    ->setName('PHARser')
    ->setVersion('2.2.0')
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
        default: 3_306,
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
        description: 'Number of threads to use for parsing (detected: ' . $defaultThreads . ')',
        default: $defaultThreads,
    )
    ->addOption(
        name: 'skip-indexing',
        mode: InputOption::VALUE_NONE,
        description: 'Skip index creation',
    )
    ->addOption(
        name: 'keep-metadata',
        mode: InputOption::VALUE_NONE,
        description: 'Keep metadata tags (source, attribution, notes, etc.)',
    )
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        try {
            /** @var string|null $pbfArg */
            $pbfArg = $input->getArgument('pbf');
            $pbfFile = str_replace('\\', '/', (string) $pbfArg);
            $logger = new Logger($output);

            $logger->__invoke('Starting to parse: ' . $pbfFile);

            $startTime = microtime(true);

            /** @var string|int $threadOpt */
            $threadOpt = $input->getOption('threads');
            $threads = (int) $threadOpt;

            if ($threads < 1) {
                throw new InvalidArgumentException('Thread count must be at least 1');
            }

            $keepMetadata = (bool) $input->getOption('keep-metadata');

            $parser = new Parser(
                pbfFile: $pbfFile,
                nodeCsvBase: $pbfFile . '.nodes.csv',
                tagCsvBase: $pbfFile . '.tags.csv',
                logger: $logger,
                numThreads: $threads,
                skipMetadata: !$keepMetadata,
            );

            $skipIndexing = (bool) $input->getOption('skip-indexing');

            /** @var string|null $dbHost */
            $dbHost = $input->getOption('db-host');

            /** @var string|int $dbPortOpt */
            $dbPortOpt = $input->getOption('db-port');

            /** @var string|null $dbUser */
            $dbUser = $input->getOption('db-user');

            /** @var string|null $dbPass */
            $dbPass = $input->getOption('db-pass');

            /** @var string|null $dbName */
            $dbName = $input->getOption('db-name');

            $importer = new Importer(
                logger: $logger,
                dbHost: (string) $dbHost,
                dbPort: (int) $dbPortOpt,
                dbUser: (string) $dbUser,
                dbPass: (string) $dbPass,
                dbName: (string) $dbName,
            );

            $importer->createDatabase();
            $importer->createTables();
            $parser->parse();
            $importer->import($parser->getNodeCsvFiles(), $parser->getTagCsvFiles());

            if ($skipIndexing) {
                $logger->__invoke('Skipping index creation');
                $logger->__invoke('Total time: ' . number_format(microtime(true) - $startTime, 0) . ' seconds');

                return Command::SUCCESS;
            }

            $importer->index();

            $logger->__invoke('Total time: ' . number_format(microtime(true) - $startTime, 0) . ' seconds');
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    })
    ->run();
