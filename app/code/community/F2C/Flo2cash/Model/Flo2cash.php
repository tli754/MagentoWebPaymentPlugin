<?php
/**
 * Flo2Cash Payment Gateway
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 */
define("live_process_url", "https://secure.flo2cash.co.nz/web2pay/default.aspx", false);
define("demo_process_url", "https://demo.flo2cash.co.nz/web2pay/default.aspx", false);
define("live_notification_url", "https://secure.flo2cash.co.nz/web2pay/MNSHandler.aspx", false);
define("demo_notification_url", "https://demo.flo2cash.co.nz/web2pay/MNSHandler.aspx", false);

class F2C_Flo2cash_Model_Flo2cash extends Mage_Payment_Model_Method_Abstract {

    protected $_code = 'flo2cash'; 

    public function getAccountID() {
        return Mage::getStoreConfig('payment/flo2cash/account_id');
    }

    public function getProcessURL() {
        if (Mage::getStoreConfig('payment/flo2cash/demo_mode') == 1) {
            return demo_process_url;
        } else {
            return live_process_url;
        }
    }

    public function getNotificationURL() {
        if (Mage::getStoreConfig('payment/flo2cash/demo_mode') == 1) {
            return demo_notification_url;
        } else {
            return live_notification_url;
        }
    }
    
    public function getHeaderImageURL() {
        return Mage::getStoreConfig('payment/flo2cash/header_image');
    }

    public function getBottomHeaderBorderColor() {
        return Mage::getStoreConfig('payment/flo2cash/bottom_header_border_color');
    }

    public function getHeaderBGColor() {
        return Mage::getStoreConfig('payment/flo2cash/header_bg_color');
    }

    public function getStoreCard() {
        return Mage::getStoreConfig('payment/flo2cash/store_card');
    }
    
    public function getCSCRequired() {
        return Mage::getStoreConfig('payment/flo2cash/csc_required');
    }

    public function getDisplayEmail() {
        return Mage::getStoreConfig('payment/flo2cash/display_email');
    }

    public function getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    public function getQuote() {
        return $this->getCheckout()->getQuote();
    }

    public function getCheckoutFormFields() {
        $lastOrderId = Mage::getSingleton('checkout/session')->getLastOrderId();
        $order = Mage::getSingleton('sales/order');
        $order->load($lastOrderId);
        $_totalData = $order->getData();
        $tax_amount = floatval($_totalData['tax_amount']);
        $shipping_amount = floatval($_totalData['base_shipping_amount']);
        $items = $order->getAllItems();
        $fields = array();
        // Get order and shipping details
        $i = 1;
        if ($shipping_amount > 0) {
            $fields['item_name' . $i] = $_totalData['shipping_description'].' (shipping)';
            $fields['item_price' . $i] = $shipping_amount;
            $fields['item_code' . $i] = $_totalData['shipping_address_id'];
            $fields['item_qty' . $i] = '1';
            $i++;
        }
        
        if ($tax_amount > 0) {
            $fields['item_name' . $i] = 'Tax amount';
            $fields['item_price' . $i] = $tax_amount;
            $fields['item_code' . $i] = $i;
            $fields['item_qty' . $i] = '1';
            $i++; 
        }

        foreach ($items as $item) {
            $product_quantity = $item->getQtyToInvoice();
            
            // Check quantity of product is not equal to 0
            if ($product_quantity != 0) {
                // Get Order Details
                $fields['item_name' . $i] = $item->getName();
                $fields['item_price' . $i] = $item->getPrice();
                $fields['item_code' . $i] = $item->getProductId();
                $fields['item_qty' . $i] = $item->getQtyToInvoice();
                $i++;
            }
        }

        // Basic settings for _xcart.
        $fields['cmd'] = '_xcart';
        $fields['account_id'] = $this->getAccountID();
        $fields['notification_url'] = Mage::getUrl('flo2cash/flo2cash/response');
        $fields['return_url'] = Mage::getUrl('flo2cash/flo2cash/return');
        $fields['header_image'] = $this->getHeaderImageURL();
        $fields['header_border_bottom'] = $this->getBottomHeaderBorderColor();
        $fields['store_card'] = $this->getStoreCard();
        $fields['csc_required'] = $this->getCSCRequired();
        $fields['display_customer_email'] = $this->getDisplayEmail();
        $fields['reference'] = $this->getCheckout()->getLastRealOrderId();
        $fields['particular'] = $this->getCheckout()->getLastRealOrderId();
        $fields['magento_version'] = $this->getMagentoVersion();
        $fields['store_name'] = Mage::app()->getStore()->getName();

        return $fields;
    }
    
    public function getMagentoVersion(){
        $m=new Mage;
        $version = $m->getVersion();
        return $version;
    }
    
    public function createFormBlock($name) {
        $block = $this->getLayout()->createBlock('flo2cash/flo2cash_form', $name)
                ->setMethod('flo2cash')
                ->setPayment($this->getPayment())
                ->setTemplate('f2c/flo2cash/form.phtml');

        return $block;
    }

    // start from here, Return url for redirection after order placed
    public function getOrderPlaceRedirectUrl() {
        return Mage::getUrl('flo2cash/flo2cash/redirect');
    }

    //call flo2cash API and return "VARIVIED" or "REJECTED" back
    public function callAPI($url, $post_data) {
        $response = '';
        try{
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 100);
            curl_setopt($ch, CURLOPT_VERBOSE, 1);
            curl_setopt($ch, CURLOPT_NOPROGRESS, 0);
            $response = curl_exec($ch);            
            curl_close($ch);
        } catch (Exception $e) {
            throw new Exception("Invalid URL", 0, $e);
        }
        
        return $response;
    }
}