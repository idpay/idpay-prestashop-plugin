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
if (!defined('_PS_VERSION_'))
    exit;

class idpay extends PaymentModule
{

    private $_html = '';
    private $_postErrors = array();

    public function __construct()
    {

        $this->name = 'idpay';
        $this->tab = 'payments_gateways';
        $this->version = '2.0';
        $this->author = 'Developer: JMDMahdi, meysamrazmi, vispa, Publisher: IDPay';
        $this->currencies = true;
        $this->currencies_mode = 'radio';
        parent::__construct();
        $this->displayName = $this->l('IDPay Payment Module');
        $this->description = $this->l('Online Payment With IDPay');
        $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');
        if (!sizeof(Currency::checkPaymentCurrencies($this->id)))
            $this->warning = $this->l('No currency has been set for this module');
        $config = Configuration::getMultiple(array('idpay_api_key'));
        if (!isset($config['idpay_api_key']))
            $this->warning = $this->l('You have to enter your idpay token code to use idpay for your online payments.');

    }

    public function install()
    {
        if (!parent::install()
            || !Configuration::updateValue('idpay_success_massage', '')
            || !Configuration::updateValue('idpay_api_key', '')
            || !Configuration::updateValue('idpay_failed_massage', '')
            || !Configuration::updateValue('idpay_sandbox', '')
            || !Configuration::updateValue('idpay_currency', '')
            || !Configuration::updateValue('idpay_HASH_KEY', $this->hash_key())
            || !$this->registerHook('payment')
            || !$this->registerHook('paymentReturn')
            || !$this->registerHook('displayShoppingCartFooter')
            || !$this->addOrderState($this->l('Awaiting IDPay Payment')) )
            return false;
        else
            return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall()
            || !Configuration::deleteByName('idpay_api_key')
            || !Configuration::deleteByName('idpay_success_massage')
            || !Configuration::deleteByName('idpay_failed_massage')
            || !Configuration::deleteByName('idpay_sandbox')
            || !Configuration::deleteByName('idpay_currency')
            || !Configuration::deleteByName('idpay_HASH_KEY') )
            return false;
        else
            return true;
    }

    public function addOrderState($name)
    {
        $state_exist = false;
        $states = OrderState::getOrderStates((int)$this->context->language->id);

        // check if order state exist
        foreach ($states as $state) {
            if (in_array($this->name, $state)) {
                $state_exist = true;
                break;
            }
        }

        // If the state does not exist, we create it.
        if (!$state_exist) {
            // create new order state
            $order_state = new OrderState();
            $order_state->color = '#bbbbbb';
            $order_state->send_email = false;
            $order_state->module_name = $this->name;
            $order_state->template = '';
            $order_state->name = array();
            $languages = Language::getLanguages(false);
            foreach ($languages as $language)
                $order_state->name[ $language['id_lang'] ] = $name;

            // Update object
            $order_state->add();
        }

        return true;
    }

    public function hash_key()
    {
        $en = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z');
        $one = rand(1, 26);
        $two = rand(1, 26);
        $three = rand(1, 26);
        return $hash = $en[$one] . rand(0, 9) . rand(0, 9) . $en[$two] . $en[$three] . rand(0, 9) . rand(10, 99);
    }

    public function getContent()
    {
        if (Tools::isSubmit('idpay_submit')) {
            Configuration::updateValue('idpay_api_key', $_POST['idpay_api_key']);
            Configuration::updateValue('idpay_sandbox', $_POST['idpay_sandbox']);
            Configuration::updateValue('idpay_currency', $_POST['idpay_currency']);
            Configuration::updateValue('idpay_success_massage', $_POST['idpay_success_massage']);
            Configuration::updateValue('idpay_failed_massage', $_POST['idpay_failed_massage']);
            $this->_html .= '<div class="conf confirm">' . $this->l('Settings updated') . '</div>';
        }

        $this->_generateForm();
        return $this->_html;
    }

    private function _generateForm()
    {
        $this->_html .= '<div align="center"><form action="' . $_SERVER['REQUEST_URI'] . '" method="post">';
        $this->_html .= $this->l('API KEY :') . '<br><br>';
        $this->_html .= '<input type="text" name="idpay_api_key" value="' . Configuration::get('idpay_api_key') . '" ><br><br>';
        $this->_html .= $this->l('Sandbox :') . '<br><br>';
        $this->_html .= '<select name="idpay_sandbox"><option value="yes"' . (Configuration::get('idpay_sandbox') == "yes" ? 'selected="selected"' : "") . '>' . $this->l('Yes') . '</option><option value="no"' . (Configuration::get('idpay_sandbox') == "no" ? 'selected="selected"' : "") . '>' . $this->l('No') . '</option></select><br><br>';
        $this->_html .= $this->l('Currency :') . '<br><br>';
        $this->_html .= '<select name="idpay_currency"><option value="rial"' . (Configuration::get('idpay_currency') == "rial" ? 'selected="selected"' : "") . '>' . $this->l('Rial') . '</option><option value="toman"' . (Configuration::get('idpay_currency') == "toman" ? 'selected="selected"' : "") . '>' . $this->l('Toman') . '</option></select><br><br>';
        $this->_html .= $this->l('Success Massage :') . '<br><br>';
        $this->_html .= '<textarea dir="auto" name="idpay_success_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('idpay_success_massage')) ? Configuration::get('idpay_success_massage') : "پرداخت شما با موفقیت انجام شد. کد رهگیری: {track_id}") . '</textarea><br><br>';
        $this->_html .= 'متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری آیدی پی استفاده نمایید.<br><br>';
        $this->_html .= $this->l('Failed Massage :') . '<br><br>';
        $this->_html .= '<textarea dir="auto" name="idpay_failed_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('idpay_failed_massage')) ? Configuration::get('idpay_failed_massage') : "پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.") . '</textarea><br><br>';
        $this->_html .= 'متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری آیدی پی استفاده نمایید.<br><br>';
        $this->_html .= '<input type="submit" name="idpay_submit" value="' . $this->l('Save it!') . '" class="button">';
        $this->_html .= '</form><br></div>';
    }

    /**
     * @param \CartCore $cart
     */
    public function do_payment($cart)
    {
        $api_key = Configuration::get('idpay_api_key');
        $sandbox = Configuration::get('idpay_sandbox') == 'yes' ? 'true' : 'false';
        $amount = $cart->getOrderTotal();
        if (Configuration::get('idpay_currency') == "toman") {
            $amount *= 10;
        }

        // Customer information
        $details = $cart->getSummaryDetails();
        $delivery = $details['delivery'];
        $name = $delivery->firstname . ' ' . $delivery->lastname;
        $phone = $delivery->phone_mobile;

        if (empty($phone_mobile)) {
            $phone = $delivery->phone;
        }

        if ( empty($amount) || $amount < 1000 || $amount > 500000000 ) {
            echo $this->error( $this->l('amount should be greater than 1,000 Rials and smaller than 500,000,000 Rials.') );
        }

        $states = OrderState::getOrderStates((int)$this->context->language->id);
        $state_id = 1; //Awaiting check payment
        // check if order state exist
        foreach ($states as $state) {
            if ( in_array($this->name, $state) ) {
                $state_id = $state['id_order_state'];
                break;
            }
        }

        $this->validateOrder( $cart->id, $state_id, $amount, $this->displayName, '', array(), (int)$this->context->currency->id );
        $order_id = Order::getOrderByCartId((int)($cart->id));

        $desc = sprintf( $this->l('Payment for order: %s'), $order_id );
        $callback = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . 'modules/idpay/callback.php?do=callback&hash=' . md5($amount . $order_id . Configuration::get('idpay_HASH_KEY'));
        $mail = Context::getContext()->customer->email;

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
            $msg = sprintf( $this->l('Error: %s (code: %s)'), $result->error_message, $result->error_code);
            $this->saveOrder($msg, Configuration::get( 'PS_OS_ERROR' ), $order_id);
            $this->context->cookie->idpay_message = $msg;

            $checkout_type = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc' : 'order';
            Tools::redirect( "/index.php?controller=$checkout_type&submitReorder=&id_order=$order_id&idpay-message=$msg");
        } else {
            Tools::redirect($result->link);
            exit;
        }
    }

    /**
     * @param $message
     * @param $paymentStatus
     * @param $order_id
     * 13 for waiting ,8 for payment error and Configuration::get('PS_OS_PAYMENT') for payment is OK
     */
    public function saveOrder($message, $paymentStatus, $order_id, $transaction_id = 0)
    {
        $history = new OrderHistory();
        $history->id_order = (int)$order_id;
        $history->changeIdOrderState($paymentStatus, (int)($order_id)); //order status=4
        $history->addWithemail();

        $order_message = new Message();
        $order_message->message = $message;
        $order_message->id_order = (int)$order_id;
        $order_message->add();

        if( !$transaction_id )
            return;

        $sql = 'SELECT reference FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '"';
        $reference = Db::getInstance()->executes($sql);
        $reference = $reference[0]['reference'];

        $sql = ' UPDATE `' . _DB_PREFIX_ . 'order_payment` SET `transaction_id` = "' . $transaction_id . '" WHERE `order_reference` = "' . $reference . '"';
        $result = Db::getInstance()->Execute($sql);
    }

    public function error($str)
    {
        return '<div class="alert alert-danger error" dir="rtl" style="text-align: right;padding: 15px;">' . $str . '</div>';
    }

    public function success($str)
    {
        return '<div class="conf alert-success confirm" dir="rtl" style="text-align: right;padding: 15px;">' . $str . '</div>';
    }

    public function hookPayment($params)
    {
        global $smarty;
        $smarty->assign('name', $this->description);

        $output = '';
        if( !empty($_GET['idpay-message']) )
            $output .= $this->error( $_GET['idpay-message'] );

        if ($this->active)
            $output .= $this->display(__FILE__, 'idpay.tpl');

        return $output;
    }

    public function hookDisplayShoppingCartFooter(){
        global $cookie;
        $output = '';
        if( !empty($_GET['idpay-message']) ){
            $output .= $this->error( $_GET['idpay-message'] );
        }
        else if( !empty($cookie->idpay_message) ){
            $output .= $this->error( $cookie->idpay_message );
            $cookie->idpay_message = '';
        }
        else if( !empty($_SESSION['idpay-message']) ){
            $output .= $this->error( $_SESSION['idpay-message'] );
            $_SESSION['idpay-message'] = '';
        }

        return $output;
    }

    public function hookPaymentReturn($params)
    {
        global $smarty;
        $output = '';

        if( !empty($_GET['idpay-message']) ){
            $smarty->assign('message', $_GET['idpay-message']);
        }
        if ($this->active)
            $output .= $this->display(__FILE__, 'idpay-confirmation.tpl');

        return $output;
    }

    public function get_status($status_code){
        switch ($status_code) {
            case 1:
                return $this->l('پرداخت انجام نشده است');
                break;
            case 2:
                return $this->l('پرداخت ناموفق بوده است');
                break;
            case 3:
                return $this->l('خطا رخ داده است');
                break;
            case 4:
                return $this->l('بلوکه شده');
                break;
            case 5:
                return $this->l('برگشت به پرداخت کننده');
                break;
            case 6:
                return $this->l('برگشت خورده سیستمی');
                break;
            case 7:
                return $this->l('انصراف از پرداخت');
                break;
            case 8:
                return $this->l('به درگاه پرداخت منتقل شد');
                break;
            case 10:
                return $this->l('در انتظار تایید پرداخت');
                break;
            case 100:
                return $this->l('پرداخت تایید شده است');
                break;
            case 101:
                return $this->l('پرداخت قبلا تایید شده است');
                break;
            case 200:
                return $this->l('به دریافت کننده واریز شد');
                break;
            default :
                return $this->l('خطای ناشناخته');
                break;
        }
    }
}