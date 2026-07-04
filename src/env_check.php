<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$channel = getenv('NOTIFY_CHANNEL');
$url = getenv('NOTIFY_WEBHOOK_URL');

echo "Diagnóstico de notificaciones\n";
echo "=============================\n\n";

echo 'NOTIFY_CHANNEL: ';
echo ($channel !== false && trim($channel) !== '')
    ? trim($channel)
    : 'NO CONFIGURADA';
echo "\n";

echo 'NOTIFY_WEBHOOK_URL: ';
if ($url !== false && trim($url) !== '') {
    $clean = trim($url);
    $host = parse_url($clean, PHP_URL_HOST) ?: 'URL no válida';
    echo "CONFIGURADA\n";
    echo "Host detectado: {$host}\n";
    echo 'Longitud: ' . strlen($clean) . " caracteres\n";
} else {
    echo "NO CONFIGURADA\n";
}

echo "\nRailway:\n";
echo 'RAILWAY_ENVIRONMENT_NAME: ' . (getenv('RAILWAY_ENVIRONMENT_NAME') ?: 'No detectado') . "\n";
echo 'RAILWAY_SERVICE_NAME: ' . (getenv('RAILWAY_SERVICE_NAME') ?: 'No detectado') . "\n";
echo 'Fecha del contenedor: ' . date('Y-m-d H:i:s P') . "\n";
