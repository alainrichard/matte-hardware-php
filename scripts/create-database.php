<?php
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
$name=env('DB_NAME','matte_php');if(!preg_match('/^[a-zA-Z0-9_]+$/',$name))throw new RuntimeException('Invalid database name.');
$pdo=new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';charset=utf8mb4',env('DB_USER','root'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');echo "Target database ready.\n";
