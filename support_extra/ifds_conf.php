<?php
	// IFDS configuration file format class.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	class IFDS_Conf
	{
		protected $ifds, $metadata, $sectionsobj, $sectionsmap, $mod;

		const OPTION_STATUS_DEFAULT = 0x00;
		const OPTION_STATUS_USE = 0x80;

		const OPTION_TYPE_MASK = 0x3F;
		const OPTION_TYPE_BOOL = 0;
		const OPTION_TYPE_INT = 1;
		const OPTION_TYPE_FLOAT = 2;
		const OPTION_TYPE_DOUBLE = 3;
		const OPTION_TYPE_STRING = 4;
		const OPTION_TYPE_BINARY = 5;
		const OPTION_TYPE_SECTION = 6;
		const OPTION_TYPE_MAX = 7;

		const OPTION_TYPE_MULTIPLE = 0x40;

		public function __construct()
		{
			$this->ifds = false;
		}

		public function __destruct()
		{
			$this->Close();
		}

		public function Create($pfcfilename, $options = array())
		{
			$this->Close();

			if (!class_exists("IFDS", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/ifds.php";

			$this->ifds = new IFDS();
			$result = $this->ifds->Create($pfcfilename, 1, 0, 0, "CONF");
			if (!$result["success"])  return $result;

			// Store metadata.
			$this->metadata = array(
				"app" => (isset($options["app"]) ? (string)$options["app"] : ""),
				"ver" => (isset($options["ver"]) ? (string)$options["ver"] : ""),
				"charset" => (isset($options["charset"]) ? (string)$options["charset"] : "utf-8")
			);

			$result = $this->ifds->CreateKeyValueMap("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->SetKeyValueMap($metadataobj, $this->metadata);
			if (!$result["success"])  return $result;

			// Create the sections object.
			$result = $this->ifds->CreateKeyIDMap("sections");
			if (!$result["success"])  return $result;

			$this->sectionsobj = $result["obj"];

			return array("success" => true);
		}

		public function Open($pfcfilename)
		{
			$this->Close();

			if (!class_exists("IFDS", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/ifds.php";

			$this->ifds = new IFDS();
			$result = $this->ifds->Open($pfcfilename, "CONF");
			if (!$result["success"])  return $result;

			if ($result["header"]["fmt_major_ver"] != 1)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Expected configuration format major version 1."), "errorcode" => "invalid_fmt_major_ver");

			// Get metadata.
			$result = $this->ifds->GetObjectByName("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->GetKeyValueMap($metadataobj);
			if (!$result["success"])  return $result;

			$this->metadata = $result["map"];

			if (!isset($this->metadata["app"]))  return array("success" => false, "error" => self::IFDSConfTranslate("Missing 'app' in metadata."), "errorcode" => "invalid_metadata");
			if (!isset($this->metadata["ver"]))  return array("success" => false, "error" => self::IFDSConfTranslate("Missing 'ver' in metadata."), "errorcode" => "invalid_metadata");
			if (!isset($this->metadata["charset"]))  return array("success" => false, "error" => self::IFDSConfTranslate("Missing 'charset' in metadata."), "errorcode" => "invalid_metadata");

			// Load the sections object.
			$result = $this->ifds->GetObjectByName("sections");
			if (!$result["success"])  return $result;

			$this->sectionsobj = $result["obj"];

			$result = $this->ifds->GetKeyValueMap($this->sectionsobj);
			if (!$result["success"])  return $result;

			$this->sectionsmap = $result["map"];

			return array("success" => true);
		}

		public function GetIFDS()
		{
			return $this->ifds;
		}

		public function Close()
		{
			if ($this->ifds !== false)
			{
				$this->Save(false);

				$this->ifds->Close();

				$this->ifds = false;
			}

			$this->metadata = array();
			$this->sectionsobj = false;
			$this->sectionsmap = array();
			$this->mod = false;
		}

		public function Save($flush = true)
		{
			if ($this->ifds === false)  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to save information.  File is not open."), "errorcode" => "file_not_open");

			if ($this->mod)
			{
				$result = $this->ifds->SetKeyValueMap($this->sectionsobj, $this->sectionsmap);
				if (!$result["success"])  return $result;

				$this->mod = false;
			}

			if ($flush)
			{
				$result = $this->ifds->FlushAll();
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		protected function WriteMetadata()
		{
			$result = $this->ifds->GetObjectByName("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->SetKeyValueMap($metadataobj, $this->metadata);

			return $result;
		}

		public function GetApp()
		{
			return $this->metadata["app"];
		}

		public function SetApp($app)
		{
			$this->metadata["app"] = $app;

			return $this->WriteMetadata();
		}

		public function GetVer()
		{
			return $this->metadata["ver"];
		}

		public function SetVer($ver)
		{
			$this->metadata["ver"] = $ver;

			return $this->WriteMetadata();
		}

		public function GetCharset()
		{
			return $this->metadata["charset"];
		}

		public function SetCharset($charset)
		{
			$this->metadata["charset"] = $charset;

			return $this->WriteMetadata();
		}

		public function GetSectionsMap()
		{
			return $this->sectionsmap;
		}

		public function CreateSection($name, $contextname)
		{
			if (isset($this->sectionsmap[$name]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to create section.  Name already exists."), "errorcode" => "name_already_exists");

			$result = $this->ifds->CreateKeyValueMap();
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			$this->sectionsmap[$name] = $obj->GetID();

			$this->mod = true;

			$result["options"] = array("" => $contextname);

			return $result;
		}

		public function DeleteSection($name)
		{
			if (!isset($this->sectionsmap[$name]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to delete section.  Section name does not exist."), "errorcode" => "name_not_found");

			$result = $this->ifds->GetObjectByID($this->sectionsmap[$name]);
			if (!$result["success"])  return $result;

			$result = $this->ifds->DeleteObject($result["obj"]);
			if (!$result["success"])  return $result;

			unset($this->sectionsmap[$name]);

			$this->mod = true;

			return $result;
		}

		public function RenameSection($oldname, $newname)
		{
			if (!isset($this->sectionsmap[$oldname]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to rename section.  Section name does not exist."), "errorcode" => "name_not_found");
			if (isset($this->sectionsmap[$newname]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to rename section.  Section name already exists."), "errorcode" => "name_already_exists");

			$this->sectionsmap[$newname] = $this->sectionsmap[$oldname];

			unset($this->sectionsmap[$oldname]);

			$this->mod = true;

			return array("success" => true);
		}

		public static function ExtractTypeData(&$val, $pos, $type, $multiple)
		{
			$vals = array();

			$y = strlen($val);

			if (!$multiple && $pos >= $y && ($type === self::OPTION_TYPE_STRING || $type === self::OPTION_TYPE_BINARY))  $vals[] = "";

			while ($pos < $y)
			{
				if (!$multiple)  $size = $y - $pos;
				else
				{
					if ($type === self::OPTION_TYPE_STRING || $type === self::OPTION_TYPE_BINARY || $type === self::OPTION_TYPE_SECTION)
					{
						if ($pos + 3 >= $y)  break;

						$size = unpack("N", substr($val, $pos, 4))[1];
						$pos += 4;
					}
					else
					{
						$size = ord($val[$pos]);
						$pos++;
					}
				}

				$val2 = substr($val, $pos, $size);

				switch ($type)
				{
					case self::OPTION_TYPE_BOOL:
					{
						$vals[] = ($val2 !== str_repeat("\x00", $size));

						break;
					}
					case self::OPTION_TYPE_INT:
					{
						// PHP unpack() doesn't appear to have an option for unpacking big endian signed integers.
						if ($size === 1)  $vals[] = unpack("c", $val2)[1];
						else if ($size === 2)  $vals[] = unpack("s", pack("s", unpack("n", $val2)[1]))[1];
						else if ($size === 4)  $vals[] = unpack("l", pack("l", unpack("N", $val2)[1]))[1];
						else if ($size === 8)  $vals[] = unpack("q", pack("q", unpack("J", $val2)[1]))[1];

						break;
					}
					case self::OPTION_TYPE_FLOAT:
					{
						$vals[] = unpack("G", $val2)[1];

						break;
					}
					case self::OPTION_TYPE_DOUBLE:
					{
						$vals[] = unpack("E", $val2)[1];

						break;
					}
					default:
					{
						$vals[] = $val2;

						break;
					}
				}

				$pos += $size;
			}

			return $vals;
		}

		public function GetSection($name)
		{
			if (!isset($this->sectionsmap[$name]))  return array("success" => false, "error" => self::IFDSConfTranslate("Section name does not exist."), "errorcode" => "name_not_found");

			$result = $this->ifds->GetObjectByID($this->sectionsmap[$name]);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			$result = $this->ifds->GetKeyValueMap($obj);
			if (!$result["success"])  return $result;

			$map = $result["map"];

			$options = array();
			foreach ($result["map"] as $key => $val)
			{
				if ($key === "")  $options[$key] = $val;
				else
				{
					if (strlen($val) < 1)  continue;

					$optinfo = ord($val[0]);

					$use = (bool)($optinfo & self::OPTION_STATUS_USE);
					$multiple = (bool)($optinfo & self::OPTION_TYPE_MULTIPLE);
					$type = ($optinfo & self::OPTION_TYPE_MASK);

					if ($type >= self::OPTION_TYPE_MAX)  $type = self::OPTION_TYPE_BINARY;

					$vals = self::ExtractTypeData($val, 1, $type, $multiple);

					$options[$key] = array("use" => $use, "type" => $type, "vals" => $vals);
				}
			}

			return array("success" => true, "obj" => $obj, "options" => &$options);
		}

		public static function AppendTypeData(&$val, $type, &$vals)
		{
			$multiple = (count($vals) > 1);

			foreach ($vals as $val2)
			{
				switch ($type)
				{
					case self::OPTION_TYPE_BOOL:
					{
						if ($multiple)  $val .= "\x01";

						$val .= ($val2 ? "\x01" : "\x00");

						break;
					}
					case self::OPTION_TYPE_INT:
					{
						if ($val2 >= -128 && $val2 <= 127)  $val .= ($multiple ? "\x01" : "") . chr($val2);
						else if ($val2 >= -32768 && $val2 <= 32767)  $val .= ($multiple ? "\x02" : "") . pack("n", $val2);
						else if ($val2 >= -2147483648 && $val2 <= 2147483647)  $val .= ($multiple ? "\x04" : "") . pack("N", $val2);
						else  $val .= ($multiple ? "\x08" : "") . pack("J", $val2);

						break;
					}
					case self::OPTION_TYPE_FLOAT:
					{
						$data = pack("G", $val2);

						if ($multiple)  $val .= chr(strlen($data));

						$val .= $data;

						break;
					}
					case self::OPTION_TYPE_DOUBLE:
					{
						$data = pack("E", $val2);

						if ($multiple)  $val .= chr(strlen($data));

						$val .= $data;

						break;
					}
					default:
					{
						if ($multiple)  $val .= pack("N", strlen($val2));

						$val .= $val2;

						break;
					}
				}
			}
		}

		public function UpdateSection($obj, $options)
		{
			$map = array();
			foreach ($options as $key => &$info)
			{
				if ($key === "")  $map[""] = $info;
				else
				{
					if (count($info["vals"]) < 1)  continue;

					$type = $info["type"];
					$multiple = (count($info["vals"]) > 1);

					if ($type >= self::OPTION_TYPE_MAX)  $type = self::OPTION_TYPE_BINARY;

					$val = chr(($info["use"] ? self::OPTION_STATUS_USE : self::OPTION_STATUS_DEFAULT) | ($multiple ? self::OPTION_TYPE_MULTIPLE : 0x00) | $type);

					self::AppendTypeData($val, $type, $info["vals"]);

					$map[$key] = $val;
				}
			}

			return $this->ifds->SetKeyValueMap($obj, $map);
		}

		public static function IFDSConfTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}

	class IFDS_ConfDef
	{
		protected $ifds, $metadata, $contextsobj, $contextsmap, $optionsobj, $docsobj, $mod;

		const OPTION_STATUS_DEFAULT = 0x00;
		const OPTION_STATUS_USE = 0x80;

		const OPTION_TYPE_MASK = 0x3F;
		const OPTION_TYPE_BOOL = 0;
		const OPTION_TYPE_INT = 1;
		const OPTION_TYPE_FLOAT = 2;
		const OPTION_TYPE_DOUBLE = 3;
		const OPTION_TYPE_STRING = 4;
		const OPTION_TYPE_BINARY = 5;
		const OPTION_TYPE_SECTION = 6;
		const OPTION_TYPE_MAX = 7;

		const OPTION_TYPE_MULTIPLE = 0x40;

		const OPTION_INFO_NORMAL = 0x00;
		const OPTION_INFO_DEPRECATED = 0x01;

		public function __construct()
		{
			$this->ifds = false;
		}

		public function __destruct()
		{
			$this->Close();
		}

		public function Create($pfcfilename, $options = array())
		{
			$this->Close();

			if (!class_exists("IFDS", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/ifds.php";

			$this->ifds = new IFDS();
			$result = $this->ifds->Create($pfcfilename, 1, 0, 0, "CONF-DEF");
			if (!$result["success"])  return $result;

			// Store metadata.
			$this->metadata = array(
				"app" => (isset($options["app"]) ? (string)$options["app"] : ""),
				"ver" => (isset($options["ver"]) ? (string)$options["ver"] : ""),
				"charset" => (isset($options["charset"]) ? (string)$options["charset"] : "utf-8")
			);

			$result = $this->ifds->CreateKeyValueMap("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->SetKeyValueMap($metadataobj, $this->metadata);
			if (!$result["success"])  return $result;

			// Create the contexts object.
			$result = $this->ifds->CreateKeyIDMap("contexts");
			if (!$result["success"])  return $result;

			$this->contextsobj = $result["obj"];

			// Create the options object.
			$result = $this->ifds->CreateLinkedList("options");
			if (!$result["success"])  return $result;

			$this->optionsobj = $result["obj"];

			// Create the docs object.
			$result = $this->ifds->CreateLinkedList("docs");
			if (!$result["success"])  return $result;

			$this->docsobj = $result["obj"];

			return array("success" => true);
		}

		public function Open($pfcfilename)
		{
			$this->Close();

			if (!class_exists("IFDS", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/ifds.php";

			$this->ifds = new IFDS();
			$result = $this->ifds->Open($pfcfilename, "CONF-DEF");
			if (!$result["success"])  return $result;

			if ($result["header"]["fmt_major_ver"] != 1)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Expected configuration definition format major version 1."), "errorcode" => "invalid_fmt_major_ver");

			// Get metadata.
			$result = $this->ifds->GetObjectByName("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->GetKeyValueMap($metadataobj);
			if (!$result["success"])  return $result;

			$this->metadata = $result["map"];

			if (!isset($this->metadata["app"]))  return array("success" => false, "error" => self::IFDSConfTranslate("Missing 'app' in metadata."), "errorcode" => "invalid_metadata");
			if (!isset($this->metadata["ver"]))  return array("success" => false, "error" => self::IFDSConfTranslate("Missing 'ver' in metadata."), "errorcode" => "invalid_metadata");
			if (!isset($this->metadata["charset"]))  return array("success" => false, "error" => self::IFDSConfTranslate("Missing 'charset' in metadata."), "errorcode" => "invalid_metadata");

			// Load the contexts object.
			$result = $this->ifds->GetObjectByName("contexts");
			if (!$result["success"])  return $result;

			$this->contextsobj = $result["obj"];

			$result = $this->ifds->GetKeyValueMap($this->contextsobj);
			if (!$result["success"])  return $result;

			$this->contextsmap = $result["map"];

			// Load options object.
			$result = $this->ifds->GetObjectByName("options");
			if (!$result["success"])  return $result;

			$this->optionsobj = $result["obj"];

			// Load docs object.
			$result = $this->ifds->GetObjectByName("docs");
			if (!$result["success"])  return $result;

			$this->docsobj = $result["obj"];

			return array("success" => true);
		}

		public function GetIFDS()
		{
			return $this->ifds;
		}

		public function Close()
		{
			if ($this->ifds !== false)
			{
				$this->Save(false);

				$this->ifds->Close();

				$this->ifds = false;
			}

			$this->metadata = array();
			$this->contextsobj = false;
			$this->contextsmap = array();
			$this->optionsobj = false;
			$this->docsobj = false;
			$this->mod = false;
		}

		public function Save($flush = true)
		{
			if ($this->ifds === false)  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to save information.  File is not open."), "errorcode" => "file_not_open");

			if ($this->mod)
			{
				$result = $this->ifds->SetKeyValueMap($this->contextsobj, $this->contextsmap);
				if (!$result["success"])  return $result;

				$this->mod = false;
			}

			if ($flush)
			{
				$result = $this->ifds->FlushAll();
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		protected function WriteMetadata()
		{
			$result = $this->ifds->GetObjectByName("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->SetKeyValueMap($metadataobj, $this->metadata);

			return $result;
		}

		public function GetApp()
		{
			return $this->metadata["app"];
		}

		public function SetApp($app)
		{
			$this->metadata["app"] = $app;

			return $this->WriteMetadata();
		}

		public function GetVer()
		{
			return $this->metadata["ver"];
		}

		public function SetVer($ver)
		{
			$this->metadata["ver"] = $ver;

			return $this->WriteMetadata();
		}

		public function GetCharset()
		{
			return $this->metadata["charset"];
		}

		public function SetCharset($charset)
		{
			$this->metadata["charset"] = $charset;

			return $this->WriteMetadata();
		}

		public function GetContextsMap()
		{
			return $this->contextsmap;
		}

		public function CreateContext($name)
		{
			if (isset($this->contextsmap[$name]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to create context.  Context name already exists."), "errorcode" => "name_already_exists");

			$result = $this->ifds->CreateKeyIDMap();
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			$this->contextsmap[$name] = $obj->GetID();

			$this->mod = true;

			$result["options"] = array("" => 0);

			return $result;
		}

		public function DeleteContext($name)
		{
			if (!isset($this->contextsmap[$name]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to delete context.  Context name does not exist."), "errorcode" => "name_not_found");

			$result = $this->ifds->GetObjectByID($this->contextsmap[$name]);
			if (!$result["success"])  return $result;

			$result = $this->ifds->DeleteObject($result["obj"]);
			if (!$result["success"])  return $result;

			unset($this->contextsmap[$name]);

			$this->mod = true;

			return $result;
		}

		public function RenameContext($oldname, $newname)
		{
			if (!isset($this->contextsmap[$oldname]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to rename context.  Context name does not exist."), "errorcode" => "name_not_found");
			if (isset($this->contextsmap[$newname]))  return array("success" => false, "error" => self::IFDSConfTranslate("Unable to rename context.  Context name already exists."), "errorcode" => "name_already_exists");

			$this->contextsmap[$newname] = $this->contextsmap[$oldname];

			unset($this->contextsmap[$oldname]);

			$this->mod = true;

			return array("success" => true);
		}

		public function GetContext($name)
		{
			if (!isset($this->contextsmap[$name]))  return array("success" => false, "error" => self::IFDSConfTranslate("Context name does not exist."), "errorcode" => "name_not_found");

			$result = $this->ifds->GetObjectByID($this->contextsmap[$name]);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			if ($obj->GetEncoder() !== IFDS::ENCODER_KEY_ID_MAP)  return array("success" => false, "error" => self::IFDSConfTranslate("Context is not a key-ID map."), "errorcode" => "invalid_data_method");

			$result = $this->ifds->GetKeyValueMap($obj);
			if (!$result["success"])  return $result;

			return array("success" => true, "obj" => $obj, "options" => $result["map"]);
		}

		public function UpdateContext($obj, $optionsmap)
		{
			return $this->ifds->SetKeyValueMap($obj, $optionsmap);
		}

		public function GetOptionsList()
		{
			return $this->optionsobj;
		}

		public function CreateOption($type, $options = array())
		{
			$result = $this->ifds->CreateLinkedListNode(IFDS::ENCODER_RAW);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			$result2 = $this->ifds->AttachLinkedListNode($this->optionsobj, $obj);
			if (!$result2["success"])  return $result2;

			$result2 = $this->UpdateOption($obj, $type, $options);
			if (!$result2["success"])  return $result2;

			return $result;
		}

		public function DeleteOption($obj)
		{
			return $this->ifds->DeleteLinkedListNode($this->optionsobj, $obj);
		}

		public function UpdateOption($obj, $type, $options = array())
		{
			if ($type >= self::OPTION_TYPE_MAX)  $type = self::OPTION_TYPE_BINARY;

			if (!isset($options["defaults"]))  $options["defaults"] = array();

			$multiple = (count($options["defaults"]) > 1);

			$data = chr(isset($options["info"]) ? (int)$options["info"] : self::OPTION_INFO_NORMAL);
			$data .= chr(($multiple ? self::OPTION_TYPE_MULTIPLE : 0x00) | $type);
			$data .= pack("N", (isset($options["doc"]) ? (int)$options["doc"] : 0));
			$data .= pack("N", (isset($options["values"]) ? (int)$options["values"] : 0));

			if (!isset($options["mimetype"]))  $options["mimetype"] = ($type === self::OPTION_TYPE_BINARY ? "application/octet-stream" : "");

			$data .= pack("n", strlen($options["mimetype"]));
			$data .= $options["mimetype"];

			IFDS_Conf::AppendTypeData($data, $type, $options["defaults"]);

			$result = $this->ifds->Truncate($obj);
			if (!$result["success"])  return $result;

			return $this->ifds->WriteData($obj, $data);
		}

		public static function ExtractOptionData(&$data)
		{
			if (strlen($data) < 12)  return false;

			$options = array();
			$options["info"] = ord($data[0]);
			$options["type"] = (ord($data[1]) & self::OPTION_TYPE_MASK);
			$multiple = (ord($data[1]) & self::OPTION_TYPE_MULTIPLE);
			$options["doc"] = unpack("N", substr($data, 2, 4))[1];
			$options["values"] = unpack("N", substr($data, 6, 4))[1];

			$size = unpack("n", substr($data, 10, 2))[1];
			$options["mimetype"] = substr($data, 12, $size);

			$options["defaults"] = IFDS_Conf::ExtractTypeData($data, 12 + $size, $options["type"], $multiple);

			return $options;
		}

		public function GetOption($id)
		{
			$result = $this->ifds->GetObjectByID($id);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			if ($obj->GetEncoder() !== IFDS::ENCODER_RAW)  return array("success" => false, "error" => self::IFDSConfTranslate("Option is not raw data."), "errorcode" => "invalid_data_method");

			$result = $this->ifds->ReadData($obj);
			if (!$result["success"])  return $result;

			$options = self::ExtractOptionData($result["data"]);
			if ($options === false)  return array("success" => false, "error" => self::IFDSConfTranslate("Failed to extract option data."), "errorcode" => "extract_option_data_failed");

			return array("success" => true, "obj" => $obj, "options" => &$options);
		}

		public function CreateOptionValues($valuesmap)
		{
			$result = $this->ifds->CreateKeyIDMap();
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			$result2 = $this->ifds->SetKeyValueMap($obj, $valuesmap);
			if (!$result2["success"])  return $result2;

			return $result;
		}

		public function DeleteOptionValues($obj)
		{
			return $this->ifds->DeleteObject($obj);
		}

		public function UpdateOptionValues($obj, $valuesmap)
		{
			return $this->ifds->SetKeyValueMap($obj, $valuesmap);
		}

		public function GetOptionValues($id)
		{
			$result = $this->ifds->GetObjectByID($id);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			if ($obj->GetEncoder() !== IFDS::ENCODER_KEY_ID_MAP)  return array("success" => false, "error" => self::IFDSConfTranslate("Option values are not a key-ID map."), "errorcode" => "invalid_data_method");

			$result = $this->ifds->GetKeyValueMap($obj);
			if (!$result["success"])  return $result;

			return array("success" => true, "obj" => $obj, "values" => $result["map"]);
		}

		public function GetDocsList()
		{
			return $this->docsobj;
		}

		public function CreateDoc($langmap)
		{
			$result = $this->ifds->CreateLinkedListNode(IFDS::ENCODER_KEY_VALUE_MAP);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			$result2 = $this->ifds->AttachLinkedListNode($this->docsobj, $obj);
			if (!$result2["success"])  return $result2;

			$result2 = $this->ifds->SetKeyValueMap($obj, $langmap);
			if (!$result2["success"])  return $result2;

			return $result;
		}

		public function DeleteDoc($obj)
		{
			return $this->ifds->DeleteLinkedListNode($this->docsobj, $obj);
		}

		public function UpdateDoc($obj, $langmap)
		{
			return $this->ifds->SetKeyValueMap($obj, $langmap);
		}

		public function GetDoc($id)
		{
			$result = $this->ifds->GetObjectByID($id);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			if ($obj->GetEncoder() !== IFDS::ENCODER_KEY_VALUE_MAP)  return array("success" => false, "error" => self::IFDSConfTranslate("Documentation object is not a key-value map."), "errorcode" => "invalid_data_method");

			$result = $this->ifds->GetKeyValueMap($obj);
			if (!$result["success"])  return $result;

			return array("success" => true, "obj" => $obj, "langmap" => $result["map"]);
		}

		public static function IFDSConfTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>