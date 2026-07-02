<?php require_once 'config.php'; if(auth()) redirect('index.php'); $err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $st=db()->prepare('SELECT * FROM users WHERE username=? AND active=1'); $st->execute([$_POST['username']??'']); $u=$st->fetch();
  if($u && password_verify($_POST['password']??'', $u['password_hash'])){ $_SESSION['user']=['id'=>$u['id'],'name'=>$u['name'],'username'=>$u['username'],'role'=>$u['role']]; redirect('index.php'); }
  $err='Usuario o contraseña incorrectos';
}
include 'partials/header.php'; ?>
<div class="login card"><h1>Ingresar</h1><p class="muted">Lopez Motos · gestión profesional de taller</p><?php if($err):?><div class="notice"><?=h($err)?></div><?php endif;?>
<form method="post"><label>Usuario</label><input name="username" value="fabricio"><label>Contraseña</label><input type="password" name="password" value="123456"><button class="btn primary">Entrar</button></form>
<p class="muted">Usuario inicial: <b>fabricio</b> · clave: <b>123456</b></p></div><?php include 'partials/footer.php'; ?>
