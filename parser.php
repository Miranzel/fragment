<?php

namespace SmileShop\OrderDelivery\Handler;

use SmileShop\OrderDelivery\Delivery\Boxberry\BoxberryInit;
use SmileShop\OrderDelivery\Delivery\Post\PostInit;
use Smile\DeliveryCalculate\DadataService;
use Smile\DeliveryCalculate\CalculateDistant;
use Smile\GlobalStorage;
use Smile\SmileShop;

class Parser
{
    const MIN_WEIGHT = 5;

    static $arBoxberryData = [];
    static $arPostData = [];

    public static function Compability($arOrder, $arConfig)
    {
        $arDelivery = [];

        $parcel_size = self::getFullDimensions($arOrder, $arConfig);

        $post = new PostInit();

        $arParams = [
            'LOCATION_TO' => $arOrder['LOCATION_ZIP'],
            'DIMENSION' => [
                'HEIGHT' => $parcel_size['HEIGHT'],
                'LENGTH' => $parcel_size['LENGTH'],
                'WIDTH' => $parcel_size['WIDTH']
            ],
            'WEIGHT' => $arOrder['WEIGHT']
        ];

        self::$arPostData = $post->methodExec($arParams);

        try
        {
            $boxberry = new BoxberryInit();
            $boxberry->getBitrixRegionNames($arOrder['LOCATION_TO']);

            if(!$cityCode = $boxberry->GetCityCode())
            {
                return false;
            }

            $pvz_to = $boxberry->GetPointCode($cityCode);

            $arParams = [
                'target='. $pvz_to,
                'targetstart='.$boxberry->target_start,
                'weight=' . $parcel_size['WEIGHT'],
                'height='. $parcel_size['HEIGHT'],
                'width='. $parcel_size['WIDTH'],
                'depth='. $parcel_size['LENGTH'],
                'ordersum=' . $arOrder['PRICE'],
                'paysum=' . $arOrder['PRICE'],
                'sucrh=1',
                'version=2.2',
                'cms=bitrix',
                'url='.$_SERVER['SERVER_NAME'],
                'zip=' .$arOrder['LOCATION_ZIP']
            ];

            self::$arBoxberryData = $boxberry->methodExec('DeliveryCosts',$arParams, TRUE);
        }
        catch (\Exception $e)
        {
            AddMessage2Log($e->getMessage());
        }

        $city =(!empty($_SESSION['USER_CITY']['CITY_CODE'])) ? $_SESSION['USER_CITY']['CITY_CODE'] : \Smile\Cities::DEFAULT_CITY_CODE;

        if($city == \Smile\Cities::DEFAULT_CITY_CODE)
        {
            $arDelivery[] = 'KD_MSC';
        }
        elseif(!empty(self::$arBoxberryData) && !empty(self::$arPostData))
        {
            $boxberry_price = self::$arBoxberryData['price'];
            $post_price = self::$arPostData['total-rate'] / 100; //по умолчанию в копейках сумма

            $post_price = self::updatePostPrice($arOrder, $post_price);

            if($boxberry_price < $post_price)
            {
                $arDelivery[] = 'KD_BOXBERRY';
            }
            else
            {
                $arDelivery[] = 'KD_POST';
            }
        }
        elseif(empty(self::$arBoxberryData) && !empty(self::$arPostData))
        {
            $arDelivery[] = 'KD_POST';
        }
        elseif(!empty(self::$arBoxberryData))
        {
            $arDelivery[] = 'KD_BOXBERRY';
        }

        return $arDelivery;
    }

    public static function Calculate($profile, $arConfig, $arOrder, $STEP, $TEMP = false)
    {
        if($profile == 'KD_MSC')
        {
            $address = GlobalStorage::get('ADDRESS');

            $module = 'smileshop.orderdelivery';
            $price = (int) \COption::GetOptionString($module, 'DELIVERY_MSK');

            if(intval($arOrder['PRICE']) >= \Smile\SmileShop::FREE_DELIVERY)
            {
                $price = $price_delivery['price'] = 0;
            }
            elseif(!empty($address))
            {
                $dadata = new DadataService();

                try
                {
                    $str = $dadata->getGeocode($address);
                    $cord = array_shift($str);

                    $distant = new CalculateDistant($cord['geo_lat'], $cord['geo_lon']);
                    $nearby_metro = $distant->getNearbyDistantMetro();

                    if(!empty($nearby_metro['PEDESTRIAN']) || !empty($nearby_metro['PUBLIC']))
                    {
                        if($distant->is_mkad)
                        {
                            $price = SmileShop::PRICE_DELIVERY_NEARBY_IN_MKAD;
                        }
                        else
                        {
                            $price = SmileShop::PRICE_DELIVERY_NEARBY_OUT_MKAD;
                        }
                    }
                    else
                    {
                        if($distant->is_mkad)
                        {
                            $price = SmileShop::PRICE_DELIVERY_IN_MKAD;
                        }
                        else
                        {
                            $price = $price = SmileShop::PRICE_DELIVERY_OUT_IN_MKAD;
                        }
                    }
                }
                catch (\GuzzleHttp\Exception\GuzzleException $e)
                {
                    AddMessage2Log('Guzzle ошибка запроса dadata');
                    $price = (int) \COption::GetOptionString($module, 'DELIVERY_MSK');
                }
                catch (\Exception $e)
                {
                    AddMessage2Log($e->getMessage());
                    $price = (int) \COption::GetOptionString($module, 'DELIVERY_MSK');
                }
            }
        }
        elseif($profile == 'KD_POST')
        {
            $price =self::$arPostData['total-rate'] / 100; //по умолчанию в копейках сумма

            $price = self::updatePostPrice($arOrder, $price);

            if(!empty(self::$arPostData['delivery-time']['min-days']) && self::$arPostData['delivery-time']['max-days'] != self::$arPostData['delivery-time']['min-days'])
            {
                $period = self::$arPostData['delivery-time']['min-days'] .' - ' . self::$arPostData['delivery-time']['max-days'] . ' дней';
            }
            else
            {
                $period =  self::$arPostData['delivery-time']['max-days'] . ' ' . self::proceedTextual(self::$arPostData['delivery-time']['max-days'], 'дней', 'день', 'дня');
            }
        }
        elseif($profile == 'KD_BOXBERRY')
        {
            $addDay = self::getFreeCelebrateDays();

            $price = self::$arBoxberryData['price'];
            $period = self::$arBoxberryData['delivery_period'] + $addDay . ' дней';
        }

        return [
            "RESULT" => "OK",
            "VALUE" => $price,
            "TRANSIT" => '<b>' .$period . '</b> с момента подтверждения заказа менеджером магазина '
        ];
    }

    private static function getFullDimensions($arOrder, $arConfig)
    {
        $weight_default = 1000;
        $multiplier = 10;

        if (count($arOrder["ITEMS"]) == 1 && $arOrder["ITEMS"][0]["QUANTITY"] == 1)
        {
            $full_package["WIDTH"] =  $arOrder["ITEMS"][0]["DIMENSIONS"]["WIDTH"] / $multiplier;
            $full_package["HEIGHT"] = $arOrder["ITEMS"][0]["DIMENSIONS"]["HEIGHT"] / $multiplier;
            $full_package["LENGTH"] = $arOrder["ITEMS"][0]["DIMENSIONS"]["LENGTH"] / $multiplier;
            $full_package["WEIGHT"] = ($arOrder["ITEMS"][0]['WEIGHT'] == '0.00' || (float)$arOrder["ITEMS"][0]['WEIGHT'] < (float)self::MIN_WEIGHT ? $weight_default : $arOrder["ITEMS"][0]['WEIGHT']);
        }
        else
        {
            $full_package["WIDTH"] = 0;
            $full_package["HEIGHT"] = 0;
            $full_package["LENGTH"] = 0;
            $full_package["WEIGHT"] = 0;

            foreach ($arOrder["ITEMS"] as $item)
            {
                $full_package["WEIGHT"] += $item["QUANTITY"] * ($item['WEIGHT'] == '0.00' || $item['WEIGHT'] < (float)self::MIN_WEIGHT  ? $weight_default : $item['WEIGHT'] );
            }

            $dimensions = array_column($arOrder['ITEMS'], 'DIMENSIONS');

            $full_package['WIDTH'] = ceil((max(array_column($dimensions, 'WIDTH')) * 1.2) / $multiplier);
            $full_package['HEIGHT'] = ceil((max(array_column($dimensions, 'HEIGHT')) * 1.2) / $multiplier);
            $full_package['LENGTH'] = ceil((array_sum(array_column($dimensions, 'LENGTH')) * 1.2) / $multiplier);
        }

        return $full_package;
    }

    private static function proceedTextual($numeric, $many, $one, $two )
    {
        $numeric = (int) abs($numeric);

        if ( $numeric % 100 == 1 || ($numeric % 100 > 20) && ( $numeric % 10 == 1 ) ) return $one;
        if ( $numeric % 100 == 2 || ($numeric % 100 > 20) && ( $numeric % 10 == 2 ) ) return $two;
        if ( $numeric % 100 == 3 || ($numeric % 100 > 20) && ( $numeric % 10 == 3 ) ) return $two;
        if ( $numeric % 100 == 4 || ($numeric % 100 > 20) && ( $numeric % 10 == 4 ) ) return $two;

        return $many;
    }

    /***
     * Делаем наценку для "Почты России"
     *
     * @param array $arOrder
     * @param int $post_price
     * @return int
     */
    private static function updatePostPrice(array $arOrder, int $post_price): int
    {
        $module = 'smileshop.orderdelivery';
        $from_delivery = (int) \COption::GetOptionString($module, 'API_POST_EXTRA_FROM_DELIVERY');
        $from_price = (int) \COption::GetOptionString($module, 'API_POST_EXTRA_FROM_PRICE');
        $add_to_price = (int) \COption::GetOptionString($module, 'API_POST_ADD_DELIVERY_PRICE');

        if($from_delivery > 0)
        {
            $post_price = $post_price * ($from_delivery / 100 + 1);
        }

        if($from_price > 0)
        {
            $post_price += $arOrder['PRICE'] * ($from_price / 100);
        }

        if($add_to_price > 0)
        {
            $post_price += $add_to_price;
        }

        $post_price = ceil($post_price);

        return $post_price;
    }

    protected static function getFreeCelebrateDays() : int
    {
        $days = 0;

        try
        {
            $workGraphic = new \Smile\ShopGraphic();
            $days = $workGraphic->getOffsetDays($workGraphic->boxberry_type);
        }
        catch (\Smile\Exceptions\ModuleDontExistException $e)
        {
            AddMessage2Log('Модуль график не покдлючен');
        }

        return $days;
    }
}
