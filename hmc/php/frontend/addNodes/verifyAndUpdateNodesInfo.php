<?php
include_once '../util/Logger.php';
include_once '../conf/Config.inc';
include_once 'localDirs.php';
include_once "../util/lock.php";
include_once '../db/HMCDBAccessor.php';

include_once 'commandUtils.php';
include_once '../util/util.php';

$logger = new HMCLogger("verifyAndUpdateNodesInfo");
$dbAccessor = new HMCDBAccessor($GLOBALS["DB_PATH"]);
$lineSeparatorPattern = "HDP-------HDP";

$clusterName = $argv[1];
$deployUser = $argv[2];
$rootTxnId = $argv[3];
$mySubTxnId = $argv[4];
$parentSubTxnId = $argv[5];
$readFromFile = $argv[6];

$stageName = "verifyAndUpdateNodesInfo";
$prevStageName = "obtainNodesInfo";
$clusterDir = getClusterDir($clusterName);
$prevOutputDir = $clusterDir . $prevStageName . "/";
$outputDir = $clusterDir . $stageName . "/";

$logger->log_debug("OutputDir is : $outputDir");

if (is_dir($outputDir)) {
  rrmdir($outputDir);
}
mkdir($outputDir);

$allHosts = array();
$allBadHosts = array();

function updateStatusForNode ($outDir, $nodeName, $status)
{
  global $logger;

  $outArray = array();
  $doneFileName = $outDir . $nodeName . ".done";
  $outFileName = $outDir . $nodeName . ".out";
  $logger->log_debug("out file name is $doneFileName");
  $fd = fopen($doneFileName, "w");
  if ($fd == FALSE) {
    $outArray['success'] = FALSE;
    $outArray['reason'] = "Failed to update done status: $status for node: $nodeName";
    return $outArray;
  }

  $retval = fwrite($fd, $status);
  if ($retval == FALSE) {
    $outArray['success'] = FALSE;
    $outArray['reason'] = "Failed to write done status: $status for node: $nodeName";
    return $outArray;
  } else {
    $outArray['success'] = TRUE;
  }
  fclose($fd);

  $fd = fopen($outFileName, "w");
  if ($fd == FALSE) {
    $outArray['success'] = FALSE;
    $outArray['reason'] = "Failed to update out status: $status for node: $nodeName";
    return $outArray;
  }

  $retval = fwrite($fd, $status);
  if ($retval == FALSE) {
    $outArray['success'] = FALSE;
    $outArray['reason'] = "Failed to write out status: $status for node: $nodeName";
  } else {
    $outArray['success'] = TRUE;
  }
  fclose($fd);

  return $outArray;
}

function updateFailedStatusForNode ($outDir, $nodeName)
{
  return updateStatusForNode($outDir, $nodeName, 255);
}

function updateSuccessStatusForNode ($outDir, $nodeName)
{
  return updateStatusForNode($outDir, $nodeName, 0);
}

function getBadNodeReason ($count)
{
  switch ($count)
  {
  case 0:
    return "Failed to get memory info";
  case 1:
    return "Failed to get cpu count info";
  case 2:
    return "Failed to get OS architecture";
  case 3:
    return "Failed to get mount point info";
  case 4:
    return "Failed to get OS distribution type";
  case 5:
    return "Failed to get OS related information";
  case 6:
    return "Failed to get IP address";
  case 7:
    return "Failed to get public FQDN";
  case 8:
    return "Failed to get private FQDN";
  default:
    return "Unknown error in host discovery";
  }
}

function populateVal ($line, $count, $arr)
{
  switch ($count)
  {
  case 0:
    $arr["totalMem"] = rtrim($line);
    break;

  case 1:
    $arr["cpuCount"] = rtrim($line);
    break;

  case 2:
    $arr["osArch"] = rtrim($line);
    break;

  case 3:
    $arr["disksInfo"][] = rtrim($line);
    break;

  case 4:
    $arr["osType"] = rtrim($line);
    break;

  case 5:
    if (!isset($arr["os"])) {
      $arr["os"] = "";
    }
    $arr["os"] = $arr["os"] . " " . rtrim($line);
    break;

  case 6:
    $arr["ip"] = rtrim($line);
    break;
  case 7:
    if (!isset($arr["attributes"])) {
      $arr["attributes"] = array();
    }
    $arr["attributes"]["publicFQDN"] = strtolower(trim($line));
  case 8:
    if (!isset($arr["attributes"])) {
      $arr["attributes"] = array();
    }
    $arr["attributes"]["privateFQDN"] = strtolower(trim($line));
  default:
    break;
  }

  return $arr;
}


$finalOpStatus = "SUCCESS";
$failedCount = 0;
$successCount = 0;
if ($dirHandle = opendir($prevOutputDir)) {
  while (false !== ($entry = readdir($dirHandle))) {
    if ($entry == "." || $entry == "..") {
      continue;
    }

    $nodeStatus = "SUCCESS";
    // Only consider .out files
    if(!preg_match("/.out/", $entry)) {
      continue;
    }
    $nodeName = basename($entry, ".out");
    $nodeStatus = "SUCCESS";

    $doneFile = $prevOutputDir . $nodeName . ".done";
    if (file_exists($doneFile)) {
      // Read the contents of the done-file
      $doneFileContents = file_get_contents($doneFile);
      if (trim($doneFileContents) != "0") {
        $failedCount += 1;
        $nodeStatus = "FAILED";
        $finalOpStatus = "FAILED";
        updateFailedStatusForNode($outputDir, $nodeName);
        $logger->log_debug( "Contents of done file for $clusterName : $doneFileContents");
      }
    } else {
      $failedCount += 1;
      $nodeStatus = "FAILED";
      $finalOpStatus = "FAILED";
      updateFailedStatusForNode($outputDir, $nodeName);
      $logger->log_debug("Update failed because file contents of $doneFile is empty");
    }

    // Initialize this host's array
    $thisHostArray = array();
    $thisHostArray["hostName"] = strtolower($nodeName);
    $thisHostArray["totalMem"] = 0;
    $thisHostArray["cpuCount"] = 0;
    $thisHostArray["osArch"] = "";
    $thisHostArray["disksInfo"] = array();
    $thisHostArray["osType"] = "";
    $thisHostArray["os"] = "";
    $thisHostArray["ip"] = $nodeName; // To be unique
    $thisHostArray["attributes"] = array();

    if ($nodeStatus != "FAILED") {
      // parse the file for the contents we need
      // if any exit value != 0, we need to set the host as bad
      $hostOutFd = fopen($prevOutputDir.$entry, "r");
      if ($hostOutFd === FALSE) {
        $logger->log_error("Failed to open file to read: ". $prevOutputDir.$entry);
        $thisHostArray["badHealthReason"] = "No data obtained for host";
        $finalOpStatus = "FAILED";
        $nodeStatus = "FAILED";
      } else {
        $goodReturnValCount = 0;
        while (!feof($hostOutFd)) {
          $line = fgets($hostOutFd, 4096);
          if (preg_match("/".$lineSeparatorPattern."0/", $line)) {
            $goodReturnValCount += 1;
          } else if (preg_match("/".$lineSeparatorPattern."/", $line)) {
            // this particular node is dead
            // add to db saying failed.
            $failedCount += 1;
            $thisHostArray["badHealthReason"] = getBadNodeReason($goodReturnValCount);
            $finalOpStatus = "FAILED";
            $nodeStatus = "FAILED";
            updateFailedStatusForNode($outputDir, $nodeName);
            // write to file if bad so as to be shown as json in frontend.
            array_push($badHostsList, $thisHostArray);
            break;
          } else {
            $thisHostArray = populateVal($line, $goodReturnValCount, $thisHostArray);
          }
        }
        fclose($hostOutFd);
      }

      if ($nodeStatus == "SUCCESS") {
        $successCount += 1;
        updateSuccessStatusForNode($outputDir, $nodeName);
      }
    }

    $thisHostArray["discoveryStatus"] = $nodeStatus;
    $thisHostArray["disksInfo"] = json_encode($thisHostArray["disksInfo"]);
    array_push($allHosts, $thisHostArray);
  }

  closedir($dirHandle);
}

// Perisist the data to the db.
$logger->log_debug("Going to persist discovered node properties");
$returnValue = $dbAccessor->addHostsToCluster($clusterName, $allHosts);
if ($returnValue["result"] != 0 ) {
  $logger->log_error("Got error while adding hosts: ".$returnValue["error"]);
  print json_encode($returnValue);
  return;
}

if ($successCount == 0) {
  $finalOpStatus = "TOTALFAILURE";
}

$nodeFileOut = fopen($readFromFile, "w");
if ($nodeFileOut == FALSE) {
  $subTransactionReturnValue = $dbAccessor->updateSubTransactionOpStatus($clusterName, $parentSubTxnId, $mySubTxnId, "TOTALFAILURE");
  $logger->log_error("Got error while trying to rewrite hosts file");
  return;
}

// foreach successfully discovered host write the host list to the readFromFile
foreach ($allHosts as $hostInfo) {
  if ($hostInfo["discoveryStatus"] == "FAILED") {
    continue;
  }

  // write the nodename to the readFromFile file.
  fwrite($nodeFileOut, $hostInfo["hostName"]."\n");
}

fclose($nodeFileOut);

$subTransactionReturnValue = $dbAccessor->updateSubTransactionOpStatus($clusterName, $parentSubTxnId, $mySubTxnId, $finalOpStatus);
if ($subTransactionReturnValue["result"] != 0 ) {
  $logger->log_error("Got error while updating subTxn: ".$subTransactionReturnValue["error"]);
  print json_encode($subTransactionReturnValue);
  return;
}

?>