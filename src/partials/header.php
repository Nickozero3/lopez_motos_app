<?php require_once __DIR__.'/../config.php'; ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h(app_name())?></title><link rel="stylesheet" href="assets/style.css"></head><body>
<header class="top"><div class="brand"><span class="logo">LM</span><div><b><?=h(app_name())?></b><small>Sistema de taller profesional</small></div></div>
<?php if(auth()): ?><nav><a href="index.php">Panel</a><a href="orders.php">Órdenes</a><a href="clients.php">Clientes</a><a href="parts.php">Repuestos</a><a href="logout.php">Salir</a></nav><?php endif; ?></header><main class="wrap">
