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

/**
 * Flo2Cash Controller - Contains the three main actions for interaction
 * with the Flo2Cash payment gateway
 */
class F2C_Flo2cash_Flo2cashController extends Mage_Core_Controller_Front_Action {

    /**
     * Controls the redirection of the customer to the Flo2Cash payment gateway.
     */
    public function redirectAction() {
        $flo2cash = Mage::getModel('flo2cash/flo2cash');
        $order_id ='';
        $html = '<html><body>';
        $html.= $this->__('You will be redirected to Flo2Cash Secure Payment Gateway in a few seconds.');
        $html.= '<form action="' . $flo2cash->getProcessURL() . '" id="flo2cash" method="post">';
        foreach ($flo2cash->getCheckoutFormFields() as $field=>$value) {
            $html.= '<input type="hidden" name="' . $field . '" value="' . $value . '"/>';
            if($field=='particular') $order_id = $value;
        }
        $html.= '</form>';
        $html.= '<script type="text/javascript">document.getElementById("flo2cash").submit();</script>';		
        $html.= '</body></html>';
        
        //set statu for a new order
        $order_status = Mage::getStoreConfig('payment/flo2cash/new_order_status');
        $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
        $order->setStatus($order_status, true)->save();
        
        // redirect to redirect page
        $this->getResponse()->setBody($html);
    }
    
    /**
     * When a customer has finished making their payment they will be redirected back to here.
     * This action will check the result of the payment and show the customer the appropriate page
     * If the customer closes their browser this action may not be hit or the merchant has set
     * Web payments to display the payment result this action may not be hit so we do not perform 
     * system/order update here but rather use Flo2Cash MNS
    */
    public function returnAction() {
        
        if($_POST) {
            $txn_status = $_POST['txn_status'];
            switch ($txn_status) {
                case 0:
                    //Unknown transaction
                    $this->_redirect('checkout/onepage/failure');
                    break;
                case 2:
                    //Successfull
                    $this->_redirect('checkout/onepage/success');
                    break;
                case 3:
                    //Failed – Error
                    $this->_redirect('checkout/onepage/failure');
                    break;
                case 4:
                    //Blocked – Transaction rules prevent this transaction taking place
                    $this->_redirect('checkout/onepage/failure');
                    break;
                case 11:
                    //Declined – Transaction was declined at the bank
                    $this->_redirect('checkout/onepage/failure');
                    break;
            }            
        } else {
            // No post data received - redirect to main page as most likely merchant has display at Flo2Cash set
            $this->_redirectUrl(Mage::getBaseUrl());
        }
    }
    
    
    /**
     * This is the MNS endpoint - notification_url
     * Here we process the MNS handshake to verify transaction was from Flo2Cash
     * If the transaction is VERIFIED then we check the status; successful payments move the order to 'Processing', declined payments leave the order in 'Pending Payment'
     * If the MNS response is REJECTED then we move the order to 'Suspected fraud' for further investigation
     */
    public function responseAction() {
        try {
            $post_data = '';
            $txn_status = '';
            $order_id = '';
            $order_status = '';
            // gte MNS url
            $flo2cash = Mage::getModel('flo2cash/flo2cash');
            $server_url = $flo2cash->getNotificationURL();
            
            foreach ($_POST as $key => $value) {
                $post_data=$key.'='.urlencode($value).'&'.$post_data;
                if ($key=='transaction_status') {
                    $txn_status = $value; 
                } else if ($key=='particular') {
                    $order_id=$value;
                }
            }
            
            $post_data = $post_data . 'cmd=_xverify-transaction';
            $response = $flo2cash->callAPI($server_url, $post_data);
            $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
            
            if($response=='VERIFIED') {
                if ($txn_status == 2) {
                    $order_status = Mage::getStoreConfig('payment/flo2cash/payment_accepted_order_status');
                    $order->setStatus($order_status, true)->save();
                } else {
                    $order_status = Mage::getStoreConfig('payment/flo2cash/payment_refused_order_status');
                    $order->setStatus($order_status, true)->save();
                }
                
            } else {
                $order_status = Mage::getStoreConfig('payment/flo2cash/payment_refused_order_status');
                $order->setStatus($order_status, true)->save();
            }

        } catch (Mage_Core_Exception $e) {
            $this->getResponse()->setBody(
                    $this->getLayout()
                            ->createBlock($this->_failureBlockType)
                            ->setOrder($this->_order)
                            ->toHtml()
            );
        }
    }
}
