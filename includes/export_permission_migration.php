<?php
declare(strict_types=1);

/** Preserve pre-upgrade download access once, never undo a later revocation. */
function portal_migrate_export_permissions(PDO $pdo): bool
{
    if($pdo->inTransaction())throw new LogicException('Run the export migration outside an existing transaction.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $pdo->beginTransaction();
    try{
        $insert=$pdo->prepare(($sqlite?'INSERT OR IGNORE':'INSERT IGNORE')." INTO portal_schema_migrations(migration_code) VALUES ('008_export_permissions')");
        $insert->execute();
        $applied=$insert->rowCount()===1;
        if($applied){
            $pdo->exec(($sqlite?'INSERT OR IGNORE':'INSERT IGNORE')." INTO portal_role_permissions(role_id,page_code,action_code) SELECT role_id,page_code,'export' FROM portal_role_permissions WHERE action_code='view' AND page_code IN ('oee-report','oee-range')");
        }
        $pdo->commit();return $applied;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
