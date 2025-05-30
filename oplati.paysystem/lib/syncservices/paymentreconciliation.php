<?php

namespace Oplati\Paysystem\SyncServices;


use Bitrix\Main\Config\Option;
use Bitrix\Main\Mail\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\PaymentCollection;
use Bitrix\Sale\PaySystem\Manager;
use Bitrix\Main\Web\HttpClient;

class PaymentReconciliation
{
    const EVENT_NAME = 'OPLATI_RECONCILIATION';
    private $url;
    private $shift;
    private $payments = [];
    private $response = [];
    private $paySystems = [];
    private $orderIds = [];
    private $emails = [];

    private $messageText = '';

    public function __construct($dateShift)
    {
        $this->includeModules();

        $this->shift = $dateShift;
        $queryDateShift=\DateTime::createFromFormat('dmY',$this->shift)->format('Y-m-d');
        $this->url = Option::get('oplati.paysystem', 'requestUrl')."/ms-pay/pos/paymentReports?shift=".$this->shift.'&shiftStart='.$queryDateShift.'&shiftEnd='.$queryDateShift;
        $this->emails = explode(',', Option::get('oplati.paysystem', 'emails'));

        global $USER;
        if (!is_object($USER) || !$USER->IsAuthorized()) {
            $USER = new \CUser;
            $USER->Authorize(1);
        }
    }

    private function includeModules(): void
    {
        Loader::includeModule("oplati.paysystem");
        Loader::includeModule("sale");
    }

    private function fetchPaymentReports()
    {
        $requestMethod = Option::get('oplati.paysystem', 'requestMethod');
        $methods = [
            'http' => [$this, 'fetchHttpPaymentReports'],
            'curl' => [$this, 'fetchCurlPaymentReports'],
        ];

        if (!isset($methods[$requestMethod])) {
            return [];
        }

        call_user_func($methods[$requestMethod]);
    }

    private function fetchHttpPaymentReports()
    {
        $headers = [
            'regNum' => Option::get('oplati.paysystem', 'regnum'),
            'password' => Option::get('oplati.paysystem', 'password'),
            'Content-Type' => 'application/json',
        ];

        $httpClient = new HttpClient();
        $httpClient->setHeaders($headers);
        $response = $httpClient->get($this->url);

        $this->log([
            'date'=>date("Y-m-d H:i:s"),
            'url'=>$this->url,
            'headers'=>$headers,
            'type'=>'Get BitrixHttpClient',
            'methodRequest' => 'paymentReports',
            'params' => [
                'response' => $response,
            ],
        ]);

        $this->response = $httpClient->getStatus() == 200 ? json_decode($response, true) : [];
    }

    private function fetchCurlPaymentReports()
    {
        $headers = [
            'regNum' => Option::get('oplati.paysystem', 'regnum'),
            'password' => Option::get('oplati.paysystem', 'password'),
            'Content-Type' => 'application/json',
        ];

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->url,
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
            'date'=>date("Y-m-d H:i:s"),
            'url'=>$this->url,
            'headers'=>$headers,
            'type'=>'Get Curl',
            'methodRequest' => 'paymentReports',
            'params' => [
                'response' => $response,
            ],
        ]);

        $this->response = $httpCode == 200  && $response? json_decode($response, true) : [];
    }

    private function fetchPaySystems()
    {
        $this->paySystems = Manager::getList([
            'filter' => ['ACTION_FILE' => 'oplati', 'ACTIVE' => 'Y'],
            'select' => ['*'],
        ])->fetchAll();

        if (empty($this->paySystems)) {
            throw new \Exception('Could not get pay system with oplati handler');
        }
    }

    private function fetchPayments()
    {
        $this->orderIds = array_column($this->response, 'orderNumber');

        $dbPayments = $this->GetPayments($this->orderIds);
        if (!empty($dbPayments)) {
            foreach ($dbPayments as $payment) {
                if ($payment['PS_INVOICE_ID']) {
                    $this->payments[$payment['PS_INVOICE_ID']] = $payment;
                }
            }
        }
    }

    private function getPayments($orderId)
    {
        $psIds = array_column($this->paySystems, 'ID');

        $dbPayments = PaymentCollection::getList([
            'select' => ['*'],
            'filter' => ['=PAY_SYSTEM_ID' => $psIds, 'ORDER_ID' => $orderId],
        ])->fetchAll();

        return !empty($dbPayments) ? $dbPayments : [];
    }

    private function preparePayments()
    {
        $prepareData = [];
        foreach ($this->response as $paymentReport) {
            if (isset($this->payments[$paymentReport['paymentId']])) {
                $sitePayment = $this->payments[$paymentReport['paymentId']];
                $prepareData[] = [
                    $sitePayment['ORDER_ID'],
                    $paymentReport['paymentId'],
                    (new \DateTime($paymentReport['paidDate']))->format('d.m.Y H:i:s'),
                    $this->getTypeDescription($paymentReport['paymentType']),
                    $this->getPaymentStatusDescription($sitePayment['PS_STATUS_CODE']),
                    $this->getPaymentStatusDescription($paymentReport['status']),
                    round($sitePayment['SUM'], 2),
                    round($paymentReport['sum'], 2),
                ];
            } elseif ($paymentReport['paymentType'] == 3) {
                $siteOrder = $this->getPayments($paymentReport['orderNumber'])[0];
                $prepareData[] = [
                    $paymentReport['orderNumber'],
                    $paymentReport['paymentId'],
                    (new \DateTime($paymentReport['paidDate']))->format('d.m.Y H:i:s'),
                    $this->getTypeDescription($paymentReport['paymentType']),
                    '',
                    $this->getReturnStatusDescription($paymentReport['status']),
                    '',
                    round($paymentReport['sum'], 2),
                ];
            } else {
                $prepareData[] = [
                    $paymentReport['orderNumber'],
                    $paymentReport['paymentId'],
                    (new \DateTime($paymentReport['paidDate']))->format('d.m.Y H:i:s'),
                    $this->getTypeDescription($paymentReport['paymentType']),
                    '',
                    $this->getPaymentStatusDescription($paymentReport['status']),
                    '',
                    round($paymentReport['sum'], 2),
                ];
            }
        }

        return $prepareData;
    }

    private function getReturnStatusDescription($code)
    {
        $description = [
            0 => 'Возврат ожидает подтверждения',
            1 => 'Возврат совершен',
            2 => 'Отказ от возврата',
            3 => 'Недостаточно средств',
            4 => 'Клиент не подтвердил возврат',
            5 => 'Операция была отменена системой',
        ];

        return $description[$code] ?: '';
    }

    private function getPaymentStatusDescription($code)
    {
        $description = [
            0 => 'Платеж ожидает подтверждения',
            1 => 'Платеж совершен',
            2 => 'Отказ от платежа',
            3 => 'Недостаточно средств',
            4 => 'Клиент не подтвердил платеж',
            5 => 'Операция была отменена системой',
        ];

        return $description[$code] ?: '';
    }

    private function getTypeDescription($code)
    {
        $description = [
            1 => 'Продажа',
            2 => 'Покупка',
            3 => 'Возврат продажи',
            4 => 'Возврат покупки',
        ];

        return $description[$code] ?: '';
    }

    private function buildHtmlTable(array $headers, array $rows): string
    {
        $html = '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; font-family: Arial, sans-serif;">';

        // Заголовки
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th style="background-color: #f2f2f2;">'.htmlspecialchars($header).'</th>';
        }
        $html .= '</tr></thead>';

        // Строки данных
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.htmlspecialchars($cell).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';

        $html .= '</table>';

        return $html;
    }

    private function prepareMessageText()
    {
        $headers = [
            'Номер заказа',
            'Номер платежа',
            'Дата оплаты',
            'Тип операции',
            'Cтатус платежа (магазин)',
            'Cтатус платежа (платежная система)',
            'Cумма платежа (магазин)',
            'Cумма платежа (платежная система)',
        ];

        $tableHtml = $this->buildHtmlTable($headers, $this->preparePayments());

        return "
        <html>
            <body>
                {$tableHtml}
            </body>
        </html>";
    }

    private function sendReconciliationEmails()
    {
        if ($this->emails) {
            foreach ($this->emails as $email) {
                \CEvent::Send(
                    self::EVENT_NAME,
                    SITE_ID,
                    [
                        'EMAIL_TO' => $email,
                        "TEXT" => $this->prepareMessageText(),
                        "DATE"=>'за '. \DateTime::createFromFormat('dmY',$this->shift)->format('d.m.Y'),
                    ]
                );
            }
        }
    }

    public function run()
    {
        try {
            $this->fetchPaymentReports();
            $this->fetchPaySystems();
            $this->fetchPayments();
            $this->sendReconciliationEmails();
        } catch (\Exception $e) {
        }
    }

    private function log($data): bool
    {
        if (Option::get('oplati.paysystem', 'set_logging') !== 'Y') {
            return false;
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'];
        $paths = [
            $documentRoot."/local/php_interface/include/sale_payment/oplati",
            $documentRoot."/bitrix/php_interface/include/sale_payment/oplati"
        ];

        $logDir = null;
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $logDir = $path;
                break;
            }
        }

        if (!$logDir) {
            return false;
        }

        return file_put_contents(
            $logDir.'/oplati-'.date('d-m-Y-H').'-log.json',
            json_encode($data, JSON_PRETTY_PRINT, JSON_UNESCAPED_UNICODE)."\n-------------\n\n",
            FILE_APPEND
        );

    }
}