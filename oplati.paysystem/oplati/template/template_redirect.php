<?php
use Bitrix\Sale\Payment;

/**
 * @var Payment $payment
 * @var  $params
 */

?>

<script>
    "use strict";
    (function () {
        var paymentUrl = '<?= $params['redirectUrl']  ?>';
        
        window.location.href = paymentUrl;

    })();
</script>