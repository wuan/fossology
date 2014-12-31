<?php
/***********************************************************
 * Copyright (C) 2014 Siemens AG
 * Author: Johannes Najjar
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

namespace Fossology\Lib\Util;

use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\Highlight;
use Fossology\Lib\Data\LicenseRef;
use Fossology\Lib\View\HighlightRenderer;

class LicenseOverviewPrinter extends Object
{

  /**
   * @var UploadDao
   */
  private $uploadDao;

  /**
   * @var LicenseDao
   */
  private $licenseDao;

  /**
   * @var ClearingDao
   */
  private $clearingDao;

  /**
   * @var HighlightRenderer
   */
  private $highlightRenderer;

  function __construct(LicenseDao $licenseDao, UploadDao $uploadDao, ClearingDao $clearingDao, HighlightRenderer $highlightRenderer)
  {
    $this->uploadDao = $uploadDao;
    $this->licenseDao = $licenseDao;
    $this->clearingDao = $clearingDao;
    $this->highlightRenderer = $highlightRenderer;
  }

  /**
   * @param $hasDiff
   * @return string rendered legend box
   */
  function legendBox($hasDiff)
  {
    $colorMapping = $this->highlightRenderer->colorMapping;

    $output = '<b>' . _("Legend") . ':</b><br/>';
    if ($hasDiff)
    {
      $output .= _("license text");
      foreach (array(Highlight::MATCH => 'identical', Highlight::CHANGED => 'modified', Highlight::ADDED => 'added', Highlight::DELETED => 'removed',
                     Highlight::SIGNATURE => 'license relevant text', Highlight::KEYWORD => 'keyword')
               as $colorKey => $txt)
      {
        $output .= '<br/>'.$this->highlightRenderer->createStyle( $colorKey, $txt, $colorMapping) . $txt . '</span>';
      }
    } else
    {
      $output .= '<span style="background:' . $colorMapping['any'] . '">' . _("license relevant text") . "</span>";
    }
    return '<div style="background-color:white; padding:2px; border:1px outset #222222; width:150px; position:fixed; right:5px; bottom:5px;">' . $output . '</div>';
  }

  /**
   * @param array which keys are agentNames
   * @param int uploadId
   */
  private function fillAgentLatestMap($agentLatestMap,$uploadId)
  {
    foreach( array_keys($agentLatestMap) as $agentName)
    {
      $latestAgentId = GetAgentKey($agentName, "why is this agent missing?");
      if (empty($latestAgentId))
      {
        throw new \Exception('currupted match');
      }

      global $container;
      /* @param DbManager */
      $dbManager = $container->get("db.manager");
      $sql = "SELECT agent_fk,ars_success,ars_endtime FROM ".$agentName."_ars WHERE upload_fk=$1 ORDER BY agent_fk";
      $stmt = __METHOD__.".$agentName";
      $dbManager->prepare($stmt,$sql);
      $res = $dbManager->execute($stmt,array($uploadId));
      $latestArs = array();
      while($row=$dbManager->fetchArray($res) )
      {
        $key = $row['ars_success']?'good':($row['ars_endtime']?'bad':'n/a');
        $latestArs[$key] = $row['agent_fk'];
      }
      $dbManager->freeResult($res);
      
      $agentLatestMap[$agentName] = array('latest'=>$latestAgentId,'ars'=>$latestArs);
    }
    return $agentLatestMap;    
  }
  
  /**
   * @param $licenseMatches
   * @param $uploadId
   * @param $uploadTreeId
   * @param int $selectedAgentId
   * @param int $selectedLicenseId
   * @param int $selectedLicenseFileId
   * @param bool $hasHighlights
   * @param bool $showReadOnly
   * @return string
   */
  function createLicenseOverview($licenseMatches, $uploadId, $uploadTreeId,
          $selectedAgentId=0, $selectedLicenseId=0, $selectedLicenseFileId=0, $hasHighlights=false, $showReadOnly=true, $editLicense=true)
  {
    if (count($licenseMatches)==0)
    {
      return '<br><b>'._('No scanner result found').'</b>';
    }

    $agentLatestMap = array();    
    foreach($licenseMatches as $agents)
    {
      foreach (array_keys($agents) as $agentName)
      {
        $agentLatestMap[$agentName] = array();
      }
    }
    $agentLatestMap = $this->fillAgentLatestMap($agentLatestMap,$uploadId);

    $output = "<h3>" . _("Scanner results") . "</h3>\n";
    foreach ($licenseMatches as $fileId => $agents)
    {
      ksort($agents);
      $breakCounter = 0;
      foreach ($agents as $agentName => $foundLicenses)
      {
        if($breakCounter++ > 0) {
            $output .= "<br/>";
        }
        $latestAgentId = $agentLatestMap[$agentName]['latest'];
        $output .= $this->renderMatches($foundLicenses,$agentName,$latestAgentId,$agentLatestMap[$agentName]['ars'],
                           $uploadId, $uploadTreeId, $selectedAgentId, $selectedLicenseId, $selectedLicenseFileId, $hasHighlights, $showReadOnly, $editLicense);
      }
    }

    if ($hasHighlights)
    {
      $output .= $this->legendBox($selectedAgentId > 0 && $selectedLicenseId > 0);
    }
    if ($selectedAgentId > 0 && $selectedLicenseId > 0)
    {
      $format = GetParm("format", PARM_STRING);
      $output .= "<br/><a href='" .
          Traceback_uri() . "?mod=view-license&upload=$uploadId&item=$uploadTreeId&format=$format'>" . _("Exit") . "</a> " . _("specific license mode") . "<br/>";
    }
    return $output;
  }

  
  private function renderMatches($foundLicenses,$agentName,$latestAgentId,$agentArs,
          $uploadId, $uploadTreeId, $selectedAgentId, $selectedLicenseId, $selectedLicenseFileId, $hasHighlights, $showReadOnly, $editLicense)
  {        
    $latestMatches = array();
    $obsoleteMatches = array();
    foreach ($foundLicenses as $licenseShortname => $agentDetails)
    {
      $mostRecentAgentId = max(array_keys($agentDetails) );
      if ($mostRecentAgentId == $latestAgentId)
      {
        $latestMatches[$licenseShortname] = $agentDetails[$mostRecentAgentId];
        continue;
      }
      $obsoleteMatches[$licenseShortname] = $agentDetails[$mostRecentAgentId];
    }

    $output = _('The newest version of')." $agentName "._('license scanner');
    if (count($latestMatches)==0 && array_key_exists('good', $agentArs) && $latestAgentId==$agentArs['good'])
    {
      $output .= ' ' . _('ran successful on this file without any match');
    }
    else if (count($latestMatches)==0 && array_key_exists('bad', $agentArs) && $latestAgentId==$agentArs['bad'])
    {
      $output .= ' ' . _('failed on this upload');
      $output .= '. '._('Please re-run');
      $link = Traceback_uri()."?mod=agent_add&upload=$uploadId&agents[]=agent_$agentName";
      $output .= " <a href='$link'>Scheduler for $agentName</a>";
    }
    else if (count($latestMatches)==0)
    {
      $output .= ' ' . _('did not finish the run on this file');
    }
    else
    {
      $output .= ' ' . _('found') . ':<b>';
      foreach ($latestMatches as $licenseShortname => $agentDetails)
      {
        $output .= "<br/>\n";
        $output .= $this->printLicenseNameAsLink($licenseShortname);
        $output .= $this->createPercentInfoAndAnchors($uploadId, $uploadTreeId, $selectedAgentId, $selectedLicenseId, $selectedLicenseFileId, $hasHighlights, $agentDetails, $showReadOnly);
		$permission = $_SESSION['UserLevel'];
        if($agentName == "nomos" && $permission >= PERM_AUDIT && $editLicense) {
		  $fl_fk = key($latestMatches[$licenseShortname]);
		  $napk = $latestMatches[$licenseShortname][$fl_fk]['agentId'];
          $infotext = _("Edit nomos license reference");
		  $button_text = _("Edit license");
          $output .= "<br/><a title='$infotext' href='" . Traceback_uri() ."?mod=nomos_change_license&fl_pk=$fl_fk";
          $output .= "&upload=$uploadId&item=$uploadTreeId&napk=$napk";
          $output .= "' style='color:#00aa00;font-style:mono;font-size:12px' class='buttonLink'>" .$button_text. "</a><br/>";
        }
      }
      $output .= '</b><br/>';
    }

    if(count($obsoleteMatches)>0)
    {
      $text = _("Other versions of the $agentName license scanner also found");
      $output .=  "<br/>\n$text: <b>";
      foreach ($obsoleteMatches as $licenseShortname => $agentDetails)
      {
        $output .= "<br/>\n";
        $output .= $this->printLicenseNameAsLink($licenseShortname);
        $output .= $this->createPercentInfoAndAnchors($uploadId, $uploadTreeId, $selectedAgentId, $selectedLicenseId, $selectedLicenseFileId, $hasHighlights, $agentDetails, $showReadOnly);
		$permission = $_SESSION['UserLevel'];
        if($agentName == "nomos" && $permission >= PERM_AUDIT && $editLicense) {
		  $fl_fk = key($obsoleteMatches[$licenseShortname]);
		  $napk = $obsoleteMatches[$licenseShortname][$fl_fk]['agentId'];
          $infotext = _("Edit nomos license reference");
		  $button_text = _("Edit license");
          $output .= "<br/><a title='$infotext' href='" . Traceback_uri() ."?mod=nomos_change_license&fl_pk=$fl_fk";
          $output .= "&upload=$uploadId&item=$uploadTreeId&napk=$napk";
          $output .= "' style='color:#00aa00;font-style:mono;font-size:12px' class='buttonLink'>" .$button_text. "</a><br/>";
        }
      }
      $output .= '</b><br/><br/>';
    }
    return $output;
  }  
  
  /**
   * @param $Upload
   * @param $Item
   * @param $selectedAgentId
   * @param $selectedLicenseId
   * @param $selectedLicenseFileId
   * @param $hasHighlights
   * @param $agentRecentDetails
   * @param $showReadOnly
   * @return string
   */
  private function createPercentInfoAndAnchors($Upload, $Item, $selectedAgentId, $selectedLicenseId, $selectedLicenseFileId, $hasHighlights, $agentRecentDetails, $showReadOnly)
  {
    $output = "";
    $foundIndex = 1;

    foreach ($agentRecentDetails as $licenseFileId => $scanDetails)
    {
      if (!empty($output))
      {
        $output .= ", ";
      }
      $licenseId = $scanDetails['licenseId'];
      $foundLabel = '#' . $foundIndex++;
      if ($showReadOnly)
      {
        $output .= $this->createAnchor($Upload, $Item, $selectedAgentId, $selectedLicenseId, $selectedLicenseFileId, $hasHighlights, $scanDetails['agentId'], $licenseId, $licenseFileId, $foundLabel);
      } else
      {
        $output .= $foundLabel;
      }
      if (!empty($scanDetails['percent']))
      {
        $output .= ': ' . $scanDetails['percent'] . '%';
      }
    }
    return "($output)";
  }

  /**
   * @param $Upload
   * @param $Item
   * @param $selectedAgentId
   * @param $selectedLicenseId
   * @param $selectedLicenseFileId
   * @param $hasHighlights
   * @param $mostRecentAgentId
   * @param $licenseId
   * @param $licenseFileId
   * @param $foundLabel
   * @return string
   */
  private function createAnchor($Upload, $Item, $selectedAgentId, $selectedLicenseId, $selectedLicenseFileId, $hasHighlights, $mostRecentAgentId, $licenseId, $licenseFileId, $foundLabel)
  {

    $format = GetParm("format", PARM_TEXT) ?: "text";
    $linkTarget = Traceback_uri() . "?mod=view-license&upload=$Upload&item=$Item&format=$format&licenseId=$licenseId&agentId=$mostRecentAgentId&highlightId=$licenseFileId#highlight";
    if (intval($licenseId) != $selectedLicenseId || intval($licenseFileId) != $selectedLicenseFileId || intval($mostRecentAgentId) != $selectedAgentId)
    {
      $output = '<a title="' . _("Show This License Diff") . '" href="' . $linkTarget . '">' . $foundLabel . '</a>';
    } else
    {
      $output = $foundLabel;
      if ($hasHighlights)
      {
        $output .= '<a title="' . _("Jump To This License Diff") . '" href="' . $linkTarget . '">&nbsp;&#8595;&nbsp;</a>';
      }
    }
    return $output;
  }

  /**
   * @param $Upload
   * @param $uploadTreeId
   * @param bool $noConcludedLicenseYet
   * @return array
   */
  public function createEditButton($Upload, $uploadTreeId, $noConcludedLicenseYet=true)
  {
    $output ="";
    /** edit this license */
    $col = $noConcludedLicenseYet ? '#ff0000' : '#00aa00';
    $text =$noConcludedLicenseYet ? _("Add concluded license") : _("Edit concluded license");
    /** go to the license change page */
	$permission = $_SESSION['UserLevel'];
//    if (plugin_find_id('change_license') >= 0)
	if ($permission >= PERM_AUDIT)
    {
      $editLicenseText = _("Edit the license of this file");
      $output = '<a title="' . $editLicenseText . '" href="' . Traceback_uri() . '?mod=change_license';
      $output .= "&upload=$Upload&item=$uploadTreeId";


      $output .= '" style="color:'. $col .';font-style:mono" class="buttonLink">' . $text . '</a><br/>';


    }
    return $output;
  }


  /**
   * @param $licenseShortName
   * @param string $licenseFullName
   * @return array
   */
  public function printLicenseNameAsLink($licenseShortName, $licenseFullName = "")
  {
    if (empty($licenseFullName))
    {
      $displayName = $licenseShortName;
    } else
    {
      $displayName = $licenseFullName;
    }
    $text = _("License Reference");
    $text2 = _("License Text");
    $output = "<a title='$text' href='javascript:;'";
    $output .= " onClick=\"javascript:window.open('";
    $output .= Traceback_uri();
    $output .= "?mod=view-license";
    $output .= "&lic=";
    $output .= urlencode($licenseShortName);
    $output .= "','$text2','width=600,height=400,toolbar=no,scrollbars=yes,resizable=yes');\"";
    $output .= ">$displayName";
    $output .= "</a> ";
    return $output;
  }

  /**
   * @param ClearingDecision[] $clearingDecWithLicenses
   * @return string
   */
  public function createRecentLicenseClearing($clearingDecWithLicenses)
  {
    $output = "<h3>" . _("Concluded license") . "</h3>\n";
     $cd= $this->clearingDao->newestEditedLicenseSelector->selectNewestEditedLicensePerFileID($clearingDecWithLicenses);

    /**
     *@var  ClearingDecision $cd
     */
    if($cd != null  )
    {
      /**
       * @var ClearingDecision $theLicense
       */
      $auditedLicenses = $cd->getLicenses();
      /**
       * @var LicenseRef[] $auditedLicenses
       */
      foreach ($auditedLicenses as $license)
      {
        $output .= $this->printLicenseNameAsLink($license->getShortName(), $license->getFullName());
        $output .= ", ";
      }
      $output = substr($output, 0, count($output) - 3);
      return $output;
    }
    else
    {
      return "";
    }
  }

  /**
   * @param $clearingDecWithLicenses
   * @return string
   */
  public function createWrappedRecentLicenseClearing($clearingDecWithLicenses)
  {
    $foundNothing=false;
    $output = "<div id=\"recentLicenseClearing\" name=\"recentLicenseClearing\">";
    if (!empty($clearingDecWithLicenses))
    {
      $output_TMP = $this->createRecentLicenseClearing($clearingDecWithLicenses);
      if(empty($output_TMP)) {
        $foundNothing =true;
      }
      else {
        $output .= $output_TMP;
      }
    }
    $output .= "</div>";
    return array($output, $foundNothing );
  }


} 
