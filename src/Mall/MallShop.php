<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Mall;

/**
 * One planned subshop of a mall: which vertical it shows, under which URL, with which theme.
 */
final class MallShop
{
    public function __construct(
        public readonly string $vertical,
        public readonly string $host,
        public readonly string $url,
        public readonly ?string $theme,
        public readonly ?string $name,
    ) {
    }
}
