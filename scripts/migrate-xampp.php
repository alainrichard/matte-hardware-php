<?php
// Uses a dedicated, initially empty database in the local XAMPP MariaDB server.
// All commands must succeed before the app configuration is switched.
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$repo=$root;$snapshot=realpath($argv[1]??$root.'/storage/docker-snapshot-final.json');
if(!$snapshot){fwrite(STDERR,"Source snapshot not found.\n");exit(1);}
$target=['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_NAME'=>$argv[2]??'matte_store','DB_USER'=>'root','DB_PASSWORD'=>''];
$migrationEnv=$root.'/storage/xampp-migration.env';$configured=file_get_contents($root.'/.env');
foreach($target as $key=>$value)$configured=preg_replace_callback('/^'.preg_quote($key,'/').'=.*$/m',fn()=>$key.'='.$value,$configured);
file_put_contents($migrationEnv,$configured);
$environment=array_merge(getenv(),['MATTE_ENV_FILE'=>$migrationEnv]);
foreach([
 [$root.'/scripts/create-database.php'],
 [$root.'/scripts/console.php','init'],
 [$root.'/scripts/import-snapshot.php',$snapshot],
 [$root.'/scripts/verify-snapshot.php',$snapshot],
] as $args){$process=proc_open([PHP_BINARY,...$args],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$repo,$environment);if(!is_resource($process)||proc_close($process)!==0){fwrite(STDERR,"Migration stopped. PHP configuration has not been switched.\n");exit(1);}}
$file=$root.'/.env';$text=file_get_contents($file);file_put_contents($root.'/storage/pre-xampp.env',$text);
foreach([...$target,'APP_URL'=>'http://localhost:8188'] as $key=>$value){$pattern='/^'.preg_quote($key,'/').'=.*$/m';$line=$key.'='.$value;$text=preg_match($pattern,$text)?preg_replace_callback($pattern,fn()=>$line,$text):$text."\n".$line;}
file_put_contents($file,$text);echo "XAMPP database imported and verified. PHP now uses http://localhost:8188.\n";
