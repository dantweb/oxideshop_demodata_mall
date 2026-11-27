<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Mall;

use InvalidArgumentException;

/**
 * Turns the command options (or a JSON mall file) into the list of subshops to create.
 *
 * Shop specs: "<vertical>[:<host>[:<theme>]]". A host without a dot is a sub-domain of --domain
 * ("apparel" + "example.com" → apparel.example.com); a host with a dot is used as is. Without a host the
 * --host-pattern is used, placeholders {n} (1, 2, … in order) and {vertical}; default "shop{n}".
 */
class MallPlanner
{
    /**
     * @param list<string> $shopSpecs
     * @param list<string> $knownVerticals
     * @return list<MallShop>
     */
    public function fromOptions(
        array $shopSpecs,
        array $knownVerticals,
        ?string $domain,
        string $scheme,
        string $hostPattern
    ): array {
        $specs = $shopSpecs ?: $knownVerticals;
        $shops = [];
        foreach (array_values($specs) as $index => $spec) {
            [$vertical, $host, $theme] = array_pad(explode(':', (string) $spec, 3), 3, '');
            $shops[] = $this->shop(
                ['vertical' => $vertical, 'host' => $host, 'theme' => $theme],
                $index + 1,
                $knownVerticals,
                $domain,
                $scheme,
                $hostPattern
            );
        }

        return $shops;
    }

    /**
     * JSON: {"domain": "example.com", "scheme": "https", "hostPattern": "shop{n}",
     *        "shops": [{"vertical": "fashion", "host": "apparel", "theme": "apex-child-3", "name": "Apparel"}]}
     *
     * @param list<string> $knownVerticals
     * @return array{shops: list<MallShop>, options: array<string, mixed>}
     */
    public function fromFile(string $file, array $knownVerticals): array
    {
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['shops']) || !is_array($data['shops'])) {
            throw new InvalidArgumentException("Mall file $file is not valid JSON with a \"shops\" list.");
        }
        $shops = [];
        foreach (array_values($data['shops']) as $index => $entry) {
            $shops[] = $this->shop(
                (array) $entry,
                $index + 1,
                $knownVerticals,
                $data['domain'] ?? null,
                (string) ($data['scheme'] ?? 'https'),
                (string) ($data['hostPattern'] ?? 'shop{n}')
            );
        }
        unset($data['shops']);

        return ['shops' => $shops, 'options' => $data];
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $knownVerticals
     */
    private function shop(
        array $entry,
        int $number,
        array $knownVerticals,
        ?string $domain,
        string $scheme,
        string $hostPattern
    ): MallShop {
        $vertical = trim((string) ($entry['vertical'] ?? ''));
        if (!in_array($vertical, $knownVerticals, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown vertical "%s". Known: %s',
                $vertical,
                implode(', ', $knownVerticals)
            ));
        }
        $host = trim((string) ($entry['host'] ?? ''));
        if ($host === '') {
            $host = strtr($hostPattern, ['{n}' => (string) $number, '{vertical}' => $vertical]);
        }
        if (!str_contains($host, '.')) {
            if (!$domain) {
                throw new InvalidArgumentException("Host \"$host\" has no domain: pass --domain or a full host name.");
            }
            $host .= '.' . ltrim($domain, '.');
        }
        $theme = trim((string) ($entry['theme'] ?? ''));
        $name = trim((string) ($entry['name'] ?? ''));

        return new MallShop(
            $vertical,
            strtolower($host),
            sprintf('%s://%s/', $scheme, strtolower($host)),
            $theme !== '' ? $theme : null,
            $name !== '' ? $name : null
        );
    }
}
