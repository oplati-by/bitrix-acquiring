<?php

defined('B_PROLOG_INCLUDED') and (B_PROLOG_INCLUDED === true) or die();

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Mail\Internal\EventTypeTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

class oplati_paysystem extends CModule
{
    public $MODULE_ID;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;
    var $MODULE_VERSION;
    var $MODULE_VERSION_DATE;

    function __construct()
    {
        if (file_exists(__DIR__."/version.php")) {
            $arModuleVersion = [];
            include __DIR__.'/version.php';

            $this->MODULE_ID = str_replace("_", ".", get_class($this));
            $this->MODULE_VERSION = $arModuleVersion["VERSION"];
            $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];

            $this->MODULE_NAME = Loc::getMessage('OPLATI_PAYSYSTEM_NAME');
            $this->MODULE_DESCRIPTION = Loc::getMessage('OPLATI_PAYSYSTEM_MODULE_DESCRIPTION');
            $this->PARTNER_NAME = Loc::getMessage("OPLATI_PAYSYSTEM_PARTNER_NAME");
            $this->PARTNER_URI = Loc::getMessage("OPLATI_PAYSYSTEM_PARTNER_URI");
        }
    }

    public function doInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);

        $this->installFiles();
        $this->installAgents();
        $this->installOptions();
        $this->installPostEvents();
    }

    public function doUninstall()
    {
        $this->unInstallAgents();
        $this->unInstallOptions();
        $this->unInstallPostEvents();
        $this->unInstallFiles();

        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    public function installFiles()
    {
        $app = Application::getInstance();
        $documentRoot = $app->getContext()->getServer()->getDocumentRoot();

        CopyDirFiles(
            __DIR__.'/../oplati',
            $documentRoot."/local/php_interface/include/sale_payment/oplati",
            true,
            true
        );
    }

    public function unInstallFiles()
    {
        $app = Application::getInstance();
        $documentRoot = $app->getContext()->getServer()->getDocumentRoot();

        Directory::deleteDirectory($documentRoot.'/local/php_interface/include/sale_payment/oplati');
    }

    public function installAgents()
    {
        $agentId = \CAgent::AddAgent(
            "Oplati\Paysystem\Handlers\BackgroundJobHandlers::SyncPaymentAgent();",
            $this->MODULE_ID,
            "N",
            120,
            "",
            "Y",
            ""
        );

        if ($agentId) {
            \COption::SetOptionString($this->MODULE_ID, 'syncPaymentAgentId', $agentId);
        }
    }

    public function unInstallAgents()
    {
        $arOptions = Option::getForModule($this->MODULE_ID);
        if ($arOptions['syncPaymentAgentId']) {
            \CAgent::Delete($arOptions['syncPaymentAgentId']);
            \COption::RemoveOption($this->MODULE_ID, 'syncPaymentAgentId');
        }
        if ($arOptions ['paymentReconciliationAgentId']) {
            \CAgent::Delete($arOptions['paymentReconciliationAgentId']);
            \COption::RemoveOption($this->MODULE_ID, 'paymentReconciliationAgentId');
        }
    }

    public function installOptions() {
    }

    public function unInstallOptions() {

        \COption::RemoveOption($this->MODULE_ID, 'requestUrl');
        \COption::RemoveOption($this->MODULE_ID, 'regnum');
        \COption::RemoveOption($this->MODULE_ID, 'password');
        \COption::RemoveOption($this->MODULE_ID, 'publicKey');
        \COption::RemoveOption($this->MODULE_ID, 'emails');
        \COption::RemoveOption($this->MODULE_ID, 'oplati_logo_type');
        \COption::RemoveOption($this->MODULE_ID, 'set_logging');
        \COption::RemoveOption($this->MODULE_ID, 'set_sync_reconciliation');
    }

    public function installPostEvents() {
        $mailEventExists = EventTypeTable::getList([
            'select' => ['*'],
            'filter' => ['EVENT_NAME' => 'OPLATI_RECONCILIATION'],
            'limit' => 1,
        ])->fetch();

        if (!$mailEventExists) {
            $arSites = [];
            $rsSites = CSite::GetList($by = "sort", $order = "desc");
            while ($arSite = $rsSites->Fetch()) {
                $arSites[] = $arSite["LID"];
            }

            $eventType = new \CEventType;
            $eventType->Add([
                "LID" => SITE_ID,
                "EVENT_NAME" => "OPLATI_RECONCILIATION",
                "NAME" => 'Cверка платежей с Оплати',
                "DESCRIPTION" => 'Cверка платежей с Оплати',
                'EVENT_TYPE' => 'email',
            ]);

            $eventMessage = new \CEventMessage;
            $mes = $eventMessage->Add([
                "ACTIVE" => "Y",
                "EVENT_NAME" => "OPLATI_RECONCILIATION",
                "LID" => $arSites,
                "EMAIL_FROM" => "#DEFAULT_EMAIL_FROM#",
                "EMAIL_TO" => "#EMAIL_TO#",
                "SUBJECT" => 'Cверка платежей с Оплати #DATE#',
                "MESSAGE" => '#TEXT#',
                "BODY_TYPE" => "html",
            ]);
        }
    }

    public function unInstallPostEvents() {

        \CEventType::Delete('OPLATI_RECONCILIATION');
        $resEventMessage = \CEventMessage::GetList($by = "id", $order = "asc", ["EVENT_NAME" => "OPLATI_RECONCILIATION"])->Fetch();

        if ($resEventMessage) {
            \CEventMessage::Delete($resEventMessage["ID"]);
        }
    }

}
