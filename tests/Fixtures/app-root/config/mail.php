<?php

declare(strict_types=1);

return [
    'default' => 'array',
    'from' => [
        'address' => 'tests@example.test',
        'name' => 'SahilPHP Tests',
    ],
    'transports' => [
        'array' => [
            'driver' => 'array',
        ],
    ],
    'queue' => [
        'enabled' => false,
        'name' => 'emails',
        'delay' => 0,
    ],
];
