<?php
namespace Oplati\Paysystem\SyncServices;

use Bitrix\Main\Application;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Sale\PaymentCollection;
use Bitrix\Sale\PaySystem\Manager;

class SyncPayment
{
    public function __construct()
    {
        $this->includeModules();
        global $USER;
        if (!is_object($USER) || !$USER->IsAuthorized()) {
            $USER = new \CUser;
            $USER->Authorize(1);
        }
    }

    public function syncData(): void
    {
        $paySystemIds = $this->getActiveOplatiPaySystemIds();
        if (empty($paySystemIds)) {
            return;
        }

        $payments = $this->getUnpaidPayments($paySystemIds);
        if (empty($payments)) {
            return;
        }


        foreach ($payments as $payment) {
            $this->processPayment($payment);
            sleep(5);
        }
    }

    private function includeModules(): void
    {
        Loader::includeModule("oplati.paysystem");
        Loader::includeModule("sale");
    }

    private function getActiveOplatiPaySystemIds(): array
    {
        $paySystems = Manager::getList([
            'filter' => [
                'ACTION_FILE' => 'oplati',
                'ACTIVE' => 'Y'
            ],
            'select' => ['ID']
        ])->fetchAll();

        return array_column($paySystems, 'ID');
    }

    private function getUnpaidPayments(array $paySystemIds): array
    {
        return PaymentCollection::getList([
            'select' => ['*'],
            'filter' => [
                '=PAY_SYSTEM_ID' => $paySystemIds,
                'PAID' => 'N',
                [
                    "LOGIC" => "OR",
                    ["PS_STATUS_CODE" => 0],
                    ["PS_STATUS_CODE" => false]
                ]
            ],
        ])->fetchAll();
    }

    private function processPayment(array $payment): void
    {
        $server = Application::getInstance()->getContext()->getServer();

        $request = new HttpRequest(
            $server,
            [
                'action' => 'paymentStatus',
                'paymentId' => $payment['ID'],
                'BX_HANDLER' => 'OPLATI'
            ],
            [],
            [],
            []
        );

        $service = Manager::getObjectById($payment['PAY_SYSTEM_ID']);

        if ($service) {

            $service->processRequest($request);
        }
    }
}
