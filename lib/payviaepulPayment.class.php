<?php

/**
 *
 * @author PaySys Ltd.
 * @name PayVia E-Pul
 * @description PayVia E-Pul payment module
 * @property-read string $EPUL_USERNAME
 * @property-read string $EPUL_PASSWORD
 *
 */
class payviaepulPayment extends waPayment implements waIPayment
{
    protected $register_transaction_url = 'https://www.e-pul.az/epay/pay_via_epul/register_transaction';
    protected $check_transaction_url = 'https://www.e-pul.az/epay/pay_via_epul/check_transaction';

    public function allowedCurrency()
    {
        return array(
            'AZN',
        );
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $order_data = waOrder::factory($order_data);

        if (!in_array($order_data->currency, $this->allowedCurrency())) {
            throw new waException($this->wp('EpulUnsupportedCurrency'));
        }

        $view = wa()->getView();

        $pattern = "@[^\\w\\d" . preg_quote("~@#$%^-_(){}'`+=[]:;/\\", '@') . "]+@u";
        $description = trim(preg_replace('@\\s{2,}@', ' ', preg_replace($pattern, ' ', $order_data->description)));
        $transactionId = 'TRID_' . $order_data['order_id'];
        $orderAmount = intval(round($order_data['amount'] * 100.0,0));

        self::log($this->id, array('payment' => 'Process TransactionID: ' . $transactionId . ' Amount: ' . $orderAmount));

        $options = array(
            'format' => waNet::FORMAT_RAW,
            'timeout' => 30,
        );
        $net = new waNet($options);
        $response_body = array();
        try {

            $pay_args = array(
                'username' => $this->EPUL_USERNAME, // E-PUL PayViaEpul Credentials and API Info
                'password' => $this->EPUL_PASSWORD,
                'description' => $description,
                'amount' => $orderAmount,
                'backUrl' => $this->getRelayUrl() . '?' . http_build_query(array(
                        'transaction_id' => $transactionId,
                        'wa_app' => $this->app_id,
                        'wa_merchant_contact_id' => $this->merchant_id)),
                'errorUrl' => $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, array('orderId', $transactionId)),
                'transactionId' => $transactionId
            );
            self::log($this->id, array('payment' => 'Request Params: ' . print_r($pay_args, TRUE)));
            $response_body = json_decode($net->query($this->register_transaction_url, $pay_args, waNet::METHOD_POST), true);
            self::log($this->id, array('payment' => 'Returned JSON: ' . print_r($response_body, TRUE)));
        } catch (waException $exp) {
            self::log($this->id, array('error' => $exp->getMessage()));
        }

        if (!empty($response_body['success']) && ($response_body['success'] == 'true') || ($response_body['success'] == '1')) {
            $chunks = explode('?', $response_body['forwardUrl']);
            parse_str($chunks[1], $hidden_fields);

            $view->assign('RedirectToEpulMsg', $this->wp('RedirectToEpul'));
            $view->assign('PayOnEpulMsg', $this->wp('PayOnEpul'));

            self::log($this->id, array('test' => $this->wp('RedirectToEpul') . ' ' + $this->wp('PayOnEpul')));


            $view->assign('hidden_fields', $hidden_fields);
            $view->assign('form_url', $chunks[0]);
            $view->assign('auto_submit', $auto_submit);

            return $view->fetch($this->path . '/templates/payment.html');
        } else {
            throw new waException($this->wp('EpulFailedCreateOrder'));
        }
    }

    protected function callbackInit($request)
    {
        self::log($this->id, array('callbackInit' => 'callback Init'));
        if (!empty($request['orderId'])) {
            $this->app_id = $request['wa_app'];
            $this->merchant_id = $request['wa_merchant_contact_id'];
            $transaction_id = $request['transaction_id'];
            $order_id = $request['orderId'];
            self::log($this->id, array('callbackInit' => "AppID: $this->app_id, MerchantID: $this->merchant_id, OrderID: $order_id, TransactionID: $transaction_id"));
        } else {
            self::log($this->id, array('error' => 'empty required field(s)'));
            throw new waPaymentException($this->wp('EpulEmptyRequiredFields'));
        }
        return parent::callbackInit($request);
    }

    protected function callbackHandler($request)
    {
        self::log($this->id, array('callbackHandler' => 'Request: ' . print_r($request, TRUE)));
        $transaction_data = $this->formalizeData($request);
        $transaction_result = null;

        $url = null;
        $app_payment_method = null;

        $options = array(
            'format' => waNet::FORMAT_RAW,
            'timeout' => 30,
        );
        $net = new waNet($options);
        $response_body = array();
        $transaction_id = '';
        try {
            $transaction_id = str_replace('TRID_', '', $request['transaction_id']);
            $pay_args = array(
                'username' => $this->EPUL_USERNAME, // E-PUL PayViaEpul Credentials and API Info
                'password' => $this->EPUL_PASSWORD,
                'orderId' =>  $request['orderId']
            );
            self::log($this->id, array('payment' => 'Request Params: ' . print_r($pay_args, TRUE)));
            $response_body = json_decode($net->query($this->check_transaction_url, $pay_args, waNet::METHOD_POST), true);
            self::log($this->id, array('payment' => 'Returned JSON: ' . print_r($response_body, TRUE)));
        } catch (waException $exp) {
            self::log($this->id, array('error' => $exp->getMessage()));
        }

        $url = null;
        if (!empty($response_body['success']) && ($response_body['success'] == 'true') || ($response_body['success'] == '1')) {
            $currencies = $this->allowedCurrency();
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction_data['state'] = self::STATE_CAPTURED;
            $transaction_data['order_id'] = $transaction_id;
            $transaction_data['native_id'] = $request['orderId'];
            $transaction_data['result'] = 'Success';
            $transaction_data['transaction_OK'] = true;
            $transaction_data['view_data'] = $request['orderId'];
            $transaction_data['currency_id'] = $currencies[0];
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
        } else {
            $app_payment_method = self::CALLBACK_DECLINE;
            $transaction_data['state'] = self::STATE_DECLINED;
            $transaction_data['result'] = 'Fail';
            $transaction_data['order_id'] = $transaction_id;
            $transaction_data['native_id'] = $request['orderId'];
            $transaction_data['transaction_OK'] = false;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
        }

        file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/data_epul.txt', "callbackHandler " . $transaction_data['state'] . "\n", FILE_APPEND);

        if ($app_payment_method) {
            $transaction_data = $this->saveTransaction($transaction_data, $request);
            $this->execAppCallback($app_payment_method, $transaction_data);
        }

        return array(
            'redirect' => $url,
        );
    }

    private function wp($msgid1)
    {
        $translated = _wp($msgid1);
        if ($translated == $msgid1) {
            // Native-huneytive functions doesn't work!
            $translated = $this->locale[wa()->getLocale()][$msgid1];
        }

        return $translated;
    }

    public function supportedOperations()
    {
        return array(
            self::TRANSACTION_CAPTURE,
            self::TRANSACTION_PAYMENT,
        );
    }

    private $locale = array(
        'en_US' => array(
            'RedirectToEpul'            => 'Now following to "E-PUL" site for payment',
            'PayOnEpul'                 => 'Pay via E-PUL',
            'PayViaEpulDesc'            => '<a href="https://www.e-pul.az">E-PUL</a> payment system',
            'EpulSuccessURL'            => 'URL for page "Return to shop", displayed after success payment',
            'EpulFailURL'               => 'URL for page "Return to shop", displayed after failed payment',
            'EpulUsername'              => 'ID of shop',
            'EpulUsernameDesc'          => 'Issued by the operator of the payment system',
            'EpulSecretKey'             => 'Secret key',
            'EpulFailedCreateOrder'     => 'Error: Unknown error. Can\'t create order',
            'EpulUnsupportedCurrency'   => 'Unsupported currency',
            'EpulEmptyRequiredFields'   => 'Empty required fields'
        ),
        'ru_RU' => array(
            'RedirectToEpul'            => 'Перенаправление на сайт «E-PUL» для оплаты',
            'PayOnEpul'                 => 'Оплатить заказ на сайте E-PUL',
            'PayViaEpulDesc'            => 'Платежная система <a href="https://www.e-pul.az">E-PUL</a>',
            'EpulSuccessURL'            => 'URL для кнопки «возврат в магазин» на странице, отображаемой покупателю после успешной оплаты',
            'EpulFailURL'               => 'URL для кнопки «возврат в магазин» на странице, отображаемой покупателю после неуспешной оплаты',
            'EpulUsername'              => 'Идентификатор магазина',
            'EpulUsernameDesc'          => 'Выдается оператором платежной системы',
            'EpulSecretKey'             => 'Секретный ключ',
            'EpulFailedCreateOrder'     => 'ОШИБКА: Неизвестная ошибка. Невозможно создать оплату',
            'EpulUnsupportedCurrency'   => 'Валюта не поддерживается',
            'EpulEmptyRequiredFields'   => 'Пустые нужные поля'
        )
    );
}