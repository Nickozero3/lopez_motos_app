<?php
require_once 'config.php';
if(auth()) redirect('index.php');
$pageTitle='Ingresar'; $error='';
if(is_post()){
 try{verify_csrf();$st=db()->prepare('SELECT * FROM users WHERE username=? AND active=1');$st->execute([clean_text(post('username'))]);$u=$st->fetch();if(!$u||!password_verify((string)post('password'),$u['password_hash']))throw new RuntimeException('Usuario o contraseña incorrectos.');session_regenerate_id(true);$_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'username'=>$u['username'],'role'=>$u['role']];redirect('index.php');}catch(Throwable $e){$error=$e->getMessage();}
}
include 'partials/header.php';?>
<div class="login"><section class="card login-card"><span class="brand-mark">LM</span><h1>Acceso al taller</h1><p class="muted">Ingresá para administrar órdenes, motos y stock.</p><?php if($error):?><div class="alert alert-error"><span><?=h($error)?></span></div><?php endif;?><form method="post"><?=csrf_field()?><div class="field"><label class="required">Usuario</label><input name="username" autocomplete="username" required value="<?=h(post('username'))?>"></div><div class="field"><label class="required">Contraseña</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn btn-primary btn-block">Ingresar</button></form><p class="login-footer">Acceso inicial: fabricio / 123456</p></section></div>
<?php include 'partials/footer.php';?>
