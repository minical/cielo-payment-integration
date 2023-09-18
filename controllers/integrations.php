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
}

