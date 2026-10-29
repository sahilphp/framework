<?php

declare(strict_types=1);

$root = sys_get_temp_dir().'/sahil-framework-fixture-'.(getmypid() ?: 'test');

return [
    'driver' => 'file',
    'lifetime' => 120,
    'path' => $root.'/sessions',
    'cookie' => 'sahil_session',
    'secure' => false,
    'http_only' => true,
    'same_site' => 'lax',
    'csrf_except' => [],
];
