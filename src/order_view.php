<?php
require_once 'config.php';
require_auth();
ensure_schema();

$pdo = db();
$orderId = (int)($_GET['id'] ?? 0);
$error = null;

function load_order(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT wo.*,c.name AS client_name,c.phone,c.email,c.dni,c.address,c.notes AS client_notes,v.id AS vehicle_id,v.type,v.brand,v.model,v.plate,v.year,v.cc,v.color,v.engine_number,v.chassis_number,v.km FROM work_orders wo JOIN clients c ON c.id=wo.client_id JOIN vehicles v ON v.id=wo.vehicle_id WHERE wo.id=?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        die('Orden no encontrada.');
    }
    return $order;
}

function recalculate_order_total(PDO $pdo, int $orderId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity*unit_price),0) FROM budget_items WHERE order_id=?');
    $stmt->execute([$orderId]);
    $total = (float)$stmt->fetchColumn();
    $stmt = $pdo->prepare('UPDATE work_orders SET total_estimated=? WHERE id=?');
    $stmt->execute([$total, $orderId]);
    return $total;
}

function invalidate_budget_approval(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare('SELECT current_status,budget_approved_at FROM work_orders WHERE id=? FOR UPDATE');
    $stmt->execute([$orderId]);
    $approval = $stmt->fetch();
    if (!$approval || !$approval['budget_approved_at']) return false;
    if (in_array($approval['current_status'], ['Entregada', 'Cancelada'], true)) return false;

    $status = 'Esperando aprobación del cliente';
    $stmt = $pdo->prepare('UPDATE work_orders SET current_status=?,budget_approved_at=NULL,budget_approved_total=NULL,budget_approved_ip=NULL WHERE id=?');
    $stmt->execute([$status, $orderId]);
    $stmt = $pdo->prepare('UPDATE budget_items SET approved=0 WHERE order_id=?');
    $stmt->execute([$orderId]);
    $stmt = $pdo->prepare('INSERT INTO order_updates(order_id,user_id,status,internal_message,client_message,visible_client,notify_client) VALUES(?,?,?,?,?,1,0)');
    $stmt->execute([
        $orderId,
        auth()['id'] ?? null,
        $status,
        'La aprobación anterior se anuló porque el presupuesto fue modificado.',
        'El taller actualizó el presupuesto. Revisalo y confirmalo nuevamente para continuar con la reparación.',
    ]);
    return true;
}

function validate_vehicle_identity(PDO $pdo, string $plate, string $engine, string $chassis, int $exclude): void
{
    $stmt = $pdo->prepare('SELECT id FROM vehicles WHERE active=1 AND id<>? AND (plate=? OR engine_number=? OR chassis_number=?) LIMIT 1');
    $stmt->execute([$exclude, $plate, $engine, $chassis]);
    if ($stmt->fetch()) throw new RuntimeException('Ya existe otra moto con la misma patente, número de motor o número de chasis.');
}

function add_part_to_budget(PDO $pdo, int $orderId): void
{
    $partId = (int)post('part_id');
    $quantity = decimal_value(post('quantity'), 1);
    if ($partId <= 0 || $quantity <= 0) throw new RuntimeException('Seleccioná un repuesto y una cantidad válida.');

    $stmt = $pdo->prepare('SELECT * FROM parts WHERE id=? AND active=1 FOR UPDATE');
    $stmt->execute([$partId]);
    $part = $stmt->fetch();
    if (!$part) throw new RuntimeException('El repuesto no existe o está archivado.');

    $before = (float)$part['stock'];
    if ($quantity > $before) throw new RuntimeException('No hay stock suficiente. Disponible: ' . number_format($before, 2, ',', '.'));
    $after = $before - $quantity;
    $description = clean_text(post('description')) ?: $part['name'];
    $price = max(0, decimal_value(post('unit_price'), (float)$part['sell_price']));

    $stmt = $pdo->prepare('INSERT INTO budget_items(order_id,part_id,item_type,description,quantity,unit_price,stock_applied,approved) VALUES(?,? ,"repuesto",?,?,?,?,0)');
    $stmt->execute([$orderId, $partId, $description, $quantity, $price, $quantity]);
    $itemId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('UPDATE parts SET stock=? WHERE id=?');
    $stmt->execute([$after, $partId]);
    add_stock_movement($partId, $orderId, $itemId, 'salida', $quantity, $before, $after, 'Aplicado a la reparación');
}

function update_budget_item(PDO $pdo, int $orderId): void
{
    $itemId = (int)post('item_id');
    $quantity = decimal_value(post('quantity'), 1);
    $price = max(0, decimal_value(post('unit_price')));
    $description = clean_text(post('description'));
    if ($quantity <= 0 || $description === '') throw new RuntimeException('Descripción y cantidad son obligatorias.');

    $stmt = $pdo->prepare('SELECT * FROM budget_items WHERE id=? AND order_id=? FOR UPDATE');
    $stmt->execute([$itemId, $orderId]);
    $item = $stmt->fetch();
    if (!$item) throw new RuntimeException('El ítem ya no existe.');

    if ($item['part_id']) {
        $stmt = $pdo->prepare('SELECT stock FROM parts WHERE id=? FOR UPDATE');
        $stmt->execute([(int)$item['part_id']]);
        $part = $stmt->fetch();
        if (!$part) throw new RuntimeException('El repuesto asociado ya no existe.');
        $before = (float)$part['stock'];
        $difference = $quantity - (float)$item['stock_applied'];
        $after = $before - $difference;
        if ($after < 0) throw new RuntimeException('No hay stock suficiente para aumentar la cantidad.');
        if (abs($difference) > 0.0001) {
            $stmt = $pdo->prepare('UPDATE parts SET stock=? WHERE id=?');
            $stmt->execute([$after, (int)$item['part_id']]);
            add_stock_movement((int)$item['part_id'], $orderId, $itemId, $difference > 0 ? 'salida' : 'devolucion', abs($difference), $before, $after, 'Ajuste del ítem en la orden');
        }
        $stmt = $pdo->prepare('UPDATE budget_items SET description=?,quantity=?,unit_price=?,stock_applied=? WHERE id=? AND order_id=?');
        $stmt->execute([$description, $quantity, $price, $quantity, $itemId, $orderId]);
        return;
    }

    $type = in_array(post('item_type'), ['mano_obra', 'repuesto', 'otro'], true) ? post('item_type') : 'otro';
    $stmt = $pdo->prepare('UPDATE budget_items SET item_type=?,description=?,quantity=?,unit_price=? WHERE id=? AND order_id=?');
    $stmt->execute([$type, $description, $quantity, $price, $itemId, $orderId]);
}

function remove_budget_item(PDO $pdo, int $orderId): void
{
    $itemId = (int)post('item_id');
    $stmt = $pdo->prepare('SELECT * FROM budget_items WHERE id=? AND order_id=? FOR UPDATE');
    $stmt->execute([$itemId, $orderId]);
    $item = $stmt->fetch();
    if (!$item) return;

    if ($item['part_id'] && (float)$item['stock_applied'] > 0) {
        $stmt = $pdo->prepare('SELECT stock FROM parts WHERE id=? FOR UPDATE');
        $stmt->execute([(int)$item['part_id']]);
        $part = $stmt->fetch();
        $before = (float)($part['stock'] ?? 0);
        $quantity = (float)$item['stock_applied'];
        $after = $before + $quantity;
        $stmt = $pdo->prepare('UPDATE parts SET stock=? WHERE id=?');
        $stmt->execute([$after, (int)$item['part_id']]);
        add_stock_movement((int)$item['part_id'], $orderId, $itemId, 'devolucion', $quantity, $before, $after, 'Ítem eliminado de la orden');
    }
    $stmt = $pdo->prepare('DELETE FROM budget_items WHERE id=? AND order_id=?');
    $stmt->execute([$itemId, $orderId]);
}

if (is_post()) {
    try {
        verify_csrf();
        $action = (string)post('action');

        if ($action === 'update_order') {
            $status = in_array(post('status'), STATUSES, true) ? post('status') : 'Ingresada';
            $priority = in_array(post('priority'), PRIORITIES, true) ? post('priority') : 'normal';
            $clientMessage = trim((string)post('client_message'));
            $pdo->beginTransaction();
            $order = load_order($pdo, $orderId);
            $stmt = $pdo->prepare('UPDATE work_orders SET current_status=?,priority=?,problem_reported=?,diagnosis=?,estimated_delivery=?,total_final=?,delivered_at=CASE WHEN ?="Entregada" THEN COALESCE(delivered_at,NOW()) ELSE delivered_at END WHERE id=?');
            $stmt->execute([$status, $priority, clean_text(post('problem_reported')), nullable_text(post('diagnosis')), nullable_text(post('estimated_delivery')), max(0, decimal_value(post('total_final'))), $status, $orderId]);
            if ($status === 'Esperando aprobación del cliente') {
                $stmt = $pdo->prepare('UPDATE work_orders SET budget_approved_at=NULL,budget_approved_total=NULL,budget_approved_ip=NULL WHERE id=?');
                $stmt->execute([$orderId]);
                $stmt = $pdo->prepare('UPDATE budget_items SET approved=0 WHERE order_id=?');
                $stmt->execute([$orderId]);
            }
            $stmt = $pdo->prepare('INSERT INTO order_updates(order_id,user_id,status,internal_message,client_message,visible_client,notify_client) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$orderId, auth()['id'], $status, nullable_text(post('internal_message')), $clientMessage ?: null, isset($_POST['visible_client']) ? 1 : 0, isset($_POST['notify_client']) ? 1 : 0]);
            $updateId = (int)$pdo->lastInsertId();
            $pdo->commit();
            if (isset($_POST['notify_client']) && $clientMessage !== '') {
                $fresh = load_order($pdo, $orderId);
                notify_customer($fresh, $updateId, $clientMessage . "\n\nSeguimiento: " . public_base_url() . '/track.php?t=' . $fresh['public_token']);
            }
            flash('success', 'Estado y datos de la orden actualizados.');
            redirect('order_view.php?id=' . $orderId);
        }

        if ($action === 'update_identity') {
            $name = clean_text(post('client_name'));
            $phone = clean_text(post('phone'));
            $brand = clean_text(post('brand'));
            $model = clean_text(post('model'));
            $plate = upper_identifier(post('plate'));
            $engine = upper_identifier(post('engine_number'));
            $chassis = upper_identifier(post('chassis_number'));
            if ($name === '' || $phone === '') throw new RuntimeException('Nombre y teléfono del cliente son obligatorios.');
            if ($brand === '' || $model === '' || $plate === '' || $engine === '' || $chassis === '') throw new RuntimeException('Marca, modelo, patente, motor y chasis son obligatorios.');
            $order = load_order($pdo, $orderId);
            validate_vehicle_identity($pdo, $plate, $engine, $chassis, (int)$order['vehicle_id']);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE clients SET name=?,phone=?,email=?,dni=?,address=?,notes=? WHERE id=?');
            $stmt->execute([$name, $phone, nullable_text(post('email')), nullable_text(post('dni')), nullable_text(post('address')), nullable_text(post('client_notes')), (int)$order['client_id']]);
            $stmt = $pdo->prepare('UPDATE vehicles SET type=?,brand=?,model=?,plate=?,year=?,cc=?,color=?,engine_number=?,chassis_number=?,km=? WHERE id=?');
            $stmt->execute([clean_text(post('type')) ?: 'Moto', $brand, $model, $plate, nullable_text(post('year')), nullable_text(post('cc')), nullable_text(post('color')), $engine, $chassis, int_value(post('km')), (int)$order['vehicle_id']]);
            $pdo->commit();
            flash('success', 'Cliente y moto actualizados. Los identificadores quedaron normalizados en mayúsculas.');
            redirect('order_view.php?id=' . $orderId);
        }

        if ($action === 'budget_add') {
            $description = clean_text(post('description'));
            $quantity = decimal_value(post('quantity'), 1);
            if ($description === '' || $quantity <= 0) throw new RuntimeException('Completá descripción y cantidad.');
            $type = in_array(post('item_type'), ['mano_obra', 'repuesto', 'otro'], true) ? post('item_type') : 'otro';
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO budget_items(order_id,item_type,description,quantity,unit_price,approved) VALUES(?,?,?,?,?,0)');
            $stmt->execute([$orderId, $type, $description, $quantity, max(0, decimal_value(post('unit_price')))]);
            recalculate_order_total($pdo, $orderId);
            $approvalInvalidated = invalidate_budget_approval($pdo, $orderId);
            $pdo->commit();
            flash('success', $approvalInvalidated ? 'Ítem agregado. El cliente deberá confirmar nuevamente el presupuesto.' : 'Ítem agregado al presupuesto.');
            redirect('order_view.php?id=' . $orderId . '#budget');
        }

        if ($action === 'add_stock_part') {
            $pdo->beginTransaction();
            add_part_to_budget($pdo, $orderId);
            recalculate_order_total($pdo, $orderId);
            $approvalInvalidated = invalidate_budget_approval($pdo, $orderId);
            $pdo->commit();
            flash('success', $approvalInvalidated ? 'Repuesto agregado. El cliente deberá confirmar nuevamente el presupuesto.' : 'Repuesto agregado y descontado del stock.');
            redirect('order_view.php?id=' . $orderId . '#budget');
        }

        if ($action === 'budget_update') {
            $pdo->beginTransaction();
            update_budget_item($pdo, $orderId);
            recalculate_order_total($pdo, $orderId);
            $approvalInvalidated = invalidate_budget_approval($pdo, $orderId);
            $pdo->commit();
            flash('success', $approvalInvalidated ? 'Ítem actualizado. La aprobación anterior fue anulada.' : 'Ítem actualizado.');
            redirect('order_view.php?id=' . $orderId . '#budget');
        }

        if ($action === 'budget_delete') {
            $pdo->beginTransaction();
            remove_budget_item($pdo, $orderId);
            recalculate_order_total($pdo, $orderId);
            $approvalInvalidated = invalidate_budget_approval($pdo, $orderId);
            $pdo->commit();
            flash('success', $approvalInvalidated ? 'Ítem eliminado. El cliente deberá confirmar nuevamente el presupuesto.' : 'Ítem eliminado. Si era un repuesto, volvió al stock.');
            redirect('order_view.php?id=' . $orderId . '#budget');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
    }
}

$order = load_order($pdo, $orderId);
$pageTitle = $order['code'];
$stmt = $pdo->prepare('SELECT ou.*,u.name AS user_name FROM order_updates ou LEFT JOIN users u ON u.id=ou.user_id WHERE ou.order_id=? ORDER BY ou.created_at DESC');
$stmt->execute([$orderId]);
$updates = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT bi.*,p.photo_path,p.stock AS current_stock,p.name AS part_name FROM budget_items bi LEFT JOIN parts p ON p.id=bi.part_id WHERE bi.order_id=? ORDER BY bi.id');
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();
$parts = $pdo->query('SELECT id,name,sku,stock,sell_price FROM parts WHERE active=1 AND stock>0 ORDER BY name')->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM notification_queue WHERE order_id=? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$orderId]);
$notifications = $stmt->fetchAll();
$total = array_reduce($items, fn(float $sum, array $item): float => $sum + (float)$item['quantity'] * (float)$item['unit_price'], 0.0);
$publicUrl = public_base_url() . '/track.php?t=' . $order['public_token'];
$waMessage = 'Hola ' . $order['client_name'] . ', te compartimos el seguimiento de la orden ' . $order['code'] . ': ' . $publicUrl;
$whatsapp = wa_link($order['phone'], $waMessage);
$identityOpen = $error !== null && is_post() && (string)post('action') === 'update_identity';

include 'partials/header.php';
?>
<div class="page-head">
    <div class="page-head-copy"><a class="muted" href="orders.php">← Órdenes</a>
        <h1><?= h($order['code']) ?></h1>
        <p><?= h($order['client_name'] . ' · ' . $order['brand'] . ' ' . $order['model'] . ' · ' . $order['plate']) ?></p>
    </div>
    <div class="actions"><a class="btn btn-success" target="_blank" rel="noopener" href="<?= h($whatsapp) ?>">WhatsApp</a><a class="btn" target="_blank" href="track.php?t=<?= h($order['public_token']) ?>">Vista del cliente</a></div>
</div>
<?php if ($error): ?><div class="alert alert-error"><span><?= h($error) ?></span></div><?php endif; ?>
<details class="card identity-card" id="identity" data-open-on-error="<?= $identityOpen ? '1' : '0' ?>" <?= $identityOpen ? 'open' : '' ?>>
    <summary class="identity-summary" aria-label="Modificar datos del cliente y la moto">
        <div class="identity-heading"><span class="identity-mark" aria-hidden="true">CM</span><span><strong>Cliente y moto</strong><small>Información vinculada a esta orden</small></span></div>
        <div class="identity-overview">
            <span class="identity-overview-item"><small>Cliente</small><strong><?= h($order['client_name']) ?></strong><span>DNI: <?= h($order['dni'] ?: 'No cargado') ?> · <?= h($order['phone']) ?></span></span>
            <span class="identity-overview-item"><small>Moto</small><strong><?= h($order['brand'] . ' ' . $order['model']) ?></strong><span><?= h($order['plate']) ?> · Motor <?= h($order['engine_number']) ?></span></span>
        </div>
        <span class="btn btn-sm identity-toggle" aria-hidden="true"><span class="identity-closed-label">Modificar</span><span class="identity-open-label">Cerrar</span></span>
    </summary>
    <div class="identity-editor">
        <div class="identity-editor-head">
            <div>
                <h2>Modificar cliente y moto</h2>
                <p>Patente, motor y chasis se guardan en mayúsculas y sin espacios.</p>
            </div>
        </div>
        <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="update_identity">
            <div class="section-divider">
                <h2>Cliente</h2>
            </div>
            <div class="field span4"><label class="required">Nombre</label><input name="client_name" required value="<?= h($order['client_name']) ?>"></div>
            <div class="field span4"><label class="required">Teléfono</label><input name="phone" required value="<?= h($order['phone']) ?>"></div>
            <div class="field span4"><label>Email</label><input type="email" name="email" value="<?= h($order['email']) ?>"></div>
            <div class="field span3"><label>DNI</label><input name="dni" value="<?= h($order['dni']) ?>"></div>
            <div class="field span5"><label>Dirección</label><input name="address" value="<?= h($order['address']) ?>"></div>
            <div class="field span4"><label>Notas</label><input name="client_notes" value="<?= h($order['client_notes']) ?>"></div>
            <div class="section-divider">
                <h2>Moto</h2>
            </div>
            <div class="field span3"><label>Tipo</label><input name="type" value="<?= h($order['type']) ?>"></div>
            <div class="field span3"><label class="required">Marca</label><input name="brand" required value="<?= h($order['brand']) ?>"></div>
            <div class="field span3"><label class="required">Modelo</label><input name="model" required value="<?= h($order['model']) ?>"></div>
            <div class="field span3"><label class="required">Patente</label><input name="plate" required data-uppercase value="<?= h($order['plate']) ?>"></div>
            <div class="field span3"><label>Año</label><input name="year" value="<?= h($order['year']) ?>"></div>
            <div class="field span3"><label>Cilindrada</label><input name="cc" value="<?= h($order['cc']) ?>"></div>
            <div class="field span3"><label>Color</label><input name="color" value="<?= h($order['color']) ?>"></div>
            <div class="field span3"><label>Kilómetros</label><input type="number" min="0" name="km" value="<?= h($order['km']) ?>"></div>
            <div class="field span6"><label class="required">Número de motor</label><input name="engine_number" required data-uppercase value="<?= h($order['engine_number']) ?>"></div>
            <div class="field span6"><label class="required">Número de chasis</label><input name="chassis_number" required data-uppercase value="<?= h($order['chassis_number']) ?>"></div>
            <div class="form-actions"><a class="btn" href="client_view.php?id=<?= (int)$order['client_id'] ?>">Abrir ficha completa</a><button class="btn" type="button" data-close-identity>Cancelar</button><button class="btn btn-primary">Guardar cliente y moto</button></div>
        </form>
    </div>
</details>
<div class="grid">
    <section class="card span8 hero-card order-summary-card">
        <div><span class="status <?= h(status_tone($order['current_status'])) ?>"><?= h($order['current_status']) ?></span>
            <h2 style="font-size:1.55rem;margin:16px 0 8px"><?= h($order['brand'] . ' ' . $order['model']) ?></h2>
            <p><?= nl2br(h($order['problem_reported'])) ?></p>
        </div>
        <div class="metric-row">
            <div class="metric-box"><small class="muted">Prioridad</small><strong><?= h(ucfirst($order['priority'])) ?></strong></div>
            <div class="metric-box"><small class="muted">Ingreso</small><strong><?= h(date_ar($order['created_at'])) ?></strong></div>
            <div class="metric-box"><small class="muted">Entrega</small><strong><?= h(date_ar($order['estimated_delivery'])) ?></strong></div>
        </div>
    </section>
    <aside class="card span4">
        <div class="card-header">
            <div>
                <h2>Seguimiento público</h2>
                <p>Enlace individual de esta reparación.</p>
            </div>
        </div><input id="publicTrackingUrl" value="<?= h($publicUrl) ?>" readonly>
        <div class="actions" style="margin-top:10px"><button class="btn" type="button" data-copy="<?= h($publicUrl) ?>">Copiar enlace</button><a class="btn" target="_blank" href="track.php?t=<?= h($order['public_token']) ?>">Abrir</a></div>
        <div class="summary-strip" style="margin-top:18px"><span>Total estimado</span><strong><?= h(money($total)) ?></strong></div><?php if ($order['budget_approved_at']): ?><div class="approval-mini is-approved"><strong>Confirmado por el cliente</strong><span><?= h(date_ar($order['budget_approved_at'], true)) ?> · <?= h(money($order['budget_approved_total'])) ?></span></div><?php elseif ($order['current_status'] === 'Esperando aprobación del cliente' && $items): ?><div class="approval-mini is-pending"><strong>Esperando confirmación</strong><span>El cliente puede aprobar desde su enlace público.</span></div><?php endif; ?>
    </aside>

    <section class="card span7">
        <div class="card-header">
            <div>
                <h2>Actualizar reparación</h2>
                <p>Estado, diagnóstico y comunicación al cliente.</p>
            </div>
        </div>
        <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="update_order">
            <div class="field span6"><label>Estado</label><select name="status"><?php foreach (STATUSES as $status): ?><option value="<?= h($status) ?>" <?= $status === $order['current_status'] ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?></select></div>
            <div class="field span3"><label>Prioridad</label><select name="priority"><?php foreach (PRIORITIES as $priority): ?><option value="<?= h($priority) ?>" <?= $priority === $order['priority'] ? 'selected' : '' ?>><?= h(ucfirst($priority)) ?></option><?php endforeach; ?></select></div>
            <div class="field span3"><label>Entrega estimada</label><input type="date" name="estimated_delivery" value="<?= h($order['estimated_delivery']) ?>"></div>
            <div class="field"><label>Problema declarado</label><textarea name="problem_reported" required><?= h($order['problem_reported']) ?></textarea></div>
            <div class="field"><label>Diagnóstico</label><textarea name="diagnosis" placeholder="Detalle técnico de la revisión"><?= h($order['diagnosis']) ?></textarea></div>
            <div class="field span6"><label>Nota interna</label><textarea name="internal_message" placeholder="Solo visible dentro del taller"></textarea></div>
            <div class="field span6"><label>Mensaje para el cliente</label><textarea name="client_message" placeholder="Ej: Terminamos el diagnóstico y aguardamos tu aprobación."></textarea></div>
            <div class="field span4"><label>Total final cobrado</label><input type="number" min="0" step="0.01" name="total_final" value="<?= h($order['total_final']) ?>"></div>
            <div class="field span8"><label class="checkline"><input type="checkbox" name="visible_client" checked> Mostrar esta actualización en el seguimiento</label><label class="checkline"><input type="checkbox" name="notify_client" checked> Enviar notificación automática cuando haya mensaje</label></div>
            <div class="form-actions"><button class="btn btn-primary">Guardar actualización</button></div>
        </form>
    </section>

    <section class="card span5">
        <div class="card-header">
            <div>
                <h2>Historial de avances</h2>
                <p><?= count($updates) ?> actualización<?= count($updates) === 1 ? '' : 'es' ?></p>
            </div>
        </div><?php if ($updates): ?><div class="timeline"><?php foreach ($updates as $update): ?><div class="event"><strong><?= h($update['status']) ?></strong><br><small class="muted"><?= h(date_ar($update['created_at'], true) . ' · ' . ($update['user_name'] ?: 'Sistema')) ?></small><?php if ($update['client_message']): ?><p><?= nl2br(h($update['client_message'])) ?></p><?php elseif ($update['internal_message']): ?><p class="muted"><?= nl2br(h($update['internal_message'])) ?></p><?php endif; ?></div><?php endforeach; ?></div><?php else: ?><div class="empty-state">
                <div>
                    <h2>Sin actualizaciones</h2>
                </div>
            </div><?php endif; ?>
    </section>

    <section class="card span6">
        <div class="card-header">
            <div>
                <h2>Usar repuesto del stock</h2>
                <p>La cantidad se descuenta automáticamente.</p>
            </div>
        </div>
        <form method="post" class="form-grid stock-add-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_stock_part">
            <div class="field"><label class="required">Repuesto</label><select name="part_id" id="partPicker" required>
                    <option value="">Seleccionar...</option><?php foreach ($parts as $part): ?><option value="<?= (int)$part['id'] ?>" data-name="<?= h($part['name']) ?>" data-price="<?= h($part['sell_price']) ?>" data-stock="<?= h($part['stock']) ?>"><?= h($part['name'] . ' · Stock ' . $part['stock'] . ' · ' . money($part['sell_price'])) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field"><label>Descripción</label><input name="description" id="stockDescription"></div>
            <div class="field span6"><label>Cantidad</label><input type="number" min="0.01" step="0.01" name="quantity" id="stockQuantity" value="1"></div>
            <div class="field span6"><label>Precio venta</label><input type="number" min="0" step="0.01" name="unit_price" id="stockPrice" value="0"></div>
            <div class="form-actions"><button class="btn btn-primary">Agregar y descontar stock</button></div>
        </form>
    </section>
    <section class="card span6">
        <div class="card-header">
            <div>
                <h2>Mano de obra u otro concepto</h2>
                <p>Ítems que no modifican el inventario.</p>
            </div>
        </div>
        <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="budget_add">
            <div class="field span4"><label>Tipo</label><select name="item_type">
                    <option value="mano_obra">Mano de obra</option>
                    <option value="repuesto">Repuesto</option>
                    <option value="otro">Otro</option>
                </select></div>
            <div class="field span8"><label class="required">Descripción</label><input name="description" required></div>
            <div class="field span6"><label>Cantidad</label><input type="number" min="0.01" step="0.01" name="quantity" value="1"></div>
            <div class="field span6"><label>Precio unitario</label><input type="number" min="0" step="0.01" name="unit_price" value="0"></div>
            <div class="form-actions"><button class="btn btn-primary">Agregar al presupuesto</button></div>
        </form>
    </section>

    <section class="card span12" id="budget">
        <div class="card-header">
            <div>
                <h2>Presupuesto de la orden</h2>
                <p>Editar cantidades de repuestos ajusta el stock de forma automática.</p>
            </div>
            <div class="kpi" style="font-size:2rem"><?= h(money($total)) ?></div>
        </div><?php if ($items): ?><div class="table-wrap responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Concepto</th>
                            <th>Tipo</th>
                            <th>Cantidad</th>
                            <th>Precio</th>
                            <th>Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($items as $item): ?><tr>
                                <td class="primary-cell">
                                    <div class="table-title"><?php if ($item['photo_path']): ?><img class="thumb" src="<?= h($item['photo_path']) ?>" alt=""><?php endif; ?><span><strong><?= h($item['description']) ?></strong><?php if ($item['part_id']): ?><small>Stock disponible: <?= h(number_format((float)$item['current_stock'], 2, ',', '.')) ?></small><?php endif; ?></span></div>
                                </td>
                                <td data-label="Tipo"><span class="badge info"><?= h(strtolower((string)$item['item_type']) === 'mano_obra' ? 'Mano de obra' : ucfirst(strtolower((string)$item['item_type']))) ?></span></td>
                                <td data-label="Cantidad"><?= h(number_format((float)$item['quantity'], 2, ',', '.')) ?></td>
                                <td data-label="Precio"><?= h(money($item['unit_price'])) ?></td>
                                <td data-label="Subtotal"><strong><?= h(money((float)$item['quantity'] * (float)$item['unit_price'])) ?></strong></td>
                                <td data-label="Acciones">
                                    <div class="table-actions"><button type="button" class="btn btn-sm" onclick="document.getElementById('edit-item-<?= (int)$item['id'] ?>').toggleAttribute('hidden')">Editar</button>
                                        <form method="post" data-confirm="¿Eliminar este ítem? Si es un repuesto, volverá al stock."><?= csrf_field() ?><input type="hidden" name="action" value="budget_delete"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-danger">Eliminar</button></form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="edit-item-<?= (int)$item['id'] ?>" hidden>
                                <td colspan="6">
                                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="budget_update"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                        <div class="field"><label>Descripción</label><input name="description" value="<?= h($item['description']) ?>" required></div><?php if (!$item['part_id']): ?><div class="field"><label>Tipo</label><select name="item_type">
                                                    <option value="mano_obra" <?= $item['item_type'] === 'mano_obra' ? 'selected' : '' ?>>Mano de obra</option>
                                                    <option value="repuesto" <?= strtolower((string)$item['item_type']) === 'repuesto' ? 'selected' : '' ?>>Repuesto</option>
                                                    <option value="otro" <?= $item['item_type'] === 'otro' ? 'selected' : '' ?>>Otro</option>
                                                </select></div><?php endif; ?><div class="field"><label>Cantidad</label><input type="number" min="0.01" step="0.01" name="quantity" value="<?= h($item['quantity']) ?>"></div>
                                        <div class="field"><label>Precio</label><input type="number" min="0" step="0.01" name="unit_price" value="<?= h($item['unit_price']) ?>"></div><button class="btn btn-primary">Guardar</button>
                                    </form>
                                </td>
                            </tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div><?php else: ?><div class="empty-state">
                <div>
                    <div class="empty-icon">$</div>
                    <h2>Presupuesto vacío</h2>
                    <p class="muted">Agregá repuestos, mano de obra u otros conceptos.</p>
                </div>
            </div><?php endif; ?>
    </section>

    <?php if ($notifications): ?><section class="card span12">
            <div class="card-header">
                <div>
                    <h2>Notificaciones automáticas</h2>
                    <p>Registro de entregas al proveedor configurado.</p>
                </div>
            </div>
            <div class="table-wrap responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Canal</th>
                            <th>Destino</th>
                            <th>Estado</th>
                            <th>Respuesta</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($notifications as $notification): ?><tr>
                                <td class="primary-cell"><strong><?= h(date_ar($notification['created_at'], true)) ?></strong></td>
                                <td data-label="Canal"><?= h($notification['channel']) ?></td>
                                <td data-label="Destino"><?= h($notification['destination'] ?: '—') ?></td>
                                <td data-label="Estado"><span class="badge <?= $notification['status'] === 'sent' ? 'success' : ($notification['status'] === 'failed' ? 'danger' : 'warning') ?>"><?= h($notification['status']) ?></span></td>
                                <td data-label="Respuesta"><small><?= h($notification['provider_response'] ?: 'Pendiente') ?></small></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section><?php endif; ?>
</div>
<?php include 'partials/footer.php'; ?>