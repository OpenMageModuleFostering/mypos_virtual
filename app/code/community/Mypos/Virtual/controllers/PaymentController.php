<?php
/*
 * Mypos Virtual Checkout Payment Controller
 *
 * @author Intercard Finance AD
*/

class Mypos_Virtual_PaymentController extends Mage_Core_Controller_Front_Action {
    public static $logFilename = 'mypos_virtual.log';

    public function __construct(Zend_Controller_Request_Abstract $request, Zend_Controller_Response_Abstract $response, array $invokeArgs = array())
    {
        parent::__construct($request, $response, $invokeArgs);
    }

	public function redirectAction() {
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'mypos_virtual', array('template' => 'mypos_virtual/redirect.phtml'));

        /**
         * Retrieve order id
         */
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();

        /**
         * @var Mypos_Virtual_Helper_Data $helper
         */
        $helper = Mage::helper('mypos_virtual');

        Mage::log('Redirect to checkout. OrderID: ' . $orderId . ' Url: ' . $helper->getFormUrl(), null, self::$logFilename);

        echo $block->toHtml();
	}

	public function notifyAction() {
        Mage::log('Received notify url request.', null, self::$logFilename);

		if($this->getRequest()->isPost()) {
            /**
             * @var Mypos_Virtual_Helper_Data $helper
             */
            $helper = Mage::helper('mypos_virtual');

            $post = $this->getRequest()->getPost();

            // Check if we have a valid signature.
			if ($helper->isValidSignature($post))
            {
                if ($post['IPCmethod'] == 'IPCPurchaseNotify')
                {
                    Mage::log('Received IPCPurchaseNotify request for order: ' . $post['OrderId'] . '.', null, self::$logFilename);

                    // Payment was successful, so update the order's state,
                    // send order email and move to the success page

                    /**
                     * @var Mage_Sales_Model_Order $order
                     */
                    $order = Mage::getModel('sales/order');
                    $order->loadByIncrementId($post['OrderID']);

                    try {
                        if(!$order->canInvoice())
                        {
                            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
                        }

                        /**
                         * @var Mage_Sales_Model_Service_Order $serviceOrder
                         */
                        $serviceOrder = Mage::getModel('sales/service_order', $order);

                        $invoice = $serviceOrder->prepareInvoice();

                        if (!$invoice->getTotalQty()) {
                            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
                        }

                        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::NOT_CAPTURE);
                        $invoice->setTransactionId($post['IPC_Trnref']);
                        $invoice->register();

                        /**
                         * @var Mage_Core_Model_Resource_Transaction $transactionSave
                         */
                        $transactionSave = Mage::getModel('core/resource_transaction');

                        $transactionSave
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder());

                        /**
                         * @var Mypos_Virtual_Model_Standard $model
                         */
                        $model = Mage::getModel('mypos_virtual/standard', $order);

                        $model->_addTransaction(
                            $order->getPayment(),
                            $post['IPC_Trnref'],
                            Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE,
                            array(),
                            array('Amount' => $post['Amount']),
                            'Transaction from notify url.'
                        );

                        $transactionSave->save();

                        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Gateway has authorized the payment.');
                        $order->sendNewOrderEmail();
                        $order->setEmailSent(true);
                        $order->save();

                        echo 'OK';

                        Mage::log('Created invoice and set order: ' . $post['OrderID'] . ' to processing.', null, self::$logFilename);

                        exit;
                    }
                    catch (Mage_Core_Exception $e) {
                        echo $e->getMessage();
                        Mage::log('Error in IPCPurchaseNotify order: ' . $post['OrderID'] . '.', null, self::$logFilename);
                        exit;
                    }
                }
                else if ($post['IPCmethod'] == 'IPCPurchaseRollback')
                {
                    Mage::log('Received IPCPurchaseRollback request for order: ' . $post['OrderID'] . '.', null, self::$logFilename);
                    /**
                     * @var Mage_Sales_Model_Order $order
                     */
                    $order = Mage::getModel('sales/order');
                    $order->loadByIncrementId($post['OrderID']);

                    if($order->getId())
                    {
                        /**
                         * @var Mage_Sales_Model_Service_Order $service
                         */
                        $service = Mage::getModel('sales/service_order', $order);

                        $service->prepareCreditmemo()->register()->save();

                        // Flag the order as 'cancelled' and save it
                        $order
                            ->cancel()
                            ->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Gateway has declined the payment.')
                            ->save()
                        ;

                        echo 'OK';

                        Mage::log('Set order: ' . $post['OrderId'] . ' as canceled.', null, self::$logFilename);

                        exit;
                    }

                    Mage::log('Order: ' . $post['OrderId'] . ' doesn\'t exist.', null, self::$logFilename);
                    echo 'Order ' . $post['OrderID'] . ' doesn\'t exist.';
                    exit;
                }
            }
            else
            {
                Mage::log('Invalid signature in notify url request.', null, self::$logFilename);
            }
		}
        else
        {
            Mage::log('No post data in notify url request.', null, self::$logFilename);
        }

        Mage_Core_Controller_Varien_Action::_redirect('');
	}

    public function successAction()
    {
        if ($this->getRequest()->isPost()) {
            /**
             * @var Mypos_Virtual_Helper_Data $helper
             */
            $helper = Mage::helper('mypos_virtual');

            $post = $this->getRequest()->getPost();

            // Check if we have a valid signature.
            if ($helper->isValidSignature($post))
            {
                if ($post['IPCmethod'] == 'IPCPurchaseOK')
                {
                    $this->redirectSuccess();
                }
            }
        }
        else
        {
            Mage_Core_Controller_Varien_Action::_redirect('');
        }
    }

	public function cancelAction() {
        Mage::log('Received cancel url request.', null, self::$logFilename);
        if ($this->getRequest()->isPost()) {

            /**
             * @var Mypos_Virtual_Helper_Data $helper
             */
            $helper = Mage::helper('mypos_virtual');

            $post = $this->getRequest()->getPost();

            // Check if we have a valid signature.
            if ($helper->isValidSignature($post))
            {
                if ($post['IPCmethod'] == 'IPCPurchaseCancel')
                {
                    Mage::log('Received IPCPurchaseCancel request for order: ' . $post['OrderId'] . '.', null, self::$logFilename);

                    /**
                     * @var Mage_Sales_Model_Order $order
                     */
                    $order = Mage::getModel('sales/order');
                    $order->loadByIncrementId($post['OrderID']);

                    if($order->getId())
                    {
                        // Flag the order as 'cancelled' and save it
                        $order
                            ->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'User has canceled the payment.')
                            ->save()
                        ;

                        Mage::log('Order: ' . $post['OrderId'] . ' canceled by user.', null, self::$logFilename);
                    }
                    else
                    {
                        Mage::log('No order with id: ' . $post['OrderId'] . ' exist.', null, self::$logFilename);
                    }

                    $this->redirectFailure();
                }
            }
            else
            {
                Mage::log('Invalid signature in cancel url request.', null, self::$logFilename);
            }
        }
        else
        {
            Mage::log('No post data in cancel url request.', null, self::$logFilename);
            Mage_Core_Controller_Varien_Action::_redirect('');
        }

	}

    private function redirectFailure()
    {
        Mage::getSingleton('core/session')->addError('Your order has been canceled.');
        Mage_Core_Controller_Varien_Action::_redirect('customer/account/', array('_secure'=>true));
    }

    private function redirectSuccess()
    {
        Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure'=>true));
    }
}