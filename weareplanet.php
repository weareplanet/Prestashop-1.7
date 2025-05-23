<?php

/**
 * WeArePlanet Prestashop
 *
 * This Prestashop module enables to process payments with WeArePlanet (https://www.weareplanet.com/).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @copyright 2017 - 2025 customweb GmbH
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */

if (!defined('_PS_VERSION_')) {
    exit();
}

use PrestaShop\PrestaShop\Core\Domain\Order\CancellationActionType;

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'weareplanet_autoloader.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'weareplanet-sdk' . DIRECTORY_SEPARATOR .
    'autoload.php');
class WeArePlanet extends PaymentModule
{

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->name = 'weareplanet';
        $this->tab = 'payments_gateways';
        $this->author = 'Customweb GmbH';
        $this->bootstrap = true;
        $this->need_instance = 0;
        $this->version = '1.2.50';
        $this->displayName = 'WeArePlanet';
        $this->description = $this->l('This PrestaShop module enables to process payments with %s.');
        $this->description = sprintf($this->description, 'WeArePlanet');
        $this->module_key = '';
        $this->ps_versions_compliancy = array(
            'min' => '1.7',
            'max' => _PS_VERSION_
        );
        parent::__construct();
        $this->confirmUninstall = sprintf(
            $this->l('Are you sure you want to uninstall the %s module?', 'abstractmodule'),
            'WeArePlanet'
        );

        // Remove Fee Item
        if (isset($this->context->cart) && Validate::isLoadedObject($this->context->cart)) {
            WeArePlanetFeehelper::removeFeeSurchargeProductsFromCart($this->context->cart);
        }
        if (!empty($this->context->cookie->pln_error)) {
            $errors = $this->context->cookie->pln_error;
            if (is_string($errors)) {
                $this->context->controller->errors[] = $errors;
            } elseif (is_array($errors)) {
                foreach ($errors as $error) {
                    $this->context->controller->errors[] = $error;
                }
            }
            unset($_SERVER['HTTP_REFERER']); // To disable the back button in the error message
            $this->context->cookie->pln_error = null;
        }
    }

    public function addError($error)
    {
        $this->_errors[] = $error;
    }

    public function getContext()
    {
        return $this->context;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getIdentifier()
    {
        return $this->identifier;
    }

    public function install()
    {
        if (!WeArePlanetBasemodule::checkRequirements($this)) {
            return false;
        }
        if (!parent::install()) {
            return false;
        }
        return WeArePlanetBasemodule::install($this);
    }

    public function uninstall()
    {
        return parent::uninstall() && WeArePlanetBasemodule::uninstall($this);
    }

    public function installHooks()
    {
        return WeArePlanetBasemodule::installHooks($this) && $this->registerHook('paymentOptions') &&
            $this->registerHook('actionFrontControllerSetMedia');
    }

    public function getBackendControllers()
    {
        return array(
            'AdminWeArePlanetMethodSettings' => array(
                'parentId' => Tab::getIdFromClassName('AdminParentPayment'),
                'name' => 'WeArePlanet ' . $this->l('Payment Methods')
            ),
            'AdminWeArePlanetDocuments' => array(
                'parentId' => -1, // No Tab in navigation
                'name' => 'WeArePlanet ' . $this->l('Documents')
            ),
            'AdminWeArePlanetOrder' => array(
                'parentId' => -1, // No Tab in navigation
                'name' => 'WeArePlanet ' . $this->l('Order Management')
            ),
            'AdminWeArePlanetCronJobs' => array(
                'parentId' => Tab::getIdFromClassName('AdminTools'),
                'name' => 'WeArePlanet ' . $this->l('CronJobs')
            )
        );
    }

    public function installConfigurationValues()
    {
        return WeArePlanetBasemodule::installConfigurationValues();
    }

    public function uninstallConfigurationValues()
    {
        return WeArePlanetBasemodule::uninstallConfigurationValues();
    }

    public function getContent()
    {
        $output = WeArePlanetBasemodule::handleSaveAll($this);
        $output .= WeArePlanetBasemodule::handleSaveApplication($this);
        $output .= WeArePlanetBasemodule::handleSaveEmail($this);
        $output .= WeArePlanetBasemodule::handleSaveCartRecreation($this);
        $output .= WeArePlanetBasemodule::handleSaveFeeItem($this);
        $output .= WeArePlanetBasemodule::handleSaveDownload($this);
        $output .= WeArePlanetBasemodule::handleSaveSpaceViewId($this);
        $output .= WeArePlanetBasemodule::handleSaveOrderStatus($this);
        $output .= WeArePlanetBasemodule::handleSaveCronSettings($this);
        $output .= WeArePlanetBasemodule::handleSaveCheckoutTypeSettings($this);
        $output .= WeArePlanetBasemodule::displayHelpButtons($this);
        return $output . WeArePlanetBasemodule::displayForm($this);
    }

    public function getConfigurationForms()
    {
        return array(
            WeArePlanetBasemodule::getEmailForm($this),
            WeArePlanetBasemodule::getCartRecreationForm($this),
            WeArePlanetBasemodule::getFeeForm($this),
            WeArePlanetBasemodule::getDocumentForm($this),
            WeArePlanetBasemodule::getCheckoutTypeForm($this),
            WeArePlanetBasemodule::getSpaceViewIdForm($this),
            WeArePlanetBasemodule::getOrderStatusForm($this),
            WeArePlanetBasemodule::getCronSettingsForm($this),
        );
    }

    public function getConfigurationValues()
    {
        return array_merge(
            WeArePlanetBasemodule::getApplicationConfigValues($this),
            WeArePlanetBasemodule::getEmailConfigValues($this),
            WeArePlanetBasemodule::getCartRecreationConfigValues($this),
            WeArePlanetBasemodule::getFeeItemConfigValues($this),
            WeArePlanetBasemodule::getDownloadConfigValues($this),
            WeArePlanetBasemodule::getSpaceViewIdConfigValues($this),
            WeArePlanetBasemodule::getOrderStatusConfigValues($this),
            WeArePlanetBasemodule::getCronSettingsConfigValues($this),
            WeArePlanetBasemodule::getCheckoutTypeConfigValues($this)
        );
    }

    public function getConfigurationKeys()
    {
        return WeArePlanetBasemodule::getConfigurationKeys();
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }
        if (!isset($params['cart']) || !($params['cart'] instanceof Cart)) {
            return;
        }
        $cart = $params['cart'];
        try {
            $possiblePaymentMethods = WeArePlanetServiceTransaction::instance()->getPossiblePaymentMethods(
                $cart
            );
        } catch (WeArePlanetExceptionInvalidtransactionamount $e) {
            PrestaShopLogger::addLog($e->getMessage() . " CartId: " . $cart->id, 2, null, 'WeArePlanet');
            $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $paymentOption->setCallToActionText(
                $this->l('There is an issue with your cart, some payment methods are not available.')
            );
            $paymentOption->setAdditionalInformation(
                $this->context->smarty->fetch(
                    'module:weareplanet/views/templates/front/hook/amount_error.tpl'
                )
            );
            $paymentOption->setForm(
                $this->context->smarty->fetch(
                    'module:weareplanet/views/templates/front/hook/amount_error_form.tpl'
                )
            );
            $paymentOption->setModuleName($this->name . "-error");
            return array(
                $paymentOption
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog($e->getMessage() . " CartId: " . $cart->id, 1, null, 'WeArePlanet');
            return array();
        }
        $shopId = $cart->id_shop;
        $language = Context::getContext()->language->language_code;
        $methods = array();
        foreach ($possiblePaymentMethods as $possible) {
            $methodConfiguration = WeArePlanetModelMethodconfiguration::loadByConfigurationAndShop(
                $possible->getSpaceId(),
                $possible->getId(),
                $shopId
            );
            if (!$methodConfiguration->isActive()) {
                continue;
            }
            $methods[] = $methodConfiguration;
        }
        $result = array();

        $this->context->smarty->registerPlugin(
            'function',
            'weareplanet_clean_html',
            array(
                'WeArePlanetSmartyfunctions',
                'cleanHtml'
            )
        );

        foreach (WeArePlanetHelper::sortMethodConfiguration($methods) as $methodConfiguration) {
            $parameters = WeArePlanetBasemodule::getParametersFromMethodConfiguration($this, $methodConfiguration, $cart, $shopId, $language);
            $parameters['priceDisplayTax'] = Group::getPriceDisplayMethod(Group::getCurrent()->id);
            $parameters['orderUrl'] = $this->context->link->getModuleLink(
                'weareplanet',
                'order',
                array(),
                true
            );
            $parameters['isPaymentPageCheckout'] = Configuration::get(WeArePlanetBasemodule::CK_CHECKOUT_TYPE) === WeArePlanetBasemodule::CK_CHECKOUT_TYPE_PAYMENT_PAGE;
            $this->context->smarty->assign($parameters);
            $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $paymentOption->setCallToActionText($parameters['name']);
            $paymentOption->setLogo($parameters['image']);
            $paymentOption->setAction($parameters['link']);
            $paymentOption->setAdditionalInformation(
                $this->context->smarty->fetch(
                    'module:weareplanet/views/templates/front/hook/payment_additional.tpl'
                )
            );
            $paymentOption->setForm(
                $this->context->smarty->fetch(
                    'module:weareplanet/views/templates/front/hook/payment_form.tpl'
                )
            );
            $paymentOption->setModuleName($this->name);
            $result[] = $paymentOption;
        }
        return $result;
    }

    public function hookActionFrontControllerSetMedia($arr)
    {
        if ($this->context->controller->php_self == 'order' || $this->context->controller->php_self == 'cart') {
            $uniqueId = $this->context->cookie->pln_device_id;
            if ($uniqueId == false) {
                $uniqueId = WeArePlanetHelper::generateUUID();
                $this->context->cookie->pln_device_id = $uniqueId;
            }
            $scriptUrl = WeArePlanetHelper::getBaseGatewayUrl() . '/s/' . Configuration::get(
                WeArePlanetBasemodule::CK_SPACE_ID
            ) . '/payment/device.js?sessionIdentifier=' . $uniqueId;
            $this->context->controller->registerJavascript(
                'weareplanet-device-identifier',
                $scriptUrl,
                array(
                    'server' => 'remote',
                    'attributes' => 'async="async"'
                )
            );
        }
        if ($this->context->controller->php_self == 'order') {
            $this->context->controller->registerStylesheet(
                'weareplanet-checkut-css',
                'modules/' . $this->name . '/views/css/frontend/checkout.css'
            );
            $this->context->controller->registerJavascript(
                'weareplanet-checkout-js',
                'modules/' . $this->name . '/views/js/frontend/checkout.js'
            );
            Media::addJsDef(
                array(
                    'weArePlanetCheckoutUrl' => $this->context->link->getModuleLink(
                        'weareplanet',
                        'checkout',
                        array(),
                        true
                    ),
                    'weareplanetMsgJsonError' => $this->l(
                        'The server experienced an unexpected error, you may try again or try to use a different payment method.'
                    )
                )
            );
            Media::addJsDef(
                array(
                'weareplanetIsPaymentPageCheckout' => Configuration::get(WeArePlanetBasemodule::CK_CHECKOUT_TYPE) === WeArePlanetBasemodule::CK_CHECKOUT_TYPE_PAYMENT_PAGE
                )
            );
            if (isset($this->context->cart) && Validate::isLoadedObject($this->context->cart)) {
                try {
                    $jsUrl = WeArePlanetServiceTransaction::instance()->getJavascriptUrl($this->context->cart);
                    $this->context->controller->registerJavascript(
                        'weareplanet-iframe-handler',
                        $jsUrl,
                        array(
                            'server' => 'remote',
                            'priority' => 45,
                            'attributes' => 'id="weareplanet-iframe-handler"'
                        )
                    );
                } catch (Exception $e) {
                }
            }
        }
        if ($this->context->controller->php_self == 'order-detail') {
            $this->context->controller->registerJavascript(
                'weareplanet-checkout-js',
                'modules/' . $this->name . '/views/js/frontend/orderdetail.js'
            );
        }
    }

    public function hookDisplayTop($params)
    {
        return  WeArePlanetBasemodule::hookDisplayTop($this, $params);
    }

    public function hookActionAdminControllerSetMedia($arr)
    {
        WeArePlanetBasemodule::hookActionAdminControllerSetMedia($this, $arr);
        $this->context->controller->addCSS(__PS_BASE_URI__ . 'modules/' . $this->name . '/views/css/admin/general.css');
    }

    public function hasBackendControllerDeleteAccess(AdminController $backendController)
    {
        return $backendController->access('delete');
    }

    public function hasBackendControllerEditAccess(AdminController $backendController)
    {
        return $backendController->access('edit');
    }

    public function hookWeArePlanetCron($params)
    {
        return WeArePlanetBasemodule::hookWeArePlanetCron($params);
    }
    /**
     * Show the manual task in the admin bar.
     * The output is moved with javascript to the correct place as better hook is missing.
     *
     * @return string
     */
    public function hookDisplayAdminAfterHeader()
    {
        $result = WeArePlanetBasemodule::hookDisplayAdminAfterHeader($this);
        $result .= WeArePlanetBasemodule::getCronJobItem($this);
        return $result;
    }

    public function hookWeArePlanetSettingsChanged($params)
    {
        return WeArePlanetBasemodule::hookWeArePlanetSettingsChanged($this, $params);
    }

    public function hookActionMailSend($data)
    {
        return WeArePlanetBasemodule::hookActionMailSend($this, $data);
    }

    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null
    ) {
        WeArePlanetBasemodule::validateOrder($this, $id_cart, $id_order_state, $amount_paid, $payment_method, $message, $extra_vars, $currency_special, $dont_touch_amount, $secure_key, $shop);
    }

    public function validateOrderParent(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null
    ) {
        parent::validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method, $message, $extra_vars, $currency_special, $dont_touch_amount, $secure_key, $shop);
    }

    public function hookDisplayOrderDetail($params)
    {
        return WeArePlanetBasemodule::hookDisplayOrderDetail($this, $params);
    }

    public function hookDisplayBackOfficeHeader($params)
    {
        WeArePlanetBasemodule::hookDisplayBackOfficeHeader($this, $params);
    }

    public function hookDisplayAdminOrderLeft($params)
    {
        return WeArePlanetBasemodule::hookDisplayAdminOrderLeft($this, $params);
    }

    public function hookDisplayAdminOrderTabOrder($params)
    {
        return WeArePlanetBasemodule::hookDisplayAdminOrderTabOrder($this, $params);
    }

    public function hookDisplayAdminOrderMain($params)
    {
        return WeArePlanetBasemodule::hookDisplayAdminOrderMain($this, $params);
    }

    /**
     * This hook triggers after submitting the form of Partial Refund.
     *
     * @param [type] $params
     * @return void
     */
    public function hookActionAfterUpdateCancelProductFormHandler($params)
    {
        // We are only interested in processing here a partial refund that involves shipping costs.
        // Other partial refunds (normal product line items) are processed in hookActionProductCancel.
        if (!empty($params['route']) && $params['route'] == "admin_orders_partial_refund" &&
            !empty($params["form_data"]["shipping_amount"]) && $params["form_data"]["shipping_amount"] != "0.00" &&
            !empty($params['id']) && is_int($params['id']) && $params['id'] > 0) {
            $order_id = $params['id'];
            $order = new Order((int) $order_id);
            $refundParameters = Tools::getAllValues();

            if (!Validate::isLoadedObject($order) || $order->module != $this->name) {
                // Only process when our plugin is involved.
                return;
            }

            $strategy = WeArePlanetBackendStrategyprovider::getStrategy();

            if ($strategy->isVoucherOnlyWeArePlanet($order, $refundParameters)) {
                return;
            }

            // need to manually set this here as it's expected downstream
            $refundParameters['partialRefund'] = true;

            $backendController = Context::getContext()->controller;
            try {
                $parsedData = $strategy->simplifiedRefund($refundParameters);
                WeArePlanetServiceRefund::instance()->executeRefund($order, $parsedData);
            } catch (Exception $e) {
                $backendController->errors[] = WeArePlanetHelper::cleanExceptionMessage($e->getMessage());
            }
        }
    }

    public function hookDisplayAdminOrderTabLink($params)
    {
        return WeArePlanetBasemodule::hookDisplayAdminOrderTabLink($this, $params);
    }

    public function hookDisplayAdminOrderContentOrder($params)
    {
        return WeArePlanetBasemodule::hookDisplayAdminOrderContentOrder($this, $params);
    }

    public function hookDisplayAdminOrderTabContent($params)
    {
        return WeArePlanetBasemodule::hookDisplayAdminOrderTabContent($this, $params);
    }

    public function hookDisplayAdminOrder($params)
    {
        return WeArePlanetBasemodule::hookDisplayAdminOrder($this, $params);
    }

    public function hookActionAdminOrdersControllerBefore($params)
    {
        return WeArePlanetBasemodule::hookActionAdminOrdersControllerBefore($this, $params);
    }

    public function hookActionObjectOrderPaymentAddBefore($params)
    {
        WeArePlanetBasemodule::hookActionObjectOrderPaymentAddBefore($this, $params);
    }

    public function hookActionOrderEdited($params)
    {
        WeArePlanetBasemodule::hookActionOrderEdited($this, $params);
    }

    public function hookActionOrderGridDefinitionModifier($params)
    {
        WeArePlanetBasemodule::hookActionOrderGridDefinitionModifier($this, $params);
    }

    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        WeArePlanetBasemodule::hookActionOrderGridQueryBuilderModifier($this, $params);
    }

    public function hookActionProductCancel($params)
    {
        // check version too here to only run on > 1.7.7 for now
        // as there is some overlap in functionality with some previous versions 1.7+
        if ($params['action'] === CancellationActionType::PARTIAL_REFUND && version_compare(_PS_VERSION_, '1.7.7', '>=')) {
            $idOrder = Tools::getValue('id_order');
            $refundParameters = Tools::getAllValues();

            $order = $params['order'];

            if (!Validate::isLoadedObject($order) || $order->module != $this->name) {
                return;
            }

            $strategy = WeArePlanetBackendStrategyprovider::getStrategy();
            if ($strategy->isVoucherOnlyWeArePlanet($order, $refundParameters)) {
                return;
            }

            // need to manually set this here as it's expected downstream
            $refundParameters['partialRefund'] = true;

            $backendController = Context::getContext()->controller;
            $editAccess = 0;

            $access = Profile::getProfileAccess(
                Context::getContext()->employee->id_profile,
                (int) Tab::getIdFromClassName('AdminOrders')
            );
            $editAccess = isset($access['edit']) && $access['edit'] == 1;

            if ($editAccess) {
                try {
                    $parsedData = $strategy->simplifiedRefund($refundParameters);
                    WeArePlanetServiceRefund::instance()->executeRefund($order, $parsedData);
                } catch (Exception $e) {
                    $backendController->errors[] = WeArePlanetHelper::cleanExceptionMessage($e->getMessage());
                }
            } else {
                $backendController->errors[] = Tools::displayError('You do not have permission to delete this.');
            }
        }
    }
}
