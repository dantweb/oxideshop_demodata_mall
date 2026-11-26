<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Picture;

use FilesystemIterator;
use OxidEsales\Eshop\Core\Registry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Copies the pictures of a vertical into source/out/pictures and removes the resized copies
 * of these pictures from out/pictures/generated, so the shop builds them again.
 */
class PictureInstaller
{
    /**
     * @return array{copied: int, cacheRemoved: int}
     */
    public function install(string $sourceDirectory): array
    {
        $target = rtrim((string) Registry::getConfig()->getConfigParam('sShopDir'), '/') . '/out/pictures';
        if (!is_dir($target . '/master')) {
            throw new RuntimeException("Picture directory not found: $target/master");
        }

        $names = [];
        $copied = 0;
        foreach ($this->files($sourceDirectory) as $file) {
            $relative = substr($file, strlen($sourceDirectory) + 1);
            $destination = $target . '/' . $relative;
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true)) {
                throw new RuntimeException('Cannot create ' . dirname($destination));
            }
            if (!copy($file, $destination)) {
                throw new RuntimeException("Cannot copy $file to $destination");
            }
            $names[basename($file)] = true;
            $copied++;
        }

        $removed = 0;
        if (is_dir($target . '/generated')) {
            foreach ($this->files($target . '/generated') as $cached) {
                $name = preg_replace('/\.webp$/', '', basename($cached));
                if (isset($names[$name]) && unlink($cached)) {
                    $removed++;
                }
            }
        }

        return ['copied' => $copied, 'cacheRemoved' => $removed];
    }

    /**
     * @return iterable<string>
     */
    private function files(string $directory): iterable
    {
        if (!is_dir($directory)) {
            return [];
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }
}
