<?php

declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Argentina/Cordoba');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const STATUSES = [
    'Ingresada', 'Pendiente de revisión', 'En diagnóstico', 'Diagnóstico cargado',
    'Presupuesto pendiente', 'Esperando aprobación del cliente', 'Aprobado',
    'En reparación', 'Esperando repuestos', 'Repuesto solicitado', 'Repuesto recibido',
    'Con complicaciones', 'Prueba final', 'Lista para retirar', 'Entregada', 'Cancelada',
];
const PRIORITIES = ['baja', 'normal', 'alta', 'urgente'];

function app_name(): string { return getenv('APP_NAME') ?: 'Lopez Motos'; }

function env_first(array $names, ?string $default = null): ?string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== '') {
            return (string)$value;
        }
    }

    return $default;
}

function db(): PDO
{
    static $pdo;

    if (!$pdo) {
        // Acepta tanto las variables propias de la aplicación como los nombres
        // nativos que expone el servicio MySQL de Railway.
        $host = env_first(['DB_HOST', 'MYSQLHOST'], 'mysql');
        $port = env_first(['DB_PORT', 'MYSQLPORT'], '3306');
        $name = env_first(['DB_NAME', 'MYSQLDATABASE'], 'lopez_motos');
        $user = env_first(['DB_USER', 'MYSQLUSER'], 'usuario');
        $password = env_first(['DB_PASSWORD', 'MYSQLPASSWORD'], 'password');

        $isRailway = env_first(['RAILWAY_ENVIRONMENT_ID', 'RAILWAY_PROJECT_ID']) !== null;
        if ($isRailway && ($user === 'usuario' || $password === 'password')) {
            throw new RuntimeException(
                'La conexión MySQL de Railway no está configurada. Añadí referencias para DB_HOST, DB_PORT, DB_NAME, DB_USER y DB_PASSWORD en el servicio web.'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        $pdo = new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        // Railway/MySQL suele trabajar en UTC. Ajustamos esta conexión a Argentina.
        $pdo->exec("SET time_zone = '-03:00'");
    }

    return $pdo;
}

function auth(): ?array { return $_SESSION['user'] ?? null; }
function require_auth(): void { if (!auth()) redirect('login.php'); }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header("Location: {$url}"); exit; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function post(string $key, mixed $default = ''): mixed { return $_POST[$key] ?? $default; }
function clean_text(mixed $value): string { return trim(preg_replace('/\s+/u', ' ', (string)$value) ?? ''); }
function nullable_text(mixed $value): ?string { $v = trim((string)$value); return $v === '' ? null : $v; }
function upper_identifier(mixed $value): string
{
    $raw = trim((string)$value);
    $upper = function_exists('mb_strtoupper') ? mb_strtoupper($raw, 'UTF-8') : strtoupper($raw);
    return preg_replace('/\s+/u', '', $upper) ?? '';
}
function decimal_value(mixed $value, float $default = 0): float
{
    if ($value === '' || $value === null) return $default;
    $v = str_replace(',', '.', (string)$value);
    return is_numeric($v) ? (float)$v : $default;
}
function whole_number_value(mixed $value, int $default = 0): int
{
    if ($value === '' || $value === null) return $default;

    $raw = preg_replace('/\s+/u', '', trim((string)$value)) ?? '';
    if ($raw === '') return $default;

    // En Argentina, 14.500 suele representar catorce mil quinientos.
    if (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/', $raw)) {
        $raw = str_replace('.', '', $raw);
    } else {
        $raw = str_replace(',', '.', $raw);
    }

    return is_numeric($raw) ? (int)round((float)$raw) : $default;
}
function units(int|float|string|null $value): string
{
    return number_format((int)round((float)$value), 0, ',', '.');
}
function int_value(mixed $value, ?int $default = null): ?int
{
    if ($value === '' || $value === null) return $default;
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $default;
}
function money(float|int|string|null $value): string { return '$' . number_format((int)round((float)$value), 0, ',', '.'); }
function date_ar(?string $value, bool $withTime = false): string
{
    if (!$value) return '—';
    $ts = strtotime($value);
    return $ts ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts) : $value;
}
function order_code(): string { return 'LM-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)); }
function token(): string { return bin2hex(random_bytes(20)); }
function public_base_url(): string
{
    $configuredUrl = trim((string)(getenv('PUBLIC_BASE_URL') ?: ''));
    if ($configuredUrl !== '') return rtrim($configuredUrl, '/');

    $railwayDomain = trim((string)(getenv('RAILWAY_PUBLIC_DOMAIN') ?: ''));
    if ($railwayDomain !== '') return 'https://' . rtrim($railwayDomain, '/');

    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $forwardedProto !== ''
        ? trim(explode(',', $forwardedProto)[0])
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    $forwardedHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    $host = $forwardedHost !== ''
        ? trim(explode(',', $forwardedHost)[0])
        : trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost:8080'));

    return $scheme . '://' . $host;
}
function wa_link(string $phone, string $message): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) $digits = substr($digits, 1);
    if (!str_starts_with($digits, '54')) $digits = '54' . $digits;
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf_token'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">'; }
function verify_csrf(): void
{
    $provided = (string)($_POST['csrf_token'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) throw new RuntimeException('La sesión del formulario venció. Actualizá la página e intentá nuevamente.');
}
function flash(string $type, string $message): void { $_SESSION['flash'][] = compact('type', 'message'); }
function pull_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function active_nav(string $file): string { return basename($_SERVER['PHP_SELF'] ?? '') === $file ? 'is-active' : ''; }
function status_tone(string $status): string
{
    return match ($status) {
        'Entregada' => 'success',
        'Lista para retirar', 'Prueba final', 'Aprobado' => 'ready',
        'Cancelada', 'Con complicaciones' => 'danger',
        'Esperando repuestos', 'Repuesto solicitado', 'Presupuesto pendiente', 'Esperando aprobación del cliente' => 'warning',
        default => 'neutral',
    };
}
function priority_tone(string $priority): string
{
    return match ($priority) { 'urgente' => 'danger', 'alta' => 'warning', 'baja' => 'neutral', default => 'info' };
}

function ensure_column(string $table, string $column, string $sql): void
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        throw new InvalidArgumentException('Nombre de tabla o columna inválido.');
    }

    // MySQL no admite marcadores de posición dentro de SHOW COLUMNS ... LIKE ?.
    // information_schema sí permite parámetros preparados y funciona en MySQL/MariaDB.
    $st = db()->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $st->execute([$table, $column]);

    if (!$st->fetchColumn()) {
        db()->exec($sql);
    }
}
function ensure_schema(): void
{
    static $done = false; if ($done) return; $done = true; $pdo = db();
    ensure_column('clients', 'active', 'ALTER TABLE clients ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes');
    ensure_column('clients', 'updated_at', 'ALTER TABLE clients ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    ensure_column('vehicles', 'active', 'ALTER TABLE vehicles ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER km');
    ensure_column('vehicles', 'updated_at', 'ALTER TABLE vehicles ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    ensure_column('parts', 'category', 'ALTER TABLE parts ADD COLUMN category VARCHAR(120) NULL AFTER sku');
    ensure_column('parts', 'min_stock', 'ALTER TABLE parts ADD COLUMN min_stock INT UNSIGNED NOT NULL DEFAULT 0 AFTER stock');
    ensure_column('parts', 'photo_path', 'ALTER TABLE parts ADD COLUMN photo_path VARCHAR(255) NULL AFTER supplier');
    ensure_column('parts', 'notes', 'ALTER TABLE parts ADD COLUMN notes TEXT NULL AFTER photo_path');
    ensure_column('parts', 'created_at', 'ALTER TABLE parts ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER active');
    ensure_column('parts', 'updated_at', 'ALTER TABLE parts ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    ensure_column('budget_items', 'part_id', 'ALTER TABLE budget_items ADD COLUMN part_id INT NULL AFTER order_id');
    ensure_column('budget_items', 'stock_applied', 'ALTER TABLE budget_items ADD COLUMN stock_applied INT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_price');
    ensure_column('work_orders', 'budget_approved_at', 'ALTER TABLE work_orders ADD COLUMN budget_approved_at DATETIME NULL AFTER total_estimated');
    ensure_column('work_orders', 'budget_approved_total', 'ALTER TABLE work_orders ADD COLUMN budget_approved_total BIGINT UNSIGNED NULL AFTER budget_approved_at');
    ensure_column('work_orders', 'budget_approved_ip', 'ALTER TABLE work_orders ADD COLUMN budget_approved_ip VARCHAR(45) NULL AFTER budget_approved_total');
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (id INT AUTO_INCREMENT PRIMARY KEY, part_id INT NOT NULL, order_id INT NULL, budget_item_id INT NULL, user_id INT NULL, movement_type ENUM('entrada','salida','ajuste','devolucion') NOT NULL, quantity INT UNSIGNED NOT NULL, stock_before INT UNSIGNED NOT NULL DEFAULT 0, stock_after INT UNSIGNED NOT NULL DEFAULT 0, notes VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(part_id), INDEX(order_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, order_update_id INT NULL, client_id INT NOT NULL, channel VARCHAR(40) NOT NULL DEFAULT 'webhook', destination VARCHAR(180) NULL, subject VARCHAR(180) NULL, message TEXT NOT NULL, status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending', provider_response TEXT NULL, sent_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(order_id), INDEX(status))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_migrations (migration_key VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $migration = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key=? LIMIT 1');
    $migration->execute(['whole_numbers_v1']);
    if (!$migration->fetchColumn()) {
        // Redondea valores anteriores y deja unidades/precios como enteros en MySQL.
        $pdo->exec('UPDATE parts SET stock=ROUND(stock),min_stock=ROUND(min_stock),buy_price=ROUND(buy_price),sell_price=ROUND(sell_price)');
        $pdo->exec('UPDATE budget_items SET quantity=ROUND(quantity),unit_price=ROUND(unit_price),stock_applied=ROUND(stock_applied)');
        $pdo->exec('UPDATE work_orders SET total_estimated=ROUND(COALESCE(total_estimated,0)),budget_approved_total=IFNULL(ROUND(budget_approved_total),NULL),total_final=ROUND(COALESCE(total_final,0))');
        $pdo->exec('UPDATE stock_movements SET quantity=ROUND(quantity),stock_before=ROUND(stock_before),stock_after=ROUND(stock_after)');

        $pdo->exec('ALTER TABLE parts MODIFY stock INT UNSIGNED NOT NULL DEFAULT 0, MODIFY min_stock INT UNSIGNED NOT NULL DEFAULT 0, MODIFY buy_price BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY sell_price BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE budget_items MODIFY quantity INT UNSIGNED NOT NULL DEFAULT 1, MODIFY unit_price BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY stock_applied INT UNSIGNED NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE work_orders MODIFY total_estimated BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY budget_approved_total BIGINT UNSIGNED NULL, MODIFY total_final BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE stock_movements MODIFY quantity INT UNSIGNED NOT NULL, MODIFY stock_before INT UNSIGNED NOT NULL DEFAULT 0, MODIFY stock_after INT UNSIGNED NOT NULL DEFAULT 0');
        $pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute(['whole_numbers_v1']);
    }

    $pdo->exec("UPDATE vehicles SET plate=UPPER(REPLACE(TRIM(plate),' ','')) WHERE plate IS NOT NULL AND BINARY plate<>BINARY UPPER(REPLACE(TRIM(plate),' ',''))");
    $pdo->exec("UPDATE vehicles SET engine_number=UPPER(REPLACE(TRIM(engine_number),' ','')) WHERE engine_number IS NOT NULL AND BINARY engine_number<>BINARY UPPER(REPLACE(TRIM(engine_number),' ',''))");
    $pdo->exec("UPDATE vehicles SET chassis_number=UPPER(REPLACE(TRIM(chassis_number),' ','')) WHERE chassis_number IS NOT NULL AND BINARY chassis_number<>BINARY UPPER(REPLACE(TRIM(chassis_number),' ',''))");
}

function add_stock_movement(int $partId, ?int $orderId, ?int $budgetItemId, string $type, int $quantity, int $before, int $after, ?string $notes = null): void
{
    $st = db()->prepare('INSERT INTO stock_movements (part_id,order_id,budget_item_id,user_id,movement_type,quantity,stock_before,stock_after,notes) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([$partId,$orderId,$budgetItemId,auth()['id'] ?? null,$type,$quantity,$before,$after,$notes]);
}

function notify_customer(array $order, ?int $updateId, string $message): void
{
    $pdo = db(); $subject = app_name() . ' - Actualización de tu moto ' . $order['code'];
    $destination = $order['email'] ?: $order['phone']; $channel = getenv('NOTIFY_CHANNEL') ?: 'webhook';
    $st = $pdo->prepare('INSERT INTO notification_queue (order_id,order_update_id,client_id,channel,destination,subject,message) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$order['id'],$updateId,$order['client_id'],$channel,$destination,$subject,$message]);
    $id = (int)$pdo->lastInsertId(); $result = send_notification_provider($channel,$order,$subject,$message);
    $st = $pdo->prepare('UPDATE notification_queue SET status=?,provider_response=?,sent_at=? WHERE id=?');
    $st->execute([$result['ok']?'sent':'failed',$result['response'],$result['ok']?date('Y-m-d H:i:s'):null,$id]);
}
function send_notification_provider(string $channel, array $order, string $subject, string $message): array
{
    return match ($channel) {
        'webhook' => send_webhook_notification($order,$subject,$message),
        'whatsapp_cloud' => send_whatsapp_cloud_notification($order,$message),
        default => ['ok'=>false,'response'=>'Canal no configurado.'],
    };
}
function send_webhook_notification(array $order, string $subject, string $message): array
{
    $url = trim((string)(getenv('NOTIFY_WEBHOOK_URL') ?: ''));
    if ($url === '') return ['ok'=>false,'response'=>'Falta NOTIFY_WEBHOOK_URL. La notificación quedó registrada.'];
    if (!filter_var($url, FILTER_VALIDATE_URL)) return ['ok'=>false,'response'=>'NOTIFY_WEBHOOK_URL no contiene una URL válida.'];

    $headers = [];
    $secret = trim((string)(getenv('NOTIFY_WEBHOOK_SECRET') ?: ''));
    if ($secret !== '') $headers[] = 'X-Lopez-Webhook-Secret: ' . $secret;

    $payload = [
        'event' => 'order.updated',
        'app' => app_name(),
        'order_code' => $order['code'],
        'client_name' => $order['client_name'],
        'phone' => $order['phone'],
        'email' => $order['email'],
        'subject' => $subject,
        'message' => $message,
        'tracking_url' => public_base_url() . '/track.php?t=' . $order['public_token'],
        'sent_at' => date(DATE_ATOM),
    ];

    return http_json_post($url, $payload, $headers);
}
function send_whatsapp_cloud_notification(array $order, string $message): array
{
    $accessToken=getenv('WHATSAPP_CLOUD_TOKEN'); $phoneId=getenv('WHATSAPP_PHONE_NUMBER_ID');
    if(!$accessToken||!$phoneId) return ['ok'=>false,'response'=>'Faltan credenciales de WhatsApp Cloud.'];
    $phone=preg_replace('/\D+/','',$order['phone'])??''; if(str_starts_with($phone,'0'))$phone=substr($phone,1); if(!str_starts_with($phone,'54'))$phone='54'.$phone;
    return http_json_post("https://graph.facebook.com/v20.0/{$phoneId}/messages",['messaging_product'=>'whatsapp','to'=>$phone,'type'=>'text','text'=>['body'=>$message]],['Authorization: Bearer '.$accessToken]);
}
function http_json_post(string $url,array $payload,array $headers=[]):array
{
    $ch=curl_init($url); $headers[]='Content-Type: application/json'; curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);
    if($body===false)return ['ok'=>false,'response'=>$error?:'Error HTTP desconocido'];
    return ['ok'=>$code>=200&&$code<300,'response'=>"HTTP {$code}: {$body}"];
}
