<?php
/*
// If you do not have Asternic Call Center Stats PRO installed, then you will
// have to install it (even if you have no license for it). Wallboard will
// function even if Asternic is not licensed. The asterniclog service will
// keep track of statistics in a MySQL database that we will query to get
// statistical data to show on the wallboard. So if you do not have Asternic
// PRO installed, then get it from http://www.asternic.net and install it
*/
if (php_sapi_name() !='cli') exit;
require_once(dirname(__FILE__)."/../../dblib.php");
require_once(dirname(__FILE__)."/../../asmanager.php");

$ini_array       = parse_ini_file('fullwallboard.ini', true);
$asternic_dir    = $ini_array['asternic_install_directory'];
if($asternic_dir == '') {
    $asternic_dir = '/var/www/html/stats';
}

require_once("$asternic_dir/config.php");
$SLA_ANSWERED = Array();

/* ---------------------------------------------------------------------------
// Config Section
----------------------------------------------------------------------------*/

$DEBUG            = 7;
$SLA_ANSWERED[''] = 20;

/* ---------------------------------------------------------------------------
// End Config
----------------------------------------------------------------------------*/

/*
// Script might get called with some agent names, to limit queries to 
// logged/available agents only and not burden MySQL with that we do not 
// need. The argument passed is a base64 encoded string of quoted agent n
// ames separated with comma:
*/

$loggedInAgents = array();
$condagent ='';
if(isset($argv[1])) {
    $agente=base64_decode($argv[1]);
    $condagent = " AND agent IN ($agente) ";
    $partes = preg_split("/,/",$agente);
    foreach($partes as $valor) {
        $loggedInAgents[]=substr($valor,1,-1);
    }
}

if($DEBUG & 1) {
    $fp = fopen("/tmp/wallboard_debug.log","a");
    fputs($fp,"GET WALLBOARD STATS ($condagent)\n\n");
    if(count($loggedInAgents)>0) {
        fputs($fp,"Agents logged:\n");
        fputs($fp,print_r($loggedInAgents,1)."\n");
    }
}

// Define and Asteirsk Manager Interface object
$astman = new AsteriskManager();

if($DEBUG & 2) {
    fputs($fp,"MySQL host: $DBHOST, user: $DBUSER, pass: $DBPASS, database name: $DBNAME\n");
}

// Connect to MySQL
$db = new dbcon($DBHOST, $DBUSER, $DBPASS, $DBNAME, false, true, true);

if (!function_exists('json_encode')) {
    function json_encode($content) {
        require_once dirname(__FILE__).'/../../../JSON.php';
        $json = new Services_JSON;
        return $json->encode($content);
    }
}

$myqevents       = array();
$last_stop       = array();
$last_stop_pause = array();
$agent_stats     = array();
$queue_stats     = array();
$outbound        = array();

$abr['COMPLETECALLER']   = 'CC';
$abr['COMPLETEAGENT']    = 'CA';
$abr['COMPLETEOUTBOUND'] = 'CO';
$abr['RINGNOANSWER']     = 'RA';
$abr['COMPLETED']        = 'COMPLETED';
$abr['COMPLETEDSLA']     = 'COMPLETEDSLA';
$abr['SERVICELEVEL']     = 'SERVICELEVEL';
$abr['ABANDONED']        = 'ABANDONED';
$abr['TALKTIME']         = 'TALKTIME';
$abr['WAITTIME']         = 'WAITTIME';
$abr['TRANSFER'] = 'TR';



// Function to parse a configuration file

function parse_conf($filename) {

    $file = file($filename);

    foreach ($file AS $line) {
        if (preg_match("/^\s*([\w]+)\s*=\s*\"?([\w\/\:\.\*\%!=\+\#@&\\$-]*)\"?\s*([;].*)?/",$line,$matches)) {
            $conf[ $matches[1] ] = $matches[2];
        }
    }

    if(!isset($conf['manager_port'])) { $conf['manager_port']='5038';      }
    if(!isset($conf['manager_host'])) { $conf['manager_host']='127.0.0.1'; }

    return $conf;
}

/*
// If we find the fop2.cfg file in its default locations, read it
// and parse it so we can get manager connection details
*/
if(is_file("/usr/local/fop2/fop2.cfg") || is_file("/etc/asterisk/fop2/fop2.cfg")) {
    if(is_file("/usr/local/fop2/fop2.cfg")) {
        $fop2conf = parse_conf("/usr/local/fop2/fop2.cfg");
    }
}

// Populate events table to get the proper event ids for future queries
$query = "SELECT * FROM qevent ORDER BY null";
$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    $myqevents[$row['event']] = $row['event_id'];
}

// Read outbound queues from Asternic PRO configuration
$query = "SELECT * FROM setup WHERE keyword='call_flow' OR keyword='sla_answered'";
$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    if($row['value']=='outbound') {
        $outbound[]="\"".$row['parameter']."\"";
    }
    if($row['keyword']=='sla_answered') {
        $SLA_ANSWERED[$row['parameter']]=intval($row['value']);
    }
}

// Built sql condition to exclude outbound queues from regular answered and failed queue calls
if(count($outbound)>0) {
    $outboundqueues = "AND queue NOT IN (".implode(",",$outbound).") ";
    $outboundquery  = "AND queue IN (".implode(",",$outbound).") ";
} else {
    $outboundqueues = '';
    $outboundquery  = '';
}

/*
// Query MySQL to get Agent session times for today
//
// First query for 'closed' sessions: session that have a start and end closing event for that day
//
*/

if($DEBUG & 1) {
    fputs($fp,"----- Session Times Start ----- \n\n");
}

$mydate = "CURDATE()";

// Initialize variables
$query = "SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL";
$res = $db->consulta($query);  

// Perform actual query for closed sessions
$query = "SELECT queue_stats_id, agent, UNIX_TIMESTAMP(start) AS start, UNIX_TIMESTAMP(stop) AS stop, ";
$query.= "TIMESTAMPDIFF(SECOND, start, stop) AS duration ";
$query.= "FROM ( SELECT queue_stats_id, datetime, qevent, (@qagent <> qagent) AS new_qagent, ";
$query.= "@start AS start, @start := IF(qevent = '".$myqevents['ADDMEMBER']."', datetime, NULL) AS prev_start, ";
$query.= "@stop  := IF(qevent = '".$myqevents['REMOVEMEMBER']."', datetime, NULL) AS stop, @qagent := qagent AS qagent ";
$query.= "FROM queue_stats WHERE qevent IN (".$myqevents['ADDMEMBER'].",".$myqevents['REMOVEMEMBER'].") ORDER BY qagent, datetime ) AS tmp ";
$query.= "LEFT JOIN qagent ON qagent = qagent.agent_id, (SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL) AS vars ";
$query.= "WHERE new_qagent = 0 AND start IS NOT NULL AND stop IS NOT NULL and start>=$mydate $condagent";

// In this version, initial time is taken from first ADDMEMBER if there are chained ADDMEMBERS with no REMOVE
$query = "SELECT queue_stats_id, agent, start, stop, (stop - start) AS duration ";
$query.= "FROM ( SELECT queue_stats_id,datetime,unix_timestamp(datetime),(@qagent <> qagent) AS new_qagent, ";
$query.= "agent,event, @prev_start := @start as start, ";
$query.= "@start := if(qevent='".$myqevents['ADDMEMBER']."',if(@start=0,unix_timestamp(datetime),if(@qagent<>qagent,unix_timestamp(datetime),@start)),0) as pstart, ";
$query.= "@stop  := IF(qevent = '".$myqevents['REMOVEMEMBER']."', unix_timestamp(datetime), NULL) AS stop, ";
$query.= "@qagent := qagent FROM queue_stats LEFT JOIN qevent ON qevent=event_id ";
$query.= "LEFT JOIN qagent ON qagent=agent_id, ( select @start := 0, @prev_start :=0, @qagent :='' ) sqlvars ";
$query.= "WHERE datetime>=$mydate AND qevent in (".$myqevents['ADDMEMBER'].",".$myqevents['REMOVEMEMBER'].") ORDER BY qagent,datetime) AS tmp ";
$query.= "WHERE start > 0 AND stop > 0 AND stop > start $condagent";

$res = $db->consulta($query);

if($DEBUG & 2) { 
    fputs($fp,"\nClosed sessions MySQL query:\n\n$query\n\n");
}

while($row=$db->fetch_assoc($res)) {
    $last_stop[$row['agent']]=$row['stop']; // Save last stop date to use in open session query below
    if(!isset($agent_stats[$row['agent']]['ST'])) { $agent_stats[$row['agent']]['ST']=0; }
    if(!isset($agent_stats[$row['agent']]['WT'])) { $agent_stats[$row['agent']]['WT']=0; }
    if(!isset($agent_stats[$row['agent']]['HT'])) { $agent_stats[$row['agent']]['HT']=0; }
    if(!isset($agent_stats[$row['agent']]['TT'])) { $agent_stats[$row['agent']]['TT']=0; }
    if(!isset($agent_stats[$row['agent']]['PT'])) { $agent_stats[$row['agent']]['PT']=0; }
    $agent_stats[$row['agent']]['ST']+=$row['duration'];
    if($DEBUG & 4) {
        fputs($fp,"Agent closed session: ".$row['agent'].", sesion += ".$row['duration']." = ".$agent_stats[$row['agent']]['ST']."\n");
    }
}

// Perform actual query for open sessions, (session with no closing event)
$query = "SELECT queue_stats_id,agent,UNIX_TIMESTAMP(datetime) AS start,UNIX_TIMESTAMP(now()) AS stop, ";
$query.= "TIMESTAMPDIFF(SECOND,datetime,now()) AS duration FROM queue_stats ";
$query.= "LEFT JOIN qagent ON qagent = qagent.agent_id WHERE qevent=".$myqevents['ADDMEMBER']." AND datetime>=$mydate $condagent";

$res = $db->consulta($query);

if($DEBUG & 2) {
    fputs($fp,"\nOpen sessions MySQL query:\n\n$query\n\n");
}

while($row=$db->fetch_assoc($res)) {
    if(!isset($last_stop[$row['agent']])) { $last_stop[$row['agent']]=$row['start']; } // We did not have a last stop from closed session so use the first start instead
    if($row['start']>=$last_stop[$row['agent']]) {
        if(!isset($agent_stats[$row['agent']])) { $agent_stats[$row['agent']]=array();}
        if(!isset($agent_stats[$row['agent']]['ST'])) { $agent_stats[$row['agent']]['ST']=0;}
        if(!isset($agent_stats[$row['agent']]['WT'])) { $agent_stats[$row['agent']]['WT']=0; }
        if(!isset($agent_stats[$row['agent']]['HT'])) { $agent_stats[$row['agent']]['HT']=0; }
        if(!isset($agent_stats[$row['agent']]['TT'])) { $agent_stats[$row['agent']]['TT']=0; }
        if(!isset($agent_stats[$row['agent']]['PT'])) { $agent_stats[$row['agent']]['PT']=0; }
        $agent_stats[$row['agent']]['ST']+=$row['duration'];
        $last_stop[$row['agent']]=time();  // If there is a match, last stop is current time
        if($DEBUG & 4) {
            fputs($fp,"Agent open session: ".$row['agent']." sesion += ".$row['duration']." = ".$agent_stats[$row['agent']]['ST']."\n");
        }
    }
}

// Now, for agents with no start or stop sessions, lets just compute a session time from todays 00 hours

//$query = "SELECT agent,UNIX_TIMESTAMP(now())-UNIX_TIMESTAMP($mydate) as ST FROM qagent WHERE 1=1";

// Improved query using agent_activity
$query = "SELECT a.agent,unix_timestamp(now())-unix_timestamp(min(datetime)) as ST FROM qagent a LEFT JOIN agent_activity b ON a.agent=b.agent WHERE date(datetime)=$mydate GROUP BY b.agent";

if($DEBUG & 2) {
    fputs($fp,"\nComputed sessions MySQL query:\n\n$query\n\n");
}
$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    //if(!in_array($row['agent'],$loggedInAgents)) {
    if(1==2) {
        if($DEBUG & 4) { 
            fputs($fp,"Agent ".$row['agent']." is not logged, we can safely skip it.\n");
        }
    } else {
        if(!isset($agent_stats[$row['agent']]['ST'])) {
            if($DEBUG & 1) {
                fputs($fp,"Agent computed session: ".$row['agent']." ST to ".$row['ST']."\n");
            }
            $agent_stats[$row['agent']]['ST']=$row['ST'];
            if(!isset($agent_stats[$row['agent']]['WT'])) { $agent_stats[$row['agent']]['WT']=0; }
            if(!isset($agent_stats[$row['agent']]['HT'])) { $agent_stats[$row['agent']]['HT']=0; }
            if(!isset($agent_stats[$row['agent']]['TT'])) { $agent_stats[$row['agent']]['TT']=0; }
            if(!isset($agent_stats[$row['agent']]['PT'])) { $agent_stats[$row['agent']]['PT']=0; }
        }
    }
}

if($DEBUG & 1) {
    fputs($fp,"----- Session Times End ----- \n\n");
}

// Call and Pause Stats for AGENTS

if($DEBUG & 1) {
    fputs($fp,"----- Agents Call Stats Start ----- \n");
}

// Total Pause duration for today, excluding pauses with reasons Hold, Login and Wrapup. Only counts "closed" pauses

// Initialize variables
$query = "SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL";
$res = $db->consulta($query);  
// Perform actual query
$query = "SELECT queue_stats_id, agent, unix_timestamp(start) AS start, unix_timestamp(stop) AS stop, ";
$query.= "TIMESTAMPDIFF(SECOND, start, stop) AS duration FROM ( SELECT queue_stats_id, datetime, qevent, ";
$query.= "(@qagent <> qagent) AS new_qagent, @start AS start, ";
$query.= "@start := IF(qevent = '".$myqevents['PAUSE']."' or qevent='".$myqevents['PAUSEALL']."', datetime, NULL) AS prev_start, ";
$query.= "@stop := IF(qevent = '".$myqevents['UNPAUSE']."' or qevent='".$myqevents['UNPAUSEALL']."', datetime, NULL) AS stop, ";
$query.= "@qagent := qagent AS qagent FROM queue_stats WHERE qevent IN (".$myqevents['PAUSE'].",".$myqevents['PAUSEALL'].",".$myqevents['UNPAUSE'].",".$myqevents['UNPAUSEALL'].") ";
$query.= "AND (info1<>'Hold' AND info1<>'Login' AND info1<>'Wrapup' OR info1 IS NULL) AND datetime>=$mydate ORDER BY qagent, datetime ) AS tmp ";
$query.= "LEFT JOIN qagent ON qagent = qagent.agent_id, (SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL) AS vars ";
$query.= "WHERE new_qagent = 0 AND start IS NOT NULL AND stop IS NOT NULL AND stop>= $mydate $condagent";

/*
 * subselect freezes on some mysql versions, unfortunately
 *
$query="SELECT qagent.agent AS agent, UNIX_TIMESTAMP(MIN(starttime)) AS start, UNIX_TIMESTAMP(stoptime) AS stop, TIMESTAMPDIFF(SECOND, starttime, stoptime) AS duration FROM ( SELECT qagent, starttime, MIN(stoptime) AS stoptime FROM ( SELECT start.qagent   AS qagent, start.datetime AS starttime, stop.datetime  AS stoptime FROM ( SELECT qagent, queue_stats.datetime FROM queue_stats WHERE queue_stats.datetime >= $mydate AND qevent IN (20, 21) AND info1 NOT IN('Hold', 'Login', 'Wrapup')) AS start INNER JOIN ( SELECT qagent, queue_stats.datetime FROM queue_stats WHERE queue_stats.datetime >= $mydate AND qevent IN (27, 28) AND info1 NOT IN('Hold', 'Login', 'Wrapup')) AS stop ON start.qagent = stop.qagent AND stop.datetime >= start.datetime) AS times GROUP BY qagent, starttime) AS times INNER JOIN qagent ON qagent = qagent.agent_id GROUP BY qagent, stoptime";

 */
if($DEBUG & 2) {
    fputs($fp,"\nPauses (excluding hold and wrapup) MySQL query:\n\n$query\n\n");
}
$res = $db->consulta($query); 

while($row=$db->fetch_assoc($res)) {
    $last_stop_pause[$row['agent']]=$row['stop'];
    if(!isset($agent_stats[$row['agent']]['PT'])) { $agent_stats[$row['agent']]['PT']=0; }
    $agent_stats[$row['agent']]['PT']+=$row['duration'];
    if($DEBUG & 4) {
        fputs($fp,"Regular Pause agent ".$row['agent']." += ".$row['duration'].", sums up to ".$agent_stats[$row['agent']]['PT']."\n");
    }
}

// Total Pause duration with reason Hold, when using the Hold report plugin

// Initialize variables
$query = "SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL, @reason:=NULL";
$res = $db->consulta($query);  

// Perform actual query
$query = "SELECT queue_stats_id, agent, unix_timestamp(start) AS start, unix_timestamp(stop) AS stop, ";
$query.= "TIMESTAMPDIFF(SECOND, start, stop) AS duration, reason from (SELECT queue_stats_id, datetime, qevent, ";
$query.= "(@qagent <> qagent) AS new_qagent, @start AS start, ";
$query.= "@start := IF(qevent = '".$myqevents['PAUSE']."' or qevent='".$myqevents['PAUSEALL']."', datetime, NULL) AS prev_start, ";
$query.= "@stop := IF(qevent = '".$myqevents['UNPAUSE']."' or qevent='".$myqevents['UNPAUSEALL']."', datetime, NULL) AS stop, ";
$query.= "@qagent := qagent AS qagent, @reason AS reason, ";
$query.= "@reason := IF(qevent = '".$myqevents['PAUSE']."' or qevent='".$myqevents['PAUSEALL']."', info1, NULL) as prev_reason  ";
$query.= "FROM queue_stats WHERE qevent IN (".$myqevents['PAUSE'].",".$myqevents['PAUSEALL'].",".$myqevents['UNPAUSE'].",".$myqevents['UNPAUSEALL'].") ";
$query.= "AND datetime>=$mydate ORDER BY qagent, datetime) tmp LEFT JOIN qagent ON qagent = qagent.agent_id, ";
$query.= "(SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL,@reason:=NULL) AS vars ";
$query.= "WHERE start IS NOT NULL AND stop IS NOT NULL AND new_qagent=0 AND start>=$mydate AND reason='Hold' $condagent";

if($DEBUG & 2) {
    fputs($fp,"\nPauses (Hold) MySQL query:\n\n$query\n\n");
}
$res = $db->consulta($query); 

while($row=$db->fetch_assoc($res)) {
    $last_stop_pause[$row['agent']]=$row['stop'];
    if(!isset($agent_stats[$row['agent']]['HT'])) { $agent_stats[$row['agent']]['HT']=0; }
    $agent_stats[$row['agent']]['HT']+=$row['duration'];
    if($DEBUG & 4) {
        fputs($fp,"Hold Pause agent ".$row['agent']." += ".$row['duration'].", sums up to ".$agent_stats[$row['agent']]['HT']."\n");
    }
}

// Total Pause duration with reason Wrapup, when using the auto wrapup plugin with reason Wrapup 

// Initialize variables
$query = "SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL, @reason:=NULL";
$res = $db->consulta($query);  

// Perform actual query
$query = "SELECT queue_stats_id, agent, unix_timestamp(start) AS start, unix_timestamp(stop) AS stop, ";
$query.= "TIMESTAMPDIFF(SECOND, start, stop) AS duration, reason from (SELECT queue_stats_id, datetime, qevent, ";
$query.= "(@qagent <> qagent) AS new_qagent, @start AS start, ";
$query.= "@start := IF(qevent = '".$myqevents['PAUSE']."' or qevent='".$myqevents['PAUSEALL']."', datetime, NULL) AS prev_start, ";
$query.= "@stop := IF(qevent = '".$myqevents['UNPAUSE']."' or qevent='".$myqevents['UNPAUSEALL']."', datetime, NULL) AS stop, ";
$query.= "@qagent := qagent AS qagent, @reason AS reason, ";
$query.= "@reason := IF(qevent = '".$myqevents['PAUSE']."' or qevent='".$myqevents['PAUSEALL']."', info1, NULL) as prev_reason  ";
$query.= "FROM queue_stats WHERE qevent IN (".$myqevents['PAUSE'].",".$myqevents['PAUSEALL'].",".$myqevents['UNPAUSE'].",".$myqevents['UNPAUSEALL'].") ";
$query.= "AND datetime>=$mydate ORDER BY qagent, datetime) tmp LEFT JOIN qagent ON qagent = qagent.agent_id, ";
$query.= "(SELECT @start:=NULL, @stop:=NULL, @qagent:=NULL,@reason:=NULL) AS vars ";
$query.= "WHERE start IS NOT NULL AND stop IS NOT NULL AND new_qagent=0 AND reason='Wrapup' $condagent";

if($DEBUG & 2) {
    fputs($fp,"\nPauses (Wrapup) MySQL query:\n\n$query\n\n");
}
$res = $db->consulta($query); 

while($row=$db->fetch_assoc($res)) {
    $last_stop_pause[$row['agent']]=$row['stop'];
    if(!isset($agent_stats[$row['agent']]['WT'])) { $agent_stats[$row['agent']]['WT']=0; }
    $agent_stats[$row['agent']]['WT']+=$row['duration'];
    if($DEBUG & 4) {
        fputs($fp,"Wrapup Pause agent ".$row['agent']." += ".$row['duration'].", sums up to ".$agent_stats[$row['agent']]['WT']."\n");
    }
}

// check for Open Pauses now, open pauses with no unpause, discarding hold and wrapup
$query = "SELECT queue_stats_id,agent,unix_timestamp(datetime) AS start, ";
$query.= "unix_timestamp(now()) AS stop, TIMESTAMPDIFF(SECOND,datetime,now()) AS duration ";
$query.= "FROM queue_stats LEFT JOIN qagent ON qagent = qagent.agent_id ";
$query.= "WHERE qevent IN (".$myqevents['PAUSE'].",".$myqevents['PAUSEALL'].") AND datetime>=$mydate AND (info1<>'Hold' AND info1<>'Wrapup' OR info1 IS NULL) $condagent";

if($DEBUG & 2) {
    fputs($fp,"\nOpen Pauses MySQL query:\n\n$query\n\n");
}

$res = $db->consulta($query);

while($row=$db->fetch_assoc($res)) {
    if(!isset($last_stop_pause[$row['agent']])) { $last_stop_pause[$row['agent']]=$row['start']; }
    if($row['start']>=$last_stop_pause[$row['agent']]) {
        if(!isset($agent_stats[$row['agent']]['PT'])) { $agent_stats[$row['agent']]['PT']=0; }
        $agent_stats[$row['agent']]['PT']+=$row['duration'];
        if($DEBUG & 4) {
            fputs($fp,"Open pause agent ".$row['agent']." += ".$row['duration'].", sums up to ".$agent_stats[$row['agent']]['PT']."\n");
        }
        $last_stop_pause[$row['agent']]=time();  // Si hay uno, cortamos aca, no queremos otros eventos START consecutivos duplicando tiempos
    }
}

// TALKTIME for today, per agent
$query = "SELECT agent, ";
$query.= "SUM(CASE WHEN qevent=".$myqevents['TRANSFER']." THEN info3 ELSE info2 END) AS talktime ";
$query.= "FROM queue_stats LEFT JOIN qagent ON qagent=qagent.agent_id ";
$query.= "LEFT JOIN qevent ON qevent=qevent.event_id ";
$query.= "LEFT JOIN qname ON qname=qname.queue_id ";
$query.= "WHERE qevent IN(".$myqevents['COMPLETECALLER'].",".$myqevents['COMPLETEAGENT'].",".$myqevents['TRANSFER'].") $outboundqueues ";
$query.= "AND datetime >= $mydate $condagent GROUP BY qagent";

if($DEBUG & 2) {
    fputs($fp,"\nTalk time MySQL query:\n\n$query\n\n");
}

$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    $agent_stats[$row['agent']]['TT']=$row['talktime'];
    if($DEBUG & 4) {
        fputs($fp,"Talk time agent ".$row['agent']." = ".$row['talktime']."\n");
    }
}

// RING NO ANSWER y COMPLETE  for today, per agent
$query = "SELECT agent,event,count(qevent) AS count FROM queue_stats ";
$query.= "LEFT JOIN qagent ON qagent=qagent.agent_id ";
$query.= "LEFT JOIN qevent ON qevent=qevent.event_id ";
$query.= "LEFT JOIN qname ON qname=qname.queue_id ";
$query.=" WHERE qevent IN(".$myqevents['COMPLETECALLER'].",".$myqevents['COMPLETEAGENT'].",".$myqevents['TRANSFER'].",".$myqevents['RINGNOANSWER'].") $outboundqueues ";
$query.= "AND datetime >= $mydate $condagent GROUP BY qagent,qevent";

if($DEBUG & 2) {
    fputs($fp,"\nRingNoAnswer and Complete* MySQL query:\n\n$query\n\n");
}

$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    // Se for TRANSFER, soma no CA ao invés de criar TR separado
    if($row['event'] == 'TRANSFER') {
        if(!isset($agent_stats[$row['agent']]['CA'])) {
            $agent_stats[$row['agent']]['CA'] = 0;
        }
        $agent_stats[$row['agent']]['CA'] += $row['count'];
    } else {
        $agent_stats[$row['agent']][$abr[$row['event']]]=$row['count'];
    }
    
    if($DEBUG & 4) {
        fputs($fp,"Completed agent ".$row['agent'].", event ".$row['event']." = ".$row['count']."\n");
    }
}

// OUTBOUND for today using Asternic call_flow setup. Requires a licensed Asternic
if($outboundquery<>'') {

    $query = "SELECT agent,event,count(qevent) AS count FROM queue_stats ";
    $query.= "LEFT JOIN qagent ON qagent=qagent.agent_id LEFT JOIN qevent ON qevent=qevent.event_id ";
    $query.= "LEFT JOIN qname ON qname=qname.queue_id WHERE qevent ";
   $query.= "IN(".$myqevents['COMPLETECALLER'].",".$myqevents['COMPLETEAGENT'].",".$myqevents['TRANSFER'].") $outboundquery AND datetime >= $mydate $condagent ";
    $query.= "GROUP BY qagent,qevent";

    if($DEBUG & 2) {
        fputs($fp,"\nOutbound query via  Asternic call_flow queues MySQL query:\n\n$query\n\n");
    }

    $res = $db->consulta($query);
    while($row=$db->fetch_assoc($res)) {
        $agent_stats[$row['agent']][$abr['COMPLETEOUTBOUND']]=$row['count'];
        if($DEBUG & 4) {
            fputs($fp,"Outbound asternic agent ".$row['agent']." = ".$row['count']."\n");
        }
    }

}

// Use CDR table for outbound count in an Issabel/FreePBX System. For this to work 
// we must be sure the Asternic user is able to access asteriskcdrdb.cdr 
// table

// query contributed by Tom  Teeuwen
$condagentoutbound = preg_replace("/AND agent IN/","AND asterisk.users.name in",$condagent);
$query = "SELECT `asterisk`.`users`.`name` AS `agent`, `cnum`.`count` AS `count`, `cnum`.`talktime` AS `talktime` FROM ( SELECT `cnum`, COUNT(*) AS `count`, SUM(`sum`) AS `talktime` FROM ( SELECT `cnum`, `uniqueid`, SUM(`billsec`) AS `sum` FROM `asteriskcdrdb`.`cdr` WHERE calldate >= $mydate AND lastapp = 'Dial' GROUP BY `cnum`, `uniqueid`) AS `billsec_sum` GROUP BY `cnum`) AS `cnum` INNER JOIN `asterisk`.`users` ON `cnum`.`cnum` = `asterisk`.`users`.`extension` WHERE 1=1 $condagentoutbound";

/*
$query = "SELECT agent,count,talktime FROM ( ";
$query.= "SELECT a.name AS agent,count(*) AS count, sum(billsec) AS talktime  ";
$query.= "FROM asterisk.users a ";
$query.= "INNER JOIN ( SELECT  * FROM asteriskcdrdb.cdr WHERE calldate >= $mydate) b ";
$query.= "ON extension=src AND lastapp='Dial' GROUP BY a.name ";
$query.=") j WHERE 1=1 $condagent";
*/

if($DEBUG & 2) {
    fputs($fp,"\nOutbound query via FreePBX CDR MySQL query:\n\n$query\n\n");
}

$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    $agent_stats[$row['agent']][$abr['COMPLETEOUTBOUND']]=$row['count'];
    if(!isset($agent_stats[$row['agent']]['TT'])) { $agent_stats[$row['agent']]['TT']=0; }
    $agent_stats[$row['agent']]['TT']+=$row['talktime'];
    if($DEBUG & 4) {
        fputs($fp,"Outbound cdr agent ".$row['agent']." = ".$row['count']."\n");
        fputs($fp,"Outbound cdr agent talktime ".$row['agent']." = ".$agent_stats[$row['agent']]['TT']."\n");
    }
}

foreach($agent_stats AS $agent=>$nada) {
    if(!isset($agent_stats[$agent]['PT'])) { $agent_stats[$agent]['PT']=0; }
    if(!isset($agent_stats[$agent]['ST'])) { $agent_stats[$agent]['ST']=0; }
    if(!isset($agent_stats[$agent]['CC'])) { $agent_stats[$agent]['CC']=0; }
    if(!isset($agent_stats[$agent]['CA'])) { $agent_stats[$agent]['CA']=0; }
    if(!isset($agent_stats[$agent]['CO'])) { $agent_stats[$agent]['CO']=0; }
    if(!isset($agent_stats[$agent]['RA'])) { $agent_stats[$agent]['RA']=0; }
    if(!isset($agent_stats[$agent]['TT'])) { $agent_stats[$agent]['TT']=0; }

    // Pasada para duplicar agentes Agent/xxxx y dejarlo solo en xxxxxx porque asternic convierte
    // agentes con nombre solo numerico a cadena Agent/xxxxx pero asterisk en AMI y realtime mostrara
    // solo xxxxxx
    if(preg_match("/Agent\//",$agent)) {
        $numeric_agent = preg_replace("/Agent\//","",$agent);
        //$agent_stats[$numeric_agent]=$agent_stats[$agent];
        foreach($agent_stats[$agent] as $key=>$val) {
            if(!isset($agent_stats[$numeric_agent][$key])) {
              $agent_stats[$numeric_agent][$key]=$val;
            }
        }
    }

}

// Finalmente tenemos en session_time los agentes => duracion de sesion

if($DEBUG & 1) {
    fputs($fp,print_r($agent_stats,1));
    fputs($fp,"----- Agents Call Stats End ----- \n\n");
}

if($DEBUG & 1) {
    fputs($fp,"----- Queue Stats Start ----- \n");
}

// Queue Stats Queries

// populate empty data from all stored queues
$query = "SELECT queue FROM qname WHERE queue NOT IN ('All','None')";
if($DEBUG & 2) {
    fputs($fp,"\nQueues filler MySQL query:\n\n$query\n\n");
}

$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    if(!isset($queue_stats[$row['queue']][$abr['SERVICELEVEL']])) { 
        $queue_stats[$row['queue']][$abr['SERVICELEVEL']]=isset($SLA_ANSWERED[$row['queue']])?$SLA_ANSWERED[$row['queue']]:$SLA_ANSWERED[''];
    }
    if(!isset($queue_stats[$row['queue']][$abr['COMPLETEDSLA']])) { $queue_stats[$row['queue']][$abr['COMPLETEDSLA']]=0; }
    if(!isset($queue_stats[$row['queue']][$abr['COMPLETED']])) { $queue_stats[$row['queue']][$abr['COMPLETED']]=0; }
    if(!isset($queue_stats[$row['queue']][$abr['TALKTIME']])) { $queue_stats[$row['queue']][$abr['TALKTIME']]=0; }
    if(!isset($queue_stats[$row['queue']][$abr['WAITTIME']])) { $queue_stats[$row['queue']][$abr['WAITTIME']]=0; }
    if(!isset($queue_stats[$row['queue']][$abr['ABANDONED']])) { $queue_stats[$row['queue']][$abr['ABANDONED']]=0; }
}

// total completed caller and agent
$query = "SELECT count(*) AS count,queue, ";
$query.= "SUM(CASE WHEN event='TRANSFER' THEN 0 ELSE info1 END) as waittime, ";
$query.= "SUM(CASE WHEN event='TRANSFER' THEN info3 ELSE info2 END) as talktime ";
$query.= "FROM queue_stats LEFT JOIN qevent ON qevent=qevent.event_id ";
$query.= "LEFT JOIN qname ON qname=qname.queue_id ";
$query.= "WHERE datetime>=$mydate AND event IN ('COMPLETECALLER','COMPLETEAGENT','TRANSFER') GROUP BY qname ORDER BY null";

if($DEBUG & 2) {
    fputs($fp,"\nQueues Completed MySQL query:\n\n$query\n\n");
}

$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    $queue_stats[$row['queue']][$abr['COMPLETED']]=intval($row['count']);
    $queue_stats[$row['queue']][$abr['COMPLETEDSLA']]=0;
    $queue_stats[$row['queue']][$abr['TALKTIME']]=intval($row['talktime']);
    $queue_stats[$row['queue']][$abr['WAITTIME']]=intval($row['waittime']);

    if($DEBUG & 4) {
        fputs($fp,"Queue ".$row['queue'].", completed: ".$row['count'].", TalkTime: ".$row['talktime'].", Wait Time: ".$row['waittime']."\n");
    }
}

// completed under sla 
$query = "SELECT queue,info1  ";
$query.= "FROM queue_stats LEFT JOIN qevent ON qevent=qevent.event_id ";
$query.= "LEFT JOIN qname ON qname=qname.queue_id ";
$query.= "WHERE datetime>=$mydate AND event IN ('COMPLETECALLER','COMPLETEAGENT','TRANSFER') $outboundqueues ORDER BY null";

if($DEBUG & 2) {
    fputs($fp,"\nQueues Completed SLA MySQL query:\n\n$query\n\n");
}

$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    $sla = (isset($SLA_ANSWERED[$row['queue']]))?$SLA_ANSWERED[$row['queue']]:$SLA_ANSWERED[''];
    $waittime = $row['info1'];
    if($waittime<=$sla) {
        $queue_stats[$row['queue']][$abr['COMPLETEDSLA']]++;
    }
}

// exit and abandon
/*
$query = "SELECT count(*) AS count,queue,sum(info3) as waittime FROM queue_stats ";
$query.= "LEFT JOIN qevent ON qevent=qevent.event_id ";
$query.= "LEFT JOIN qname ON qname=qname.queue_id ";
$query.= "WHERE datetime>=$mydate AND event IN ('ABANDON','EXITWITHKEY','EXITWITHTIMEOUT','EXITEMPTY') GROUP BY qname ORDER BY null";
*/
// using materialized view is faster
$query = "SELECT count(*) AS count,queue,unix_timestamp(datetimeend)-unix_timestamp(datetimeconnect) AS waittime ";
$query.= "FROM queue_stats_mv WHERE event IN ('ABANDON','EXITWITHKEY','EXITWITHTIMEOUT','EXITEMPTY') AND datetime>=$mydate GROUP BY queue";

if($DEBUG & 2) {
    fputs($fp,"\nQueues no answer/abandon MySQL query:\n\n$query\n\n");
}
$res = $db->consulta($query);
while($row=$db->fetch_assoc($res)) {
    if(!isset($queue_stats[$row['queue']][$abr['COMPLETED']])) { $queue_stats[$row['queue']][$abr['COMPLETED']]=0; }
    if(!isset($queue_stats[$row['queue']][$abr['TALKTIME']])) { $queue_stats[$row['queue']][$abr['TALKTIME']]=0; }
    if(!isset($queue_stats[$row['queue']][$abr['WAITTIME']])) { $queue_stats[$row['queue']][$abr['WAITTIME']]=0; }
    if(!isset($queue_stats[$row['queue']][$abr['ABANDONED']])) { $queue_stats[$row['queue']][$abr['ABANDONED']]=0; }
    $queue_stats[$row['queue']][$abr['ABANDONED']]=intval($row['count']);
    $queue_stats[$row['queue']][$abr['WAITTIME']]+=intval($row['waittime']);
    if($DEBUG & 4) {
        fputs($fp,"Queue ".$row['queue'].", abandoned: ".$row['count'].", Wait Time: ".$row['waittime']."\n");
    }
}

if($DEBUG & 1) {
    fputs($fp,print_r($queue_stats,1));
    fputs($fp,"----- Queue Stats End ----- \n\n");
}

// Connect to AMI and fire events for every piece of stats we have

if(!$res = $astman->connect($fop2conf['manager_host'].':'.$fop2conf['manager_port'], $fop2conf['manager_user'] , $fop2conf['manager_secret'], 'off')) {
    unset($astman);
}

if ($astman) {

    foreach ($agent_stats as $key => $nada) {
        // we have to send individual events as there is a limit in AMI events header size
        $pepe_stats = Array();
        $pepe_stats[$key] = $agent_stats[$key];

        if(!isset($pepe_stats[$key]['TR'])) {
            $pepe_stats[$key]['TR'] = 0;
        }
        $json_data = json_encode($pepe_stats);
        $res = $astman->UserEvent('ASTERNICAGENTSTATS',array('Channel'=>'GLOBAL_FULLWALLBOARD','Family'=>'ASTERNICAGENTSTATS','Value'=>base64_encode($json_data)));
    }

    $res = $astman->UserEvent('ASTERNICQUEUESTATSRESET',array('Channel'=>'GLOBAL_FULLWALLBOARD','Family'=>'ASTERNICQUEUESTATSRESET','Value'=>''));
    foreach ($queue_stats as $key => $nada) {
        $pepe_stats = Array();
        $pepe_stats[$key] = $queue_stats[$key];
        $json_data = json_encode($pepe_stats);
        $res = $astman->UserEvent('ASTERNICQUEUESTATS',array('Channel'=>'GLOBAL_FULLWALLBOARD','Family'=>'ASTERNICQUEUESTATS','Value'=>base64_encode($json_data)));
    }
    $res = $astman->UserEvent('ASTERNICQUEUESTATSEND',array('Channel'=>'GLOBAL_FULLWALLBOARD','Family'=>'ASTERNICQUEUESTATSEND','Value'=>''));
}

if($DEBUG & 1) {
    fclose($fp);
}
