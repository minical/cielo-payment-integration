<?php

use LifenPag\Asaas\V3\Client;
use LifenPag\Asaas\V3\Domains\Customer as CustomerDomain;
use LifenPag\Asaas\V3\Entities\Customer as CustomerEntity;
use LifenPag\Asaas\V3\Collections\Customer as CustomerCollection;

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Manages gateway operations
 * @property  Currency_model Currency_model
 */
class ProcessPayment
{

    const DEFAULT_CURRENCY = 'usd';

    /**
     * @var CI_Controller
     */
    private $ci;

    private $selected_gateway;

    /**
     * @var array Company gateway settings
     */
    private $company_gateway_settings;

    /**
     * @var array Customer
     */
    private $customer;

    /**
     * @var string Error message
     */
    private $error_message;

    /**
     * @var string
     */
    private $currency = self::DEFAULT_CURRENCY;

    /**
     *
     * @var string External Id, can only be one per gateway
     */
     
    private $customer_external_entity_id;

    function __construct($params = null)
    {   
        $this->ci =& get_instance();
        $this->ci->load->model('Payment_gateway_model');
        $this->ci->load->model('Customer_model');
        $this->ci->load->library('session');
        $this->ci->load->model("Card_model");

        $this->ci->load->library('encrypt');
        
        $this->ci->load->model('Booking_model');
        $this->ci->load->model('company_model');          
        $this->ci->load->model('Channex_model');          
        
        $company_id = $this->ci->session->userdata('current_company_id');

        if (isset($params['company_id'])) {
            $company_id = $params['company_id'];
        }
        
        $this->cielo_url = ($this->ci->config->item('app_environment') == "development") ? "https://apisandbox.cieloecommerce.cielo.com.br" : "https://api.cieloecommerce.cielo.com.br";
        
        $this->cielo_pay_link_url = ($this->ci->config->item('app_environment') == "development") ? "https://cieloecommerce.cielo.com.br" : "https://cieloecommerce.cielo.com.br";

        $gateway_settings = $this->ci->Payment_gateway_model->get_payment_gateway_settings(
            $company_id
        );
                    
        if($gateway_settings)
        {
            $this->setCompanyGatewaySettings($gateway_settings);
            $this->setSelectedGateway($this->company_gateway_settings['selected_payment_gateway']);
            $this->populateGatewaySettings();
            $this->setCurrency();       
        }       
    }

    private function populateGatewaySettings()
    {
        switch ($this->selected_gateway) {
            case 'cielo':
                $gateway_meta_data = json_decode($this->company_gateway_settings['gateway_meta_data'], true);
                $this->cielo_merchant_id = $gateway_meta_data['cielo_merchant_id'];
                $this->cielo_merchant_key = $gateway_meta_data['cielo_merchant_key'];
                $this->cielo_client_id = $gateway_meta_data['cielo_client_id'];
                $this->cielo_client_secret = $gateway_meta_data['cielo_client_secret'];
                // if($this->asaas_api_key)
                //     Client::connect($this->asaas_api_key, $this->asaas_env);
                break;
        }
    }

    private function setCurrency()
    {
        // itodo some gateway currency maybe unavailable
        $this->ci->load->model('Currency_model');
        $currency       = $this->ci->Currency_model->get_default_currency($this->company_gateway_settings['company_id']);
        $this->currency = strtolower($currency['currency_code']);
    }

    /**
     * @return string
     */
    public function getSelectedGateway()
    {
        return $this->selected_gateway;
    }

    /**
     * @param string $selected_gateway
     */
    public function setSelectedGateway($selected_gateway)
    {
        $this->selected_gateway = $selected_gateway;
    }

    /**
     * @return mixed
     */
    public function getCustomer()
    {
        return $this->customer;
    }

    public function getCustomerExternalEntityId()
    {
        $id = null;

        if ($this->customer) {
            switch ($this->selected_gateway) {
                case 'stripe':
                    $id = $this->customer['stripe_customer_id'];
                    break;
            }
        }


        return $id;
    }

   
    /**
     * @param string $customer_external_entity_id
     */
    public function setCustomerExternalEntityId($customer_external_entity_id)
    {
        $this->customer_external_entity_id = $customer_external_entity_id;
    }

    /**
     * @param $booking_id
     * @param $amount
     * @return bool
     */
    public function createBookingCharge($booking_id, $amount, $customer_id = null)
    {
        $charge_id = null;

        if ($this->isGatewayPaymentAvailableForBooking($booking_id, $customer_id)) {
            try {
                $this->ci->load->model('Booking_model');
                $this->ci->load->model('Customer_model');
                $this->ci->load->model('Card_model');
                $this->ci->load->library('tokenex');
                
                $booking     = $this->ci->Booking_model->get_booking($booking_id);
                
                $customer_id = $customer_id ? $customer_id : $booking['booking_customer_id'];
                
                $customer_info    = $this->ci->Card_model->get_customer_cards($customer_id);
                //print_r($customer);
                $customer = "";
                if(isset($customer_info) && $customer_info){
                    
                    foreach($customer_info as $customer_data){
                        if(($customer_data['is_primary']) && !$customer_data['is_card_deleted']){
                            $customer = $customer_data;
                        }
                    } 
                }

                $customer_data = $this->ci->Customer_model->get_customer($customer_id);
                
                $customer    = json_decode(json_encode($customer), 1);
                $customer['customer_data'] = $customer_data;

                $customer_meta_data = json_decode($customer['customer_meta_data'], true);
               
                if(isset($customer_meta_data['token']) && $customer_meta_data['token'])
                {
                    $cielo_merchant_id = $this->cielo_merchant_id;
                    $cielo_merchant_key = $this->cielo_merchant_key;
                    // use tokenex for payments
                    $charge = $this->make_payment($cielo_merchant_id, $cielo_merchant_key, $booking_id, $amount, $this->currency, $customer_meta_data, $customer);

                    $charge_id = null;
                    if($charge['success'])
                    {
                        if(isset($charge['charge_id']) && $charge['charge_id'])
                            $charge_id = $charge['charge_id'];
                        else
                        {
                           return $charge['authorization'];
                        }
                    }
                    else
                    {
                        $charge_id = isset($charge['errors']) && $charge['errors'] ? $charge['errors'] : null;
                        $this->setErrorMessage($charge['errors']);
                    }
                }
                
                              
            } catch (Exception $e) {
                $error = $e->getMessage();
                $this->setErrorMessage($error);
            }
        }

        return $charge_id;
    }

    public function make_payment($cielo_merchant_id, $cielo_merchant_key, $booking_id, $amount, $currency, $customer_meta_data, $customer)
    {   
        if (function_exists('send_payment_request')) {
            $api_url = $this->cielo_url;
            $method = '/1/sales/';
            $method_type = 'POST';

            if (
                isset($customer_meta_data['cielo_card_token']) && 
                $customer_meta_data['cielo_card_token']
            ) {
                $data = array(
                    'MerchantOrderId' => $booking_id,
                    'Payment' => array(
                        'Installments' => 1,
                        'Amount' => $amount,
                        'Type' => "CreditCard",
                        'Capture' => true,
                        'CreditCard' => array(
                            'CardToken' => $customer_meta_data['cielo_card_token'],
                            'Brand' => ucfirst($customer_meta_data['card_type'])
                        )
                    )
                );
            }
            else 
            {
                $data = array(
                    'MerchantOrderId' => $booking_id,
                    'Payment' => array(
                        'Installments' => 1,
                        'Amount' => $amount,
                        'Type' => "CreditCard",
                        'Capture' => true,
                        'CreditCard' => array(
                            'CardNumber' => "%CARD_NUMBER%",
                            'ExpirationDate' => "%EXPIRATION_MM%" .'/'. "%EXPIRATION_YYYY%",
                            'Brand' => ucfirst($customer_meta_data['card_type'])
                        )
                    )
                );
            }

            $headers = array(
                "MerchantId: " . $this->cielo_merchant_id,
                "MerchantKey: " . $this->cielo_merchant_key,
                "Content-Type: application/json"
            );

            $response = $this->call_api($api_url, $method, $data, $headers);

            $response = json_decode($response, true);

            if (
                isset($response['Payment']['ReturnCode']) && 
                (
                    $response['Payment']['ReturnCode'] == '6' ||
                    $response['Payment']['ReturnCode'] == '4'
                ) && 
                isset($response['Payment']['Status']) && 
                $response['Payment']['Status'] == 2) {

                return array('success' => true, 'charge_id' => $response['Payment']['PaymentId']);
            } else if (
                isset($response['Payment']['ReturnCode']) && 
                $response['Payment']['ReturnCode'] == '05' && 
                isset($response['Payment']['Status']) && 
                $response['Payment']['Status'] == 2) {
                
                return array('success' => false, 'errors' => $response['Payment']['ReturnMessage']);
            }

            // prx($response);
        }
    }

    function get_credit_card_type($cardNumber)
    {
        // Remove non-digits from the number
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        // Validate the length
        $len = strlen($cardNumber);
        if ($len < 15 || $len > 16) {
            throw new Exception("Invalid credit card number. Length does not match");
        } else {
            switch ($cardNumber) {
                case (preg_match('/^4/', $cardNumber) >= 1):
                    return 'Visa';
                case (preg_match('/^5[1-5]/', $cardNumber) >= 1):
                    return 'Master';
                case (preg_match('/^3[47]/', $cardNumber) >= 1):
                    return 'Amex';
                case (preg_match('/^3(?:0[0-5]|[68])/', $cardNumber) >= 1):
                    return 'Diners';
                case (preg_match('/^6(?:011|5)/', $cardNumber) >= 1):
                    return 'Discover';
                case (preg_match('/^(?:2131|1800|35\d{3})/', $cardNumber) >= 1):
                    return 'JCB';
                default:
                    throw new Exception("Could not determine the credit card type.");
                    break;
            }
        }
    }

    /**
     * Can Booking perform payment operations
     *
     * @param $booking_id
     * @return bool
     */
    public function isGatewayPaymentAvailableForBooking($booking_id, $customer_id = null)
    {
        $result = false;

        $this->ci->load->model('Booking_model');
        $this->ci->load->model('Customer_model');

        $booking       = $this->ci->Booking_model->get_booking($booking_id);
        
        $customer_id = $customer_id ? $customer_id : $booking['booking_customer_id'];
        
        $customer      = $this->ci->Customer_model->get_customer($customer_id);
        
        unset($customer['cc_number']);
        unset($customer['cc_expiry_month']);
        unset($customer['cc_expiry_year']);
        unset($customer['cc_tokenex_token']);
        unset($customer['cc_cvc_encrypted']);
        
        $card_data = $this->ci->Card_model->get_active_card($customer_id, $this->ci->company_id);
            
        if(isset($card_data) && $card_data){
            $customer['cc_number'] = $card_data['cc_number'];
            $customer['cc_expiry_month'] = $card_data['cc_expiry_month'];
            $customer['cc_expiry_year'] = $card_data['cc_expiry_year'];
            $customer['cc_tokenex_token'] = $card_data['cc_tokenex_token'];
            $customer['cc_cvc_encrypted'] = $card_data['cc_cvc_encrypted'];
            $customer['customer_meta_data'] = $card_data['customer_meta_data'];
        }
            
        $customer      = json_decode(json_encode($customer), 1);
        $customer_meta_data = json_decode($customer['customer_meta_data'], true);
        $hasTokenexToken = (isset($customer_meta_data['cielo_card_token']) and $customer_meta_data['cielo_card_token']);

        if(!$hasTokenexToken) {

            $customer['token'] = $customer_meta_data['token'];
            
            $card_toknization_response = $this->create_card_token($customer);

            $meta['cielo_card_token'] = isset($card_toknization_response['success']) && $card_toknization_response['success'] && isset($card_toknization_response['card_token']) && $card_toknization_response['card_token'] ? $card_toknization_response['card_token'] : null;

            $meta['token'] = isset($customer_meta_data['token']) && $customer_meta_data['token'] ? $customer_meta_data['token'] : '';
            $meta['card_type'] = isset($customer_meta_data['card_type']) && $customer_meta_data['card_type'] ? $customer_meta_data['card_type'] : '';

            $card_details['customer_meta_data'] = json_encode($meta);

            if($card_details && count($card_details) > 0) {
                $this->ci->Card_model->update_customer_primary_card($customer_id, $card_details);
            }

            $customer      = $this->ci->Customer_model->get_customer($customer_id);
        
            unset($customer['cc_number']);
            unset($customer['cc_expiry_month']);
            unset($customer['cc_expiry_year']);
            unset($customer['cc_tokenex_token']);
            unset($customer['cc_cvc_encrypted']);
            
            $card_data = $this->ci->Card_model->get_active_card($customer_id, $this->ci->company_id);
                
            if(isset($card_data) && $card_data) {
                $customer['cc_number'] = $card_data['cc_number'];
                $customer['cc_expiry_month'] = $card_data['cc_expiry_month'];
                $customer['cc_expiry_year'] = $card_data['cc_expiry_year'];
                $customer['cc_tokenex_token'] = $card_data['cc_tokenex_token'];
                $customer['cc_cvc_encrypted'] = $card_data['cc_cvc_encrypted'];
                $customer['customer_meta_data'] = $card_data['customer_meta_data'];
            }
                
            $customer      = json_decode(json_encode($customer), 1);
            $customer_meta_data = json_decode($customer['customer_meta_data'], true);
            $hasTokenexToken = (isset($customer_meta_data['cielo_card_token']) and $customer_meta_data['cielo_card_token']);
        }

        if (
            $this->areGatewayCredentialsFilled()
            and $customer
            and $hasTokenexToken
        ) {
            $result = true;
        }

        return $result;
    }

    function create_card_token($customer_data){

        if (function_exists('send_payment_request')) {
            $api_url = $this->cielo_url;
            $method = '/1/card/';
            $method_type = 'POST';

            $customer_meta_data = json_decode($customer_data['customer_meta_data'], true);

            $data = array(
                'CustomerName' => $customer_data['customer_name'],
                'CardNumber' => "%CARD_NUMBER%",
                'Holder' => "%CARDHOLDER_NAME%",
                'ExpirationDate' => "%EXPIRATION_MM%" .'/'. "%EXPIRATION_YYYY%",
                'Brand' => ucfirst($customer_meta_data['card_type'])
            );

            $headers = array(
                "MerchantId: " . $this->cielo_merchant_id,
                "MerchantKey: " . $this->cielo_merchant_key,
                "Content-Type: application/json"
            );
        }

        $response = send_payment_request($api_url . $method, $customer_data['token'], $data, $headers);
        // echo "resp =>";prx($response);

        if(
            isset($response['CardToken']) && 
            $response['CardToken']
        ) {
            return array('success' => true, 'card_token' => $response['CardToken']);
        }
    }

    /**
     * Checks if gateway settings are filled
     *
     * @return bool
     */
    public function areGatewayCredentialsFilled()
    {
        $filled                       = true;
        $selected_gateway_credentials = $this->getSelectedGatewayCredentials();

        foreach ($selected_gateway_credentials as $credential) {
            if (empty($credential)) {
                $filled = false;
            }
        }

        return $filled;
    }

    /**
     * @param bool $publicOnly
     * @return array
     */
    public function getSelectedGatewayCredentials($publicOnly = false)
    {
        $credentials = $this->getGatewayCredentials($this->selected_gateway, $publicOnly);

        return $credentials;
    }

    /**
     * @param null $filter
     * @param bool $publicOnly
     * @return array
     */
    public function getGatewayCredentials($filter = null, $publicOnly = false)
    {
        $credentials                                     = array();
        $credentials['selected_payment_gateway']         = $this->selected_gateway; // itodo legacy
        
        $meta_data = json_decode($this->company_gateway_settings['gateway_meta_data'], true);
        // $meta_data = $meta_data['asaas'];

        $credentials['payment_gateway'] = array(
            'cielo_merchant_id' => isset($meta_data["cielo_merchant_id"]) ? $meta_data["cielo_merchant_id"] : "",
            'cielo_merchant_key' => isset($meta_data["cielo_merchant_key"]) ? $meta_data["cielo_merchant_key"] : "",
            'cielo_client_id' => isset($meta_data["cielo_client_id"]) ? $meta_data["cielo_client_id"] : "",
            'cielo_client_secret' => isset($meta_data["cielo_client_secret"]) ? $meta_data["cielo_client_secret"] : ""
        );

        $result                                = $credentials;

        if ($filter) {
            $result                             = isset($result[$filter]) ? $result[$filter] : $result['payment_gateway'];
            $result['selected_payment_gateway'] = $this->selected_gateway; // itodo legacy
        }

        return $result;
    }

    /**
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->error_message;
    }

    /**
     * @param string $error_message
     */
    public function setErrorMessage($error_message)
    {
        $this->error_message = $error_message;
    }

    /**
     * @param $payment_id
     */
    public function refundBookingPayment($payment_id, $amount, $payment_type, $booking_id = null)
    {
        $result = array("success" => true, "refund_id" => true);
        $this->ci->load->model('Payment_model');
        $this->ci->load->model('Customer_model');
   
        $payment = $this->ci->Payment_model->get_payment($payment_id);
        
        try {
            if ($payment['payment_gateway_used'] and $payment['gateway_charge_id']) {
                $customer    = $this->ci->Customer_model->get_customer($payment['customer_id']);
                
                unset($customer['cc_number']);
                unset($customer['cc_expiry_month']);
                unset($customer['cc_expiry_year']);
                unset($customer['cc_tokenex_token']);
                unset($customer['cc_cvc_encrypted']);

                $card_data = $this->ci->Card_model->get_active_card($payment['customer_id'], $this->ci->company_id);

                if(isset($card_data) && $card_data){
                    $customer['cc_number'] = $card_data['cc_number'];
                    $customer['cc_expiry_month'] = $card_data['cc_expiry_month'];
                    $customer['cc_expiry_year'] = $card_data['cc_expiry_year'];
                    $customer['cc_tokenex_token'] = $card_data['cc_tokenex_token'];
                    $customer['cc_cvc_encrypted'] = $card_data['cc_cvc_encrypted'];

                    $customer_meta_data = json_decode($card_data['customer_meta_data'], true);
                    $customer['token'] = isset($customer_meta_data['token']) && $customer_meta_data['token'] ? $customer_meta_data['token'] : '';
                }
                
                $customer    = json_decode(json_encode($customer), 1);
                if(isset($customer['token']) && $customer['token'])
                {
                    if($payment_type == 'full'){
                        $amount = abs($payment['amount']); // in cents, only positive
                    }

                    $asaas_api_key = $this->asaas_api_key;
                    $result = $this->refund_payment($asaas_api_key, $amount, $payment['gateway_charge_id']);
                    
                    // $result = $this->refund_payment($this->selected_gateway, $payment['gateway_charge_id'], $amount, $this->currency, $booking_id, $payment['credit_card_id']);
                    
                }
                
            }
        } catch (Exception $e) {
            $result = array("success" => false, "message" => $e->getMessage());
        }

        return $result;
    }
   
    /**
     * @param $payment_type
     * @param $company_id
     * @return array
     */
    public function getPaymentGatewayPaymentType($payment_type, $company_id = null)
    {
        $payment_type = 'cielo';
        $settings   = $this->getCompanyGatewaySettings();
        $company_id = $company_id ?: $settings['company_id'];

        $row = $this->query("select * from payment_type WHERE payment_type = '$payment_type' and company_id = '$company_id'");

        if (empty($row)) {
            // if doesn't exist - create
            $this->createPaymentGatewayPaymentType($payment_type, $company_id);
            $result = $this->getPaymentGatewayPaymentType($payment_type, $company_id);
        } else {
            $result = reset($row);
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getCompanyGatewaySettings()
    {
        return $this->company_gateway_settings;
    }

    /**
     * @param array $company_gateway_settings
     */
    public function setCompanyGatewaySettings($company_gateway_settings)
    {
        $this->company_gateway_settings = $company_gateway_settings;
    }

    private function query($sql)
    {
        return $this->ci->db->query($sql)->result_array();
    }

    /**
     * @param $company_id
     */
    public function createPaymentGatewayPaymentType($payment_type, $company_id)
    {
        $this->ci->db->insert(
            'payment_type',
            array(
                'payment_type' => $payment_type,
                'company_id'   => $company_id,
                'is_read_only' => '1'
            )
        );

        return $this->ci->db->insert_id();
    }
    
    public function send_payment_link($payment_amount, $payment_link_name, $shipping_name, $shipping_price, $shipping_type)
    {
        $api_url = $this->cielo_pay_link_url;
        $method = '/api/public/v1/products';
        $method_type = 'POST';

        $data = array(
            'type' => 'Digital',
            'name' => $payment_link_name,
            'price' => $payment_amount,
            'shipping' => array(
                'type' => $shipping_type,
                'name' => $shipping_name,
                'price' => $shipping_price
            )
        );

        // get access token
        $access_token = "";
        $access_token_resp = $this->get_access_token();

        if(
            isset($access_token_resp['access_token']) && 
            $access_token_resp['access_token']
        ) {
            $access_token = $access_token_resp['access_token'];
        }

        $headers = array(
            "Authorization: Bearer " . $access_token,
            "Content-Type: application/json"
        );
    
        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        // prx($response);
        return $response;
    }

    public function verify_cielo_payment($payment_link_id, $payment_id)
    {
        $api_url = $this->cielo_pay_link_url;
        $method = '/api/public/v1/products/'.$payment_link_id.'/payments';
        $method_type = 'GET';

        $data = array();

        // get access token
        $access_token = "";
        $access_token_resp = $this->get_access_token();

        if(
            isset($access_token_resp['access_token']) && 
            $access_token_resp['access_token']
        ) {
            $access_token = $access_token_resp['access_token'];
        }

        $headers = array(
            "Authorization: Bearer " . $access_token
        );
    
        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        // prx($response);
        return $response;
    }

    function get_access_token(){
        $api_url = $this->cielo_pay_link_url;
        $method = '/api/public/v2/token';
        $method_type = 'POST';

        $data = array();

        $cielo_client_id = $this->cielo_client_id;
        $cielo_client_secret = $this->cielo_client_secret;

        $base_64_encode_key = base64_encode($this->cielo_client_id.':'.$this->cielo_client_secret);

        $headers = array(
            "Authorization: Basic " . $base_64_encode_key,
            "Content-Type: application/x-www-form-urlencoded"
        );
    
        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        // prx($response);
        return $response;
    }

    public function call_api($api_url, $method, $data, $headers, $method_type = 'POST'){

        $url = $api_url . $method;
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            
        if($method_type == 'GET'){

        } else if($method_type == 'delete'){
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        } else if($method_type == 'PUT'){
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($curl, CURLOPT_POSTFIELDS,http_build_query($data));
        } else {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
               
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        $response = curl_exec($curl);        

        curl_close($curl);
        return $response;
    }
}