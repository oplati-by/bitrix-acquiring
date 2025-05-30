<?php
use Bitrix\Sale\Payment;

/**
 * @var Payment $payment
 * @var  $params
 */
?>

<style>
    #oplati-<?= $payment->getId() ?> a div {
        margin: 15px 0;
        width: 260px;
        height: 55px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: <?= $params['paymentLogoColors']['paymentLogoColor'] ?>;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    #oplati-<?= $payment->getId() ?> a div:hover {
        background-color: <?= $params['paymentLogoColors']['paymentLogoHoverColor'] ?>;
    }
</style>

<div id="oplati-<?= $payment->getId() ?>">
    <p style="max-width: 270px; text-align: left;">Для оплаты заказа через мобильное приложение Оплати нажмите на кнопку</p>
    <a href="<?= $params['redirectUrl'] ?>" id="oplati-link<?= $payment->getId() ?>" target="_blank">
        <div>
            <img style="height: 25px; width: 153px;" src="<?= $params['paymentLogoPath'] ?> " alt="logo">
        </div>
    </a>
</div>

<script>
    "use strict";

    (function () {
        var paymentId = <?= $payment->getId()  ?>;
        var sessionId = '<?= $params["paymentId"] ?>';
        let interval = null;

        startConsumerStatusCheck(sessionId);

        function onPaySuccess() {
            window.location = '/bitrix/tools/sale_ps_success.php';
        }

        function onPayFail() {
            window.location = '/bitrix/tools/sale_ps_fail.php';
        }

        function startConsumerStatusCheck(sessionId) {
            interval = setInterval(function() {
                fetch('/bitrix/tools/sale_ps_result.php' + '?action=consumerStatus' + '&BX_HANDLER=OPLATI' + '&paymentId=' + paymentId)
                    .then(res => res.json())
                    .then(data => {
                        if(data.statusCode=='success'){
                            clearInterval(interval);
                            onPaySuccess()
                        }
                        if(data.statusCode=='failed'){
                            clearInterval(interval);
                            onPayFail()
                        }
                    });
            }, 5000);
        }


    })();
</script>