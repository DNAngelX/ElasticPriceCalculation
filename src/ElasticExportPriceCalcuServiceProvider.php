<?php

namespace ElasticExportPriceCalcu;

use Plenty\Modules\DataExchange\Services\ExportPresetContainer;
use Plenty\Plugin\DataExchangeServiceProvider;

class ElasticExportPriceCalcuServiceProvider extends DataExchangeServiceProvider
{
    public function register()
    {

    }

    public function exports(ExportPresetContainer $container)
    {
        $container->add(
            'Preiskalkulation-Plugin',
            'ElasticExportPriceCalcu\ResultField\PriceCalcu',
            'ElasticExportPriceCalcu\Generator\PriceCalcu',
            '',
            true
        );
    }
}