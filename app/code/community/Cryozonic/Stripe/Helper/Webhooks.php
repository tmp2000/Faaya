<?php
/**
 * Cryozonic
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Single Domain License
 * that is available through the world-wide-web at this URL:
 * http://cryozonic.com/licenses/stripe.html
 * If you are unable to obtain it through the world-wide-web,
 * please send an email to info@cryozonic.com so we can send
 * you a copy immediately.
 *
 * @category   Cryozonic
 * @package    Cryozonic_Stripe
 * @copyright  Copyright (c) Cryozonic Ltd (http://cryozonic.com)
 */

class Cryozonic_Stripe_Helper_Webhooks extends Mage_Payment_Helper_Data
{
    public function dispatchEvent()
    {
        try
        {
            // Retrieve the request's body and parse it as JSON
            $body = Mage::app()->getRequest()->getRawBody();
            $event = json_decode($body, true);
            $stdEvent = json_decode($body);

            if (empty($event['type']))
                throw new Exception("Unknown event type");

            $eventType = "cryozonic_stripe_webhook_" . str_replace(".", "_", $event['type']);

            if (isset($event['data']['object']['type'])) // Bancontact, Giropay, iDEAL
                $eventType .= "_" . $event['data']['object']['type'];
            else if (isset($event['data']['object']['source']['type'])) // SOFORT and SEPA
                $eventType .= "_" . $event['data']['object']['source']['type'];

            $this->log("Received $eventType");

            Mage::dispatchEvent($eventType, array('event' => $event, 'stdEvent' => $stdEvent, 'object' => $event['data']['object']));

            $this->cache($event);

            $this->log("200 OK");
        }
        catch (Exception $e)
        {
            Mage::logException($e);
            $this->error($e->getMessage(), $e->getCode());
        }
    }

    public function error($msg, $status = null)
    {
        if ($status && $status > 0)
            $responseStatus = $status;
        else
            $responseStatus = 400;

        Mage::app()->getResponse()
            ->setHeader('HTTP/1.1', $responseStatus, true)
            ->setBody($msg);

        $this->log("$responseStatus $msg");
    }

    public function log($msg)
    {
        Mage::log($msg, null, 'cryozonic_stripe_webhooks.log');
    }

    public function cache($event)
    {
        // Don't cache or check requests in development
        if (Mage::app()->getRequest()->getParam('dev'))
            return;

        if (empty($event['id']))
            throw new Exception("No event ID specified");

        $cache = Mage::app()->getCache();

        if ($cache->load($event['id']))
            throw new Exception("Event with ID {$event['id']} has already been processed.", 409);

        $cache->save("processed", $event['id'], array('cryozonic_stripe_webhooks_events_processed'), 24 * 60 * 60);
    }

    public function loadOrderFromEvent($event)
    {
        $object = $event['data']['object'];

        if (isset($object['metadata']['Order #'])) // source.* events
            $orderId = $object['metadata']['Order #'];
        else if (isset($object['source']['metadata']['Order #'])) // charge.* events
            $orderId = $object['source']['metadata']['Order #'];
        else
            throw new Exception("Received {$event['type']} webhook but there was no Order # in the source's metadata", 202);

        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        if (!$order)
            throw new Exception("Received {$event['type']} webhook with Order #$orderId but could not find the order in Magento", 202);

        // For multi-stripe account configurations, load the correct Stripe API key from the correct store view
        Mage::app()->setCurrentStore($order->getStoreId());

        return $order;
    }

    // Called after a source.chargable event
    public function charge($order, $object, $module, $addTransaction = true)
    {
        $orderId = $order->getIncrementId();

        $payment = $order->getPayment();
        if (!$payment)
            throw new Exception("Could not load payment method for order #$orderId");

        $orderSourceId = $payment->getAdditionalInformation('source_id');
        $webhookSourceId = $object['id'];
        if ($orderSourceId != $webhookSourceId)
            throw new Exception("Received source.chargeable webhook for order #$orderId but the source ID on the webhook $webhookSourceId was different than the one on the order $orderSourceId");

        // Authorize Only is not supported for this payment type
        // if (Mage::getStoreConfig('payment/cryozonic_stripe/payment_action') == Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE)
        //     $capture = true;
        // else
        //     $capture = false;

        $stripe = Mage::helper($module)->getStripe();

        // Reusable sources may not have an amount set
        if (empty($object['amount']))
        {
            $params = $stripe->getStripeParamsFrom($order);
            $amount = $params['amount'];
        }
        else
        {
            $amount = $object['amount'];
        }

        $params = array(
            "amount" => $amount,
            "currency" => $object['currency'],
            "source" => $webhookSourceId,
            "description" => "Order #" . $orderId . ' by ' . $order->getCustomerName()
            // "capture" => $capture // Not supported by Stripe
        );

        // For reusable sources, we will always need a customer ID
        $customerStripeId = $payment->getAdditionalInformation('customer_stripe_id');
        if (!empty($customerStripeId))
        {
            $params["customer"] = $customerStripeId;
        }
        // For other payment methods, only registered Magento customers will be assigned to charges
        // because we do not have any guest customer session ID in a webhook to check for security
        else
        {
            $customerStripeId = $stripe->getCustomerStripeId($order->getCustomerId());
            if ($customerStripeId)
                $params["customer"] = $customerStripeId;
        }

        try
        {
            $charge = \Stripe\Charge::create($params);

            // Possibly log additional info about the payment
            $info = $object[$object['type']];
            unset($info['mandate_url']);
            unset($info['fingerprint']);
            unset($info['client_token']);
            $payment->setTransactionId($charge->id);
            $payment->setIsTransactionClosed(0);
            $payment->setAdditionalInformation('address_line1_check', $charge->source->address_line1_check);
            $payment->setAdditionalInformation('address_zip_check', $charge->source->address_zip_check);
            $payment->setAdditionalInformation('source_info', json_encode($info));
            $payment->save();

            if ($addTransaction)
            {
                if ($charge->status == 'pending')
                    $transactionType = Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH;
                else
                    $transactionType = Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE;

                $transaction = $payment->addTransaction($transactionType, null, false);
                $transaction->save();
            }

            if ($charge->status == 'succeeded')
            {
                $invoice = Mage::helper('cryozonic_stripe')->invoiceOrder($order, $charge->id);
                $this->sendNewOrderEmailFor($order);
            }
            // SEPA, SOFORT and other asynchronous methods will be pending
            else if ($charge->status == 'pending')
            {
                $invoice = Mage::helper('cryozonic_stripe')->invoicePendingOrder($order, Mage_Sales_Model_Order_Invoice::NOT_CAPTURE, $charge->id);
                $this->sendNewOrderEmailFor($order);
            }
            else
            {
                // In theory we should never have failed charges because they would throw an exception
                $comment = "Authorization failed. Transaction ID: {$charge->id}. Charge status: {$charge->status}";
                $order->addStatusHistoryComment($comment);
                $order->save();
            }

        }
        catch (\Stripe\Error\Card $e)
        {
            $comment = "Order could not be charged because of a card error: " . $e->getMessage();
            $order->addStatusHistoryComment($comment);
            $order->save();
            throw $e;
        }
        catch (\Stripe\Error $e)
        {
            $comment = "Order could not be charged because of a Stripe error.";
            $order->addStatusHistoryComment($comment);
            $order->save();
            throw $e;
        }
        catch (\Exception $e)
        {
            $comment = "Order could not be charged because of server side error.";
            $order->addStatusHistoryComment($comment);
            $order->save();
            throw $e;
        }
    }

    public function sendNewOrderEmailFor($order)
    {
        // Send the order email
        if ($order->getCanSendNewEmailFlag())
        {
            try {
                $order->sendNewOrderEmail();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }
    }
}