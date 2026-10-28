<?php
declare(strict_types=1);

function workflow_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root=dirname(__DIR__);
$shell=file_get_contents($root.'/app-shell.php');
$modal=file_get_contents($root.'/js/modal-forms.js');
$export=file_get_contents($root.'/js/table-export.js');
$pagination=file_get_contents($root.'/js/table-pagination.js');
$styles=file_get_contents($root.'/css/portal.css');

workflow_check(is_string($shell)&&is_string($modal)&&is_string($export)&&is_string($pagination)&&is_string($styles),'Workflow source files could not be read.');
foreach(['record-create-form','record-edit-form','user-create-form','user-role-form','role-form','profile-form','password-form','downtime-form','chemical-item-form','chemical-rate-form','breakdown-form','chemical-consumption-form','threshold-form','cycle-form'] as $formId){
    workflow_check(str_contains($modal,'id: "'.$formId.'"'),'Modal workflow missing for '.$formId);
}
workflow_check(str_contains($shell,'js/modal-forms.js')&&str_contains($shell,'js/table-export.js'),'Modal or Excel workflow script is not loaded.');
workflow_check(str_contains($shell,'js/table-pagination.js'),'Table pagination script is not loaded.');
workflow_check(str_contains($modal,'window.PortalModalForms')&&str_contains($modal,'dialog.showModal()'),'Reusable modal controller is incomplete.');
workflow_check(str_contains($export,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')&&str_contains($export,'.xlsx'),'Export must create a genuine XLSX download.');
workflow_check(str_contains($export,'data-icon="arrow-up-tray"')&&!str_contains($export,'arrow-down-tray'),'Excel export icon must point upward.');
workflow_check(str_contains($export,'/^(action|actions)$/i'),'Excel export must omit action controls.');
workflow_check(str_contains($pagination,'const DEFAULT_SIZE = 15')&&str_contains($pagination,'table-pagination-summary'),'Professional default-15 table pagination is missing.');
workflow_check(str_contains($pagination,'serialHeader.textContent = "SR. No."')&&str_contains($pagination,'table-serial-cell'),'Automatic SR. No. columns are missing.');
workflow_check(str_contains($pagination,'const heading = serialHeader')&&str_contains($pagination,'sortDirection: "descending"')&&str_contains($pagination,'latest first by default'),'SR. No.-only latest-first sorting is missing.');
workflow_check(str_contains($pagination,'cleanActionCells')&&str_contains($pagination,'Node.TEXT_NODE'),'Unnecessary Action-column placeholders are not removed.');
workflow_check(str_contains($styles,'.portal-form-modal::backdrop')&&str_contains($styles,'.table-export-toolbar'),'Premium modal or table export styling is missing.');
workflow_check(str_contains($styles,'.portal-form-modal .btn[type="submit"]')&&str_contains($styles,'padding: 0.68rem 1.3rem'),'Modal submit button padding is inconsistent.');

echo "Portal modal workflow and XLSX export tests passed\n";
