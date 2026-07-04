<?php
require_once __DIR__ . '/../config.php';
$pageTitle = $pageTitle ?? app_name();
$authenticated = auth() !== null;
$flashes = pull_flashes();
$icon = static fn(string $path): string => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="'.$path.'"/></svg>';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#080808">
<title><?=h($pageTitle)?> · <?=h(app_name())?></title>
<link rel="stylesheet" href="assets/style.css?v=20260704-stock-select">
</head>
<body class="<?=$authenticated?'app-body':'public-body'?>">
<?php if($authenticated): ?>
<div class="app-shell">
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand-mark">LM</span><span class="brand-copy"><strong><?=h(app_name())?></strong><small>Gestión de taller</small></span></div>
  <nav class="sidebar-nav">
    <a class="nav-item <?=active_nav('index.php')?>" href="index.php"><?=$icon('M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z')?><span>Panel</span></a>
    <a class="nav-item <?=(active_nav('orders.php')||active_nav('order_new.php')||active_nav('order_view.php'))?'is-active':''?>" href="orders.php"><?=$icon('M7 3h10v2h3a1 1 0 0 1 1 1v15H3V6a1 1 0 0 1 1-1h3V3Zm2 2h6V4H9v1Zm-4 2v12h14V7h-2v2H7V7H5Zm3 5h8v2H8v-2Zm0 4h6v2H8v-2Z')?><span>Órdenes</span></a>
    <a class="nav-item <?=(active_nav('clients.php')||active_nav('client_view.php'))?'is-active':''?>" href="clients.php"><?=$icon('M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0-2a3 3 0 1 1 0-6 3 3 0 0 1 0 6Zm0 3c-5 0-9 2.5-9 6v3h18v-3c0-3.5-4-6-9-6Zm-7 7v-1c0-2 2.8-4 7-4s7 2 7 4v1H5Z')?><span>Clientes y motos</span></a>
    <a class="nav-item <?=active_nav('parts.php')?>" href="parts.php"><?=$icon('m14.7 6.3 3-3a5 5 0 0 0-6.4 6.4l-7.6 7.6a2.1 2.1 0 0 0 3 3l7.6-7.6a5 5 0 0 0 6.4-6.4l-3 3-3-3Z')?><span>Stock</span></a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-card"><span class="avatar"><?=h(function_exists('mb_substr')?mb_substr(auth()['name'],0,1,'UTF-8'):substr(auth()['name'],0,1))?></span><span class="user-copy"><strong><?=h(auth()['name'])?></strong><small><?=h(ucfirst(auth()['role']))?></small></span></div>
    <a class="nav-item" href="logout.php"><?=$icon('M10 3H4a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h6v-2H5V5h5V3Zm4.6 4.6L13.2 9l2 2H8v2h7.2l-2 2 1.4 1.4L19 12l-4.4-4.4Z')?><span>Cerrar sesión</span></a>
  </div>
</aside>
<div class="app-content">
<header class="topbar">
  <button class="icon-button menu-toggle" type="button" data-menu-toggle aria-label="Abrir menú" aria-expanded="false"><?=$icon('M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z')?></button>
  <div class="topbar-title"><small><?=h(app_name())?></small><strong><?=h($pageTitle)?></strong></div>
  <a class="btn btn-primary topbar-action" href="order_new.php"><?=$icon('M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z')?><span>Nueva orden</span></a>
</header>
<main class="main-content">
<?php foreach($flashes as $flash): ?><div class="alert alert-<?=h($flash['type'])?>" data-alert><span><?=h($flash['message'])?></span><button type="button" class="alert-close" data-alert-close>×</button></div><?php endforeach; ?>
<?php else: ?>
<header class="public-header"><a class="public-brand" href="login.php"><span class="brand-mark">LM</span><span><strong><?=h(app_name())?></strong><small>Taller de motos</small></span></a></header>
<main class="public-main">
<?php endif; ?>
