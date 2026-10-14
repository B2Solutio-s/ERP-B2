<?php

return [
    'biometrico' => [
        'host' => env('BIOMETRICO_HOST', '192.168.1.201'),
        'port' => (int) env('BIOMETRICO_PORT', 4370),
        'clave' => (int) env('BIOMETRICO_CLAVE', 0),
    ],
];
