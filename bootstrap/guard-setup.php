<?php

$root = dirname(__DIR__);

foreach ([
    'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'LARAVEL_STORAGE_PATH',
    'APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_EVENTS_CACHE', 'APP_ROUTES_CACHE',
] as $name) {
    if (! in_array(getenv($name), [false, ''], true)) {
        fwrite(STDERR, "Setup requires a fresh checkout without an inherited {$name} setting. Use the documented update procedure for an existing installation.\n");
        exit(1);
    }
}

$database = $root.'/database/database.sqlite';

if (file_exists($root.'/.env') || file_exists($root.'/bootstrap/cache/config.php')
    || (file_exists($database) && filesize($database) !== 0)) {
    fwrite(STDERR, "Setup requires a fresh checkout without .env, cached configuration, or a populated SQLite database. Use the documented update procedure for an existing installation.\n");
    exit(1);
}
