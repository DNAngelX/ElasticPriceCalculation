<?php

namespace ElasticExportPriceCalcu\Generator;

use Plenty\Modules\DataExchange\Contracts\CSVPluginGenerator;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\Item\DataLayer\Models\Record;
use Plenty\Modules\Item\DataLayer\Models\RecordList;
use Plenty\Modules\DataExchange\Models\FormatSetting;
use ElasticExport\Helper\ElasticExportCoreHelper;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\Property\Contracts\PropertySelectionRepositoryContract;
use Plenty\Modules\Item\Property\Models\PropertySelection;
use Plenty\Modules\Helper\Contracts\UrlBuilderRepositoryContract;

/**
 * Class PriceCalcu
 */
class PriceCalcu extends CSVPluginGenerator
{
    const SHOP = 1.00;
    const STATUS_VISIBLE = 0;
    const STATUS_LOCKED = 1;
    const STATUS_HIDDEN = 2;

    /**
     * @var ElasticExportCoreHelper $elasticExportHelper
     */
    private $elasticExportHelper;

    /*
     * @var ArrayHelper
     */
    private $arrayHelper;

    /**
     * PropertySelectionRepositoryContract $propertySelectionRepository
     */
    private $propertySelectionRepository;

    /**
     * @var UrlBuilderRepositoryContract $urlBuilderRepository
     */
    private $urlBuilderRepository;

    /**
     * @var array
     */
    private $itemPropertyCache = [];

    /**
     * @var array
     */
    private $addedItems = [];

    /**
     * @var array $idlVariations
     */
    private $idlVariations = array();

    /**
     * @var array
     */
    private $flags = [
        0 => '',
        1 => 'Sonderangebot',
        2 => 'Neuheit',
        3 => 'Top Artikel',
    ];

    /**
     * PriceCalcu constructor.
     * @param ArrayHelper $arrayHelper
     * @param PropertySelectionRepositoryContract $propertySelectionRepository
     * @param UrlBuilderRepositoryContract $urlBuilderRepository
     */
    public function __construct(
        ArrayHelper $arrayHelper,
        PropertySelectionRepositoryContract $propertySelectionRepository,
        UrlBuilderRepositoryContract $urlBuilderRepository
    )
    {
        $this->arrayHelper = $arrayHelper;
        $this->propertySelectionRepository = $propertySelectionRepository;
        $this->urlBuilderRepository = $urlBuilderRepository;
    }

    /**
     * @param array $resultData
     * @param array $formatSettings
     * @param array $filter
     */
    protected function generatePluginContent($resultData, array $formatSettings = [], array $filter = [])
    {
        $this->elasticExportHelper = pluginApp(ElasticExportCoreHelper::class);
        if(is_array($resultData['documents']) && count($resultData['documents']) > 0)
        {
            $settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');

            $this->setDelimiter(";");

            $this->addCSVContent([
                    'ItemID',
                    'VariantID',
                    'Hersteller',
                    'ItemNo',
                    'Name',
                    'FreeVar1',
                    'Preis Shop',
                    'UVP',
                    'EK',
                    'TransportCosts',
                    'operatingCosts',
                    'Stock_Sottrum',
                    'Stock_Fulfillments',
                    'Stock_Vorbuchlager',
                    'Stock_gesamt',
                    'Sold 90 Tage',
                    'Sold 30 Tage',
                    'Lagerreichweite 90T Durchschnitt',
                    'Lagerreichweite 30T Durchschnitt',
                    'Preis min AYN',
                    'Preis min eBay / Shop',
                    'Preis min Amazon',
                    'Mark1',
                    'Mark2',
                    'Aktiv',
                    'Credit Notes 30 Tage',
                    'Credit Notes 90 Tage',
                    'Warrantys 30 Tage',
                    'Warrantys 90 Tage',
                    'Model',
                    'Free4',
                    'Free6',
            ]);

            //Create a List of all VariationIds
            $variationIdList = array();
            foreach($resultData['documents'] as $variation)
            {
                $variationIdList[] = $variation['id'];
            }

            //Get the missing fields in ES from IDL
            if(is_array($variationIdList) && count($variationIdList) > 0)
            {
                /**
                 * @var \ElasticExportPriceCalcu\IDL_ResultList\PriceCalcu $idlResultList
                 */
                $idlResultList = pluginApp(\ElasticExportPriceCalcu\IDL_ResultList\PriceCalcu::class);
                $idlResultList = $idlResultList->getResultList($variationIdList, $settings);
            }

            //Creates an array with the variationId as key to surpass the sorting problem
            if(isset($idlResultList) && $idlResultList instanceof RecordList)
            {
                $this->createIdlArray($idlResultList);
            }

            foreach($resultData['documents'] as $item)
            {
                if(!$this->valid($item))
                {
                    continue;
                }

                $basePriceList = $this->elasticExportHelper->getBasePriceList($item,(float) $this->idlVariations[$item['id']]['variationRetailPrice.price']);

                $shippingCost = $this->elasticExportHelper->getShippingCost($item['data']['item']['id'], $settings);
                if(is_null($shippingCost))
                {
                    $shippingCost = '';
                }

                $imageList = $this->elasticExportHelper->getImageListInOrder($item, $settings, 3, 'variationImages');

                $data = [
                    'ItemID' 			=> $item['data']['item']['id'],
                    'VariantID'         => $variation['id'],
                    'Hersteller' 		=> $this->elasticExportHelper->getExternalManufacturerName((int)$item['data']['item']['manufacturer']['id']),
                    'ItemNo' 	        => $item['data']['variation']['number'],
                    'Name' 		        => $this->elasticExportHelper->getName($item, $settings), //. ' ' . $item->variationBase->variationName, todo maybe add the attribute value name
                    'FreeVar1' 			=> $item['data']['item']['free1'],
                    'Preis Shop' 		=> number_format((float)$this->idlVariations[$item['id']]['variationRetailPrice.price'], 2, '.', ''),
                    'UVP' 				=> $this->elasticExportHelper->getRecommendedRetailPrice($item, $settings),
                    'EK'    			=> $item['data']['variation']['purchasePrice'],
                    'TransportCosts'    => $item['data']['variation']['transportationCosts'],
                    'operatingCosts'    => $item['data']['variation']['operatingCosts'],
                    'Stock_Sottrum'     => $this->getStock($item),
                    'Stock_Fulfillments'=> '',
                    'Stock_Vorbuchlager'=> '',
                    'Stock_gesamt'      => $this->getStock($item),
                    'Sold 90 Tage'      => '',
                    'Sold 30 Tage'      => '',
                    'Lagerreichweite 90T Durchschnitt' => '',
                    'Lagerreichweite 30T Durchschnitt' => '',
                    'Preis min AYN'     => number_format((float)((((($item['data']['variation']['purchasePrice']+$item['data']['variation']['transportationCosts'])/100*($item['data']['variation']['operatingCosts']+100))*1.1)*1.19)*1.08), 2, '.', ''),
                    'Preis min eBay / Shop'     => number_format((float)((((($item['data']['variation']['purchasePrice']+$item['data']['variation']['transportationCosts'])/100*($item['data']['variation']['operatingCosts']+100))*1.1)*1.19)*1.10), 2, '.', ''),
                    'Preis min Amazon'  => number_format((float)((((($item['data']['variation']['purchasePrice']+$item['data']['variation']['transportationCosts'])/100*($item['data']['variation']['operatingCosts']+100))*1.1)*1.19)*1.19), 2, '.', ''),
                    'Mark1'             => $item['data']['item']['flagOne'],
                    'Mark2'             => $item['data']['item']['flagTwo'],
                    'Aktiv'             => $item['data']['variation']['isActive'],
                    'Credit Notes 30 Tage' => '',
                    'Credit Notes 90 Tage'  => '',
                    'Warrantys 30 Tage' => '',
                    'Warrantys 90 Tage' => '',
                    'Model'             => $item['data']['variation']['model'],
                    'Free4'             => $item['data']['item']['free4'],
                    'Free6'             => $item['data']['item']['free6'],
                    
                    
                ];

                $this->addCSVContent(array_values($data));
            }
        }
    }

    /**
     * Get description of all correlated properties
     * @param array $item
     * @return string
     */
    private function getPropertyDescription($item):string
    {
        $properties = $this->getItemPropertyList($item);

        $propertyDescription = '';

        foreach($properties as $property)
        {
            $propertyDescription .= '<br/>' . $property;
        }

        return $propertyDescription;
    }

    /**
     * Get item properties.
     * @param 	array $item
     * @return array<string,string>
     */
    private function getItemPropertyList($item):array
    {
        if(!array_key_exists($item['data']['item']['id'], $this->itemPropertyCache))
        {
            $characterMarketComponentList = $this->elasticExportHelper->getItemCharactersByComponent($this->idlVariations[$item['id']], self::SHOP, 1);

            $list = [];

            if(count($characterMarketComponentList))
            {
                foreach($characterMarketComponentList as $data)
                {
                    if((string) $data['characterValueType'] != 'file' && (string) $data['characterValueType'] != 'empty')
                    {
                        if((string) $data['characterValueType'] == 'selection')
                        {
                            $characterSelection = $this->propertySelectionRepository->findOne((int) $data['characterValue'], 'de');
                            if($characterSelection instanceof PropertySelection)
                            {
                                $list[] = (string) $characterSelection->name;
                            }
                        }
                        else
                        {
                            $list[] = (string) $data['characterValue'];
                        }

                    }
                }
            }

            $this->itemPropertyCache[$item['data']['item']['id']] = $list;
        }

        return $this->itemPropertyCache[$item['data']['item']['id']];
    }

    /**
     * Get list of cross selling items.
     * @param array $item
     * @return array<string>
     */
    private function getCrossSellingItems($item):array
    {
        $list = [];

        foreach($this->idlVariations[$item['id']]['itemCrossSellingList'] as $itemCrossSelling)
        {
            $list[] = (string) $itemCrossSelling->crossItemId;
        }

        return $list;
    }

    /**
     * Get status.
     * @param  array $item
     * @return int
     */
    private function getStatus($item):int
    {
        if(!array_key_exists($item['data']['item']['id'], $this->addedItems))
        {
            $this->addedItems[$item['data']['item']['id']] = $item['data']['item']['id'];

            return self::STATUS_VISIBLE;
        }

        return self::STATUS_HIDDEN;
    }

    /**
     * Get stock.
     * @param array $item
     * @return int
     */
    private function getStock($item):int
    {
        $stock = $this->idlVariations['variationStock.stockNet'];

        if ($item['data']['variation']['stockLimitation'] == 0 || $this->config('stockCondition') == 'N')
        {
            $stock = 100;
        }

        return (int) $stock;
    }

    /**
     * Get kauflux configuration.
     * @param  string $key
     * @return string
     */
    private function config(string $key):string
    {
        $config = $this->elasticExportHelper->getConfig('plenty.market.kauflux');

        if(is_array($config) && array_key_exists($key, $config))
        {
            return (string) $config[$key];
        }

        return '';
    }

    /**
     * Check if stock available.
     * @param  array $item
     * @return bool
     */
    private function valid($item):bool
    {
        $stock = $this->idlVariations[$item['id']]['variationStock.stockNet'];

        if ($item['data']['variation']['stockLimitation'] == 0 || $this->config('stockCondition') == 'N')
        {
            $stock = 100;
        }

        if($this->config('stockCondition') != 'N' && $stock <= 0)
        {
            return false;
        }

        return true;
    }

    /**
     * @param RecordList $idlResultList
     */
    private function createIdlArray($idlResultList)
    {
        if($idlResultList instanceof RecordList)
        {
            foreach($idlResultList as $idlVariation)
            {
                if($idlVariation instanceof Record)
                {
                    $this->idlVariations[$idlVariation->variationBase->id] = [
                        'itemBase.id' => $idlVariation->itemBase->id,
                        'variationBase.id' => $idlVariation->variationBase->id,
                        'itemCrossSellingList' => $idlVariation->itemCrossSellingList,
                        'itemPropertyList' => $idlVariation->itemPropertyList,
                        'variationStock.stockNet' => $idlVariation->variationStock->stockNet,
                        'variationRetailPrice.price' => $idlVariation->variationRetailPrice->price,
                        'variationRetailPrice.vatValue' => $idlVariation->variationRetailPrice->vatValue,
                        'variationRecommendedRetailPrice.price' => $idlVariation->variationRecommendedRetailPrice->price,
                    ];
                }
            }
        }
    }

}
