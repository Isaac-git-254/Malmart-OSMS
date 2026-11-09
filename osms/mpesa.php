<?php
declare(strict_types=1);

class MpesaRejectedException extends RuntimeException
{
}

function mpesa_callback_token(): string
{
    $token = getenv('MPESA_CALLBACK_TOKEN');
    if ($token === false || strlen($token) < 32) {
        throw new RuntimeException('MPESA_CALLBACK_TOKEN must contain at least 32 characters.');
    }

    return $token;
}

function mpesa_config(): array
{
    $environment = getenv('MPESA_ENVIRONMENT') ?: (APP_ENVIRONMENT === 'production' ? 'production' : 'sandbox');
    $transactionType = getenv('MPESA_TRANSACTION_TYPE') ?: 'CustomerPayBillOnline';
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        throw new RuntimeException('MPESA_ENVIRONMENT must be sandbox or production.');
    }
    if (!in_array($transactionType, ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)) {
        throw new RuntimeException('MPESA_TRANSACTION_TYPE must be CustomerPayBillOnline or CustomerBuyGoodsOnline.');
    }

    $values = [
        'consumer_key' => getenv('MPESA_CONSUMER_KEY'),
        'consumer_secret' => getenv('MPESA_CONSUMER_SECRET'),
        'shortcode' => getenv('MPESA_SHORTCODE'),
        'passkey' => getenv('MPESA_PASSKEY'),
        'callback_url' => getenv('MPESA_CALLBACK_URL'),
        'callback_token' => mpesa_callback_token(),
    ];
    foreach ($values as $name => $value) {
        if ($value === false || $value === '') {
            throw new RuntimeException('M-Pesa configuration is missing ' . $name . '.');
        }
    }
    $callback = parse_url($values['callback_url']);
    if (
        !is_array($callback)
        || ($callback['scheme'] ?? '') !== 'https'
        || !isset($callback['host'])
    ) {
        throw new RuntimeException('MPESA_CALLBACK_URL must be an absolute HTTPS URL.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required for M-Pesa payments.');
    }

    $host = $environment === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';

    return $values + ['environment' => $environment, 'transaction_type' => $transactionType, 'host' => $host];
}

function mpesa_phone_number(string $phone): string
{
    $normalized = preg_replace('/[\s().-]/', '', $phone);
    if (!is_string($normalized)) {
        throw new DomainException('Enter a valid Kenyan mobile number.');
    }
    if (str_starts_with($normalized, '+')) {
        $normalized = substr($normalized, 1);
    }
    if (preg_match('/\A0[17]\d{8}\z/', $normalized) === 1) {
        $normalized = '254' . substr($normalized, 1);
    } elseif (preg_match('/\A[17]\d{8}\z/', $normalized) === 1) {
        $normalized = '254' . $normalized;
    }
    if (preg_match('/\A254[17]\d{8}\z/', $normalized) !== 1) {
        throw new DomainException('Enter a valid Kenyan mobile number, such as 0712345678.');
    }

    return $normalized;
}

function mpesa_http_request(string $url, array $options): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the M-Pesa connection.');
    }
    curl_setopt_array($curl, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('M-Pesa request failed with HTTP ' . $status . ($curlError !== '' ? ': ' . $curlError : '.'));
    }
    try {
        $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('M-Pesa returned an invalid response.', 0, $exception);
    }
    if (!is_array($data)) {
        throw new RuntimeException('M-Pesa returned an invalid response.');
    }

    return $data;
}

function mpesa_start_stk_push(array $config, string $phone, int $amount, string $orderNumber): array
{
    $credentials = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
    try {
        $tokenResponse = mpesa_http_request(
            $config['host'] . '/oauth/v1/generate?grant_type=client_credentials',
            [CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials]]
        );
    } catch (RuntimeException $exception) {
        throw new MpesaRejectedException('M-Pesa authentication failed before the payment request was sent.', 0, $exception);
    }
    $accessToken = $tokenResponse['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        throw new MpesaRejectedException('M-Pesa did not return an access token.');
    }

    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('YmdHis');
    $password = base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);
    $callbackUrl = $config['callback_url']
        . (str_contains($config['callback_url'], '?') ? '&' : '?')
        . 'token=' . rawurlencode($config['callback_token']);
    $request = [
        'BusinessShortCode' => $config['shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => $config['transaction_type'],
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $config['shortcode'],
        'PhoneNumber' => $phone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => $orderNumber,
        'TransactionDesc' => 'Mallmart order ' . $orderNumber,
    ];
    $response = mpesa_http_request(
        $config['host'] . '/mpesa/stkpush/v1/processrequest',
        [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
        ]
    );
    if (
        ($response['ResponseCode'] ?? null) !== '0'
        || !is_string($response['CheckoutRequestID'] ?? null)
        || !is_string($response['MerchantRequestID'] ?? null)
    ) {
        throw new MpesaRejectedException('M-Pesa did not accept the STK push request.');
    }

    return [
        'checkout_request_id' => $response['CheckoutRequestID'],
        'merchant_request_id' => $response['MerchantRequestID'],
    ];
}
