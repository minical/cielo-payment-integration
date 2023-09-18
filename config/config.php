<?php 

$config = array(
    "name" => "Cielo Payment Gateway",
    "description" => "This extension is devised as the channel to make payments. The procedure to make payments includes the customer requiring to fill in some details, like credit/debit card number, expiry date, and CVV.",
    "is_default_active" => 1,
    "version" => "1.0.0",
    "logo" => "image/logo.png",
    "setting_link" => "cielo_payment_gateway",
    "view_link" => "cielo_transaction_history",
    "gateway_key" => "cielo",
	"app_environment" => "development",
    "categories" => array("payment_process"),
	"marketplace_product_link" => "https://marketplace.minical.io/product/cielo"
);