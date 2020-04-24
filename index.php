<?php
date_default_timezone_set('America/New_York');
require_once('./KalturaClient/KalturaClient.php');
require_once('./AppSettings.php');

// Create Kaltura configuration and client
$config = new KalturaConfiguration();
$config->setServiceUrl('https://www.kaltura.com');
$client = new KalturaClient($config);

// use an Admin KS to create the Webcast Entry (needed for operations like listing of conversionProfiles)
$ks = $client->generateSession(API_ADMIN_SECRET, UNIQUE_USER_ID, KalturaSessionType::ADMIN, PARTNER_ID, KS_EXPIRY);
$client->setKS($ks);

// create the live webcast entry; see documentation here:
//   https://developer.kaltura.com/api-docs/General_Objects/Objects/KalturaLiveStreamEntry
//   https://developer.kaltura.com/console/service/liveStream/action/add
$webcastEntry = new KalturaLiveStreamEntry();
$webcastEntry->name = WEBCAST_NAME.' '.date("Y-m-d H:i");
$webcastEntry->description = WEBCAST_DESCRIPTION;
$webcastEntry->tags = 'test';
$webcastEntry->mediaType = KalturaMediaType::LIVE_STREAM_FLASH; //indicates rtmp/rtsp source broadcast
$webcastEntry->dvrStatus = KalturaDVRStatus::ENABLED; //enable or disalbe DVR
$webcastEntry->dvrWindow = 60; // how long should the DVR be, specified in minutes
$webcastEntry->recordStatus = KalturaRecordStatus::PER_SESSION; // recording per event, append all events to one recording, or disablbe recording
$webcastEntry->adminTags = "kms-webcast-event"; // for analytics tracking of source as webcast vs. source as regular live (don't change this value)
// enabling categories duplication on recorded entry creation
$webcastEntry->recordingOptions = new KalturaLiveEntryRecordingOptions();
$webcastEntry->recordingOptions->shouldCopyEntitlement = KalturaNullableBoolean::TRUE_VALUE; //copy user entitlement settings from Live to Recorded VOD entry
$webcastEntry->recordingOptions->shouldMakeHidden = true; //hide the VOD entry in KMS/KMC, only make it accessible via the Live entry
$webcastEntry->explicitLive = KalturaNullableBoolean::TRUE_VALUE; // should admins preview the stream BEFORE going live? To enable preview set this to true, if set to true only KS with restrictexplicitliveview privilege will be allowed to watch the stream before isLive flag is set to true. If set to false, isLive will be set automatically as broadcast from encoders begins
$webcastEntry->pushPublishEnabled = KalturaLivePublishStatus::DISABLED; // Only enable if Multicasting is setup

// get the availalbe live ingest conversion profiles;
$filter = new KalturaConversionProfileFilter();
$filter->typeEqual = KalturaConversionProfileType::LIVE_STREAM;
$liveConversionProfiles = $client->conversionProfile->listAction($filter);
// this will normally return 2: systemName=Default_Live is the Cloud Transcode, and systemName=Passthrough_Live is passthrough with no cloud transcoding. If you wish to set the entry to Passthrough, set the $webcastEntry->conversionProfileId to the respective id. For Cloud transcode leave don't override the default.

$webcastEntry = $client->liveStream->add($webcastEntry, KalturaSourceType::LIVE_STREAM);

// set the live stream webcast metadata
$metadataUtils = KalturaCustomMetadataUtils::get($client);
$metadataProfileId = $metadataUtils->metadataProfile->getProfileId(METADATA_PROFILE_SYS_NAME_KWEBCAST);
$metadataXml = $metadataUtils->metadataProfile->getMetadataSimpleTemplate($metadataProfileId);
$metadataXml->IsKwebcastEntry = 1; // always set to 1
$isSelfServed = true; // should we enable self-served broadcasting using webcam/audio without external encoder?
$metadataXml->IsSelfServe = $isSelfServed ? 1 : 0;
//$metadataXml->SlidesDocEntryId = ''; // if there are pre-uploaded slides entry, assign the entry id here
$metadataUtils->metadata->upsert($metadataProfileId, KalturaMetadataObjectType::ENTRY, $webcastEntry->id, $metadataXml);

// set the Event timing metadata (used to present "time to event start" in KMS)
$eventsMetadataProfileId = $metadataUtils->metadataProfile->getProfileId(METADATA_PROFILE_SYS_NAME_EVENTS);
$eventMetadataXml = $metadataUtils->metadataProfile->getMetadataSimpleTemplate($eventsMetadataProfileId);
$webcastStartTime = strtotime('+5 minutes');
$eventMetadataXml->StartTime = $webcastStartTime; // indicate when the webcast will begin
$webcastEndTime = strtotime('+1 hour');
$eventMetadataXml->EndTime = $webcastEndTime; // indicate when the webcast will end
$eventMetadataXml->Timezone = 'America/New_York'; // valid PHP timezone - https://www.php.net/manual/en/timezones.php

// Set the metadata regarding the presenter
$eventMetadataXml->Presenter->PresenterId    = UNIQUE_USER_ID;
$eventMetadataXml->Presenter->PresenterName  = PRESENTER_NAME;
$eventMetadataXml->Presenter->PresenterTitle = PRESENTER_TITLE;
$eventMetadataXml->Presenter->PresenterBio   = PRESENTER_BIO;
$eventMetadataXml->Presenter->PresenterLink  = PRESENTER_LINK;
//$eventMetadataXml->Presenter->PresenterImage = ''; //Thumbnail Asset ID, on the webcast entry, that holds the speaker image
$metadataUtils->metadata->upsert($eventsMetadataProfileId, KalturaMetadataObjectType::ENTRY, $webcastEntry->id, $eventMetadataXml);

$filter = new KalturaConversionProfileFilter();
$filter->systemNameEqual = "KMS54_NEW_DOC_CONV_IMAGE_WIDE"; // get the ID of the document to images conversion profile
$presentationConversionProfile = $client->conversionProfile->listAction($filter)->objects[0];
$presentationConversionProfileId = $presentationConversionProfile->id; 

$hostname = getenv('HOSTNAME'); 
if(!$hostname) $hostname = trim(`hostname`); 
if(!$hostname) $hostname = exec('echo $HOSTNAME');
if(!$hostname) $hostname = preg_replace('#^\w+\s+(\w+).*$#', '$1', exec('uname -a')); 

// Create the Kaltura Session for webcast app
$expiresAt = time() + KS_EXPIRY;
$ks = $client->generateSession(API_ADMIN_SECRET, UNIQUE_USER_ID, KalturaSessionType::USER, PARTNER_ID, KS_EXPIRY, 'setrole:WEBCAST_PRODUCER_DEVICE_ROLE,sview:*,list:'.$webcastEntry->id.',download:'.$webcastEntry->id);
$client->setKS($ks);

$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$isMac = strpos(strtolower($userAgent), 'mac') !== false;
$filter = new KalturaUiConfFilter();
$filter->objTypeEqual = KalturaUiConfObjType::WEBCASTING;
$filter->nameLike = "WebcastingVersionInfo";
$filter->partner = 0;
$systemUiconf = $client->uiConf->listTemplates($filter);
$systemUiconf = json_decode($systemUiconf->objects[0]->config);
if (JSON_ERROR_NONE !== json_last_error()) {
    // Kwebcast: uiconf JSON is invalid. Reason: ' . json_last_error_msg()
}
$winUiconf = (WIN_UI_CONF_ID)? $client->uiConf->get(WIN_UI_CONF_ID): null;
$macUiconf = (MAC_UI_CONF_ID)? $client->uiConf->get(MAC_UI_CONF_ID): null;
$winConfig = json_decode($winUiconf->config);
if (JSON_ERROR_NONE !== json_last_error()) {
    // 'Kwebcast: uiconf JSON is invalid. Reason: ' . json_last_error_msg()
}
$macConfig = json_decode($macUiconf->config);
if (JSON_ERROR_NONE !== json_last_error()) {
    // 'Kwebcast: uiconf JSON is invalid. Reason: ' . json_last_error_msg()
}
if (!($winConfig->ignoreOptionalUpdates) && version_compare($systemUiconf->windows->recommendedVersion, $winUiconf->swfUrlVersion) > 0) {
    $winDownloadUrl = $systemUiconf->windows->recommendedVersionUrl;
} else {
    $winDownloadUrl = !empty($winConfig->recommendedVersionUrl) ? $winConfig->recommendedVersionUrl : $config->serviceUrl . '/kgeneric/ui_conf_id/' . WIN_UI_CONF_ID;
}
if (!($macConfig->ignoreOptionalUpdates) &&
    version_compare($systemUiconf->osx->recommendedVersion, $macUiconf->swfUrlVersion) > 0) {
    $macDownloadUrl = $systemUiconf->osx->recommendedVersionUrl;
} else {
    $macDownloadUrl = !empty($macConfig->recommendedVersionUrl) ? $macConfig->recommendedVersionUrl : $config->serviceUrl . '/kgeneric/ui_conf_id/' . MAC_UI_CONF_ID;
}

$s = $_SERVER;
$useForwardedHost = false;
$ssl      = ( ! empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
$sp       = strtolower( $s['SERVER_PROTOCOL'] );
$protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( ( $ssl ) ? 's' : '' );
$port     = $s['SERVER_PORT'];
$port     = ( ( ! $ssl && $port=='80' ) || ( $ssl && $port=='443' ) ) ? '' : ':'.$port;
$host     = ( $useForwardedHost && isset( $s['HTTP_X_FORWARDED_HOST'] ) ) ? $s['HTTP_X_FORWARDED_HOST'] : ( isset( $s['HTTP_HOST'] ) ? $s['HTTP_HOST'] : null );
$host     = isset( $host ) ? $host : $s['SERVER_NAME'] . $port;
$absoluteUrl = $protocol . '://' . $host . $s['REQUEST_URI'];
$logoUrl = $absoluteUrl."kaltura-logo.png";

$isQnAEnabled = true;
$isPollsEnabled = true;

// userRole correleates to KMS roles: adminRole, viewerRole, unmoderatedAdminRole, privateOnlyRole
$userRole = 'adminRole';

// Set myHostingAppName to a simple alpha-numeric string that represents the hosting application name (the app that launches the webcast)
$myHostingAppName = 'myCustomWebcastApp';

// This is the page where the webcast can be viewed.
$playbackPageUrl = $absoluteUrl.'/webcast-viewer/?entryId='.$webcastEntry->id;

// appHostUrl should point to your application's base URL - where live streams and recordings are embedded for end users;
// In embedded apps, such as KAF, it is set to '' (empty string).
$appHostUrl = '';

?>

<html>
  <head>
    <title>Kaltura Webcaster</title>
    <script type="text/javascript" src="KAppLauncher.js"></script>
    <script type="text/javascript" src="jquery.js"></script>
    <style>
      body {
	      font-family: 'robotolight', 'Open Sans', 'Helvetica', 'Arial', sans-serif;
	      text-align:   center;
	      font-weight:  300;
      }
      .launch {
	      font-size:       14px;
	      text-align:      center;
	      text-decoration: none;
	      display:         inline-block;
	      padding:         14px 0px 14px 0px;
	      margin-bottom:   10px;
	      width:           200px;
	      border-radius:   5px;
        background-color: #fc9003;
      	color:            white;
        -webkit-appearance: none;
      }
      .divider { color: #83C36D }
      h1 { font-weight: 300; }
      h2 { font-weight: 300; }
      h3 { font-weight: 300; }
      h4 { font-weight: 300; }
    </style>
  </head>
  <body>
    <img src="https://developer.kaltura.com/homepage/assets/images/Kaltura-logo.png"/>
    <h1>Webcaster</h1>
    <h4 class="divider">_______________</h4>
    <h3>Created Webcast Entry: <?php echo $webcastEntry->id; ?></h3>
    <h4>Entry can be found in the <a target="_blank" href="https://kmc.kaltura.com/index.php/kmcng/content/entries/list">KMC</a><h4>
    <h4 class="divider">_______________</h4>
    <h3>Prior to First Webcast</h3>
    <h4>Download Kaltura Webcast Studio</h4>
    <ul style="list-style-type:none">
      <li><a href="<?php echo $winDownloadUrl; ?>" target="_blank">Windows (Win 7 and up)</a></li>
      <li><a href="<?php echo $macDownloadUrl; ?>" target="_blank">macOS (10.7+)</a></li>
    </ul>
    <h4 class="divider">_______________</h4>
    <h3>Prepare and Launch Webcast</h3>
    <h4>via Kaltura Webcast Studio</h4>
    <button id="launchProducerApp" class="launch">launch</button>
    <h4 class="divider">_______________</h4>
    <h3><a target="_blank" id="launchPlayer">View Webcast</a></h3>

    <script>
      // Handle click on Launch button
      document.getElementById("launchProducerApp").onclick = launchKalturaWebcast;
 
      function launchKalturaWebcast() {
        var kapp = new KAppLauncher();

        var params = <?php
        echo json_encode(array(
            'ks' => $ks,
            'ks_expiry' => date('Y-m-d\TH:i:sP', $expiresAt),
            'MediaEntryId' => $webcastEntry->id,
            'uiConfID' => $isMac == 1 ? MAC_UI_CONF_ID : WIN_UI_CONF_ID,
            'serverAddress' => $config->serviceUrl,
            'eventsMetadataProfileId' => $eventsMetadataProfileId,
            'kwebcastMetadataProfileId' => $metadataProfileId,
            'appName' => APP_NAME,
            'logoUrl' => $logoUrl,
            'fromDate' => date('Y-m-d\TH:i:sP', $webcastStartTime),
            'toDate' => date('Y-m-d\TH:i:sP', $webcastEndTime),
            'userId' => UNIQUE_USER_ID,
            'QnAEnabled' => $isQnAEnabled,
            'pollsEnabled' => $isPollsEnabled,
            'userRole' => $userRole, 
            'playerUIConf' => PLAYER_UI_CONF,
            'presentationConversionProfileId' => $presentationConversionProfileId,
            'referer' => $hostname,
            'debuggingMode' => false,
            'verifySSL' => true,
            'selfServeEnabled' => $isSelfServed,
            'appHostUrl' => $appHostUrl,
            'instanceProfile' => $myHostingAppName
        ));
        ?>;
        
        kapp.startApp(params, function(isSupported, failReason) {
          if (!isSupported && failReason !== 'browserNotAware') {
            alert(res + " " + reason);
          } else {
            var playerLink = document.getElementById("launchPlayer");
            playerLink.href = "<?php echo $playbackPageUrl; ?>";
            playerLink.style.display = "inline";
          }
        }, 3000, true);
      }
    </script>
  </body>
</html>
