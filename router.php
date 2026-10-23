<?php
// Development server only: php -S 127.0.0.1:8080 -t public router.php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
if(preg_match('~(?:^|/)\.[^/]*~',$path)){http_response_code(404);exit;}
if(preg_match('~\.(?:env|sql|log|bak|ini|conf|old|swp)$|^/uploads/.*\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh|shtml)(?:/|$)~i',$path)){http_response_code(404);exit;}
$file=realpath(__DIR__.'/public'.$path);
if($file&&str_starts_with($file,realpath(__DIR__.'/public').DIRECTORY_SEPARATOR)&&is_file($file)&&!str_ends_with($file,'.php'))return false;
require __DIR__.'/public/index.php';
