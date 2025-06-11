<?php
/** @var $mid */

defined('B_PROLOG_INCLUDED') and (B_PROLOG_INCLUDED === true) or die();
defined('ADMIN_MODULE_NAME') or define('ADMIN_MODULE_NAME', 'oplati.paysystem');

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

$app = Application::getInstance();
$context = $app->getContext();
$request = $context->getRequest();

Loc::loadMessages($context->getServer()->getDocumentRoot()."/bitrix/modules/main/options.php");

$tabControl = new CAdminTabControl(
    "tabControl",
    [
        [
            "DIV" => "edit1",
            "TAB" => 'Настройки',
            "TITLE" => 'Настройки',
        ],
    ],
);

function setOptionIfPost($key)
{
    global $request;
    $value = $request->getPost($key);
    if ($key == 'publicKey') {
        $value = preg_replace('/\s+/', '', $value);
    }
    if ($key == 'emails') {
        $value = preg_replace('/\s+/', '', $value);
    }

    Option::set(ADMIN_MODULE_NAME, $key, $value);
}

function setCheckboxOption($key)
{
    global $request;
    $value = $request->getPost($key);
    Option::set(ADMIN_MODULE_NAME, $key, $value === 'Y' ? 'Y' : 'N');
}

function startPaymentReconciliation($key)
{
    global $request;
    $value = $request->getPost($key);
    if ($value === 'Y') {
        $nextDay = (new \DateTime())->modify('+1 day')->setTime(0, 0);
        $agentId = \CAgent::AddAgent(
            "Oplati\Paysystem\Handlers\BackgroundJobHandlers::PaymentReconciliationAgent();",
            ADMIN_MODULE_NAME,
            "Y",
            86400,
            $nextDay->format('d.m.Y 00:01:00'),
            "Y",
            $nextDay->format('d.m.Y 00:01:00'),
        );

        if ($agentId) {
            \COption::SetOptionString(ADMIN_MODULE_NAME, 'paymentReconciliationAgentId', $agentId);
            \COption::RemoveOption(ADMIN_MODULE_NAME, 'last_reconciliation_date');
        }
    } else {
        if (Option::get(ADMIN_MODULE_NAME, 'paymentReconciliationAgentId')) {
            \CAgent::Delete(Option::get(ADMIN_MODULE_NAME, 'paymentReconciliationAgentId'));
            \COption::RemoveOption(ADMIN_MODULE_NAME, 'last_reconciliation_date');
            \COption::RemoveOption(ADMIN_MODULE_NAME, 'paymentReconciliationAgentId');
        }
    }
}


if ($request->isPost() && check_bitrix_sessid()) {
    if (!empty($save)) {
        if (!empty($restore)) {
            $urlRequest = Option::get(ADMIN_MODULE_NAME, 'requestUrl');
            Option::delete(ADMIN_MODULE_NAME);
            Option::set(ADMIN_MODULE_NAME, 'requestUrl', $urlRequest);
            CAdminMessage::showMessage([
                "MESSAGE" => Loc::getMessage("OPLATI_PAYSYSTEM_RESTORE_SETTING"),
                "TYPE" => "OK",
            ]);
        } else {
            setOptionIfPost('requestUrl');
            setOptionIfPost('regnum');
            setOptionIfPost('password');
            setOptionIfPost('publicKey');
            setOptionIfPost('requestMethod');
            setOptionIfPost('typePay');
            setOptionIfPost('emails');
            setOptionIfPost('oplati_logo_type');
            setCheckboxOption('set_logging');
            setCheckboxOption('set_sync_reconciliation');
            startPaymentReconciliation('set_sync_reconciliation');

            CAdminMessage::showMessage([
                "MESSAGE" => 'Настройки сохранены',
                "TYPE" => "OK",
            ]);
        }
    }
}

$tabControl->begin();
?>

<form method="post"
      action="<?= sprintf('%s?mid=%s&lang=%s', $request->getRequestedPage(), urlencode($mid), LANGUAGE_ID) ?>">
    <?php
    echo bitrix_sessid_post();
    $tabControl->beginNextTab();
    ?>
    <tr>
        <td width="40%"><?= Loc::getMessage("OPLATI_PAYSYSTEM_URL") ?></td>
        <td width="60%">
            <select name="requestUrl">
                <option value=""><?= Loc::getMessage("OPLATI_PAYSYSTEM_NOT_SELECT") ?></option>
                <option value="https://cashboxapi.o-plati.by" <?= (Option::get(
                        ADMIN_MODULE_NAME,
                        'requestUrl',
                    ) == "https://cashboxapi.o-plati.by") ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_URL_PROD") ?>
                </option>
                <option value="https://oplati-cashboxapi.lwo-dev.by" <?= (Option::get(
                        ADMIN_MODULE_NAME,
                        'requestUrl',
                    ) == "https://oplati-cashboxapi.lwo-dev.by") ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_URL_TEST") ?>
                </option>
            </select>
        </td>
    </tr>
    <tr>
        <td>
            <label for="regnum"><?= Loc::getMessage("OPLATI_PAYSYSTEM_CASHBOX_REGNUM") ?></label>
        </td>
        <td>
            <input type="text" name="regnum" value="<?= Option::get(ADMIN_MODULE_NAME, 'regnum') ?>">
        </td>
    </tr>
    <tr>
        <td>
            <label for="password"><?= Loc::getMessage("OPLATI_PAYSYSTEM_PASSWORD") ?></label>
        </td>
        <td>
            <input type="text" name="password" value="<?= Option::get(ADMIN_MODULE_NAME, 'password') ?>">
        </td>
    </tr>

    <tr>
        <td>
            <label for="publicKey"><?= Loc::getMessage("OPLATI_PAYSYSTEM_PUBLIC_KEY") ?></label>
        </td>
        <td>
            <textarea name="publicKey" style="min-width: 400px; min-height: 190px;"><?= Option::get(
                    ADMIN_MODULE_NAME,
                    'publicKey',
                ) ?></textarea>
        </td>
    </tr>

    <tr>
        <td width="40%"><?= Loc::getMessage("OPLATI_PAYSYSTEM_METHOD_REQUEST") ?></td>
        <td width="60%">
            <select name="requestMethod">
                <option value="http" <?= (Option::get(ADMIN_MODULE_NAME, 'requestMethod') == "http" || !Option::get(
                        ADMIN_MODULE_NAME,
                        'requestMethod',
                    )) ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_METHOD_REQUEST_HTTP") ?>
                </option>
                <option value="curl" <?= (Option::get(
                        ADMIN_MODULE_NAME,
                        'requestMethod',
                    ) == "curl") ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_METHOD_REQUEST_CURL") ?>
                </option>
            </select>
        </td>
    </tr>

    <tr>
        <td width="40%"><?= Loc::getMessage("OPLATI_PAYSYSTEM_TYPE_PAY") ?></td>
        <td width="60%">
            <select name="typePay">
                <option value="button" <?= (Option::get(ADMIN_MODULE_NAME, 'typePay') == "button" || !Option::get(
                        ADMIN_MODULE_NAME,
                        'requestMethod',
                    )) ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_TYPE_PAY_BUTTON") ?>
                </option>
                <option value="auto" <?= (Option::get(ADMIN_MODULE_NAME, 'typePay') == "auto") ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_TYPE_PAY_AUTO") ?>
                </option>
            </select>
        </td>
    </tr>

    <tr>
        <td width="40%"><?= Loc::getMessage("OPLATI_PAYSYSTEM_LOGO") ?></td>
        <td width="60%">
            <select name="oplati_logo_type">
                <option value="logo_Oplati_black.png" <?= (Option::get(
                        ADMIN_MODULE_NAME,
                        'oplati_logo_type',
                    ) == "logo_Oplati_black.png" || !Option::get(
                        ADMIN_MODULE_NAME,
                        'oplati_logo_type',
                    )) ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_LOGO_BLACK") ?>
                </option>
                <option value="logo_Oplati_white.png" <?= (Option::get(
                        ADMIN_MODULE_NAME,
                        'oplati_logo_type',
                    ) == "logo_Oplati_white.png") ? 'selected' : '' ?>>
                    <?= Loc::getMessage("OPLATI_PAYSYSTEM_LOGO_WHITE") ?>
                </option>
            </select>
        </td>
    </tr>
    <tr>
        <td width="40%"><?= Loc::getMessage("OPLATI_PAYSYSTEM_SET_LOGGING") ?></td>
        <input type="hidden" name="set_logging" value="N">
        <td width="60%"><input type="checkbox" name="set_logging" <?= Option::get(
                ADMIN_MODULE_NAME,
                'set_logging',
            ) == "Y" ? 'checked' : '' ?> value="Y"/></td>
    </tr>
    <tr>
        <td width="40%"><?= Loc::getMessage("OPLATI_PAYSYSTEM_SET_SYNC_RECONCILIATION") ?></td>
        <input type="hidden" name="set_sync_reconciliation" value="N">
        <td width="60%"><input type="checkbox" name="set_sync_reconciliation" value="Y" <?= Option::get(
                ADMIN_MODULE_NAME,
                'set_sync_reconciliation',
            ) == "Y" ? 'checked' : '' ?> /></td>
    </tr>
    <tr>
        <td>
            <label for="emails"><?= Loc::getMessage("OPLATI_PAYSYSTEM_EMAILS_FOR_NOTIFICATIONS") ?></label>
        </td>
        <td>
            <input type="text" name="emails" value="<?= Option::get(ADMIN_MODULE_NAME, 'emails') ?>">
        </td>
    </tr>

    <?php
    $tabControl->buttons();
    ?>
    <input type="submit" name="save" value="<?= Loc::getMessage("MAIN_SAVE") ?>"
           title="<?= Loc::getMessage("MAIN_OPT_SAVE_TITLE") ?>" class="adm-btn-save"/>
    <input type="submit" name="restore" title="<?= Loc::getMessage("MAIN_HINT_RESTORE_DEFAULTS") ?>"
           onclick="return confirm('<?= AddSlashes(GetMessage("MAIN_HINT_RESTORE_DEFAULTS_WARNING")) ?>')"
           value="<?= Loc::getMessage("MAIN_RESTORE_DEFAULTS") ?>"/>
    <?php
    $tabControl->end();
    ?>
</form>

