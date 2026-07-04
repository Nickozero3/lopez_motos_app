<?php

declare(strict_types=1);

function envFirst(array $names, ?string $default = null): ?string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string) $value) !== '') {
            return (string) $value;
        }
    }

    return $default;
}

$host = envFirst(['DB_HOST', 'MYSQLHOST'], 'mysql');
$port = envFirst(['DB_PORT', 'MYSQLPORT'], '3306');
$name = envFirst(['DB_NAME', 'MYSQLDATABASE'], 'lopez_motos');
$user = envFirst(['DB_USER', 'MYSQLUSER'], 'usuario');
$password = envFirst(['DB_PASSWORD', 'MYSQLPASSWORD'], 'password');
$sqlPath = '/opt/lopez-motos/init.sql';

if (!is_file($sqlPath)) {
    fwrite(STDERR, "[db-init] No se encontró {$sqlPath}.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $host,
    $port,
    $name
);

$pdo = null;
$lastError = null;

for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $pdo = new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
        break;
    } catch (Throwable $error) {
        $lastError = $error;
        fwrite(
            STDERR,
            sprintf(
                "[db-init] MySQL todavía no está disponible (%d/30): %s\n",
                $attempt,
                $error->getMessage()
            )
        );
        sleep(2);
    }
}

if (!$pdo instanceof PDO) {
    fwrite(
        STDERR,
        '[db-init] No fue posible conectar con MySQL: ' .
        ($lastError?->getMessage() ?? 'error desconocido') . "\n"
    );
    exit(1);
}

$sql = file_get_contents($sqlPath);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "[db-init] El archivo init.sql está vacío o no se pudo leer.\n");
    exit(1);
}

try {
    $pdo->exec($sql);
    fwrite(STDOUT, "[db-init] Esquema verificado correctamente en la base {$name}.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[db-init] Error ejecutando init.sql: {$error->getMessage()}\n");
    exit(1);
}
