<?php
if(PHP_SAPI!=='cli')exit;
require 'C:/SMS-Awarie/common.php';
require 'C:/SMS-Awarie/groups.php';
$p=db('sqlite',config());ensureSchema($p);
echo "Login: ";$login=trim(fgets(STDIN));echo "Haslo (widoczne podczas wpisywania, min. 12 znakow): ";$pass=trim(fgets(STDIN));if(!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D',$login)||strlen($pass)<12)exit("Niepoprawne dane\n");$p->prepare('INSERT INTO web_users VALUES(?,?,?,?)')->execute([uid(),$login,password_hash($pass,PASSWORD_DEFAULT),'admin']);echo "OK\n";
