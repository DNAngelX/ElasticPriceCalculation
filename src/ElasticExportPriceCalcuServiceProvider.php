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
            'KaufluxDE-Plugin',
            'ElasticExportKaufluxDE\ResultField\KaufluxDE',
            'ElasticExportKaufluxDE\Generator\KaufluxDE',
            '',
            true
        );
    }
}