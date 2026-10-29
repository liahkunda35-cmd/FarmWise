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
    // IFMS_SQLITE_PATH lets disposable tests use a temp file outside the project.
    'sqlite_path' => getenv('IFMS_SQLITE_PATH') ?: (dirname(__DIR__, 2) . '/storage/ifms.sqlite'),
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
    // OpenWeather free plan: Current Weather + 5 Day / 3 Hour Forecast (server-side only).
    // One Call 3.0/4.0 requires a separate subscription and is not used here.
    'weather' => [
        'provider' => 'openweather',
        'current_url' => 'https://api.openweathermap.org/data/2.5/weather',
        'forecast_url' => 'https://api.openweathermap.org/data/2.5/forecast',
    ],
    // Legacy key kept for any older references; unused by WeatherService.
    'weather_api' => 'https://api.openweathermap.org/data/2.5/weather',
    // Local farm hero images for the landing slideshow (first slide = primary HD farm/laptop image)
    'hero_image' => 'img/farmwise-hero-laptop.jpg',
    'hero_images' => [
        'img/farmwise-hero-laptop.jpg',
        'img/livestock-farm-hero.jpg',
        'img/crops-farm-hero.jpg',
        'img/farm-overview.jpg',
    ],
];
