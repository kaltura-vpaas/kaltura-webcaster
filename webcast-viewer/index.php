<?php
date_default_timezone_set('America/New_York');
require_once('../KalturaClient/KalturaClient.php');
require_once('../AppSettings.php');

$userRole = 'adminRole'; // correleates to KMS roles: adminRole, viewerRole, unmoderatedAdminRole, privateOnlyRole
$webcastEntryId = $_GET['entryId'];
$myHostingAppName = 'myCustomWebcastApp'; // set this to a simple alpha-numeric string that represents your hosting application name (the app that embeds the webcast)
$myHostingAppDomain = 'my.customwebcast.com';

$config = new KalturaConfiguration();
$config->setServiceUrl('https://www.kaltura.com');
$client = new KalturaClient($config);
$ks = $client->generateSession(API_ADMIN_SECRET, UNIQUE_USER_ID, KalturaSessionType::ADMIN, PARTNER_ID, KS_EXPIRY);
$client->setKS($ks);

$liveStreamEntry = $client->liveStream->get($webcastEntryId);
$isLive = $client->liveStream->isLive($webcastEntryId, KalturaPlaybackProtocol::AUTO);
if ($isLive == false) {
    if (isset($liveStreamEntry->redirectEntryId) && $liveStreamEntry->redirectEntryId != '') 
        $webcastEntryId = $liveStreamEntry->redirectEntryId;
    elseif (isset($liveStreamEntry->recordedEntryId) && $liveStreamEntry->recordedEntryId != '')
        $webcastEntryId = $liveStreamEntry->recordedEntryId;
}

// Create the Kaltura Session and set it to the KalturaClient
$ksPrivileges = 'sview:'.$webcastEntryId.',restrictexplicitliveview:'.$webcastEntryId.',enableentitlement,appid:'.$myHostingAppName.'-$myHostingAppDomain,sessionkey:'.UNIQUE_USER_ID;
$ks = $client->generateSession(API_ADMIN_SECRET, UNIQUE_USER_ID, KalturaSessionType::USER, PARTNER_ID, KS_EXPIRY, $ksPrivileges);
$client->setKS($ks);
?>

<!DOCTYPE html>
<html>
  <head>
    <title>Kaltura Webcaster Viewer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- KMS has Bootstrap 2.3 -->
    <link href="https://stackpath.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet" integrity="sha384-4FeI0trTH/PCsLWrGCD1mScoFu9Jf2NdknFdFoJhXZFwsvzZ3Bo5sAh7+zL8Xgnd" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js" integrity="sha384-vOWIrgFbxIPzY09VArRHMsxned7WiY6hzIPtAIIeTFuii9y3Cr6HE6fcHXy5CFhc" crossorigin="anonymous"></script>
  </head>
  <body>
    <script type="text/javascript" src="https://cdnapisec.kaltura.com/p/<?php echo PARTNER_ID;?>/sp/<?php echo PARTNER_ID;?>00/embedIframeJs/uiconf_id/<?php echo PLAYER_UI_CONF;?>/partner_id/<?php echo PARTNER_ID;?>"></script>
    <style>
      #kaltura_player,
      #kaltura_player object,
      #kaltura_player embed,
      #kaltura_player iframe {
        background: #000;
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
      }
      .wrapper-video {
        overflow: hidden;
        position: relative;
        padding-top: 30px !important;
        padding-bottom: 56.25% !important;
        height: 0;
      }
    </style>
    <br/>
    <div class="container" style="height: auto !important;">
      <div class="row">
        <div class="span8">
          <div class="wrapper-video">
            <div id="kaltura_player">
            </div>
          </div>
        </div>
        <div class="span4" style="margin: 0">
          <div id = "qnaListHolder">
          </div>
        </div>
      </div>
    </div>

    <script>
	  kWidget.embed({
		"targetId": "kaltura_player",
		"wid": "_<?php echo PARTNER_ID;?>",
		"uiconf_id": <?php echo PLAYER_UI_CONF;?>,
		"flashvars":
			{
        "ks": "<?php echo $ks;?>",
				"applicationName": "<?php echo $myHostingAppName; ?>",
				"disableAlerts": "false",
				"externalInterfaceDisabled": "false",
        "IframeCustomPluginCss1": "custom.css", // this CSS hides the QNA button
				"autoPlay": "true",
				"dualScreen": {"plugin": "true"},
				"chapters": {"plugin": "true"},
				"sideBarContainer": {"plugin": "true"},
				"LeadWithHLSOnFlash": "true",
				"EmbedPlayer.LiveCuepoints": "true",
				"EmbedPlayer.EnableIpadNativeFullscreen": "true",
				"qna": {
					"plugin": "true",
					"moduleWidth": "200",
					"containerPosition": "right",
					"qnaPollingInterval": "10000",
					"onPage": "true",
					"userId": "<?php echo UNIQUE_USER_ID; ?>",
					"userRole": "<?php echo $userRole; ?>",
					"qnaTargetId": "qnaListHolder"
				},
				"webcastPolls": {"plugin": "true", "userId": "<?php echo UNIQUE_USER_ID; ?>", "userRole": "<?php echo $userRole; ?>"}
			},
		"entry_id": "<?php echo $webcastEntryId; ?>"
	  });
    </script>

  </body>
</html>
