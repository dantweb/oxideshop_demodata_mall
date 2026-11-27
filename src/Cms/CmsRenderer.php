<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Cms;

/**
 * Renders the start page CMS snippets of a vertical in the markup of the APEX theme shortcodes
 * (image teaser rows, text/image rows, USP lists) that the EE demo data uses, so every APEX-based
 * theme styles them.
 *
 * Texts are escaped here; links and image URLs are passed in already resolved.
 */
final class CmsRenderer
{
    /**
     * Big tile left, two half tiles right (oxstartslot1).
     *
     * @param array{title: string, text: string, image: string, link: string} $main
     * @param list<array{title: string, text: string, image: string, link: string}> $side
     */
    public function heroRow(array $main, array $side, string $cta): string
    {
        $sideTiles = '';
        foreach ($side as $tile) {
            $sideTiles .= $this->tile($tile, $cta, 'small-tile', 'card-img-half', 900, 450);
        }

        return $this->wrap('dd-shortcode-image-teaser-row style-1 space-0', '
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-lg-6">' . $this->tile($main, $cta, 'big-tile', 'card-img-full', 900, 900) . '</div>
                        <div class="col-lg-6">
                            <div class="d-flex flex-row d-lg-block slider-container">' . $sideTiles . '</div>
                        </div>
                    </div>
                </div>');
    }

    /**
     * Four portrait promotion tiles (oxstartslot2).
     *
     * @param list<array{title: string, image: string, link: string}> $promos
     */
    public function promoRow(array $promos, string $cta): string
    {
        $columns = '';
        foreach ($promos as $promo) {
            $columns .= '
                    <div class="col-6 col-lg">
                        <div class="card text-white zoom-in">
                            <img src="' . $this->escape($promo['image']) . '" class="card-img card-img-full" alt="'
                . $this->escape($promo['title']) . '" width="460" height="580" loading="lazy">
                            <div class="card-img-overlay text-center">
                                <div class="card-title h2">' . $this->escape($promo['title']) . '</div>
                                <span class="btn btn-secondary mx-auto">' . $this->escape($cta) . '</span>
                            </div>
                            <a href="' . $this->escape($promo['link']) . '" class="stretched-link" aria-label="'
                . $this->escape($cta . ' - ' . $promo['title']) . '"></a>
                        </div>
                    </div>';
        }

        return $this->wrap('dd-shortcode-image-teaser-row style-2 space-0', '
                <div class="container-xxl">
                    <div class="row">' . $columns . '
                    </div>
                </div>');
    }

    /**
     * Full-width image banner with text (oxstartslot3).
     *
     * @param array{title: string, text: string, image: string, link: string, cta: string} $banner
     */
    public function banner(array $banner): string
    {
        return $this->wrap('dd-shortcode-text-image-row style-1 space-0', '
    <div class="container-xxl">
        <div class="row">
            <div class="col">
                <div class="card text-white zoom-in">
                    <img src="' . $this->escape($banner['image']) . '" class="card-img card-img-full" alt="'
            . $this->escape($banner['title']) . '" width="1600" height="550" loading="lazy">
                    <div class="card-img-overlay">
                        <div class="card-img-overlay-content">
                            <div class="card-title h2 mb-3">' . $this->escape($banner['title']) . '</div>
                            <p class="d-none d-md-block card-text">' . $this->escape($banner['text']) . '</p>
                            <span class="btn btn-secondary me-auto">' . $this->escape($banner['cta']) . '</span>
                        </div>
                    </div>
                    <a href="' . $this->escape($banner['link']) . '" class="stretched-link" aria-label="'
            . $this->escape($banner['cta']) . '"></a>
                </div>
            </div>
        </div>
    </div>');
    }

    /**
     * Two image/text cards: the shop story and the customer service (oxstartslot4).
     *
     * @param array{title: string, text: string, image: string, link: string, cta: string} $story
     * @param array{title: string, text: string, image: string, link: string, cta: string} $service
     */
    public function storyAndService(array $story, array $service): string
    {
        return $this->wrap('dd-shortcode-text-image-row style-4 space-0', $this->imageTextCard($story, false))
            . $this->wrap('dd-shortcode-text-image-row style-3 space-0', $this->imageTextCard($service, true));
    }

    /**
     * @param list<array{icon: string, text: string}> $usps
     */
    public function headerUsps(array $usps): string
    {
        $items = '';
        foreach ($usps as $usp) {
            $items .= '
    <li class="list-inline-item">' . $this->icon($usp['icon']) . '<span class="usp-text">'
                . $this->escape($usp['text']) . '</span></li>';
        }

        return '<ul class="usp-list">' . $items . '
</ul>';
    }

    /**
     * @param list<array{icon: string, text: string}> $usps
     */
    public function startUsps(array $usps): string
    {
        $items = '';
        foreach ($usps as $usp) {
            $items .= '
        <div class="list-inline-item">' . $this->icon($usp['icon']) . '<span class="usp-text">'
                . $this->escape($usp['text']) . '</span></div>';
        }

        return '<div class="usp-list usp-list-content">
    <div class="slider-container">' . $items . '
    </div>
</div>';
    }

    public function footer(string $text): string
    {
        return '<div>' . $this->escape($text) . '</div>';
    }

    /**
     * @param array{title: string, text: string, image: string, link: string} $tile
     */
    private function tile(array $tile, string $cta, string $size, string $imageClass, int $width, int $height): string
    {
        return '
                    <div class="card text-white ' . $size . ' zoom-in">
                        <img src="' . $this->escape($tile['image']) . '" class="card-img ' . $imageClass . '" alt="'
            . $this->escape($tile['title']) . '" width="' . $width . '" height="' . $height . '">
                        <div class="card-img-overlay">
                            <div class="card-title h2">' . $this->escape($tile['title'])
            . '<svg aria-hidden="true" class="d-lg-none"><use xlink:href="#right"></use></svg></div>
                            <p class="d-none d-md-block card-text">' . $this->escape($tile['text']) . '</p>
                            <span class="d-none d-md-block btn btn-secondary me-auto">' . $this->escape($cta) . '</span>
                        </div>
                        <a href="' . $this->escape($tile['link']) . '" class="stretched-link" aria-label="'
            . $this->escape($cta . ' - ' . $tile['title']) . '"></a>
                    </div>';
    }

    /**
     * @param array{title: string, text: string, image: string, link: string, cta: string} $card
     */
    private function imageTextCard(array $card, bool $imageRight): string
    {
        $imageColumn = $imageRight ? 'col-lg-6 order-lg-2' : 'col-lg-7';
        $textColumn = $imageRight ? 'col-lg-6 order-lg-1' : 'col-lg-5';

        return '
    <div class="container-xxl">
        <div class="card">
            <div class="row g-0 m-0">
                <div class="' . $imageColumn . '">
                    <img src="' . $this->escape($card['image']) . '" class="card-img card-img-full" alt="'
            . $this->escape($card['title']) . '" width="1200" height="900" loading="lazy">
                </div>
                <div class="' . $textColumn . '">
                    <div class="card-body">
                        <div class="card-title h2">' . $this->escape($card['title']) . '</div>
                        <div class="card-text"><p>' . $this->escape($card['text']) . '</p></div>
                        <span class="btn btn-outline-primary">' . $this->escape($card['cta']) . '</span>
                    </div>
                </div>
                <a href="' . $this->escape($card['link']) . '" class="stretched-link" aria-label="'
            . $this->escape($card['cta']) . '"></a>
            </div>
        </div>
    </div>';
    }

    private function wrap(string $shortcodeClass, string $inner): string
    {
        return '<div class="container-fluid dd-ve-container clearfix"><div class="row"><div class="col-sm-12 col-xs-12">'
            . '<div class="' . $shortcodeClass . '">' . $inner . '
</div></div></div></div>';
    }

    private function icon(string $icon): string
    {
        return '<svg aria-hidden="true"><use xlink:href="#' . $this->escape($icon) . '"></use></svg>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
