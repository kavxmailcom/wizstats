<?php
#    wizstats - bitcoin pool web statistics
#    Copyright (C) 2012  Jason Hughes <wizkid057@gmail.com>
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU Affero General Public License as published by
#    the Free Software Foundation, either version 3 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU Affero General Public License for more details.
#
#    You should have received a copy of the GNU Affero General Public License
#    along with this program.  If not, see <http://www.gnu.org/licenses/>.


# TODO: Allow worker sub-names

if (!isset($_SERVER['PATH_INFO'])) {
	print "Error: Specify username in path\n";
	exit;
}

require_once 'config.php';
$link = pg_Connect("dbname=$psqldb user=$psqluser password='$psqlpass' host=$psqlhost");


$givenuser = substr($_SERVER['PATH_INFO'],1,strlen($_SERVER['PATH_INFO'])-1);
$bits =  hex2bits(\Bitcoin::addressToHash160($givenuser));

$sql = "select id from public.users where keyhash='$bits' order by id asc limit 1";
$result = pg_exec($link, $sql);
$numrows = pg_numrows($result);
if (!$numrows) {
	print "Error: Username <I>$givenuser</I> not found in database.  Please try again later.";
	exit;
}

$row = pg_fetch_array($result, 0);
$user_id = $row["id"];



if (isset($_GET["cmd"])) {
	$cmd = $_GET["cmd"];

	$res   = isset($_GET["res"])?$_GET["res"]:1;
	$start = isset($_GET["start"])?$_GET["start"]:0;
	$back  = isset($_GET["back"])?$_GET["back"]:86400;
	if (($res < 1) || ($res > 128)) { $res = 1; }
	if (($start < 0)) { $start = 0; }
	if (($back < 675)) { $back = 675; }
	$sstart = $back+$start;
	$ressec = $res * 675;


	if ($cmd == "balancegraph") {

		$sql = "select * from $psqlschema.stats_balances where user_id=$user_id and server=$serverid and time > to_timestamp((date_part('epoch', NOW()-'$sstart seconds'::interval)::integer / 675) * 675) and time < to_timestamp((date_part('epoch', NOW()-'$start seconds'::interval)::integer / 675) * 675) order by time desc;";
		$result = pg_exec($link, $sql);
		$numrows = pg_numrows($result);

		print "date,everpaid,unpaid+everpaid,maximum reward\n";

		for($ri = 0; $ri < $numrows; $ri++) {

			$row = pg_fetch_array($result, $ri);
			print $row["time"].",".($row["everpaid"]/100000000).",".(($row["balance"]/100000000)+($row["everpaid"]/100000000)).",".(($row["credit"]/100000000)+(($row["balance"]/100000000)+($row["everpaid"]/100000000)))."\n";
		}

		exit;

	}

	if ($cmd == "hashgraph") {


		print "date,".$ressec." seconds,3 hour,12 hour\n";

		$sql = "select gtime,COALESCE(hashrate,0) as hashrate from (select * from generate_series(to_timestamp(((date_part('epoch', (select time from stats_shareagg where server=$serverid group by server,time order by time desc limit 1)-'$sstart seconds'::interval)::integer / 675) * 675)-43200), to_timestamp((date_part('epoch', (select time from stats_shareagg where server=$serverid group by server,time order by time desc limit 1)-'$start seconds'::interval)::integer / 675) * 675), '$ressec seconds') as gtime) as gentime left join (select * from wizkid057.stats_shareagg where user_id=$user_id) as dstats on (dstats.time = gentime.gtime);";
		$result = pg_exec($link, $sql);
		$numrows = pg_numrows($result);

		$an = 0;
		$an2 = 0;

		for($i=0;$i<64;$i++) { $ta[$i] = 0; $ta2[$i] = 0; }
		for($i=0;$i<128;$i++) { $lagavg3[$i] = 0; $lagavg12[$i] = 0; }

		for($ri = 0; $ri < $numrows+32; $ri++) {
			$row = pg_fetch_array($result, $ri);
			if ($ri < $numrows) {
				$ta[$an] = $row["hashrate"]; $an++; if ($an == 16) { $an = 0; } $av = 0; for($i=0;$i<16;$i++) { $av += $ta[$i]; } if ($ri > 16) {	$av = $av / 16; } else { $av = ""; }
				$ta2[$an2] = $row["hashrate"]; $an2++; if ($an2 == 64) { $an2 = 0; } $av2 = 0; for($i=0;$i<64;$i++) { $av2 += $ta2[$i]; } if ($ri > 64) {	$av2 = $av2 / 64; } else { $av2 = ""; }
				list($datex) = explode("+", $row["gtime"]);
				$lagavg3[$lav] = round($av/1000000,2);
				$lagavg12[$lav] = round($av2/1000000,2);
			} else {
				unset($row);
				$lagavg3[$lav] = "";
				$lagavg12[$lav] = "";
			}
			if ($ri > 64) {
				$lagavg675[$lav] = $datex.",".round($row["hashrate"]/1000000,2);
				if ($ri > 64+32) {

					$lavx = $lav - 32; if ($lavx < 0) { $lavx+=128; }
					$lavy = $lav - 24; if ($lavy < 0) { $lavy+=128; }
					$lavz = $lav - 0; if ($lavz < 0) { $lavz+=128; }

					print $lagavg675[$lavx].",".$lagavg3[$lavy].",".$lagavg12[$lavz]."\n";
				}
			}
			$lav++; if ($lav == 128) { $lav = 0; }
		}

		exit;
	}

}


$sql = "select * from $psqlschema.stats_balances where user_id=$user_id order by time desc limit 1";
$result = pg_exec($link, $sql);
$numrows = pg_numrows($result);
if (!$numrows) {
	$bal = "N/A"; $cbe = "N/A"; $ec = "N/A"; $datadate = "N/A";
} else {
	$row = pg_fetch_array($result, 0);
	$bal = $row["balance"];
	$ec = $row["credit"];
	$datadate = $row["time"];
}


### TODO: CONSIDER ORPHANS HERE ?
### actually, maybe the coinbaser does this? not sure... need to catch it...
$balx = file_get_contents("/var/lib/eligius/$serverid/blocks/latest.json");
$balj = json_decode($balx,true);
$latest = $balj[$givenuser];

if (isset($latest["balance"])) { $lbal = $latest["balance"]; } else { $lbal = 0; }
if (isset($latest["credit"])) { $lec = $latest["credit"]; } else { $lec = 0; }

$cbal = $bal - $lbal;
$cec = $ec - $lec;

$xbal = $bal - $cbal;
$xec = $ec - $cec;

$_COOKIE['a2_tbc'] = 0;
setcookie("a2_tbc",0);


if ($cbal > 0) { $cbalt = "+".prettySatoshis($cbal); }
else { $cbalt = prettySatoshis($cbal); }
if ($cec > 0) { $cect = "+".prettySatoshis($cec); }
else { $cect = prettySatoshis($cec); }



$xbal = prettySatoshis($xbal);
$xec = prettySatoshis($xec);
$bal = prettySatoshis($bal);
$ec = prettySatoshis($ec);


$titleprepend = "($bal) $givenuser - ";
print_stats_top();


#print "<A HREF=\"/~wizkid057/\">Back to main Stats page</A><BR>";

print "<H2>$givenuser</H2>";

#print "Data as of $datadate<BR>";
print "<TABLE BORDER=1>";
print "<TR><TD></TD><TD>Unpaid Balance</TD><TD>Extra Credit</TD></TR>";
print "<TR><TD>As of last block*: </TD><TD>$xbal</TD><TD>$xec</TD></TR>";
print "<TR><TD>Estimated Change: </TD><TD>$cbalt</TD><TD>$cect</TD></TR>";
print "<TR><TD>Estimated Total: </TD><TD>$bal</TD><TD>$ec</TD></TR>";
print "</TABLE>";




# get latest time in share aggregation database...
#$sql = "select server,time from $psqlschema.stats_shareagg where server=$serverid group by server,time order by time desc limit 1;";
#$result = pg_exec($link, $sql); $row = pg_fetch_array($result, 0);
#$latesttime = $row["time"];



# 3 hour hashrate
#$sql = "select avg(COALESCE(hashrate,0)) as avghash from (select * from generate_series(to_timestamp((date_part('epoch', NOW()-'3 hours'::interval)::integer / 675) * 675), to_timestamp((date_part('epoch', NOW())::integer / 675) * 675), '675 seconds') as gtime) as gentime left join (select * from $psqlschema.stats_shareagg where user_id=$user_id) as dstats on (dstats.time = gentime.gtime)";
#$sql = "select (sum(accepted_shares)*pow(2,32))/10800 as avghash from $psqlschema.stats_shareagg where server=$serverid and user_id=$user_id and time >= to_timestamp((date_part('epoch', NOW()-'3 hours'::interval)::integer / 675) * 675)";
# FIX FOR BAD HASHRATE BUG 091512
$sql = "select (sum(accepted_shares)*pow(2,32))/10800 as avghash from $psqlschema.stats_shareagg where server=$serverid and user_id=$user_id and time > to_timestamp((date_part('epoch', (select time from stats_shareagg where server=$serverid group by server,time order by time desc limit 1)-'3 hours'::interval)::integer / 675::integer) * 675::integer)";
$result = pg_exec($link, $sql); $row = pg_fetch_array($result, 0);
$u16avghash = isset($row["avghash"])?$row["avghash"]:0;

# 22.5 minute hashrate
#$sql = "select avg(COALESCE(hashrate,0)) as avghash from (select * from generate_series(to_timestamp((date_part('epoch', NOW()-'1350 seconds '::interval)::integer / 675) * 675), to_timestamp((date_part('epoch', NOW())::integer / 675) * 675), '675 seconds') as gtime) as gentime left join (select * from $psqlschema.stats_shareagg where user_id=$user_id) as dstats on (dstats.time = gentime.gtime)";
#$sql = "select (sum(accepted_shares)*pow(2,32))/1350 as avghash from $psqlschema.stats_shareagg where server=$serverid and user_id=$user_id and time >= to_timestamp((date_part('epoch', NOW())::integer / 675) * 675)-'1350 seconds'::interval";
# FIX FOR BAD HASHRATE BUG 091512
$sql = "select (sum(accepted_shares)*pow(2,32))/1350 as avghash from $psqlschema.stats_shareagg where server=$serverid and user_id=$user_id and time > to_timestamp((date_part('epoch', (select time from stats_shareagg where server=$serverid group by server,time order by time desc limit 1))::integer / 675::integer)::integer * 675::integer)-'1350 seconds'::interval";
$result = pg_exec($link, $sql); $row = pg_fetch_array($result, 0);
$u2avghash = isset($row["avghash"])?$row["avghash"]:0;

#print "<SMALL><I>Note: There is a known bug in these hashrate calculations which makes them show slightly lower than expected.  Rest assured that these stats have no impact on actual earnings and that I will fix this shortly!</i></SMALL><BR>";
print "3 hour average hashrate: ".prettyHashrate($u16avghash)."<BR>\n";
print "22.5 minute average hashrate: ".prettyHashrate($u2avghash)."<BR>\n";



if (isset($_GET["timemachine"])) {
	$secondsback = 5184000;
} else {
	$secondsback = 604800;
}



print "<div id=\"graphdiv2\"
  style=\"width:750px; height:375px;\"></div>
";

if (!isset($_GET["timemachine"])) {

print "<A HREF=\"?timemachine=1\">(Click for up to 60 days of hashrate data)</A><BR>";

}

print "<div id=\"graphdiv3\"
  style=\"width:750px; height:375px;\"></div>
<script type=\"text/javascript\">

  g2 = new Dygraph(
    document.getElementById(\"graphdiv2\"),
    \"$givenuser?cmd=hashgraph&start=0&back=$secondsback&res=1\", // path to CSV file
   	{

		strokeWidth: 2.25,

		'675 seconds': { fillGraph: true, strokeWidth: 1.5 },
      labelsDivStyles: { border: '1px solid black' },
      title: 'Hashrate Graph ($givenuser)',
      xlabel: 'Date',
      ylabel: 'MH/sec'          // options
	}
  );


  g3 = new Dygraph(
    document.getElementById(\"graphdiv3\"),
    \"$givenuser?cmd=balancegraph&start=0&back=604800&res=1\", // path to CSV file
   

	{

		strokeWidth: 2.25,

		fillGraph: true,
      labelsDivStyles: { border: '1px solid black' },
      title: 'Balance Graph ($givenuser)',
      xlabel: 'Date',
      ylabel: 'BTC'          // options
	}
  );
</script>
";

print_stats_bottom();

?>
