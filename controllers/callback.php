<?php

class Callback extends CI_Controller
{
    public $module_name;

    function __construct()
    {
        parent::__construct();
 
        $this->load->library('../extensions/asaas-payment-integration/libraries/ProcessPayment');
        $this->load->model('../extensions/asaas-payment-integration/models/Payment_model');

    }


    function webhook()
    {
        if (strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') == 0) {
            $response = trim(file_get_contents("php://input"));
            $content = json_decode($response);
            $payment_id = $content->payment->id;
            $externalReference = $content->payment->externalReference;
            $status = $content->payment->status;
            $billingType = $content->payment->billingType;

             if ($status == "RECEIVED" || $status == "CONFIRMED" ) {
                $this->db->where('payment_link_id', $payment_id);
                $query = $this->db->get('payment')->row();     
                if ($query) {
                    $data = array(
                        "date_time" => gmdate("Y-m-d H:i:s"),
                        "is_captured" => '1',
                        "payment_status" => 'charge',
                        "gateway_charge_id" => $query->payment_link_id,
                        "description" => ""
                    );
                    $this->Payment_model->update_payment_by($query->payment_link_id, $data);
                    echo json_encode(array('success' => true));
                }
            }
			
			 if ($status == "REFUNDED" ) {
                $this->db->where('payment_link_id', $payment_id);
                $query = $this->db->get('payment')->row();     
                if ($query) {
                    $data = array(
                        "date_time" => gmdate("Y-m-d H:i:s"),
                        "is_captured" => '1',
                        "payment_status" => 'refund',
                        "gateway_charge_id" => $query->payment_link_id,
                        "description" => ""
                    );
                    $this->Payment_model->update_payment_by($query->payment_link_id, $data);
                    echo json_encode(array('success' => true));
                }
            }
			
        }
    }

}