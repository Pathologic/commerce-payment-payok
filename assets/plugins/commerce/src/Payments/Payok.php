<?php

namespace Commerce\Payments;

class Payok extends Payment
{
    protected $debug = false;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('payok');
        $this->debug = $this->getSetting('debug') == '1';
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('shop_id')) && empty($this->getSetting('secret_key'))) {
            return '<span class="error" style="color: red;">' . $this->lang['payok.error.empty_client_credentials'] . '</span>';
        }
    }

    public function getPaymentMarkup()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->getOrder();
        $currency = ci()->currency->getCurrency($order['currency']);
        $payment = $this->createPayment($order['id'], $order['amount']);
        $fields = [
            'shop'     => $this->getSetting('shop_id'),
            'payment'  => $order['id'] . '-' . $payment['id'],
            'amount'   => $payment['amount'],
            'currency' => $currency['code'],
            'desc'     => $this->lang['payok.order_description'] . ' ' . $order['id'],
            'email'    => $order['email']
        ];
        $fields['sign'] = $this->getSignature($fields);

        if ($this->debug) {
            $this->modx->logEvent(0, 1, '<pre>' . print_r($fields, true) . '</pre>',
                'Start Commerce Payok Payment');
        }

        $view = new \Commerce\Module\Renderer($this->modx, null, [
            'path' => 'assets/plugins/commerce/templates/front/',
        ]);

        return $view->render('payment_form.tpl', [
            'url'    => 'https://payok.io/pay',
            'method' => 'post',
            'data'   => $fields,
        ]);
    }

    public function handleCallback()
    {
        if ($this->debug) {
            $this->modx->logEvent(0, 1, '<pre>' . print_r($_POST, true) . '</pre>',
                'Process Callback Commerce Payok Payment');
        }
        if (!empty($_POST['payment_id']) && !empty($_POST['sign']) && $_POST['sign'] === $this->getSignature($_POST, 'callback')) {
            $order = explode('-', $_POST['payment_id']);
            $paymentId = $order[1];
            $processor = $this->modx->commerce->loadProcessor();
            try {
                $payment = $processor->loadPaymentById($paymentId);

                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($paymentId, true)) . '" . not found!');
                }

                return $processor->processPayment($payment['id'], $payment['amount']);
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(),
                    'Commerce Payok Payment');
                return false;
            }
        }

        return false;
    }

    protected function getSignature(array $data, $type = 'order')
    {
        if($type == 'callback') {
            $fields = ['amount', 'payment_id', 'shop', 'currency', 'desc'];
        } else {
            $fields = ['amount', 'payment', 'shop', 'currency', 'desc'];
        }

        $_data = [];
        foreach ($fields as $field) {
            if(!isset($data[$field]) || !is_scalar($data[$field])) continue;
            $_data[] = $data[$field];
        }
        $_data[] = $this->getSetting('secret_key');

        if($type == 'callback') {
            $_data = array_reverse($_data);
        }

        return md5(implode('|', $_data));
    }
}
