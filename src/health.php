<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
try { ensure_schema(); $v=db()->query('SELECT VERSION()')->fetchColumn(); echo json_encode(['ok'=>true,'app'=>app_name(),'database'=>'ok','mysql'=>$v,'time'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE); } catch(Throwable $e) { http_response_code(503); echo json_encode(['ok'=>false,'app'=>app_name(),'database'=>'error','message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
