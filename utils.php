<?php

require_once("path/to/config.php");

$_db_connections = 0;
$_pdo = NULL;

function db_connect() {
    global $_db_connections;
    global $_pdo;
    if ($_db_connections > 0) {
        $_db_connections += 1;
        return;
    }
    $_pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=UTF8",
                    DB_USERNAME, DB_PASSWORD);
    $_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $_pdo;
}

function db_disconnect() {
    global $_db_connections;
    global $_pdo;
    if ($_db_connections > 1) {
        $_db_connections -= 1;
        return;
    }
    $_pdo = NULL;
}

function exec_query($query, $params, $fetch=true, $lastInsertId=false) {
    global $_pdo;
    $disconnect_when_done = ($_pdo === NULL);
    if ($disconnect_when_done) $_pdo = db_connect();
    $qry = $_pdo->prepare($query);
    try {
        $qry->execute($params);
    } catch (Exception $e) {
        echo $e->getMessage()."\n";
        echo $query,"\n";
        print_r($params);
    }
    if ($fetch) $res = $qry->fetchAll();
    else if ($lastInsertId) $res = $_pdo->lastInsertId();
    else $res = NULL;
    if ($disconnect_when_done) db_disconnect();
    return $res;
}

?>
