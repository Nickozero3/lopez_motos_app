<?php require_once 'config.php'; require_auth();
$pdo=db();
$counts=[]; foreach(['Ingresada','En diagnóstico','En reparación','Esperando repuestos','Lista para retirar','Entregada'] as $s){$st=$pdo->prepare('SELECT COUNT(*) c FROM work_orders WHERE current_status=?');$st->execute([$s]);$counts[$s]=$st->fetch()['c'];}
$orders=$pdo->query('SELECT wo.*, c.name client_name, v.brand, v.model, v.plate FROM work_orders wo JOIN clients c ON c.id=wo.client_id JOIN vehicles v ON v.id=wo.vehicle_id ORDER BY wo.updated_at DESC LIMIT 8')->fetchAll();
include 'partials/header.php'; ?>
<div class="grid">
  <section class="card span12"><h1>Panel de taller</h1><p class="muted">Hola <?=h(auth()['name'])?>. Estado general de Lopez Motos.</p><a class="btn primary" href="order_new.php">+ Nueva orden</a></section>
  <?php foreach($counts as $k=>$v): ?><div class="card span4"><div class="muted"><?=h($k)?></div><div class="kpi"><?=$v?></div></div><?php endforeach; ?>
  <section class="card span12"><h2>Últimas órdenes</h2><table><tr><th>Orden</th><th>Cliente</th><th>Moto</th><th>Estado</th><th></th></tr>
  <?php foreach($orders as $o): ?><tr><td><?=h($o['code'])?></td><td><?=h($o['client_name'])?></td><td><?=h($o['brand'].' '.$o['model'].' '.$o['plate'])?></td><td><span class="status"><?=h($o['current_status'])?></span></td><td><a class="btn" href="order_view.php?id=<?=$o['id']?>">Ver</a></td></tr><?php endforeach; ?></table></section>
</div><?php include 'partials/footer.php'; ?>
