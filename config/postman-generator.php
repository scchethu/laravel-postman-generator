<?php

return [
    'name' => env('POSTMAN_NAME', 'Laravel API Collection'),
    'base_url' => env('POSTMAN_BASE_URL', env('APP_URL')),
    'output_path' => env('POSTMAN_OUTPUT_PATH', 'postman'),
    'filename' => env('POSTMAN_FILENAME', 'collection.json'),
];
