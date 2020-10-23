<?php 
require_once('./KalturaClient/KalturaClient.php');

function getMetadataSimpleTemplate($client, $id)
{
	// https://developer.kaltura.com/console/service/metadataProfile/action/serve
	$metadataPlugin = KalturaMetadataClientPlugin::get($client);
	$schemaUrl = $metadataPlugin->metadataProfile->serve($id); //returns a URL

	$schemaXSDFile = file_get_contents($schemaUrl); //download the XSD file from Kaltura

	// Build a <metadata> template:
	$schema = new DOMDocument();
	$schemaXSDFile = mb_convert_encoding($schemaXSDFile, 'utf-8', mb_detect_encoding($schemaXSDFile));
	$schema->loadXML($schemaXSDFile); //load and parse the XSD as an XML
	$xpath = new DOMXPath($schema);
	$xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

	$metadataTemplaeStr = '';
	$elementDefs = $xpath->evaluate("/xs:schema/xs:element");
	foreach($elementDefs as $elementDef) {
	  $metadataTemplaeStr .= iterateElements($metadataTemplaeStr, $elementDef, $schema, $xpath);
	} 
	$metadataTemplaeStr .= '</metadata>';
	$metadataTemplaeSimpleXml = simplexml_load_string($metadataTemplaeStr);
	return $metadataTemplaeSimpleXml;
}

function iterateElements($xmlStr, $elementDef, $doc, $xpath) {
	$key = trim($elementDef->getAttribute('name'));
	$xmlStr = '<'.$key.'>';
	$elementDefs = $xpath->evaluate("xs:complexType/xs:sequence/xs:element", $elementDef);
	foreach($elementDefs as $elementDef) {
		$xmlStr .= iterateElements($xmlStr, $elementDef, $doc, $xpath);
		$key = trim($elementDef->getAttribute('name'));
		$xmlStr .= '</'.$key.'>';
	}
	return $xmlStr;
}

function metadataUpsert($client, $metadataProfileId, $objectType, $objectId, $metadataSimpleXml)
{
	$metadataPlugin = KalturaMetadataClientPlugin::get($client);
	$filter = new KalturaMetadataFilter();
	$filter->objectIdEqual = $objectId;
	$filter->metadataProfileIdEqual = $metadataProfileId;
	$filter->metadataObjectTypeEqual = $objectType;

	// https://developer.kaltura.com/console/service/metadata/action/list
	$existingRecords = $metadataPlugin->metadata->listAction($filter)->objects;
	$savedMetadata = null;
	$metadataXmlString = $metadataSimpleXml->asXML();
	if (count($existingRecords) == 0) {
	  // add new record
	  // https://developer.kaltura.com/console/service/metadata/action/add
	  $savedMetadata = $metadataPlugin->metadata->add($metadataProfileId, $objectType, $objectId, $metadataXmlString);
	} else {
	  // update existing record
	  // https://developer.kaltura.com/console/service/metadata/action/update
	  $metadataRecordId = $existingRecords[0]->id;
	  $savedMetadata = $metadataPlugin->metadata->update($metadataRecordId, $metadataXmlString);
	}
	return $savedMetadata;
}