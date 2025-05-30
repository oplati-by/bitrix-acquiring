<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\PaySystem\IRefund;
use Bitrix\Main\Diag\Debug;
use Bitrix\Sale\Order;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Loader;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\MeasureTable;

class OplatiHandler
    extends PaySystem\ServiceHandler implements IRefund
{
    const OPLATI_MODULE_NAME = 'oplati.paysystem';

    /**
     * @param  Payment  $payment
     * @param  Request|null  $request
     * @return PaySystem\ServiceResult
     */
    public function initiatePay(Payment $payment, Request $request = null): PaySystem\ServiceResult
    {
        $demandPaymentData = $this->GetDemandPayment($payment);
        $demandPaymentData['paymentLogoPath'] = $this->getPaymentLogoPath();
        $demandPaymentData['paymentLogoColors'] = $this->getPaymentLogoColor();

        $this->setExtraParams($demandPaymentData);
        $template = $this->getTypePayment() == 'auto' ? "template_redirect" : ($this->isMobile(
        ) ? "template_mobile" : "template");

        return $this->showTemplate($payment, $template);
    }


    /**
     * @param  Payment  $payment
     * @param  int  $refundableSum
     * @return PaySystem\ServiceResult
     */
    public function refund(Payment $payment, $refundableSum)
    {
        $result = new PaySystem\ServiceResult();

        $response = $this->sendRefundRequest($payment, $refundableSum);

        if ($response && $response['status'] === 1) {
            $result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
        }

        return $result;
    }

    /**
     * @return array
     */
    private function sendRefundRequest(Payment $payment, $refundableSum)
    {
        $data = $this->getDetailInfo($payment);
        $data['sum'] = $refundableSum;

        $response = $payment->getField('PS_INVOICE_ID') ? $this->SendPostRequest(
            'payments/'.$payment->getField('PS_INVOICE_ID').'/reversals',
            $data,
        ) : [];

        return $response;
    }

    /**
     * @param  PaySystem\ServiceResult  $result
     * @param  Request  $request
     * @return mixed
     */
    public function sendResponse(PaySystem\ServiceResult $result, Request $request)
    {
        $data = $result->getData();

        echo json_encode($data);
    }

    /**
     * @param  Request  $request
     * @return mixed
     */
    public function getPaymentIdFromRequest(Request $request)
    {
        return $request->get('paymentId');
    }

    private function processDemandPaymentAction(Payment $payment, Request $request)
    {
        $result = new PaySystem\ServiceResult();

        if (!$payment->isPaid()) {
            $response = $this->GetDemandPayment($payment);
            $result->setData($response);
        } else {
            $result->setData(['isPaid' => true]);
        }

        return $result;
    }

    private function GetDemandPayment(Payment $payment)
    {
        $data = $this->getDetailInfo($payment);

        $response = $this->SendPostRequest('webPayments/v2', $data);

        if ($response) {
            $this->SetOplatiPaymentId($payment, $response['paymentId']);
        }

        return $response;
    }

    private function GetMeasureName($productId)
    {
        \Bitrix\Main\Loader::includeModule('catalog');

        $measureName = "pc";

        $productData = ProductTable::getRow([
            'filter' => ['=ID' => $productId],
            'select' => ['MEASURE'],
        ]);

        if ($productData && $productData['MEASURE']) {
            $measureData = MeasureTable::getRow([
                'filter' => ['=ID' => $productData['MEASURE']],
                'select' => ['MEASURE_TITLE', 'SYMBOL_INTL', 'CODE'],
            ]);

            if ($measureData && $measureData['SYMBOL_INTL']) {
                $symbol = \CCatalogMeasureClassifier::getMeasureTitle($measureData["CODE"], 'SYMBOL_RUS');

                if ($symbol) {
                    $measureName = $symbol;
                } else {
                    $measureName = $measureData['SYMBOL_INTL'];
                }
            } elseif ($measureData && $measureData['MEASURE_TITLE']) {
                $measureName = $measureData['MEASURE_TITLE'];
            }
        }

        return $measureName;
    }

    private function SetOplatiPaymentId($payment, $paymentId): void
    {
        $paymentId = $paymentId ?: '';

        $payment->setField('PS_INVOICE_ID', $paymentId);
        $order = $payment->getCollection()->getOrder();
        $result = $order->save();
        if (!$result->isSuccess()) {
            $errors = $result->getErrorMessages();
        }
    }


    private function getPaymentLogoPath(): string
    {
        $realPath = realpath(__DIR__.'/template/img/'.Option::get(self::OPLATI_MODULE_NAME, 'oplati_logo_type'));

        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT']);

        if (
            !$realPath || strpos($realPath, $documentRoot) !== 0 || !Option::get(
                self::OPLATI_MODULE_NAME,
                'oplati_logo_type',
            )
        ) {
            return '';
        }

        $relativePath = str_replace('\\', '/', str_replace($documentRoot, '', $realPath));

        return $this->GetSiteUrl().$relativePath;
    }

    private function getPaymentLogoColor()
    {
        $paymentLogoColors = [
            'paymentLogoColor' => '',
            'paymentLogoHoverColor' => '',
        ];
        $logoType = Option::get(self::OPLATI_MODULE_NAME, 'oplati_logo_type');

        switch ($logoType) {
            case 'logo_Oplati_black.png':
                $paymentLogoColors = [
                    'paymentLogoColor' => "#DDDDDD",
                    'paymentLogoHoverColor' => '#B3B3B3',
                ];
                break;
            case 'logo_Oplati_white.png':
                $paymentLogoColors = [
                    'paymentLogoColor' => '#B3B3B3',
                    'paymentLogoHoverColor' => "#DDDDDD",
                ];
                break;
        }

        return $paymentLogoColors;
    }

    private function GetSiteUrl()
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $host = preg_replace('/:\d+$/', '', $host);

        return $scheme.'://'.$host;
    }

    private function GetNotificationUrl($paymentId)
    {
        return $this->GetSiteUrl(
            ).'/bitrix/tools/sale_ps_result.php'.'?action=updateStatusNotification'.'&BX_HANDLER=OPLATI'.'&paymentId='.$paymentId;
    }

    private function processUpdateStatusNotification(Payment $payment, Request $request)
    {
        $result = new PaySystem\ServiceResult();

        $data = ['success' => false];

        $isValidNotification = $this->IsValidNotificationSign($request);

        if ($isValidNotification) {
            $dataRequest = $request->getJsonList();
            $requestPaymentId = $request->get('paymentId');
            if (
                $requestPaymentId == $payment->getId()
                && $payment->getOrderId() == $dataRequest->get('orderNumber')
                && !$payment->isPaid()
            ) {
                $psData = [
                    'EMP_PAID_ID' => 1,
                    'PS_STATUS' => 'N',
                    'PS_STATUS_CODE' => $dataRequest->get('status'),
                    'PS_STATUS_DESCRIPTION' => $this->getStatusDescription($dataRequest->get('status')),
                    'PS_CURRENCY' => 'BYN',
                    'PS_SUM' => $dataRequest->get('sum'),
                    'PS_RESPONSE_DATE' => new DateTime(),
                    'PS_INVOICE_ID' => $dataRequest->get('paymentId'),
                ];


                if ($dataRequest->get('status') == 1) {
                    $result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
                    $data['success'] = true;
                    $psData['PS_STATUS'] = 'Y';
                } else {
                    $result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
                }

                $result->setPsData($psData);
                $result->setData($data);
            }
        }

        return $result;
    }

    /**
     * @param  Payment  $payment
     * @param  Request  $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request): PaySystem\ServiceResult
    {
        $action = $request->get('action');

        $map = [
            'demandPayment' => 'processDemandPaymentAction',
            'updateStatusNotification' => 'processUpdateStatusNotification',
            'consumerStatus' => 'processConsumerStatusAction',
            'paymentStatus' => 'processPaymentStatusAction',
        ];

        return isset($map[$action])
            ? $this->{$map[$action]}($payment, $request)
            : new PaySystem\ServiceResult();
    }

    private function IsValidNotificationSign(Request $request)
    {
        $headers = getallheaders();

        $serverSign = $headers['Server-Sign'] ?? null;
        $key = $this->getPublicKey();
        if (!$serverSign || !$key) {
            return false;
        }

        $publicKeyPem = "-----BEGIN PUBLIC KEY-----\n".
            chunk_split($key, 64, "\n").
            "-----END PUBLIC KEY-----\n";

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        $signature = base64_decode($serverSign);
        $rawBody = file_get_contents('php://input');
        $ok = openssl_verify($rawBody, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($ok === 1) {
            return true;
        } else {
            return false;
        }
    }


    private function processPaymentStatusAction(Payment $payment, Request $request)
    {
        $result = new PaySystem\ServiceResult();

        $response = $this->sendCheckStatusRequest($payment);

        $data = ['success' => false];

        if ($response) {
            $psData = [
                'EMP_PAID_ID' => 1,
                'PS_STATUS' => 'N',
                'PS_STATUS_CODE' => $response['status'],
                'PS_STATUS_DESCRIPTION' => $this->getStatusDescription($response['status']),
                'PS_CURRENCY' => 'BYN',
                'PS_SUM' => $response['sum'],
                'PS_RESPONSE_DATE' => new DateTime(),
                'PS_INVOICE_ID' => $response['paymentId'],
            ];

            if ($response['status'] === 1) {
                $result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
                $data['success'] = true;
                $psData['PS_STATUS'] = 'Y';
            } else {
                $result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
            }

            $result->setPsData($psData);
            $result->setData($data);
        }

        return $result;
    }


    private function sendCheckStatusRequest(Payment $payment)
    {
        return $payment->getField('PS_INVOICE_ID') ? $this->SendGetRequest(
            'payments',
            $payment->getField('PS_INVOICE_ID'),
        ) : null;
    }

    private function processConsumerStatusAction(Payment $payment, Request $request)
    {
        $result = new PaySystem\ServiceResult();

        $result = $this->processPaymentStatusAction($payment, $request);
        $psData = [$result->getPsData()];
        $statusCode = 'load';
        $psStatusCode = $psData[0]['PS_STATUS_CODE'];

        if ($psStatusCode == 1) {
            $statusCode = 'success';
        } elseif (isset($psData) && $psStatusCode !== 0) {
            $statusCode = 'failed';
        }

        $result->setData(['statusCode' => $statusCode]);

        return $result;
    }

    private function getStatusDescription($code)
    {
        $description = [
            0 => 'Платеж ожидает подтверждения',
            1 => 'Платеж совершен',
            2 => 'Отказ от платежа',
            3 => 'Недостаточно средств',
            4 => 'Клиент не подтвердил платеж',
            5 => 'Операция была отменена системой',
        ];

        return $description[$code];
    }


    /**
     * Информация для чека
     * @return array
     */
    private function getDetailInfo(Payment $payment, Request $request = null)
    {
        $orderId = $payment->getOrderId();
        Loader::includeModule("sale");
        Loader::includeModule("catalog");
        Loader::includeModule("iblock");

        $order = Order::load($orderId);

        if (!$order) {
            return [];
        }

        $basket = $order->getBasket();
        $items = [];

        foreach ($basket as $basketItem) {
            $productId = $basketItem->getProductId();
            $items[] = [
                "type" => 1,
                "name" => $basketItem->getField("NAME"),
                "quantity" => (float)$basketItem->getQuantity(),
                "unit" => $this->GetMeasureName($productId),
                "price" => round((float)$basketItem->getPrice(), 2),
                "cost" => round((float)$basketItem->getQuantity() * (float)$basketItem->getPrice(), 2),
            ];
        }

        $shipmentPrice = $order->getShipmentCollection()->getPriceDelivery();

        if ($shipmentPrice) {
            $items[] = [
                'type' => 2,
                'name' => 'Доставка',
                'price' => $shipmentPrice,
                'cost' => $shipmentPrice,
            ];
        }

        $data = [
            "shift" => date("dmY"),
            "sum" => round($order->getPrice(), 2),
            "orderNumber" => $orderId,
            "details" => [
                "receiptNumber" => (string)$payment->getId(),
                "regNum" => $this->getRegNum(),
                "items" => $items,
                "amountTotal" => round($order->getPrice(), 2),
            ],
            "successUrl" => $this->GetSiteUrl().'/bitrix/tools/sale_ps_success.php',
            "failureUrl" => $this->GetSiteUrl().'/bitrix/tools/sale_ps_fail.php',
            "notificationUrl" => $this->GetNotificationUrl($payment->getId()),
        ];

        return $data;
    }

    /**
     * @return array
     */
    public static function getIndicativeFields()
    {
        return ['BX_HANDLER' => 'OPLATI'];
    }

    /**
     * @return string
     */
    private function getPassword()
    {
        return Option::get('oplati.paysystem', 'password');
    }

    /**
     * @return string
     */
    private function getRegNum()
    {
        return Option::get('oplati.paysystem', 'regnum');
    }


    /**
     * @return string
     */
    private function getPublicKey()
    {
        return Option::get('oplati.paysystem', 'publicKey');
    }


    /**
     * @return string
     */
    private function getRequestUrl()
    {
        return Option::get('oplati.paysystem', 'requestUrl');
    }

    /**
     * @return string
     */
    private function getRequestMethod()
    {
        return Option::get('oplati.paysystem', 'requestMethod');
    }

    /**
     * @return string
     */
    private function getTypePayment()
    {
        return Option::get('oplati.paysystem', 'typePay');
    }


    /**
     * @return array
     */
    private function getHeadersRequest()
    {
        return [
            'regNum' => $this->getRegNum(),
            'password' => $this->getPassword(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @return bool
     */
    private function isMobile()
    {
        return preg_match(
            "/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i",
            $_SERVER["HTTP_USER_AGENT"],
        );
    }


    /**
     * @return array
     */
    public function getCurrencyList()
    {
        return ['BYN'];
    }

    /**
     * @return bool
     */
    public function isTuned()
    {
        return !empty($this->getPassword()) && !empty($this->getRegNum());
    }

    private function SendPostRequest($method, $requestData)
    {
        $requestMethod = $this->getRequestMethod();
        $methods = [
            'http' => [$this, 'SendPostHttpRequest'],
            'curl' => [$this, 'SendPostCurlRequest'],
        ];

        if (!isset($methods[$requestMethod])) {
            return [];
        }

        return call_user_func($methods[$requestMethod], $method, $requestData);
    }

    private function SendPostHttpRequest($method, $requestData)
    {
        $httpClient = new HttpClient();
        $headers = $this->getHeadersRequest();
        foreach ($headers as $name => $value) {
            $httpClient->setHeader($name, $value);
        }
        $response = $httpClient->post(
            $this->getRequestUrl().'/ms-pay/pos/'.$method,
            json_encode($requestData),
        );

        $this->log([
            'date' => date("Y-m-d H:i:s"),
            'url' => $this->getRequestUrl().'/ms-pay/pos/'.$method,
            'headers' => $headers,
            'type' => 'Post BitrixHttpClient',
            'methodRequest' => $method,
            'params' => [
                'options' => $requestData,
                'response' => $response,
            ],
        ]);

        return $httpClient->getStatus() == 200 ? json_decode($response, true) : [];
    }

    private function SendPostCurlRequest($method, $requestData)
    {
        $curl = curl_init();
        $headers = $this->getHeadersRequest();

        $method = 'webPayments/v2';
        $url = $this->getRequestUrl().'/ms-pay/pos/'.$method;
        $data = json_encode($requestData);

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name.': '.$value;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $formattedHeaders,
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \Exception("cURL Error: $error");
        }

        curl_close($curl);

        $this->log([
            'date' => date("Y-m-d H:i:s"),
            'url' => $this->getRequestUrl().'/ms-pay/pos/'.$method,
            'headers' => $headers,
            'type' => 'Post Curl',
            'methodRequest' => $method,
            'params' => [
                'options' => $requestData,
                'response' => $response,
            ],
        ]);

        return $httpCode == 200 && $response ? json_decode($response, true) : [];
    }

    private function SendGetRequest($method, $requestString)
    {
        $requestMethod = $this->getRequestMethod();
        $methods = [
            'http' => [$this, 'SendHttpGetRequest'],
            'curl' => [$this, 'SendCurlGetRequest'],
        ];

        if (!isset($methods[$requestMethod])) {
            return [];
        }

        return call_user_func($methods[$requestMethod], $method, $requestString);
    }

    private function SendHttpGetRequest($method, $requestString)
    {
        $httpClient = new HttpClient();
        $headers = $this->getHeadersRequest();
        foreach ($headers as $name => $value) {
            $httpClient->setHeader($name, $value);
        }
        $response = $httpClient->get(
            $this->getRequestUrl().'/ms-pay/pos/'.$method.'/'.$requestString,
        );

        $this->log([
            'date' => date("Y-m-d H:i:s"),
            'url' => $this->getRequestUrl().'/ms-pay/pos/'.$method.'/'.$requestString,
            'headers' => $headers,
            'type' => 'Get BitrixHttpClient',
            'methodRequest' => $method,
            'params' => [
                'options' => $requestString,
                'response' => $response,
            ],
        ]);

        return $httpClient->getStatus() == 200 ? json_decode($response, true) : [];
    }


    private function SendCurlGetRequest($method, $requestString)
    {
        $curl = curl_init();
        $headers = $this->getHeadersRequest();
        $requestString = urlencode($requestString);

        $url = $this->getRequestUrl().'/ms-pay/pos/'.$method.'/'.$requestString;

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name.': '.$value;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $formattedHeaders,
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \Exception("cURL Error: $error");
        }

        curl_close($curl);

        $this->log([
            'date' => date("Y-m-d H:i:s"),
            'url' => $this->getRequestUrl().'/ms-pay/pos/'.$method.'/'.$requestString,
            'headers' => $headers,
            'type' => 'Get Curl',
            'methodRequest' => $method,
            'params' => [
                'options' => $requestString,
                'response' => $response,
            ],
        ]);

        return $httpCode == 200 && $response ? json_decode($response, true) : [];
    }


    private function log($data)
    {
        if (Option::get('oplati.paysystem', 'set_logging') == "Y") {
            return file_put_contents(
                __DIR__.'/oplati-'.date('d-m-Y-H').'-log.json',
                json_encode($data, JSON_PRETTY_PRINT, JSON_UNESCAPED_UNICODE)."\n-------------\n\n",
                FILE_APPEND,
            );
        } else {
            return false;
        }
    }
}
