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

use WeArePlanet\Sdk\Model\LineItemType;
/**
 * This service provides functions to deal with WeArePlanet refunds.
 */
class WeArePlanetServiceRefund extends WeArePlanetServiceAbstract
{
    private static $refundableStates = array(
        \WeArePlanet\Sdk\Model\TransactionState::COMPLETED,
        \WeArePlanet\Sdk\Model\TransactionState::DECLINE,
        \WeArePlanet\Sdk\Model\TransactionState::FULFILL
    );

    /**
     * The refund API service.
     *
     * @var \WeArePlanet\Sdk\Service\RefundService
     */
    private $refundService;

    /**
     * Returns the refund by the given external id.
     *
     * @param int $spaceId
     * @param string $externalId
     * @return \WeArePlanet\Sdk\Model\Refund
     */
    public function getRefundByExternalId($spaceId, $externalId)
    {
        $query = new \WeArePlanet\Sdk\Model\EntityQuery();
        $query->setFilter($this->createEntityFilter('externalId', $externalId));
        $query->setNumberOfEntities(1);
        $result = $this->getRefundService()->search($spaceId, $query);
        if ($result != null && ! empty($result)) {
            return current($result);
        } else {
            throw new Exception('The refund could not be found.');
        }
    }

    /**
     * Executes the refund, saving it in the database (via database transaction)
     * and then sending the refund information to the portal.
     *
     * @param Order $order
     * @param array $parsedParameters
     * @return void
     *
     * @see hookActionProductCancel
     */
    public function executeRefund(Order $order, array $parsedParameters)
    {
        $currentRefundJob = null;
        try {
            WeArePlanetHelper::startDBTransaction();
            $transactionInfo = WeArePlanetHelper::getTransactionInfoForOrder($order);
            if ($transactionInfo === null) {
                throw new Exception(
                    WeArePlanetHelper::getModuleInstance()->l(
                        'Could not load corresponding transaction',
                        'refund'
                    )
                );
            }

            WeArePlanetHelper::lockByTransactionId(
                $transactionInfo->getSpaceId(),
                $transactionInfo->getTransactionId()
            );
            // Reload after locking
            $transactionInfo = WeArePlanetModelTransactioninfo::loadByTransaction(
                $transactionInfo->getSpaceId(),
                $transactionInfo->getTransactionId()
            );
            $spaceId = $transactionInfo->getSpaceId();
            $transactionId = $transactionInfo->getTransactionId();

            if (! in_array($transactionInfo->getState(), self::$refundableStates)) {
                throw new Exception(
                    WeArePlanetHelper::getModuleInstance()->l(
                        'The transaction is not in a state to be refunded.',
                        'refund'
                    )
                );
            }

            if (WeArePlanetModelRefundjob::isRefundRunningForTransaction($spaceId, $transactionId)) {
                throw new Exception(
                    WeArePlanetHelper::getModuleInstance()->l(
                        'Please wait until the existing refund is processed.',
                        'refund'
                    )
                );
            }

            $refundJob = new WeArePlanetModelRefundjob();
            $refundJob->setState(WeArePlanetModelRefundjob::STATE_CREATED);
            $refundJob->setOrderId($order->id);
            $refundJob->setSpaceId($transactionInfo->getSpaceId());
            $refundJob->setTransactionId($transactionInfo->getTransactionId());
            $refundJob->setExternalId(uniqid($order->id . '-'));
            $refundJob->setRefundParameters($parsedParameters);
            $refundJob->save();
            // validate Refund Job
            $this->createRefundObject($refundJob);
            $currentRefundJob = $refundJob->getId();
            WeArePlanetHelper::commitDBTransaction();
        } catch (Exception $e) {
            WeArePlanetHelper::rollbackDBTransaction();
            throw $e;
        }
        $this->sendRefund($currentRefundJob);
    }

    protected function sendRefund($refundJobId)
    {
        $refundJob = new WeArePlanetModelRefundjob($refundJobId);
        WeArePlanetHelper::startDBTransaction();
        WeArePlanetHelper::lockByTransactionId($refundJob->getSpaceId(), $refundJob->getTransactionId());
        // Reload refund job;
        $refundJob = new WeArePlanetModelRefundjob($refundJobId);
        if ($refundJob->getState() != WeArePlanetModelRefundjob::STATE_CREATED) {
            // Already sent in the meantime
            WeArePlanetHelper::rollbackDBTransaction();
            return;
        }
        try {
            $executedRefund = $this->refund($refundJob->getSpaceId(), $this->createRefundObject($refundJob));
            $refundJob->setState(WeArePlanetModelRefundjob::STATE_SENT);
            $refundJob->setRefundId($executedRefund->getId());

            if ($executedRefund->getState() == \WeArePlanet\Sdk\Model\RefundState::PENDING) {
                $refundJob->setState(WeArePlanetModelRefundjob::STATE_PENDING);
            }
            $refundJob->save();
            WeArePlanetHelper::commitDBTransaction();
        } catch (\WeArePlanet\Sdk\ApiException $e) {
            if ($e->getResponseObject() instanceof \WeArePlanet\Sdk\Model\ClientError) {
                $refundJob->setFailureReason(
                    array(
                        'en-US' => sprintf(
                            WeArePlanetHelper::getModuleInstance()->l(
                                'Could not send the refund to %s. Error: %s',
                                'refund'
                            ),
                            'WeArePlanet',
                            WeArePlanetHelper::cleanExceptionMessage($e->getMessage())
                        )
                    )
                );
                $refundJob->setState(WeArePlanetModelRefundjob::STATE_FAILURE);
                $refundJob->save();
                WeArePlanetHelper::commitDBTransaction();
            } else {
                $refundJob->save();
                WeArePlanetHelper::commitDBTransaction();
                $message = sprintf(
                    WeArePlanetHelper::getModuleInstance()->l(
                        'Error sending refund job with id %d: %s',
                        'refund'
                    ),
                    $refundJobId,
                    $e->getMessage()
                );
                PrestaShopLogger::addLog($message, 3, null, 'WeArePlanetModelRefundjob');
                throw $e;
            }
        } catch (Exception $e) {
            $refundJob->save();
            WeArePlanetHelper::commitDBTransaction();
            $message = sprintf(
                WeArePlanetHelper::getModuleInstance()->l('Error sending refund job with id %d: %s', 'refund'),
                $refundJobId,
                $e->getMessage()
            );
            PrestaShopLogger::addLog($message, 3, null, 'WeArePlanetModelRefundjob');
            throw $e;
        }
    }

    /**
     * This functionality is called from a webhook, triggered by the portal.
     * Updates the status of the order in the shop.
     *
     * @param [type] $refundJobId
     * @return void
     *
     * @see WeArePlanetWebhookRefund::process
     */
    public function applyRefundToShop($refundJobId)
    {
        $refundJob = new WeArePlanetModelRefundjob($refundJobId);
        WeArePlanetHelper::startDBTransaction();
        WeArePlanetHelper::lockByTransactionId($refundJob->getSpaceId(), $refundJob->getTransactionId());
        // Reload refund job;
        $refundJob = new WeArePlanetModelRefundjob($refundJobId);
        if ($refundJob->getState() != WeArePlanetModelRefundjob::STATE_APPLY) {
            // Already processed in the meantime
            WeArePlanetHelper::rollbackDBTransaction();
            return;
        }
        try {
            $order = new Order($refundJob->getOrderId());
            $strategy = WeArePlanetBackendStrategyprovider::getStrategy();
            $appliedData = $strategy->applyRefund($order, $refundJob->getRefundParameters());
            $refundJob->setState(WeArePlanetModelRefundjob::STATE_SUCCESS);
            $refundJob->save();
            WeArePlanetHelper::commitDBTransaction();
            try {
                $strategy->afterApplyRefundActions($order, $refundJob->getRefundParameters(), $appliedData);
            } catch (Exception $e) {
                // We ignore errors in the after apply actions
            }
        } catch (Exception $e) {
            WeArePlanetHelper::rollbackDBTransaction();
            WeArePlanetHelper::startDBTransaction();
            WeArePlanetHelper::lockByTransactionId($refundJob->getSpaceId(), $refundJob->getTransactionId());
            $refundJob = new WeArePlanetModelRefundjob($refundJobId);
            $refundJob->increaseApplyTries();
            if ($refundJob->getApplyTries() > 3) {
                $refundJob->setState(WeArePlanetModelRefundjob::STATE_FAILURE);
                $refundJob->setFailureReason(array(
                    'en-US' => sprintf($e->getMessage())
                ));
            }
            $refundJob->save();
            WeArePlanetHelper::commitDBTransaction();
        }
    }

    public function updateForOrder($order)
    {
        $transactionInfo = WeArePlanetHelper::getTransactionInfoForOrder($order);
        $spaceId = $transactionInfo->getSpaceId();
        $transactionId = $transactionInfo->getTransactionId();
        $refundJob = WeArePlanetModelRefundjob::loadRunningRefundForTransaction($spaceId, $transactionId);
        if ($refundJob->getState() == WeArePlanetModelRefundjob::STATE_CREATED) {
            $this->sendRefund($refundJob->getId());
        } elseif ($refundJob->getState() == WeArePlanetModelRefundjob::STATE_APPLY) {
            $this->applyRefundToShop($refundJob->getId());
        }
    }

    public function updateRefunds($endTime = null)
    {
        $toSend = WeArePlanetModelRefundjob::loadNotSentJobIds();
        foreach ($toSend as $id) {
            if ($endTime !== null && time() + 15 > $endTime) {
                return;
            }
            try {
                $this->sendRefund($id);
            } catch (Exception $e) {
                $message = sprintf(
                    WeArePlanetHelper::getModuleInstance()->l(
                        'Error updating refund job with id %d: %s',
                        'refund'
                    ),
                    $id,
                    $e->getMessage()
                );
                PrestaShopLogger::addLog($message, 3, null, 'WeArePlanetModelRefundjob');
            }
        }
        $toApply = WeArePlanetModelRefundjob::loadNotAppliedJobIds();
        foreach ($toApply as $id) {
            if ($endTime !== null && time() + 15 > $endTime) {
                return;
            }
            try {
                $this->applyRefundToShop($id);
            } catch (Exception $e) {
                $message = sprintf(
                    WeArePlanetHelper::getModuleInstance()->l(
                        'Error applying refund job with id %d: %s',
                        'refund'
                    ),
                    $id,
                    $e->getMessage()
                );
                PrestaShopLogger::addLog($message, 3, null, 'WeArePlanetModelRefundjob');
            }
        }
    }

    public function hasPendingRefunds()
    {
        $toSend = WeArePlanetModelRefundjob::loadNotSentJobIds();
        $toApply = WeArePlanetModelRefundjob::loadNotAppliedJobIds();
        return ! empty($toSend) || ! empty($toApply);
    }

    /**
     * Creates a refund request model for the given parameters.
     *
     * @param Order $order
     * @param array $refund
     *            Refund data to be determined
     * @return \WeArePlanet\Sdk\Model\RefundCreate
     */
    protected function createRefundObject(WeArePlanetModelRefundjob $refundJob)
    {
        $order = new Order($refundJob->getOrderId());

        $strategy = WeArePlanetBackendStrategyprovider::getStrategy();

        $spaceId = $refundJob->getSpaceId();
        $transactionId = $refundJob->getTransactionId();
        $externalRefundId = $refundJob->getExternalId();
        $parsedData = $refundJob->getRefundParameters();
        $amount = $strategy->getRefundTotal($parsedData);
        $type = $strategy->getWeArePlanetRefundType($parsedData);

        $reductions = $strategy->createReductions($order, $parsedData);
        $reductions = $this->fixReductions($amount, $spaceId, $transactionId, $reductions, $order);

        $remoteRefund = new \WeArePlanet\Sdk\Model\RefundCreate();
        $remoteRefund->setExternalId($externalRefundId);
        $remoteRefund->setReductions($reductions);
        $remoteRefund->setTransaction($transactionId);
        $remoteRefund->setType($type);

        return $remoteRefund;
    }

    /**
     * Returns the fixed line item reductions for the refund.
     *
     * If the amount of the given reductions does not match the refund's grand total, the amount to refund is
     * distributed equally to the line items.
     *
     * @param float $refundTotal
     * @param int $spaceId
     * @param int $transactionId
     * @param \WeArePlanet\Sdk\Model\LineItemReductionCreate[] $reductions
     * @return \WeArePlanet\Sdk\Model\LineItemReductionCreate[]
     */
    protected function fixReductions($refundTotal, $spaceId, $transactionId, array $reductions, Order $order)
    {
        $baseLineItems = $this->getBaseLineItems($spaceId, $transactionId);
        $reductionAmount = WeArePlanetHelper::getReductionAmount($baseLineItems, $reductions);
        // We try to get the information about which Line Items need to be reduced.
        // We have this information in the REQUEST object.
        $line_items = $order->getProducts();
        $line_items_ids = [];
        foreach ($line_items as $line_item) {
            $line_items_ids[$line_item['product_reference']] = [
                'unit_price' => $line_item['unit_price_tax_incl'],
                'id' => $line_item['id_order_detail'],
            ];
        }

        $fixedReductions = [];
        $refunded_value = 0;
        foreach ($baseLineItems as $lineItem) {
            
            if ($lineItem->getType() === LineItemType::SHIPPING) {
                foreach ($reductions as $reduction) {
                    if (strpos($reduction['line_item_unique_id'], strtolower(LineItemType::SHIPPING)) !== false) {
                        $fixedReductions[] = $reduction;
                        break;
                    }
                }
                continue;
            }

            // The way of identify the lineItem is based on the position in the array.
            $sku = $lineItem->getSku();
            if (!(empty($line_items_ids[$sku]['id']))) {
                $line_item_id = $line_items_ids[$sku]['id'];
                $unit_price = (float) $line_items_ids[$sku]['unit_price'];
                if (!(empty($_REQUEST['cancel_product']['amount_' . $line_item_id]))) {
                    $refund_value = (float) $_REQUEST['cancel_product']['amount_' . $line_item_id];
                    if ($refund_value > 0 && $refund_value <= $refundTotal) {
                        $reduction = new \WeArePlanet\Sdk\Model\LineItemReductionCreate();
                        $reduction->setLineItemUniqueId($lineItem->getUniqueId());

                        // Prestashop forces to, at least, refund a quantity of 1.
                        $quantity = 1;
                        if (!empty($_REQUEST['cancel_product']['quantity_' . $line_item_id])) {
                            $quantity = (int) $_REQUEST['cancel_product']['quantity_' . $line_item_id];
                        }

                        // Prestashop allows to refund whole quantites (the whole unit price), or a reduced value between the unit price.
                        // For example, if unit price was 30, and the customer got 10 of them, Prestashop expects the admin to refund a
                        // quantity of items and then allow reduce the value to be refunded. So, the admin can:
                        // - Refund 2 items => 2*30 = 60
                        // - Refund 2 items and reduce the refund value => A value less than 60
                        // - But not to refund 2 items and increase the refund value => A value bigger than 60. If the user does this in the form,
                        //   the system does not complain but the total amount refunded is still 60.
                        //
                        // We need to accomodate here also what is expected by the SDK
                        // In the SDK, we can set how many quantites can be refunded. But we cannot decrease the value to refund after setting the
                        // quantity. So, following the example:
                        // - Refund just 2 items => setQuantityReduction(2), setUnitPriceReduction(0)
                        // - Refund 2 items and reduce the refund value => depending on the value to reduce, if it's bigger than unit price.
                        // - - If for example the reduce value is 20 (less than 30): setQuantityReduction(0), setUnitPriceReduction(20)
                        // - - If the reduce value is 40 (more than 30): setQuantityReduction(1), setUnitPriceReduction(10)
                        // - Refund 2 items and increase the refund value => Replicate how Prestashop works, this is, just refund as many items and
                        //   ignore the rest: setQuantityReduction(2), setUnitPriceReduction(0)
                        //
                        // On top of that, we need to send the data to the SDK using its format. The SDK can contain a unit_price lower than the
                        // one in the shop. This happens if there has been previous refundings. The same happens with the number of items that
                        // potentially can yet be refunded. So we need to use the SDK values for the calculations.
                        // Finally, the setUnitPriceReduction method will reduce the given value equally through all the remaining items in the line.
                        // This is why we divide, right before setting, the amount to be refunded by all the remaining items in the line. The SDK,
                        // back in the portal, will undo this operation and refund the money we are expecting to refund.

                        $unit_price_sdk = $lineItem->getUnitPriceIncludingTax();
                        $line_quantity_sdk = $lineItem->getQuantity();

                        $max_to_refund = $unit_price * $quantity;
                        $max_to_refund = floor($max_to_refund * 100) / 100;
                        if ($max_to_refund <= $refund_value) {
                            $reduction->setQuantityReduction($quantity);
                            $div = $line_quantity_sdk - $quantity;
                            
                            if ($div > 0) {
                                $unitPriceReductionAmount = floor(abs((float)$unit_price - $unit_price_sdk) * 100) / 100 / $div;
                            } else {
                                $unitPriceReductionAmount = floor(abs((float)$unit_price - $unit_price_sdk) * 100) / 100;
                            }
                            $reduction->setUnitPriceReduction($unitPriceReductionAmount);
                        } else {
                            $items_to_refund = (int) floor(abs($refund_value / $unit_price_sdk));
                            $rest_from_whole_item = floor(abs((100 * $refund_value) % (100 * $unit_price_sdk))) / 100;
                            $reduction->setQuantityReduction($items_to_refund);
                            $divisor = $line_quantity_sdk - $items_to_refund;
                            if ($divisor > 0) {
                                $reduction->setUnitPriceReduction($rest_from_whole_item / $divisor);
                            } else {
                                $reduction->setUnitPriceReduction($rest_from_whole_item);
                            }
                        }

                        $fixedReductions[] = $reduction;
                        $refunded_value += $refund_value;
                    }
                }
            }
        }

        if ($refunded_value <= $refundTotal && !empty($fixedReductions)) {
            return $fixedReductions;
        }

        $fixedReductions = [];
        $configuration = WeArePlanetVersionadapter::getConfigurationInterface();
        $computePrecision = $configuration->get('_PS_PRICE_COMPUTE_PRECISION_');

        if (Tools::ps_round($refundTotal, $computePrecision) != Tools::ps_round($reductionAmount, $computePrecision)) {
            $fixedReductions = array();
            $baseAmount = WeArePlanetHelper::getTotalAmountIncludingTax($baseLineItems);
            $rate = $refundTotal / $baseAmount;
            foreach ($baseLineItems as $lineItem) {
                $reduction = new \WeArePlanet\Sdk\Model\LineItemReductionCreate();
                $reduction->setLineItemUniqueId($lineItem->getUniqueId());
                $reduction->setQuantityReduction(0);
                $reduction->setUnitPriceReduction(
                    round($lineItem->getAmountIncludingTax() * $rate / $lineItem->getQuantity(), 8)
                );
                $fixedReductions[] = $reduction;
            }

            return $fixedReductions;
        } else {
            return $reductions;
        }
    }

    /**
     * Sends the refund to the gateway.
     *
     * @param int $spaceId
     * @param \WeArePlanet\Sdk\Model\RefundCreate $refund
     * @return \WeArePlanet\Sdk\Model\Refund
     */
    public function refund($spaceId, \WeArePlanet\Sdk\Model\RefundCreate $refund)
    {
        return $this->getRefundService()->refund($spaceId, $refund);
    }

    /**
     * Returns the line items that are to be used to calculate the refund.
     *
     * This returns the line items of the latest refund if there is one or else of the completed transaction.
     *
     * @param int $spaceId
     * @param int $transactionId
     * @param \WeArePlanet\Sdk\Model\Refund $refund
     * @return \WeArePlanet\Sdk\Model\LineItem[]
     */
    protected function getBaseLineItems($spaceId, $transactionId, \WeArePlanet\Sdk\Model\Refund $refund = null)
    {
        $lastSuccessfulRefund = $this->getLastSuccessfulRefund($spaceId, $transactionId, $refund);
        if ($lastSuccessfulRefund) {
            return $lastSuccessfulRefund->getReducedLineItems();
        } else {
            return $this->getTransactionInvoice($spaceId, $transactionId)->getLineItems();
        }
    }

    /**
     * Returns the transaction invoice for the given transaction.
     *
     * @param int $spaceId
     * @param int $transactionId
     * @throws Exception
     * @return \WeArePlanet\Sdk\Model\TransactionInvoice
     */
    protected function getTransactionInvoice($spaceId, $transactionId)
    {
        $query = new \WeArePlanet\Sdk\Model\EntityQuery();

        $filter = new \WeArePlanet\Sdk\Model\EntityQueryFilter();
        $filter->setType(\WeArePlanet\Sdk\Model\EntityQueryFilterType::_AND);
        $filter->setChildren(
            array(
                $this->createEntityFilter(
                    'state',
                    \WeArePlanet\Sdk\Model\TransactionInvoiceState::CANCELED,
                    \WeArePlanet\Sdk\Model\CriteriaOperator::NOT_EQUALS
                ),
                $this->createEntityFilter('completion.lineItemVersion.transaction.id', $transactionId)
            )
        );
        $query->setFilter($filter);
        $query->setNumberOfEntities(1);
        $invoiceService = new \WeArePlanet\Sdk\Service\TransactionInvoiceService(
            WeArePlanetHelper::getApiClient()
        );
        $result = $invoiceService->search($spaceId, $query);
        if (! empty($result)) {
            return $result[0];
        } else {
            throw new Exception('The transaction invoice could not be found.');
        }
    }

    /**
     * Returns the last successful refund of the given transaction, excluding the given refund.
     *
     * @param int $spaceId
     * @param int $transactionId
     * @param \WeArePlanet\Sdk\Model\Refund $refund
     * @return \WeArePlanet\Sdk\Model\Refund
     */
    protected function getLastSuccessfulRefund(
        $spaceId,
        $transactionId,
        \WeArePlanet\Sdk\Model\Refund $refund = null
    ) {
        $query = new \WeArePlanet\Sdk\Model\EntityQuery();

        $filter = new \WeArePlanet\Sdk\Model\EntityQueryFilter();
        $filter->setType(\WeArePlanet\Sdk\Model\EntityQueryFilterType::_AND);
        $filters = array(
            $this->createEntityFilter('state', \WeArePlanet\Sdk\Model\RefundState::SUCCESSFUL),
            $this->createEntityFilter('transaction.id', $transactionId)
        );
        if ($refund != null) {
            $filters[] = $this->createEntityFilter(
                'id',
                $refund->getId(),
                \WeArePlanet\Sdk\Model\CriteriaOperator::NOT_EQUALS
            );
        }

        $filter->setChildren($filters);
        $query->setFilter($filter);

        $query->setOrderBys(
            array(
                $this->createEntityOrderBy('createdOn', \WeArePlanet\Sdk\Model\EntityQueryOrderByType::DESC)
            )
        );
        $query->setNumberOfEntities(1);

        $result = $this->getRefundService()->search($spaceId, $query);
        if (! empty($result)) {
            return $result[0];
        } else {
            return false;
        }
    }

    /**
     * Returns the refund API service.
     *
     * @return \WeArePlanet\Sdk\Service\RefundService
     */
    protected function getRefundService()
    {
        if ($this->refundService == null) {
            $this->refundService = new \WeArePlanet\Sdk\Service\RefundService(
                WeArePlanetHelper::getApiClient()
            );
        }

        return $this->refundService;
    }
}
