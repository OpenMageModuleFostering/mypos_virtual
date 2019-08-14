<?php
class Mypos_Virtual_Helper_Data extends Mage_Core_Helper_Abstract
{
    public $privateKey;
    public $publicKey;
    public $isTest;
    public $formUrl;
    public $sid;
    public $walletNumber;
    public $ipcVersion;
    public $keyindex;

    public function __construct()
    {
        $this->isTest = (bool) Mage::getStoreConfig('payment/mypos_virtual/test');
        $this->ipcVersion   = '1.0';

        if ($this->isTest)
        {
            $this->sid          = Mage::getStoreConfig('payment/mypos_virtual/developer_sid');
            $this->walletNumber = Mage::getStoreConfig('payment/mypos_virtual/developer_wallet_number');
            $this->privateKey   = Mage::getStoreConfig('payment/mypos_virtual/developer_store_private_key');
            $this->publicKey    = Mage::getStoreConfig('payment/mypos_virtual/developer_ipc_public_certificate');
            $this->formUrl      = Mage::getStoreConfig('payment/mypos_virtual/developer_url');
            $this->privateKey   = Mage::getStoreConfig('payment/mypos_virtual/developer_store_private_key');
            $this->publicKey    = Mage::getStoreConfig('payment/mypos_virtual/developer_ipc_public_certificate');
            $this->keyindex     = Mage::getStoreConfig('payment/mypos_virtual/developer_keyindex');
        }
        else
        {
            $this->sid          = Mage::getStoreConfig('payment/mypos_virtual/production_sid');
            $this->walletNumber = Mage::getStoreConfig('payment/mypos_virtual/production_wallet_number');
            $this->privateKey   = Mage::getStoreConfig('payment/mypos_virtual/production_store_private_key');
            $this->publicKey    = Mage::getStoreConfig('payment/mypos_virtual/production_ipc_public_certificate');
            $this->formUrl      = Mage::getStoreConfig('payment/mypos_virtual/production_url');
            $this->privateKey   = Mage::getStoreConfig('payment/mypos_virtual/production_store_private_key');
            $this->publicKey    = Mage::getStoreConfig('payment/mypos_virtual/production_ipc_public_certificate');
            $this->keyindex     = Mage::getStoreConfig('payment/mypos_virtual/production_keyindex');
        }
    }

    public function getFormUrl()
    {
        return $this->formUrl;
    }

    public function getOrderOKUrl()
    {
        return Mage::getUrl('mypos_virtual/payment/success', array('_secure' => true));
    }

    public function getOrderCancelUrl()
    {
        return Mage::getUrl('mypos_virtual/payment/cancel', array('_secure' => true));
    }

    public function getOrderNotifyUrl()
    {
        return Mage::getUrl('mypos_virtual/payment/notify', array('_secure' => true));
    }

    public function getPost(Mage_Sales_Model_Order $_order)
    {
        $billing_address = $_order->getBillingAddress();
        $items = $_order->getItemsCollection(array(), true);

        $post = array();
        $post['IPCmethod'] = 'IPCPurchase';
        $post['IPCVersion'] = $this->ipcVersion;
        $post['IPCLanguage'] = substr(Mage::app()->getLocale()->getLocaleCode(), 0, 2);
        $post['WalletNumber'] = $this->walletNumber;
        $post['SID'] = $this->sid;
        $post['keyindex'] = $this->keyindex;
        $post['Source'] = 'sc_magento';

        $post['Amount'] = number_format($_order->getBaseGrandTotal(), 2, '.', '');
        $post['Currency'] = $_order->getBaseCurrency()->getCode();
        $post['OrderID'] = $_order->getRealOrderId();
        $post['URL_OK'] = $this->getOrderOKUrl();
        $post['URL_CANCEL'] = $this->getOrderCancelUrl();
        $post['URL_Notify'] = $this->getOrderNotifyUrl();
        $post['CustomerIP'] = $_SERVER['REMOTE_ADDR'];
        $post['CustomerEmail'] = $_order->getCustomerEmail();
        $post['CustomerFirstNames'] =  $_order->getCustomerFirstname();
        $post['CustomerFamilyName'] = $_order->getCustomerLastname();
        $post['CustomerCountry'] = $billing_address->getCountryModel()->getIso3Code() !== 'ROU' ? $billing_address->getCountryModel()->getIso3Code() : 'ROM';
        $post['CustomerCity'] = $billing_address->getCity();
        $post['CustomerZIPCode'] = $billing_address->getPostcode();
        $post['CustomerAddress'] = $billing_address->getStreetFull();
        $post['CustomerPhone'] = $billing_address->getTelephone();
        $post['Note'] = 'Mypos Virtual Checkout Magento Extension';
        $post['CartItems'] = $_order->getTotalItemCount();

        $index = 1;

        /**
         * @var Mage_Sales_Model_Order_Item $item
         */
        foreach($items as $item)
        {
            $post['Article_' . $index] = html_entity_decode(strip_tags($item->getName()));
            $post['Quantity_' . $index] = number_format($item->getQtyOrdered(), 2, '.', '');
            $post['Price_' . $index] = number_format($item->getPrice(), 2, '.', '');
            $post['Amount_' . $index] = number_format($item->getPrice() * $item->getQtyOrdered(), 2, '.', '');
            $post['Currency_' . $index] = $_order->getBaseCurrency()->getCode();

            $index++;
        }

        if ($_order->getShippingDescription() !== '') {
            $post['Article_' . $index] = $_order->getShippingDescription();
            $post['Quantity_' . $index] = 1;
            $post['Price_' . $index] = number_format($_order->getShippingAmount(), 2, '.', '');
            $post['Amount_' . $index] = number_format($_order->getShippingAmount() * 1, 2, '.', '');
            $post['Currency_' . $index] = $_order->getBaseCurrency()->getCode();

            $index++;
            $post['CartItems']++;
        }

        $taxes = $_order->getFullTaxInfo();

        if (count($taxes) !== 0) {
            foreach ($taxes as $tax) {
                $post['Article_' . $index] = "Tax" . ' (' . number_format($tax['percent'], 2, '.', '') . '%)';
                $post['Quantity_' . $index] = 1;
                $post['Price_' . $index] = number_format($tax['amount'], 2, '.', '');
                $post['Amount_' . $index] = number_format($tax['amount'], 2, '.', '');
                $post['Currency_' . $index] = $_order->getBaseCurrency()->getCode();

                $index++;
                $post['CartItems']++;
            }
        }

        $post['Signature'] = $this->createSignature($post);

        return $post;
    }

    private function createSignature($post)
    {
        $concData = base64_encode(implode('-', $post));
        $privKeyObj = openssl_get_privatekey($this->privateKey);
        openssl_sign($concData, $signature, $privKeyObj, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    public function isValidSignature($post)
    {
        // Save signature
        $signature = $post['Signature'];

        // Remove signature from POST data array
        unset($post['Signature']);

        // Concatenate all values
        $concData = base64_encode(implode('-', $post));

        // Extract public key from certificate
        $pubKeyId = openssl_get_publickey($this->publicKey);

        // Verify signature
        $result = openssl_verify($concData, base64_decode($signature), $pubKeyId, OPENSSL_ALGO_SHA256);

        //Free key resource
        openssl_free_key($pubKeyId);

        if ($result == 1)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function getRefundData(Mage_Sales_Model_Order $order, $transactionId, $amount)
    {
        $post = array();
        $post['IPCmethod'] = 'IPCRefund';
        $post['IPCVersion'] = $this->ipcVersion;
        $post['IPCLanguage'] = 'en';
        $post['WalletNumber'] = $this->walletNumber;
        $post['SID'] = $this->sid;
        $post['keyindex'] = $this->keyindex;
        $post['Source'] = 'sc_magento';

        $post['IPC_Trnref'] = $transactionId;
        $post['OrderID'] = $order->getRealOrderId();
        $post['Amount'] = number_format($amount, 2, '.', '');
        $post['Currency'] = $order->getBaseCurrency()->getCode();
        $post['OutputFormat'] = 'xml';

        $post['Signature'] = $this->createSignature($post);

        return $post;
    }

    public function xml2Post($xml)
    {
        $xml = simplexml_load_string($xml);

        $post = array();

        /**
         * @var \SimpleXMLElement $child
         */
        foreach ($xml->children() as $child)
        {
            $post[$child->getName()] = (string) $child;
        }

        return $post;
    }
}