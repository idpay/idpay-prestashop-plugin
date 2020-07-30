<?php
/**
 * IDPay payment gateway
 *
 * @developer JMDMahdi, meysamrazmi, vispa
 * @publisher IDPay
 * @copyright (C) 2018-2020 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
@session_start();
if (isset($_GET['do'])) {
    include(dirname(__FILE__) . '/../../config/config.inc.php');
    include(dirname(__FILE__) . '/../../header.php');
    include_once(dirname(__FILE__) . '/idpay.php');
    global $cookie;

    $status   = empty( $_POST['status'] )   ? NULL : $_POST['status'];
    $track_id = empty( $_POST['track_id'] ) ? NULL : $_POST['track_id'];
    $pid      = empty( $_POST['id'] )       ? NULL : $_POST['id'];
    $orderid  = empty( $_POST['order_id'] ) ? NULL : $_POST['order_id'];

    $idpay = new idpay;
    if ($_GET['do'] == 'payment') {
        $idpay->do_payment($cart);
    }
    else if (!empty($pid) && !empty($orderid) && !empty($status) ) {
        $amount = $cart->getOrderTotal();
        if (Configuration::get('idpay_currency') == "toman") {
            $amount *= 10;
        }
        if ( md5($amount . $orderid . Configuration::get('idpay_HASH_KEY')) == $_GET['hash'] ) {
            if($status == 10) {
                $api_key = Configuration::get( 'idpay_api_key' );
                $sandbox = Configuration::get( 'idpay_sandbox' ) == 'yes' ? 'true' : 'false';

                $data = array(
                    'id'       => $pid,
                    'order_id' => $orderid,
                );

                $ch = curl_init();
                curl_setopt( $ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify' );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
                curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'X-API-KEY:' . $api_key,
                    'X-SANDBOX:' . $sandbox,
                ) );

                $result      = curl_exec( $ch );
                $result      = json_decode( $result );
                $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                curl_close( $ch );

                if ( $http_status != 200 ) {
                    echo $idpay->error( sprintf( $this->l('Error: %s (code: %s)'), $result->error_message, $result->error_code) );
                }
                else {
                    $verify_status   = empty( $result->status ) ? NULL : $result->status;
                    $verify_track_id = empty( $result->track_id ) ? NULL : $result->track_id;
                    $verify_order_id = empty( $result->order_id ) ? NULL : $result->order_id;
                    $verify_amount   = empty( $result->amount ) ? NULL : $result->amount;

                    if ( empty( $verify_status ) || empty( $verify_track_id ) || empty( $verify_amount ) || $verify_status < 100 || $verify_order_id !== $orderid ) {
                        echo $idpay->error( idpay_get_failed_message( $verify_track_id, $verify_order_id ) );
                    }
                    else {
                        error_reporting( E_ALL );

                        if ( Configuration::get( 'idpay_currency' ) == "toman" ){
                            $amount /= 10;
                        }

                        $message = idpay_get_success_massage( $verify_track_id, $verify_order_id );
                        $idpay->saveOrder( $message, Configuration::get( 'PS_OS_PAYMENT' ), (int)$verify_order_id, $verify_track_id);

                        $_SESSION['order' . $verify_order_id] = '';
                        Tools::redirect( 'index.php?controller=order-confirmation'.
                            '&id_cart=' . $cart->id .
                            '&id_module=' . $idpay->id .
                            '&id_order=' . $verify_order_id .
                            '&key=' . Context::getContext()->customer->secure_key .
                            '&idpay-message=' . $idpay->success($message)
                        );
                    }
                }
            } else {
                $message = sprintf( $this->l('Error: %s (code: %s)'), $idpay->get_status($status), $status) .'<br>'. idpay_get_failed_message( $track_id, $orderid );

                $idpay->saveOrder($message, Configuration::get( 'PS_OS_ERROR' ), (int)$orderid, $track_id);

                $_SESSION['order' . $orderid] = '';
                $cookie->idpay_message = $message;

                $checkout_type = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc' : 'order';
                Tools::redirect( "index.php?controller=$checkout_type&submitReorder=&id_order=$orderid");
            }
        } else {
            echo $idpay->error( $this->l('Wrong Input Parameters.') );
        }
    }
    else{
        echo $idpay->error( $this->l('No transaction found.') );
    }
    include_once(dirname(__FILE__) . '/../../footer.php');
} else {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit();
}

function idpay_get_failed_message($track_id, $order_id)
{
    return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('idpay_failed_massage'));
}

function idpay_get_success_massage($track_id, $order_id)
{
    return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('idpay_success_massage'));
}