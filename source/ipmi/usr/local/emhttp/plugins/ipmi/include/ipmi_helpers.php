<?
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_drives.php';
require_once '/usr/local/emhttp/plugins/dynamix/include/Helpers.php';

$action = array_key_exists('action', $_GET) ? htmlspecialchars($_GET['action']) : '';
$hdd_temp = get_highest_temp();
extract(parse_plugin_cfg('dynamix',true));
if (isset($display['unit'])) $display_unit = $display['unit']; else $display_unit = "C";

if (!empty($action)) {
    $state = ['Critical' => 'red', 'Warning' => 'yellow', 'Nominal' => 'green', 'N/A' => 'blue'];
    if ($action === 'ipmisensors'){
        $return  = ['Sensors' => ipmi_sensors($ignore),'Network' => ($netsvc === 'enable'),'State' => $state];
        echo json_encode($return);
    }
    elseif($action === 'ipmievents'){
        $return  = ['Events' => ipmi_events(),'Network' => ($netsvc === 'enable'),'State' => $state];
        echo json_encode($return);
    }
    elseif($action === 'ipmiarch'){
        $return  = ['Archives' => ipmi_events(true), 'Network' => ($netsvc === 'enable'), 'State' => $state];
        echo json_encode($return);
    }
    elseif($action === 'ipmidash') {
        $return  = ['Sensors' => ipmi_sensors($dignore), 'Network' => ($netsvc === 'enable'),'State' => $state];
        echo json_encode($return);
    }
}

/* get highest temp of hard drives */
function get_highest_temp(){
    global $devignore;
    $ignore = array_flip(explode(',', $devignore));

    //get UA devices
    $ua_json = '/var/state/unassigned.devices/hdd_temp.json';
    $ua_devs = file_exists($ua_json) ? json_decode(file_get_contents($ua_json), true) : [];

    //get all hard drives
    $hdds = array_merge(parse_ini_file('/var/local/emhttp/disks.ini',true), parse_ini_file('/var/local/emhttp/devs.ini',true));

    $highest_temp = 0;
    foreach ($hdds as $hdd) {
        if (!array_key_exists($hdd['id'], $ignore)) {

            if(array_key_exists('temp', $hdd))
                $temp = $hdd['temp'];
            else{
                $ua_key = "/dev/".$hdd['device'];
                $temp = (array_key_exists($ua_key, $ua_devs)) ? $ua_devs[$ua_key]['temp'] : 'N/A';
            }

            if(is_numeric($temp))
                $highest_temp = ($temp > $highest_temp) ? $temp : $highest_temp;
        }
    }
    $return = ($highest_temp === 0) ? 'N/A': $highest_temp;
    return $return;
}

/* get an array of all sensors and their values */
function ipmi_sensors($ignore='') {
    global $ipmi, $netopts, $hdd_temp;

    // return empty array if no ipmi detected and no network options
    if(!($ipmi || !empty($netopts)))
        return [];

    $ignored = (empty($ignore)) ? '' : '-R '.escapeshellarg($ignore);
    $cmd = '/usr/sbin/ipmi-sensors --output-sensor-thresholds --comma-separated-output '.
        "--output-sensor-state --no-header-output --interpret-oem-data $netopts $ignored 2>/dev/null";
    $return_var=null ;    
    exec($cmd, $output, $return_var);

    // return empty array if error
    if ($return_var)
        return [];

    // add highest hard drive temp sensor and check if hdd is ignored
    $hdd = (preg_match('/99/', $ignore)) ? '' :
        "99,HDD Temperature,Temperature,Nominal,$hdd_temp,C,N/A,N/A,N/A,45.00,50.00,N/A,Ok";
    if(!empty($hdd)){
        if(!empty($netopts))
            $hdd = '127.0.0.1:'.$hdd;
        $output[] = $hdd;
    }
    // test sensor
    // $output[] = "98,CPU Temp,OEM Reserved,Nominal,N/A,N/A,N/A,N/A,N/A,45.00,50.00,N/A,'Medium'";

    // key names for ipmi sensors output
    $keys = ['ID','Name','Type','State','Reading','Units','LowerNR','LowerC','LowerNC','UpperNC','UpperC','UpperNR','Event'];
    $sensors = [];

    foreach($output as $line){

        $sensor_raw = explode(",", str_replace("'",'',$line));
        $size_raw = sizeof($sensor_raw);

        // add sensor keys as keys to ipmi sensor output
        $sensor = ($size_raw < 13) ? []: array_combine($keys, array_slice($sensor_raw,0,13,true));

        if(empty($netopts))
            $sensors[$sensor['ID']] = $sensor;
        else{

            //split id into host and id
            $id = explode(':',$sensor['ID']);
            $sensor['IP'] = trim($id[0]);
            $sensor['ID'] = trim($id[1]);
            if ($sensor['IP'] === 'localhost')
                $sensor['IP'] = '127.0.0.1';

            // add sensor to array of sensors
            $sensors[ip2long($sensor['IP']).'_'.$sensor['ID']] = $sensor;
        }
    }
    return $sensors;
}

/* get array of events and their values */
function ipmi_events($archive=null){
    global $ipmi, $netopts;
    $return_var = null;
    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    if($archive) {
        $filename = "/boot/config/plugins/ipmi/archived_events.log";
        $output = is_file($filename) ? file($filename, FILE_IGNORE_NEW_LINES) : [] ;
    } else {
        $cmd = '/usr/sbin/ipmi-sel --comma-separated-output --output-event-state --no-header-output '.
            "--interpret-oem-data --output-oem-event-strings $netopts 2>/dev/null";
        $return_var=null ;
        exec($cmd, $output, $return_var);
    }

    // return empty array if error
    if ($return_var)
        return [];

    // key names for ipmi event output
    $keys = ['ID','Date','Time','Name','Type','State','Event'];
    $events = [];

    foreach($output as $line){

        $event_raw = explode(",", $line);
        $size_raw = sizeof($event_raw);

        // add event keys as keys to ipmi event output
        $event = ($size_raw < 7) ? []: array_combine($keys, array_slice($event_raw,0,7,true));

        // put time in sortable format and add unix timestamp
        $timestamp = $event['Date']." ".$event['Time'];
        if(strtotime($timestamp)) {
            if($date = Datetime::createFromFormat('M-d-Y H:i:s', $timestamp)) {
                $event['Date'] = $date->format('Y-m-d H:i:s');
                $event['Time'] = $date->format('U');
            }
        }

        if (empty($netopts)){

            if($archive)
                $events[$event['Time']."-".$event['ID']] = $event;
            else
                $events[$event['ID']] = $event;

        }else{

            //split id into host and id
            $id = explode(':',$event['ID']);
            $event['IP'] = trim($id[0]);
            if($archive)
                $event['ID'] = $event['Time'];
            else
                $event['ID'] = trim($id[1]);
            if ($event['IP'] === 'localhost')
                $event['IP'] = '127.0.0.1';

            // add event to array of events
            $events[ip2long($event['IP']).'_'.$event['ID']] = $event;
        }
    }
    return $events;
}

/* get select options for a fan and temp sensors */
function ipmi_get_options($selected=null){
    global $sensors;
    $options = "";
    foreach($sensors as $id => $sensor){
        $name = $sensor['Name'];
        $reading  = ($sensor['Type'] === 'OEM Reserved') ? $sensor['Event'] : $sensor['Reading'];
        $ip       = (empty($sensor['IP'])) ? '' : " ({$sensor['IP']})";
        $units    = is_numeric($reading) ? $sensor['Units'] : '';
        $options .= "<option value='$id'";

        // set saved option as selected
        if ($selected == $id)
            $options .= " selected";
        if ($sensor['Type'] == "Temperature")  $options .= ">$name$ip - ".my_temp($reading)."</option>"; else $options .= ">$name$ip - $reading $units</option>" ;
    }
    return $options;
}

/* get select options for enabled sensors */
function ipmi_get_enabled($ignore){
    global $ipmi, $netopts, $allsensors;
    $options = "";
    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    // create array of keyed ignored sensors
    $ignored = array_flip(explode(',', $ignore));
    foreach($allsensors as $sensor){
        $id       = $sensor['ID'];
        $reading  = $sensor['Reading'];
        $units    = ($reading === 'N/A') ? '' : " {$sensor['Units']}";
        $ip       = (empty($netopts))    ? '' : " {$sensor['IP']}";
        $options .= "<option value='$id'";

        // search for id in array to not select ignored sensors
        $options .= array_key_exists($id, $ignored) ?  '' : " selected";

        $options .= ">{$sensor['Name']}$ip - $reading$units</option>";

    }
    return $options;
}

// get a json array of the contents of gihub repo
function get_content_from_github($repo, $file) {
    $ch = curl_init();
    $ch_vers = curl_version();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/'.$ch_vers['version']);
    curl_setopt($ch, CURLOPT_URL, $repo);
    $content = curl_exec($ch);
    curl_close($ch);
    if (!empty($content) && (!is_file($file) || $content != file_get_contents($file)))
        file_put_contents($file, $content);
}


/* FAN HELPERS */


/* get fan and temp sensors array */
function ipmi_fan_sensors($ignore=null) {
    global $ipmi, $fanopts, $hdd_temp;

    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($fanopts)))
        return [];

    $ignored = (empty($ignore)) ? '' : "-R $ignore";
    $cmd = "/usr/sbin/ipmi-sensors --comma-separated-output --no-header-output --interpret-oem-data $fanopts $ignored 2>/dev/null";
    $return_var=null ;
    exec($cmd, $output, $return_var);

    if ($return_var)
        return []; // return empty array if error

    // add highest hard drive temp sensor
    $output[] = "99,HDD Temperature,Temperature, $hdd_temp,C,Ok";
    // test sensors
    //$output[] = "700,CPU_FAN1,Fan,1200,RPM,Ok";
    //$output[] = "701,CPU_FAN2,Fan,1200,RPM,Ok";
    //$output[] = "702,SYS_FAN1,Fan,1200,RPM,Ok";
    //$output[] = "703,SYS_FAN2,Fan,1200,RPM,Ok";
    //$output[] = "704,SYS_FAN3,Fan,1200,RPM,Ok";

    // key names for ipmi sensors output
    $keys = ['ID', 'Name', 'Type', 'Reading', 'Units', 'Event'];
    $sensors = [];

    foreach($output as $line){

        // add sensor keys as keys to ipmi sensor output
        $sensor_raw = explode(",", $line);
        $size_raw = sizeof($sensor_raw);
        $sensor = ($size_raw < 6) ? []: array_combine($keys, array_slice($sensor_raw,0,6,true));

        if ($sensor['Type'] === 'Temperature' || $sensor['Type'] === 'Fan')
            $sensors[$sensor['ID']] = $sensor;
    }
    return $sensors; // sensor readings
    unset($sensors);
}

/* Normalize a BMC fan sensor name into a stable fan-control config key. */
function normalize_fan_control_name($raw_name, $board, $index=1){
    $name = strtoupper(trim(str_replace(' ', '_', $raw_name)));

    if($board === 'Supermicro'){
        if(preg_match('/CPU_FAN([1-4])$/', $name, $m))
            return 'FAN'.$m[1];
        if(preg_match('/^FAN([1-4]|A|B)$/', $name, $m))
            return 'FAN'.$m[1];
        if(preg_match('/SYS_FAN([1-9])$/', $name, $m))
            return intval($m[1]) === 1 ? 'FANA' : 'FANB';
    }

    return preg_replace('/[^A-Z0-9_]/', '_', $name ?: ('FAN'.$index));
}

function get_shared_fan_channel_peers($fan_name, $board, $board_json, $cmd_count=0){
    $board_keys = [$board];
    if($cmd_count !== 0)
        $board_keys[] = $board.'1';

    foreach($board_keys as $board_key){
        if(!isset($board_json[$board_key]['fans'][$fan_name]))
            continue;
        $target = $board_json[$board_key]['fans'][$fan_name];
        $peers = [];
        foreach($board_json[$board_key]['fans'] as $candidate => $candidate_target){
            if($candidate_target === $target)
                $peers[] = $candidate;
        }
        if(count($peers) > 1)
            return $peers;
    }

    return [];
}

/* get all fan options for fan control */
function get_fanctrl_options(){
    global $fansensors, $fancfg, $board, $board_json, $board_file_status, $board_status, $cmd_count, $range, $display_unit;
    if($board_status) {
        $i = 0;
        $seen_fans = [];
        foreach($fansensors as $id => $fan){
            if($i > 23) break;
            if ($fan['Type'] === 'Fan'){
                $name = normalize_fan_control_name($fan['Name'], $board, $i + 1);
                $display = htmlspecialchars($fan['Name']);
                $shared_peers = get_shared_fan_channel_peers($name, $board, $board_json, $cmd_count);
                if(count($shared_peers) > 1){
                    $shared_label = htmlspecialchars(implode(', ', $shared_peers));
                    $display .= ' <span class="orange-text fan-shared-channel" title="These fan sensors share one BMC PWM control channel">Shared channel: '.$shared_label.'</span>';
                }
                if(isset($seen_fans[$name]))
                    $name .= '_'.$id;
                $seen_fans[$name] = true;
                if($board ==='Dell'){
                    $name = 'FAN123456';
                    $display = 'FAN123456';
                    if(isset($seen_fans['DELL_GROUP_DONE']))
                        continue;
                    $seen_fans['DELL_GROUP_DONE'] = true;
                }
                $tempid  = 'TEMP_'.$name;
                $temphdd  = 'TEMPHDD_'.$name;
                $temp    = $fansensors[$fancfg[$tempid]];
                $temphddd    = $fansensors[$fancfg[$temphdd]];
                $templo  = 'TEMPLO_'.$name;
                $temphi  = 'TEMPHI_'.$name;
                $fanmax  = 'FANMAX_'.$name;
                $fanmin  = 'FANMIN_'.$name;
                $temploo  = 'TEMPLOO_'.$name;
                $temphio  = 'TEMPHIO_'.$name;
                $fanmaxo  = 'FANMAXO_'.$name;
                $fanmino  = 'FANMINO_'.$name;
                $fanenable = 'FANENABLE_'.$name;
                $fanenabled = !isset($fancfg[$fanenable]) || strval($fancfg[$fanenable]) !== '0';
                $fanconfigclass = 'fan-config-'.$name;
                $fanhide = $fanenabled ? '' : ' style="display:none;"';
                $spindownconfigclass = 'fan-spindown-'.$name;
                $showspindown = ($fanenabled && isset($fancfg[$tempid]) && strval($fancfg[$tempid]) === '99' && intval(isset($fancfg[$temphdd]) ? $fancfg[$temphdd] : 0) > 0);
                $spindownhide = $showspindown ? '' : ' style="display:none;"';

                echo '<div class="fan-block" data-fan="',$name,'" data-enabled="', ($fanenabled ? '1' : '0'), '">';

                // hidden fan id
                echo '<input type="hidden" name="FAN_',$name,'" value="',$id,'"/>';

                // fan name: reading => temp name: reading
                echo '<dl><dt>',$display,' (',floatval($fan['Reading']),' ',$fan['Units'],'):</dt><dd><span class="fanctrl-basic">';
                if (!$fanenabled) {
                    echo 'Disabled';
                } else {
                    if ($temp['Name']){
                        echo $temp['Name'],' ('.my_temp(floatval($temp['Reading'])),' ','), ',
                        $fancfg[$templo],'-',$fancfg[$temphi],'&deg;, ',number_format((intval(intval($fancfg[$fanmin])/$range*1000)/10),1),'-',number_format((intval(intval($fancfg[$fanmax])/$range*1000)/10),1),'%';
                    }else{
                        echo 'Auto';
                    }
                }

                echo '</span><span class="fanctrl-settings" style="display:none;">';
                if ($fanenabled) {
                    if ($temp['Name']){
                        echo $temp['Name'],' ('.my_temp(floatval($temp['Reading'])),' ','), ',
                        $fancfg[$templo],', ',$fancfg[$temphi],', ',number_format((intval(intval($fancfg[$fanmin])/$range*1000)/10),1),'-',number_format((intval(intval($fancfg[$fanmax])/$range*1000)/10),1),'%';
                    }else{
                        echo 'Auto';
                    }

                    echo '&nbsp;&nbsp;&nbsp;&nbsp;Override:';
                    if (isset($temphddd['Name'])){
                        echo $temphddd['Name'].' ('.my_temp(floatval($temp['Reading'])),' ','), ',
                        $fancfg[$temploo],', ',$fancfg[$temphio],', ',number_format((intval(intval($fancfg[$fanmino])/$range*1000)/10),1),'-',number_format((intval(intval($fancfg[$fanmaxo])/$range*1000)/10),1),'%';
                    }else{
                        echo 'Not Defined';
                    }
                } else {
                    echo '&nbsp;';
                }
                echo '</span></dd>';

                // check if board.json exists then if fan name is in board.json
                $noconfig = '<font class="red"><b><i> (fan is not configured!)</i></b></font>';
                if($board_file_status){
                    if(!array_key_exists($name, $board_json[$board]['fans']))
                        if ($cmd_count !== 0){
                            if(!array_key_exists($name, $board_json["{$board}1"]['fans']))
                                echo $noconfig;
                        }else{
                            echo $noconfig;
                        }
                } else {
                    echo $noconfig;
                }

                echo '</dl>';

                // enable or disable this fan in fan control
                echo '<dl class="fanctrl-settings">',
                '<dt>Use in fan control:</dt><dd>',
                '<select name="',$fanenable,'" class="fanctrl-enable" data-fan="',$name,'">',
                '<option value="1"', ($fanenabled ? ' selected' : ''), '>Enabled</option>',
                '<option value="0"', (!$fanenabled ? ' selected' : ''), '>Disabled</option>',
                '</select></dd></dl>';

                // temperature sensor
                echo '<dl class="fanctrl-settings ',$fanconfigclass,'"',$fanhide,'>',
                '<dt>Temperature sensor:</dt><dd>',
                '<select name="',$tempid,'" class="fanctrl-temp fanctrl-settings">',
                '<option value="0">Auto</option>',
                get_temp_options($fancfg[$tempid]),
                '</select></dd></dl>';

                // per-fan hard drive sensor pool used when the primary sensor is HDD Temperature
                $hddinclude = 'HDDINCLUDE_'.$name;
                echo '<dl class="fanctrl-settings ',$fanconfigclass,'"',$fanhide,'>',
                '<dt>Hard drives for this fan:</dt><dd>',
                '<select multiple class="fanctrl-drive-select" data-hidden="#',$hddinclude,'" title="Select drives whose temperatures should control this fan">',
                '<option value="">Select All</option>',
                get_hdd_options_for_fan(isset($fancfg[$hddinclude]) ? $fancfg[$hddinclude] : ''),
                '</select>',
                '<input type="hidden" id="',$hddinclude,'" class="fanctrl-drive-hidden" name="',$hddinclude,'" value="',htmlspecialchars(isset($fancfg[$hddinclude]) ? $fancfg[$hddinclude] : ''),'" />',
                '</dd></dl>';
                
                if ($fancfg[$tempid] == "99") $disabled = "" ; else $disabled = " disabled ";

                // high temperature threshold
                                echo '<dl class="fanctrl-settings ',$fanconfigclass,'"',$fanhide,'>',
                '<dt>High temperature threshold (&deg;'.$display_unit.'):</dt>',
                '<dd><select name="',$temphi,'" class="',$tempid,' fanctrl-settings">',
                get_temp_range('HI', $fancfg[$temphi],$display_unit),
                '</select></dd></dl>';

                // low temperature threshold
                                echo '<dl class="fanctrl-settings ',$fanconfigclass,'"',$fanhide,'>',
                '<dt>Low temperature threshold (&deg;'.$display_unit.'):</dt>',
                '<dd><select name="',$templo,'" class="',$tempid,' fanctrl-settings">',
                get_temp_range('LO', $fancfg[$templo],$display_unit),
                '</select></dd></dl>';

                // fan control maximum speed
                                echo '<dl class="fanctrl-settings ',$fanconfigclass,'"',$fanhide,'>',
                '<dt>Fan speed maximum (%):</dt><dd>',
                '<select name="',$fanmax,'" class="',$tempid,' fanctrl-settings">',
                get_minmax_options('HI', $fancfg[$fanmax]),
                '</select></dd></dl>';

                // fan control minimum speed
                                echo '<dl class="fanctrl-settings ',$fanconfigclass,'"',$fanhide,'>',
                '<dt>Fan speed minimum (%):</dt><dd>',
                '<select name="',$fanmin,'" class="',$tempid,' fanctrl-settings">',
                get_minmax_options('LO', $fancfg[$fanmin]),
                '</select></dd></dl>&nbsp;';
       
                // temperature sensor Spundown
                                                                echo '<dl class="fanctrl-settings ',$fanconfigclass,'"',$fanhide,'>',
                                '<dt>HDD Spundown Temperature sensor:</dt><dd>',
                '<select', $disabled, ' name="', $temphdd, '" class="fanctrl-temp fanctrl-settings">',
                '<option value="0">None</option>',
                get_temp_options($fancfg[$temphdd]),
                '</select></dd></dl>';

                // high temperature threshold Spundown
                                                                                                                                echo '<dl class="fanctrl-settings ',$fanconfigclass,' ',$spindownconfigclass,'"',$spindownhide,'>',
                                '<dt>High temperature threshold Spundown (&deg;', $display_unit, '):</dt>',
                '<dd><select name="', $temphio, '" class="', $tempid, ' fanctrl-settings">',
                get_temp_range('HI', $fancfg[$temphio], $display_unit),
                '</select></dd></dl>';

                // low temperature threshold Spundown
                                                                                                                 echo '<dl class="fanctrl-settings ',$fanconfigclass,' ',$spindownconfigclass,'"',$spindownhide,'>',
                             '<dt>Low temperature threshold Spundown (&deg;', $display_unit, '):</dt>',
               '<dd><select name="', $temploo, '" class="', $tempid, ' fanctrl-settings">',
               get_temp_range('LO', $fancfg[$temploo], $display_unit),
               '</select></dd></dl>';

               // fan control maximum speed Spundown
                                                                                                                 echo '<dl class="fanctrl-settings ',$fanconfigclass,' ',$spindownconfigclass,'"',$spindownhide,'>',
                             '<dt>Fan speed maximum Spundown (%):</dt><dd>',
               '<select name="', $fanmaxo, '" class="', $tempid, ' fanctrl-settings">',
               get_minmax_options('HI', $fancfg[$fanmaxo]),
               '</select></dd></dl>';

              // fan control minimum speed Spundown
                                                                                                                echo '<dl class="fanctrl-settings ',$fanconfigclass,' ',$spindownconfigclass,'"',$spindownhide,'>',
                            '<dt>Fan speed minimum Spundown (%):</dt><dd>',
              '<select name="', $fanmino, '" class="', $tempid, ' fanctrl-settings">',
              get_minmax_options('LO', $fancfg[$fanmino]),
            '</select></dd></dl>&nbsp;';

                                echo '</div>';

                $i++;
            }
        }
    } else {
        echo '<dl><dt>&nbsp;</dt><dd><p><b><font class="red">Your board is not currently supported</font></b></p></dd></dl>';
    }
}

/* get select options for temp & fan sensor types from fan ip*/
function get_temp_options($selected=0){
    global $fansensors, $fanip;
    $options = '';
    foreach($fansensors as $id => $sensor){
        if (($sensor['Type'] === 'Temperature') || ($sensor['Name'] === 'HDD Temperature')){
            $name = $sensor['Name'];
            $options .= "<option value='$id'";

            // set saved option as selected
            if (intval($selected) === $id)
                $options .= ' selected';

        $options .= ">$name</option>";
        }
    }
    return $options;
}

/* get options for high or low temp thresholds */
function get_temp_range($order, $selected=0,$unit = "C"){
    $temps = [20,80];
    if ($order === 'HI')
      rsort($temps);
    $options = "";
    foreach(range($temps[0], $temps[1], 5) as $temp){
        $options .= "<option value='$temp'";

        // set saved option as selected
        if (intval($selected) === $temp)
            $options .= " selected";
        if ($unit == "F") $temp=round(9/5*$temp)+32; ;
        $options .= ">$temp</option>";
    }
    return $options;
}

/* get options for fan speed min and max */
function get_minmax_options($order, $selected=0){
    global $range;
    $incr = [1,$range];
    if ($order === 'HI')
      rsort($incr);
    $options = "";
    foreach(range($incr[0], $incr[1], 1) as $value){
        $options .= "<option value='$value'";

        // set saved option as selected
        if (intval($selected) === $value)
            $options .= ' selected';

        $options .= '>'.number_format((intval(($value/$range)*1000)/10),1).'</option>';
    }
    return $options;
}

/* get network ip options for fan control */
function get_fanip_options(){
    global $ipaddr, $fanip;
    $options = "";
    $ips = 'None,'.$ipaddr;
    $ips = explode(',',$ips);
        foreach($ips as $ip){
            $options .= '<option value="'.$ip.'"';
            if($fanip === $ip)
                $options .= ' selected';

            $options .= '>'.$ip.'</option>';
        }
    echo $options;
}

function get_hdd_options($ignore=null) {
    $hdds = get_all_hdds();
    $ignored = array_flip(explode(',', $ignore));
    $options = "";
    foreach ($hdds as $serial => $hdd) {
        $options .= "<option value='$serial'";

        // search for id in array to not select ignored sensors
        $options .= array_key_exists($serial, $ignored) ?  '' : " selected";

        $options .= ">$serial ($hdd)</option>";

    }
    return $options;
}

function get_hdd_options_for_fan($selected=null) {
    $hdds = get_all_hdds();
    $selected = trim((string)$selected);
    $selected_drives = ($selected === '') ? [] : array_flip(array_filter(explode(',', $selected)));
    $options = "";
    foreach ($hdds as $serial => $hdd) {
        $options .= "<option value='$serial'";
        if ($selected === '' || array_key_exists($serial, $selected_drives))
            $options .= " selected";
        $options .= ">$serial ($hdd)</option>";
    }
    return $options;
}

function fan_group_ids(){
    global $fancfg;
    $raw = isset($fancfg['FANGROUPS']) ? trim((string)$fancfg['FANGROUPS']) : '';
    return ($raw === '') ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function get_fan_channel_options($selected=''){
    global $fansensors, $board, $board_json, $cmd_count;
    $selected_map = array_flip(array_filter(explode(',', (string)$selected)));
    $options = '';
    $seen = [];
    foreach($fansensors as $id => $fan){
        if($fan['Type'] !== 'Fan')
            continue;
        $name = normalize_fan_control_name($fan['Name'], $board, count($seen) + 1);
        if(isset($seen[$name]))
            continue;
        $seen[$name] = true;
        $peers = get_shared_fan_channel_peers($name, $board, $board_json, $cmd_count);
        $shared = count($peers) > 1 ? ' shared with '.implode(', ', $peers) : ' independent channel';
        $label = htmlspecialchars($name.' - '.$fan['Name'].' ('.$fan['Reading'].' '.$fan['Units'].')'.$shared);
        $options .= '<option value="'.htmlspecialchars($name).'"'.(isset($selected_map[$name]) ? ' selected' : '').'>'.$label.'</option>';
    }
    return $options;
}

function get_sensor_group_options($selected=''){
    global $fansensors;
    $selected_map = array_flip(array_filter(explode(',', (string)$selected)));
    $options = '';
    foreach($fansensors as $id => $sensor){
        if($sensor['Type'] !== 'Temperature' && $sensor['Name'] !== 'HDD Temperature')
            continue;
        $label = htmlspecialchars($sensor['Name']);
        if(isset($sensor['Reading']))
            $label .= ' - '.htmlspecialchars((string)$sensor['Reading']);
        $options .= '<option value="'.htmlspecialchars($id).'"'.(isset($selected_map[(string)$id]) ? ' selected' : '').'>'.$label.'</option>';
    }
    return $options;
}

function fan_group_label($gid){
    return preg_replace('/[^A-Z0-9_]/', '_', strtoupper((string)$gid));
}

function get_fan_group_editor(){
    global $fancfg, $display_unit;
    $groups = htmlspecialchars(isset($fancfg['FANGROUPS']) ? $fancfg['FANGROUPS'] : '');
    echo '<div id="fan-group-editor" class="fan-group-editor">';
    echo '<input type="hidden" id="FANGROUPS" name="FANGROUPS" value="',$groups,'" />';
    echo '<input type="hidden" id="fan-group-edit-id" value="" />';
    echo '<input type="hidden" id="fan-group-fans-hidden" data-group-field="FANS" value="" />';
    echo '<input type="hidden" id="fan-group-sensors-hidden" data-group-field="SENSORS" value="" />';
    echo '<input type="hidden" id="fan-group-hdds-hidden" data-group-field="HDDINCLUDE" value="" />';
    echo '<dl><dt>Fan group:</dt><dd><input type="text" id="fan-group-name" placeholder="e.g. FRONT_INTAKE" title="Name for this configured fan group" /></dd></dl>';
    echo '<dl><dt>Fans in this group:</dt><dd><select multiple id="fan-group-fans" title="Select the fan ports controlled by this rule">',get_fan_channel_options(),'</select></dd></dl>';
    echo '<dl><dt>Temperature sensors:</dt><dd><select multiple id="fan-group-sensors" title="Select one or more temperature sensors; highest reading controls the group">',get_sensor_group_options(),'</select></dd></dl>';
    echo '<dl class="fan-group-hdd-row"><dt>Hard drives for this fan group:</dt><dd><select multiple id="fan-group-hdds" title="If HDD Temperature is selected, choose which drives belong to this fan group"><option value="">Select All</option>',get_hdd_options_for_fan(''),'</select></dd></dl>';
    echo '<dl><dt>High temperature threshold (&deg;',$display_unit,'):</dt><dd><select id="fan-group-temphi" data-group-field="TEMPHI">',get_temp_range('HI',45,$display_unit),'</select></dd></dl>';
    echo '<dl><dt>Low temperature threshold (&deg;',$display_unit,'):</dt><dd><select id="fan-group-templo" data-group-field="TEMPLO">',get_temp_range('LO',30,$display_unit),'</select></dd></dl>';
    echo '<dl><dt>Fan speed maximum (%):</dt><dd><select id="fan-group-fanmax" data-group-field="FANMAX">',get_minmax_options('HI',64),'</select></dd></dl>';
    echo '<dl><dt>Fan speed minimum (%):</dt><dd><select id="fan-group-fanmin" data-group-field="FANMIN">',get_minmax_options('LO',16),'</select></dd></dl>';
    echo '<dl><dt>HDD Spundown Temperature sensor:</dt><dd><select id="fan-group-temphdd" data-group-field="TEMPHDD"><option value="0">None</option>',get_temp_options(0),'</select></dd></dl>';
    echo '<dl><dt>High temperature threshold Spundown (&deg;',$display_unit,'):</dt><dd><select id="fan-group-temphio" data-group-field="TEMPHIO">',get_temp_range('HI',45,$display_unit),'</select></dd></dl>';
    echo '<dl><dt>Low temperature threshold Spundown (&deg;',$display_unit,'):</dt><dd><select id="fan-group-temploo" data-group-field="TEMPLOO">',get_temp_range('LO',30,$display_unit),'</select></dd></dl>';
    echo '<dl><dt>Fan speed maximum Spundown (%):</dt><dd><select id="fan-group-fanmaxo" data-group-field="FANMAXO">',get_minmax_options('HI',64),'</select></dd></dl>';
    echo '<dl><dt>Fan speed minimum Spundown (%):</dt><dd><select id="fan-group-fanmino" data-group-field="FANMINO">',get_minmax_options('LO',16),'</select></dd></dl>';
    echo '<dl><dt>&nbsp;</dt><dd><input id="fan-group-save" type="submit" value="Add / Update Fan Group"><input id="fan-group-clear" type="button" value="Clear Editor"></dd></dl>';
    echo '</div>';
}

function get_configured_fan_group_table(){
    global $fancfg;
    echo '<div id="configured-fan-groups"><h3>Configured fan groups</h3>';
    echo '<table class="tablesorter fan-group-table"><thead><tr><th>Group</th><th>Fans</th><th>Sensors</th><th>Hard drives</th><th>Actions</th></tr></thead><tbody>';
    foreach(fan_group_ids() as $gid){
        $safe = htmlspecialchars($gid);
        $fans = isset($fancfg['FANS_'.$gid]) ? $fancfg['FANS_'.$gid] : '';
        $sensors = isset($fancfg['SENSORS_'.$gid]) ? $fancfg['SENSORS_'.$gid] : '';
        $hdds = isset($fancfg['HDDINCLUDE_'.$gid]) ? $fancfg['HDDINCLUDE_'.$gid] : '';
        echo '<tr class="fan-group-row" data-group="',$safe,'" data-fans="',htmlspecialchars($fans),'" data-sensors="',htmlspecialchars($sensors),'" data-hdds="',htmlspecialchars($hdds),'">';
        echo '<td>',$safe,'</td><td>',htmlspecialchars($fans),'</td><td>',htmlspecialchars($sensors),'</td><td>',($hdds === '' ? 'All / global' : htmlspecialchars($hdds)),'</td>';
        echo '<td><input type="button" value="Edit" onclick="editFanGroup(\'',$safe,'\')"><input type="button" value="Remove" onclick="removeFanGroup(\'',$safe,'\')"></td></tr>';
    }
    echo '</tbody></table></div>';
}

?>
