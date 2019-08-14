<?php
class Mypos_Virtual_Model_Standard extends Mage_Payment_Model_Method_Abstract {
    private static $logFilename = 'mypos_virtual.log';
    protected $_code = 'mypos_virtual';
	
	protected $_isInitializeNeeded      = true;
	protected $_canUseInternal          = false;
    protected $_canUseForMultishipping  = false;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = true;

    private $transactionId;

	public function getOrderPlaceRedirectUrl() {
		return Mage::getUrl('mypos_virtual/payment/redirect', array('_secure' => true));
	}

    public function processBeforeRefund($invoice, $payment){
        $this->transactionId = $invoice->getTransactionId();
        return $this;
    }

    /**
     * Check whether payment method can be used
     *
     * @param Mage_Sales_Model_Quote|null $quote
     *
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        $checkResult = parent::isAvailable($quote);

        if ($checkResult)
        {
            $currency_code = Mage::app()->getStore()->getCurrentCurrencyCode();

            if (!in_array($currency_code, array('EUR', 'USD', 'GBP', 'HRK', 'CHF', 'RON', 'JPY', 'BGN')))
            {
                $checkResult = false;
            }
        }

        return $checkResult;
    }

    public function refund(Varien_Object $payment, $amount){

        /**
         * @var Mypos_Virtual_Helper_Data $helper
         */
        $helper = Mage::helper('mypos_virtual');

        /**
         * @var Mage_Sales_Model_Order $order
         */
        $order = $payment->getOrder();

        Mage::log('Create IPCRefund request for order: ' . $order->getRealOrderId() . '.', null, self::$logFilename);

        $post = $helper->getRefundData($order, $this->transactionId, $amount);

        //open connection
        $ch = curl_init($helper->getFormUrl());

        //set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $helper->getFormUrl());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION ,1);
        curl_setopt($ch, CURLOPT_HEADER ,0); // DO NOT RETURN HTTP HEADERS
        curl_setopt($ch, CURLOPT_RETURNTRANSFER ,1); // RETURN THE CONTENTS OF THE CALL
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120); // Timeout on connect (2 minutes)

        //execute post
        $result = curl_exec($ch);
        curl_close($ch);

        // Parse xml
        $post = $helper->xml2Post($result);

        if ($helper->isValidSignature($post))
        {
            Mage::log('Valid signature for IPCRefund request for order: ' . $order->getRealOrderId() . '.', null, self::$logFilename);

            if ($post['Status'] != 0)
            {
                Mage::log('There was an error when processing IPCRefund response for order: ' . $order->getRealOrderId() . ' with status ' . $post['Status'] . '.', null, self::$logFilename);
                Mage::throwException("There was an error processing the refund.");
            }
            else
            {
                $this->_addTransaction(
                    $payment,
                    $post['IPC_Trnref'] . '-' . time(),
                    Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND,
                    array(),
                    array('Amount' => $post['Amount']),
                    'Transaction from refund.'
                );

                Mage::log('Successfully processed IPCRefund response for order: ' . $order->getRealOrderId() . '.', null, self::$logFilename);
            }
        }
        else
        {
            Mage::log('Invalid signature from IPCRefund response for order: ' . $order->getRealOrderId() . '.', null, self::$logFilename);
            Mage::throwException("Invalid signature from response.");
        }

        return $this;
    }

    /**
     * Add payment transaction
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param string $transactionId
     * @param string $transactionType
     * @param array $transactionDetails
     * @param array $transactionAdditionalInfo
     * @return null|Mage_Sales_Model_Order_Payment_Transaction
     */
    public function _addTransaction(Mage_Sales_Model_Order_Payment $payment, $transactionId, $transactionType,
                                       array $transactionDetails = array(), array $transactionAdditionalInfo = array(), $message = false
    ) {
        $payment->setTransactionId($transactionId);
        $payment->resetTransactionAdditionalInfo();

        foreach ($transactionDetails as $key => $value) {
            $payment->setData($key, $value);
        }
        foreach ($transactionAdditionalInfo as $key => $value) {
            $payment->setTransactionAdditionalInfo($key, $value);
        }

        $transaction = $payment->addTransaction($transactionType, null, false , $message);

        foreach ($transactionDetails as $key => $value) {
            $payment->unsetData($key);
        }

        $payment->unsLastTransId();

        /**
         * It for self using
         */
        $transaction->setMessage($message);

        return $transaction;
    }
}
?>