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
 * Webhook processor to handle transaction completion state transitions.
 */
class WeArePlanetBackendStrategy1774 extends WeArePlanetBackendDefaultstrategy
{

    public function isVoucherOnlyWeArePlanet(Order $order, array $postData)
    {
        return isset($postData['cancel_product']['voucher']) && $postData['cancel_product']['voucher'] == 1;
    }
}
