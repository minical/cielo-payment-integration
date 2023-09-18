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
            case 'asaas':
                $gateway_meta_data = json_decode($this->company_gateway_settings['gateway_meta_data'], true);
                $this->asaas_api_key = $gateway_meta_data['asaas_api_key'];
                $this->asaas_cpf_cnpj = isset($gateway_meta_data['cpf_cnpj']) && $gateway_meta_data['cpf_cnpj'] ? $gateway_meta_data['cpf_cnpj'] : null;
                if($this->asaas_api_key)
                    Client::connect($this->asaas_api_key, $this->asaas_env);
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

    public static function getGatewayNames()
    {
        return array(
            'stripe' => 'Stripe'
        );
    }

    /**
     * @return int
     */
    public function getStripeToken()
    {
        return $this->stripe_token;
    }

    /**
     * @param $token
     */
    public function setCCToken($token)
    {
        switch ($this->selected_gateway) {
            case 'stripe':
                $this->setStripeToken($token);
                break;
        }
    }

    /**
     * @param int $stripe_token
     */
    private function setStripeToken($stripe_token)
    {
        $this->stripe_token = $stripe_token;
    }

    /**
     * @return string
     */
    public function getStripePrivateKey()
    {
        return $this->stripe_private_key;
    }

    /**
     * @param string $stripe_private_key
     */
    public function setStripePrivateKey($stripe_private_key)
    {
        $this->stripe_private_key = $stripe_private_key;
    }

    /**
     * @return string
     */
    public function getStripePublicKey()
    {
        return $this->stripe_public_key;
    }

    /**
     * @param string $stripe_public_key
     */
    public function setStripePublicKey($stripe_public_key)
    {
        $this->stripe_public_key = $stripe_public_key;
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

    public function createExternalEntity()
    {
        $external_id = null;

        switch ($this->selected_gateway) {
            case 'stripe':
                $data        = \Stripe\Customer::create(
                    array(
                        "description" => json_encode(
                            array(
                                'customer_id' => isset($this->customer['customer_id']) ? $this->customer['customer_id'] : 'new_customer',
                            )
                        ),
                        "source"      => $this->stripe_token
                    )
                );
                $external_id = $data->id;
                break;
        }

        return $external_id;
    }

    /**
     * @return mixed
     */
    public function getCustomer()
    {
        return $this->customer;
    }

    /**
     * @param $customer_id
     */
    public function setCustomerById($customer_id)
    {
        $customer = $this->ci->Customer_model->get_customer($customer_id);
        unset($customer['cc_number']);
        unset($customer['cc_expiry_month']);
        unset($customer['cc_expiry_year']);
        unset($customer['cc_tokenex_token']);
        unset($customer['cc_cvc_encrypted']);
        
        $card_data = $this->ci->Card_model->get_active_card($customer_id, $this->ci->company_id);
            
        if($card_data){
            $customer['cc_number'] = $card_data['cc_number'];
            $customer['cc_expiry_month'] = $card_data['cc_expiry_month'];
            $customer['cc_expiry_year'] = $card_data['cc_expiry_year'];
            $customer['cc_tokenex_token'] = $card_data['cc_tokenex_token'];
            $customer['cc_cvc_encrypted'] = $card_data['cc_cvc_encrypted'];
        }
            
        $this->customer = json_decode(json_encode($customer), 1);
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
    public function createBookingCharge($booking_id, $amount, $customer_id = null, $installment_charge, $installment_count)
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
               
                if(isset($customer_meta_data['customer_id']) && $customer_meta_data['customer_id'])
                {
                    $asaas_api_key = $this->asaas_api_key;
                    // use tokenex for payments
                    $charge = $this->make_payment($asaas_api_key, $amount, $this->currency, $customer_meta_data, $customer, $installment_charge, $installment_count);

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
        $hasExternalId = (isset($customer[$this->getExternalEntityField()]) and $customer[$this->getExternalEntityField()]);
        $customer_meta_data = json_decode($customer['customer_meta_data'], true);
        $hasTokenexToken = (isset($customer_meta_data['cielo_token']) and $customer_meta_data['cielo_token']);

        if(!$hasTokenexToken) {
            
            $create_customer_response = $this->create_card_token($customer);

            $meta['customer_id'] = isset($create_customer_response['success']) && $create_customer_response['success'] && isset($create_customer_response['customer_id']) && $create_customer_response['customer_id'] ? $create_customer_response['customer_id'] : null;

            $meta['token'] = isset($customer_meta_data['token']) && $customer_meta_data['token'] ? $customer_meta_data['token'] : '';

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
            $hasExternalId = (isset($customer[$this->getExternalEntityField()]) and $customer[$this->getExternalEntityField()]);
            $customer_meta_data = json_decode($customer['customer_meta_data'], true);
            $hasTokenexToken = (isset($customer_meta_data['customer_id']) and $customer_meta_data['customer_id']);
        }

        if (
            $this->areGatewayCredentialsFilled()
            and $customer
            and ($hasExternalId or $hasTokenexToken)
        ) {
            $result = true;
        }

        return $result;
    }

    /**
     * @return string
     */
    public function getExternalEntityField()
    {
        $name = '';
        switch ($this->selected_gateway) {
            case 'stripe':
                $name = 'stripe_customer_id';
                break;
        }

        return $name;
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
            'cielo_merchant_key' => isset($meta_data["cielo_merchant_key"]) ? $meta_data["cielo_merchant_key"] : ""
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

    
    public function getCustomerTokenInfo()
    {
        $data = array();
        foreach ($this->getPaymentGateways() as $gateway => $settings) {
            if (isset($this->customer[$settings['customer_token_field']]) and $this->customer[$settings['customer_token_field']]) {
                $data[$gateway] = $this->customer[$settings['customer_token_field']];
            }
        }
        return $data;
    }

    /**
     * @return array
     */
    public static function getPaymentGateways()
    {
        return array(
            'stripe' => array(
                'name'                 => 'Stripe',
                'customer_token_field' => 'stripe_customer_id'
            )
        );
    }
   
    /**
     * @param $payment_type
     * @param $company_id
     * @return array
     */
    public function getPaymentGatewayPaymentType($payment_type, $company_id = null)
    {
        $payment_type = 'hoteli.pay';
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
    
    function getCustomerFields($field_name, $customer_id){
        $this->ci->load->model('../extensions/'.$this->ci->current_payment_gateway.'/models/Customer_model');
        $customer = $this->ci->Customer_model->get_customer_field_by_name($customer_id, $field_name);
        return $customer;
    }

    public function make_payment($asaas_api_key, $amount, $currency, $customer_meta_data, $customer, $installment_charge, $installment_count)
    {	

        $webhook = $this->get_webhook($this->asaas_api_key);

        if ($webhook["url"] !== site_url('hotelipay_callback')) {
            $create_webhook = $this->create_webhook($this->asaas_api_key);
        }
		
		
 	$has_options = $this->ci->Asaas_model->getCustomOptions($this->ci->company_id, 'asaas_sale_comission');
	$asaas_sale_comission = $has_options ? $has_options : 0;
	
    if (function_exists('send_payment_request')) {
        $api_url = $this->asaas_url;
        $method = '/api/v3/payments';
        $method_type = 'POST';
		
		$split = NULL;
		
		if($asaas_sale_comission !== 0){
		$split = array(
                    array(
                        'walletId' => $_SERVER['ASAAS_WALLET_ID'],
                        //'fixedValue' => 20
                        'percentualValue' => $asaas_sale_comission
                    )
                );
		}

        if (isset($customer['cc_tokenex_token']) && $customer['cc_tokenex_token']) {
            $data = array(
                'customer' => $customer_meta_data['customer_id'],
                'billingType' => 'CREDIT_CARD',
                'dueDate' => date("Y-m-d", strtotime("+ 1 day")),
                'value' => $amount,
                'creditCardToken' => $customer['cc_tokenex_token']
            );
        } else {

            $cpfCnpj_data = $this->getCustomerFields('CPF', $customer['customer_id']);

            // $assas_customer_data = $this->getAssasCustomerDetail($customer_meta_data['customer_id']);

            if(
                isset($cpfCnpj_data['name']) 
                && $cpfCnpj_data['name'] == 'CPF'
                && isset($cpfCnpj_data['value']) 
                && $cpfCnpj_data['value']
            ){
                $cpfCnpj = $cpfCnpj_data['value'];
            } else {
                $cpfCnpj = $this->asaas_cpf_cnpj;
            }

            $data = array(
                'customer' => $customer_meta_data['customer_id'],
                'billingType' => 'CREDIT_CARD',
                'dueDate' => date("Y-m-d", strtotime("+ 1 day")),
                'value' => $amount,
                'creditCard' => array(
                    'holderName' => "%CARDHOLDER_NAME%",
                    'number' => "%CARD_NUMBER%",
                    'expiryMonth' => "%EXPIRATION_MM%",
                    'expiryYear' => "%EXPIRATION_YYYY%",
                    'ccv' => "%SERVICE_CODE%"
                ),
                'creditCardHolderInfo' => array(
                    'name' => $customer['customer_name'],
                    'email' => $customer['customer_data']['email'],
                    'cpfCnpj' => $cpfCnpj,
                    'postalCode' => $customer['customer_data']['postal_code'],
                    'addressNumber' => $customer['customer_data']['address'],
                    'phone' => $customer['customer_data']['phone'],
                    'address' => $customer['customer_data']['address'],
                    'city' => $customer['customer_data']['city'],
                    'state' => $customer['customer_data']['region']

                ),
                //
                'split' => $split
            );
        }

        if ($installment_charge == 'true') {
            unset($data['value']);
            $data['installmentCount'] = $installment_count;
            $data['totalValue'] = $amount;
        }

        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = send_payment_request($api_url . $method, $customer_meta_data['token'], $data, $headers);

        if (isset($response['object']) && $response['object'] == 'payment' && isset($response['id']) && $response['id']) {

            $update_card_data = array(
                'cc_tokenex_token' => $response['creditCard']['creditCardToken'],
                'cc_cvc_encrypted' => null
            );
            $this->ci->Card_model->update_customer_primary_card($customer['customer_id'], $update_card_data);

            $property_data = $this->ci->Channex_model->get_channex_x_company(null, $this->ci->company_id);
            $log_data = array(
                'ota_property_id' => $property_data['ota_property_id'],
                'request_type' => 5,
                'response_type' => 0,
                'xml_in' => json_encode($data),
                'xml_out' => json_encode($response)
            );
            $this->ci->Channex_model->save_logs($log_data);

            return array('success' => true, 'charge_id' => $response['id']);
        } else if (isset($response['errors']) && $response['errors']) {

            $property_data = $this->ci->Channex_model->get_channex_x_company(null, $this->ci->company_id);
            $log_data = array(
                'ota_property_id' => $property_data['ota_property_id'],
                'request_type' => 5,
                'response_type' => 1,
                'xml_in' => json_encode($data),
                'xml_out' => json_encode($response)
            );
            $this->ci->Channex_model->save_logs($log_data);

            return array('success' => false, 'errors' => $response['errors']);
        }
    } else {
        return array('success' => false, 'errors' => 'Tokenization service is not available.');
    }
}

    public function refund_payment($asaas_api_key, $amount, $gateway_charge_id){

        $api_url = $this->asaas_url;
        $method = '/api/v3/payments/'.$gateway_charge_id.'/refund';
        $method_type = 'POST';

        $data = array();

        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);

        if(isset($response['object']) && $response['object'] == 'payment' && isset($response['id']) && $response['id'] && isset($response['status']) && $response['status'] == 'REFUNDED') {

            return array('success' => true, 'refund_id' => $response['id']);
        } else if(isset($response['errors']) && $response['errors']) {
            return array('success' => false, 'message' => $response['errors']);
        }
    }

    public function getAccountDetails($asaas_api_key){

        $api_url = $this->asaas_url;
        $method = '/api/v3/accounts?limit=100';
        $method_type = 'GET';

        $data = array();

        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        return $response;
    }

    public function getAssasCustomerDetail($assas_customer_id){

        $api_url = $this->asaas_url;
        $method = '/api/v3/customers/'.$assas_customer_id;
        $method_type = 'GET';

        $data = array();

        $asaas_api_key = '';
        if(isset($this->asaas_api_key) && $this->asaas_api_key){
            $asaas_api_key = $this->asaas_api_key;
        }

        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        return $response;
    }

    public function getCharges($is_status = false){

        $api_url = $this->asaas_url;

        if($is_status)
            $method = '/api/v3/payments?limit=100&status='.$this->asaas_status;
        else
            $method = '/api/v3/payments?limit=100';

        // $method = '/api/v3/payments?billingType=CREDIT_CARD&limit=100';
        // $method = '/api/v3/payments?limit=100';
        $method_type = 'GET';

        $asaas_api_key = '';
        if(isset($this->asaas_api_key) && $this->asaas_api_key){
            $asaas_api_key = $this->asaas_api_key;
        }

        $data = array();

        $headers = array(
            "access_token: " . $asaas_api_key
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);

        // prx($response);
        return $response;
    }

    public function getCurrentBalance(){

        $api_url = $this->asaas_url;
        $method = '/api/v3/finance/getCurrentBalance';
        $method_type = 'GET';

        $asaas_api_key = '';
        if(isset($this->asaas_api_key) && $this->asaas_api_key){
            $asaas_api_key = $this->asaas_api_key;
        }
	   
        $data = array();

        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);

        // prx($response);
        return $response;
    }

    public function send_payment_link($customer_meta_data, $payment_amount, $payment_link_name, $due_date, $installment_charge, $installment_count)
{
        $webhook = $this->get_webhook($this->asaas_api_key);

        if ($webhook["url"] !== site_url('hotelipay_callback')) {
            $create_webhook = $this->create_webhook($this->asaas_api_key);
        }
   	$has_options = $this->ci->Asaas_model->getCustomOptions($this->ci->company_id, 'asaas_sale_comission');
	$asaas_sale_comission = $has_options ? $has_options : 0;
	
	$api_url = $this->asaas_url;
    $method = '/api/v3/payments';
    $method_type = 'POST';
	
	$split = NULL;
	
		if($asaas_sale_comission !== 0){
		$split = array(
                    array(
                        'walletId' => $_SERVER['ASAAS_WALLET_ID'],
                        //'fixedValue' => 20
                        'percentualValue' => $asaas_sale_comission
                    )
                );
		}

    $data = array(
	    'customer' => $customer_meta_data['customer_id'],
        'description' => $payment_link_name,
        'value' => $payment_amount,
        'billingType' => 'UNDEFINED',       
        'dueDate' => date("Y-m-d", strtotime("+ ". $due_date." days")),      
             'split' => $split
    );

    if ($installment_charge == 'true') {
        // unset($data['value']);
        $data['chargeType'] = 'INSTALLMENT';
        $data['maxInstallmentCount'] = $installment_count;
        // $data['totalValue'] = $payment_amount;
    }

    $headers = array(
        "access_token: " . $this->asaas_api_key,
        "Content-Type: application/json"
    );
	
	//var_dump($data);
//die();
    $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

    $response = json_decode($response, true);
    $response['currency'] = $this->currency;
    return $response;
}

    function get_customer_name($asaas_customer_id){
        $this->ci->load->model('../extensions/'.$this->ci->current_payment_gateway.'/models/Customer_model');
        $customer = $this->ci->Customer_model->get_asaas_customer($asaas_customer_id);
        return $customer;
    }

    function get_booking($asaas_payment_id){
        $this->ci->load->model('../extensions/'.$this->ci->current_payment_gateway.'/models/Asaas_model');
        $booking = $this->ci->Asaas_model->get_booking_details($asaas_payment_id);
        return $booking;
    }

    function cr($customer_data){
        $customer = new CustomerEntity();
        $customer->name = $customer_data['customer_name'];
        $customer->email = isset($customer_data['email']) && $customer_data['email'] ? $customer_data['email'] : '';

        $customer->cpfCnpj = '85267610054';

        $customerCreated = $customer->create();

        $customer_repsonse = json_decode(json_encode($customerCreated, true), true);

        return array('success' => true, 'customer_id' => $customer_repsonse['id']);
        
    }
	
	
	   function get_customer($asaas_api_key,  $customer_email){

       $data = [];
        $api_url = $this->asaas_url;
        $method = '/api/v3/customers?email='. $customer_email;
        $method_type = 'GET';
		  $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);
		
        $response = json_decode($response, true);
		//var_dump($response);
		//die();
		
		if($response["totalCount"] == 0) {
		 $create_customer = $this->create_customer(['customer_name' => $customer_email, 'email' => $customer_email]);	
		 
		 return $create_customer['customer_id'];
		} else {		
        return $response['data'][0]['id'];
		}
 }
	
	
// return account wallet_id
   function asaas_wallets($asaas_api_key){

$data = [];

        $api_url = $this->asaas_url;
        $method = '/api/v3/wallets';
        $method_type = 'GET';
		  $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
 return $response;

//die();
 }
 // new 
   function excerpt(){     
	 $data = [];
	 // TODO: add filters
	     $asaas_api_key = '';
        if (isset($this->asaas_api_key) && $this->asaas_api_key) {
            $asaas_api_key = $this->asaas_api_key;
        }
        $api_url = $this->asaas_url;
        $method = '/api/v3/financialTransactions';
        $method_type = 'GET';
		  $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
 return $response;
         }
 
    function create_asaas_account($account_data, $company_id, $asaas_api_key){

        $api_url = $this->asaas_url;
        $method = '/api/v3/accounts';
        $method_type = 'POST';

$asaas_api_key = getenv("ASAAS_API_KEY") ? getenv("ASAAS_API_KEY") : $_SERVER["ASAAS_API_KEY"];

        $data = array(
                        'name' => $account_data['account_name'],
                        'email' => $account_data['account_email'],
                        'cpfCnpj' => $account_data['cpf_cnpj'],
                        'companyType' => $account_data['company_type'],
                        'phone' => $account_data['phone'],
                        'mobilePhone' => $account_data['mobile_phone'],
                        'address' => $account_data['address'],
                        'addressNumber' => $account_data['address_number'],
                        'province' => $account_data['province'],
                        'postalCode' => $account_data['postal_code']
                    );

        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);

        if(isset($response['object']) && $response['object'] == 'account' && isset($response['apiKey']) && $response['apiKey']) {

            $meta = array("asaas" => 
                        array(
                            'asaas_api_key' => $response['apiKey'],
                            'asaas_wallet_id' => $response['walletId'],
                            'cpf_cnpj' => $response['cpfCnpj']
                        )
                    );

            $meta_data = $this->ci->Payment_gateway_model->get_payment_gateway_settings($this->ci->company_id);

            if(isset($meta_data['gateway_meta_data']) && $meta_data['gateway_meta_data']){
                $meta_data = json_decode($meta_data['gateway_meta_data'], true);

                $meta = array_merge($meta, $meta_data);
            }

            $update_data['gateway_meta_data'] = json_encode($meta);

            $update_data['company_id'] = $company_id;
            $update_data['selected_payment_gateway'] = 'asaas';
            $this->ci->Payment_gateway_model->update_payment_gateway_settings($update_data);

            return array('success' => true, 'asaas_api_key' => $response['apiKey'], 'asaas_wallet_id' => $response['walletId']);
        } else if(isset($response['errors']) && $response['errors']) {
            return array('success' => false, 'errors' => $response['errors']);
        }
    }

    // function withdrawal_amount($withdrawal_data, $company_id, $asaas_api_key){
    function withdrawal_amount($withdrawal_data, $company_id){

        $api_url = $this->asaas_url;
        $method = '/api/v3/transfers';
        $method_type = 'POST';

        $data = array(
                        'value' => number_format($withdrawal_data['amount'], 2, ".", ","),
                        'bankAccount' => array(
                                        'bank' => array(
                                                        'code' => $withdrawal_data['bank_code']
                                                    ),
                                        
                                        'cpfCnpj' => $withdrawal_data['cpf_cnpj'],
                                        'ownerName' => $withdrawal_data['owner_name'],
                                        'agency' => $withdrawal_data['agency'],
                                        'account' => $withdrawal_data['bank_account_number'],
                                        'accountDigit' => $withdrawal_data['bank_account_digit'],
                                        'bankAccountType' => $withdrawal_data['account_type']
                                    )
                    );

        $asaas_api_key = '';
        if(isset($this->asaas_api_key) && $this->asaas_api_key){
            $asaas_api_key = $this->asaas_api_key;
        }
 
        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);

   
        if(isset($response['object']) && $response['object'] == 'transfer' && isset($response['id']) && $response['id']) {

            $this->ci->load->model('../extensions/'.$this->ci->current_payment_gateway.'/models/Asaas_model');

            $withdrawal_data['company_id'] = $company_id;
            $withdrawal_data['transfer_id'] = $response['id'];
            $withdrawal_data['transfer_status'] = $response['status'];
            $withdrawal_data['created_date'] = date('Y-m-d H:i:s');
            
            $hoteli_pay_bank_details = $this->ci->Asaas_model->get_hoteli_pay_bank_details($company_id);
            if(isset($hoteli_pay_bank_details) && count($hoteli_pay_bank_details) > 0){
                $this->ci->Asaas_model->save_hoteli_pay_bank_details($withdrawal_data);
            } else {
                $this->ci->Asaas_model->update_hoteli_pay_bank_details($withdrawal_data);
            }

            return array('success' => true, 'transfer_id' => $response['id']);
        } 
        else if(isset($response['errors']) && $response['errors']) {
            return array('success' => false, 'errors' => $response['errors']);
        }
    }

    function delete_payment($payment){

        $api_url = $this->asaas_url;
        $method = '/api/v3/payments/'.$payment['payment_link_id'];
        $method_type = 'delete';

        $data = array();

        $headers = array(
            "access_token: " . $this->asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        return $response;
    }
	
	
    function get_webhook($asaas_api_key)
    {
        $api_url = $this->asaas_url;
        $method =  "/api/v3/webhook";
        $method_type = 'GET';

        $data = array();

        $asaas_api_key = $asaas_api_key ? $asaas_api_key : $this->asaas_api_key;

        $headers = array(
            "access_token: " . $asaas_api_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        return $response;
    }

    function create_webhook($asaas_key)
    {
        $data = [
            "url" => site_url('hotelipay_callback'),
            "email" => "contato@hoteli.com.br",
            "interrupted" => false,
            "enabled" => true,
            "apiVersion" => "3",
            "authToken" => ""
        ];
        $api_url = $this->asaas_url;
        $method =  "/api/v3/webhook";
        $method_type = 'POST';

        $headers = array(
            "access_token: " . $asaas_key,
            "Content-Type: application/json"
        );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);
        $response = json_decode($response, true);
		return  $response;        
    }
 public function get_company($company_id, $get_last_actio = 0)
 {
 $company = $this->ci->company_model->get_company($company_id, array("get_last_action" => (boolean) $get_last_action)); 
 }

    function asaas_customers(){

        $data = [];
        // TODO: add filters
        $asaas_api_key = '';
        if (isset($this->asaas_api_key) && $this->asaas_api_key) {
            $asaas_api_key = $this->asaas_api_key;
        }

        $api_url = $this->asaas_url;
        $method = '/api/v3/customers';
        $method_type = 'GET';
            $headers = array(
                "access_token: " . $asaas_api_key,
                "Content-Type: application/json"
            );

        $response = $this->call_api($api_url, $method, $data, $headers, $method_type);

        $response = json_decode($response, true);
        return $response;
    }
 
    public function call_api($api_url, $method, $data, $headers, $method_type = 'POST'){

        $url = $api_url . $method;
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            
        if($method_type == 'GET'){

        } else if($method_type == 'delete'){
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
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