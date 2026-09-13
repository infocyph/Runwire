<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use FilesystemIterator;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class DevelopmentFileScanner
{
    public function __construct(private DevelopmentWatchPolicy $policy) {}

    public function snapshot(): string
    {
        $entries = [];
        foreach ($this->policy->paths as $path) {
            if (is_file($path)) {
                $this->append(new SplFileInfo($path), $entries);

                continue;
            }
            if (!is_dir($path)) {
                $entries[] = 'missing:' . $path;

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $this->append($file, $entries);
            }
        }

        sort($entries, SORT_STRING);

        return hash('sha256', implode("\n", $entries));
    }

    /** @param list<string> $entries */
    private function append(SplFileInfo $file, array &$entries): void
    {
        if (count($entries) >= $this->policy->maxFiles) {
            throw new RuntimeException(sprintf(
                'Development watcher file limit of %d was exceeded.',
                $this->policy->maxFiles,
            ));
        }

        $entries[] = sprintf(
            '%s:%d:%d',
            $file->getPathname(),
            $file->getMTime(),
            $file->getSize(),
        );
    }
}
