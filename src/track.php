<?php
require_once 'config.php';
ensure_schema();

$pdo = db();
$trackingToken = trim((string)($_GET['t'] ?? ''));
$approvalError = null;

$stmt = $pdo->prepare('SELECT wo.*,c.name AS client_name,v.type,v.brand,v.model,v.plate,v.color,v.km,v.engine_number,v.chassis_number FROM work_orders wo JOIN clients c ON c.id=wo.client_id JOIN vehicles v ON v.id=wo.vehicle_id WHERE wo.public_token=?');
$stmt->execute([$trackingToken]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Seguimiento no encontrado';
    include 'partials/header.php';
    ?>
    <section class="card track-hero">
        <span class="track-code">ERROR 404</span>
        <div class="track-status">Seguimiento no encontrado</div>
        <p class="muted">El enlace puede estar incompleto o haber sido copiado de forma incorrecta.</p>
    </section>
    <?php
    include 'partials/footer.php';
    exit;
}

if (is_post()) {
    try {
        verify_csrf();
        if ((string)post('action') !== 'approve_budget') {
            throw new RuntimeException('La acción solicitada no es válida.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id,current_status,budget_approved_at FROM work_orders WHERE id=? AND public_token=? FOR UPDATE');
        $stmt->execute([(int)$order['id'], $trackingToken]);
        $lockedOrder = $stmt->fetch();
        if (!$lockedOrder) throw new RuntimeException('No pudimos validar esta orden. Actualizá la página.');

        $stmt = $pdo->prepare('SELECT COUNT(*) AS item_count,COALESCE(SUM(quantity*unit_price),0) AS total FROM budget_items WHERE order_id=?');
        $stmt->execute([(int)$order['id']]);
        $budget = $stmt->fetch();
        $itemCount = (int)($budget['item_count'] ?? 0);
        $approvedTotal = (float)($budget['total'] ?? 0);
        if ($itemCount === 0) throw new RuntimeException('El taller todavía no cargó un presupuesto para confirmar.');

        if ($lockedOrder['budget_approved_at']) {
            $pdo->commit();
            redirect('track.php?t=' . rawurlencode($trackingToken) . '&approved=1');
        }

        if ($lockedOrder['current_status'] !== 'Esperando aprobación del cliente') {
            throw new RuntimeException('Este presupuesto todavía no está disponible para confirmar o la orden ya avanzó de estado.');
        }

        $approvalIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
        $stmt = $pdo->prepare('UPDATE work_orders SET current_status=?,total_estimated=?,budget_approved_at=NOW(),budget_approved_total=?,budget_approved_ip=? WHERE id=?');
        $stmt->execute(['Aprobado', $approvedTotal, $approvedTotal, $approvalIp, (int)$order['id']]);

        $stmt = $pdo->prepare('UPDATE budget_items SET approved=1 WHERE order_id=?');
        $stmt->execute([(int)$order['id']]);

        $stmt = $pdo->prepare('INSERT INTO order_updates(order_id,user_id,status,internal_message,client_message,visible_client,notify_client) VALUES(?,NULL,?,?,?,1,0)');
        $stmt->execute([
            (int)$order['id'],
            'Aprobado',
            'El cliente confirmó el presupuesto desde el seguimiento público por un total de ' . money($approvedTotal) . '.',
            'Presupuesto confirmado. El taller ya está autorizado para avanzar con la reparación.',
        ]);

        $pdo->commit();
        redirect('track.php?t=' . rawurlencode($trackingToken) . '&approved=1');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $approvalError = $exception->getMessage();
    }
}

$stmt = $pdo->prepare('SELECT status,client_message,created_at FROM order_updates WHERE order_id=? AND visible_client=1 ORDER BY created_at DESC');
$stmt->execute([(int)$order['id']]);
$updates = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT item_type,description,quantity,unit_price FROM budget_items WHERE order_id=? ORDER BY id');
$stmt->execute([(int)$order['id']]);
$budgetItems = $stmt->fetchAll();
$budgetTotal = array_reduce(
    $budgetItems,
    static fn(float $sum, array $item): float => $sum + (float)$item['quantity'] * (float)$item['unit_price'],
    0.0
);

$pageTitle = 'Seguimiento ' . $order['code'];
$steps = ['Ingreso', 'Diagnóstico', 'Aprobación', 'Reparación', 'Prueba final', 'Retiro'];
$statusStep = match ($order['current_status']) {
    'Ingresada', 'Pendiente de revisión' => 0,
    'En diagnóstico', 'Diagnóstico cargado', 'Presupuesto pendiente' => 1,
    'Esperando aprobación del cliente', 'Aprobado' => 2,
    'En reparación', 'Esperando repuestos', 'Repuesto solicitado', 'Repuesto recibido', 'Con complicaciones' => 3,
    'Prueba final' => 4,
    'Lista para retirar', 'Entregada' => 5,
    default => 0,
};
$cancelled = $order['current_status'] === 'Cancelada';
$workshopPhone = trim((string)(getenv('WORKSHOP_WHATSAPP') ?: ''));
$workshopWa = $workshopPhone !== '' ? wa_link($workshopPhone, 'Hola ' . app_name() . ', consulto por la orden ' . $order['code']) : null;
$isApproved = !empty($order['budget_approved_at']);
$canApprove = !$isApproved && $order['current_status'] === 'Esperando aprobación del cliente' && count($budgetItems) > 0;
$approvedNotice = isset($_GET['approved']) && $_GET['approved'] === '1' && $isApproved;

include 'partials/header.php';
?>
<?php if ($approvalError): ?>
    <div class="alert alert-error" data-alert>
        <span><?=h($approvalError)?></span>
        <button type="button" class="alert-close" data-alert-close>×</button>
    </div>
<?php elseif ($approvedNotice): ?>
    <div class="alert alert-success" data-alert>
        <span>Presupuesto confirmado correctamente. El taller ya puede avanzar con la reparación.</span>
        <button type="button" class="alert-close" data-alert-close>×</button>
    </div>
<?php endif; ?>

<section class="card track-hero">
    <span class="track-code">Orden <?=h($order['code'])?></span>
    <div class="track-status"><?=h($order['current_status'])?></div>
    <p class="muted">Hola <?=h($order['client_name'])?>. Acá podés revisar el avance de tu moto sin instalar ninguna aplicación.</p>
</section>

<div class="track-progress" aria-label="Progreso de la reparación">
    <?php foreach ($steps as $index => $step): ?>
        <div class="track-step <?=(!$cancelled && $index <= $statusStep) ? 'is-complete' : ''?>">
            <span><?=(!$cancelled && $index < $statusStep) ? '✓' : ($index + 1)?></span>
            <small><?=h($step)?></small>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid">
    <section class="card span7">
        <div class="card-header"><div><h2>Tu moto</h2><p>Datos asociados a esta orden.</p></div></div>
        <div class="detail-list">
            <div class="detail-item"><small>Vehículo</small><strong><?=h($order['brand'] . ' ' . $order['model'])?></strong></div>
            <div class="detail-item"><small>Patente</small><strong><?=h($order['plate'])?></strong></div>
            <div class="detail-item"><small>Color</small><strong><?=h($order['color'] ?: 'No informado')?></strong></div>
            <div class="detail-item"><small>Kilómetros</small><strong><?=h($order['km'] !== null ? number_format((int)$order['km'], 0, ',', '.') : 'No informado')?></strong></div>
            <div class="detail-item"><small>Ingreso</small><strong><?=h(date_ar($order['created_at']))?></strong></div>
            <div class="detail-item"><small>Entrega estimada</small><strong><?=h(date_ar($order['estimated_delivery']))?></strong></div>
        </div>
        <div class="section-divider"><h2>Problema informado</h2></div>
        <p><?=nl2br(h($order['problem_reported']))?></p>
    </section>

    <aside class="card span5">
        <div class="card-header"><div><h2>Diagnóstico y presupuesto</h2><p>Información cargada por el taller.</p></div></div>
        <h3>Diagnóstico</h3>
        <p class="muted"><?=nl2br(h($order['diagnosis'] ?: 'La revisión todavía está en proceso. Te avisaremos cuando haya novedades.'))?></p>
        <div class="summary-strip"><span>Presupuesto estimado</span><strong><?=h(money($budgetTotal))?></strong></div>

        <?php if ($isApproved): ?>
            <div class="approval-state is-approved">
                <span class="approval-state-icon" aria-hidden="true">✓</span>
                <div>
                    <strong>Presupuesto confirmado</strong>
                    <span><?=h(date_ar($order['budget_approved_at'], true))?> · Total aprobado: <?=h(money($order['budget_approved_total'] ?? $budgetTotal))?></span>
                </div>
            </div>
        <?php elseif ($canApprove): ?>
            <form method="post" class="approval-form" data-confirm="¿Confirmás el presupuesto de <?=h(money($budgetTotal))?> y autorizás al taller a comenzar la reparación?">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="approve_budget">
                <button class="btn btn-success btn-block" type="submit">Confirmar presupuesto y avanzar</button>
                <small>Al confirmar, autorizás al taller a comenzar la reparación por el total indicado.</small>
            </form>
        <?php elseif ($budgetItems && !$cancelled): ?>
            <div class="approval-state is-pending">
                <span class="approval-state-icon" aria-hidden="true">…</span>
                <div>
                    <strong>Confirmación todavía no habilitada</strong>
                    <span>El taller habilitará el botón cuando el presupuesto esté listo para aprobar.</span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($workshopWa): ?>
            <a class="btn btn-primary btn-block" style="margin-top:14px" target="_blank" rel="noopener" href="<?=h($workshopWa)?>">Consultar al taller</a>
        <?php endif; ?>
    </aside>

    <section class="card span12" id="presupuesto">
        <div class="card-header"><div><h2>Detalle del presupuesto</h2><p>Revisá los conceptos antes de confirmar.</p></div><div class="kpi public-budget-total"><?=h(money($budgetTotal))?></div></div>
        <?php if ($budgetItems): ?>
            <div class="public-budget-list">
                <?php foreach ($budgetItems as $item): ?>
                    <?php $subtotal = (float)$item['quantity'] * (float)$item['unit_price']; ?>
                    <article class="public-budget-item">
                        <div>
                            <strong><?=h($item['description'])?></strong>
                            <small><?=h($item['item_type'] === 'mano_obra' ? 'Mano de obra' : ucfirst($item['item_type']))?> · <?=h(number_format((float)$item['quantity'], 2, ',', '.'))?> × <?=h(money($item['unit_price']))?></small>
                        </div>
                        <strong><?=h(money($subtotal))?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><div><div class="empty-icon">$</div><h2>Presupuesto pendiente</h2><p class="muted">El detalle aparecerá cuando el taller termine de cargarlo.</p></div></div>
        <?php endif; ?>
    </section>

    <section class="card span12">
        <div class="card-header"><div><h2>Actualizaciones</h2><p>Últimos avances visibles de la reparación.</p></div></div>
        <?php if ($updates): ?>
            <div class="timeline">
                <?php foreach ($updates as $update): ?>
                    <article class="event">
                        <strong><?=h($update['status'])?></strong><br>
                        <small class="muted"><?=h(date_ar($update['created_at'], true))?></small>
                        <p><?=nl2br(h($update['client_message'] ?: 'El taller actualizó el estado de la reparación.'))?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><div><div class="empty-icon">LM</div><h2>Sin novedades todavía</h2><p class="muted">La orden ya fue registrada. Las actualizaciones aparecerán acá.</p></div></div>
        <?php endif; ?>
    </section>
</div>
<?php include 'partials/footer.php'; ?>
