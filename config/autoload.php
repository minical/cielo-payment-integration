<?php 
$config['js-files'] = array(
    array(
        "file" => 'assets/js/payment-gateway-settings-handle.js',
         "location" => array(
          "integrations/cielo_payment_gateway",
        ),
    )
    array(
        "file" => 'assets/js/payment-gateway-invoice-handle.js',
         "location" => array(
          "invoice/show_invoice",
        ),
    )
);


$config['css-files'] = array(
    array(
        "file" => 'https://cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css',
        "location" => array(
            "integrations/cielo_transaction_history"
        )
    )
);





