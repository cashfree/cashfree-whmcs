<?php
require_once __DIR__ . '/vendor/autoload.php';
\Sentry\init(['dsn' => 'https://9df3dcf280ee43b1842a230c3a9592b9@o330525.ingest.sentry.io/4504864054640640']);

try {
    throw new Exception("Failed to decode JSON response");
} catch (Exception $e) {
    // Catch the exception and log an error
    \Sentry\captureException($e);
}

