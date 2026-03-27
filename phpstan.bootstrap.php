<?php

use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__.'/vendor/autoload.php';

new Dotenv()->loadEnv(__DIR__.'/.env');
