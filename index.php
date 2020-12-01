<?php
date_default_timezone_set('America/New_York');
require_once('./KalturaClient/KalturaClient.php');
require_once('./AppSettings.php');
require_once('./Metadata.php');

// Get Kaltura client
$config = new KalturaConfiguration();
$config->setServiceUrl('https://www.kaltura.com');
$client = new KalturaClient($config);

// Use an Admin KS to create the live stream (webcast) entry (needed for operations like listing of conversion profiles)
$ks = $client->generateSession(API_ADMIN_SECRET, ADMIN_USER_ID, KalturaSessionType::ADMIN, PARTNER_ID, KS_EXPIRY);
$client->setKS($ks);

// Get the necessary conversion profiles

// Live ingest conversion profiles
$filter = new KalturaConversionProfileFilter();
$filter->systemNameEqual = "Default_Live"; // systemName=Default_Live is the Cloud Transcode, and systemName=Passthrough_Live
$liveConversionProfile = $client->conversionProfile->listAction($filter)->objects[0];

// Presentation (document to images) conversion profile
$filter = new KalturaConversionProfileFilter();
$filter->systemNameEqual = "KMS54_NEW_DOC_CONV_IMAGE_WIDE";
$presentationConversionProfile = $client->conversionProfile->listAction($filter)->objects[0];

// create the live stream entry; see documentation here:
// * https://developer.kaltura.com/api-docs/General_Objects/Objects/KalturaLiveStreamEntry
$liveStreamEntry = new KalturaLiveStreamEntry();
$liveStreamEntry->name = WEBCAST_NAME.' '.date("Y-m-d H:i");
$liveStreamEntry->description = WEBCAST_DESCRIPTION;
$liveStreamEntry->mediaType = KalturaMediaType::LIVE_STREAM_FLASH; //indicates rtmp/rtsp source broadcast
$liveStreamEntry->dvrStatus = KalturaDVRStatus::ENABLED; //enable or disable DVR
$liveStreamEntry->dvrWindow = 60; // how long should the DVR be, specified in minutes
$liveStreamEntry->sourceType = KalturaSourceType::LIVE_STREAM;
$liveStreamEntry->adminTags = "kms-webcast-event,vpaas-webcast"; // for analytics tracking of source as webcast vs. source as regular live (don't change this value)
$liveStreamEntry->pushPublishEnabled = KalturaLivePublishStatus::DISABLED;
$liveStreamEntry->conversionProfileId = $liveConversionProfile->id;
$liveStreamEntry->explicitLive = KalturaNullableBoolean::TRUE_VALUE;
$liveStreamEntry->entitledUsersEdit = PRESENTER_USER_ID; // give the presenter access to this live stream entry

$liveStreamEntry->recordStatus = KalturaRecordStatus::PER_SESSION;

// enabling categories duplication on recorded entry creation
$liveStreamEntry->recordingOptions = new KalturaLiveEntryRecordingOptions();
$liveStreamEntry->recordingOptions->shouldCopyEntitlement = KalturaNullableBoolean::TRUE_VALUE; // copy user entitlement settings from Live to Recorded VOD entry
$liveStreamEntry->recordingOptions->shouldMakeHidden = KalturaNullableBoolean::TRUE_VALUE; // hide the VOD entry in KMS/KMC, only make it accessible via the Live entry
$liveStreamEntry->recordingOptions->shouldAutoArchive = KalturaNullableBoolean::TRUE_VALUE;

// Add the live stream entry; see documentation here:
// * https://developer.kaltura.com/console/service/liveStream/action/add
$liveStreamEntry = $client->liveStream->add($liveStreamEntry, KalturaSourceType::LIVE_STREAM);

// Set the metadata to specify that the live stream entry is a container for a webcast.
// call metadata profile list action with a filter on the profile name
// * https://developer.kaltura.com/console/service/metadataProfile/action/list
$metadataService = KalturaMetadataClientPlugin::get($client);
$metadataFilter = new KalturaMetadataProfileFilter();
$metadataFilter->systemNameEqual = METADATA_PROFILE_SYS_NAME_KWEBCAST;
$metadataProfileId = $metadataService->metadataProfile->listAction($metadataFilter)->objects[0]->id;
$metadataXml = getMetadataSimpleTemplate($client, $metadataProfileId); // from Metadata.php
$metadataXml->IsKwebcastEntry = 1; // always set to 1
$metadataXml->IsSelfServe = 1; // should we enable self-served broadcasting using webcam/audio without external encoder?
//$metadataXml->SlidesDocEntryId = ''; // if there are pre-uploaded slides entry, assign the entry id here
metadataUpsert($client, $metadataProfileId, KalturaMetadataObjectType::ENTRY, $liveStreamEntry->id, $metadataXml); // from Metadata.php

// Set the Event timing metadata (used to present "time to event start" in KMS);
// * https://developer.kaltura.com/console/service/metadataProfile/action/list
$webcastStartTime = strtotime('+5 minutes');
$webcastEndTime = strtotime('+1 hour');
$metadataService = KalturaMetadataClientPlugin::get($client);
$metadataFilter = new KalturaMetadataProfileFilter();
$metadataFilter->systemNameEqual = METADATA_PROFILE_SYS_NAME_EVENTS;
$eventsMetadataProfileId = $metadataService->metadataProfile->listAction($metadataFilter)->objects[0]->id;
$eventMetadataXml = getMetadataSimpleTemplate($client, $eventsMetadataProfileId); // from Metadata.php
$eventMetadataXml->StartTime = $webcastStartTime; // indicate when the webcast will begin
$eventMetadataXml->EndTime = $webcastEndTime; // indicate when the webcast will end
$eventMetadataXml->Timezone = 'America/New_York'; // valid PHP timezone - https://www.php.net/manual/en/timezones.php
$eventMetadataXml->Presenter->PresenterId    = PRESENTER_USER_ID;
$eventMetadataXml->Presenter->PresenterName  = PRESENTER_NAME;
$eventMetadataXml->Presenter->PresenterTitle = PRESENTER_TITLE;
$eventMetadataXml->Presenter->PresenterBio   = PRESENTER_BIO;
$eventMetadataXml->Presenter->PresenterLink  = PRESENTER_LINK;
//$eventMetadataXml->Presenter->PresenterImage = ''; //Thumbnail Asset ID, on the webcast entry, that holds the speaker image

// See Metadata.php for metadataUpsert function
metadataUpsert($client, $eventsMetadataProfileId, KalturaMetadataObjectType::ENTRY, $liveStreamEntry->id, $eventMetadataXml);

// Create the Kaltura Session for webcast app
$expiresAt = time() + KS_EXPIRY;
$ks = $client->generateSession(API_ADMIN_SECRET, PRESENTER_USER_ID, KalturaSessionType::USER, PARTNER_ID, KS_EXPIRY, 'setrole:WEBCAST_PRODUCER_DEVICE_ROLE,sview:*,list:'.$liveStreamEntry->id.',download:'.$liveStreamEntry->id);
$client->setKS($ks);

// https://developer.kaltura.com/console/service/uiConf/action/listTemplates
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

// Get absolute URL
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

// This is the page where the webcast can be viewed.
$playbackPageUrl = $absoluteUrl.'/webcast-viewer/?entryId='.$liveStreamEntry->id;
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
    <img src="<?php echo $logoUrl; ?>" height="60"/>
    <h1>Webcaster</h1>
    <h4 class="divider">_______________</h4>
    <h3>Created Webcast Entry: <?php echo $liveStreamEntry->id; ?></h3>
    <h4>Entry can be found in the <a target="_blank" href="https://kmc.kaltura.com/index.php/kmcng/content/entries/list">KMC</a><h4>
    <h4>RTMP URL: <?php echo $liveStreamEntry->primaryBroadcastingUrl; ?></h4>
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
            'MediaEntryId' => $liveStreamEntry->id,
            'uiConfID' => $isMac == 1 ? MAC_UI_CONF_ID : WIN_UI_CONF_ID,
            'serverAddress' => $config->serviceUrl,
            'eventsMetadataProfileId' => $eventsMetadataProfileId,
            'kwebcastMetadataProfileId' => $metadataProfileId,
            'appName' => APP_NAME,
            'logoUrl' => $logoUrl,
            'fromDate' => date('Y-m-d\TH:i:sP', $webcastStartTime),
            'toDate' => date('Y-m-d\TH:i:sP', $webcastEndTime),
            'userId' => PRESENTER_USER_ID,
            'QnAEnabled' => QNA_ENABLED,
            'pollsEnabled' => POLLS_ENABLED,
            'userRole' => 'adminRole', // adminRole, viewerRole, unmoderatedAdminRole, privateOnlyRole 
            'presentationConversionProfileId' => $presentationConversionProfile->id,
            'referer' => APP_DOMAIN,
            'debuggingMode' => false,
            'verifySSL' => true,
            'selfServeEnabled' => true,
            'appHostUrl' => '', // in embedded apps, such as KAF, set to empty string
            'instanceProfile' => APP_NAME
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
