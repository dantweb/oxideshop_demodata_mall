<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Command;

use Dantweb\DemodataMall\Catalog\Catalog;
use Dantweb\DemodataMall\Cms\CmsInstaller;
use Dantweb\DemodataMall\Catalog\CatalogCleaner;
use Dantweb\DemodataMall\Catalog\CatalogInstaller;
use Dantweb\DemodataMall\Catalog\CatalogRepository;
use Dantweb\DemodataMall\Order\OrderPurger;
use Dantweb\DemodataMall\Picture\PictureInstaller;
use Dantweb\DemodataMall\Shop\SubshopCreator;
use OxidEsales\Eshop\Core\Registry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

class InstallCommand extends Command
{
    private const MODE_SUBSHOP = 'subshop';
    private const MODE_REPLACE = 'replace';
    private const MODE_ADD = 'add';

    public function __construct(
        private CatalogRepository $catalogRepository,
        private CatalogInstaller $catalogInstaller,
        private CatalogCleaner $catalogCleaner,
        private OrderPurger $orderPurger,
        private PictureInstaller $pictureInstaller,
        private SubshopCreator $subshopCreator,
        private CmsInstaller $cmsInstaller
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Installs a demo shop (fashion, electronics, medical-devices, ...).')
            ->addArgument('vertical', InputArgument::REQUIRED, 'Vertical id, see oe:demodata-mall:list')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, implode(' | ', [
                'subshop: create an EE subshop with this catalog (default)',
                'replace: delete the catalog of the current shop (--shop-id) and install this one',
                'add: add this catalog to the current shop',
            ]), self::MODE_SUBSHOP)
            ->addOption('purge-orders', null, InputOption::VALUE_NONE, 'replace mode: also delete all orders of the shop')
            ->addOption('shop-name', null, InputOption::VALUE_REQUIRED, 'subshop mode: shop name (default from catalog)')
            ->addOption('shop-url', null, InputOption::VALUE_REQUIRED, 'subshop mode: own URL of the subshop')
            ->addOption('no-modules', null, InputOption::VALUE_NONE, 'subshop mode: do not activate the modules of shop 1')
            ->addOption('no-pictures', null, InputOption::VALUE_NONE, 'do not copy pictures to out/pictures')
            ->addOption('no-cms', null, InputOption::VALUE_NONE, 'subshop/replace mode: keep the CMS start page content of the shop')
            ->addOption('cms', null, InputOption::VALUE_NONE, 'add mode: also install the CMS start page content (replaces the shop\'s)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'replace mode: do not ask for confirmation')
            ->setHelp(<<<'HELP'
Examples:
  <info>vendor/bin/oe-console oe:demodata-mall:install fashion</info>
      creates the subshop "Mall Fashion & Apparel" with its catalog
  <info>vendor/bin/oe-console oe:demodata-mall:install electronics --mode=replace --purge-orders --yes</info>
      replaces the catalog of shop 1 and deletes its orders
  <info>vendor/bin/oe-console oe:demodata-mall:install medical-devices --mode=replace --shop-id=3 --yes</info>
      replaces the catalog of shop 3
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!defined('OX_IS_ADMIN')) {
            define('OX_IS_ADMIN', true);
        }

        $catalog = $this->catalogRepository->get((string) $input->getArgument('vertical'));
        $mode = (string) $input->getOption('mode');

        return match ($mode) {
            self::MODE_SUBSHOP => $this->installSubshop($catalog, $input, $output),
            self::MODE_REPLACE => $this->replace($catalog, $input, $output),
            self::MODE_ADD => $this->add($catalog, $input, $output),
            default => $this->fail($output, "Unknown --mode \"$mode\". Use subshop, replace or add."),
        };
    }

    private function installSubshop(Catalog $catalog, InputInterface $input, OutputInterface $output): int
    {
        $name = (string) ($input->getOption('shop-name') ?: $catalog->getShopName());
        $shopId = $this->subshopCreator->findByName($name);
        if ($shopId) {
            $output->writeln("<info>Subshop \"$name\" exists (id $shopId), updating its catalog.</info>");
        } else {
            $shopId = $this->subshopCreator->create($name, $input->getOption('shop-url') ?: null);
            $output->writeln("<info>Created subshop \"$name\" with id $shopId.</info>");
        }

        if (!$input->getOption('no-modules')) {
            $modules = $this->subshopCreator->activateBaseShopModules($shopId);
            if ($modules['activated']) {
                $output->writeln('Activated modules: ' . implode(', ', $modules['activated']));
            }
            foreach ($modules['failed'] as $moduleId => $error) {
                $output->writeln("<comment>Module $moduleId not activated: $error</comment>");
            }
        }

        // a new process gives a clean shop context for the new shop id
        $command = array_filter([
            PHP_BINARY,
            $_SERVER['argv'][0] ?? 'vendor/bin/oe-console',
            $this->getName(),
            $catalog->getId(),
            '--mode=' . self::MODE_ADD,
            '--shop-id=' . $shopId,
            $input->getOption('no-pictures') ? '--no-pictures' : null,
            $input->getOption('no-cms') ? null : '--cms',
        ]);
        passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);
        if ($exitCode !== 0) {
            return $this->fail($output, "Installing the catalog into shop $shopId failed.");
        }

        $output->writeln(sprintf(
            '<info>Done. Open the subshop with %s</info>',
            $input->getOption('shop-url') ?: 'the shop URL and ?shp=' . $shopId
        ));

        return Command::SUCCESS;
    }

    private function replace(Catalog $catalog, InputInterface $input, OutputInterface $output): int
    {
        $shopId = (int) Registry::getConfig()->getShopId();
        $purgeOrders = (bool) $input->getOption('purge-orders');
        $question = sprintf(
            'This deletes all products, categories and manufacturers%s of shop %d. Continue? [y/N] ',
            $purgeOrders ? ' and ALL ORDERS' : '',
            $shopId
        );
        if (!$input->getOption('yes') && !$this->confirm($input, $output, $question)) {
            return $this->fail($output, 'Aborted. Pass --yes to run without the question.');
        }

        if ($purgeOrders) {
            $output->writeln('Deleting orders ...');
            foreach ($this->orderPurger->purge() as $table => $rows) {
                $output->writeln(sprintf('  %-28s %d', $table, $rows));
            }
        }

        $output->writeln('Deleting the current catalog ...');
        foreach ($this->catalogCleaner->clean() as $type => $count) {
            $output->writeln(sprintf('  %-28s %d', $type, $count));
        }

        return $this->add($catalog, $input, $output, !$input->getOption('no-cms'));
    }

    private function add(Catalog $catalog, InputInterface $input, OutputInterface $output, ?bool $withCms = null): int
    {
        $withCms ??= (bool) $input->getOption('cms');
        $shopId = (int) Registry::getConfig()->getShopId();
        $output->writeln(sprintf('Installing "%s" into shop %d ...', $catalog->getId(), $shopId));
        foreach ($this->catalogInstaller->install($catalog) as $type => $count) {
            $output->writeln(sprintf('  %-28s %d', $type, $count));
        }

        if (!$input->getOption('no-pictures')) {
            $pictures = $this->pictureInstaller->install($catalog->getPicturesDirectory());
            $output->writeln(sprintf(
                '  %-28s %d (resized copies removed: %d)',
                'pictures',
                $pictures['copied'],
                $pictures['cacheRemoved']
            ));
        }

        if ($withCms && $this->cmsInstaller->has($catalog)) {
            $cms = $this->cmsInstaller->install($catalog);
            $output->writeln(sprintf('  %-28s %d (deactivated: %d)', 'cms pages', $cms['pages'], $cms['deactivated']));
        }

        $this->clearCache($output);
        $output->writeln("<info>Shop $shopId has the \"{$catalog->getId()}\" catalog.</info>");

        return Command::SUCCESS;
    }

    private function clearCache(OutputInterface $output): void
    {
        $application = $this->getApplication();
        if (!$application || !$application->has('oe:cache:clear')) {
            return;
        }
        try {
            $application->find('oe:cache:clear')->run(new ArrayInput([]), $output);
        } catch (Throwable $exception) {
            $output->writeln('<comment>Cache not cleared: ' . $exception->getMessage() . '</comment>');
        }
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        if (!$input->isInteractive()) {
            return false;
        }

        return (bool) $this->getHelper('question')->ask($input, $output, new ConfirmationQuestion($question, false));
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln("<error>$message</error>");

        return Command::FAILURE;
    }
}
