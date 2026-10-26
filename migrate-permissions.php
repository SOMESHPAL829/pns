<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/permissions.php';
try{
    $pdo=db();$sql=file_get_contents(__DIR__.'/migrations/007_portal_permissions.sql');
    if($sql===false)throw new RuntimeException('Permissions migration missing.');
    $pdo->exec($sql);
    $pdo->beginTransaction();
    $q=$pdo->query("SELECT id FROM portal_access_roles WHERE name='Standard user' AND is_builtin=1");
    if(!$q->fetchColumn()){
        $pdo->exec("INSERT INTO portal_access_roles(name,is_builtin) VALUES ('Standard user',1)");$id=(int)$pdo->lastInsertId();
        $q=$pdo->prepare('INSERT INTO portal_role_permissions(role_id,page_code,action_code) VALUES (?,?,?)');
        foreach(portal_standard_permissions() as $page=>$actions)foreach($actions as $action=>$allowed)if($allowed)$q->execute([$id,$page,$action]);
    }
    $pdo->commit();echo "Page permissions installed. Existing users, administrators and configured roles preserved.\n";
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'Permissions migration failed: '.$e->getMessage()."\n");exit(1);}
