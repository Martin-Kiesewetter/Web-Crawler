<?php
$db = mysqli_connect("localhost", "root", "", "screaming_frog");

$db->query("truncate table crawl");
// $db->query("insert into crawl (start_url, user_id) values ('https://kies-media.de/', 1)");
$db->query("insert into crawl (start_url, user_id) values ('https://kies-media.de/leistungen/externer-ausbilder-fuer-fachinformatiker/', 1)");

$db->query("truncate table urls");
$urls = $db->query("insert ignore into urls (id, url, crawl_id) select 1,start_url, id from crawl where id = 1"); #->fetch_all(MYSQLI_ASSOC)

$db->query("truncate table links");