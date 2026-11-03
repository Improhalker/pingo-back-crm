<?php

return [
    // Empty by default. Only the addresses of the actual TLS proxy are trusted.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', ''))))),
];
