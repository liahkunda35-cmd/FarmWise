<?php
return [
    'name' => 'FarmWise',
    'full_name' => 'Farm Management System',
    'tagline' => 'Smarter Farming. Better Yields.',
    'farm_photo' => 'img/farm-overview.jpg',
    'promo_photo' => 'img/promo-seedling.jpg',
    // sqlite | mysql | pgsql (Neon). Overridden by IFMS_DB_DRIVER or DATABASE_URL presence.
    'db_driver' => getenv('IFMS_DB_DRIVER')
        ?: ((getenv('DATABASE_URL') || getenv('DATABASE_URL_POOLED') || getenv('IFMS_DATABASE_URL')) ? 'pgsql' : 'sqlite'),
    'database_url' => getenv('DATABASE_URL_POOLED') ?: getenv('DATABASE_URL') ?: getenv('IFMS_DATABASE_URL') ?: '',
    'sqlite_path' => dirname(__DIR__) . '/storage/ifms.sqlite',
    'mysql' => [
        'host' => getenv('IFMS_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('IFMS_DB_PORT') ?: '3306',
        'database' => getenv('IFMS_DB_NAME') ?: 'ifms',
        'username' => getenv('IFMS_DB_USER') ?: 'root',
        'password' => getenv('IFMS_DB_PASS') !== false ? getenv('IFMS_DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],
    'pgsql' => [
        'host' => getenv('PGHOST') ?: getenv('IFMS_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('PGPORT') ?: getenv('IFMS_DB_PORT') ?: '5432',
        'database' => getenv('PGDATABASE') ?: getenv('IFMS_DB_NAME') ?: 'neondb',
        'username' => getenv('PGUSER') ?: getenv('IFMS_DB_USER') ?: 'neondb_owner',
        'password' => getenv('PGPASSWORD') !== false && getenv('PGPASSWORD') !== null
            ? getenv('PGPASSWORD')
            : (getenv('IFMS_DB_PASS') !== false ? getenv('IFMS_DB_PASS') : ''),
        'sslmode' => getenv('PGSSLMODE') ?: 'require',
    ],
    'session_name' => 'IFMSSESSID',
    'currency' => 'ZMW',
    'currency_symbol' => 'K',
    'timezone' => 'Africa/Johannesburg',
    'weather_api' => 'https://api.open-meteo.com/v1/forecast',
    // Local farm hero images for the landing slideshow
    'hero_image' => 'img/livestock-farm-hero.jpg',
    'hero_images' => [
        'img/livestock-farm-hero.jpg',
        'img/crops-farm-hero.jpg',
        'img/farm-overview.jpg',
    ],
];
