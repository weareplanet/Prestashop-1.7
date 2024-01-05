<?php
/**
 * WeArePlanet Prestashop
 *
 * This Prestashop module enables to process payments with WeArePlanet (https://www.weareplanet.com/).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @copyright 2017 - 2024 customweb GmbH
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */

/**
 * This provider allows to create a WeArePlanet_ShopRefund_IStrategy.
 * The implementation of
 * the strategy depends on the actual prestashop version.
 */
class WeArePlanetBackendStrategyprovider
{
    private static $supported_strategies = [
        '1.7.7.4' => WeArePlanetBackendStrategy1774::class
    ];

    /**
     * Returns the refund strategy to use
     *
     * @return WeArePlanetBackendIstrategy
     */
    public static function getStrategy()
    {
        if (isset(self::$supported_strategies[_PS_VERSION_])) {
            return new self::$supported_strategies[_PS_VERSION_];
        }
        return new WeArePlanetBackendDefaultstrategy();
    }
}
