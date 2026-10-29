<?php

declare(strict_types=1);

$root = sys_get_temp_dir().'/sahil-framework-fixture-'.(getmypid() ?: 'test');

return [
    'paths' => ['views'],
    'cache' => $root.'/views',
    'extension' => '.sahil.php',
];
