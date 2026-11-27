<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Command;

use Dantweb\DemodataMall\Catalog\CatalogRepository;
use Dantweb\DemodataMall\Mall\MallPlanner;
use Dantweb\DemodataMall\Mall\MallShop;
use Dantweb\DemodataMall\Mall\ModuleSettingsCopier;
use Dantweb\DemodataMall\Mall\ShippingAssigner;
use Dantweb\DemodataMall\Shop\SubshopCreator;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ShopConfigurationDaoBridgeInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Creates (or brings up to date) a whole EE mall in one run: one subshop per vertical on its own domain,
 * each with catalog + start page CMS, the base shop's modules and module settings, shipping methods and cost
 * rules for its categories, its theme — plus the mall-wide settings (mall mode, shared customers).
 *
 * Re-runnable: a subshop is found by its URL (sMallShopURL) and updated instead of created twice.
 * See docs/managing-a-mall.md.
 */
class CreateMallCommand extends Command
{
    private const BASE_SHOP_ID = 1;

    public function __construct(
        private CatalogRepository $catalogRepository,
        private MallPlanner $mallPlanner,
        private SubshopCreator $subshopCreator,
        private ModuleSettingsCopier $moduleSettingsCopier,
        private ShippingAssigner $shippingAssigner,
        private ShopConfigurationDaoBridgeInterface $shopConfigurationDaoBridge
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Creates an EE mall: one subshop per vertical on its own domain, ready to sell.')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Base domain, e.g. example.com')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Subshop "<vertical>[:<host>[:<theme>]]", repeatable. Default: every vertical')
            ->addOption('host-pattern', null, InputOption::VALUE_REQUIRED,
                'Host when a --shop has none; placeholders {n}, {vertical}', 'shop{n}')
            ->addOption('scheme', null, InputOption::VALUE_REQUIRED, 'URL scheme', 'https')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'JSON mall file instead of the options above')
            ->addOption('delivery-rules', null, InputOption::VALUE_REQUIRED,
                'Cost rules (titles) to link to the subshop categories', implode(',', ShippingAssigner::DEFAULT_RULES))
            ->addOption('shared-customers', null, InputOption::VALUE_NONE,
                'Customers log in on every shop (blMallUsers)')
            ->addOption('mall-mode', null, InputOption::VALUE_REQUIRED,
                'iMallMode: 0 = main URL shows the base shop, 1 = shop chooser', '0')
            ->addOption('sync-module-settings', null, InputOption::VALUE_NONE,
                'Also copy the base shop module settings into subshops that already exist')
            ->addOption('skip-module', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Module id NOT to activate (nor copy settings of) in the subshops, repeatable')
            ->addOption('no-content', null, InputOption::VALUE_NONE, 'Do not install catalog and CMS')
            ->addOption('no-cms', null, InputOption::VALUE_NONE, 'Install the catalog but not the start page CMS')
            ->addOption('no-pictures', null, InputOption::VALUE_NONE, 'Do not copy pictures')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done')
            ->setHelp(<<<'HELP'
Examples:
  <info>oe:demodata-mall:create-mall --domain=example.com</info>
      every vertical as shop1.example.com, shop2.example.com, …
  <info>oe:demodata-mall:create-mall --domain=example.com --shared-customers \
      --shop=fashion:apparel:apex-child-3 --shop=electronics:electronics:apex-child-2 \
      --shop=medical-devices:med:apex-child-1</info>
  <info>oe:demodata-mall:create-mall --config=mall.json --dry-run</info>

See docs/managing-a-mall.md in the package.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!defined('OX_IS_ADMIN')) {
            define('OX_IS_ADMIN', true);
        }
        if ((int) Registry::getConfig()->getShopId() !== self::BASE_SHOP_ID) {
            $output->writeln('<error>Run create-mall in the base shop (no --shop-id or --shop-id=1).</error>');
            return Command::FAILURE;
        }
        if (!SubshopCreator::isEnterprise()) {
            $output->writeln('<error>A mall needs OXID eShop Enterprise Edition.</error>');
            return Command::FAILURE;
        }

        try {
            [$shops, $options] = $this->plan($input);
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->printPlan($output, $shops, $options);
        if ($options['dryRun']) {
            $output->writeln('<comment>Dry run — nothing changed.</comment>');
            return Command::SUCCESS;
        }

        $summary = [];
        foreach ($shops as $shop) {
            $summary[] = $this->setUpShop($shop, $options, $input, $output);
        }
        $this->applyMallSettings($summary, $options, $output);
        $this->printSummary($output, $summary);

        return in_array(false, array_column($summary, 'ok'), true) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array{0: list<MallShop>, 1: array<string, mixed>}
     */
    private function plan(InputInterface $input): array
    {
        $verticals = $this->catalogRepository->getIds();
        $options = [
            'deliveryRules' => array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('delivery-rules'))))),
            'sharedCustomers' => (bool) $input->getOption('shared-customers'),
            'mallMode' => (string) $input->getOption('mall-mode'),
            'dryRun' => (bool) $input->getOption('dry-run'),
            'skipModules' => array_values((array) $input->getOption('skip-module')),
        ];
        if ($input->getOption('config')) {
            $file = $this->mallPlanner->fromFile((string) $input->getOption('config'), $verticals);
            $fileOptions = $file['options'];
            $options['deliveryRules'] = $fileOptions['deliveryRules'] ?? $options['deliveryRules'];
            $options['sharedCustomers'] = (bool) ($fileOptions['sharedCustomers'] ?? $options['sharedCustomers']);
            $options['mallMode'] = (string) ($fileOptions['mallMode'] ?? $options['mallMode']);

            return [$file['shops'], $options];
        }

        return [$this->mallPlanner->fromOptions(
            (array) $input->getOption('shop'),
            $verticals,
            $input->getOption('domain') ? (string) $input->getOption('domain') : null,
            (string) $input->getOption('scheme'),
            (string) $input->getOption('host-pattern')
        ), $options];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function setUpShop(MallShop $shop, array $options, InputInterface $input, OutputInterface $output): array
    {
        $row = ['url' => $shop->url, 'vertical' => $shop->vertical, 'id' => null, 'ok' => true, 'notes' => []];
        $output->writeln("<info>== {$shop->url} ({$shop->vertical})</info>");
        try {
            $shopId = $this->findShopIdByUrl($shop->url);
            $isNew = $shopId === null;
            if ($isNew) {
                $name = $shop->name ?? $this->catalogRepository->get($shop->vertical)->getShopName();
                $shopId = $this->subshopCreator->create($name, $shop->url);
                $row['notes'][] = "created \"$name\"";
            }
            $row['id'] = $shopId;

            $moduleIds = array_values(array_diff($this->baseShopActiveModules(), $options['skipModules']));
            if ($isNew || $input->getOption('sync-module-settings')) {
                $copy = $this->moduleSettingsCopier->copy($moduleIds, self::BASE_SHOP_ID, $shopId);
                $row['notes'][] = count($copy['copied']) . ' module settings copied';
                $this->noteFailures($row, $copy['failed'], 'settings');
            }
            $this->activateModules($shopId, $options['skipModules'], $row);

            if (!$input->getOption('no-content')) {
                $row['ok'] = $this->runConsole($this->installArguments($shop, $shopId, $input), $output) && $row['ok'];
            }

            $shipping = $this->shippingAssigner->assign($shopId, self::BASE_SHOP_ID, $options['deliveryRules']);
            $row['notes'][] = sprintf('shipping +%d/+%d, %d category links', $shipping['shippingMethods'], $shipping['costRules'], $shipping['categoryLinks']);
            if ($shipping['missingRules']) {
                $row['notes'][] = 'missing cost rules: ' . implode(', ', $shipping['missingRules']);
            }

            if ($shop->theme) {
                $row['ok'] = $this->runConsole(['oe:theme:activate', $shop->theme, '--shop-id=' . $shopId], $output) && $row['ok'];
                $row['notes'][] = "theme {$shop->theme}";
            }
        } catch (Throwable $exception) {
            $row['ok'] = false;
            $row['notes'][] = 'ERROR: ' . $exception->getMessage();
        }

        return $row;
    }

    /**
     * Activates the base shop's modules; a second pass picks up modules whose dependencies were activated later.
     *
     * @param list<string> $skipModules
     * @param array<string, mixed> $row
     */
    private function activateModules(int $shopId, array $skipModules, array &$row): void
    {
        $first = $this->subshopCreator->activateBaseShopModules($shopId, $skipModules);
        $second = $first['failed']
            ? $this->subshopCreator->activateBaseShopModules($shopId, $skipModules)
            : ['activated' => [], 'failed' => []];
        $row['notes'][] = (count($first['activated']) + count($second['activated'])) . ' modules activated';
        $this->noteFailures($row, $second['failed'], 'module');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $failures
     */
    private function noteFailures(array &$row, array $failures, string $kind): void
    {
        foreach ($failures as $id => $message) {
            $row['notes'][] = "$kind $id failed: " . mb_substr($message, 0, 120);
        }
    }

    /**
     * @return list<string>
     */
    private function installArguments(MallShop $shop, int $shopId, InputInterface $input): array
    {
        return array_values(array_filter([
            'oe:demodata-mall:install',
            $shop->vertical,
            '--mode=add',
            '--shop-id=' . $shopId,
            $input->getOption('no-cms') ? null : '--cms',
            $input->getOption('no-pictures') ? '--no-pictures' : null,
        ]));
    }

    /**
     * @param list<array<string, mixed>> $summary
     * @param array<string, mixed> $options
     */
    private function applyMallSettings(array $summary, array $options, OutputInterface $output): void
    {
        $config = Registry::getConfig();
        $shopIds = array_merge([self::BASE_SHOP_ID], array_filter(array_column($summary, 'id')));
        foreach (array_unique($shopIds) as $shopId) {
            $config->saveShopConfVar('str', 'iMallMode', $options['mallMode'], (int) $shopId);
        }
        $output->writeln("Mall mode iMallMode={$options['mallMode']} on shops " . implode(', ', array_unique($shopIds)));
        if ($options['sharedCustomers']) {
            $config->saveShopConfVar('bool', 'blMallUsers', true, self::BASE_SHOP_ID);
            $output->writeln('Shared customers (blMallUsers) on');
        }
        $this->runConsole(['oe:cache:clear'], $output);
    }

    private function findShopIdByUrl(string $url): ?int
    {
        $id = DatabaseProvider::getDb()->getOne(
            "SELECT OXSHOPID FROM oxconfig WHERE OXVARNAME = 'sMallShopURL' AND TRIM(TRAILING '/' FROM OXVARVALUE) = :url AND OXSHOPID > 1",
            [':url' => rtrim($url, '/')]
        );

        return $id ? (int) $id : null;
    }

    /**
     * @return list<string>
     */
    private function baseShopActiveModules(): array
    {
        $ids = [];
        foreach ($this->shopConfigurationDaoBridge->get()->getModuleConfigurations() as $moduleConfiguration) {
            if ($moduleConfiguration->isActivated()) {
                $ids[] = $moduleConfiguration->getId();
            }
        }

        return $ids;
    }

    /**
     * Runs another oe-console command in a new process (clean shop context for --shop-id).
     *
     * @param list<string> $arguments
     */
    private function runConsole(array $arguments, OutputInterface $output): bool
    {
        $command = array_merge([PHP_BINARY, $_SERVER['argv'][0] ?? 'vendor/bin/oe-console'], $arguments);
        passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);
        if ($exitCode !== 0) {
            $output->writeln('<error>Failed: ' . implode(' ', $arguments) . '</error>');
        }

        return $exitCode === 0;
    }

    /**
     * @param list<MallShop> $shops
     * @param array<string, mixed> $options
     */
    private function printPlan(OutputInterface $output, array $shops, array $options): void
    {
        $table = new Table($output);
        $table->setHeaders(['URL', 'vertical', 'theme', 'subshop']);
        foreach ($shops as $shop) {
            $existing = $this->findShopIdByUrl($shop->url);
            $table->addRow([$shop->url, $shop->vertical, $shop->theme ?? '(unchanged)', $existing ? "exists (id $existing)" : 'new']);
        }
        $table->render();
        $output->writeln(sprintf(
            'Cost rules: %s · mall mode: %s · shared customers: %s · skipped modules: %s',
            implode(', ', $options['deliveryRules']) ?: '-',
            $options['mallMode'],
            $options['sharedCustomers'] ? 'on' : 'unchanged',
            implode(', ', $options['skipModules']) ?: '-'
        ));
    }

    /**
     * @param list<array<string, mixed>> $summary
     */
    private function printSummary(OutputInterface $output, array $summary): void
    {
        $table = new Table($output);
        $table->setHeaders(['shop id', 'URL', 'vertical', 'result']);
        foreach ($summary as $row) {
            $table->addRow([$row['id'] ?? '-', $row['url'], $row['vertical'], ($row['ok'] ? 'ok' : 'FAILED') . ' — ' . implode('; ', $row['notes'])]);
        }
        $table->render();
    }
}
