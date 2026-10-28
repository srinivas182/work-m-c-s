<?php

declare(strict_types=1);

/*
 * Provider addresses. Kept here (not in code) so a correction found during the sandbox tests is a config
 * change. Ozow and Peach in particular must be confirmed against their sandbox accounts before going live.
 */
return [
    'ozow' => [
        'pay' => 'https://pay.ozow.com',
        'api' => 'https://api.ozow.com',
    ],
    'peach' => [
        'auth' => ['test' => 'https://sandbox-dashboard.peachpayments.com/api/oauth/token', 'live' => 'https://dashboard.peachpayments.com/api/oauth/token'],
        'checkout' => ['test' => 'https://testsecure.peachpayments.com', 'live' => 'https://secure.peachpayments.com'],
    ],
];
