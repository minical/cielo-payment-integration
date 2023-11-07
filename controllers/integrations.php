<?php
class Integrations extends MY_Controller
{
     public $module_name;
    function __construct()
    {
        parent::__construct();
        $this->module_name = $this->router->fetch_module();

        $this->load->model('../extensions/'.$this->module_name.'/models/Payment_gateway_model');
        $this->load->model('../extensions/'.$this->module_name.'/models/Employee_log_model');
        $this->load->model('../extensions/'.$this->module_name.'/models/Booking_model');
        $this->load->model('../extensions/'.$this->module_name.'/models/Card_model');
        $this->load->model('../extensions/'.$this->module_name.'/models/Customer_model');
        $this->load->model('../extensions/'.$this->module_name.'/models/Payment_model');
        $this->load->model('../extensions/'.$this->module_name.'/models/Asaas_model');
        $this->load->model('../extensions/'.$this->module_name.'/models/Invoice_log_model');

        $this->load->library('../extensions/'.$this->module_name.'/libraries/ProcessPayment');
        $this->load->library('../extensions/'.$this->module_name.'/libraries/CieloEmailTemplate');

        // $this->load->library('PaymentGateway');
        
        $view_data['menu_on'] = true;       
        $this->load->vars($view_data);
    }

    function cielo_payment_gateway()
    {
        
        $data['main_content'] = '../extensions/'.$this->module_name.'/views/payment_gateway_settings';
        $this->load->view('includes/bootstrapped_template', $data);
    }

    function update_cielo_payment_gateway_settings() {
        foreach($_POST as $key => $value)
        {
            if(
                $key != 'cielo_merchant_id' &&
                $key != 'cielo_merchant_key' &&
                $key != 'cielo_client_id' &&
                $key != 'cielo_client_secret'
            )
            {
                $data[$key] = $this->input->post($key);
            }
        }

        $meta = array(
            "cielo_merchant_id" => $_POST['cielo_merchant_id'],
            "cielo_merchant_key" => $_POST['cielo_merchant_key'],
            "cielo_client_id" => $_POST['cielo_client_id'],
            "cielo_client_secret" => $_POST['cielo_client_secret']
        );

        $data['gateway_meta_data'] = json_encode($meta);
        
        $data['company_id'] = $this->company_id;
        $this->Payment_gateway_model->update_payment_gateway_settings($data);
        $this->_create_accounting_log("Update Payment Gateway Setting");
        
        echo json_encode($data);
    }
    
    function get_cielo_payment_gateway_settings() {
        $data = $this->processpayment->getGatewayCredentials();
        echo json_encode($data);
    }

    function _create_accounting_log($log) {
        $log_detail =  array(
                    "user_id" => $this->user_id,
                    "selling_date" => $this->selling_date,
                    "date_time" => gmdate('Y-m-d H:i:s'),
                    "log" => $log,
                );   
        
        $this->Employee_log_model->insert_log($log_detail);     
    }

    public function verify_cielo_payment() {

        $payment_link_id = $this->input->post('payment_link_id');
        $payment_id = $this->input->post('payment_id');
        $customer_id = $this->input->post('customer_id');

        $payment_link_status = $this->processpayment->verify_cielo_payment(
                                                $payment_link_id, 
                                                $payment_id
                                            );

        if(
            isset($payment_link_status['orders']) && 
            $payment_link_status['orders']
        ){
            if(
                isset($payment_link_status['orders'][0]) &&
                $payment_link_status['orders'][0] && 
                isset($payment_link_status['orders'][0]['payment']) &&
                $payment_link_status['orders'][0]['payment'] &&
                isset($payment_link_status['orders'][0]['payment']['status']) &&
                $payment_link_status['orders'][0]['payment']['status'] == 'Paid'
            ) {
                $data['credit_card_id'] = null;
                $card_data = $this->Card_model->get_active_card($customer_id, $this->company_id);
                
                if (isset($card_data) && $card_data) {
                    $data['credit_card_id'] = $card_data['id'];
                }

                $data = Array(
                    "date_time" => gmdate("Y-m-d H:i:s"),
                    "is_captured" => '1',
                    "payment_status" => 'charge',
                    "gateway_charge_id" => $payment_link_status['productId'],
                    "description" => ""
                );
                $this->Payment_model->update_payment($payment_id, $data);

                $post_payment_data = $data;
                $post_payment_data['payment_id'] = $payment_id;

                do_action('post.update.payment', $post_payment_data);

                echo json_encode(array('success' => true ));
            }
        } else {
            $message = l('cielo-payment-integration/Payment not done yet', true);
            echo json_encode(array('success' => false, 'message' => $message));
        }
    }

    public function verify_payment_link($payment_link_id) {

        $asaas_status = ($this->config->item('app_environment') == "development") ? false : true;
        $charges = $this->processpayment->getCharges($asaas_status);

        $is_payment_varified = false;
        $payment_data = array();
        if(isset($charges['data']) && count($charges['data']) > 0){
            foreach($charges['data'] as $charge){
                if($charge['id'] == $payment_link_id){
                    $payment_data = $charge;
                    $is_payment_varified = true;
                    break;
                }
            }
        }

        if($is_payment_varified){
            return true;
        } else {
            return false;
        }
    }

    function send_cielo_payment_link() {

        $shipping_name = $this->input->post('shipping_name');
        $shipping_price = $this->input->post('shipping_price');
        $shipping_type = $this->input->post('shipping_type');
        $payment_link_name = $this->input->post('payment_link_name');
        $payment_amount = $this->input->post('payment_amount');
        $booking_id = $this->input->post('booking_id');
        // $installment_charge = $this->input->post('installment_charge');
        // $installment_count = $this->input->post('installment_count');
    
        $booking = $this->Booking_model->get_booking($booking_id);
        $customer_id =  $booking['booking_customer_id'];
        $customer = $this->Customer_model->get_customer($customer_id);
     
        // $asaas_api_key = getenv("ASAAS_API_KEY") ? getenv("ASAAS_API_KEY") : $_SERVER["ASAAS_API_KEY"];
     
        // $customer_meta_data['customer_id'] = $this->processpayment->get_customer($asaas_api_key,  $customer['email']);
        // $customer['customer_meta_data'] = $customer_meta_data;   
        $customer['customer_data'] = $customer;
     
        $payment_link = $this->processpayment->send_payment_link(
                                                // $customer_meta_data, 
                                                abs($payment_amount) * 100, // in cents, only positive, 
                                                $payment_link_name, 
                                                $shipping_name,
                                                $shipping_price,
                                                $shipping_type
                                            );

        // prx($payment_link, 1);

        if(
            isset($payment_link['id']) && 
            $payment_link['id'] && 
            isset($payment_link['shortUrl']) && 
            $payment_link['shortUrl']
        ){

            $payment_gateway_used = $this->processpayment->getSelectedGateway();

            $payment_type    = $this->processpayment->getPaymentGatewayPaymentType($this->selected_payment_gateway);
            $payment_type_id = $payment_type['payment_type_id'];

            $data = array(
                "user_id" => $this->session->userdata('user_id'),
                "booking_id" => $booking_id,
                "selling_date" => date('Y-m-d', strtotime($this->input->post('payment_date'))),
                "amount" => $payment_amount,
                "customer_id" => $this->input->post('customer_id'),
                "payment_type_id" => $payment_type_id,
                "payment_gateway_used" => $payment_gateway_used,
                "date_time" => gmdate("Y-m-d H:i:s"),
                "is_captured" => '0',
                "description" => $payment_link_name,
                "payment_status" => 'payment_link',
                "payment_link_id" => $payment_link['id']
            );
            $payment_id = $this->Payment_model->create_payment($data);

            if ($this->Booking_model->booking_belongs_to_company($booking_id, $this->company_id))
            {
                $email_data = array(
                                    "shipping_name" => $shipping_name,
                                    "shipping_type" => $shipping_type,
                                    "shipping_price" => $shipping_price,
                                    "amount" => $payment_amount,
                                    "description" => $payment_link_name,
                                    "customer_id" => $data['customer_id'],
                                    "payment_link" => $payment_link['shortUrl']
                                );

                $this->cieloemailtemplate->send_payment_link_email($booking_id, $payment_link['shortUrl'], $email_data);
            }

            $this->Booking_model->update_booking_balance($booking_id);

            echo json_encode(array('success' => true, 'payment_id' => $payment_id, 'payment_link_url' => $payment_link['shortUrl']));
        } else {
            echo json_encode(array('success' => false, 'error' => $payment_link[0]['message']));
        }       
    }

    function add_cielo_payment(){
        $data = Array(
                "user_id" => $this->session->userdata('user_id'),
                "booking_id" => $this->input->post('booking_id'),
                "selling_date" => date('Y-m-d', strtotime($this->input->post('payment_date'))),
                "amount" => $this->input->post('payment_amount'),
                "customer_id" => $this->input->post('customer_id'),
                "payment_type_id" => $this->input->post('payment_type_id'),
                "description" => $this->input->post('description'),
                "date_time" => gmdate("Y-m-d H:i:s"),
                "selected_gateway" => $this->input->post('selected_gateway')
            );

            // $installment_charge = trim($this->input->post('installment_charge'));
            // $installment_count = trim($this->input->post('installment_count'));
        
            $payment_folio_id = $this->input->post('folio_id');
            $payment_folio_id = $payment_folio_id ? $payment_folio_id : 0;
            $card_data = $this->Card_model->get_active_card($data['customer_id'], $this->company_id);
            $data['credit_card_id'] = null;
            if (isset($card_data) && $card_data) {
                $data['credit_card_id'] = $card_data['id'];
            }

            $payment_type_id               = &$data['payment_type_id'];
            $use_gateway                   = ($payment_type_id == 'gateway');

            if($use_gateway){

                $payment_type    = $this->processpayment->getPaymentGatewayPaymentType($data['selected_gateway']);
                $payment_type_id = $payment_type['payment_type_id'];

                $gateway_charge_id = $this->processpayment->createBookingCharge(
                    $data['booking_id'],
                    abs($data['amount']), // in cents, only positive
                    $data['customer_id']
                );
            }

            if(isset($gateway_charge_id[0]) && isset($gateway_charge_id[0]['code'])){
                $error = $gateway_charge_id;
            } else if($gateway_charge_id == 'Tokenization service is not available.'){
                $error = $gateway_charge_id;
            }
            
            else if ($use_gateway) {
                $data['payment_gateway_used'] = $this->processpayment->getSelectedGateway();
                $data['gateway_charge_id'] = $gateway_charge_id;
                $data['is_captured'] = 1;
                $data['description'] = isset($data['description']) && $data['description'] ? $data['description'].'<br/>' : '';

                // insert payment
            
                $data['payment_status'] = 'charge';
                unset($data['selected_gateway']);
                $this->db->insert('payment', $data);            
                $query = $this->db->query('select LAST_INSERT_ID( ) AS last_id');
                $result = $query->result_array();
                if(isset($result[0]))
                {
                    $payment_id = $result[0]['last_id'];
                }

                $invoice_log_data = array();
                $invoice_log_data['date_time'] = gmdate('Y-m-d h:i:s');
                $invoice_log_data['booking_id'] = $this->input->post('booking_id');
                $invoice_log_data['user_id'] = $this->session->userdata('user_id');
                $invoice_log_data['action_id'] = CAPTURED_PAYMENT;
                $invoice_log_data['charge_or_payment_id'] = $payment_id;
                $invoice_log_data['new_amount'] = $this->input->post('payment_amount');
                if ($payment_id && $invoice_log_data['charge_or_payment_id']) {
                    $this->Payment_model->insert_payment_folio(array('payment_id' => $payment_id, 'folio_id' => $payment_folio_id));
                    $invoice_log_data['log'] = 'Payment Captured';
                    $this->Invoice_log_model->insert_log($invoice_log_data);
                }
                else {
                    $invoice_log_data['charge_or_payment_id'] = 0;
                    $invoice_log_data['log'] = isset($error) && $error ? $error : '';
                    $this->Invoice_log_model->insert_log($invoice_log_data);
                }

                $this->Booking_model->update_booking_balance($data['booking_id']);
            }

            // show error
            if (!empty($error)) {
                $response = array("success" => false, "message" => $error);
            } else {
                $response = array("success" => true, "payment_id" => $payment_id);
            }

            echo json_encode($response);
    }

    function delete_payment_row(){
        $payment_id = $this->input->post('payment_id');
        $payment = $this->Payment_model->get_payment($payment_id);
        // if the user permission level above employee, and the booking belongs to the company

        $response = $this->processpayment->delete_payment($payment);

        if(
            isset($response['deleted']) && 
            $response['deleted'] && 
            $response['id']
        ) {
            $this->Payment_model->delete_payment($payment_id);

            $post_payment_data['payment_id'] = $payment_id;
            do_action('post.delete.payment', $post_payment_data);

            $this->Booking_model->update_booking_balance($payment['booking_id']);        
            $invoice_log_data = array();
            $invoice_log_data['date_time'] = gmdate('Y-m-d h:i:s');
            $invoice_log_data['booking_id'] = $payment['booking_id'];
            $invoice_log_data['user_id'] = $this->session->userdata('user_id');
            $invoice_log_data['action_id'] = DELETE_PAYMENT;
            $invoice_log_data['charge_or_payment_id'] = $payment_id;
            $invoice_log_data['new_amount'] = $payment['amount'];
            $invoice_log_data['log'] = 'Payment Deleted';
            $this->Invoice_log_model->insert_log($invoice_log_data);

            echo json_encode(array('success' => true));
        } else {
            if(
                isset($response['errors']) &&
                isset($response['errors'][0]) &&
                isset($response['errors'][0]['description'])
            ){
                echo json_encode(array('success' => false, 'msg' => $response['errors'][0]['description']));
            } else {
                echo json_encode(array('success' => false, 'msg' => ''));
            }
        }
    }

    function save_assas_info(){
        $asaas_api_key = $this->input->post('api_key');
        $cpf_cnpj = $this->input->post('cpf_cnpj');

        $asaas_wallets = $this->processpayment->asaas_wallets($asaas_api_key);

        $wallet_id = "";
        if(isset($asaas_wallets['data']) && $asaas_wallets['data']['0'] && $asaas_wallets['data']['0']['id']) {
            $wallet_id = $asaas_wallets['data']['0']['id'];
        }

        $data = array(
                        "selected_payment_gateway" => "asaas"
                    );
        
        $meta = array(
                        "asaas_api_key" => $asaas_api_key,
                        "asaas_wallet_id" => $wallet_id,
                        "cpf_cnpj" => $cpf_cnpj
                );
        $data['gateway_meta_data'] = json_encode($meta);
        
        $data['company_id'] = $this->company_id;

        $this->Payment_gateway_model->update_payment_gateway_settings($data);
        $this->_create_accounting_log("Update Payment Gateway Setting");
        
        echo json_encode(array('success' => true));
    }
    
    public function set_webhook($asaas_key)
    {
        $webhook = $this->processpayment->get_webhook($asaas_key);

        if ($webhook["url"] !== site_url('hotelipay_callback')) {
            $create_webhook = $this->processpayment->create_webhook($asaas_key);
        }
    }
}

