
// gateway button
var $methods_list = $('select[name="payment_type_id"]');
var cielo_gateway_button = $('input[name="cielo_use_gateway"]');
var cielo_selected_gateway = $('input[name="cielo_use_gateway"]').data('gateway_name');

var gatewayTypes = {
    'cielo': 'Cielo'
};
cielo_selected_gateway = gatewayTypes[cielo_selected_gateway];

$methods_list.prop('disabled', false);
cielo_gateway_button.prop('checked',0);

cielo_gateway_button.on('click',function(){
    $that = $(this);
    
    var checked = $that.prop('checked');
    $methods_list.prop('disabled', checked);
    var manualPaymentCapture = $("#manual_payment_capture").val();
    if(checked)
    {
        $methods_list
            .append(
            $('<option></option>',{
                id : 'gateway_option'
            })
                .val('gateway')
                .html(cielo_selected_gateway)
        );
        $methods_list.val('gateway');

        $(".add_payment_button").parent('.modal-footer').prepend(
                                                '<button type="button" class="btn btn-success add_payment" id="add_payment">'+
                                                    '<span alt="add" title="add">'+l('cielo-payment-integration/Add Payment')+'</span>'+
                                                '</button>'
                                            );
        
        var available_gateway = $('.paid-by-customers').children('option:selected').data('available-gateway');
        
        $('.installment_html').removeClass('hidden');
        $("#add_payment_normal").addClass('hidden');
    }else{
        $('#gateway_option').remove();
        $('#cvc-field').remove();
        $("#add_payment_normal").removeClass('hidden');
        $('.installment_html').addClass('hidden');
    }

    $(".send_payment_link").remove();
    $(".payment_link_div").remove();
    // $(".installment_payment_div").remove();
    // $("#add_payment_normal").removeClass('hidden');

});

$("body").on("click", ".add_payment", function () {

    if($('input[name="installment_charge"]').is(":checked") == true){
        var installment_charge = true;
        var installment_count = $('select[name="installment_count"]').val();
    }
    else {
        var installment_charge = false;
        var installment_count = 0;
    }

    $(this).html("Processing. . .");
    $(this).prop("disabled", true);
    
    $.ajax({
        url    : getBaseURL() + 'add_cielo_payment',
        method : 'post',
        dataType: 'json',
        data   : {
            payment_amount: $("input[name='payment_amount']").val(),
            booking_id      : $("#booking_id").val(),
            payment_date    : innGrid._getBaseFormattedDate($("input[name='payment_date']").val()),
            payment_type_id : $("select[name='payment_type_id']").val(),
            customer_id     : $("select[name='customer_id']").val(),
            payment_amount  : $("input[name='payment_amount']").val(),
            installment_charge  : installment_charge,
            installment_count  : installment_count,
            description     : $("textarea[name='description']").val(),
            cvc             : $("input[name='cvc']").val(),
            folio_id        : $('#current_folio_id').val(),
            selected_gateway : $('input[name="'+innGrid.featureSettings.selectedPaymentGateway+'_use_gateway"]').data('gateway_name')
        },
        success: function (data) { 
            console.log('expire ',data);
            if (data == "You don't have permission to access this functionality."){
                alert(data);
                $(that).prop("disabled", false);
                return;
            }
            
            if(data.success){
               window.location.reload();
            }
            // else if(data.expire)
            // {
            //     window.location.href = getBaseURL() + 'settings/integrations/payment_gateways';
            // }
            else
            {
                var error_html = "";
                // console.log(jQuery.isArray( data.message ));
                if(jQuery.isArray( data.message )){
                    $.each(data.message, function(i,v){
                        error_html += v.description+'\n';
                    });
                    console.log(error_html);
                    $('#display-errors').find('.modal-body').html(error_html.replace(/\n/g,'<br/>'));
                    $('#display-errors').modal('show');
                    // alert(error_html);
                } else {
                    alert(data.message ? data.message : data);
                }
                
                
                $(that).prop("disabled", false);
            }
        }
    });
});

// show "Use Payment Gateway" option 
$('.paid-by-customers').on('change', function(){
    var isGatewayAvailable = $(this).find('option:selected').attr('is-gateway-available');
    if(isGatewayAvailable == 'true'){
        $('.use-payment-gateway-btn').show();
        $('input[name="use_gateway"]').prop('checked', false);
        $('#cvc-field').remove();
        //$('select[name = "payment_type_id"]').attr('disabled');
        $checked = $('input[name="use_gateway"]').prop('checked');
        if($checked){
            $('select[name = "payment_type_id"]')
                    .append('<option id="gateway_option" value="gateway">'+cielo_selected_gateway+'</option>')
        }
    }
    else
    {
        $('.use-payment-gateway-btn').hide();
        $('select[name = "payment_type_id"]').removeAttr('disabled');
        $('#gateway_option').remove();
        $('input[name="use_gateway"]').prop('checked', 0);
    }
});
if( $('.paid-by-customers option:selected').attr('is-gateway-available') == 'true'){
    $('.use-payment-gateway-btn').show();
}

if(innGrid.isAsaasPaymentEnabled == 1 && $('.paid-by-customers option:selected').attr('is-gateway-available') == 'false'){
    var paymentLinkHtml =   '<div class="form-group">'+
                                '<label for="payment_amount" class="col-sm-4 control-label">'+
                                    l('cielo-payment-integration/Use Payment Gateway')+
                                '</label>'+
                                '<div class="col-sm-2">'+
                                    '<input type="checkbox" class="form-control use-gateway hoteli_payment_link" data-gateway_name="hoteli.pay" name="hoteli.pay_payment_link" type="payment_link">'+
                                    '<p style="margin: -38px 64px 0px;"><b>'+l('cielo-payment-integration/Payment Link')+'</b></p>'+
                                '</div>'+
                            '</div>';

    $(paymentLinkHtml).insertAfter('.payment_type_div');
}

if(cielo_selected_gateway != undefined){
    var paymentLinkHtml =   '<div class="col-sm-4"></div>'+
                            '<div class="col-sm-2">'+
                                '<input type="checkbox" class="form-control use-gateway hoteli_payment_link" data-gateway_name="'+cielo_selected_gateway.toLowerCase()+'" name="'+cielo_selected_gateway.toLowerCase()+'_payment_link" type="payment_link">'+
                                '<p style="margin: -37px 41px 0px;"><b>'+l('cielo-payment-integration/Payment Link')+'</b></p>'+
                            '</div>';

    $('#use-gateway-div').append(paymentLinkHtml);

    var installmentHtml =   '<div class="form-group hidden installment_html">'+
                                '<label for="payment_amount" class="col-sm-4 control-label">'+
                                    l('cielo-payment-integration/Pay in Instalments')+
                                '</label>'+
                                '<div class="col-sm-2">'+
                                    '<input type="checkbox" name="installment_charge" class="form-control installment_charge" data-gateway_name="hoteli.pay" name="hoteli.pay_installment_charge">'+
                                '</div>'+
                            '</div>';

    $(installmentHtml).insertAfter('.use-payment-gateway-btn');

    cielo_gateway_button.parent('div').append('<p style="margin: -37px 41px; width:90px;"><b>'+l('cielo-payment-integration/Credit or Debit Charge')+'</b></p>');
}

var gatewayTypes = {
        'cielo': 'Cielo'
    };

// var $methods_list = $('select[name="payment_type_id"]');
var cielo_payment_button = $('.hoteli_payment_link');
var cielo_payment = $('.hoteli_payment_link').data('gateway_name');

cielo_payment = gatewayTypes[cielo_payment];

$methods_list.prop('disabled', false);
cielo_payment_button.prop('checked',0);

cielo_payment_button.on('click',function(){
    $that = $(this);
    
    var checked = $that.prop('checked');
    $methods_list.prop('disabled', checked);
    var manualPaymentCapture = $("#manual_payment_capture").val();
    if(checked)
    {
        $methods_list
            .append(
            $('<option></option>',{
                id : 'gateway_option'
            })
                .val('gateway')
                .html(cielo_payment)
        );
        $methods_list.val('gateway');

        $(".add_payment_button").parent('.modal-footer').prepend(
                                                '<button type="button" class="btn btn-success send_payment_link" id="send_payment_link">'+
                                                    '<span alt="add" title="add">'+l('cielo-payment-integration/Send Payment Link')+'</span>'+
                                                '</button>'
                                            );
        $payment_link_div = '<div class="form-group payment_link_div">'+
                                '<label for="payment_link" class="col-sm-4 control-label">'+
                                    '<span alt="payment_link" title="amount">'+l('cielo-payment-integration/Payment link name')+'</span></label>'+
                                '<div class="col-sm-8">'+
                                    '<input type="text" class="form-control" name="payment_link_name" placeholder="'+l("cielo-payment-integration/Enter name of payment link")+'">'+
                                '</div>'+
                            '</div>'+
                            '<div class="form-group payment_link_div">'+
                                '<label for="due_date" class="col-sm-4 control-label">'+
                                    '<span alt="due_date" title="amount">'+l('cielo-payment-integration/Due date limit days')+'</span></label>'+
                                '<div class="col-sm-8">'+
                                    '<input type="text" class="form-control" name="due_date" placeholder="'+l("cielo-payment-integration/Enter days of due date")+'">'+
                                '</div>'+
                            '</div>';

        $($payment_link_div).insertAfter('.use-payment-gateway-btn');
        $("#add_payment_normal").addClass('hidden');
        $('.installment_html').addClass('hidden');
        $("#add_payment").addClass('hidden');
        
        var available_gateway = $('.paid-by-customers').children('option:selected').data('available-gateway');
        
    }else{

        $("#add_payment").removeClass('hidden');
        $("#add_payment_normal").removeClass('hidden');
        //$('.installment_html').addClass('hidden');
        $(".send_payment_link").remove();
        $(".payment_link_div").remove();
        $('#gateway_option').remove();
        $('#cvc-field').remove();
    }

});

$('.use-gateway').on('change', function() {
    $('.use-gateway').not(this).prop('checked', false);  
});


$(".send_payment_link").prop("disabled", false);

$("body").on("click", ".send_payment_link", function () {

    var payment_link_name = $("input[name='payment_link_name']").val();
    var payment_due_date = $("input[name='due_date']").val();

    if(payment_link_name == ''){
        alert(l('cielo-payment-integration/Please enter payment link name', true));
    } else if(payment_due_date == ''){
        alert(l('cielo-payment-integration/Please enter payment due days', true));
    } else if(payment_due_date <= 0){
        alert(l('cielo-payment-integration/Please enter valid due days', true));
    } else{

        if($('input[name="installment_charge"]').is(":checked") == true){
            var installment_charge = true;
            var installment_count = $('select[name="installment_count"]').val();
        }
        else {
            var installment_charge = false;
            var installment_count = 0;
        }

        $(this).html("Processing. . .");
        $(this).prop("disabled", true);
        
        $.ajax({
            url    : getBaseURL() + 'send_payment_link',
            method : 'post',
            dataType: 'json',
            data   : {
                payment_link_name: $("input[name='payment_link_name']").val(),
                due_date: $("input[name='due_date']").val(),
                payment_amount: $("input[name='payment_amount']").val(),
                booking_id      : $("#booking_id").val(),
                payment_date    : innGrid._getBaseFormattedDate($("input[name='payment_date']").val()),
                payment_type_id : $("select[name='payment_type_id']").val(),
                customer_id     : $("select[name='customer_id']").val(),
                payment_amount  : $("input[name='payment_amount']").val(),
                installment_charge  : installment_charge,
                installment_count  : installment_count
            },
            success: function (resp) { 
                console.log('resp',resp);
                if(resp.success){

                    modalContent = '<div class="modal fade" id="payment_link_modal">'+
                                        '<div class="modal-dialog" role="document">'+
                                            '<div class="modal-content">'+
                                                '<div class="modal-header">'+
                                                    '<h5 class="modal-title">'+l('cielo-payment-integration/Payment Link')+'</h5>'+
                                                    '<button type="button" class="close" data-dismiss="modal" aria-label="Close">'+
                                                      '<span aria-hidden="true">&times;</span>'+
                                                    '</button>'+
                                                '</div>'+
                                                '<div class="modal-body payment_link_message">'+
                                                    
                                                '</div>'+
                                                '<div class="modal-footer">'+
                                                    '<button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>'+
                                                    // '<button type="button" class="btn btn-primary">Save changes</button>'+
                                                '</div>'+
                                            '</div>'+
                                        '</div>'+
                                    '</div>';
                    $('body').append(modalContent);
                    $('.payment_link_message').append('<p>'+l('cielo-payment-integration/Payment Link has been created successfully. Please copy this URL to pay')+'</p>\n <b><p>'+resp.payment_link_url+'</p></b>');
                    $('#add-payment-modal').modal('hide');
                    $('#payment_link_modal').modal('show');

                    $('#payment_link_modal').on('hidden.bs.modal', function () {
                        location.reload();
                    });

                } else {
                    alert(resp.error);
                }
            }
        });
    }
});

$('body').on('click', '.verify_payment', function(){
    var payment_link_id = $(this).data('payment_link_id');
    var payment_id = $(this).data('payment_id');

    $(this).html("Processing. . .");
    $(this).prop("disabled", true);

    $.ajax({
            url    : getBaseURL() + 'verify_payment',
            method : 'post',
            dataType: 'json',
            data   : {
                payment_link_id : payment_link_id,
                customer_id : $("select[name='customer_id']").val(),
                payment_id : payment_id
            },
            success: function (resp) {
                console.log('resp',resp);
                if(resp.success){
                    // alert(resp.message);
                } else {
                    alert(resp.message);
                }

                location.reload();
            }
        });
});

$('body').on('click', '.delete-payment', function(){
    if(cielo_selected_gateway == 'Hoteli.Pay'){    
        setTimeout(function(){
            $("#partial-amount-div").parent('div').remove();
        }, 500);
    }
});


// installment charge

var cielo_install_button = $('.installment_charge');
var cielo_install = $('.installment_charge').data('gateway_name');

// cielo_install = gatewayTypes['hoteli.pay'];

$methods_list.prop('disabled', false);
cielo_install_button.prop('checked',0);

cielo_install_button.on('click',function(){
    $that = $(this);
    
    var checked = $that.prop('checked');
    // $methods_list.prop('disabled', checked);
    var manualPaymentCapture = $("#manual_payment_capture").val();
    if(checked)
    {
        // $(".add_payment_button").parent('.modal-footer').prepend(
        //                                         '<button type="button" class="btn btn-success add_installment_charge" id="add_installment_charge">'+
        //                                             '<span alt="add" title="add">'+l('cielo-payment-integration/Add Installment Charge')+'</span>'+
        //                                         '</button>'
        //                                     );

        $installment_charge_div =   '<div class="form-group installment_html installment_payment_div">'+
                                        '<label for="installment_charge" class="col-sm-4 control-label">'+
                                            '<span alt="installment_charge" title="amount">'+l('cielo-payment-integration/Number of Installments')+'</span>'+
                                        '</label>'+
                                        '<div class="col-sm-8">'+
                                            '<select class="form-control" name="installment_count">'+
                                                '<option value = "2"> 2 </option>'+
                                                '<option value = "3"> 3 </option>'+
                                                '<option value = "4"> 4 </option>'+
                                                '<option value = "5"> 5 </option>'+
                                                '<option value = "6" selected> 6 </option>'+
                                                '<option value = "7"> 7 </option>'+
                                                '<option value = "8"> 8 </option>'+
                                                '<option value = "9"> 9 </option>'+
                                                '<option value = "10"> 10 </option>'+
                                                '<option value = "11"> 11 </option>'+
                                                '<option value = "12"> 12 </option>'+
                                            '</select>'+
                                        '</div>'+
                                    '</div>';

        $($installment_charge_div).insertAfter('.installment_html');

        // $("#add_payment_normal").addClass('hidden');
        
        var available_gateway = $('.paid-by-customers').children('option:selected').data('available-gateway');
        
    }else{

        // $("#add_payment_normal").removeClass('hidden');
        // $(".send_payment_link").remove();
        $(".installment_payment_div").remove();
        // $('#gateway_option').remove();
        // $('#cvc-field').remove();
    }
    // $(".send_payment_link").remove();
});

$('.delete-payment-link-row').on("click", function (e) {
    deletePaymentRow($(this), e);
});

function deletePaymentRow(item, e){
    e.preventDefault();
    var tr = item.closest('tr');
    var paymentID = tr.attr('id');
    var paymentID = tr.attr('id');

    var image = getBaseURL() + "images/loading.gif";

    var message = l('cielo-payment-integration/Delete this payment permanently?', true);
    $.ajax({
            beforeSend: function (request) {
                if (!confirm(message))
                {
                    return false;
                } else {
                    tr.find('.payment_status_buttons').append("<img src='"+image+"' style='width: 22px;margin: 0px 4px;' />");
                }
            },
            type: "POST",
            url    : getBaseURL() + "delete_payment_row",
            data: "payment_id=" + paymentID,
            dataType: "json",
            success: function( data ) {             
                
                if (data.success) {

                    if(typeof(data.delete) != "undefined" && data.delete == false) {
                        alert(l("cielo-payment-integration/Can't delete the Payment Link as it's been already paid. Please refund instead"));
                    
                        $.ajax({
                            url    : getBaseURL() + 'verify_payment',
                            method : 'post',
                            dataType: 'json',
                            data   : {
                                payment_link_id : data.payment_link_id,
                                customer_id : $("#customer_id").val(),
                                payment_id : paymentID
                            },
                            success: function (resp) {
                                console.log('resp',resp);
                                if(resp.success){
                                    // alert(resp.message);
                                } else {
                                    alert(resp.message);
                                }

                                location.reload();
                            }
                        });                        

                    } else {
                        tr.remove();
                        innGrid.updateTotals();
                    }
                    
                } else {
                    alert(data.msg);
                }
            }
        });
}