<?php

declare(strict_types=1);

$loader = require __DIR__ . '/../vendor/autoload.php';

// route:forge:gen 的自动路由夹具：把 app\ 指向测试控制器目录，供反射枚举 public 方法
$loader->setPsr4('app\\', __DIR__ . '/Fixtures/AutoRouteApp/app/');
