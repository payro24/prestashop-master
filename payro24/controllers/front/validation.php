<?php
/**
 * payro24 - A Sample Payment Module for PrestaShop 1.7
 *
 * Order Validation Controller
 *
 * @author Andresa Martins <contact@andresa.dev>
 * @license https://opensource.org/licenses/afl-3.0.php
 */

class payro24ValidationModuleFrontController extends ModuleFrontController
{

    /** @var array Controller errors */
    public $errors = [];

    /** @var array Controller warning notifications */
    public $warning = [];

    /** @var array Controller success notifications */
    public $success = [];

    /** @var array Controller info notifications */
    public $info = [];


    /**
     * set notifications on SESSION
     */
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


    /**
     * register order and request to api
     */
    public function postProcess()
    {


        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;
        $authorized = false;

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);


        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'payro24') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            $this->errors[] = 'This payment method is not available.';
            $this->notification();
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
        }

        /**
         * Check if this is a vlaid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        //call callBack function
        if (isset($_GET['do'])) {
            $this->callBack($customer);
        }


        $this->module->validateOrder(
            (int)$this->context->cart->id,
            13,
            (float)$this->context->cart->getOrderTotal(true, Cart::BOTH),
            "payro24",
            null,
            null,
            (int)$this->context->currency->id,
            false,
            $customer->secure_key
        );


        //get order id
        $sql = ' SELECT  `id_order`  FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = "' . $cart->id . '"';
        $order_id = Db::getInstance()->executeS($sql);
        $order_id = $order_id[0]['id_order'];


        $api_key = Configuration::get('payro24_api_key');
        $sandbox = Configuration::get('payro24_sandbox') == 'yes' ? 'true' : 'false';
        $amount = $cart->getOrderTotal();
        if (Configuration::get('payro24_currency') == "toman") {
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

        // There is not any email field in the cart details.
        // So we gather the customer email from this line of code:
        $mail = Context::getContext()->customer->email;


        $desc = $Description = 'پرداخت سفارش شماره: ' . $order_id;
        $url = $this->context->link->getModuleLink('payro24', 'validation', array(), true);
        $callback = $url . '&do=callback&hash=' . md5($amount . $order_id . Configuration::get('payro24_HASH_KEY'));


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


        $ch = curl_init('https://api.payro24.ir/v1.1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'P-TOKEN:' . $api_key,
            'P-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $msg = [
            'payro24_id' => $result->id,
            'msg' => "در انتظار پرداخت...",
        ];
        $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . 13 . '", `payment` = ' . "'" . $msg . "'" . ' WHERE `id_order` = "' . $order_id . '"';
        Db::getInstance()->Execute($sql);


        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            $msg=sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
            $this->errors[] =$msg;
            $this->notification();
            $this->saveOrder($msg, 8, $order_id);
            Tools::redirect('index.php?controller=order-confirmation');

        } else {
            Tools::redirect($result->link);
            exit;
        }

    }


    /**
     * @param $customer
     */
    public function callBack($customer)
    {

        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            $order_id = $_GET['order_id'];
            $order = new Order((int)$order_id);
            $pid = $_GET['id'];
            $status = $_GET['status'];
            $track_id = $_GET['track_id'];
            $amount = (float)$order->total_paid_tax_incl;
        }
        elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $order_id = $_POST['order_id'];
            $order = new Order((int)$order_id);
            $pid = $_POST['id'];
            $status = $_POST['status'];
            $track_id = $_POST['track_id'];
            $amount = (float)$order->total_paid_tax_incl;
        }

        if (!empty($pid) && !empty($order_id) && !empty($status)) {

            if (Configuration::get('payro24_currency') == "toman") {
                $amount *= 10;
            }

            if (!empty($pid) && !empty($order_id) && md5($amount . $order->id . Configuration::get('payro24_HASH_KEY')) == $_GET['hash']) {

                if ($status == 10) {

                    $api_key = Configuration::get('payro24_api_key');
                    $sandbox = Configuration::get('payro24_sandbox') == 'yes' ? 'true' : 'false';
                    $data = array(
                        'id' => $pid,
                        'order_id' => $order_id,
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://api.payro24.ir/v1.1/payment/verify');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'P-TOKEN:' . $api_key,
                        'P-SANDBOX:' . $sandbox,
                    ));

                    $result = curl_exec($ch);
                    $result = json_decode($result);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);


                    if ($http_status != 200) {
                        $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                        $this->errors[] = $msg;
                        $this->notification();
                        $this->saveOrder($msg, 8, $order_id);
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
                            $this->saveOrder($msgForSaveDataTDataBase, 8, $order_id);
                            $msg = $this->payro24_get_failed_message($verify_track_id, $verify_order_id, 1000);
                            $this->errors[] = $msg;
                            $this->notification();
                            Tools::redirect('index.php?controller=order-confirmation');

                        } else {


                            //check double spending
                            $sql = 'SELECT JSON_EXTRACT(payment, "$.payro24_id") FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '" AND JSON_EXTRACT(payment, "$.payro24_id")   = "' . $result->id . '"';
                            $exist = Db::getInstance()->executes($sql);
                            if ($verify_order_id !== $order_id or count($exist) == 0) {

                                $msgForSaveDataTDataBase = $this->otherStatusMessages(0) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                                $this->saveOrder($msgForSaveDataTDataBase, 8, $order_id);
                                $msg = $this->payro24_get_failed_message($verify_track_id, $verify_order_id, 0);
                                $this->errors[] = $msg;
                                $this->notification();
                                Tools::redirect('index.php?controller=order-confirmation');
                            }


                            if (Configuration::get('payro24_currency') == "toman") {
                                $amount /= 10;
                            }

                            $msgForSaveDataTDataBase = $this->otherStatusMessages($verify_status) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                            $this->saveOrder($msgForSaveDataTDataBase,Configuration::get('PS_OS_PAYMENT'),$order_id);

                            $this->success[] = $this->payro24_get_success_message($verify_track_id, $verify_order_id, $verify_status);
                            $this->notification();
                            /**
                             * Redirect the customer to the order confirmation page
                             */
                            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$order->id_cart . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);


                        }
                    }
                } else {

                    $msgForSaveDataTDataBase = $this->otherStatusMessages($status) . "کد پیگیری :  $track_id " . "شماره سفارش :  $order_id  ";
                    $this->saveOrder($msgForSaveDataTDataBase, 8, $order_id);

                    $this->errors[] = $this->payro24_get_failed_message($track_id, $order_id, $status);
                    $this->notification();
                    /**
                     * Redirect the customer to the order confirmation page
                     */
                    Tools::redirect('index.php?controller=order-confirmation');

                }

            } else {

                $this->errors[] = $this->payro24_get_failed_message($track_id, $order_id, 405);
                $this->notification();
                $msgForSaveDataTDataBase = $this->otherStatusMessages(1000) . "کد پیگیری :  $track_id " . "شماره سفارش :  $order_id  ";
                $this->saveOrder($msgForSaveDataTDataBase, 8, $order_id);
                Tools::redirect('index.php?controller=order-confirmation');
            }


        } else {

            $this->errors[] = $this->otherStatusMessages(1000);
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');

        }


    }

    /**
     * @param $msgForSaveDataTDataBase
     * @param $paymentStatus
     * @param $order_id
     * 13 for waiting ,8 for payment error and Configuration::get('PS_OS_PAYMENT') for payment is OK
     */
    public function saveOrder($msgForSaveDataTDataBase, $paymentStatus, $order_id)
    {

        $sql = 'SELECT payment FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '"';
        $payment = Db::getInstance()->executes($sql);

        $payment = json_decode($payment[0]['payment'], true);
        $payment['msg'] = $msgForSaveDataTDataBase;
        $data = json_encode($payment, JSON_UNESCAPED_UNICODE);
        $sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . $paymentStatus .
            '", `payment` = ' . "'" . $data . "'" .
            ' WHERE `id_order` = "' . $order_id . '"';

        Db::getInstance()->Execute($sql);
    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return string
     */
    function payro24_get_success_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('payro24_success_massage')) . "<br>" . $msg;
    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return mixed
     */
    public function payro24_get_failed_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('payro24_failed_massage') . "<br>" . $msg);

    }

    /**
     * @param null $msgNumber
     * @return string
     */
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
