<?php
/**
 * IDPay - A Sample Payment Module for PrestaShop 1.7
 *
 * Order Validation Controller
 *
 * @author Andresa Martins <contact@andresa.dev>
 * @license https://opensource.org/licenses/afl-3.0.php
 */

class IDPayValidationModuleFrontController extends ModuleFrontController
{
    /** @var array Controller errors */
    public $errors = [];

    /** @var array Controller warning notifications */
    public $warning = [];

    /** @var array Controller success notifications */
    public $success = [];

    /** @var array Controller info notifications */
    public $info = [];


    /** set notifications on SESSION */
    public function notification()
    {

        $notifications = json_encode([
            'error' => $this->errors,
            'warning' => $this->warning,
            'success' => $this->success,
            'info' => $this->info,
        ]);

        if (session_status() == PHP_SESSION_ACTIVE) {
            $_SESSION['notifications'] = $notifications;
        } elseif (session_status() == PHP_SESSION_NONE) {
            session_start();
            $_SESSION['notifications'] = $notifications;
        } else {
            setcookie('notifications', $notifications);
        }


    }

    /** Function For Payment And  Callback */
    public function postProcess()
    {
        $cart = $this->context->cart;
        $authorized = false;
        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);
        $mail = $customer->email;
        $moduleActive = $this->module->active;

        /** Verify if this module is enabled and if the cart has a valid customer, delivery address and invoice address */
        if (!$moduleActive || empty($cart->id_customer) || empty($cart->id_address_delivery) || empty($cart->id_address_invoice)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /** Verify if this payment module is authorized */
        foreach (Module::getPaymentModules() as $module) {
            $authorized = $module['name'] == 'idpay';
            if ($authorized) {
                break;
            }
        }

        if (!$authorized) {
            $this->errors[] = 'This payment method is not available.';
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
        }

        /** Check if this is a vlaid customer account */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /** check method for countinue payment Or callBack function */
        if (isset($_GET['do'])) {
            $this->callBack($customer);
        }

        $order_id = $cart->id;
        $api_key = Configuration::get('idpay_api_key');
        $sandbox = Configuration::get('idpay_sandbox') == 'yes' ? 'true' : 'false';
        $amount = (float)$cart->getOrderTotal(true, Cart::BOTH);
        if (Configuration::get('idpay_currency') == "toman") {
            $amount *= 10;
        }

        // Customer information
        $details = $cart->getSummaryDetails();
        $delivery = $details['delivery'];
        $name = $delivery->firstname . ' ' . $delivery->lastname;
        $phone = empty($delivery->phone_mobile) ? $delivery->phone : $delivery->phone_mobile;
        $desc = $Description = 'پرداخت سفارش شماره: ' . $order_id;
        $md5 = md5($amount . $order_id . Configuration::get('idpay_HASH_KEY'));
        // $callback = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? "https://" : "http://") . $_SERVER['SERVER_NAME'] . '/index.php?fc=module&module=idpay&controller=validation&id_lang=2&do=callback&hash=' . md5($amount . $order_id . Configuration::get('idpay_HASH_KEY'));
        $callback = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? "https://" : "http://")
            . $_SERVER['SERVER_NAME']
            . '/index.php?fc=module&module=idpay&controller=validation&id_lang=2&do=callback&hash='
            . $md5;

        if (empty($amount)) {
            $this->errors[] = $this->otherStatusMessages(404);
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');
        }

        $data = array(
            'order_id' => $order_id,
            'amount' => $amount,
            'name' => $name,
            'phone' => $phone,
            'mail' => $mail,
            'desc' => $desc,
            'callback' => $callback,
        );

        $ch = curl_init('https://api.idpay.ir/v1.1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));
        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
            $this->errors[] = $msg;
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');

        } else {
            $this->saveTransactionID($order_id, $result->id);
            Tools::redirect($result->link);
            exit;
        }

    }

    public function saveTransactionID($order_id, $transaction_id)
    {
        $sqlcart = 'SELECT checkout_session_data FROM `' . _DB_PREFIX_ . 'cart` WHERE id_cart  = "' . $order_id . '"';
        $cart = Db::getInstance()->getRow($sqlcart)['checkout_session_data'];
        $cart = json_decode($cart, true);
        $cart['idpayTransactionId'] = $transaction_id;
        $cart = json_encode($cart);
        $sql = 'UPDATE `' . _DB_PREFIX_ . 'cart` SET `checkout_session_data` = ' . "'" . $cart . "'" . ' WHERE `id_cart` = ' . $order_id;
        return Db::getInstance()->Execute($sql);
    }

    public static function sanitize($variable)
    {
        return trim(strip_tags($variable));
    }

    public static function isNotDoubleSpending($reference, $order_id, $transaction_id)
    {
        if ($reference->id == $order_id) {
            $sqlcart = 'SELECT checkout_session_data FROM `' . _DB_PREFIX_ . 'cart` WHERE id_cart  = "' . $order_id . '"';
            $cart = Db::getInstance()->getRow($sqlcart)['checkout_session_data'];
            $cart = json_decode($cart, true);
            return $cart['idpayTransactionId'] == $transaction_id;
        }
        return false;
    }

    public function callBack($customer)
    {
        $pid = IDPayValidationModuleFrontController::sanitize($_REQUEST['id']);
        $status = IDPayValidationModuleFrontController::sanitize($_REQUEST['status']);
        $track_id = IDPayValidationModuleFrontController::sanitize($_REQUEST['track_id']);
        $order_id = IDPayValidationModuleFrontController::sanitize($_REQUEST['order_id']);
        $order = new Order((int)$order_id);
        $cart = $this->context->cart;
        $amount = (float)$cart->getOrderTotal(true, Cart::BOTH);

        if (!empty($pid) && !empty($order_id) && !empty($status)) {

            if (Configuration::get('idpay_currency') == "toman") {
                $amount *= 10;
            }

            $md5 = md5($amount . $order_id . Configuration::get('idpay_HASH_KEY'));

            if ($md5 == IDPayValidationModuleFrontController::sanitize($_GET['hash'])) {

                if ($status == 10 && self::isNotDoubleSpending($cart, $order_id, $pid)) {

                    $api_key = Configuration::get('idpay_api_key');
                    $sandbox = Configuration::get('idpay_sandbox') == 'yes' ? 'true' : 'false';
                    $data = array(
                        'id' => $pid,
                        'order_id' => $order_id,
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'X-API-KEY:' . $api_key,
                        'X-SANDBOX:' . $sandbox,
                    ));
                    $result = curl_exec($ch);
                    $result = json_decode($result);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);


                    if ($http_status != 200) {
                        $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                        $this->errors[] = $msg;
                        $this->notification();
                        $this->saveOrderState($cart, $customer, 8, $msg);
                        Tools::redirect('index.php?controller=order-confirmation');

                    } else {
                        $verify_status = empty($result->status) ? NULL : $result->status;
                        $verify_track_id = empty($result->track_id) ? NULL : $result->track_id;
                        $verify_order_id = empty($result->order_id) ? NULL : $result->order_id;
                        $verify_amount = empty($result->amount) ? NULL : $result->amount;
                        $hashed_card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;
                        $card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;


                        if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount) || $verify_status < 100 || $verify_order_id !== $order_id) {

                            //generate msg and save to database as order
                            $msgForSaveDataTDataBase = $this->otherStatusMessages(1000) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                            $this->saveOrderState($cart, $customer, 8, $msgForSaveDataTDataBase);
                            $msg = $this->idpay_get_failed_message($verify_track_id, $verify_order_id, 1000);
                            $this->errors[] = $msg;
                            $this->notification();
                            Tools::redirect('index.php?controller=order-confirmation');

                        } else {
                            // not check dobule spending becuase not save order
                            if (Configuration::get('idpay_currency') == "toman") {
                                $amount /= 10;
                            }

                            $msgForSaveDataTDataBase = $this->otherStatusMessages($verify_status) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                            $this->saveOrderState($cart, $customer, Configuration::get('PS_OS_PAYMENT'), $msgForSaveDataTDataBase);
                            $this->success[] = $this->idpay_get_success_message($verify_track_id, $verify_order_id, $verify_status);
                            $this->notification();
                            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$order->id_cart . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
                        }
                    }
                } else {

                    $msgForSaveDataTDataBase = $this->otherStatusMessages($status) . "کد پیگیری :  $track_id " . "شماره سفارش :  $order_id  ";
                    $this->saveOrderState($cart, $customer, 8, $msgForSaveDataTDataBase);
                    $this->errors[] = $this->idpay_get_failed_message($track_id, $order_id, $status);
                    $this->notification();
                    Tools::redirect('index.php?controller=order-confirmation');

                }

            } else {

                $this->errors[] = $this->idpay_get_failed_message($track_id, $order_id, 405);
                $this->notification();
                $msgForSaveDataTDataBase = $this->otherStatusMessages(1000) . "کد پیگیری :  $track_id " . "شماره سفارش :  $order_id  ";
                $this->saveOrderState($cart, $customer, 8, $msgForSaveDataTDataBase);
                Tools::redirect('index.php?controller=order-confirmation');
            }
        } else {
            $this->errors[] = $this->otherStatusMessages(1000);
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');
        }
    }

    public function saveOrderState($cart, $customer, $state, $message)
    {

        return $this->module->validateOrder(
            (int)$cart->id,
            $state,
            (float)$cart->getOrderTotal(true, Cart::BOTH),
            $message,
            null,
            null,
            (int)$this->context->currency->id,
            false,
            $customer->secure_key
        );
    }

    function idpay_get_success_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('idpay_success_massage')) . "<br>" . $msg;
    }

    public function idpay_get_failed_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('idpay_failed_massage') . "<br>" . $msg);

    }

    public function otherStatusMessages($msgNumber = null)
    {

        switch ($msgNumber) {
            case "1":
                $msg = "پرداخت انجام نشده است";
                break;
            case "2":
                $msg = "پرداخت ناموفق بوده است";
                break;
            case "3":
                $msg = "خطا رخ داده است";
                break;
            case "4":
                $msg = "بلوکه شده";
                break;
            case "5":
                $msg = "برگشت به پرداخت کننده";
                break;
            case "6":
                $msg = "برگشت خورده سیستمی";
                break;
            case "7":
                $msg = "انصراف از پرداخت";
                break;
            case "8":
                $msg = "به درگاه پرداخت منتقل شد";
                break;
            case "10":
                $msg = "در انتظار تایید پرداخت";
                break;
            case "100":
                $msg = "پرداخت تایید شده است";
                break;
            case "101":
                $msg = "پرداخت قبلا تایید شده است";
                break;
            case "200":
                $msg = "به دریافت کننده واریز شد";
                break;
            case "0":
                $msg = "سواستفاده از تراکنش قبلی";
                break;
            case "404":
                $msg = "واحد پول انتخاب شده پشتیبانی نمی شود.";
                $msgNumber = '404';
                break;
            case "405":
                $msg = "کاربر از انجام تراکنش منصرف شده است.";
                $msgNumber = '404';
                break;
            case "1000":
                $msg = "خطا دور از انتظار";
                $msgNumber = '404';
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }

        return $msg . ' -وضعیت: ' . "$msgNumber";

    }


}
