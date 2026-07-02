<?php
session_start();

const STATUSES = [
    'Ingresada',
    'Pendiente de revisión',
    'En diagnóstico',
    'Diagnóstico cargado',
    'Presupuesto pendiente',
    'Esperando aprobación del cliente',
    'Aprobado',
    'En reparación',
    'Esperando repuestos',
    'Repuesto solicitado',
    'Repuesto recibido',
    'Con complicaciones',
    'Prueba final',
    'Lista para retirar',
    'Entregada',
    'Cancelada',
];

function app_name(): string
{
    return getenv('APP_NAME') ?: 'Lopez Motos';
}

function db(): PDO
{
    static $pdo;

    if (!$pdo) {
        $host = getenv('DB_HOST') ?: 'mysql';
        $db = getenv('DB_NAME') ?: 'lopez_motos';
        $user = getenv('DB_USER') ?: 'usuario';
        $pass = getenv('DB_PASSWORD') ?: 'password';

        $pdo = new PDO(
            "mysql:host={$host};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $pdo;
}

function auth(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): void
{
    if (!auth()) {
        header('Location: login.php');
        exit;
    }
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

function order_code(): string
{
    return 'LM-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function token(): string
{
    return bin2hex(random_bytes(20));
}

function public_base_url(): string
{
    return rtrim(getenv('PUBLIC_BASE_URL') ?: 'http://localhost:8082', '/');
}

function wa_link(string $phone, string $message): string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    if (!str_starts_with($digits, '54')) {
        $digits = '54' . $digits;
    }

    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
}

function ensure_schema(): void
{
    $pdo = db();

    ensure_column('parts', 'category', "ALTER TABLE parts ADD COLUMN category VARCHAR(120) NULL AFTER sku");
    ensure_column('parts', 'photo_path', "ALTER TABLE parts ADD COLUMN photo_path VARCHAR(255) NULL AFTER supplier");
    ensure_column('parts', 'notes', "ALTER TABLE parts ADD COLUMN notes TEXT NULL AFTER photo_path");
    ensure_column('parts', 'min_stock', "ALTER TABLE parts ADD COLUMN min_stock DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER stock");

    ensure_column('budget_items', 'part_id', "ALTER TABLE budget_items ADD COLUMN part_id INT NULL AFTER order_id");
    ensure_column('budget_items', 'stock_applied', "ALTER TABLE budget_items ADD COLUMN stock_applied DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER unit_price");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            part_id INT NOT NULL,
            order_id INT NULL,
            budget_item_id INT NULL,
            user_id INT NULL,
            movement_type ENUM('entrada','salida','ajuste','devolucion') NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            stock_before DECIMAL(10,2) NOT NULL DEFAULT 0,
            stock_after DECIMAL(10,2) NOT NULL DEFAULT 0,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (part_id),
            INDEX (order_id)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notification_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            order_update_id INT NULL,
            client_id INT NOT NULL,
            channel VARCHAR(40) NOT NULL DEFAULT 'webhook',
            destination VARCHAR(180) NULL,
            subject VARCHAR(180) NULL,
            message TEXT NOT NULL,
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            provider_response TEXT NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (order_id),
            INDEX (status)
        )"
    );
}

function ensure_column(string $table, string $column, string $sql): void
{
    $stmt = db()->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$column]);

    if (!$stmt->fetch()) {
        db()->exec($sql);
    }
}

function add_stock_movement(
    int $partId,
    ?int $orderId,
    ?int $budgetItemId,
    string $type,
    float $quantity,
    float $before,
    float $after,
    ?string $notes = null
): void {
    $stmt = db()->prepare(
        'INSERT INTO stock_movements
            (part_id, order_id, budget_item_id, user_id, movement_type, quantity, stock_before, stock_after, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $partId,
        $orderId,
        $budgetItemId,
        auth()['id'] ?? null,
        $type,
        $quantity,
        $before,
        $after,
        $notes,
    ]);
}

function notify_customer(array $order, ?int $updateId, string $message): void
{
    $pdo = db();
    $subject = app_name() . ' - Actualización de tu moto ' . $order['code'];
    $destination = $order['email'] ?: $order['phone'];
    $channel = getenv('NOTIFY_CHANNEL') ?: 'webhook';

    $stmt = $pdo->prepare(
        'INSERT INTO notification_queue
            (order_id, order_update_id, client_id, channel, destination, subject, message)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$order['id'], $updateId, $order['client_id'], $channel, $destination, $subject, $message]);

    $queueId = (int) $pdo->lastInsertId();
    $result = send_notification_provider($channel, $order, $subject, $message);

    $stmt = $pdo->prepare(
        'UPDATE notification_queue
         SET status = ?, provider_response = ?, sent_at = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $result['ok'] ? 'sent' : 'failed',
        $result['response'],
        $result['ok'] ? date('Y-m-d H:i:s') : null,
        $queueId,
    ]);
}

function send_notification_provider(string $channel, array $order, string $subject, string $message): array
{
    if ($channel === 'webhook') {
        return send_webhook_notification($order, $subject, $message);
    }

    if ($channel === 'whatsapp_cloud') {
        return send_whatsapp_cloud_notification($order, $message);
    }

    return [
        'ok' => false,
        'response' => 'Canal no configurado. Usá NOTIFY_CHANNEL=webhook o NOTIFY_CHANNEL=whatsapp_cloud.',
    ];
}

function send_webhook_notification(array $order, string $subject, string $message): array
{
    $url = getenv('NOTIFY_WEBHOOK_URL');

    if (!$url) {
        return [
            'ok' => false,
            'response' => 'Falta NOTIFY_WEBHOOK_URL. La notificación quedó registrada en cola.',
        ];
    }

    return http_json_post($url, [
        'app' => app_name(),
        'order_code' => $order['code'],
        'client_name' => $order['client_name'],
        'phone' => $order['phone'],
        'email' => $order['email'],
        'subject' => $subject,
        'message' => $message,
        'tracking_url' => public_base_url() . '/track.php?t=' . $order['public_token'],
    ]);
}

function send_whatsapp_cloud_notification(array $order, string $message): array
{
    $token = getenv('WHATSAPP_CLOUD_TOKEN');
    $phoneId = getenv('WHATSAPP_PHONE_NUMBER_ID');

    if (!$token || !$phoneId) {
        return [
            'ok' => false,
            'response' => 'Faltan WHATSAPP_CLOUD_TOKEN y/o WHATSAPP_PHONE_NUMBER_ID.',
        ];
    }

    $phone = preg_replace('/\D+/', '', $order['phone']);
    if (str_starts_with($phone, '0')) {
        $phone = substr($phone, 1);
    }
    if (!str_starts_with($phone, '54')) {
        $phone = '54' . $phone;
    }

    $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";

    return http_json_post(
        $url,
        [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'text',
            'text' => ['body' => $message],
        ],
        ['Authorization: Bearer ' . $token]
    );
}

function http_json_post(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    $headers[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'response' => $error ?: 'Error HTTP desconocido'];
    }

    return [
        'ok' => $code >= 200 && $code < 300,
        'response' => "HTTP {$code}: {$body}",
    ];
}
?>
