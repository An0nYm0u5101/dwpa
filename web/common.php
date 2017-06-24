<?php
//php 5.5 has this one
if (! function_exists('hash_pbkdf2')) {
    // based on https://defuse.ca/php-pbkdf2.htm
    function hash_pbkdf2($algorithm, $password, $salt, $count, $key_length, $raw_output = false)
    {
        $hash_length = array('sha1' => 20,
                             'md5'  => 16,);

        $block_count = ceil($key_length / $hash_length[$algorithm]);

        $output = '';
        for($i = 1; $i <= $block_count; $i++) {
            // $i encoded as 4 bytes, big endian.
            $last = $salt . pack("N", $i);
            // first iteration
            $last = $xorsum = hash_hmac($algorithm, $last, $password, true);
            // perform the other $count - 1 iterations
            for ($j = 1; $j < $count; $j++) {
                $xorsum ^= ($last = hash_hmac($algorithm, $last, $password, true));
            }
            $output .= $xorsum;
        }

        if($raw_output)
            return substr($output, 0, $key_length);
        else
            return bin2hex(substr($output, 0, $key_length));
    }
}

// helper function for PHP version < 5.4.0
if (function_exists('hex2bin') == False) {
    /* Alternative working, but slow function
    function hex2bin($h) {
        if (strlen($h) % 2 != 0)
            $h = '0'.$h;
        if (!ctype_xdigit($h))
            return '';
        $r = '';
        for ($i=0; $i<strlen($h); $i+=2)
            $r .= chr(hexdec($h{$i}.$h{($i+1)}));
        return $r;
    }
    */
    function hex2bin($h) {
        if (strlen($h) & 1)
            $h = '0'.$h;

        return pack('H*', $h);
    }
}

/*
    check_key(hccap contents, array of keys)
    return:  False: bad format;
             Null: not found
             string: the key
    hccap structure http://hashcat.net/wiki/doku.php?id=hccap

    typedef struct
    {
        char          essid[36];

        unsigned char mac1[6];
        unsigned char mac2[6];
        unsigned char nonce1[32];
        unsigned char nonce2[32];

        unsigned char eapol[256];
        int           eapol_size;

        int           keyver;
        unsigned char keymic[16];

    } hccap_t;
*/
function check_key($hccap, $keys, $nc=65535) {
    if (strlen($hccap) != 392)
        return False;

    $ahccap = array();
    if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
        $ahccap['essid']      = unpack('Z36', substr($hccap, 0x000, 36));
    } else {
        $ahccap['essid']      = unpack('a36', substr($hccap, 0x000, 36));
    }
    $ahccap['mac1']       =               substr($hccap, 0x024, 6);
    $ahccap['mac2']       =               substr($hccap, 0x02a, 6);
    $ahccap['nonce1']     =               substr($hccap, 0x030, 32);
    $ahccap['nonce2']     =               substr($hccap, 0x050, 32);
    $ahccap['eapol']      =               substr($hccap, 0x070, 256);
    $ahccap['eapol_size'] = unpack('i',   substr($hccap, 0x170, 4));
    $ahccap['keyver']     = unpack('i',   substr($hccap, 0x174, 4));
    $ahccap['keymic']     =               substr($hccap, 0x178, 16);

    // fixup unpack
    $ahccap['essid']      = $ahccap['essid'][1];
    $ahccap['eapol_size'] = $ahccap['eapol_size'][1];
    $ahccap['keyver']     = $ahccap['keyver'][1];

    // cut eapol to right size
    $ahccap['eapol'] = substr($ahccap['eapol'], 0, $ahccap['eapol_size']);

    // fix order
    if (strncmp($ahccap['mac1'], $ahccap['mac2'], 6) < 0)
        $m = $ahccap['mac1'].$ahccap['mac2'];
    else
        $m = $ahccap['mac2'].$ahccap['mac1'];

    $swap = False;
    if (strncmp($ahccap['nonce1'], $ahccap['nonce2'], 6) < 0)
        $n = $ahccap['nonce1'].$ahccap['nonce2'];
    else {
        $n = $ahccap['nonce2'].$ahccap['nonce1'];
        $swap = True;
    }

    $last1 = substr($ahccap['nonce2'], 24, 4);
    $last2 = substr($ahccap['nonce2'], 28, 4);
    
    $last1le = unpack('V', $last1);
    $last2le = unpack('V', $last2);
    $last1be = unpack('N', $last1);
    $last2be = unpack('N', $last2);
    
    $corr['V'] = ($last1le[1] << 32) | $last2le[1];
    $corr['N'] = ($last1be[1] << 32) | $last2be[1];
    $halfnc = intdiv($nc, 2) + 1;
    $ncarr = array(array('N', 0));

    foreach ($keys as $key) {
        $kl = strlen($key);
        if (($kl < 8) || ($kl > 64))
            continue;

        $pmk = hash_pbkdf2('sha1', $key, $ahccap['essid'], 4096, 32, True);

        do {
            foreach ($ncarr as $j) {
                $rawlast1 = pack($j[0], $corr[$j[0]] + $j[1] >> 32);
                $rawlast2 = pack($j[0], $corr[$j[0]] + $j[1]);

                if ($swap) {
                    $n = substr_replace($n, $rawlast1.$rawlast2, 24, 8);
                } else {
                    $n = substr_replace($n, $rawlast1.$rawlast2, 56, 8);
                }

                $ptk = hash_hmac('sha1', "Pairwise key expansion\0".$m.$n."\0", $pmk, True);

                if ($ahccap['keyver'] == 1)
                    $testmic = hash_hmac('md5',  $ahccap['eapol'], substr($ptk, 0, 16), True);
                else
                    $testmic = hash_hmac('sha1', $ahccap['eapol'], substr($ptk, 0, 16), True);

                if (strncmp($testmic, $ahccap['keymic'], 16) == 0) {
                    return $key;
                }
            }
            if ($ncarr[0][1] == 0) {
                $ncarr = array(array('V', 1), array('V', -1), array('N', 1), array('N', -1));
            } else {
                $ncarr[0][1] += 1;
                $ncarr[1][1] -= 1;
                $ncarr[2][1] += 1;
                $ncarr[3][1] -= 1;
            }
        } while ($ncarr[0][1]<=$halfnc);
    }

    return NULL;
}

//Extract keymic
function get_mic($hccap) {
    return substr($hccap, 0x178, 16);
}

//Process submission
function submission($mysql, $file) {
    //clean uploaded capture
    $res = '';
    $rc  = 0;
    exec(WPACLEAN.' '.SHM.' '.$file, $res, $rc);

    //parse wpaclean output and create references to $incap mic
    $incap = array();
    $ref = array('');
    foreach ($res as $net) {
        if (strlen($net) > 59) {
            $mic = hex2bin(substr($net, 26, 32));
            if (isset($incap[$mic]))
                continue;
            $incap[$mic] = array($mic,                          //mic
                                 mac2long(substr($net, 4, 17)), //ibssid
                                 substr($net, 59));             //ssid
            $ref[] = & $incap[$mic][0];
        }
    }
    if (count($incap) == 0) {
        @unlink($file);
        return false;
    }
    $ref[0] = str_repeat('s',count($incap));

    //get all net_ids of of networks already in the DB
    $sql = 'SELECT net_id, mic FROM nets WHERE mic IN ('.implode(',', array_fill(0, count($incap), '?')).')';
    $stmt = $mysql->stmt_init();
    $stmt->prepare($sql);
    call_user_func_array(array($stmt, 'bind_param'), $ref);
    $stmt->execute();
    stmt_bind_assoc($stmt, $data);
    while ($stmt->fetch()) {
        $incap[$data['mic']][] = $data['net_id'];
    }
    $stmt->close();

    //get u_id if we have key set
    $u_id = Null;
    if (isset($_COOKIE['key']))
        if (valid_key($_COOKIE['key'])) {
            $sql = 'SELECT u_id FROM users WHERE userkey=UNHEX(?)';
            $stmt = $mysql->stmt_init();
            $stmt->prepare($sql);
            $stmt->bind_param('s', $_COOKIE['key']);
            $stmt->execute();
            $stmt->bind_result($u_id);
            $stmt->fetch();
            $stmt->close();
        }

    // Prepare nets for import
    $sql = 'INSERT IGNORE INTO nets(bssid, ssid, ip, mic, cap, hccap) VALUES(?, ?, ?, ?, ?, ?)';
    $stmt = $mysql->stmt_init();
    $stmt->prepare($sql);

    // Prepare n2u for insert
    if ($u_id != Null) {
        $n2usql = 'INSERT IGNORE INTO n2u(net_id, u_id) VALUES(?, ?)';
        $n2ustmt = $mysql->stmt_init();
        $n2ustmt->prepare($n2usql);
    }

    //BEGIN TRANSACTION
    $mysql->autocommit(FALSE);
    foreach ($incap as $net) {
        //associate net with user
        if (isset($net[3])) {
            if ($u_id != Null) {
                $n2ustmt->bind_param('ii', $net[3], $u_id);
                $n2ustmt->execute();
            }
            continue;
        }
        $dotmac = long2mac($net[1]);

        $bnfile = SHM.bin2hex($net[0]).'.mic.cap';
        if (! file_exists($bnfile)) {
            file_put_contents('shitlog.txt', "$bnfile not exists, continue...", FILE_APPEND);
            continue;
        }
        //run through pyrit analyze
        $cut = '';
        $rc  = 0;
        exec(PYRIT.' -r '.$bnfile.' analyze', $cut, $rc);
        //check for correct errorcode and if we have only one AP
        if (($rc == 0) && (strpos(implode("\n", $cut), 'got 1 AP(s)') !== FALSE)) {
            //generate hccap
            $cut = '';
            exec(CAP2HCCAP." $bnfile $bnfile.hccap", $cut, $rc);
            if (($rc == 0) && filesize("$bnfile.hccap") == 392) {
                //we are OK, read data
                $cap = file_get_contents($bnfile);
                $gzcap = gzencode($cap, 9);
                $hccap = file_get_contents("$bnfile.hccap");
                $gzhccap = gzencode($hccap, 9);
                //extract mic
                $mic = get_mic($hccap);
                if ($mic != $net[0]) {
                    file_put_contents('shitlog.txt', print_r($net, True), FILE_APPEND);
                }
                //put in db
                $ip = ip2long($_SERVER['REMOTE_ADDR']);
                $stmt->bind_param('isisss', $net[1], $net[2], $ip, $net[0], $gzcap, $gzhccap);
                $stmt->execute();
                $net_id = $mysql->insert_id;
                if (($u_id != Null) && ($net_id != 0)) {
                    $n2ustmt->bind_param('ii', $net_id, $u_id);
                    $n2ustmt->execute();
                }
            }
            @unlink("$bnfile.hccap");
        }
    }
    $stmt->close();

    chmod($file, 0644);
    move_uploaded_file($file, CAP.$_SERVER['REMOTE_ADDR'].'-'.md5_file($file).'.cap');

    //update net count stats
    $mysql->query("UPDATE stats SET pvalue = (SELECT count(net_id) FROM nets) WHERE pname='nets'");
    $mysql->query("UPDATE stats SET pvalue = (SELECT count(DISTINCT bssid) FROM nets) WHERE pname='nets_unc'");

    $mysql->commit();

    foreach ($incap as $net) {
        @unlink(SHM.bin2hex($net[0]).'.mic.cap');
    }

    return true;
}

//Put work
function put_work($mysql) {
    if (empty($_POST))
        return false;

    //get nets by bssid
    $sql = 'SELECT net_id, hccap FROM nets WHERE bssid = ? AND n_state=0';
    $stmt = $mysql->stmt_init();
    $stmt->prepare($sql);
    $data = array();
    stmt_bind_assoc($stmt, $data);

    //get net by nhash
    $nsql = 'SELECT net_id, hccap FROM nets WHERE mic = unhex(?) AND n_state=0';
    $nstmt = $mysql->stmt_init();
    $nstmt->prepare($nsql);
    $ndata = array();
    stmt_bind_assoc($nstmt, $ndata);

    //Update key stmt
    $usql = 'UPDATE nets SET pass=?, sip=?, n_state=1, sts=NOW() WHERE net_id=?';
    $ustmt = $mysql->stmt_init();
    $ustmt->prepare($usql);

    $mcount = 0;
    foreach ($_POST as $bssid_or_mic => $key) {
        if (strlen($key) < 8)
            continue;
        if (valid_mac($bssid_or_mic)) {
            //old style submission with bssid
            $ibssid = mac2long($bssid_or_mic);
            $stmt->bind_param('i', $ibssid);
            $stmt->execute();

            while ($stmt->fetch()) {
                $hccap = gzinflate(substr($data['hccap'], 10));
                if ($key == check_key($hccap, array($key))) {
                    //put result in nets
                    $iip = ip2long($_SERVER['REMOTE_ADDR']);
                    $net_id = $data['net_id'];
                    $ustmt->bind_param('sii', $key, $iip, $net_id);
                    $ustmt->execute();
                    //delete from n2d
                    $mysql->query("DELETE FROM n2d WHERE net_id=$net_id");
                }
            }
            $stmt->free_result();
        } elseif (valid_key($bssid_or_mic)) {
            //hash submission
            $mic = strtolower($bssid_or_mic);
            $nstmt->bind_param('s', $mic);
            $nstmt->execute();

            if ($nstmt->fetch()) {
                $hccap = gzinflate(substr($ndata['hccap'], 10));
                if ($key == check_key($hccap, array($key))) {
                    //put result in nets
                    $iip = ip2long($_SERVER['REMOTE_ADDR']);
                    $net_id = $ndata['net_id'];
                    $ustmt->bind_param('sii', $key, $iip, $net_id);
                    $ustmt->execute();
                    //delete from n2d
                    $mysql->query("DELETE FROM n2d WHERE net_id=$net_id");
                }
            }
            $nstmt->free_result();
        }
        if ($mcount++ > 20)
            break;
    }
    $stmt->close();
    $ustmt->close();
    $nstmt->close();

    //Update cracked net stats
    $mysql->query("UPDATE stats SET pvalue = (SELECT count(net_id) FROM nets WHERE n_state=1) WHERE pname='cracked'");
    $mysql->query("UPDATE stats SET pvalue = (SELECT count(DISTINCT bssid) FROM nets WHERE n_state=1) WHERE pname='cracked_unc'");

    //Create new cracked.txt.gz and update wcount
    $sql = 'SELECT pass FROM (SELECT pass, count(pass) AS c FROM nets WHERE n_state=1 GROUP BY pass) i ORDER BY i.c DESC';
    $stmt = $mysql->stmt_init();
    $stmt->prepare($sql);
    $data = array();
    stmt_bind_assoc($stmt, $data);
    $stmt->execute();
    $wl = '';
    $i = 0;
    while ($stmt->fetch()) {
        $wl = "$wl{$data['pass']}\n";
        $i += 1;
    }
    $stmt->close();

    $gzdata    = gzencode($wl, 9);
    $md5gzdata = md5($gzdata, True);
    
    $sem = sem_get(888);
    sem_acquire($sem);
    file_put_contents(CRACKED, $gzdata);
    sem_release($sem);

    //update wcount for cracked dict
    $cr = '%'.basename(CRACKED);
    $sql = 'UPDATE dicts SET wcount = ?, dhash = ? WHERE dpath LIKE ?';
    $stmt = $mysql->stmt_init();
    $stmt->prepare($sql);
    $stmt->bind_param('iss', $i, $md5gzdata, $cr);
    $stmt->execute();
    $stmt->close();

    return true;
}

//MAC conversions and checks
function mac2long($mac) {
    return hexdec(str_replace(':', '', $mac));
}

function long2mac($lmac) {
    $pmac = str_pad(dechex($lmac), 12, '0', STR_PAD_LEFT);
    return "{$pmac[0]}{$pmac[1]}:{$pmac[2]}{$pmac[3]}:{$pmac[4]}{$pmac[5]}:{$pmac[6]}{$pmac[7]}:{$pmac[8]}{$pmac[9]}:{$pmac[10]}{$pmac[11]}";
}

function valid_mac($mac, $part=6) {
    return preg_match('/^([a-f0-9]{2}\:?){'.$part.'}$/', strtolower($mac));
}

//Generate random key
function gen_key() {
    $fp = fopen('/dev/random','rb');
    $rand = fread($fp, 32);
    fclose($fp);
    return md5($rand);
}

/*
Validate an email address.
Provide email address (raw input)
Returns true if the email address has the email 
address format and the domain exists.
*/
function validEmail($email) {
	$isValid = true;
	$atIndex = strrpos($email, "@");
	if (is_bool($atIndex) && !$atIndex) {
		$isValid = false; 
	} else {
		$domain = substr($email, $atIndex+1);
		$local = substr($email, 0, $atIndex);
		$localLen = strlen($local);
		$domainLen = strlen($domain);
		if ($localLen < 1 || $localLen > 64) {
			// local part length exceeded
			$isValid = false;
		} else if ($domainLen < 1 || $domainLen > 255) {
			// domain part length exceeded
			$isValid = false;
		} else if ($local[0] == '.' || $local[$localLen-1] == '.') {
			// local part starts or ends with '.'
			$isValid = false;
		} else if (preg_match('/\\.\\./', $local)) {
			// local part has two consecutive dots
			$isValid = false;
		} else if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain)) {
			// character not valid in domain part
			$isValid = false;
		} else if (preg_match('/\\.\\./', $domain)) {
			// domain part has two consecutive dots
			$isValid = false;
		} else if (!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/', str_replace("\\\\","",$local))) {
			// character not valid in local part unless 
			// local part is quoted
			if (!preg_match('/^"(\\\\"|[^"])+"$/', str_replace("\\\\","",$local))) {
				$isValid = false;
			}
		}
		if ($isValid && !(checkdnsrr($domain,"MX") || checkdnsrr($domain,"A"))) {
			// domain not found in DNS
			$isValid = false;
		}
	}
	return $isValid;
}

//convert num
function convert_num($num) {
    $num = (float) $num;
    if ($num >= 1000000000000) {
        $tera = $num / 1000000000000;
        $size = sprintf('%.2fT', $tera);
    } elseif ($num >= 1000000000) {
        $giga = $num / 1000000000;
        $size = sprintf('%.2fG', $giga);
    } elseif ($num >= 1000000) {
        $mega = $num / 1000000;
        $size = sprintf('%.2fM', $mega);
    } elseif ($num >= 1000) {
        $kilo = $num / 1000;
        $size = sprintf('%.2fK', $kilo);
    } else
        $size = sprintf('%.2f', $num);
    return $size;
}

//convert seconds to text
function convert_sec($secs) {
    $units = array (
        'year'   => 29030400, // seconds in a year   (12 months)
        'month'  => 2419200,  // seconds in a month  (4 weeks)
        'day'    => 86400,    // seconds in a day    (24 hours)
        'hour'   => 3600      // seconds in an hour  (60 minutes)
    );
    $output='';

    foreach($units as $unit => $mult)
        if($secs >= $mult) {
            $and = (($mult != 1) ? ('') : ('and '));
            $output .= ', '.$and.intval($secs / $mult).' '.$unit.((intval($secs / $mult) == 1) ? ('') : ('s'));
            $secs -= intval($secs / $mult) * $mult;
        }

    //remove leading ,
    return substr($output, 2);
}

//Write nets table
function write_nets($stmt, $data) {
    $has_input = false;
    echo '
<style>
td {padding-left: 7px; padding-right: 7px}
</style>
<script type="text/javascript">
function goWigle(bssid) {
    document.getElementById("netid").value = bssid;
    document.getElementById("wigle").submit();
}
</script>
<form method="POST" action="https://www.wigle.net/gps/gps/main/confirmquery" target="_blank" id="wigle" >
<input type="hidden" name="netid" id="netid" />
</form>
<form class="form" method="POST" action="?nets" enctype="multipart/form-data">
<table style="border: 1;">
<tr><th>BSSID</th><th>SSID</th><th>WPA key</th><th>Get works</th><th>Timestamp</th></tr>';
    while ($stmt->fetch()) {
        $bssid = long2mac($data['bssid']);
        $mic = $data['mic'];
        $ssid = htmlspecialchars($data['ssid']);
        if ($data['pass'] == '') {
            $pass = '<input class="input" type="text" name="'.$mic.'" size="20"/>';
            $has_input = true;
        } else
            $pass = htmlspecialchars($data['pass']);
        echo "<tr><td style=\"font-family:monospace; font-size: 12px; cursor: pointer; \"><a title=\"Wigle geo query. You must be logged in.\" onclick=\"goWigle(this.text)\">$bssid</a></td><td>$ssid</td><td>$pass</td><td align=\"right\">{$data['hits']}</td><td>{$data['ts']}</td></tr>\n";
    }
    echo '</table>';
    if ($has_input)
        echo '<input class="submitbutton" type="submit" value="Send WPA keys" />';
    echo '</form>';
}
?>
