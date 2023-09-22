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
        $this->load->library('../extensions/'.$this->module_name.'/libraries/AsaasEmailTemplate');

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
                $key != 'cielo_merchant_key'
            )
            {
                $data[$key] = $this->input->post($key);
            }
        }

        $meta = array(
            "cielo_merchant_id" => $_POST['cielo_merchant_id'],
            "cielo_merchant_key" => $_POST['cielo_merchant_key']
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
}

