<?php
/*
Copyright (C) 2014, Siemens AG
Author: Johannes Najjar

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\Dao;

use Fossology\Lib\BusinessRules\NewestEditedLicenseSelector;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\ClearingDecisionBuilder;
use Fossology\Lib\Data\ClearingDecWithLicenses;
use Fossology\Lib\Data\DatabaseEnum;
use Fossology\Lib\Data\FileTreeBounds;
use Fossology\Lib\Data\LicenseRef;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Util\Object;
use Monolog\Logger;

class ClearingDao extends Object
{
  /**
   * @var DbManager
   */
  private $dbManager;

  /**
   * @var Logger
   */
  private $logger;

  /**
   * @var NewestEditedLicenseSelector
   */
  public $newestEditedLicenseSelector;

  /**
   * @var UploadDao
   */
  private $uploadDao;

  /**
   * @param DbManager $dbManager
   */
  function __construct(DbManager $dbManager)
  {
    $this->dbManager = $dbManager;
    $this->logger = new Logger(self::className());
    $this->newestEditedLicenseSelector = new NewestEditedLicenseSelector();
    $this->uploadDao = new UploadDao($dbManager);
  }

  /**
   * \brief get all the licenses for a single file or uploadtree
   *
   * @param $uploadTreeId
   * @return ClearingDecision[]
   */
  function getFileClearings($uploadTreeId)
  {
    $fileTreeBounds = $this->uploadDao->getFileTreeBounds($uploadTreeId);
    return $this->getFileClearingsFolder($fileTreeBounds);
  }

  /**
   * @param FileTreeBounds $fileTreeBounds
   * @return ClearingDecision[]
   */
  function getFileClearingsFolder(FileTreeBounds $fileTreeBounds)
  {
    //The first join to uploadtree is to find out if this is the same upload <= this needs to be uploadtree
    //The second gives all the clearing decisions which correspond to a filehash in the folder <= we can use the special upload table
    $uploadTreeTable = $fileTreeBounds->getUploadTreeTableName();

    $sql_upload="";
    if ('uploadtree_a' == $uploadTreeTable) {
      $sql_upload = "ut.upload_fk=$1  and ";
    }

    $statementName = __METHOD__.$uploadTreeTable;

    $sql="SELECT
           CD.clearing_pk AS id,
           CD.uploadtree_fk AS uploadtree_id,
           CD.pfile_fk AS pfile_id,
           users.user_name AS user_name,
           CD.user_fk AS user_id,
           CD_types.meaning AS type,
           CD_scopes.meaning AS scope,
           CD.comment AS comment,
           CD.reportinfo AS reportinfo,
           CD.date_added AS date_added,
           ut2.upload_fk = $1 AS same_upload,
           ut2.upload_fk = $1 and ut2.lft BETWEEN $2 and $3 AS is_local,
           LR.rf_pk as license_id,
           LR.rf_shortname as shortname,
           LR.rf_fullname  as fullname
         FROM clearing_decision CD
         LEFT JOIN users ON CD.user_fk=users.user_pk
         INNER JOIN uploadtree ut2 ON CD.uploadtree_fk = ut2.uploadtree_pk
         INNER JOIN ".$uploadTreeTable." ut ON CD.pfile_fk = ut.pfile_fk
         LEFT JOIN clearing_licenses CL on CL.clearing_fk = CD.clearing_pk
         LEFT JOIN license_ref LR on CL.rf_fk=LR.rf_pk
         LEFT JOIN clearing_decision_types CD_types ON CD.type_fk=CD_types.type_pk
         LEFT JOIN clearing_decision_scopes CD_scopes ON CD.scope_fk=CD_scopes.scope_pk
           WHERE ".$sql_upload." ut.lft BETWEEN $2 and $3
         GROUP BY id,uploadtree_id,pfile_id,user_name,user_id,type,scope,comment,reportinfo,date_added,same_upload,is_local,
         license_id, shortname, fullname
         ORDER by CD.pfile_fk, CD.clearing_pk desc";

    $this->dbManager->prepare($statementName, $sql);

    // the array needs to be sorted with the newest clearingDecision first.
    $result = $this->dbManager->execute($statementName, array($fileTreeBounds->getUploadId(), $fileTreeBounds->getLeft(), $fileTreeBounds->getRight()));
    $clearingsWithLicensesArray = array();

    $previousClearingId  = -1;
    $licenses=array();
    $clearingDecisionBuilder = ClearingDecisionBuilder::create();
    $firstMatch = true;
    while ($row = $this->dbManager->fetchArray($result))
    {
      $clearingId = $row['id'];
      $licenseId = $row['license_id'];
      $licenseShortName = $row['shortname'];
      $licenseName = $row['fullname'];

      if($clearingId === $previousClearingId) {
        //append To last finding
        $this->appendToLicenses($licenseId, $licenseShortName, $licenseName, $licenses);
      }
      else {
        //store the old one
        if(!$firstMatch) {
          $clearingDec =$clearingDecisionBuilder->setLicenses($licenses)
                                                ->build();
          $clearingsWithLicensesArray[] = $clearingDec;
        }

        $firstMatch = false;
        //prepare the new one
        $previousClearingId  = $clearingId;
        $licenses=array();
        $clearingDecisionBuilder = ClearingDecisionBuilder::create()
                                    ->setSameUpload($this->dbManager->booleanFromDb($row['same_upload']))
                                    ->setSameFolder($this->dbManager->booleanFromDb($row['is_local']))
                                    ->setClearingId($row['id'])
                                    ->setUploadTreeId($row['uploadtree_id'])
                                    ->setPfileId($row['pfile_id'])
                                    ->setUserName($row['user_name'])
                                    ->setUserId($row['user_id'])
                                    ->setType($row['type'])
                                    ->setScope($row['scope'])
                                    ->setComment($row['comment'])
                                    ->setReportinfo($row['reportinfo'])
                                    ->setDateAdded($row['date_added']);

        $this->appendToLicenses($licenseId, $licenseShortName, $licenseName, $licenses);
      }
    }

    //! Add the last match
    if(!$firstMatch)
    {
      $clearingDec = $clearingDecisionBuilder->setLicenses($licenses)
          ->build();
      $clearingsWithLicensesArray[] = $clearingDec;
    }

    $this->dbManager->freeResult($result);
    return $clearingsWithLicensesArray;
  }

  /**
   * @param $licenseId
   * @param $licenseShortName
   * @param $licenseName
   * @param $licenses
   */
  protected function appendToLicenses($licenseId, $licenseShortName, $licenseName,  &$licenses)
  {
    $licenseRef = new LicenseRef($licenseId, $licenseShortName, $licenseName);
    $licenses[] = $licenseRef;
  }

  /**
   * @param $id
   * @return LicenseRef[]
   */
  public function getFileClearingLicenses($id)
  {
    $licenses = array();
    $statementN = __METHOD__;
    $this->dbManager->prepare($statementN,
        "select
               license_ref.rf_pk as rf,
               license_ref.rf_shortname as shortname,
               license_ref.rf_fullname  as fullname
           from clearing_licenses
           left join license_ref on clearing_licenses.rf_fk=license_ref.rf_pk
               where clearing_fk=$1");

    $res = $this->dbManager->execute($statementN, array($id));

    while ($rw = $this->dbManager->fetchArray($res))
    {
      $licenses[] = new LicenseRef($rw['rf'], $rw['shortname'], $rw['fullname']);
    }
    $this->dbManager->freeResult($res);
    return $licenses;
  }

  /**
   * @return DatabaseEnum[]
   */
  public function getClearingTypes()
  {
    $clearingTypes = array();
    $statementN = __METHOD__;

    $this->dbManager->prepare($statementN, "select * from clearing_decision_types");
    $res = $this->dbManager->execute($statementN);
    while ($rw = pg_fetch_assoc($res))
    {
      $clearingTypes[] = new DatabaseEnum($rw['type_pk'], $rw['meaning']);
    }
    pg_free_result($res);
    return $clearingTypes;
  }

  /**
   * @return DatabaseEnum[]
   */
  public function getClearingScopes()
  {
    $clearingScopes = array();
    $statementN = __METHOD__;

    $this->dbManager->prepare($statementN, "select * from clearing_decision_scopes");
    $res = $this->dbManager->execute($statementN);

    while ($rw = pg_fetch_assoc($res))
    {
      $clearingScopes[] = new DatabaseEnum($rw['scope_pk'], $rw['meaning']);
    }

    return $clearingScopes;
  }

  /**
   * @param array $licenses
   * @param $uploadTreeId
   * @param $userid
   * @param $type
   * @param $scope
   * @param $comment
   * @param $remark
   */
  public function insertClearingDecision($licenses, $uploadTreeId, $userid, $type, $scope, $comment, $remark)
  {
    $statementName2 = __METHOD__ . ".d";
    $row = $this->dbManager->getSingleRow(
        "delete from clearing_decision where uploadtree_fk = $1 and type_fk = (select type_pk from clearing_decision_types where meaning ='To be determined')",
        array($uploadTreeId),
        $statementName2);

    $statementName = __METHOD__;
    $row = $this->dbManager->getSingleRow(
        "insert into clearing_decision (uploadtree_fk,pfile_fk,user_fk,type_fk,scope_fk,comment,reportinfo) values ($1,(SELECT pfile_fk FROM uploadtree where uploadtree_pk = $1),$2,$3,$4,$5,$6) RETURNING clearing_pk",
        array($uploadTreeId, $userid, $type, $scope, $comment, $remark),
        $statementName);
    $lastClearingId=$row['clearing_pk'];

    $statementN = __METHOD__ . ".l";
    $this->dbManager->prepare($statementN,
        "insert into clearing_licenses (clearing_fk,rf_fk) values ($1,$2)");

    foreach ($licenses as $license)
    {
      $res = $this->dbManager->execute($statementN, array($lastClearingId, $license));
      pg_free_result($res);
    }

  }

  /**
   * @param FileTreeBounds $fileTreeBounds
   * @return ClearingDecision[]
   */
  public function getGoodClearingDecPerFileId(FileTreeBounds $fileTreeBounds)
  {
    $licenseCandidates = $this->getFileClearingsFolder($fileTreeBounds);
    $filteredLicenses = $this->newestEditedLicenseSelector->extractGoodClearingDecisionsPerFileID($licenseCandidates);
    return $filteredLicenses;
  }

  /**
   * @param FileTreeBounds $fileTreeBounds
   * @return array
   */
  public function getEditedLicenseShortNamesFullList(FileTreeBounds $fileTreeBounds)
  {

    $licenseCandidates = $this->getFileClearingsFolder($fileTreeBounds);
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($licenseCandidates);
    return $licenses;
  }


  /**
   * @param FileTreeBounds $fileTreeBounds
   * @return string[]
   */
  public function getEditedLicenseShortnamesContained(FileTreeBounds $fileTreeBounds)
  {
    $licenses = $this->getEditedLicenseShortNamesFullList($fileTreeBounds);

    return array_unique($licenses);
  }

  /**
   * @param FileTreeBounds $fileTreeBounds
   * @return array
   */
  public function getEditedLicenseShortnamesContainedWithCount(FileTreeBounds $fileTreeBounds, $licenses=null)
  {
    if(empty($licenses))
    $licenses = $this->getEditedLicenseShortNamesFullList($fileTreeBounds);

    $uniqueLicenses = array_unique($licenses);
    $licensesWithCount = array();

    foreach ($uniqueLicenses as $licN)
    {
      $count = 0;
      foreach ($licenses as $candidate)
      {
        if ($licN == $candidate)
        {
          $count++;
        }
      }
      $licensesWithCount[$licN] = $count;
    }

    return $licensesWithCount;
  }


}
