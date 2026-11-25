
<?php

return [
    'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
    'port'     => env('RABBITMQ_PORT', 5672),
    'user'     => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'queue'    => env('RABBITMQ_QUEUE', 'demandas.processar'),
    'management_url' => env('RABBITMQ_MANAGEMENT_URL', 'http://127.0.0.1:15672'),
    'vhost' => env('RABBITMQ_VHOST', '/')
];

?>