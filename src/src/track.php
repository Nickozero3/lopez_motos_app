<?php
require_once 'config.php';
ensure_schema();

$pdo = db();
$trackingToken = trim((string)($_GET['t'] ?? ''));
$stmt = $pdo->prepare('SELECT wo.*,c.name AS client_name,v.type,v.brand,v.model,v.plate,v.color,v.km,v.engine_number,v.chassis_number FROM work_orders wo JOIN clients c ON c.id=wo.client_id JOIN vehicles v ON v.id=wo.vehicle_id WHERE wo.public_token=?');
$stmt->execute([$trackingToken]);
$order = $stmt->fetch();
if (!$order) { http_response_code(404); $pageTitle='Seguimiento no encontrado'; include 'partials/header.php'; ?><section class="card track-hero"><span class="track-code">ERROR 404</span><div class="track-status">Seguimiento no encontrado</div><p class="muted">El enlace puede estar incompleto o haber sido copiado de forma incorrecta.</p></section><?php include 'partials/footer.php'; exit; }

$stmt = $pdo->prepare('SELECT status,client_message,created_at FROM order_updates WHERE order_id=? AND visible_client=1 ORDER BY created_at DESC');
$stmt->execute([(int)$order['id']]);
$updates = $stmt->fetchAll();
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

include 'partials/header.php';
?>
<section class="card track-hero"><span class="track-code">Orden <?=h($order['code'])?></span><div class="track-status"><?=h($order['current_status'])?></div><p class="muted">Hola <?=h($order['client_name'])?>. Acá podés revisar el avance de tu moto sin instalar ninguna aplicación.</p></section>
<div class="track-progress" aria-label="Progreso de la reparación"><?php foreach($steps as $index=>$step):?><div class="track-step <?=(!$cancelled&&$index<=$statusStep)?'is-complete':''?>"><span><?=(!$cancelled&&$index<$statusStep)?'✓':($index+1)?></span><small><?=h($step)?></small></div><?php endforeach;?></div>
<div class="grid">
<section class="card span7"><div class="card-header"><div><h2>Tu moto</h2><p>Datos asociados a esta orden.</p></div></div><div class="detail-list"><div class="detail-item"><small>Vehículo</small><strong><?=h($order['brand'].' '.$order['model'])?></strong></div><div class="detail-item"><small>Patente</small><strong><?=h($order['plate'])?></strong></div><div class="detail-item"><small>Color</small><strong><?=h($order['color']?:'No informado')?></strong></div><div class="detail-item"><small>Kilómetros</small><strong><?=h($order['km']!==null?number_format((int)$order['km'],0,',','.'):'No informado')?></strong></div><div class="detail-item"><small>Ingreso</small><strong><?=h(date_ar($order['created_at']))?></strong></div><div class="detail-item"><small>Entrega estimada</small><strong><?=h(date_ar($order['estimated_delivery']))?></strong></div></div><div class="section-divider"><h2>Problema informado</h2></div><p><?=nl2br(h($order['problem_reported']))?></p></section>
<aside class="card span5"><div class="card-header"><div><h2>Diagnóstico y presupuesto</h2><p>Información cargada por el taller.</p></div></div><h3>Diagnóstico</h3><p class="muted"><?=nl2br(h($order['diagnosis']?:'La revisión todavía está en proceso. Te avisaremos cuando haya novedades.'))?></p><div class="summary-strip"><span>Presupuesto estimado</span><strong><?=h(money($order['total_estimated']))?></strong></div><?php if($workshopWa):?><a class="btn btn-primary btn-block" style="margin-top:14px" target="_blank" rel="noopener" href="<?=h($workshopWa)?>">Consultar al taller</a><?php endif;?></aside>
<section class="card span12"><div class="card-header"><div><h2>Actualizaciones</h2><p>Últimos avances visibles de la reparación.</p></div></div><?php if($updates):?><div class="timeline"><?php foreach($updates as $update):?><article class="event"><strong><?=h($update['status'])?></strong><br><small class="muted"><?=h(date_ar($update['created_at'],true))?></small><p><?=nl2br(h($update['client_message']?:'El taller actualizó el estado de la reparación.'))?></p></article><?php endforeach;?></div><?php else:?><div class="empty-state"><div><div class="empty-icon">LM</div><h2>Sin novedades todavía</h2><p class="muted">La orden ya fue registrada. Las actualizaciones aparecerán acá.</p></div></div><?php endif;?></section>
</div>
<?php include 'partials/footer.php'; ?>
