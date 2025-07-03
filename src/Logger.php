<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function number_format;
use function round;

final class Logger
{
    private ?DateTimeImmutable $lastTime = null;

    public function __construct(
        private OutputInterface $output,
    ) {
        //
    }

    public function __invoke(string $message): void
    {
        $curTime = new DateTimeImmutable();
        $milliSeconds = 0;

        if ($this->lastTime !== null) {
            $secondsDiff = $curTime->getTimestamp() - $this->lastTime->getTimestamp();
            $microsecondsDiff = $curTime->format('u') - $this->lastTime->format('u');
            $microSeconds = ($secondsDiff * 1_000_000) + $microsecondsDiff;
            $milliSeconds = (int) round($microSeconds / 1_000);
        }

        $this->lastTime = $curTime;

        $parts = [
            '<info>' . $curTime->format('H:i:s.v') . '</info>',
            $message,
        ];

        if ($milliSeconds > 0) {
            $parts[] = '<comment>[' . $this->formatDuration($milliSeconds) . ']</comment>';
        }

        $this->output->writeln(implode(' ', $parts));
    }

    private function formatDuration(int $milliSeconds): string
    {
        if ($milliSeconds < 60_000) {
            return '+' . number_format($milliSeconds / 1_000, 3, '.', '') . 's';
        }

        $seconds = (int) round($milliSeconds / 1_000);
        $hours = (int) ($seconds / 3_600);
        $minutes = (int) (($seconds % 3_600) / 60);
        $remainingSeconds = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }

        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }

        $parts[] = $remainingSeconds . 's';

        return '+' . implode('', $parts);
    }
}
