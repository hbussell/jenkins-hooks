<?php

require_once __DIR__.'/vendor/autoload.php';

$app = new Silex\Application();

require_once __DIR__.'/config.php';

$sql = "SELECT * FROM build ";
$builds = $app['db']->fetchAssoc($sql);
var_dump($builds);
