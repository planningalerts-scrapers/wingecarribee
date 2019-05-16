<?php
### Wingecarribee Shire Council scraper - ApplicationMaster
require_once 'vendor/autoload.php';
require_once 'vendor/openaustralia/scraperwiki/scraperwiki.php';

use PGuardiario\PGBrowser;
use Torann\DomParser\HtmlDom;

date_default_timezone_set('Australia/Sydney');

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

$url_base = "https://datracker.wsc.nsw.gov.au/Modules/applicationmaster/";
$da_page  = $url_base . "default.aspx?page=found&1=" .$period. "&4a=WLUA,82AReview,CDC,DA,Mods&6=F";
$comment_base = "mailto:mail@wsc.nsw.gov.au?subject=Development Application Enquiry: ";

# Agreed Terms
$browser = new PGBrowser();
$page = $browser->get($url_base);
$form = $page->form();
$form->set('ctl00$cphContent$ctl01$Button2', 'Agree');
$page = $form->submit();

// Get list of development applications
$page = $browser->get($da_page);
$dom = HtmlDom::fromString($page->html);

# By default, assume it is single page
$dataset  = $dom->find("tr[class=rgRow], tr[class=rgAltRow]");
$NumPages = count($dom->find('div[class=rgWrap rgNumPart] a'));
if ($NumPages === 0) { $NumPages = 1; }

for ($i = 1; $i <= $NumPages; $i++) {
    echo "Scraping page $i of $NumPages\n";

    # If more than a single page, fetch the page
    if ($NumPages > 1) {
        $doPostBack = $dom->find('div[class=rgWrap rgNumPart] a')[$i-1]->href;
        $form = $page->form();
        $page = $form->doPostBack($doPostBack);
        $dom = HtmlDom::fromString($page->html);
        $dataset  = $dom->find("tr[class=rgRow], tr[class=rgAltRow]");
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
        $addr    = preg_replace('/\s+/', ' ', $addr[0]);

        $desc    = trim(html_entity_decode($tempstr[1]));
        $desc    = preg_replace('/\s+/', ' ', $desc);

        $council_reference = trim(html_entity_decode($record->find('td',1)->plaintext));
        $council_reference = preg_replace('/\s+/', ' ', $council_reference);

        $info_url = $url_base . trim($record->find('a',0)->href);

        # Put all information in an array
        $application = [
            'council_reference' => $council_reference,
            'address'           => $addr,
            'description'       => $desc,
            'info_url'          => $info_url,
            'comment_url'       => $comment_base . $council_reference,
            'date_scraped'      => date('Y-m-d'),
            'date_received'     => $date_received
        ];

        print ("Saving record " . $application['council_reference'] . " - " .$application['address']. "\n");
//             print_r ($application);
        scraperwiki::save(array('council_reference'), $application);
    }
}

?>
