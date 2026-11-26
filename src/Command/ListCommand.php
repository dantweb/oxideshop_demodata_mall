<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Command;

use Dantweb\DemodataMall\Catalog\CatalogRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
    public function __construct(private CatalogRepository $catalogRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Lists the demo shops (verticals) that oe:demodata-mall:install can install.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['vertical', 'shop name', 'categories', 'products', 'manufacturers']);
        foreach ($this->catalogRepository->getIds() as $id) {
            $catalog = $this->catalogRepository->get($id);
            $table->addRow([
                $id,
                $catalog->getShopName(),
                count($catalog->getCategories()),
                count($catalog->getProducts()),
                count($catalog->getManufacturers()),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
