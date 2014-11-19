<?php
/***********************************************************
 Copyright (C) 2012-2014 Hewlett-Packard Development Company, L.P.

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
***********************************************************/


define("TITLE_showjobs", _("Show Jobs"));

  /**
   * @brief Sort compare function to order $JobsInfo by job_pk
   * @param $JobsInfo1 Result from GetJobInfo
   * @param $JobsInfo2 Result from GetJobInfo
   * @return <0,==0, >0 
   */
  function CompareJobsInfo($JobsInfo1, $JobsInfo2)
  {
    $job_pk1 = $JobsInfo1["job"]["job_pk"];
    $job_pk2 = $JobsInfo2["job"]["job_pk"];

    return $job_pk2 - $job_pk1;
  }

class showjobs extends FO_Plugin
{
  var $Name       = "showjobs";
  var $Title      = TITLE_showjobs;
  var $Version    = "1.0";
  var $MenuOrder  = 5;
  var $Dependency = array("browse");
  var $DBaccess   = PLUGIN_DB_WRITE;
  var $MaxUploadsPerPage = 10;  /* max number of uploads to display on a page */
  var $nhours = 672;  /* 672=24*28 (4 weeks) What is considered a recent number of hours for "My Recent Jobs" */

  var $Colors=array(
	"Queued" => "#FFFFCC",	// "white-ish",
	"Scheduled" => "#99FFFF", // "blue-ish",
	"Running" => "#99FF99",	// "green",
	"Finished" => "#D3D3D3", // "lightgray",
	"Blocked" => "#FFCC66",	// "orange",
	"Failed" => "#FF6666"	// "red"
	);

  /***********************************************************
   RegisterMenus(): Customize submenus.
   ***********************************************************/
  function RegisterMenus()
  {
    menu_insert("Main::Jobs::My Recent Jobs",$this->MenuOrder -1,$this->Name, $this->MenuTarget);

    if (@$_SESSION['UserLevel'] != PLUGIN_DB_ADMIN) return;

    if (GetParm("mod", PARM_STRING) == $this->Name)
    {
      /* Set micro menu to select either all users or this user */
      $allusers = GetParm("allusers",PARM_INTEGER);
      if ($allusers == 0)
      {
        $text = _("Show uploads from all users");
        $URI = $this->Name . Traceback_parm_keep(array( "page" )) . "&allusers=1";
      }
      else
      {
        $text = _("Show only your own uploads");
        $URI = $this->Name . Traceback_parm_keep(array( "page")) . "&allusers=0";
      }

      menu_insert("showjobs::$text", 1, $URI, $text);
    }

  } // RegisterMenus()


  /**
   * @brief Returns geeky scan details about the jobqueue item
   * @param $job_pk
   * @return Return job and jobqueue record data in an html table.
   **/
  function ShowJobDB($job_pk)
  {
    global $PG_CONN;

    $V = "";
    $Fields=array('jq_pk'=>'jq_pk',
                  'job_pk'=>'jq_job_fk',
                  'Job Name'=> 'job_name',
                  'Agent Name'=>'jq_type',
                  'Priority'=>'job_priority',
                  'Args'=>'jq_args',
                  'jq_runonpfile'=>'jq_runonpfile',
                  'Queued'=>'job_queued',
                  'Started'=>'jq_starttime',
                  'Ended'=>'jq_endtime',
                  'Elapsed HH:MM:SS'=>'elapsed',
                  'Status'=>'jq_end_bits',
                  'Items processed'=>'jq_itemsprocessed',
	              'Submitter'=>'job_user_fk',
	              'Upload'=>'job_upload_fk', 
                  'Log'=>'jq_log');
    $Uri = Traceback_uri() . "?mod=" . $this->Name . "&upload=";

    $sql = "SELECT *, jq_endtime-jq_starttime as elapsed FROM jobqueue LEFT JOIN job ON job.job_pk = jobqueue.jq_job_fk WHERE jobqueue.jq_pk = $job_pk";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $Row = pg_fetch_assoc($result);
    pg_free_result($result);

if (!empty($Row["job_upload_fk"]))
{
    /* get the upload filename */
    $uploadsql = "select upload_filename, upload_desc from upload where upload_pk='$Row[job_upload_fk]'";
    $uploadresult = pg_query($PG_CONN, $uploadsql);
    DBCheckResult($uploadresult, $uploadsql, __FILE__, __LINE__);
    if (pg_num_rows($uploadresult) == 0)
    {
      /* upload has been deleted so try to get the job name from the original upload job record */
      $JobName = $this->GetJobName($Row["job_upload_fk"]);
      $upload_filename = "Deleted " . $JobName;
      $upload_desc = '';
    }
    else
    {
      $uploadRow = pg_fetch_assoc($uploadresult);
      $upload_filename = $uploadRow['upload_filename'];
      $upload_desc = $uploadRow['upload_desc'];
    }
    pg_free_result($uploadresult);

    if (empty($Row['jq_pk'])) 
    { 
      return _("Job history record is no longer available"); 
    }

    $uploadtree_tablename = GetUploadtreeTableName($Row['job_upload_fk']);
    if (NULL == $uploadtree_tablename) strcpy($uploadtree_tablename, "uploadtree");

    /* Find the uploadtree_pk for this upload so that it can be used in the browse link */
    $uploadtreeRec = GetSingleRec($uploadtree_tablename, "where parent is NULL and upload_fk='$Row[job_upload_fk]'");
    $uploadtree_pk = $uploadtreeRec['uploadtree_pk'];
}
    $V .= "<h3>" . _("Geeky Scan Details") . "</h3>";

    $V .= "<table class='text' border=1 name='jobtable1'>\n";

    /* upload file name link to browse */
    if (!empty($Row['job_upload_fk']))
    {
      $V .= "<tr><th colspan=2 align=left>";
      $V .= "<a title='Click to browse this upload' href='" . Traceback_uri() . "?mod=browse&upload=" . $Row['job_upload_fk'] . "&item=" . $uploadtree_pk . "'>";
      $V .= $upload_filename;
      if (!empty($upload_desc)) $V .= " (" . $upload_desc . ")";
      $V .= "</a>";
      $V .= "</th></tr>";
    }

    $text = _("Field");
    $text1 = _("Value");
    $V .= "<tr><th>$text</th><th align=left>$text1</th></tr>\n";
    foreach($Fields as $Label=>$Field)
    {
      $V .= "  <tr><th align='left'>$Label</th><td>";
      switch($Field)
	  {
	    case 'jq_itemsprocessed':
            $V .= number_format($Row[$Field]);
            break;
	    case 'jq_end_bits':
            $V .= $this->jobqueueStatus($Row);
            break;
    	case 'jq_pk':
            if (!empty($Row['job_upload_fk']))
            {
    		  $V .= "<a href='$Uri" . $Row['job_upload_fk'] . "'>" . htmlentities($Row[$Field]) . "</a>";
              $V .= " (" . _("Click to view jobs for this upload") . ")";
            }
            else
            {
              $Uri2 = Traceback_uri() . "?mod=" . $this->Name;
              $Back = "(" . _("Click to return to Show Jobs") . ")";
    		  $V .= "<a href='$Uri2'>$Row[$Field] $Back</a>";
            }
    		break;
    	case 'job_upload_fk':
    		if (!empty($Row[$Field]))
            {
		      $Browse = Traceback_uri() . "?mod=browse&upload=" . htmlentities($Row[$Field]);
		      $V .= "<a href='$Browse'>" . htmlentities($Row[$Field]) . "</a>";
              $V .= " (" . _("Click to browse upload") . ")";
            }
            break;
        case 'jq_log':
            if (!empty($Row[$Field]))
            {
              $V .= "<pre>";
              if (file_exists($Row[$Field])) $V .= file_get_contents($Row[$Field]);
              $V .= "</pre>";
            }
            break;
        case 'job_user_fk':
            if (!empty($Row[$Field]))
            {
              $usersql = "select user_name from users where user_pk='$Row[$Field]'";
              $userresult = pg_query($PG_CONN, $usersql);
              DBCheckResult($userresult, $usersql, __FILE__, __LINE__);
              $UserRow = pg_fetch_assoc($userresult);
              $V .= $UserRow['user_name'];
              pg_free_result($userresult);
            }
            break;
        case 'jq_args':
            $jq_args_temp = $Row[$Field];
            $jq_args_show = $jq_args_temp;
            if (!empty($jq_args_temp))
            {
              $pos = strpos($jq_args_temp, ' SVN ');
              if ($pos) {
                $jq_args_show = substr($jq_args_temp, 0, $pos + 4);
              }
              $pos = strpos($jq_args_temp, ' CVS ');
              if ($pos) {
                $jq_args_show = substr($jq_args_temp, 0, $pos + 4);
              }
              $pos = strpos($jq_args_temp, ' Git ');
              if ($pos) {
                $jq_args_show = substr($jq_args_temp, 0, $pos + 4);
              }
              $V .= $jq_args_show;
            }
            break;
	    default:
            if (array_key_exists($Field, $Row)) $V .= htmlentities($Row[$Field]);
            break;
      }
      $V .= "</td></tr>\n";
    }

    /* Close the table */
    $V .= "</table>\n";
    return($V);
  } // ShowJobDB()


  /**
   * @brief Find all the jobs for a given set of uploads.
   *
   * @param $upload_pks Array of upload_pk's
   * @param $Page Get data for this display page.  Starts with zero.
   *
   * @return array of job_pk's for the uploads
   **/
  function Uploads2Jobs($upload_pks, $Page=0)
  {
    global $PG_CONN;

    $JobArray = array();
    $JobCount = count($upload_pks);
    if ($JobCount == 0) return $JobArray;

    /* calculate index of starting upload_pk */
    if (empty($Page)) 
      $Offset = 0; 
    else 
      $Offset = $Page * $this->MaxUploadsPerPage;

    /* Get the job_pk's for each for each upload_pk */
    $LastOffset = ($JobCount < $this->MaxUploadsPerPage) ? $Offset+$JobCount : $this->MaxUploadsPerPage;
    for(; $Offset < $LastOffset; $Offset++)
    {
      $upload_pk = $upload_pks[$Offset];
      $sql = "select job_pk, job_user_fk from job where job_upload_fk='$upload_pk' order by job_pk asc";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      if (pg_num_rows($result))
      {
        while($Row = pg_fetch_assoc($result)) $JobArray[] = $Row['job_pk'];
      }
      pg_free_result($result);
    }
    return $JobArray;
  }  /* Uploads2Jobs() */


  /**
   * @brief Find all of my jobs submitted within the last n hours.
   *
   * @param $nhours Number of hours that the job report spans
   *
   * @return array of job_pk's 
   **/
  function MyJobs($nhours)
  {
    global $PG_CONN;

    $JobArray = array();
    $allusers = GetParm("allusers",PARM_INTEGER); 

    if ($allusers == 0) 
      $allusers_str = "job_user_fk='$_SESSION[UserId]' and ";
    else
      $allusers_str = "";
    
    $sql = "select job_pk, job_upload_fk from job where $allusers_str job_queued >= (now() - interval '$nhours hours') order by job_queued desc";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    while($Row = pg_fetch_assoc($result)) 
    {
      if (!empty($Row['job_upload_fk']))
      {
        $UploadPerm = GetUploadPerm($Row['job_upload_fk']);
        if ($UploadPerm < PERM_WRITE) continue;
      }
      $JobArray[] = $Row['job_pk'];
    }
    pg_free_result($result);

    return $JobArray;
  }  /* MyJobs() */


  /**
   * @brief Get job queue data from db.
   *
   * @param $job_pks Array of $job_pk's to display.
   * @param $Page Get data for this display page.  Starts with zero.
   *
   * @return array of job data
   * \code
   *    JobData [job_pk]  Array of job records (JobRec)
   *
   *    JobRec['jobqueue'][jq_pk] = array of JobQueue records
   *    JobRec['jobqueue'][jq_pk]['depends'] = array of jq_pk's for dependencies
   *    JobRec['upload'] = array for upload record
   *    JobRec['job'] = array for job record
   *    JobRec['uploadtree'] = array for parent uploadtree record
   *
   *    JobQueue ['jq_pk'] = jq_pk
   *    JobQueue ['jq_type'] = jq_type
   *    JobQueue ['jq_itemsprocessed'] = jq_itemsprocessed
   *    JobQueue ['jq_starttime'] = jq_starttime
   *    JobQueue ['jq_endtime'] = jq_endtime
   *    JobQueue ['jq_log'] = jq_log
   *    JobQueue ['jq_endtext'] = jq_endtext
   *    JobQueue ['jq_end_bits'] = jq_end_bits
   * \endcode
   **/
  function GetJobInfo($job_pks, $Page=0)
  {
    global $PG_CONN;

    /* Output data array */
    $JobData = array();
    foreach($job_pks as $job_pk)
    {
      /* Get job table data */
      $JobRec = GetSingleRec("job", "where job_pk='$job_pk'");

      $JobData[$job_pk]["job"] = $JobRec;

      if (!empty($JobRec["job_upload_fk"]))
      {
        $upload_pk = $JobRec["job_upload_fk"];

        /* Get Upload record for job */
        $UploadRec = GetSingleRec("upload", "where upload_pk='$upload_pk'");
        if (!empty($UploadRec))
        {
          $JobData[$job_pk]["upload"] = $UploadRec;

          /* Get Upload record for uploadtree */
          $uploadtree_tablename = GetUploadtreeTableName($upload_pk);
          if (NULL == $uploadtree_tablename) strcpy($uploadtree_tablename, "uploadtree");
          $UploadtreeRec = GetSingleRec($uploadtree_tablename, "where upload_fk='$upload_pk' and parent is null");
          $JobData[$job_pk]["uploadtree"] = $UploadtreeRec;
        }
        else
        {
          $UploadRec = GetSingleRec("upload", "right join job on upload_pk = job_upload_fk where job_upload_fk = '$upload_pk'");

          /* upload has been deleted so try to get the job name from the original upload job record */
          $JobName = $this->GetJobName($UploadRec["job_upload_fk"]);

	      $UploadRec["upload_filename"] = "Deleted Upload: " . $UploadRec["job_upload_fk"] . "(" . $JobName . ")";

          $UploadRec["upload_pk"] = $UploadRec["job_upload_fk"];
          $JobData[$job_pk]["upload"] = $UploadRec;
        }
      }

      /* Get jobqueue table data */
      $sql = "select * from jobqueue where jq_job_fk='$job_pk' order by jq_pk asc";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      if (pg_num_rows($result))
      {
        $Rows = pg_fetch_all($result);
        foreach($Rows as $JobQueueRec)
        {
          $jq_pk = $JobQueueRec["jq_pk"];

          /* Get dependencies */
          $DepArray = array();
          $sql = "select jdep_jq_depends_fk from jobdepends where jdep_jq_fk='$jq_pk' order by jdep_jq_depends_fk asc";
          $DepResult = pg_query($PG_CONN, $sql);
          DBCheckResult($DepResult, $sql, __FILE__, __LINE__);
          while ($DepRow = pg_fetch_assoc($DepResult)) $DepArray[] = $DepRow["jdep_jq_depends_fk"];
          $JobQueueRec["depends"] = $DepArray;
          pg_free_result($DepResult);
  
          $JobData[$job_pk]['jobqueue'][$jq_pk] = $JobQueueRec;
        }
      }
      else
        unset($JobData[$job_pk]);
      pg_free_result($result);
    }
    return $JobData;
  }  /* GetJobInfo() */


  /** 
   * @brief Return job name.  Used for deleted jobs
   * @param upload_pk
   * @return Original job name in job record.
   */
  function GetJobName($upload_pk)
  {
    global $PG_CONN;

    /* upload has been deleted so try to get the job name from the original upload job record */
    $sql = "SELECT job_name from job where job_upload_fk='$upload_pk' order by job_pk asc";
    $JobResult = pg_query($PG_CONN, $sql);
    DBCheckResult($JobResult, $sql, __FILE__, __LINE__);
    $JobRow = pg_fetch_assoc($JobResult);
    pg_free_result($JobResult);
    if (empty($JobRow))
      $JobName = $upload_pk;
    else
      $JobName = $JobRow['job_name'];
    return $JobName;
  }

  /**
   * @brief Returns an upload job status in html
   * @param $JobData
   * @return Returns an upload job status in html
   **/
  function Show	($JobData, $Page)
  {
    global $PG_CONN;
    $OutBuf = '';
    $NumJobs = count($JobData);
    if ($NumJobs == 0)
    {
      return _("There are no jobs to display");
    }
    
    /* Next/Prev menu */
    $Next = $NumJobs > $this->MaxUploadsPerPage;
    if ($NumJobs > $this->MaxUploadsPerPage)  $OutBuf .= MenuEndlessPage($Page, $Next); 

    /*****************************************************************/
    /* Now display the summary */
    /*****************************************************************/
    $Job=-1;
    $Blocked=array();
    $First=1;
    $Upload="-1";
    $Uri = Traceback_uri() . "?mod=" . $this->Name;
    $UriFull = $Uri . Traceback_parm_keep(array("upload"));
    $uploadStyle = "style='font:bold 10pt verdana, arial, helvetica; background:gold; color:white;'";
    $NoUploadStyle = "style='font:bold 10pt verdana, arial, helvetica; background:gold; color:black;'";
    $jobStyle = "style='font:bold 8pt verdana, arial, helvetica; background:lavender; color:black;'";
    $prevupload_pk = "";

    $OutBuf .= "<table class='text' border=1 width='100%' name='jobtable'>\n";
    $FirstJob = $Page * $this->MaxUploadsPerPage;
    $LastJob = ($Page * $this->MaxUploadsPerPage) + $this->MaxUploadsPerPage;
    $JobNumber = -1;
    /** if $single_browse is 1, represent alread has an upload browse link, if single_browse is 0, no upload browse link */
    $single_browse = 0;
    foreach ($JobData as $job_pk => $Job)
    {
      /* Upload  */
      if (!empty($Job["upload"]))
      {
        $UploadName = GetArrayVal("upload_filename", $Job["upload"]);
        $UploadDesc = GetArrayVal("upload_desc", $Job["upload"]);
        $upload_pk = GetArrayVal("upload_pk", $Job["upload"]);
        /** the column pfile_fk of the record in the table(upload) is NULL when this record is inserted */
        if ((!empty($upload_pk) && $prevupload_pk != $upload_pk) || (empty($upload_pk) && 0 == $single_browse))
        {
          $prevupload_pk = $upload_pk;
          $JobNumber++;

          /* Only display the jobs for this page */
          if ($JobNumber >= $LastJob) break;
          if ($JobNumber < $FirstJob) continue;

          /* blank line separator between pfiles */
	      $OutBuf .= "<tr><td colspan=7> <hr> </td></tr>";

          $OutBuf .= "<tr>";
          $OutBuf .= "<th $uploadStyle></th>";
          $OutBuf .= "<th colspan=4 $uploadStyle>";
          if(!empty($Job['uploadtree'])) 
          {
            $uploadtree_pk = $Job['uploadtree']['uploadtree_pk'];
            $OutBuf .= "<a title='Click to browse' href='" . Traceback_uri() . "?mod=browse&upload=" . $Job['job']['job_upload_fk'] . "&item=" . $uploadtree_pk . "'>";
          }
          else
            $OutBuf .= "<a $NoUploadStyle>";
          
          /* get $UserName if all jobs are shown */
          $UserName = "";
          $allusers = GetParm("allusers",PARM_INTEGER);
          if ($allusers > 0)
          {
            $UploadRec = GetSingleRec("upload", "where upload_pk={$Job['job']['job_upload_fk']}");
            if (!empty($UploadRec['user_fk']))
            {
              $UserRec = GetSingleRec("users", "where user_pk={$UploadRec['user_fk']}");
              $UserName = "&nbsp;&nbsp;&nbsp;($UserRec[user_name])";
            }
            else
            {
              $UserRec = GetSingleRec("users", "where user_pk={$Job['job']['job_user_fk']}");
              $UserName = "&nbsp;&nbsp;&nbsp;($UserRec[user_name])";
            }
          }

          $OutBuf .= $UploadName . $UserName;
          if (!empty($UploadDesc)) $OutBuf .= " (" . $UploadDesc . ")";
          $OutBuf .= "</a>";
          $OutBuf .= "</th>";
          $OutBuf .= "<th $uploadStyle></th>";
          $OutBuf .= "</tr>";
          $single_browse = 1;
        }
        else  if ($JobNumber < $FirstJob) continue;
      }
      else /* Show Jobs that are not attached to an upload */
      {
        $JobNumber++;
        /* Only display the jobs for this page */
        if ($JobNumber >= $LastJob) break;
        if ($JobNumber < $FirstJob) continue;

        /* blank line separator between pfiles */
        $OutBuf .= "<tr><td colspan=7> <hr> </td></tr>";
        $OutBuf .= "<tr>";
        $OutBuf .= "<th $NoUploadStyle></th>";
        $OutBuf .= "<th colspan=4 $NoUploadStyle>";
        $OutBuf .= $Job["job"]["job_name"];
        $OutBuf .= "</th>";
        $OutBuf .= "<th $NoUploadStyle></th>";
        $OutBuf .= "</tr>";
      }

      /* Job data */
      $OutBuf .= "<tr>";
      $OutBuf .= "<th $jobStyle>";
      $OutBuf .= _("Job/Dependency");
      $OutBuf .= "</th>";

      $OutBuf .= "<th $jobStyle>";
      $OutBuf .= _("Status");
      $OutBuf .= "</th>";

      $OutBuf .= "<th colspan=3 $jobStyle>";
      $OutBuf .= $Job["job"]["job_name"];
      $OutBuf .= "</th>";

      $OutBuf .= "<th $jobStyle>";
      $OutBuf .= "</th></tr>";
  
      /* Job queue */
      foreach ($Job['jobqueue'] as $jq_pk => $jobqueueRec)
      {
        $RowColor = $this->GetColor($jobqueueRec);
        $jobqueueStyle = $this->jobqueueStyle($RowColor);
        $OutBuf .= "<tr $jobqueueStyle>";

        /* Job/Dependency */
        $OutBuf .= "<td $jobqueueStyle>";
        $OutBuf .= "<a href='$UriFull&show=job&job=" . $jq_pk . "'>" ;
        $OutBuf .= $jq_pk;
        $OutBuf .= "</a>";
        $count = 0;
        if (!empty($jobqueueRec["depends"]))
        {
          foreach ($jobqueueRec["depends"] as $depend_jq_pk)
          {
            $OutBuf .= ($count++ == 0) ? " / " : ", ";
            $OutBuf .= "<a href='$UriFull&show=job&job=" . $depend_jq_pk . "'>" ;
            $OutBuf .= $depend_jq_pk;
            $OutBuf .= "</a>";
          }
        }
        $OutBuf .= "</td>";

        /* status */
        $Status = $jobqueueRec["jq_endtext"];
        $OutBuf .= "<td style='text-align:center'>$Status</td>";
        $isPaused = ($Status == "Paused") ? true : false;

        /* agent name */
        $OutBuf .= "<td>$jobqueueRec[jq_type]</td>";

        /* items processed */
        if ( $jobqueueRec["jq_itemsprocessed"] > 0)
        {
          $items = number_format($jobqueueRec['jq_itemsprocessed']);
          $OutBuf .= "<td style='text-align:right'>$items items</td>";
        }
        else
          $OutBuf .= "<td></td>";

        /* dates */
        $OutBuf .= "<td>";
        $OutBuf .= substr($jobqueueRec['jq_starttime'], 0, 16);
        if (!empty($jobqueueRec["jq_endtime"])) 
        {
          $OutBuf .= " - " . substr($jobqueueRec['jq_endtime'], 0, 16);
          $NumSecs = strtotime($jobqueueRec['jq_endtime']) - strtotime($jobqueueRec['jq_starttime']);
        }
        else
          $NumSecs = time()  - strtotime($jobqueueRec['jq_starttime']);

        /* Don't display items/sec unless the job has started */
        if ($jobqueueRec['jq_starttime'])
        {
          if ( $NumSecs > 0)
          {
            $ItemsPerSec = round($jobqueueRec['jq_itemsprocessed']/$NumSecs);
            if ($ItemsPerSec < 1)
              $OutBuf .= sprintf(" : (%01.2f items/sec)", $jobqueueRec['jq_itemsprocessed']/$NumSecs);
            else
              $OutBuf .= sprintf(" : (%d items/sec)", $ItemsPerSec);
          }
        }
        $OutBuf .= "</td>";

        /* actions, must be admin or own the upload  */
        if (($jobqueueRec['jq_end_bits'] == 0) 
             && (($_SESSION["UserLevel"] == PLUGIN_DB_ADMIN)
                 || ($_SESSION["UserId"] == $Job['job']['job_user_fk'])))
        {
          $OutBuf .= "<th $jobStyle>";
          if ($isPaused)
          {
            $text = _("Unpause");
            $OutBuf .= "<a href='$UriFull&action=restart&jobid=$jq_pk' title='Un-Pause this job'>$text</a>";
          }
          else
          {
            $text = _("Pause");
            $OutBuf .= "<a href='$UriFull&action=pause&jobid=$jq_pk' title='Pause this job'>$text</a>";
          }
          $OutBuf .= " | ";
          $text = _("Cancel");
          $OutBuf .= "<a href='$UriFull&action=cancel&jobid=$jq_pk' title='Cancel this job'>$text</a>";
        }
        else
         $OutBuf .= "<th>";
        $OutBuf .= "</th></tr>";
      }
    }
    $OutBuf .= "</table>\n";

    if ($NumJobs > $this->MaxUploadsPerPage) $OutBuf .= "<p>" . MenuEndlessPage($Page, $Next); 
    return($OutBuf);
  } // Show()


  /**
   * @brief Are there any unfinished jobqueues in this job?
   * @param $Job
   * @return true if $Job contains unfinished jobqueue's
   **/
  function isUnfinishedJob($Job)
  {
    foreach ($Job['jobqueue'] as $jq_pk => $jobqueueRec)
    {
      if ($jobqueueRec['jq_end_bits'] == 0) return true;
    } 
    return false;
  }  /* isUnfinishedJob()  */

  
  /**
   * @brief Get the style for a jobqueue rec.
   * This is color coded based on $color
   * @param $color
   * @return a string containing the style
   **/
  function jobqueueStyle($color)
  {
    $jobqueueStyle = "style='font:normal 8pt verdana, arial, helvetica; background:$color; color:black;'";
    return $jobqueueStyle;
  }  /* jobqueueStyle() */


  /**
   * @brief Get the status of a jobqueue item
   * If the job isn't known to the scheduler, then report the status based on the
   * jobqueue table.  If it is known to the scheduler, use that for the status.
   * @param $jobqueueRec
   * @return a string describing the status
   **/
  function jobqueueStatus($jobqueueRec)
  {
    $status = "";
    $response_from_scheduler;
    $error_info;

    /* check the jobqueue status.  If the job is finished, return the status. */
    if (!empty($jobqueueRec['jq_endtext'])) 
      $status .= "$jobqueueRec[jq_endtext]";

    if (!strstr($status, "Success") and !strstr($status, "Fail") and $jobqueueRec["jq_end_bits"])
    {
      $status .= "<br>";
      if ($jobqueueRec["jq_end_bits"] == 0x1)
        $status .= _("Success");
      else if ($jobqueueRec["jq_end_bits"] == 0x2)
        $status .= _("Failure");
      else if ($jobqueueRec["jq_end_bits"] == 0x4)
        $status .= _("Nonfatal");
    }

    /* if the job is incomplete, check the scheduler status */
//    $Command = "status " . $jobqueueRec['jq_pk'];
//    $rv = fo_communicate_with_scheduler($Command, $response_from_scheduler, $error_info);
//    if ($rv == false) return $response_from_scheduler . $error_info;
//echo "from sched: $response_from_scheduler<br> error_info: $error_info <br>";
    return $status;

    /* The job is active, so ask the scheduler for the status */
  }  /* jobqueueStatus() */


  /**
   * @brief Get the jobqueue row color
   * @return the color as a string
   **/
  function GetColor($jobqueueRec)
  {
    $Color=$this->Colors['Queued']; /* default */
    if ($jobqueueRec['jq_end_bits'] > 1)
    {
      $Color=$this->Colors['Failed'];
      $Blocked[$jobqueueRec['jq_pk']] = 1;
    }
/*
    else if ($Blocked[$jobqueueRec['jdep_jq_depends_fk']] == 1)
    {
      $Color=$this->Colors['Blocked'];
      $Blocked[$jobqueueRec['jq_pk']] = 1;
    }
*/
    else if (!empty($jobqueueRec['jq_starttime']) && empty($jobqueueRec['jq_endtime']))
    {
      $Color=$this->Colors['Scheduled'];
    }
    else if (!empty($jobqueueRec['jq_starttime']) && !empty($jobqueueRec['jq_endtime']))
    {
      $Color=$this->Colors['Finished'];
    }
    return $Color;
  }  /* GetColor()  */

  /***********************************************************
   Output(): This function returns the job queue status.
   ***********************************************************/
  function Output()
  {
    if ($this->State != PLUGIN_STATE_READY) { return(0); }

    $V="";
    $Page = "";
    $UploadPk = GetParm('upload',PARM_INTEGER);
    if (empty($UploadPk)) 
    { 
      $UploadPk = -1; 
    }
    else
    {
      $UploadPerm = GetUploadPerm($UploadPk);
      if ($UploadPerm < PERM_WRITE) 
      {
        $text = _("Permission Denied");
        echo "<h2>$text<h2>";
        return;
      }
    }

    switch($this->OutputType)
    {
      case "XML":
	    break;
      case "HTML":
        // micro menus
        $V .= menu_to_1html(menu_find($this->Name, $MenuDepth),0);

        /* Process any actions */
        if (@$_SESSION['UserLevel'] >= PLUGIN_DB_WRITE)
        {
          $jq_pk = GetParm("jobid",PARM_INTEGER);
          $Action = GetParm("action",PARM_STRING);
          $UploadPk = GetParm("upload",PARM_INTEGER);
          if (!empty($UploadPk))
          {
            $UploadPerm = GetUploadPerm($UploadPk);
            if ($UploadPerm < PERM_WRITE) 
            {
              $text = _("Permission Denied");
              echo "<h2>$text<h2>";
              return;
            }
          }
          $Page = GetParm('page',PARM_INTEGER);
          if (empty($Page)) $Page = 0;
          $jqtype = GetParm("jqtype",PARM_STRING);
          $ThisURL = Traceback_uri() . "?mod=" . $this->Name . "&upload=$UploadPk";
          $Job = GetParm('job',PARM_INTEGER);
          switch($Action)
          {
            case 'pause':
              if (empty($jq_pk)) break;
              $Command = "pause $jq_pk";
              $rv = fo_communicate_with_scheduler($Command, $response_from_scheduler, $error_info);
              if ($rv == false) $V .= _("Unable to pause job.") . " " . $response_from_scheduler . $error_info;
              echo "<script type=\"text/javascript\"> window.location.replace(\"$ThisURL\"); </script>";
    		  break;
    	    case 'restart':
              if (empty($jq_pk)) break;
              $Command = "restart $jq_pk";
              $rv = fo_communicate_with_scheduler($Command, $response_from_scheduler, $error_info);
              if ($rv == false) $V .= _("Unable to restart job.") . " " . $response_from_scheduler . $error_info;
              echo "<script type=\"text/javascript\"> window.location.replace(\"$ThisURL\"); </script>";
	    	  break;
            case 'cancel':
              if (empty($jq_pk)) break;
              $Msg = "\"" . _("Killed by") . " " . @$_SESSION['User'] . "\"";
              $Command = "kill $jq_pk $Msg";
              $rv = fo_communicate_with_scheduler($Command, $response_from_scheduler, $error_info);
              if ($rv == false) $V .= _("Unable to cancel job.") . $response_from_scheduler . $error_info;
              echo "<script type=\"text/javascript\"> window.location.replace(\"$ThisURL\"); </script>";
              break;
	        default:
		      break;
	      }
	    }

        if (!empty($Job))
    	  $V .= $this->ShowJobDB($Job);
        else 
        {
          if ($UploadPk) 
          {
            $upload_pks = array($UploadPk);
            $Jobs = $this->Uploads2Jobs($upload_pks, $Page);
          }
         else
          {
            $Jobs = $this->MyJobs($this->nhours);
          } 
          $JobsInfo = $this->GetJobInfo($Jobs, $Page);

          /* Sort jobs by job_pk (so most recent comes out first) */
          usort($JobsInfo, "CompareJobsInfo");

    	  $V .= $this->Show($JobsInfo, $Page);
        }

    	break;
      case "Text":
    	break;
      default:
    	break;
    }
    if (!$this->OutputToStdout) { return($V); }
    print "$V";
    return;
  }

};
$NewPlugin = new showjobs;
$NewPlugin->Initialize();

?>
