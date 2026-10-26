<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/export_permission_migration.php';
try{
    $pdo=db();$sql=file_get_contents(__DIR__.'/migrations/008_export_permissions.sql');
    if($sql===false)throw new RuntimeException('Export permissions migration missing.');
    $pdo->exec($sql);
    $applied=portal_migrate_export_permissions($pdo);
    echo $applied?"Export permissions installed; existing report download access preserved.\n":"Export permissions already installed; grants unchanged.\n";
}catch(Throwable $e){fwrite(STDERR,'Export permissions migration failed: '.$e->getMessage()."\n");exit(1);}
