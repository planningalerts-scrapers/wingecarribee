<?php
### Wingecarribee Shire Council scraper - ApplicationMaster
require 'scraperwiki.php'; 
require 'simple_html_dom.php';
date_default_timezone_set('Australia/Sydney');

## Accept Terms and return Cookies
function accept_terms_get_cookies($terms_url, $button='Next', $postfields=array()) {
    $dom = file_get_html($terms_url);

    foreach ($dom->find('input[type=hidden]') as $data) {
        $postfields = array_merge($postfields, array($data->name => $data->value));
    }
    foreach ($dom->find("input[value=$button]") as $data) {
        $postfields = array_merge($postfields, array($data->name => $data->value));
    }

    $curl = curl_init($terms_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postfields);
    curl_setopt($curl, CURLOPT_HEADER, TRUE);
    $terms_response = curl_exec($curl);
    curl_close($curl);
    // get cookie
    // Please imporve it, I am not regex expert, this code changed ASP.NET_SessionId cookie
    // to ASP_NET_SessionId and Path, HttpOnly are missing etc
    // Example Source - Cookie: ASP.NET_SessionId=bz3jprrptbflxgzwes3mtse4; path=/; HttpOnly
    // Stored in array - ASP_NET_SessionId => bz3jprrptbflxgzwes3mtse4
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $terms_response, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }
    return $cookies;
}

### Collect all 'hidden' inputs, plus add the current $eventtarget
### $eventtarget is coming from the 'pages' section of the HTML
### This is for pagnation
function buildformdata($dom, $eventTarget, $eventArgument="") {
    $a = array();
    foreach ($dom->find("input[type=hidden]") as $input) {
        if ($input->value === FALSE) {
            $a = array_merge($a, array($input->name => ""));
        } else {
            $a = array_merge($a, array($input->name => $input->value));
        }
    }
    $a = array_merge($a, array('__EVENTTARGET' => $eventTarget));
    $a = array_merge($a, array('__EVENTARGUMENT' => $eventArgument));
    
    return $a;
}


$url_base = "http://datracking.wsc.nsw.gov.au/Modules/applicationmaster/";

    # Default to 'thisweek', use MORPH_PERIOD to change to 'thismonth' or 'lastmonth' for data recovery
    switch(getenv('MORPH_PERIOD')) {
        case 'thismonth' :
            $period = 'thismonth';
            break;
        case 'lastmonth' :
            $period = 'lastmonth';
            break;
        default         :
            $period = 'thisweek';
            break;
    }

$term_url = "http://datracking.wsc.nsw.gov.au/Modules/applicationmaster/Default.aspx";

$da_page  = $url_base . "default.aspx?page=found&1=" .$period. "&4a=WLUA&6=F";
$comment_base = "mailto:wscmail@wsc.nsw.gov.au?subject=Development Application Enquiry: ";
$user_agent = "User-Agent:Mozilla/5.0 (Windows NT 6.1; WOW64) PlanningAlerts.org.au";

$cookies = accept_terms_get_cookies($term_url, "Agree");

# Manually set cookie's key and get the value from array
$request = array(
    'http'    => array(
    'header'  => "Cookie: ASP.NET_SessionId=" .$cookies['ASP_NET_SessionId']. "; path=/; HttpOnly\r\n".
                 "$user_agent\r\n"
    ));
$context = stream_context_create($request);
$dom = file_get_html($da_page, false, $context);

# By default, assume it is single page
$dataset  = $dom->find("tr[class=rgRow], tr[class=rgAltRow]");
$NumPages = count($dom->find('div[class=rgWrap rgNumPart] a'));
if ($NumPages === 0) { $NumPages = 1; }

for ($i = 1; $i <= $NumPages; $i++) {
    # If more than a single page, fetch the page
    if ($NumPages > 1) {
        $eventtarget = substr($dom->find('div[class=rgWrap rgNumPart] a',$i-1)->href, 25, 61);
        $request = array(
            'http'    => array(
            'method'  => "POST",
            'header'  => "Cookie: ASP.NET_SessionId=" .$cookies['ASP_NET_SessionId']. "; path=/; HttpOnly\r\n" .
                         "Content-Type: application/x-www-form-urlencoded\r\n".
                         "$user_agent\r\n",
            'content' => http_build_query(buildformdata($dom, $eventtarget))));
        $context = stream_context_create($request);
        $html = file_get_html($da_page, false, $context);

        $dataset = $html->find("tr[class=rgRow], tr[class=rgAltRow]");
        echo "Scraping page $i of $NumPages\r\n";
    }

    # The usual, look for the data set and if needed, save it
    foreach ($dataset as $record) {
        # Slow way to transform the date but it works
        $date_received = explode(' ', trim($record->find('td',2)->plaintext));
        $date_received = "$date_received[2]-$date_received[1]-$date_received[0]";
        $date_received = date('Y-m-d', strtotime($date_received));

        # Prep a bit more, ready to add these to the array
        $tempstr = explode('<br/>', $record->find('td', 3)->innertext);
        $addr    = trim(html_entity_decode($tempstr[0]));
        $addr    = explode('<strong>', $addr);
        $addr    = explode('</strong>', $addr[1]);
        $addr    = preg_replace('/\s+/', ' ', $addr[0]) . ", Australia";
        
        $desc    = trim(html_entity_decode($tempstr[1]));
        $desc    = preg_replace('/\s+/', ' ', $desc);
        
        $council_reference = trim(html_entity_decode($record->find('td',1)->plaintext));
        $council_reference = preg_replace('/\s+/', ' ', $council_reference);
        
        $info_url = $url_base . trim($record->find('a',0)->href);

        # Put all information in an array
        $application = array (
            'council_reference' => $council_reference,
            'address'           => $addr,
            'description'       => $desc,
            'info_url'          => $info_url,
            'comment_url'       => $comment_base . $council_reference,
            'date_scraped'      => date('Y-m-d'),
            'date_received'     => $date_received
        );

        # Check if record exist, if not, INSERT, else do nothing
        $existingRecords = scraperwiki::select("* from data where `council_reference`='" . $application['council_reference'] . "'");
        if (count($existingRecords) == 0) {
            print ("Saving record " . $application['council_reference'] . "\n");
            # print_r ($application);
            scraperwiki::save(array('council_reference'), $application);
        } else {
            print ("Skipping already saved record " . $application['council_reference'] . "\n");
        }
    }
}

?>
