<?php
declare(strict_types=1);

$employeeId = (int) ($_GET['employee_id'] ?? 0);
$target = '/coc_system/index.php?section=coc';
if ($employeeId > 0) {
    $target .= '&employee_id=' . $employeeId;
}

header('Location: ' . $target);
exit;
