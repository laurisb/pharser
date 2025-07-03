<?php

declare(strict_types=1);

$pharFile = __DIR__ . '/pharser.phar';

if (is_file($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile);
$stub = $phar->createDefaultStub('bin/console.php');

$phar->buildFromDirectory(dirname(__DIR__));
$phar->setStub($stub);
