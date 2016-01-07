<?php
/**
 * Alma ILS Driver
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2015.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Luke O'Sullivan <l.osullivan@swansea.ac.uk>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
namespace VuFind\ILS\Driver;
use File_MARC, PDO, PDOException, Exception,
    VuFind\Exception\ILS as ILSException,
    VuFindSearch\Backend\Exception\HttpErrorException,
    Zend\Json\Json,
    Zend\Http\Client,
    Zend\Http\Request,
    SimpleXMLElement,
    VuFind\Exception\RecordMissing as RecordMissingException, 
    VuFind\SimpleXML;


/**
 * Alma ILS Driver.
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Luke O'Sullivan <l.osullivan@swansea.ac.uk>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Simon Barron <s.barron@imperial.ac.uk>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
class Alma extends AbstractBase implements \VuFindHttp\HttpServiceAwareInterface
{
    /**
     * HTTP service
     *
     * @var \VuFindHttp\HttpServiceInterface
     */
    protected $httpService = null;

    /**
     * Set the HTTP service to be used for HTTP requests.
     *
     * @param HttpServiceInterface $service HTTP service
     *
     * @return void
     */
    public function setHttpService(\VuFindHttp\HttpServiceInterface $service)
    {
        $this->httpService = $service;
    }

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     * 
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        if (empty($this->config)) {
            throw new ILSException('Configuration needs to be set.');
        }
    }

    /**
     * Function required for retrieving from Alma API
     *
     * @author Chris Keene
     * This function is by Chris Keene from 
     * http://work.nostuff.org/alma-real-time-holdings-availability-for-vufind/
     *
     */
    protected function curlMultiRequest($urls) {
       $ch = array();
       $results = array();
       $mh = curl_multi_init();
       foreach($urls as $key => $val) {
          $ch[$key] = curl_init();
          curl_setopt($ch[$key],CURLOPT_RETURNTRANSFER,1);
          curl_setopt($ch[$key], CURLOPT_URL, $val);
          //curl_setopt ($ch[$key], CURLOPT_FAILONERROR,1);
          // follow line enables https
          curl_setopt($ch[$key], CURLOPT_SSL_VERIFYPEER, false);
          curl_multi_add_handle($mh, $ch[$key]);
       }
       $running = null;
       do {
          $status = curl_multi_exec($mh, $running);
          $info = curl_multi_info_read($mh);
          if ( $info['result'] != 0 ) {
             //print_r($info);
          }
       }
       while ($running > 0);
       // Get content and remove handles.
       foreach ($ch as $key => $val) {
          $results[$key] = curl_multi_getcontent($val);
          curl_multi_remove_handle($mh, $val);
       }
       curl_multi_close($mh);
       return $results;
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @author Chris Keene
     * This function is by Chris Keene from
     * http://work.nostuff.org/alma-real-time-holdings-availability-for-vufind/
     *     
     * @author Simon Barron
     * Adapted by Simon Barron <s.barron@imperial.ac.uk>
     *
     * @param string $id     The record id to retrieve the holdings for
     * @param array  $patron Patron data
     *
     * @throws \VuFind\Exception\ILS
     * @return array         On success, an associative array with the following
     * keys: id, availability (boolean), status, location, reserve, callnumber,
     * duedate, number, barcode.
     */
    public function getHolding($id, array $patron = null)
    {
       $mmsid = $id;
       $apikey = $this->config['Alma']['apikey'];
       $baseurl = $this->config['Alma']['baseurl'].'/almaws/v1/bibs/';
       $count=0;
       $holdinglist = array(); // for holding the holdings
       $items = array(); // what we will return
       $urllist = array();
       // prepare loans url
       $paramurl = '?apikey=' . $apikey;
       $urllist['loans'] = $baseurl . urlencode($mmsid) . '/loans' . $paramurl;
       // prepare holdings url
       $suffixurl = '/holdings?apikey=' . $apikey;
       $urllist['holdings'] = $baseurl . urlencode($mmsid) . $suffixurl;
       // get loans and holdings from api
       // returns an array, keys are loans/holdings.
       // values are the xml responses
       $results2 = $this->curlMultiRequest($urllist);
       $loaninfo = array();
       // loans xml
       $record = new SimpleXMLElement($results2["loans"]);
       // process loans xml creating array with barcode as key
       $loans = array();
       // if no loans there will be no item_loan
       if ($record) {
          foreach ($record->item_loan as $itemloan) {
             $duedate = $itemloan->due_date;
             $barcode = $itemloan->item_barcode;
             $loans["$barcode"] = $duedate;
          }
       }
       // now get each holdings id number
       $record = new SimpleXMLElement($results2["holdings"]);
       $holdinglist = array();
       $datafields = $record->xpath('/holdings/holding/holding_id');
       foreach ($datafields as $hid) {
          $holdinglist[] = $hid;
       }
       // for each holdings, prepare urls
       // GET /almaws/v1/bibs/{mms_id}/holdings/{holding_id}/items
       $urllist = array();
       $paramurl = '/items?apikey=' . $apikey . '&limit=90';
       foreach ($holdinglist as $hid) {
          $holdingsurl = '/holdings/' . urlencode($hid);
          $urllist["$hid"] = $baseurl . urlencode($mmsid) . $holdingsurl . $paramurl;
       }
       // call each of the holdings urls at the same time
       $results3 = $this->curlMultiRequest($urllist);
       // for each holding id, get its items
       $items = array();
       foreach ($holdinglist as $hid) {
          //create xml object based on this holdings xml
          $record = new SimpleXMLElement($results3["$hid"]);
          $datafields = $record->xpath('/items/item');
          // for each item within this holding
          foreach ($datafields as $item) {
             $duedate = "";
             $barcode = (string) $item->item_data->barcode;
             $items["$count"] = array();
             $items["$count"]["id"] = $mmsid;
             $items["$count"]["barcode"] = $barcode;
             $items["$count"]["number"] = $count;
             $items["$count"]["item_id"] = $barcode;
             $items["$count"]["availability"] = (string) $item->item_data->base_status;
             if ($items["$count"]["availability"]) {
                $items["$count"]["status"] = "Available";
             } else {
                $items["$count"]["status"] = "Not Available";
             }
             $items["$count"]["location"] = $item->item_data->library['desc'] . " - " . (string) $item->item_data->location['desc'];
             $items["$count"]["callnumber"] = (string) $item->holding_data->call_number;
             $items["$count"]["reserve"] = "0";
             if (isset($loans["$barcode"])) {
                $duedate = (string) $loans["$barcode"];
             }
             if ($duedate) {
                // ISO 8601
                $items["$count"]["duedate"] = date('l jS F Y', strtotime($duedate));
             }
             $count++;
             }
        }
    return $items;
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @throws ILSException
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        $statuses = $this->getHolding($id);
        foreach ($statuses as $status) {
            $status['status']
                = ($status['availability'] == 1) ? 'available' : 'unavailable';
        }
        return $statuses;
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $ids The array of record ids to retrieve the status for
     *
     * @throws ILSException
     * @return array     An array of getStatus() return values on success.
     */
    public function getStatuses($ids)
    {
        $items = [];
        $count = 0;
        foreach ($ids as $id) {
            $items[$count] = $this->getStatus($id);
            $count++;
        }
        return $items;
    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @throws \VuFind\Exception\ILS
     * @return array     An array with the acquisitions data on success.
     */
    public function getPurchaseHistory($id)
    {
    }
}
