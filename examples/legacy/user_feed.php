<?php
require __DIR__ . '/../../vendor/autoload.php';
header('Content-Type: application/json');
$api = new TikScraper\Legacy;
$user = $api->getUserFeed('ibaillanos');
echo $user->ToJson(true);
