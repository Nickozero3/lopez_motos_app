<?php
require_once 'config.php';

require_auth();
ensure_schema();

$pdo = db();
$orderId = (int) ($_GET['id'] ?? 0);

function find_order(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            wo.*,
            c.id AS client_id,
            c.name AS client_name,
            c.phone,
            c.email,
            c.dni,
            v.type,
            v.brand,
            v.model,
            v.plate,
            v.year,
            v.cc,
            v.color,
            v.engine_number,
            v.chassis_number,
            v.km
         FROM work_orders wo
         JOIN clients c ON c.id = wo.client_id
         JOIN vehicles v ON v.id = wo.vehicle_id
         WHERE wo.id = ?'
    );
    $stmt->execute([$orderId]);

    $order = $stmt->fetch();
    if (!$order) {
        die('Orden no encontrada');
    }

    return $order;
}

function refresh_total(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare(
        'UPDATE work_orders
         SET total_estimated = COALESCE((
             SELECT SUM(quantity * unit_price)
             FROM budget_items
             WHERE order_id = ?
         ), 0)
         WHERE id = ?'
    );
    $stmt->execute([$orderId, $orderId]);
}

function add_stock_part_to_order(PDO $pdo, int $orderId): void
{
    $partId = (int) ($_POST['part_id'] ?? 0);
    $quantity = max(0.01, (float) ($_POST['quantity'] ?? 1));

    $stmt = $pdo->prepare('SELECT * FROM parts WHERE id = ? AND active = 1 FOR UPDATE');
    $stmt->execute([$partId]);
    $part = $stmt->fetch();

    if (!$part) {
        throw new RuntimeException('El repuesto seleccionado no existe.');
    }

    if ((float) $part['stock'] < $quantity) {
        throw new RuntimeException('No hay stock suficiente para agregar ese repuesto a la reparación.');
    }

    $description = trim($_POST['description'] ?? '') ?: $part['name'];
    $unitPrice = (float) ($_POST['unit_price'] ?? $part['sell_price']);
    $stockBefore = (float) $part['stock'];
    $stockAfter = $stockBefore - $quantity;

    $stmt = $pdo->prepare(
        'INSERT INTO budget_items
            (order_id, part_id, item_type, description, quantity, unit_price, stock_applied, approved)
         VALUES (?, ?, "repuesto", ?, ?, ?, ?, 0)'
    );
    $stmt->execute([$orderId, $partId, $description, $quantity, $unitPrice, $quantity]);

    $budgetItemId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('UPDATE parts SET stock = ? WHERE id = ?');
    $stmt->execute([$stockAfter, $partId]);

    add_stock_movement(
        $partId,
        $orderId,
        $budgetItemId,
        'salida',
        $quantity,
        $stockBefore,
        $stockAfter,
        'Usado en reparación'
    );
}

function update_budget_item(PDO $pdo, int $orderId): void
{
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $quantity = max(0.01, (float) ($_POST['quantity'] ?? 1));
    $unitPrice = max(0, (float) ($_POST['unit_price'] ?? 0));

    $stmt = $pdo->prepare('SELECT * FROM budget_items WHERE id = ? AND order_id = ? FOR UPDATE');
    $stmt->execute([$itemId, $orderId]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new RuntimeException('Ítem no encontrado.');
    }

    if ($item['part_id']) {
        $partId = (int) $item['part_id'];
        $oldApplied = (float) $item['stock_applied'];
        $difference = $quantity - $oldApplied;

        $stmt = $pdo->prepare('SELECT * FROM parts WHERE id = ? FOR UPDATE');
        $stmt->execute([$partId]);
        $part = $stmt->fetch();

        if (!$part) {
            throw new RuntimeException('El repuesto asociado ya no existe.');
        }

        $stockBefore = (float) $part['stock'];
        $stockAfter = $stockBefore - $difference;

        if ($stockAfter < 0) {
            throw new RuntimeException('No hay stock suficiente para aumentar la cantidad.');
        }

        $stmt = $pdo->prepare('UPDATE parts SET stock = ? WHERE id = ?');
        $stmt->execute([$stockAfter, $partId]);

        if (abs($difference) > 0.0001) {
            add_stock_movement(
                $partId,
                $orderId,
                $itemId,
                $difference > 0 ? 'salida' : 'devolucion',
                abs($difference),
                $stockBefore,
                $stockAfter,
                'Ajuste de cantidad en reparación'
            );
        }

        $stmt = $pdo->prepare(
            'UPDATE budget_items
             SET description = ?, quantity = ?, unit_price = ?, stock_applied = ?
             WHERE id = ? AND order_id = ?'
        );
        $stmt->execute([
            trim($_POST['description'] ?? '') ?: $item['description'],
            $quantity,
            $unitPrice,
            $quantity,
            $itemId,
            $orderId,
        ]);

        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE budget_items
         SET item_type = ?, description = ?, quantity = ?, unit_price = ?
         WHERE id = ? AND order_id = ?'
    );
    $stmt->execute([
        $_POST['item_type'],
        trim($_POST['description'] ?? ''),
        $quantity,
        $unitPrice,
        $itemId,
        $orderId,
    ]);
}

function delete_budget_item(PDO $pdo, int $orderId): void
{
    $itemId = (int) ($_POST['item_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM budget_items WHERE id = ? AND order_id = ? FOR UPDATE');
    $stmt->execute([$itemId, $orderId]);
    $item = $stmt->fetch();

    if (!$item) {
        return;
    }

    if ($item['part_id'] && (float) $item['stock_applied'] > 0) {
        $partId = (int) $item['part_id'];
        $quantity = (float) $item['stock_applied'];

        $stmt = $pdo->prepare('SELECT stock FROM parts WHERE id = ? FOR UPDATE');
        $stmt->execute([$partId]);
        $part = $stmt->fetch();
        $stockBefore = (float) ($part['stock'] ?? 0);
        $stockAfter = $stockBefore + $quantity;

        $stmt = $pdo->prepare('UPDATE parts SET stock = ? WHERE id = ?');
        $stmt->execute([$stockAfter, $partId]);

        add_stock_movement(
            $partId,
            $orderId,
            $itemId,
            'devolucion',
            $quantity,
            $stockBefore,
            $stockAfter,
            'Ítem quitado de la reparación'
        );
    }

    $stmt = $pdo->prepare('DELETE FROM budget_items WHERE id = ? AND order_id = ?');
    $stmt->execute([$itemId, $orderId]);
}

$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $pdo->beginTransaction();

            $order = find_order($pdo, $orderId);
            $status = $_POST['status'];
            $clientMessage = trim($_POST['client_message'] ?? '');

            $stmt = $pdo->prepare(
                'UPDATE work_orders
                 SET current_status = ?,
                     diagnosis = COALESCE(NULLIF(?, ""), diagnosis),
                     estimated_delivery = COALESCE(NULLIF(?, ""), estimated_delivery),
                     total_estimated = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $status,
                $_POST['diagnosis'] ?? '',
                $_POST['estimated_delivery'] ?? '',
                $_POST['total_estimated'] ?: 0,
                $orderId,
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO order_updates
                    (order_id, user_id, status, internal_message, client_message, visible_client, notify_client)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $orderId,
                auth()['id'],
                $status,
                $_POST['internal_message'] ?: null,
                $clientMessage ?: null,
                isset($_POST['visible_client']) ? 1 : 0,
                isset($_POST['notify_client']) ? 1 : 0,
            ]);

            $updateId = (int) $pdo->lastInsertId();
            $pdo->commit();

            if (isset($_POST['notify_client']) && $clientMessage !== '') {
                $freshOrder = find_order($pdo, $orderId);
                $trackingUrl = public_base_url() . '/track.php?t=' . $freshOrder['public_token'];
                notify_customer($freshOrder, $updateId, $clientMessage . "\n\nSeguimiento: " . $trackingUrl);
            }

            redirect('order_view.php?id=' . $orderId);
        }

        if ($action === 'budget') {
            $stmt = $pdo->prepare(
                'INSERT INTO budget_items
                    (order_id, item_type, description, quantity, unit_price, approved)
                 VALUES (?, ?, ?, ?, ?, 0)'
            );
            $stmt->execute([
                $orderId,
                $_POST['item_type'],
                $_POST['description'],
                $_POST['quantity'],
                $_POST['unit_price'],
            ]);

            refresh_total($pdo, $orderId);
            redirect('order_view.php?id=' . $orderId);
        }

        if ($action === 'add_stock_part') {
            $pdo->beginTransaction();
            add_stock_part_to_order($pdo, $orderId);
            refresh_total($pdo, $orderId);
            $pdo->commit();
            redirect('order_view.php?id=' . $orderId);
        }

        if ($action === 'budget_update') {
            $pdo->beginTransaction();
            update_budget_item($pdo, $orderId);
            refresh_total($pdo, $orderId);
            $pdo->commit();
            redirect('order_view.php?id=' . $orderId);
        }

        if ($action === 'budget_delete') {
            $pdo->beginTransaction();
            delete_budget_item($pdo, $orderId);
            refresh_total($pdo, $orderId);
            $pdo->commit();
            redirect('order_view.php?id=' . $orderId);
        }
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $exception->getMessage();
}

$order = find_order($pdo, $orderId);

$stmt = $pdo->prepare(
    'SELECT ou.*, u.name AS user_name
     FROM order_updates ou
     LEFT JOIN users u ON u.id = ou.user_id
     WHERE order_id = ?
     ORDER BY ou.created_at DESC'
);
$stmt->execute([$orderId]);
$updates = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT bi.*, p.photo_path, p.stock AS current_stock
     FROM budget_items bi
     LEFT JOIN parts p ON p.id = bi.part_id
     WHERE bi.order_id = ?
     ORDER BY bi.id DESC'
);
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

$parts = $pdo
    ->query('SELECT * FROM parts WHERE active = 1 ORDER BY name ASC')
    ->fetchAll();

$stmt = $pdo->prepare(
    'SELECT *
     FROM notification_queue
     WHERE order_id = ?
     ORDER BY created_at DESC
     LIMIT 8'
);
$stmt->execute([$orderId]);
$notifications = $stmt->fetchAll();

$sum = 0;
foreach ($items as $item) {
    $sum += (float) $item['quantity'] * (float) $item['unit_price'];
}

$public = public_base_url() . '/track.php?t=' . $order['public_token'];
$lastMessage = $updates[0]['client_message']
    ?? 'Actualización de tu moto en Lopez Motos: ' . $order['current_status'];
$whatsapp = wa_link($order['phone'], $lastMessage . ' ' . $public);

include 'partials/header.php';
?>
<div class="grid">
    <section class="card span8">
        <div class="actions">
            <h1 style="flex: 1"><?= h($order['code']) ?></h1>
            <a class="btn good" target="_blank" href="<?= h($whatsapp) ?>">WhatsApp manual</a>
            <a class="btn" target="_blank" href="track.php?t=<?= h($order['public_token']) ?>">Vista cliente</a>
        </div>

        <?php if ($error): ?>
            <div class="notice error"><?= h($error) ?></div>
        <?php endif; ?>

        <p><span class="status"><?= h($order['current_status']) ?></span></p>
        <p class="muted">
            Cliente: <b><?= h($order['client_name']) ?></b> · <?= h($order['phone']) ?> ·
            Moto: <b><?= h($order['brand'] . ' ' . $order['model']) ?></b> <?= h($order['plate']) ?>
        </p>

        <h2>Actualizar estado</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">

            <label>Estado</label>
            <select name="status">
                <?php foreach (STATUSES as $status): ?>
                    <option <?= $status === $order['current_status'] ? 'selected' : '' ?>><?= h($status) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Diagnóstico</label>
            <textarea name="diagnosis" placeholder="Ej: falla de regulador, batería descargada, carburador sucio..."><?= h($order['diagnosis']) ?></textarea>

            <label>Mensaje interno</label>
            <textarea name="internal_message" placeholder="Detalle técnico para el taller"></textarea>

            <label>Mensaje visible para cliente</label>
            <textarea name="client_message" placeholder="Mensaje claro para enviar automáticamente al dueño"></textarea>

            <label>Fecha estimada de entrega</label>
            <input type="date" name="estimated_delivery" value="<?= h($order['estimated_delivery']) ?>">

            <label>Total estimado</label>
            <input type="number" step="0.01" name="total_estimated" value="<?= h($sum) ?>">

            <label class="checkline">
                <input type="checkbox" name="visible_client" checked>
                Visible para el cliente
            </label>

            <label class="checkline">
                <input type="checkbox" name="notify_client" checked>
                Enviar notificación automática
            </label>

            <button class="btn primary">Guardar actualización</button>
        </form>
    </section>

    <aside class="card span4">
        <h2>Seguimiento público</h2>
        <p class="muted">Compartí este link o abrilo como QR desde el celular.</p>
        <input value="<?= h($public) ?>" readonly>
        <button class="btn" data-copy="<?= h($public) ?>">Copiar link</button>

        <h2>Problema declarado</h2>
        <p><?= nl2br(h($order['problem_reported'])) ?></p>

        <h2>Presupuesto</h2>
        <div class="kpi">$<?= number_format($sum, 2, ',', '.') ?></div>
    </aside>

    <section class="card span6">
        <h2>Agregar repuesto desde stock</h2>
        <div class="notice">
            Al agregar un repuesto acá, se descuenta automáticamente del stock. Si lo borrás de la orden,
            el stock vuelve a sumarse.
        </div>

        <form method="post" class="stock-add-form">
            <input type="hidden" name="action" value="add_stock_part">

            <label>Buscar repuesto</label>
            <select name="part_id" id="partPicker" required>
                <option value="">Seleccionar producto del stock...</option>
                <?php foreach ($parts as $part): ?>
                    <option
                        value="<?= h($part['id']) ?>"
                        data-name="<?= h($part['name']) ?>"
                        data-price="<?= h($part['sell_price']) ?>"
                        data-stock="<?= h($part['stock']) ?>"
                    >
                        <?= h($part['name']) ?> · Stock: <?= h($part['stock']) ?> · $<?= number_format((float) $part['sell_price'], 2, ',', '.') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Descripción en la reparación</label>
            <input name="description" id="stockDescription" placeholder="Se completa al seleccionar stock">

            <div class="form-row">
                <div>
                    <label>Cantidad</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" value="1">
                </div>
                <div>
                    <label>Precio venta</label>
                    <input type="number" step="0.01" min="0" name="unit_price" id="stockPrice" value="0">
                </div>
            </div>

            <button class="btn primary">Agregar a reparación</button>
        </form>
    </section>

    <section class="card span6">
        <h2>Mano de obra / otros ítems</h2>
        <form method="post">
            <input type="hidden" name="action" value="budget">

            <label>Tipo</label>
            <select name="item_type">
                <option value="mano_obra">Mano de obra</option>
                <option value="otro">Otro</option>
            </select>

            <label>Descripción</label>
            <input name="description" required>

            <div class="form-row">
                <div>
                    <label>Cantidad</label>
                    <input type="number" step="0.01" name="quantity" value="1">
                </div>
                <div>
                    <label>Precio unitario</label>
                    <input type="number" step="0.01" name="unit_price" value="0">
                </div>
            </div>

            <button class="btn">Agregar ítem</button>
        </form>
    </section>

    <section class="card span8">
        <h2>Ítems de la reparación</h2>
        <table>
            <tr>
                <th>Detalle editable</th>
                <th>Total</th>
                <th></th>
            </tr>

            <?php foreach ($items as $item): ?>
                <tr>
                    <td colspan="3">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="budget_update">
                            <input type="hidden" name="item_id" value="<?= h($item['id']) ?>">

                            <?php if ($item['photo_path']): ?>
                                <img class="item-photo" src="<?= h($item['photo_path']) ?>" alt="Foto del repuesto">
                            <?php endif; ?>

                            <?php if ($item['part_id']): ?>
                                <span class="pill">Stock</span>
                                <input type="hidden" name="item_type" value="repuesto">
                            <?php else: ?>
                                <select name="item_type" class="mini">
                                    <option value="mano_obra" <?= $item['item_type'] === 'mano_obra' ? 'selected' : '' ?>>Mano de obra</option>
                                    <option value="otro" <?= $item['item_type'] === 'otro' ? 'selected' : '' ?>>Otro</option>
                                </select>
                            <?php endif; ?>

                            <input name="description" value="<?= h($item['description']) ?>" required>
                            <input class="mini" type="number" step="0.01" min="0.01" name="quantity" value="<?= h($item['quantity']) ?>">
                            <input class="mini" type="number" step="0.01" min="0" name="unit_price" value="<?= h($item['unit_price']) ?>">
                            <b>$<?= number_format((float) $item['quantity'] * (float) $item['unit_price'], 2, ',', '.') ?></b>
                            <button class="btn">Guardar</button>
                        </form>

                        <form method="post" onsubmit="return confirm('¿Borrar este ítem?')">
                            <input type="hidden" name="action" value="budget_delete">
                            <input type="hidden" name="item_id" value="<?= h($item['id']) ?>">
                            <button class="btn bad">Borrar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="card span4">
        <h2>Notificaciones</h2>
        <p class="muted">Registro de envíos automáticos al cliente.</p>

        <div class="timeline">
            <?php foreach ($notifications as $notification): ?>
                <div class="event">
                    <b><?= h($notification['status']) ?></b>
                    <br>
                    <small class="muted"><?= h($notification['created_at']) ?> · <?= h($notification['channel']) ?></small>
                    <p><?= nl2br(h($notification['message'])) ?></p>
                </div>
            <?php endforeach; ?>

            <?php if (!$notifications): ?>
                <p class="muted">Todavía no hay notificaciones automáticas.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="card span12">
        <h2>Historial</h2>
        <div class="timeline">
            <?php foreach ($updates as $update): ?>
                <div class="event">
                    <b><?= h($update['status']) ?></b>
                    <br>
                    <small class="muted"><?= h($update['created_at']) ?> · <?= h($update['user_name'] ?: 'Sistema') ?></small>

                    <?php if ($update['internal_message']): ?>
                        <p>Interno: <?= nl2br(h($update['internal_message'])) ?></p>
                    <?php endif; ?>

                    <?php if ($update['client_message']): ?>
                        <p>Cliente: <?= nl2br(h($update['client_message'])) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php include 'partials/footer.php'; ?>
