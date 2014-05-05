<?php

require_once __DIR__.'/vendor/autoload.php';

$app = new Silex\Application();


$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver'   => 'pdo_mysql',
        'host'     => 'localhost',
        'dbname'     => 'relhub_jenkins',
        'user'     => 'root',
        'password'     => 'WebSols',
    ),
));


$sql = "SELECT * FROM build ";
$builds = $app['db']->fetchAssoc($sql);
var_dump($builds);
