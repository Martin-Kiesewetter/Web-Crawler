<?php
declare(strict_types=1);

Error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'webanalyse.php';
$wa = new webanalyse();
$db = mysqli_connect("localhost", "root", "", "screaming_frog");


$wa-> doCrawl(1);

