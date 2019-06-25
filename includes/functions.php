<?php
/*
Open DMARC Analyzer - Open Source DMARC Analyzer
includes/functions.php
2019 - John Bradley (userjack6880)

Available at: https://github.com/userjack6880/Open-DMARC-Analyzer

This file is part of Open DMARC Analyzer.

Open DMARC Analyzer is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software 
Foundation, either version 3 of the License, or (at your option) any later 
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY 
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with 
this program.  If not, see <https://www.gnu.org/licenses/>.
*/

// Debug Functions //
function debug($string, $debugLevel = DEBUG) {
	if ($debugLevel == 1 && php_sapi_name() === 'cli') { echo "$string\n"; } // Basic CLI Debug Level
	if ($debugLevel == 2) { echo "$string<br>\n"; } // Web Debug Level
}

// Get Data //
function report_data($pdo, $dateRange = DATE_RANGE, $serial = NULL) {

	// pull the data of all reports within date range
	$startDate = start_date($dateRange);
	if (isset($serial)) {
		$params = array(':startDate' => $startDate, ':serial' => $serial);
		$query = $pdo->prepare("SELECT * FROM `report` WHERE `serial` = :serial AND `mindate` BETWEEN :startDate AND NOW() ORDER BY `domain`");
	} else {
		$params = array(':startDate' => $startDate);
		$query = $pdo->prepare("SELECT * FROM `report` WHERE `mindate` BETWEEN :startDate AND NOW() ORDER BY `serial`");
	}
	$query->execute($params);
	$rows = [];
	while ($row = $query->fetch(PDO::FETCH_ASSOC)) { array_push($rows, $row); }
	$query = null;
	return $rows;
}

function domain_data($pdo, $dateRange = DATE_RANGE, $domain) {
	// This function will only work if the domain is given
	if (!isset($domain)) { die("critical error: must have domain name defined"); }

	// since we know the domain, we need to get all of the serial numbers of reports associated with this domain
	$params = array(':domain' => $domain);
	$query = $pdo->prepare("SELECT DISTINCT `serial` FROM `rptrecord` WHERE `identifier_hfrom` = :domain");
	$query->execute($params);

	// now that we have the serial numbers, let's get the data for each serial number
	$rows = [];
	while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
		$rdata = report_data($pdo, $dateRange, $row['serial']);
		// this will return an array of rows - we'll need to merge this with the existing blank rows array
		debug("Merging arrays for ".$row['serial']);
		$rows = array_merge($rows, $rdata);
	}

	$query = null;
	debug("ROWS Array\n".print_r($rows,true));
	return $rows;
}

function dmarc_data($pdo, $rdata, $domain = NULL) {

	$counts = [];
	// using said serial numbers, pull all rpt record data
	// run through each row, and count total emails, the alignment counts, and results
	foreach ($rdata as $data) {
		if (isset($domain)) {
			$params = array(':serial' => $data['serial'], ':domain' => $domain);
			$query = $pdo->prepare("SELECT * from `rptrecord` WHERE `serial` = :serial AND `identifier_hfrom` = :domain ORDER BY `identifier_hfrom`");
		} else {
			$params = array(':serial' => $data['serial']);
			$query = $pdo->prepare("SELECT * from `rptrecord` WHERE `serial` = :serial ORDER BY `identifier_hfrom`");
		}
		$query->execute($params);
		while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
			$id = strtolower($row['identifier_hfrom']);

			if (empty($counts[$id])) { 
				$counts[$id] = new stdClass(); 
				$counts[$id]->hfrom      = $id;
				$counts[$id]->rcount     = 0;
				$counts[$id]->numReport  = 0;
				$counts[$id]->resultDKIM = 0;
				$counts[$id]->resultSPF  = 0;
				$counts[$id]->alignDKIM  = 0;
				$counts[$id]->alignSPF   = 0;
				$counts[$id]->compliance = 0;
				$counts[$id]->policy     = $data['policy_p'];
				$counts[$id]->policyPct  = $data['policy_pct'];
				$counts[$id]->reports    = [];
			}
			$counts[$id]->numReport++;
			$counts[$id]->rcount += $row['rcount'];
			if ($row['dkimresult'] == 'pass')   { $counts[$id]->resultDKIM++; }
			if ($row['spfresult']  == 'pass')   { $counts[$id]->resultSPF++;  }
			if ($row['dkim_align'] == 'pass')   { $counts[$id]->alignDKIM++;  }
			if ($row['spf_align']  == 'pass')   { $counts[$id]->alignSPF++;   }

			// let's properly count compliance - if results and alignment pass for either SPF or DKIM, it's compliant
			if (($row['dkimresult'] == 'pass' && $row['dkim_align'] == 'pass') || ($row['spfresult'] == 'pass' && $row['spf_align'] == 'pass')) {
				$counts[$id]->compliance++;
			}
			
			if (empty($counts[$id]->reports[$data['org']])) { $counts[$id]->reports[$data['org']] = 0; }
			$counts[$id]->reports[$data['org']]++;
		}

		$query = null;
	}

	return $counts;
}

// Domain Reports //

function domain_reports($domain, $pdo, $dateRange = DATE_RANGE) {
	echo "<h2>Domain Details for $domain - Since ".start_date($dateRange)."</h2>\n";

	// pull serial numbers of reports within date range and with specific domain
	$rdata = domain_data($pdo, $dateRange, $domain);
	$counts = dmarc_data($pdo, $rdata, $domain);	

	domain_reports_dkim_table_start();

	foreach ($counts as $data) {
		echo "\t<tr class='dash_row'>\n";
		echo "\t\t<td><a href='domain.php?domain=".$data->hfrom."'>".$data->hfrom."</a></td>\n";
		echo "\t\t<td>".$data->rcount."</td>\n";

		$alignDKIM  = number_format(100 * ($data->alignDKIM  / $data->numReport));
		$alignSPF   = number_format(100 * ($data->alignSPF   / $data->numReport));
		$DKIMpass   = number_format(100 * ($data->resultDKIM / $data->numReport));
		$SPFpass    = number_format(100 * ($data->resultSPF  / $data->numReport));
		$compliance = number_format(100 * ($data->compliance / $data->numReport));

		echo "\t\t<td>".$data->policyPct."% ".$data->policy."</td>\n";

		echo "\t\t<td>\n";
		echo "\t\t\t<div class='perc-text'><span>$compliance% Compliant</span></div>\n";
		echo "\t\t\t<div class='perc-bar'>\n";
		echo "\t\t\t\t<div class='green-per' style='width:$compliance%'></div>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t</td>\n";

		echo "\t\t<td>\n";
		echo "\t\t\t<div class='perc-text'>\n";
		echo "\t\t\t\t<span>$alignDKIM% Aligned | $DKIMpass% Passed</span>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t\t<div class='perc-bar'>\n";
		echo "\t\t\t\t<div class='gray-per' style='width:$DKIMpass%'></div>\n";
		echo "\t\t\t\t<div class='green-per' style='width:$alignDKIM%'></div>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t</td>\n";

		echo "\t\t<td>\n";
		echo "\t\t\t<div class='perc-text'>\n";
		echo "\t\t\t\t<span>$alignSPF% Aligned | $SPFpass% Passed</span>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t\t<div class='perc-bar'>\n";
		echo "\t\t\t\t<div class='gray-per' style='width:$SPFpass%'></div>\n";
		echo "\t\t\t\t<div class='green-per' style='width:$alignSPF%'></div>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t</td>\n";
	}

	echo "</table>\n";

	// list out the reports
	domain_reports_table_start();
	
	foreach ($rdata as $data) {
		echo "\t<tr>\n";
		echo "\t\t<td>".$data['mindate']." - ".$data['maxdate']."</td>\n";
		echo "\t\t<td>".$data['org']."</td>\n";
		echo "\t\t<td><a href='report.php?serial=".$data['serial']."'>".$data['reportid']."</a></td>\n";
		echo "\t<tr>\n";
	}
	echo "</table>\n";

	// now let's just list out all the details
	reports_table_start();

	foreach ($rdata as $data) {
		$params = array(':serial' => $data['serial'], ':domain' => $domain);
		$query = $pdo->prepare("SELECT * FROM `rptrecord` WHERE `serial` = :serial AND `identifier_hfrom` = :domain");
		$query->execute($params);
		while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
			debug ("printing row");
			echo "\t<tr>\n";
			echo "\t\t<td><a href='report.php?serial=".$data['serial']."'>".$data['reportid']."</a></td>\n";
			echo "\t\t<td>".long2ip($row['ip'])."</td>\n";
			echo "\t\t<td>".gethostbyaddr(long2ip($row['ip']))."</td>\n";
			echo "\t\t<td>".$row['rcount']."</td>\n";
			echo "\t\t<td>".$row['disposition']."</td>\n";
			echo "\t\t<td>".$row['reason']."</td>\n";
			echo "\t\t<td>".$row['dkimdomain']."</td>\n";
			echo "\t\t<td>Result: ".$row['dkimresult']." | Alignment: ".$row['dkim_align']."</td>\n";
			echo "\t\t<td>".$row['spfdomain']."</td>\n";
			echo "\t\t<td>Result: ".$row['spfresult']." | Alignment: ".$row['spf_align']."</td>\n";
			echo "\t</tr>\n";
		} 
		$query = null;
	}
	echo "</table>\n";

}

// Single Report Table //

function single_report($serial, $pdo) {
	// let's get some data from the report that matches this serial number
	$params = array(':serial' => $serial);
	$query = $pdo->prepare("SELECT * FROM `report` WHERE `serial` = :serial");
	$query->execute($params);

	$data = $query->fetch(PDO::FETCH_ASSOC); // this should only return one row

	echo "<h2>Details for Report ".$data['reportid']."</h2>\n";
	echo "<p>Date Range: ".$data['mindate']." - ".$data['maxdate']."<br />\n";
	echo "Domain: ".$data['domain']."<br />\n";
	echo "Reporting Org: ".$data['org']."<br />\n";
	echo "Domain DMARC Policy: ".$data['policy_p'].
	     " | Subdomain Policy: ".$data['policy_sp'].
	     " | Enforcement Percentage: ".$data['policy_pct']."<br />\n";
	echo "DKIM Policy: ".$data['policy_adkim']." | SPF Policy: ".$data['policy_aspf']."<br />\n";

	// Now print a detailed table...
	single_report_table_start();

	$query = null;
	$query = $pdo->prepare("SELECT * FROM `rptrecord` WHERE `serial` = :serial");
	$query->execute($params);

	while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
		echo "\t<tr>\n";
		echo "\t\t<td>".long2ip($row['ip'])."</td>\n";
		echo "\t\t<td>".gethostbyaddr(long2ip($row['ip']))."</td>\n";
		echo "\t\t<td>".$row['rcount']."</td>\n";
		echo "\t\t<td>".$row['disposition']."</td>\n";
		echo "\t\t<td>".$row['reason']."</td>\n";
		echo "\t\t<td>".$row['dkimdomain']."</td>\n";
		echo "\t\t<td>Result: ".$row['dkimresult']." | Alignment: ".$row['dkim_align']."</td>\n";
		echo "\t\t<td>".$row['spfdomain']."</td>\n";
		echo "\t\t<td>Result: ".$row['spfresult']." | Alignment: ".$row['spf_align']."</td>\n";
		echo "\t</tr>\n";
	}

	echo "</table>\n";
	$query = null;
}

function senders_report($pdo, $dateRange = DATE_RANGE, $domain = null, $ip = null) {
	$rdata = report_data($pdo, $dateRange);

	senders_report_table_start();

	$serials = [];
	foreach ($rdata as $data) {
		array_push($serials, $data['serial']);
	}

	$params = array(':ip' => '%%', ':domain' => '%%');
	if (isset($ip)) { $params[':ip'] = "%$ip%"; }
	if (isset($domain)) { $params[':domain'] = "%$domain%"; }
	$query = $pdo->prepare("SELECT DISTINCT `ip` FROM `rptrecord` WHERE `ip` IS NOT NULL AND `ip` LIKE :ip AND `identifier_hfrom` LIKE :domain AND `serial` IN ('".implode("', '",$serials)."') ORDER BY `ip`");
	$query->execute($params);

	while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
		echo "\t<tr>\n";
		echo "\t\t<td>".long2ip($row['ip'])."</td>\n";
		echo "\t\t<td>".gethostbyaddr(long2ip($row['ip']))."</td>\n";
		echo "\t</tr>\n";
	}

	echo "</table>\n";
}

// Dashboard //

function dashboard($pdo, $dateRange = DATE_RANGE) {
	echo "<h2>Dashboard</h2>\n";

	dashboard_dmarc_table_start(start_date($dateRange));

	// Now we calculate the volume of mail, the DMARC compliance, and the verification percentages
	// and each organization and number of reports... and print it out into a table

	$rdata = dmarc_data($pdo, report_data($pdo,$dateRange));	

	foreach ($rdata as $data) {
		echo "\t<tr class='dash_row'>\n";
		echo "\t\t<td><a href='domain.php?domain=".$data->hfrom."'>".$data->hfrom."</a></td>\n";
		echo "\t\t<td>".$data->rcount."</td>\n";

		$alignDKIM  = number_format(100 * ($data->alignDKIM  / $data->numReport));
		$alignSPF   = number_format(100 * ($data->alignSPF   / $data->numReport));
		$DKIMpass   = number_format(100 * ($data->resultDKIM / $data->numReport));
		$SPFpass    = number_format(100 * ($data->resultSPF  / $data->numReport));
		$compliance = number_format(100 * ($data->compliance / $data->numReport));

		echo "\t\t<td>".$data->policyPct."% ".$data->policy."</td>\n";

		echo "\t\t<td>\n";
		echo "\t\t\t<div class='perc-text'><span>$compliance% Compliant</span></div>\n";
		echo "\t\t\t<div class='perc-bar'>\n";
		echo "\t\t\t\t<div class='green-per' style='width:$compliance%'></div>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t</td>\n";

		echo "\t\t<td>\n";
		echo "\t\t\t<div class='perc-text'>\n";
		echo "\t\t\t\t<span>$alignDKIM% Aligned | $DKIMpass% Passed</span>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t\t<div class='perc-bar'>\n";
		echo "\t\t\t\t<div class='gray-per' style='width:$DKIMpass%'></div>\n";
		echo "\t\t\t\t<div class='green-per' style='width:$alignDKIM%'></div>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t</td>\n";

		echo "\t\t<td>\n";
		echo "\t\t\t<div class='perc-text'>\n";
		echo "\t\t\t\t<span>$alignSPF% Aligned | $SPFpass% Passed</span>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t\t<div class='perc-bar'>\n";
		echo "\t\t\t\t<div class='gray-per' style='width:$SPFpass%'></div>\n";
		echo "\t\t\t\t<div class='green-per' style='width:$alignSPF%'></div>\n";
		echo "\t\t\t</div>\n";
		echo "\t\t</td>\n";
	}

	echo "</table>";
}

// Misc Functions //
function format_date($date) {
	return date("Y-m-d H:i:s",$date);
}

function date_range($dateRange = DATE_RANGE) {
	debug("Date Range: $dateRange");
	if ($dateRange == DATE_RANGE) { return $dateRange; }
	preg_match('/(\d+)(\w+)/', $dateRange, $match);
	$range = "-$match[1] ";

	// work out if it's week, month, or day
	if ($match[2] == 'w') { $range .= 'week'; }
	if ($match[2] == 'm') { $range .= 'month'; }
	if ($match[2] == 'y') { $range .= 'year'; }

	debug("Date Range Formatted: $range");

	return $range;
}

function start_date($dateRange = DATE_RANGE) {
	return format_date(strtotime(date_range($dateRange)));
}


?>
