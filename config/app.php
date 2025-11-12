<?php
return [
    'name' => 'Hillemballage',
    'env' => getenv('APP_ENV') ?: 'local',
    'debug' => (bool)(getenv('APP_DEBUG') ?: true),
    'base_url' => getenv('APP_URL') ?: '/',
    'timezone' => 'Africa/Dakar',
];
