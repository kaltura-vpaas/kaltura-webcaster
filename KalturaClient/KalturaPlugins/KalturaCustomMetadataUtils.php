<?php
// ===================================================================================================
//                           _  __     _ _
//                          | |/ /__ _| | |_ _  _ _ _ __ _
//                          | ' </ _` | |  _| || | '_/ _` |
//                          |_|\_\__,_|_|\__|\_,_|_| \__,_|
//
// This file is part of the Kaltura Collaborative Media Suite which allows users
// to do with audio, video, and animation what Wiki platfroms allow them to do with
// text.
//
// Copyright (C) 2006-2019  Kaltura Inc.
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
// @ignore
// ===================================================================================================

/**
 * @package Kaltura
 * @subpackage Client
 */
require_once(dirname(__FILE__) . "/../KalturaClientBase.php");
require_once(dirname(__FILE__) . "/../KalturaEnums.php");
require_once(dirname(__FILE__) . "/../KalturaTypes.php");

/**
 * @package Kaltura
 * @subpackage Client
 */
class KalturaMetadataUtils extends KalturaMetadataService
{
	function __construct(KalturaClient $client = null)
	{
		parent::__construct($client);
	}

	/**
	 * Update (if metadata record already exists) or create a new metadata record (if not yet exists)
	 * 
	 * @param int $metadataProfileId
	 * @param string $objectType
	 * @param int $objectId
	 * @param SimpleXMLElement $metadataSimpleXml
	 * @return KalturaMetadata
	 */
	function upsert($metadataProfileId, $objectType, $objectId, $metadataSimpleXml)
	{
		$filter = new KalturaMetadataFilter();
		$filter->objectIdEqual = $objectId;
		$filter->metadataProfileIdEqual = $metadataProfileId;
		$filter->metadataObjectTypeEqual = $objectType;
		$existingRecords = $this->listAction($filter)->objects;
		$savedMetadata = null;
		$metadataXmlString = $metadataSimpleXml->asXML();
		if (count($existingRecords) == 0) {
		  //add new record
		  $savedMetadata = $this->add($metadataProfileId, $objectType, $objectId, $metadataXmlString);
		} else {
		  //update existing record
		  $metadataRecordId = $existingRecords[0]->id;
		  $savedMetadata = $this->update($metadataRecordId, $metadataXmlString);
		}
		return $savedMetadata;
	}
}

/**
 * @package Kaltura
 * @subpackage Client
 */
class KalturaMetadataProfileUtils extends KalturaMetadataProfileService
{
	function __construct(KalturaClient $client = null)
	{
		parent::__construct($client);
	}

	/**
	 * Retrieves the id of the metadata profile by its system name, assuming profile with that system name exists
	 * 
	 * @param int $profileSystemName 
	 * @return int
	 */
	function getProfileId($profileSystemName)
	{
		$filter = new KalturaMetadataProfileFilter();
  		$filter->systemNameEqual = $profileSystemName;
   		$id = $this->listAction($filter)->objects[0]->id;
   		return $id;
	}

	/**
	 * Retrieve a SimpleXMLElement template of the profile schema by the profile id
	 * 
	 * @param int $id 
	 * @return SimpleXMLElement
	 */
	function getMetadataSimpleTemplate($id)
	{
		//Get the schema XSD:
		$schemaUrl = $this->serve($id); //returns a URL
		$schemaXSDFile = file_get_contents($schemaUrl); //download the XSD file from Kaltura
		//Build a <metadata> template:
		$schema = new DOMDocument();
		$schemaXSDFile = mb_convert_encoding($schemaXSDFile, 'utf-8', mb_detect_encoding($schemaXSDFile));
		$schema->loadXML($schemaXSDFile); //load and parse the XSD as an XML
		$xpath = new DOMXPath($schema);
		$xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
		$metadataTemplaeStr = '';
		$elementDefs = $xpath->evaluate("/xs:schema/xs:element");
		foreach($elementDefs as $elementDef) {
		  $metadataTemplaeStr .= $this->iterateElements($metadataTemplaeStr, $elementDef, $schema, $xpath);
		} 
		$metadataTemplaeStr .= '</metadata>';
		$metadataTemplaeSimpleXml = simplexml_load_string($metadataTemplaeStr);
		return $metadataTemplaeSimpleXml;
	}

	/**
	 * Helper function to build a template xml from xsd schema (used by getMetadataSimpleTemplate)
	 */
	private function iterateElements($xmlStr, $elementDef, $doc, $xpath) {
	  $key = trim($elementDef->getAttribute('name'));
	  $xmlStr = '<'.$key.'>';
	  $elementDefs = $xpath->evaluate("xs:complexType/xs:sequence/xs:element", $elementDef);
	  foreach($elementDefs as $elementDef) {
	    $xmlStr .= $this->iterateElements($xmlStr, $elementDef, $doc, $xpath);
	    $key = trim($elementDef->getAttribute('name'));
	  	$xmlStr .= '</'.$key.'>';
	  }
	  return $xmlStr;
	}
}

/**
 * @package Kaltura
 * @subpackage Client
 */
class KalturaCustomMetadataUtils extends KalturaMetadataClientPlugin
{
	protected function __construct(KalturaClient $client)
	{
		parent::__construct($client);
		$this->metadata = new KalturaMetadataUtils($client);
		$this->metadataProfile = new KalturaMetadataProfileUtils($client);
	}

	/**
	 * @return KalturaCustomMetadataUtils
	 */
	public static function get(KalturaClient $client)
	{
		return new KalturaCustomMetadataUtils($client);
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return 'metadataUtils';
	}
}