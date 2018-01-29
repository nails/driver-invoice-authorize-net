<?php

return array(
    'models' => array(
        'Source' => function () {
            if (class_exists('App\Invoice\Driver\Payment\AuthorizeDotNet\Model\Source')) {
                return new App\Invoice\Driver\Payment\AuthorizeDotNet\Model\Source();
            } else {
                return new Nails\Invoice\Driver\Payment\AuthorizeDotNet\Model\Source();
            }
        }
    )
);
