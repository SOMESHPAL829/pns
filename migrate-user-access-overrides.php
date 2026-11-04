<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/includes/db.php';
try{
    $sql=file_get_contents(__DIR__.'/migrations/018_portal_user_access_overrides.sql');
    if($sql===false)throw new RuntimeException('User access override migration missing.');
    db()->exec($sql);
    echo "Per-user permission and plant overrides installed. Existing users continue using role defaults.\n";
}catch(Throwable $e){fwrite(STDERR,'User access override migration failed: '.$e->getMessage()."\n");exit(1);}
