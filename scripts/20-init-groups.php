<?php
// Execute with C:\xampp\php\php.exe, after a consistent SQLite backup.
require 'C:/SMS-Awarie/common.php';
require 'C:/SMS-Awarie/groups.php';
if (PHP_SAPI!=='cli') exit(1);
$p=db('sqlite',config());
ensureSchema($p);
$groups=$p->query('SELECT COUNT(*) FROM incident_groups')->fetchColumn();
$mapped=$p->query('SELECT COUNT(*) FROM ticket_groups')->fetchColumn();
echo "Migration OK. Groups: $groups, mapped tickets: $mapped\n";
