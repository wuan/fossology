<?php
/***********************************************************
 * Copyright (C) 2014 Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

use Fossology\Lib\Dao\ClearingDao;

global $container;
global $SysConf;

$license = GetParm("license", PARM_RAW);
$uploadTreeId = GetParm("uploadTreeId", PARM_INTEGER);
$userId = GetParm("userId", PARM_INTEGER);

if (!empty($license) && !empty($uploadTreeId)) {
	$clearingDao = $container->get('dao.clearing');

	$licenses = array($license);
	$type = 1;
	$scope = 1;
	$comment = _("Reviewed");
	$remark = _("Reviewed");

    $clearingDao->insertClearingDecision($licenses, $uploadTreeId, $userId, $type, $scope, $comment, $remark);
	header('Location: ' . $_SERVER['HTTP_REFERER']);
}
?>

