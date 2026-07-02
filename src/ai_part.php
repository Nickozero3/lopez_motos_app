<?php
require_once 'config.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

function normalize_text($text){
  $text = trim(preg_replace('/\s+/', ' ', (string)$text));
  return $text;
}

function guess_category($text){
  $t = mb_strtolower($text, 'UTF-8');
  $rules = [
    'Filtro' => ['filtro','filter','aceite','aire'],
    'Electricidad' => ['bujia','bujía','cdi','bobina','regulador','relay','rele','relé','bateria','batería','lampara','lámpara','led'],
    'Frenos' => ['freno','pastilla','zapata','disco'],
    'Transmisión' => ['cadena','piñon','piñón','corona','transmision','transmisión'],
    'Motor' => ['piston','pistón','cilindro','valvula','válvula','junta','reten','retén','embrague'],
    'Suspensión' => ['amortiguador','barral','reten barral','retén barral'],
    'Lubricantes' => ['aceite','lubricante','10w','20w','15w']
  ];
  foreach($rules as $cat=>$words){ foreach($words as $w){ if(str_contains($t,$w)) return $cat; } }
  return null;
}

function extract_viscosity($text){
  $u = strtoupper($text);
  if(preg_match('/\b(0W|5W|10W|15W|20W|25W)\s*[- ]?\s*(20|30|40|50|60)\b/u', $u, $m)){
    return $m[1].$m[2];
  }
  // OCR a veces lee mal el guion o deja separado 15W 50
  if(preg_match('/\b(0W|5W|10W|15W|20W|25W)\b.*?\b(20|30|40|50|60)\b/u', $u, $m)){
    return $m[1].$m[2];
  }
  return null;
}

function extract_brand($text){
  $u = strtoupper($text);
  $brands = ['MOTUL','CASTROL','IPONE','YAMALUBE','HONDA','YAMAHA','ELF','SHELL','REPSOL','VALVOLINE','WSTANDARD','NGK','DID','RIFFEL','OSAKA','BOSCH'];
  foreach($brands as $b){ if(str_contains($u,$b)) return ucfirst(strtolower($b)); }
  return null;
}

function guess_sku($text){
  $u = strtoupper($text);
  // En aceites, números como 5100 / 7100 suelen ser línea comercial, no SKU interno real.
  // Igual lo devolvemos como código sugerido porque sirve para búsqueda en stock.
  if(str_contains($u,'MOTUL') && preg_match('/\b(3000|5000|5100|7100|8100)\b/', $u, $m)) return $m[1];

  preg_match_all('/\b[A-Z0-9][A-Z0-9\-\.\/]{3,}\b/u', $u, $m);
  $bad = ['MADE','ORIG','ORIGINAL','REPUESTO','MOTOR','MOTOS','HONDA','YAMAHA','MOTOMEL','ZANELLA','ENGINE','COMMUTING','RECREATIONAL'];
  foreach($m[0] ?? [] as $code){
    if(!in_array($code, $bad, true) && preg_match('/\d/', $code)) return $code;
  }
  return null;
}

function title_case_product($s){
  $s = trim(preg_replace('/\s+/', ' ', $s));
  $s = mb_convert_case(mb_strtolower($s,'UTF-8'), MB_CASE_TITLE, 'UTF-8');
  // mantener formatos técnicos en mayúscula
  $s = preg_replace_callback('/\b(\d+w\d{2})\b/i', fn($m)=>strtoupper($m[1]), $s);
  $s = str_ireplace(['Motul','Ipone','Elf','Ngk','Did'], ['Motul','Ipone','ELF','NGK','DID'], $s);
  return $s;
}

function guess_name($text, $category, $sku){
  $clean = normalize_text($text);
  if($clean === '') return null;
  $u = strtoupper($clean);
  $brand = extract_brand($clean);
  $visc = extract_viscosity($clean);

  // Regla especial para el caso que mostraste: aceite Motul 5100 15W50.
  // OCR.Space suele leer primero "RECREATIONAL & COMMUTING", que es un slogan, no el nombre.
  if($brand && strtoupper($brand)==='MOTUL' && preg_match('/\b(3000|5000|5100|7100|8100)\b/', $u, $m)){
    $name = 'Motul '.$m[1];
    if($visc) $name .= ' '.$visc;
    return $name;
  }

  // Priorizar líneas que tengan marca + número/modelo, no slogans.
  $lines = preg_split('/\R+/', trim($text));
  $stop = '/(RECREATIONAL|COMMUTING|ENGINE OIL|MOTOR OIL|MADE IN|INDUSTRIA|WWW|TEL|PART|RACING|ORIGINAL QUALITY)/iu';
  $best = null; $bestScore = -999;
  foreach($lines as $line){
    $line = normalize_text($line);
    if(mb_strlen($line) < 3) continue;
    $score = 0;
    if($brand && stripos($line,$brand)!==false) $score += 6;
    if(preg_match('/\d/', $line)) $score += 3;
    if($visc && stripos(str_replace('-','',$line), $visc)!==false) $score += 3;
    if(preg_match($stop, $line)) $score -= 6;
    if(mb_strlen($line) > 45) $score -= 2;
    if($score > $bestScore){ $bestScore=$score; $best=$line; }
  }

  if($best && $bestScore > 0) return title_case_product($best);

  // Último recurso: primera línea útil.
  foreach($lines as $line){
    $line = normalize_text($line);
    if(mb_strlen($line) < 4) continue;
    if(preg_match($stop, $line)) continue;
    return title_case_product($line);
  }
  return mb_substr($clean,0,70);
}

try{
  if(empty($_FILES['photo']) || $_FILES['photo']['error']!==UPLOAD_ERR_OK) throw new Exception('Subí una foto primero.');
  $mime=mime_content_type($_FILES['photo']['tmp_name']);
  if(!in_array($mime,['image/jpeg','image/png','image/webp'])) throw new Exception('Formato de imagen no válido. Usá JPG, PNG o WEBP.');

  $apiKey=getenv('OCR_SPACE_API_KEY') ?: getenv('OCRSPACE_API_KEY') ?: '';
  if(!$apiKey){
    echo json_encode(['ok'=>false,'message'=>'No hay OCR_SPACE_API_KEY configurada. Cargá tu key de OCR.Space en el .env y reiniciá Docker.']);
    exit;
  }

  $b64='data:'.$mime.';base64,'.base64_encode(file_get_contents($_FILES['photo']['tmp_name']));
  $payload=[
    'base64Image'=>$b64,
    'language'=>'eng',
    'isOverlayRequired'=>'false',
    'OCREngine'=>'2',
    'scale'=>'true',
    'detectOrientation'=>'true'
  ];

  $ch=curl_init('https://api.ocr.space/parse/image');
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['apikey: '.$apiKey],
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_TIMEOUT=>45
  ]);
  $raw=curl_exec($ch);
  if($raw===false) throw new Exception(curl_error($ch));
  $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
  curl_close($ch);
  if($status<200 || $status>=300) throw new Exception('Error OCR HTTP '.$status.': '.$raw);

  $json=json_decode($raw,true);
  if(!$json) throw new Exception('OCR.Space respondió algo inválido.');
  if(!empty($json['IsErroredOnProcessing'])){
    $err=$json['ErrorMessage'] ?? $json['ErrorDetails'] ?? 'No se pudo procesar la imagen.';
    if(is_array($err)) $err=implode(' ', $err);
    throw new Exception('Error OCR.Space: '.$err);
  }
  $parsed=$json['ParsedResults'][0]['ParsedText'] ?? '';
  $text=normalize_text($parsed);
  if($text==='') throw new Exception('No pude leer texto en la foto. Probá con buena luz y que se vea la etiqueta/código.');

  $category=guess_category($text);
  $sku=guess_sku($text);
  $name=guess_name($parsed,$category,$sku);
  $notes="Texto leído por OCR.Space: ".$text."\n\nRevisar datos antes de guardar.";
  echo json_encode(['ok'=>true,'data'=>['name'=>$name,'sku'=>$sku,'category'=>$category,'notes'=>$notes,'raw_text'=>$text]]);
}catch(Exception $e){
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
