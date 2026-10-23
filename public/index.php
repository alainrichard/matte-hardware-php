<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
ini_set('display_errors','0');
header_remove('X-Powered-By');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header('X-Frame-Options: DENY');header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
try{
    if(($_SERVER['HTTPS']??'')==='on')header('Strict-Transport-Security: max-age=31536000');
    if(str_starts_with($path,'/order/')||str_starts_with($path,'/admin')||$path==='/account'){header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');}
    if(str_starts_with($path,'/api/')){
        header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');$method=$_SERVER['REQUEST_METHOD'];$webhook=$path==='/api/whatsapp/webhook';
        $multipart=str_starts_with($_SERVER['CONTENT_TYPE']??'','multipart/form-data');$limit=$multipart?100*1024*1024:($webhook?1048576:204800);
        if((int)($_SERVER['CONTENT_LENGTH']??0)>$limit)fail('Request is too large.',413);
        $raw=$multipart?'':file_get_contents('php://input',false,null,0,$limit+1);
        if(strlen($raw)>$limit)fail('Request is too large.',413);
        if(!$webhook&&!str_starts_with($path,'/api/payments/momo/callback/')&&($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')fail('Cross-site requests are not allowed.',403);
        $origin=$_SERVER['HTTP_ORIGIN']??'';$allowed=rtrim(env('APP_URL'),'/');if(!$webhook&&!str_starts_with($path,'/api/payments/momo/callback/')&&$origin&&$origin!==$allowed)fail('Origin is not allowed.',403);
        $b=$_POST;if(str_contains($_SERVER['CONTENT_TYPE']??'','application/json')&&$raw!==''){$b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($b))fail('Expected a JSON object.');}
        if(!$webhook&&!str_starts_with($path,'/api/payments/momo/callback/'))rate('api',300,60);
        require ROOT.'/src/api.php';api($path,$method,$b,$raw);
    }
    require ROOT.'/src/catalog.php';require ROOT.'/src/view.php';renderPage($path);
}catch(Throwable $ex){
    $status=$ex instanceof HttpError?$ex->status:($ex instanceof JsonException?400:500);$message=$ex instanceof HttpError?$ex->getMessage():($ex instanceof JsonException?'Invalid JSON request.':'Something went wrong. Please try again.');
    if($ex instanceof PDOException&&$ex->getCode()==='23000'){$status=409;$message='This record conflicts with existing data. Check unique values and related records.';}
    if($status===500)error_log((string)$ex);
    if(str_starts_with($path,'/api/'))json(['error'=>$message,...($ex instanceof HttpError?$ex->extra:[])],$status);
    http_response_code($status);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html><meta charset="utf-8"><title>MATTE</title><body><h1>MATTE</h1><p>'.e($message).'</p><p><a href="/">Return to the shop</a></p></body></html>';
}
