<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/includes/db.php';
try{
    $sql=file_get_contents(__DIR__.'/migrations/017_portal_plant_access.sql');
    if($sql===false)throw new RuntimeException('Plant access migration missing.');
    db()->exec($sql);
    echo "Role and user plant access installed. Existing roles retain All Plants.\n";
}catch(Throwable $e){fwrite(STDERR,'Plant access migration failed: '.$e->getMessage()."\n");exit(1);}
