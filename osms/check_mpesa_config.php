<?php

$variables = [
    'APP_ENV',
    'MPESA_ENVIRONMENT',
    'MPESA_TRANSACTION_TYPE',
    'MPESA_CONSUMER_KEY',
    'MPESA_CONSUMER_SECRET',
    'MPESA_SHORTCODE',
    'MPESA_PASSKEY',
    'MPESA_CALLBACK_URL',
    'MPESA_CALLBACK_TOKEN'
];

foreach ($variables as $variable) {
    echo $variable . ': ';
    echo getenv($variable) !== false
        && getenv($variable) !== ''
        ? 'CONFIGURED'
        : 'MISSING';

    echo "<br>";
}

echo "PHP cURL: " .
    (extension_loaded('curl') ? 'ENABLED' : 'DISABLED');