<?php

namespace Oplati\Paysystem\Handlers;


use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;

class BackgroundJobHandlers
{

    public static function SyncPaymentAgent()
    {
        \Bitrix\Main\Application::getInstance()->addBackgroundJob(
            [
                "Oplati\Paysystem\Handlers\BackgroundJobHandlers",
                "startAgentSyncPayment"
            ]
        );
        return  "Oplati\Paysystem\Handlers\BackgroundJobHandlers::SyncPaymentAgent();";
    }

    public static function PaymentReconciliationAgent()
    {
        \Bitrix\Main\Application::getInstance()->addBackgroundJob(
            [
                "Oplati\Paysystem\Handlers\BackgroundJobHandlers",
                "startAgentPaymentReconciliation"
            ]
        );
        return  "Oplati\Paysystem\Handlers\BackgroundJobHandlers::PaymentReconciliationAgent();";
    }


    public static function startAgentSyncPayment()
    {
        (new \Oplati\Paysystem\SyncServices\SyncPayment())->SyncData();
    }

    public static function startAgentPaymentReconciliation()
    {
        set_time_limit(0);

        $yesterday = (new \DateTime())->modify('-1 day')->setTime(0, 0);
        $lastDateStr = \Bitrix\Main\Config\Option::get('oplati.paysystem', 'last_reconciliation_date', null);

        if ($lastDateStr) {
            $lastDate = (new \DateTime())->setTimestamp((int)$lastDateStr);
        } else {
            $lastDate = clone $yesterday;
        }

        while ($lastDate <= $yesterday) {
            $dateStr = $lastDate->format('dmY');

            (new \Oplati\Paysystem\SyncServices\PaymentReconciliation($dateStr))->run();

            $lastDate->modify('+1 day');
        }

        \Bitrix\Main\Config\Option::set('oplati.paysystem', 'last_reconciliation_date', $lastDate->getTimestamp());
    }
}