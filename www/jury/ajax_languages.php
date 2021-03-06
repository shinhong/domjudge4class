<?php declare(strict_types=1);

require('init.php');
header('content-type: application/json');

$languages = $DB->q("TABLE SELECT langid AS id, name,
                     CONCAT(name, ' (', langid, ')') AS search FROM language
                     WHERE (name LIKE %c OR langid LIKE %c)" .
                    (isset($_GET['enabled']) ? " AND allow_submit = 1" : ""),
                   $_GET['q'], $_GET['q']);

echo dj_json_encode($languages);
