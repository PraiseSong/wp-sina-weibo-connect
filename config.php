<?php
@header('Content-Type: text/html; charset=UTF-8');

$protocal = 'http';
if ($_SERVER["HTTPS"] == "on"){
        $protocal .= "s";
}

define('WB_SKEY','07ee8787ff28f4fdd34487250a4a8c66');
define( "WB_AKEY" , '499635422' );
define( "WB_CALLBACK_URL" , $protocal.'://'.$_SERVER['HTTP_HOST'].'/wp-login.php?loggedout=true' );
