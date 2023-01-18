<?php
	// Incredibly Flexible Data Storage (IFDS) file format class.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	class IFDS
	{
		protected $open, $pfc, $fp, $vardata, $currpos, $streampos, $maxpos, $magic, $fileheader, $objcache, $objposmap, $namemap, $idmap, $nextid, $nextminid, $freemap;
		protected $estram, $maxram, $typeencoders, $typedecoders, $typedeleteverifiers;

		const FEATURES_NODE_IDS = 0x0001;
		const FEATURES_OBJECT_ID_STRUCT_SIZE = 0x0002;
		const FEATURES_OBJECT_ID_LAST_ACCESS = 0x0004;

		const TYPE_LEAF = 0x40;
		const TYPE_STREAMED = 0x80;

		const TYPE_BASE_MASK = 0x3F;
		const TYPE_DELETED = 0;
		const TYPE_RAW_DATA = 1;
		const TYPE_FIXED_ARRAY = 2;
		const TYPE_LINKED_LIST = 3;
		const TYPE_UNKNOWN_MIN = 4;

		const TYPE_DATA_CHUNKS = 0x3F;
		const DC_DATA = 0;
		const DC_DATA_TERM = 1;
		const DC_DATA_LOCATIONS = 2;

		const ENCODER_NONE = 0;
		const ENCODER_RAW = 1;
		const ENCODER_KEY_ID_MAP = 2;
		const ENCODER_KEY_VALUE_MAP = 3;

		const ENCODER_MASK_DATA_NUM = 0x3F;
		const ENCODER_MASK_DATA = 0xC0;
		const ENCODER_NO_DATA = 0x00;
		const ENCODER_INTERNAL_DATA = 0x40;
		const ENCODER_DATA_CHUNKS = 0x80;
		const ENCODER_DATA_CHUNKS_STREAM = 0xC0;

		const TYPE_STR_MAP = array(
			self::TYPE_RAW_DATA => "raw",
			self::TYPE_FIXED_ARRAY => "array",
			self::TYPE_LINKED_LIST => "list",
			self::TYPE_LINKED_LIST | self::TYPE_LEAF => "list_node"
		);

		public function __construct()
		{
			$this->magic = "IFDS";
			$this->maxram = 10000000;
			$this->typeencoders = array();
			$this->typedecoders = array();
			$this->typedeleteverifiers = array();

			$this->ResetInternal();

			$this->SetTypeEncoder(self::TYPE_FIXED_ARRAY, array($this, "FixedArrayTypeEncoder"));
			$this->SetTypeDecoder(self::TYPE_FIXED_ARRAY, array($this, "FixedArrayTypeDecoder"));

			$this->SetTypeEncoder(self::TYPE_LINKED_LIST, array($this, "LinkedListTypeEncoder"));
			$this->SetTypeDecoder(self::TYPE_LINKED_LIST, array($this, "LinkedListTypeDecoder"));
			$this->SetTypeDeleteVerifier(self::TYPE_LINKED_LIST, array($this, "LinkedListTypeCanDelete"));

			for ($x = self::TYPE_UNKNOWN_MIN; $x < self::TYPE_DATA_CHUNKS; $x++)
			{
				$this->SetTypeEncoder($x, array($this, "UnknownTypeEncoder"));
				$this->SetTypeDecoder($x, array($this, "UnknownTypeDecoder"));
			}
		}

		public function __destroy()
		{
			$this->Close();
		}

		public function GetMaxRAM()
		{
			return $this->maxram;
		}

		public function SetMaxRAM($maxram)
		{
			$this->maxram = $maxram;
		}

		public function GetEstimatedRAM()
		{
			return $this->estram;
		}

		public function SetMagic($magic)
		{
			$this->magic = $magic;
		}

		public function SetTypeEncoder($type, $encodercallback)
		{
			$this->typeencoders[$type] = $encodercallback;
		}

		public function SetTypeDecoder($type, $decodercallback)
		{
			$this->typedecoders[$type] = $decodercallback;
		}

		public function SetTypeDeleteVerifier($type, $deleteverifiercallback)
		{
			$this->typedeleteverifiers[$type] = $deleteverifiercallback;
		}

		protected function CreateObjectIDChunksTable()
		{
			if ($this->idmap === false)
			{
				// ID chunks table structure (Fixed array, 8 byte ID table entries position + 2 byte number of unassigned IDs).
				$result = $this->CreateFixedArray(10, false, false);
				if (!$result["success"])  return false;

				$this->idmap = $result["obj"];
				$this->idmap->data["man"] = true;
				$this->idmap->data["entries"] = array();
				$this->idmap->data["loaded"] = 0;
			}

			return true;
		}

		protected function RemoveObjectIDInternal($obj)
		{
			if ($obj->data["id"] > 0)
			{
				// Remove the ID from the object ID map.
				$id = $obj->data["id"];

				$id2 = $id - 1;
				$pagenum = (int)($id2 / 65536);
				$pageid = $id2 % 65536;

				if (isset($this->idmap->data["entries"][$pagenum]))
				{
					$result = $this->LoadObjectIDTableMap($pagenum);
					if ($result["success"])
					{
						$pageobj = $this->idmap->data["entries"][$pagenum];

						// Verify that the object's position matches to prevent object ID table shenanigans.
						if (isset($pageobj->data["entries"][$pageid]) && $pageobj->data["entries"][$pageid][0] === $obj->data["obj_pos"])
						{
							$pageobj->data["entries"][$pageid][0] = 0;
							$pageobj->data["entries"][$pageid][1] = 0;
							$pageobj->data["assigned"]--;

							$pageobj->data["data_mod"] = true;

							$this->idmap->data["data_mod"] = true;
						}
					}
				}

				// Assign a negative ID.
				unset($this->objcache[$id]);

				$obj->data["id"] = $this->nextminid;
				$this->nextminid--;

				$this->objcache[$obj->data["id"]] = &$obj->data;
				if ($obj->data["obj_pos"] > 0)  $this->objposmap[$obj->data["obj_pos"]] = $obj->data["id"];

				$obj->data["mod"] = true;
			}
		}

		protected function LoadObjectIDTableChunksMap($create)
		{
			if ($this->idmap !== false)  return true;

			if ($this->fileheader === false || $this->fileheader["id_map_pos"] < $this->fileheader["size"])
			{
				if ($create)  return $this->CreateObjectIDChunksTable();

				return false;
			}
			else
			{
				// Load the object.
				$result = $this->GetObjectByPosition($this->fileheader["id_map_pos"]);
				if (!$result["success"] || $result["obj"]->data["type"] !== self::TYPE_FIXED_ARRAY || $result["obj"]->data["info"]["size"] != 10)
				{
					// There's a problem with the object or the header.
					$this->fileheader["id_map_pos"] = 0;

					if ($create)  return $this->CreateObjectIDChunksTable();

					return false;
				}

				// Seek to the beginning.
				$result2 = $this->Seek($result["obj"], 0);
				if (!$result2["success"])
				{
					// There's a problem with the data for the object.
					$this->fileheader["id_map_pos"] = 0;

					if ($create)  return $this->CreateObjectIDChunksTable();

					return false;
				}

				// Read and extract the data.
				$result2 = $this->ReadData($result["obj"], 10 * 65536);
				if (!$result2["success"])
				{
					// There's a problem with the data for the object.
					$this->fileheader["id_map_pos"] = 0;

					if ($create)  return $this->CreateObjectIDChunksTable();

					return false;
				}

				$this->idmap = $result["obj"];
				$this->idmap->data["man"] = true;
				$this->idmap->data["entries"] = array();
				$this->idmap->data["loaded"] = 0;

				$y = strlen($result2["data"]);
				if ($y > 10 * 65536)  $y = 10 * 65536;

				for ($x = 0; $x + 9 < $y; $x += 10)
				{
					$pos = unpack("J", substr($result2["data"], $x, 8))[1];
					$unassigned = unpack("n", substr($result2["data"], $x + 8, 2))[1];

					$this->idmap->data["entries"][] = array("file_pos" => $pos, "unassigned" => $unassigned);
				}

				// Prevent weird circular logic issues.  Root ID map should not have an ID.
				if ($this->idmap->data["id"] > 0)  $this->RemoveObjectIDInternal($this->idmap);

				// Truncate the data and reset the encoding to internal object data if it is currently streaming.  Root ID map should never be streaming.
				if (($this->idmap->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
				{
					$result = $this->Truncate($this->idmap);
					if (!$result["success"])  return false;
				}
			}

			return true;
		}

		protected function CreateObjectIDTable($chunknum)
		{
			// ID table entries structure (Fixed array, 8 byte object position + 2 byte structure size + 2 byte last access day).
			$result = $this->CreateFixedArray(12, false, false);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];
			$obj->data["man"] = true;
			$obj->data["entries"] = array();
			$obj->data["assigned"] = 0;

			$this->idmap->data["entries"][$chunknum] = $obj;
			$this->idmap->data["data_mod"] = true;

			$this->idmap->data["loaded"]++;

			return $result;
		}

		protected function LoadObjectIDTableMap($chunknum)
		{
			// Create the object.
			if (!isset($this->idmap->data["entries"][$chunknum]))  return $this->CreateObjectIDTable($chunknum);

			if (is_array($this->idmap->data["entries"][$chunknum]))
			{
				$hasstructsize = ($this->fileheader["ifds_features"] & self::FEATURES_OBJECT_ID_STRUCT_SIZE);
				$haslastaccess = ($this->fileheader["ifds_features"] & self::FEATURES_OBJECT_ID_LAST_ACCESS);
				$entrysize = ($hasstructsize ? 2 : 0) + ($haslastaccess ? 2 : 0);

				// Load the object.
				$result = $this->GetObjectByPosition($this->idmap->data["entries"][$chunknum]["file_pos"]);
				if (!$result["success"] || $result["obj"]->data["type"] !== self::TYPE_FIXED_ARRAY)  return $this->CreateObjectIDTable($chunknum);

				if ($result["obj"]->data["info"]["size"] == 2 + $entrysize)
				{
					$poschr = "n";
					$possize = 2;
					$entrysize += 2;
				}
				else if ($result["obj"]->data["info"]["size"] == 4 + $entrysize)
				{
					$poschr = "N";
					$possize = 4;
					$entrysize += 4;
				}
				else if ($result["obj"]->data["info"]["size"] == 8 + $entrysize)
				{
					$poschr = "J";
					$possize = 8;
					$entrysize += 8;
				}
				else
				{
					return $this->CreateObjectIDTable($chunknum);
				}

				// Seek to the beginning.
				$obj = $result["obj"];
				$result = $this->Seek($obj, 0);
				if (!$result["success"])  return $this->CreateObjectIDTable($chunknum);

				// Read and extract the data.
				$result = $this->ReadData($obj, $entrysize * 65536);
				if (!$result["success"])  return $this->CreateObjectIDTable($chunknum);

				$this->idmap->data["entries"][$chunknum] = $obj;

				$obj->data["man"] = true;
				$obj->data["entries"] = array();
				$obj->data["assigned"] = 0;

				$y = strlen($result["data"]);
				if ($y > $entrysize * 65536)  $y = $entrysize * 65536;

				for ($x = 0; $x + $entrysize - 1 < $y; $x += $entrysize)
				{
					$pos = unpack($poschr, substr($result["data"], $x, $possize))[1];
					$size = ($hasstructsize ? unpack("n", substr($result["data"], $x + $possize, 2))[1] : 8);
					$last = ($haslastaccess ? unpack("n", substr($result["data"], $x + $possize + ($hasstructsize ? 2 : 0), 2))[1] : 0);

					$obj->data["entries"][] = array($pos, $size, $last);

					if ($pos > 0 || $size > 0)  $obj->data["assigned"]++;
				}

				// Prevent weird circular logic issues.  Root ID map entries should not have IDs.
				if ($obj->data["id"] > 0)  $this->RemoveObjectIDInternal($obj);

				// Truncate the data and reset the encoding to internal object data if it is currently streaming.  Root ID map should never be streaming.
				if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
				{
					$result = $this->Truncate($obj);
					if (!$result["success"])  return $result;
				}

				$this->idmap->data["loaded"]++;
			}

			return array("success" => true);
		}

		public function Create($pfcfilename, $appmajorver, $appminorver, $appbuildnum, $magic = false, $ifdsfeatures = self::FEATURES_OBJECT_ID_STRUCT_SIZE, $fmtfeatures = 0)
		{
			$this->Close();

			if (class_exists("PagingFileCache", false) && $pfcfilename instanceof PagingFileCache)
			{
				if ($pfcfilename->GetMaxPos() > 0)  return array("success" => false, "error" => self::IFDSTranslate("The file already exists."), "errorcode" => "file_exists", "info" => "PagingFileCache");

				$this->pfc = $pfcfilename;
			}
			else if ($pfcfilename === false)  $this->fp = "";
			else if (file_exists($pfcfilename))  return array("success" => false, "error" => self::IFDSTranslate("The file already exists."), "errorcode" => "file_exists", "info" => $pfcfilename);
			else if (($this->fp = @fopen($pfcfilename, "w+b")) === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to open the file for writing."), "errorcode" => "fopen_failed", "info" => $pfcfilename);

			$this->open = true;

			if ($magic !== false)  $this->SetMagic($magic);

			$this->fileheader = array(
				"mod" => true,
				"valid" => true,
				"magic" => $this->magic,
				"ifds_major_ver" => 1,
				"ifds_minor_ver" => 0,
				"fmt_major_ver" => (int)$appmajorver,
				"fmt_minor_ver" => (int)$appminorver,
				"fmt_build_num" => (int)$appbuildnum,
				"ifds_features" => (int)$ifdsfeatures,
				"fmt_features" => (int)$fmtfeatures,
				"base_date" => (int)(time() / 86400),
				"date_diff" => 0,
				"name_map_pos" => 0,
				"id_map_pos" => 0,
				"free_map_pos" => 0
			);

			// Named object table.
			$result = $this->CreateKeyIDMap();
			if (!$result["success"])  return $result;

			$this->namemap = $result["obj"];
			$this->namemap->data["man"] = true;
			$this->namemap->data["entries"] = array();

			if (!$this->CreateObjectIDChunksTable())  return array("success" => false, "error" => self::IFDSTranslate("Unable to create the object ID chunks table."), "errorcode" => "create_object_id_chunks_table_failed", "info" => $pfcfilename);

			if (!$this->WriteHeader())  return array("success" => false, "error" => self::IFDSTranslate("Unable to write header to file."), "errorcode" => "write_header_failed", "info" => $pfcfilename);

			return array("success" => true, "header" => $this->fileheader);
		}

		protected function LoadFileHeader()
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read file header.  File is not open."), "errorcode" => "file_not_open");

			if ($this->fileheader !== false)  return array("success" => true);

			// Attempt to load the magic string of an unknown file type.
			if ($this->magic === false)
			{
				$data = $this->ReadDataInternal(0, 128);
				if ($data === false || strlen($data) < 6)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read file header.  Insufficient data."), "errorcode" => "insufficient_data", "size" => 6);

				$y2 = ord($data[0]);
				if (($y2 & 0x80) == 0)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  First byte is truncated."), "errorcode" => "invalid_signature");

				$y = $y2 & 0x7F;
				if ($y < 5)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Expected magic string."), "errorcode" => "invalid_signature_length");
				if (strlen($data) < 1 + $y)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read file header.  Insufficient data."), "errorcode" => "insufficient_data", "size" => 1 + $y);
				if (substr($data, 1 + $y - 5, 5) !== "\r\n\x00\x1A\n")  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Invalid magic string."), "errorcode" => "invalid_signature");

				$this->magic = substr($data, 1, $y - 5);
			}

			$y = strlen($this->magic) + 5;

			$size = 1 + $y + 52;
			$data = $this->ReadDataInternal(0, $size);
			if ($data === false || strlen($data) < $size)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read file header.  Insufficient data."), "errorcode" => "insufficient_data", "size" => $size);

			$y2 = ord($data[0]);
			if (($y2 & 0x80) == 0)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  First byte is truncated."), "errorcode" => "invalid_signature");
			if (($y2 & 0x7F) !== $y)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Invalid signature length."), "errorcode" => "invalid_signature_length");
			if (substr($data, 1, $y) !== $this->magic . "\r\n\x00\x1A\n")  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Invalid signature."), "errorcode" => "invalid_signature");

			$majorver = ord(substr($data, 1 + $y, 1));
			$minorver = ord(substr($data, 1 + $y + 1, 1));

			if ($majorver != 1)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Expected major version 1."), "errorcode" => "invalid_major_ver");

			$this->fileheader = array(
				"mod" => false,
				"valid" => (pack("N", crc32(substr($data, 0, $size - 4))) === substr($data, $size - 4, 4)),
				"magic" => $this->magic,
				"ifds_major_ver" => $majorver,
				"ifds_minor_ver" => $minorver,
				"fmt_major_ver" => unpack("n", substr($data, 1 + $y + 2, 2))[1],
				"fmt_minor_ver" => unpack("n", substr($data, 1 + $y + 4, 2))[1],
				"fmt_build_num" => unpack("n", substr($data, 1 + $y + 6, 2))[1],
				"ifds_features" => unpack("N", substr($data, 1 + $y + 8, 4))[1],
				"fmt_features" => unpack("N", substr($data, 1 + $y + 12, 4))[1],
				"base_date" => unpack("J", substr($data, 1 + $y + 16, 8))[1],
				"date_diff" => 0,
				"name_map_pos" => unpack("J", substr($data, 1 + $y + 24, 8))[1],
				"id_map_pos" => unpack("J", substr($data, 1 + $y + 32, 8))[1],
				"free_map_pos" => unpack("J", substr($data, 1 + $y + 40, 8))[1],
				"size" => $size
			);

			$date = (int)(time() / 86400);
			if ($date > $this->fileheader["base_date"])  $this->fileheader["date_diff"] = $date - $this->fileheader["base_date"];

			return array("success" => true);
		}

		public function Open($pfcfilename, $magic = false)
		{
			$this->Close();

			if ($magic !== false)  $this->SetMagic($magic);

			if (class_exists("PagingFileCache", false) && $pfcfilename instanceof PagingFileCache)
			{
				$this->maxpos = $pfcfilename->GetMaxPos();

				if ($this->maxpos === 0)  return array("success" => false, "error" => self::IFDSTranslate("No file data.  For streaming content, use stream reader."), "errorcode" => "no_file_data", "info" => "PagingFileCache");

				$this->pfc = $pfcfilename;

				$pfcfilename = false;
			}

			if ($pfcfilename === false)  $this->fp = false;
			else if (!file_exists($pfcfilename))  return array("success" => false, "error" => self::IFDSTranslate("The file does not exist."), "errorcode" => "file_not_found", "info" => $pfcfilename);
			else if (($this->fp = @fopen($pfcfilename, "r+b")) === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to open the file for reading/writing."), "errorcode" => "fopen_failed", "info" => $pfcfilename);
			else
			{
				@fseek($this->fp, 0, SEEK_END);

				$this->maxpos = ftell($this->fp);
			}

			$this->open = true;

			$result = $this->LoadFileHeader();
			if (!$result["success"])  return $result;

			// Attempt to load the last structure as the streamed file terminating DATA chunk.
			if ($this->fileheader["name_map_pos"] == 0 || $this->fileheader["id_map_pos"] == 0)
			{
				$filepos = $this->maxpos - 24;
				$data = "";

				$result = $this->ReadNextStructure($filepos, $data, 24);
				if (!$result["success"] || $result["type"] !== self::TYPE_DATA_CHUNKS || $result["enc"] === self::DC_DATA_LOCATIONS || strlen($result["data"]) != 16)  return array("success" => false, "error" => self::IFDSTranslate("Last file chunk does not contain streaming header information."), "errorcode" => "chunk_read_failed", "info" => $result);

				$this->fileheader["name_map_pos"] = unpack("J", substr($result["data"], 0, 8))[1];
				$this->fileheader["id_map_pos"] = unpack("J", substr($result["data"], 8, 8))[1];
			}

			// Load the name map.
			$result = $this->GetObjectByPosition($this->fileheader["name_map_pos"]);
			if (!$result["success"])  return $result;

			if (($result["obj"]->data["enc"] & self::ENCODER_MASK_DATA_NUM) !== self::ENCODER_KEY_ID_MAP)  return array("success" => false, "error" => self::IFDSTranslate("Name map is not a key to object ID map."), "errorcode" => "invalid_name_map", "info" => $result);

			$result2 = $this->GetKeyValueMap($result["obj"]);
			if (!$result2["success"])  return $result2;

			$this->namemap = $result["obj"];
			$this->namemap->data["man"] = true;
			$this->namemap->data["entries"] = $result2["map"];

			// Load the object ID root map.
			if (!$this->LoadObjectIDTableChunksMap(false))  return array("success" => false, "error" => self::IFDSTranslate("Failed to load object ID table chunks map."), "errorcode" => "load_object_id_map_failed");

			// Find the next available ID.
			$this->nextid = $this->FindNextAvailableID(0);
//echo "Next ID:  " . $this->nextid . "\n";
//var_dump($this->idmap);

			return array("success" => true, "header" => $this->fileheader, "valid" => $this->fileheader["valid"] && $this->namemap->data["valid"] && $this->idmap->data["valid"]);
		}

		// For read only streaming data.
		public function InitStreamReader($magic = false)
		{
			$this->Close();

			if ($magic !== false)  $this->SetMagic($magic);

			$this->fp = "";
			$this->open = true;
		}

		public function AppendStreamReader($data)
		{
			$this->fp .= $data;
			$this->maxpos += strlen($data);

			if ($this->fileheader === false)
			{
				$result = $this->LoadFileHeader();
				if (!$result["success"])  return $result;

				$this->streampos = $this->fileheader["size"];
			}

			return array("success" => true);
		}

		public function GetStreamPos()
		{
			return $this->streampos;
		}

		public function ReadNextFromStreamReader(&$data, $size, $raw = false)
		{
			$result = $this->ReadNextStructure($this->streampos, $data, $size, $raw);
			if (!$result["success"])
			{
				// Rewrite the error for EOF data read "failure" when using the stream reader.
				if ($result["errorcode"] === "read_data_failed")  return array("success" => false, "error" => self::IFDSTranslate("Insufficient data."), "errorcode" => "insufficient_data", "size" => $result["nextsize"]);

				return $result;
			}

			// Reduce RAM usage after processing ~1MB.
			if ($this->streampos - $this->currpos > 1048576)
			{
				$this->fp = substr($this->fp, $this->streampos - $this->currpos);
				$this->currpos = $this->streampos;
			}

			return $result;
		}

		protected function ResetInternal()
		{
			$this->open = false;
			$this->pfc = false;
			$this->fp = false;
			$this->vardata = false;
			$this->currpos = 0;
			$this->streampos = 0;
			$this->maxpos = 0;
			$this->fileheader = false;
			$this->objcache = array();
			$this->objposmap = array();
			$this->namemap = false;
			$this->idmap = false;
			$this->nextid = 1;
			$this->nextminid = -1;
			$this->freemap = false;
			$this->estram = 0;
		}

		public function Close()
		{
			$this->FlushAll();

			if ($this->pfc !== false)  $this->pfc->Close();
			else if ($this->fp !== false && is_resource($this->fp))  fclose($this->fp);

			$this->ResetInternal();
		}

		public static function OptimizeCopyObjectInternal($srcifds, $destifds, $obj)
		{
			if (!($srcifds instanceof IFDS))  return array("success" => false, "error" => self::IFDSTranslate("Unable to copy object.  Source is not a valid object."), "errorcode" => "invalid_src");
			if (!($destifds instanceof IFDS))  return array("success" => false, "error" => self::IFDSTranslate("Unable to copy object.  Destination is not a valid object."), "errorcode" => "invalid_dest");
			if (!($obj instanceof IFDS_RefCountObj))  return array("success" => false, "error" => self::IFDSTranslate("Unable to copy object.  Object is not valid."), "errorcode" => "invalid_obj");

			// Finalize the source object.
			$result = $srcifds->WriteObject($obj);
			if (!$result["success"])  return $result;

			// Seek to the start.
			$result = $srcifds->Seek($obj, 0);
			if (!$result["success"])  return $result;

			// Copy base object and reset some information.
			$data = (array)$obj->data;
			$data["refs"] = 0;
			$data["mod"] = true;
			$data["data_mod"] = true;
			$data["obj_pos"] = 0;
			$data["obj_size"] = 0;
			$data["data_tab"] = false;

			if ($data["id"] < 0)
			{
				$data["id"] = $destifds->nextminid;

				$destifds->nextminid--;
			}

			$encmethod = $data["enc"] & self::ENCODER_MASK_DATA;

			if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				$data["data_size"] = 0;
			}
			else if ($encmethod === self::ENCODER_DATA_CHUNKS)
			{
				// Calculate DATA locations table size.
				$data["data_tsize"] = 18;

				if ($data["data_size"] >= 65528)  $data["data_tsize"] += ((int)($data["data_size"] / (65535 * 65528)) + 1) * 10;

				$data["data_size"] = 0;
			}

			$destifds->objcache[$data["id"]] = &$data;

			$destifds->estram += $data["est_ram"];

			$obj2 = new IFDS_RefCountObj($data);

			// Write the new object.
			$result = $destifds->WriteObject($obj2);
			if (!$result["success"])  return $result;

			// Copy data.
			if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				// Read data in large chunks even if it will go well past the end of the object's data stream.
				$filepos = $obj->data["obj_pos"] + $obj->data["obj_size"];

				$data2 = "";
				$nextsize = 65536;

				do
				{
					if (strlen($data2) < $nextsize)  $nextsize = 65536;

					$result = $srcifds->ReadNextStructure($filepos, $data2, $nextsize, true);
					if (!$result["success"])  return $result;

					if ($result["type"] !== self::TYPE_DATA_CHUNKS || !($result["origtype"] & self::TYPE_STREAMED))  return array("success" => false, "error" => self::IFDSTranslate("Unable to find the end of the interleaved DATA chunks.  Invalid/Unexpected data structure encountered."), "errorcode" => "invalid_data_structure");

					if (!$destifds->WriteDataInternal($result["raw"], $destifds->maxpos))  return array("success" => false, "error" => self::IFDSTranslate("Unable to copy object data.  Write failed."), "errorcode" => "write_data_failed");

					$nextsize = $result["nextsize"];

				} while ($result["enc"] !== self::DC_DATA_TERM || $result["channel"] !== 0);

				$destifds->vardata = false;

				unset($destifds->objposmap[$obj2->data["obj_pos"]]);
				unset($destifds->objcache[$obj2->data["id"]]);

				$destifds->estram -= $obj2->data["est_ram"];
			}
			else if ($encmethod === self::ENCODER_DATA_CHUNKS)
			{
				// Copy main blocks.
				$y = count($obj->data["data_tab"]);

				for ($x = 0; $x < $y - 1; $x++)
				{
					$tinfo = &$obj->data["data_tab"][$x];

					$pos = $tinfo["file_pos"];
					$size = $tinfo["file_size"];
					while ($size > 0)
					{
						$data2 = "";
						while (strlen($data2) < 65536)
						{
							$data3 = $srcifds->ReadDataInternal($pos, 65536 - strlen($data2));
							if ($data3 === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to copy object data.  Read failed."), "errorcode" => "read_data_failed");

							$data2 .= $data3;
						}

						if ($obj2->data["data_tab"] === false)  $obj2->data["data_tab"] = array(array("file_pos" => $destifds->maxpos, "file_size" => 0, "data_pos" => 0, "data_size" => 0));

						$tinfo2 = &$obj2->data["data_tab"][$obj2->data["data_tnum"]];

						if ($tinfo2["file_size"] >= 65535 * 65536)
						{
							$obj2->data["data_tab"][] = array("file_pos" => $destifds->maxpos, "file_size" => 0, "data_pos" => $tinfo2["data_pos"] + $tinfo2["data_size"], "data_size" => 0);
							$obj2->data["data_tnum"]++;

							$tinfo2 = &$obj2->data["data_tab"][$obj2->data["data_tnum"]];
						}

						if (!$destifds->WriteDataInternal($data2, $destifds->maxpos))  return array("success" => false, "error" => self::IFDSTranslate("Unable to copy object data.  Write failed."), "errorcode" => "write_data_failed");

						$tinfo2["file_size"] += 65536;
						$tinfo2["data_size"] += 65528;

						$size -= 65536;
					}
				}

				// Copy last DATA chunk.
				if ($obj2->data["data_tab"] === false)  $obj2->data["data_tab"] = array(array("file_pos" => $destifds->maxpos, "file_size" => 0, "data_pos" => 0, "data_size" => 0));
				else
				{
					$tinfo2 = &$obj2->data["data_tab"][$obj2->data["data_tnum"]];

					$obj2->data["data_tab"][] = array("file_pos" => $destifds->maxpos, "file_size" => 0, "data_pos" => $tinfo2["data_pos"] + $tinfo2["data_size"], "data_size" => 0);
					$obj2->data["data_tnum"]++;
				}

				$tinfo = &$obj->data["data_tab"][$y - 1];

				$pos = $tinfo["file_pos"];
				$size = $tinfo["file_size"];

				if ($pos >= $srcifds->fileheader["size"])
				{
					$tinfo2 = &$obj2->data["data_tab"][$obj2->data["data_tnum"]];

					$data2 = "";
					$result = $srcifds->ReadNextStructure($pos, $data2, $size, true);
					if (!$result["success"])  return $result;

					if ($result["type"] !== self::TYPE_DATA_CHUNKS || ($result["origtype"] & self::TYPE_STREAMED))  return array("success" => false, "error" => self::IFDSTranslate("Last DATA chunk is invalid.  Invalid/Unexpected data structure encountered."), "errorcode" => "invalid_data_structure");

					if (strlen($result["raw"]) >= 65536)  return array("success" => false, "error" => self::IFDSTranslate("Last DATA chunk is invalid.  Expected less than max chunk size."), "errorcode" => "invalid_data_chunk_size");

					if (!$destifds->WriteDataInternal($result["raw"], $destifds->maxpos))  return array("success" => false, "error" => self::IFDSTranslate("Unable to copy object data.  Write failed."), "errorcode" => "write_data_failed");

					$tinfo2["file_size"] = strlen($result["raw"]);
					$tinfo2["data_size"] = strlen($result["raw"]) - 8;
				}

				// Write the DATA locations table.
				$result = $destifds->WriteObjectDataLocationsTable($obj2);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public static function LinkedListOptimizeInternal($srcifds, $destifds, $obj)
		{
			// Skip processing for leaf nodes.
			if ($obj->data["type"] & self::TYPE_LEAF)  return array("success" => true);

			// Get an iterator for the linked list.  May cause the source file to update all of its nodes and the root object.
			$result = $srcifds->CreateLinkedListIterator($obj);
			if (!$result["success"])  return $result;

			// Copy the object.
			$result = self::OptimizeCopyObjectInternal($srcifds, $destifds, $obj);
			if (!$result["success"])  return $result;

			$result = $srcifds->CreateLinkedListIterator($obj);
			if (!$result["success"])  return $result;

			$iter = $result["iter"];

			// Copy all nodes in the linked list.
			while ($srcifds->GetNextLinkedListNode($iter) && $iter->nodeobj !== false)
			{
				$result = self::OptimizeCopyObjectInternal($srcifds, $destifds, $iter->nodeobj);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public static function Optimize($srcfile, $destfile, $magic = false, $typeoptimizecallbacks = array(self::TYPE_LINKED_LIST => __CLASS__ . "::LinkedListOptimizeInternal"))
		{
			// Open the source file.
			if ($srcfile instanceof IFDS)
			{
				$srcifds = $srcfile;

				// Write and flush the object ID map.
				$result = $srcifds->WriteIDMap();
				if (!$result["success"])  return $result;

				$magic = $srcifds->magic;
			}
			else
			{
				if (!is_string($srcfile) || !file_exists($srcfile))  return array("success" => false, "error" => self::IFDSTranslate("Unable to optimize.  File does not exist."), "errorcode" => "file_not_found");

				$srcifds = new IFDS();

				$result = $srcifds->Open($srcfile, $magic);
				if (!$result["success"])  return $result;
			}

			$srcheader = $srcifds->fileheader;
			if ($srcheader === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to optimize.  File header does not exist."), "errorcode" => "file_header_not_found");

			// Open the destination file.
			if ($destfile instanceof IFDS)
			{
				$destifds = $destfile;

				if ($destifds->fileheader === false || $destifds->maxpos !== $destifds->fileheader["size"])  return array("success" => false, "error" => self::IFDSTranslate("Unable to optimize.  Destination file size is invalid."), "errorcode" => "invalid_destfile_size");
			}
			else
			{
				$destifds = new IFDS();

				$result = $destifds->Create($destfile, $srcheader["fmt_major_ver"], $srcheader["fmt_minor_ver"], $srcheader["fmt_build_num"], $magic, $srcheader["ifds_features"], $srcheader["fmt_features"]);
				if (!$result["success"])  return $result;
			}

			// Copy and write the name map.
			$destifds->namemap->data["entries"] = $srcifds->namemap->data["entries"];
			$destifds->namemap->data["mod"] = true;

			$result = $destifds->WriteNameMap();
			if (!$result["success"])  return $result;

			// Initialize the object ID map.
			$y = count($srcifds->idmap->data["entries"]);

			$useddates = array();

			for ($x = 0; $x < $y; $x++)
			{
				$result = $srcifds->LoadObjectIDTableMap($x);
				if (!$result["success"])  return $result;

				$pageobj = $srcifds->idmap->data["entries"][$x];

				$result = $destifds->LoadObjectIDTableMap($x);
				if (!$result["success"])  return $result;

				$pageobj2 = $destifds->idmap->data["entries"][$x];

				for ($x2 = 0; $x2 < 65536; $x2++)
				{
					if (isset($pageobj->data["entries"][$x2]))
					{
						if ($pageobj->data["entries"][$x2][0] >= $srcifds->fileheader["size"])
						{
							$pageobj2->data["entries"][] = array(0, 1, 0);

							if (!isset($useddates[$pageobj->data["entries"][$x2][2]]))  $useddates[$pageobj->data["entries"][$x2][2]] = array($x * 65536 + $x2, $x * 65536 + $x2);
							else  $useddates[$pageobj->data["entries"][$x2][2]][1] = $x * 65536 + $x2;
						}
						else
						{
							$pageobj2->data["entries"][] = array(0, 0, 0);
						}
					}
					else if ($x == $y - 1)
					{
						break;
					}
					else
					{
						$pageobj2->data["entries"][] = array(0, 0, 0);
					}
				}

				$pageobj2->data["data_mod"] = true;

				$destifds->idmap->data["data_mod"] = true;
			}

			// Write object ID map.
			$result = $destifds->WriteIDMap();
			if (!$result["success"])  return $result;

			// Copy objects.
			krsort($useddates);

			foreach ($useddates as $date => $dinfo)
			{
				for ($id2 = $dinfo[0]; $id2 <= $dinfo[1]; $id2++)
				{
					$pagenum = (int)($id2 / 65536);
					$pageid = $id2 % 65536;

					$result = $srcifds->LoadObjectIDTableMap($pagenum);
					if (!$result["success"])  return $result;

					$pageobj = $srcifds->idmap->data["entries"][$pagenum];

					if (!isset($pageobj->data["entries"][$pageid]) || $pageobj->data["entries"][$pageid][0] < $srcifds->fileheader["size"] || $pageobj->data["entries"][$pageid][2] !== $date)  continue;

					$result = $destifds->LoadObjectIDTableMap($pagenum);
					if (!$result["success"])  return $result;

					$pageobj2 = $destifds->idmap->data["entries"][$pagenum];

					$result = $srcifds->GetObjectByPosition($pageobj->data["entries"][$pageid][0], $pageobj->data["entries"][$pageid][1]);
					if (!$result["success"])  return $result;

					$obj = $result["obj"];
					$obj->data["id"] = $id2 + 1;

					$basetype = $obj->data["type"] & self::TYPE_BASE_MASK;

					// Let custom handlers override default copying behavior (e.g. always copy linked lists in node order regardless of last access date).
					$result = (isset($typeoptimizecallbacks[$basetype]) ? call_user_func_array($typeoptimizecallbacks[$basetype], array($srcifds, $destifds, $obj)) : self::OptimizeCopyObjectInternal($srcifds, $destifds, $obj));
					if (!$result["success"])  return $result;
				}
			}

			// Finalize the file.
			$result = $destifds->FlushAll();

			return $result;
		}

		public function GetHeader()
		{
			return $this->fileheader;
		}

		public function SetAppFormatVersion($majorver, $minorver, $buildnum)
		{
			if ($this->fileheader !== false)
			{
				$this->fileheader["fmt_major_ver"] = (int)$majorver;
				$this->fileheader["fmt_minor_ver"] = (int)$minorver;
				$this->fileheader["fmt_build_num"] = (int)$buildnum;

				$this->fileheader["mod"] = true;
			}
		}

		public function GetAppFormatFeatures()
		{
			return $this->fileheader["fmt_features"];
		}

		public function SetAppFormatFeatures($features)
		{
			if ($this->fileheader !== false && $this->fileheader["fmt_features"] !== (int)$features)
			{
				$this->fileheader["fmt_features"] = (int)$features;

				$this->fileheader["mod"] = true;
			}
		}

		public function WriteHeader()
		{
			if (!$this->open)  return false;

			if ($this->fileheader !== false && $this->fileheader["mod"])
			{
				$data = chr(0x80 | (strlen($this->fileheader["magic"]) + 5)) . $this->fileheader["magic"] . "\r\n\x00\x1A\n";
				$data .= chr($this->fileheader["ifds_major_ver"]);
				$data .= chr($this->fileheader["ifds_minor_ver"]);
				$data .= pack("n", $this->fileheader["fmt_major_ver"]);
				$data .= pack("n", $this->fileheader["fmt_minor_ver"]);
				$data .= pack("n", $this->fileheader["fmt_build_num"]);
				$data .= pack("N", $this->fileheader["ifds_features"]);
				$data .= pack("N", $this->fileheader["fmt_features"]);
				$data .= pack("J", $this->fileheader["base_date"]);
				$data .= pack("J", $this->fileheader["name_map_pos"]);
				$data .= pack("J", $this->fileheader["id_map_pos"]);
				$data .= pack("J", $this->fileheader["free_map_pos"]);
				$data .= pack("N", crc32($data));

				$this->fileheader["size"] = strlen($data);

				if (!$this->WriteDataInternal($data, 0))  return false;

				$this->fileheader["mod"] = false;
			}

			return true;
		}

		public function WriteNameMap()
		{
			if ($this->namemap === false)  return array("success" => true);

			if ($this->namemap->data["mod"])
			{
				$result = $this->SetKeyValueMap($this->namemap, $this->namemap->data["entries"]);
				if (!$result["success"])  return $result;

				$result = $this->WriteObject($this->namemap);
				if (!$result["success"])  return $result;

				if ($this->fileheader !== false && $this->fileheader["name_map_pos"] !== $this->namemap->data["obj_pos"])
				{
					$this->fileheader["name_map_pos"] = $this->namemap->data["obj_pos"];

					$this->fileheader["mod"] = true;
				}
			}

			return array("success" => true);
		}

		public function WriteIDMap()
		{
			if ($this->idmap === false)  return array("success" => true);

			// Shrink the loaded object ID map.
			$y = count($this->idmap->data["entries"]);

			while ($y)
			{
				$obj = &$this->idmap->data["entries"][$y - 1];

				if (!is_object($obj))  break;

				$y2 = count($obj->data["entries"]);

				for ($x = $y2; $x && $obj->data["entries"][$x - 1][1] == 0; $x--)
				{
					unset($obj->data["entries"][$x - 1]);

					$obj->data["data_mod"] = true;
				}

				if (count($obj->data["entries"]) > 0)  break;

				$result = $this->DeleteObject($obj);
				if (!$result["success"])  return $result;

				unset($this->idmap->data["entries"][$y - 1]);

				$y--;
			}

			// For the very first write, write the root ID map node before any root ID map entries.
			if ($this->idmap->data["data_size"] === 0)
			{
				$result = $this->WriteData($this->idmap, str_repeat("\x00", count($this->idmap->data["entries"]) * 10));
				if (!$result["success"])  return $result;

				$this->idmap->data["info"]["num"] = count($this->idmap->data["entries"]);

				$result = $this->WriteObject($this->idmap);
				if (!$result["success"])  return $result;

				$this->idmap->data["mod"] = true;
			}

			$data = "";

			$hasstructsize = ($this->fileheader["ifds_features"] & self::FEATURES_OBJECT_ID_STRUCT_SIZE);
			$haslastaccess = ($this->fileheader["ifds_features"] & self::FEATURES_OBJECT_ID_LAST_ACCESS);
			$baseentrysize = ($hasstructsize ? 2 : 0) + ($haslastaccess ? 2 : 0);

			// Unload all loaded entries.  One reason to call this function is to periodically release RAM used by large object ID tables.
			foreach ($this->idmap->data["entries"] as $chunknum => &$obj)
			{
				if (is_object($obj))
				{
					if ($obj->data["data_mod"])
					{
						// Calculate entry size.
						$maxpos = 0;
						foreach ($obj->data["entries"] as &$entry)
						{
							if ($maxpos < $entry[0])  $maxpos = $entry[0];
						}

						if ($maxpos <= 65535)
						{
							$obj->data["info"]["size"] = 2 + $baseentrysize;
							$poschr = "n";
						}
						else if ($maxpos <= 4294967295)
						{
							$obj->data["info"]["size"] = 4 + $baseentrysize;
							$poschr = "N";
						}
						else
						{
							$obj->data["info"]["size"] = 8 + $baseentrysize;
							$poschr = "J";
						}

						// Rewind.
						$result = $this->Seek($obj, 0);
						if (!$result["success"])  return $result;

						// Generate and write the data.
						$data2 = "";
						foreach ($obj->data["entries"] as &$entry)
						{
							$data2 .= pack($poschr, $entry[0]);
							if ($hasstructsize)  $data2 .= pack("n", $entry[1]);
							if ($haslastaccess)  $data2 .= pack("n", $entry[2]);
						}

						$result = $this->WriteData($obj, $data2);
						if (!$result["success"])  return $result;

						if ($obj->data["data_pos"] < $obj->data["data_size"])
						{
							$result = $this->Truncate($obj, $obj->data["data_pos"]);
							if (!$result["success"])  return $result;
						}
					}

					if ($obj->data["mod"] || $obj->data["data_mod"])
					{
						$obj->data["info"]["num"] = count($obj->data["entries"]);

						$result = $this->WriteObject($obj);
						if (!$result["success"])  return $result;
					}

					// Free the object.
					$obj = array("file_pos" => $obj->data["obj_pos"], "unassigned" => ($obj->data["assigned"] <= 0 ? 65535 : 65536 - $obj->data["assigned"]));

					$this->idmap->data["entries"][$chunknum] = $obj;

					$this->idmap->data["loaded"]--;
				}

				if ($this->idmap->data["mod"] || $this->idmap->data["data_mod"])
				{
					$data .= pack("J", $obj["file_pos"]);
					$data .= pack("n", $obj["unassigned"]);
				}
			}

			// Write the root object ID map object.
			if ($this->idmap->data["mod"] || $this->idmap->data["data_mod"])
			{
				// Rewind.
				$result = $this->Seek($this->idmap, 0);
				if (!$result["success"])  return $result;

				// Write the data and object.
				$result = $this->WriteData($this->idmap, $data);
				if (!$result["success"])  return $result;

				$this->idmap->data["info"]["num"] = count($this->idmap->data["entries"]);

				$result = $this->WriteObject($this->idmap);
				if (!$result["success"])  return $result;

				if ($this->fileheader !== false && $this->fileheader["id_map_pos"] !== $this->idmap->data["obj_pos"])
				{
					$this->fileheader["id_map_pos"] = $this->idmap->data["obj_pos"];

					$this->fileheader["mod"] = true;
				}
			}

			return array("success" => true);
		}

		public function WriteFreeSpaceMap()
		{
			// Don't do anything if the free space map does not exist or was not loaded.
			if ($this->freemap === false)  return array("success" => true);

			// Write a root free space node placeholder before any free space tables.
			$y = count($this->freemap->data["entries"]) * 12;
			if ($this->freemap->data["data_size"] < $y)
			{
				// Seek to the end.
				$result = $this->Seek($this->freemap, $this->freemap->data["data_size"]);
				if (!$result["success"])  return $result;

				// Write placeholder data.
				$result = $this->WriteData($this->freemap, str_repeat("\x00", $y - $this->freemap->data["data_size"]));
				if (!$result["success"])  return $result;

				$this->freemap->data["info"]["num"] = count($this->freemap->data["entries"]);

				$result = $this->WriteObject($this->freemap);
				if (!$result["success"])  return $result;

				$this->freemap->data["mod"] = true;
			}

			do
			{
				$processed = false;

				// Unload all loaded entries.
				foreach ($this->freemap->data["entries"] as $chunknum => &$obj)
				{
					if (is_object($obj))
					{
						if ($obj->data["data_mod"])
						{
							// When expanding DATA chunks, write placeholder data first so the object can relocate as needed.
							$y = count($obj->data["entries"]) * 4;
							if ($obj->data["data_size"] < $y)
							{
								// Seek to the end.
								$result = $this->Seek($obj, $obj->data["data_size"]);
								if (!$result["success"])  return $result;

								// Write placeholder data.
								$result = $this->WriteData($obj, str_repeat("\xFF", $y - $obj->data["data_size"]));
								if (!$result["success"])  return $result;

								$result = $this->WriteObject($obj);
								if (!$result["success"])  return $result;
							}

							// Rewind.
							$result = $this->Seek($obj, 0);
							if (!$result["success"])  return $result;

							// Generate and write the data.
							$data = "";
							foreach ($obj->data["entries"] as &$entry)
							{
								if ($entry[0] === 65536)  $data .= "\x00\x00\xFF\xFF";
								else if ($entry[0] === 0)  $data .= "\xFF\xFF\xFF\xFF";
								else
								{
									$data .= pack("n", $entry[0]);
									$data .= pack("n", $entry[1]);
								}
							}

							$result = $this->WriteData($obj, $data);
							if (!$result["success"])  return $result;


							if ($obj->data["data_pos"] < $obj->data["data_size"])
							{
								$result = $this->Truncate($obj, $obj->data["data_pos"]);
								if (!$result["success"])  return $result;
							}
						}

						if ($obj->data["mod"] || $obj->data["data_mod"])
						{
							$obj->data["info"]["num"] = count($obj->data["entries"]);

							$result = $this->WriteObject($obj);
							if (!$result["success"])  return $result;
						}

						// Determine the size of the largest free space.
						$bestsize = 0;
						$currsize = 0;
						foreach ($obj->data["entries"] as &$entry)
						{
							if ($entry[1] == 0)
							{
								$currsize += $entry[0];

								if ($entry[1] < 65536)
								{
									if ($bestsize < $currsize)  $bestsize = $currsize;

									$currsize = 0;
								}
							}
							else if ($entry[1] + $entry[0] == 65536)
							{
								$currsize = $entry[0];
							}
							else
							{
								if ($bestsize < $entry[0])  $bestsize = $entry[0];

								$currsize = 0;
							}
						}

						// Free the object.
						$obj = array("file_pos" => $obj->data["obj_pos"], "size" => $bestsize);

						$this->freemap->data["entries"][$chunknum] = $obj;

						$processed = true;
					}
				}
			} while ($processed);

			// Write the root free space map object.
			if ($this->freemap->data["mod"] || $this->freemap->data["data_mod"])
			{
				// Rewind.
				$result = $this->Seek($this->freemap, 0);
				if (!$result["success"])  return $result;

				$data = "";

				foreach ($this->freemap->data["entries"] as $chunknum => &$obj)
				{
					$data .= pack("J", $obj["file_pos"]);
					$data .= pack("N", $obj["size"]);
				}

				// Write the data and object.
				$result = $this->WriteData($this->freemap, $data);
				if (!$result["success"])  return $result;

				$this->freemap->data["info"]["num"] = count($this->freemap->data["entries"]);

				$result = $this->WriteObject($this->freemap);
				if (!$result["success"])  return $result;

				if ($this->fileheader !== false && $this->fileheader["free_map_pos"] !== $this->freemap->data["obj_pos"])
				{
					$this->fileheader["free_map_pos"] = $this->freemap->data["obj_pos"];

					$this->fileheader["mod"] = true;
				}
			}

			return array("success" => true);
		}

		public function ReadDataInternal($pos, $size)
		{
			if (!$this->open)  return false;

			if ($this->pfc !== false)
			{
				$result = $this->pfc->Seek($pos);
				if (!$result["success"])  return false;

				$result = $this->pfc->Read($size);
				if (!$result["success"])  return false;

				$data = ($result["data"] === "" && $result["eof"] ? false : $result["data"]);
			}
			else if (is_resource($this->fp))
			{
				@fseek($this->fp, $pos);

				$data = @fread($this->fp, $size);
				if ($data === "" && @feof($this->fp))  $data = false;
			}
			else
			{
				if ($pos < $this->currpos || $pos >= $this->maxpos)  return false;

				$data = substr($this->fp, $pos - $this->currpos, $size);
			}

			return $data;
		}

		protected function WriteDataInternal(&$data, $pos)
		{
			if (!$this->open)  return false;

			$y = strlen($data);

			if ($this->pfc !== false)
			{
				$result = $this->pfc->Seek($pos);
				if (!$result["success"])  return false;

				$result = $this->pfc->Write($data);
				if (!$result["success"])  return false;
			}
			else if (is_resource($this->fp))
			{
				@fseek($this->fp, $pos);

				if (@fwrite($this->fp, $data) < $y)  return false;
			}
			else
			{
				if ($pos < $this->currpos)  return false;
				if ($pos > $this->maxpos)  $pos = $this->maxpos;

				for ($x = 0; $x < $y; $x++)  $this->fp[$pos - $this->currpos + $x] = $data[$x];
			}

			if ($this->maxpos < $pos + $y)  $this->maxpos = $pos + $y;

			return true;
		}

		public function GetMaxPos()
		{
			return ($this->open ? $this->maxpos : 0);
		}

		public function GetStreamData()
		{
			if ($this->pfc !== false)  return $this->pfc->GetData();

			if (is_resource($this->fp))  return false;

			$result = $this->fp;

			$this->currpos += strlen($this->fp);
			$this->fp = "";

			return $result;
		}

		public function FlushAll()
		{
			// Close and finish writing any interleaved multi-channel data stream.
			if ($this->vardata !== false)
			{
				if ($this->CanWriteDataInternal($this->vardata))
				{
					$result = $this->WriteData(new IFDS_RefCountObj($this->vardata), "", 0, true);
					if (!$result["success"])  return $result;
				}

				$result = $this->ProcessVarData();
				if (!$result["success"])  return $result;
			}

			// Finalize all streaming objects.
			foreach ($this->objcache as $id => &$data)
			{
				$encmethod = $data["enc"] & self::ENCODER_MASK_DATA;

				if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM && $this->CanWriteDataInternal($data))
				{
					$result = $this->WriteData(new IFDS_RefCountObj($data), "", 0, true);
					if (!$result["success"])  return $result;
				}
			}

			// Write the name map.
			$result = $this->WriteNameMap();
			if (!$result["success"])  return $result;

			// Write all non-manual objects.  Attempt to write the object structure ahead of its data if possible.
			foreach ($this->objcache as $id => &$data)
			{
				if (!$data["man"])
				{
					// Write the object.
					$result = $this->WriteObject(new IFDS_RefCountObj($data));
					if (!$result["success"])  return $result;

					// Write and release data.
					$result = $this->FlushObjectDataChunks($data, true);
					if (!$result["success"])  return $result;
				}
			}

			// Write the ID and free space maps.
			$result = $this->WriteIDMap();
			if (!$result["success"])  return $result;

			$result = $this->WriteFreeSpaceMap();
			if (!$result["success"])  return $result;

			// Write the header or a terminating DATA CHUNKS object (0x3F 0x01) containing the streaming header portion.
			if ($this->fileheader !== false && $this->fileheader["mod"] && !$this->WriteHeader())
			{
				$data2 = "\x3F\x01";
				$data2 .= pack("n", 16);

				$data2 .= pack("J", $this->fileheader["name_map_pos"]);
				$data2 .= pack("J", $this->fileheader["id_map_pos"]);
				$data2 .= pack("N", crc32($data2));

				if (!$this->WriteDataInternal($data2, $this->maxpos))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write stream header to file."), "errorcode" => "stream_header_write_failed");
			}

			return array("success" => true);
		}

		public function GetNameMap()
		{
			return ($this->namemap !== false ? $this->namemap->data["entries"] : false);
		}

		public function GetNameMapID($name)
		{
			return ($this->namemap !== false && isset($this->namemap->data["entries"][$name]) ? $this->namemap->data["entries"][$name] : false);
		}

		public function SetNameMapID($name, $id)
		{
			if ($this->namemap !== false)
			{
				$this->namemap->data["entries"][$name] = (int)$id;

				$this->namemap->data["mod"] = true;
			}
		}

		public function UnsetNameMapID($name)
		{
			if ($this->namemap !== false && isset($this->namemap->data["entries"][$name]))
			{
				unset($this->namemap->data["entries"][$name]);

				$this->namemap->data["mod"] = true;
			}
		}

		protected function FlushObjectDataChunks(&$data, $flushall = false)
		{
			$encmethod = $data["enc"] & self::ENCODER_MASK_DATA;

			if ($encmethod === self::ENCODER_DATA_CHUNKS)
			{
				$hasmod = false;

				foreach ($data["chunks"] as $chunknum => &$cinfo)
				{
					$y2 = strlen($cinfo["data"]);

					// Sanity check.
					if ($y2 > 65528)
					{
						$cinfo["data"] = substr($cinfo["data"], 0, 65528);

						$y2 = 65528;
					}

					// Handle newly resized chunks.
					if ($cinfo["file_size"] < $y2 + 8)
					{
						// Free partial chunk from a previous write.
						if ($cinfo["file_size"] > 0)
						{
							$result = $this->FreeBytesInternal($cinfo["file_pos"], $cinfo["file_size"]);
							if (!$result["success"])  return $result;
						}

						if ($y2 >= 65528)
						{
							// Attempt to append to the last chunk in the file.
							if ($data["data_tab"] !== false && count($data["data_tab"]) > 1)
							{
								$tnum = count($data["data_tab"]) - 2;
								$tinfo = &$data["data_tab"][$tnum];

								$pos = $this->ReserveBytesInternal(65536, $tinfo["file_pos"] + $tinfo["file_size"]);

								if ($tinfo["file_pos"] + $tinfo["file_size"] !== $pos || $tinfo["file_size"] >= 65535 * 65536)
								{
									// Merge blocks to make space in the DATA locations table as needed.
									if (count($data["data_tab"]) > 65535)
									{
										$result = $this->MergeDownObjectDataChunks(new IFDS_RefCountObj($obj), 1);
										if (!$result["success"])  return $result;
									}

									$tempentry = array_pop($data["data_tab"]);

									$data["data_tab"][] = array("file_pos" => $pos, "file_size" => 0, "data_pos" => $cinfo["data_pos"], "data_size" => 0);
									$data["data_tab"][] = $tempentry;

									$tnum = count($data["data_tab"]) - 2;
									$tinfo = &$data["data_tab"][$tnum];

									$data["mod"] = true;
								}
							}
							else
							{
								$pos = $this->ReserveBytesInternal(65536);

								// Create the first chunk in the DATA locations table.
								if ($data["data_tab"] === false)
								{
									$data["data_tab"] = array();

									$tempentry = array("file_pos" => 0, "file_size" => 0, "data_pos" => 0, "data_size" => 0);
								}
								else
								{
									$tempentry = array_pop($data["data_tab"]);
								}

								$data["data_tab"][] = array("file_pos" => $pos, "file_size" => 0, "data_pos" => $cinfo["data_pos"], "data_size" => 0);
								$data["data_tab"][] = $tempentry;

								$tinfo = &$data["data_tab"][0];

								$data["mod"] = true;
							}

							// Update DATA chunk and DATA locations table information.
							$cinfo["file_pos"] = $pos;
							$cinfo["file_size"] = 65536;

							$tinfo["file_size"] += 65536;
							$tinfo["data_size"] += 65528;
						}
						else if ($flushall || $data["refs"] < 1)
						{
							// Update the last chunk in the DATA locations table.
							if ($data["data_tab"] === false)
							{
								$data["data_tab"] = array(
									array("file_pos" => 0, "file_size" => 0, "data_pos" => 0, "data_size" => 0)
								);

								$pos = $this->ReserveBytesInternal($y2 + 8);
							}
							else if (count($data["data_tab"]) > 1)
							{
								$tnum = count($data["data_tab"]) - 2;
								$tinfo = &$data["data_tab"][$tnum];

								$pos = $this->ReserveBytesInternal($y2 + 8, $tinfo["file_pos"] + $tinfo["file_size"]);
							}
							else
							{
								$tnum = count($data["data_tab"]) - 1;
								$tinfo = &$data["data_tab"][$tnum];

								$pos = $this->ReserveBytesInternal($y2 + 8, $tinfo["file_pos"]);
							}

							$tinfo = &$data["data_tab"][count($data["data_tab"]) - 1];

							$cinfo["file_pos"] = $pos;
							$cinfo["file_size"] = $y2 + 8;

							$tinfo["file_pos"] = $pos;
							$tinfo["file_size"] = $y2 + 8;
							$tinfo["data_pos"] = $cinfo["data_pos"];
							$tinfo["data_size"] = $y2;
						}
					}

					// Prepare and write the DATA chunk.
					if ($cinfo["mod"] && ($cinfo["file_size"] >= 65536 || ($cinfo["file_size"] > 0 && ($flushall || $data["refs"] < 1))))
					{
						$data2 = "\x3F";
						$data2 .= chr($cinfo["type"]);
						$data2 .= pack("n", $y2);
						$data2 .= $cinfo["data"];
						$data2 .= pack("N", crc32($data2));

						if (!$this->WriteDataInternal($data2, $cinfo["file_pos"]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data chunk."), "errorcode" => "write_failed");

						$cinfo["mod"] = false;
					}

					// Release the DATA chunk.
					if ($cinfo["mod"])  $hasmod = true;
					else if ($data["data_pos"] < $cinfo["data_pos"] || $data["data_pos"] > $cinfo["data_pos"] + $y2)
					{
						$size = $y2 + 50;

						$data["est_ram"] -= $size;
						$this->estram -= $size;

						unset($data["chunks"][$chunknum]);
					}
				}

				// Clear the DATA chunks modified indicator if there are no modified DATA chunks left.
				if (!$hasmod)  $data["data_mod"] = false;
			}
			else if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				if ($data["obj_pos"] > 0)
				{
					foreach ($data["chunks"] as $chunknum => &$cinfo)
					{
						$y2 = strlen($cinfo["data"]);

						if ($cinfo["mod"] && ($cinfo["file_size"] === 0 || $cinfo["file_size"] === $y2 + 10))
						{
							$data2 = "\xBF";
							$data2 .= chr($cinfo["type"]);
							$data2 .= pack("n", $y2);
							$data2 .= pack("n", $cinfo["channel"]);
							$data2 .= $cinfo["data"];
							$data2 .= pack("N", crc32($data2));

							if ($cinfo["file_pos"] < 1)  $cinfo["file_pos"] = $this->maxpos;
							$cinfo["file_size"] = strlen($data2);

							if (!$this->WriteDataInternal($data2, $cinfo["file_pos"]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data chunk."), "errorcode" => "write_failed");

							$cinfo["mod"] = false;
						}

						// Release the object from RAM.
						if ($data["data_pos"] < $cinfo["data_pos"] || $data["data_pos"] > $cinfo["data_pos"] + $y2)
						{
							$size = $y2 + 50;

							$data["est_ram"] -= $size;
							$this->estram -= $size;

							unset($data["chunks"][$chunknum]);
						}
					}

					$data["data_mod"] = false;
				}
			}

			return array("success" => true);
		}

		public function ProcessVarData()
		{
			// Currently writing an interleaved multi-channel data stream.
			if ($this->vardata !== false)
			{
				// Write and release data.
				$result = $this->FlushObjectDataChunks($this->vardata);
				if (!$result["success"])  return $result;

				if (!$this->CanWriteDataInternal($this->vardata))  $this->vardata = false;
			}

			return array("success" => true, "eof" => ($this->vardata === false));
		}

		public function ReduceObjectCache()
		{
			if ($this->estram < $this->maxram)  return true;

			$result = $this->ProcessVarData();
			if (!$result["success"])  return false;

			if ($this->vardata !== false)  return true;

			// Clean up the object cache.
			$largest = array();
			$largestmin = false;
			$interleavedid = false;
			foreach ($this->objcache as $id => &$data)
			{
				$encmethod = $data["enc"] & self::ENCODER_MASK_DATA;

				if ($data["refs"] < 1)
				{
					// Write the object.
					$result = $this->WriteObject(new IFDS_RefCountObj($data));
					if (!$result["success"])  return false;

					// Write and release data.
					$result = $this->FlushObjectDataChunks($data);
					if (!$result["success"])  return false;

					$this->estram -= $data["est_ram"];

					unset($this->objposmap[$data["obj_pos"]]);
					unset($this->objcache[$id]);
				}
				else if ($encmethod === self::ENCODER_DATA_CHUNKS && ($largestmin === false || $data["est_ram"] >= $largestmin))
				{
					// Select 25 to 50 large objects with non-interleaved data.
					$largest[$id] = $data["est_ram"];

					if (count($largest) >= 50)
					{
						asort($largest);
						$largest = array_slice($largest, -25, 25, true);
						foreach ($largest as $id2 => $size)
						{
							$largestmin = $size;

							break;
						}
					}
				}
				else if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM && !$data["man"] && ($interleavedid === false || $data["est_ram"] >= $this->objcache[$interleavedid]["est_ram"]))
				{
					// Select the largest interleaved multi-channel data stream.
					$interleavedid = $id;
				}
			}

			// Reduce memory usage of the largest objects.
			$threshold = $this->maxram * 0.80;
			if ($this->estram > $threshold)
			{
				foreach ($largest as $id => $size)
				{
					// Write and release data.
					$result = $this->FlushObjectDataChunks($this->objcache[$id]);
					if (!$result["success"])  return false;

					if ($this->estram < $threshold)  break;
				}
			}

			// Reduce memory usage of the largest interleaved multi-channel data stream.
			// Unfortunately, nothing else can be written out until this stream is completely finalized.
			if ($interleavedid !== false && $this->estram > $threshold)
			{
				// Write the object.
				$result = $this->WriteObject(new IFDS_RefCountObj($this->objcache[$interleavedid]));
				if (!$result["success"])  return false;
			}

			return true;
		}

		public function FindNextAvailableID($id)
		{
			if ($id < 0)  $id = 0;
			$id++;

			$id2 = $id - 1;
			$pagenum = (int)($id2 / 65536);
			$pageid = $id2 % 65536;

			while (isset($this->idmap->data["entries"][$pagenum]))
			{
				$pageobj = &$this->idmap->data["entries"][$pagenum];

				if (is_array($pageobj))
				{
					if ($pageobj["unassigned"] > 0)
					{
						$result = $this->LoadObjectIDTableMap($pagenum);
						if (!$result["success"])  return false;

						$pageobj = $this->idmap->data["entries"][$pagenum];
					}
				}

				if (!is_array($pageobj) && $pageobj->data["assigned"] < 65536)
				{
					$y = count($pageobj->data["entries"]);

					while ($pageid < $y && $pageobj->data["entries"][$pageid][0] > 0)
					{
						$id++;

						$pageid++;
					}

					break;
				}

				$pagenum++;

				$id += 65536 - $pageid;

				$pageid = 0;
			}

			return $id;
		}

		public function CreateObject($type, $dataencodernum, $name, $typeinfo, $extra, $withid = true)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to create object.  File is not open."), "errorcode" => "file_not_open");

			$this->ReduceObjectCache();

			if ($this->idmap === false || $withid === false)
			{
				$id = $this->nextminid;

				$this->nextminid--;
			}
			else
			{
				if ($name !== false && $this->GetNameMapID($name) !== false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to create object.  Name already exists."), "errorcode" => "name_already_exists");

				$id = $this->nextid;

				if ($id >= 4294967296)  return array("success" => false, "error" => self::IFDSTranslate("Unable to create object.  Object ID limit reached."), "errorcode" => "object_id_limit_reached");
				if ($id < 1)  return array("success" => false, "error" => self::IFDSTranslate("Unable to allocate ID.  Internal integer limit reached."), "errorcode" => "object_id_allocation_failed");

				// Mark the ID as used.
				$id2 = $id - 1;
				$pagenum = (int)($id2 / 65536);
				$pageid = $id2 % 65536;

				$result = $this->LoadObjectIDTableMap($pagenum);
				if (!$result["success"])  return $result;

				$pageobj = $this->idmap->data["entries"][$pagenum];

				if (!isset($pageobj->data["entries"][$pageid]))
				{
					$pageobj->data["entries"][$pageid] = array(0, 1, ($this->fileheader !== false ? $this->fileheader["date_diff"] : 0));

					if (($pageobj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_INTERNAL_DATA)
					{
						$result = $this->ClearObject($pageobj);
						if (!$result["success"])  return $result;
					}
				}

				$pageobj->data["assigned"]++;
				$pageobj->data["data_mod"] = true;

				$this->idmap->data["data_mod"] = true;

				// Find the next available ID.
				$this->nextid = $this->FindNextAvailableID($this->nextid);

				if ($name !== false && $this->SetNameMapID($name, $id) === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to create object.  Unable to set name map."), "errorcode" => "set_name_map_id_failed");
			}

			$data = array(
				"id" => $id,
				"refs" => 0,
				"man" => false,
				"mod" => true,
				"type" => $type,
				"type_str" => (isset(self::TYPE_STR_MAP[$type & ~self::TYPE_STREAMED]) ? self::TYPE_STR_MAP[$type & ~self::TYPE_STREAMED] : "unknown"),
				"enc" => ($dataencodernum !== self::ENCODER_NONE ? ($dataencodernum | self::ENCODER_INTERNAL_DATA) : (self::ENCODER_NONE | self::ENCODER_NO_DATA)),
				"est_ram" => 400,
				"obj_pos" => 0,
				"obj_size" => 0,
				"info" => $typeinfo,
				"valid" => true,

				"data_mod" => true,
				"data_tab" => false,
				"data_tnum" => 0,
				"data_tsize" => 0,
				"data_pos" => 0,
				"data_size" => 0,
				"chunk_num" => 0,
				"chunks" => ($dataencodernum !== self::ENCODER_NONE ? array(array(
					"mod" => true,
					"valid" => true,
					"type" => self::DC_DATA_TERM,
					"channel" => false,
					"file_pos" => 0,
					"file_size" => 0,
					"data_pos" => 0,
					"data" => ""
				)) : array()),
			);

			foreach ($extra as $key => $val)  $data[$key] = $val;

			$this->objcache[$id] = &$data;

			$this->estram += $data["est_ram"];

			return array("success" => true, "obj" => new IFDS_RefCountObj($data));
		}

		public function CreateRawData($dataencodernum, $name = false)
		{
			return $this->CreateObject(self::TYPE_RAW_DATA, $dataencodernum, $name, false, array());
		}

		public function CreateKeyIDMap($name = false)
		{
			return $this->CreateObject(self::TYPE_RAW_DATA, self::ENCODER_KEY_ID_MAP, $name, false, array());
		}

		public function CreateKeyValueMap($name = false, $withid = true)
		{
			return $this->CreateObject(self::TYPE_RAW_DATA, self::ENCODER_KEY_VALUE_MAP, $name, false, array(), $withid);
		}

		public function CreateFixedArray($entrysize, $name = false, $withid = true, $dataencodernum = self::ENCODER_RAW)
		{
			return $this->CreateObject(self::TYPE_FIXED_ARRAY, $dataencodernum, $name, array("size" => $entrysize, "num" => 0), array(), $withid);
		}

		public function CreateLinkedList($name = false, $streaming = false, $dataencodernum = self::ENCODER_NONE)
		{
			return $this->CreateObject(($streaming ? self::TYPE_LINKED_LIST | self::TYPE_STREAMED : self::TYPE_LINKED_LIST), $dataencodernum, $name, array("num" => 0, "first" => 0, "last" => 0), array());
		}

		public function CreateLinkedListNode($dataencodernum, $name = false)
		{
			return $this->CreateObject(self::TYPE_LINKED_LIST | self::TYPE_LEAF, $dataencodernum, $name, array("prev" => 0, "next" => 0), array());
		}

		public function GetObjectID($obj)
		{
			return $obj->data["id"];
		}

		public function GetObjectBaseType($obj)
		{
			return ($obj->data["type"] & self::TYPE_BASE_MASK);
		}

		public function GetObjectType($obj)
		{
			return $obj->data["type"];
		}

		public function GetObjectTypeStr($obj)
		{
			return $obj->data["type_str"];
		}

		public function GetObjectEncoder($obj)
		{
			return ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM);
		}

		public function GetObjectDataMethod($obj)
		{
			return ($obj->data["enc"] & self::ENCODER_MASK_DATA);
		}

		public function GetObjectDataPos($obj)
		{
			return $obj->data["data_pos"];
		}

		public function GetObjectDataSize($obj)
		{
			return $obj->data["data_size"];
		}

		public function SetManualWriteObject($obj, $enable)
		{
			$obj->data["man"] = (bool)$enable;
		}

		public function IsObjectDataNull($obj)
		{
			return ($obj->data["enc"] === (self::ENCODER_NONE | self::ENCODER_NO_DATA));
		}

		public function IsObjectValid($obj)
		{
			return $obj->data["valid"];
		}

		public function IsObjectModified($obj)
		{
			return ($obj->data["mod"] || $obj->data["data_mod"]);
		}

		public function IsInterleavedObject($obj)
		{
			return (($obj->data["enc"] & IFDS::ENCODER_MASK_DATA) === IFDS::ENCODER_DATA_CHUNKS_STREAM);
		}

		public function IsManualWriteObject($obj)
		{
			return $obj->data["man"];
		}

		protected function ClearLoadedObjectDataChunksInternal(&$data)
		{
			// Assumes that all chunks have been written out.
			foreach ($data["chunks"] as $chunknum => &$cinfo)
			{
				$size = strlen($cinfo["data"]) + 50;

				$data["est_ram"] -= $size;

				$this->estram -= $size;
			}

			$data["chunks"] = array();
		}

		public function SetObjectEncoder($obj, $dataencodernum)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to set object encoder.  File is not open."), "errorcode" => "file_not_open");

			if ($dataencodernum === self::ENCODER_NONE)
			{
				// Store NULL.
				$result = $this->Truncate($obj);
				if (!$result["success"])  return $result;

				$result = $this->ClearObject($obj);
				if (!$result["success"])  return $result;

				$obj->data["enc"] = (self::ENCODER_NONE | self::ENCODER_NO_DATA);
				$this->ClearLoadedObjectDataChunksInternal($obj->data);
			}
			else if ($dataencodernum < self::TYPE_DATA_CHUNKS)
			{
				$obj->data["enc"] = ($obj->data["enc"] & self::ENCODER_MASK_DATA) | $dataencodernum;
				$obj->data["mod"] = true;

				// Reset data if it is NULL.
				if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_NO_DATA)
				{
					$obj->data["chunks"] = array(array(
						"mod" => true,
						"valid" => true,
						"type" => self::DC_DATA_TERM,
						"channel" => false,
						"file_pos" => 0,
						"file_size" => 0,
						"data_pos" => 0,
						"data" => ""
					));

					$obj->data["est_ram"] += 50;

					$this->estram += 50;

					$obj->data["enc"] = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM) | self::ENCODER_INTERNAL_DATA;

					$obj->data["data_mod"] = true;
				}
			}

			return array("success" => true);
		}

		public function ExtractNextStructure(&$data, &$pos, $size, $raw = false)
		{
			if ($pos >= $size)  return array("success" => false, "error" => self::IFDSTranslate("Insufficient data."), "errorcode" => "insufficient_data", "size" => $pos - $size);

			if ($data[$pos] === "\x00")
			{
				$num = 1;
				$pos++;

				while ($pos < $size && $data[$pos] === "\x00")
				{
					$num++;
					$pos++;
				}

				return array("success" => true, "type" => self::TYPE_DELETED, "origtype" => self::TYPE_DELETED, "enc" => self::ENCODER_NONE, "num" => $num, "valid" => true);
			}

			if ($pos + 8 >= $size)  return array("success" => false, "error" => self::IFDSTranslate("Insufficient data."), "errorcode" => "insufficient_data", "size" => $pos + 8 - $size);

			$type = ord($data[$pos]);
			$basetype = $type & self::TYPE_BASE_MASK;

			// Technically invalid according to the specification.
			if ($basetype === 0)
			{
				$data[$pos] = "\x00";

				$num = 1;
				$pos++;

				while ($pos < $size && (ord($data[$pos]) & self::TYPE_BASE_MASK) === 0)
				{
					$data[$pos] = "\x00";

					$num++;
					$pos++;
				}

				return array("success" => true, "type" => self::TYPE_DELETED, "origtype" => $type, "enc" => self::ENCODER_NONE, "num" => $num, "valid" => false);
			}

			$enc = ord($data[$pos + 1]);
			$size2 = unpack("n", substr($data, $pos + 2, 2))[1];

			// DATA CHUNKS.
			if ($basetype === self::TYPE_DATA_CHUNKS)
			{
				if ($type & self::TYPE_STREAMED)
				{
					if ($size2 > 65526)  return array("success" => false, "error" => self::IFDSTranslate("Interleaved DATA chunk too large."), "errorcode" => "data_chunk_too_large");
					if ($pos + $size2 + 10 > $size)  return array("success" => false, "error" => self::IFDSTranslate("Insufficient data."), "errorcode" => "insufficient_data", "size" => $pos + $size2 + 10 - $size);

					if ($enc !== self::DC_DATA)  $enc = self::DC_DATA_TERM;

					$valid = (pack("N", crc32(substr($data, $pos, $size2 + 6))) === substr($data, $pos + $size2 + 6, 4));
					$channel = unpack("n", substr($data, $pos + 4, 2))[1];
					$data2 = substr($data, $pos + 6, $size2);

					$result = array("success" => true, "type" => $basetype, "origtype" => $type, "enc" => $enc, "valid" => $valid, "channel" => $channel, "data" => $data2);

					if ($raw)  $result["raw"] = substr($data, $pos, $size2 + 10);

					$pos += $size2 + 10;

					return $result;
				}
				else if ($enc === self::DC_DATA_LOCATIONS)
				{
					if ($pos + $size2 * 10 + 18 > $size)  return array("success" => false, "error" => self::IFDSTranslate("Insufficient data."), "errorcode" => "insufficient_data", "size" => $pos + $size2 * 10 + 18 - $size);

					$valid = (pack("N", crc32(substr($data, $pos, $size2 * 10 + 14))) === substr($data, $pos + $size2 * 10 + 14, 4));
					$data2 = substr($data, $pos + 4, $size2 * 10 + 10);

					$result = array("success" => true, "type" => $basetype, "origtype" => $type, "enc" => $enc, "valid" => $valid, "entries" => $size2 + 1, "data" => $data2);

					if ($raw)  $result["raw"] = substr($data, $pos, $size2 * 10 + 18);

					$pos += $size2 * 10 + 18;

					return $result;
				}
				else
				{
					if ($size2 > 65528)  return array("success" => false, "error" => self::IFDSTranslate("DATA chunk too large."), "errorcode" => "data_chunk_too_large");
					if ($pos + $size2 + 8 > $size)  return array("success" => false, "error" => self::IFDSTranslate("Insufficient data."), "errorcode" => "insufficient_data", "size" => $pos + $size2 + 8 - $size);

					if ($enc !== self::DC_DATA)  $enc = self::DC_DATA_TERM;

					$valid = (pack("N", crc32(substr($data, $pos, $size2 + 4))) === substr($data, $pos + $size2 + 4, 4));
					$data2 = substr($data, $pos + 4, $size2);

					$result = array("success" => true, "type" => $basetype, "origtype" => $type, "enc" => $enc, "valid" => $valid, "channel" => false, "data" => $data2);

					if ($raw)  $result["raw"] = substr($data, $pos, $size2 + 8);

					$pos += $size2 + 8;

					return $result;
				}
			}

			// Objects.
			if ($size2 > 32767)  return array("success" => false, "error" => self::IFDSTranslate("Structure too large."), "errorcode" => "structure_too_large");
			if ($pos + $size2 + 8 > $size)  return array("success" => false, "error" => self::IFDSTranslate("Insufficient data."), "errorcode" => "insufficient_data", "size" => $pos + $size2 + 8 - $size);

			$id = (($this->fileheader["ifds_features"] & self::FEATURES_NODE_IDS) ? unpack("N", substr($data, $pos + 4, 4))[1] : 0);

			if ($id > 0 && $this->objcache[$id])
			{
				$obj = new IFDS_RefCountObj($this->objcache[$id]);

				$valid = $obj->data["valid"];
			}
			else
			{
				$valid = (pack("N", crc32(substr($data, $pos, $size2 + 4))) === substr($data, $pos + $size2 + 4, 4));

				// Initialize a base object.
				$result = $this->CreateObject($type, ($enc & self::ENCODER_MASK_DATA_NUM), false, array(), array(), false);
				if (!$result["success"])  return $result;

				$obj = $result["obj"];
				$obj->data["mod"] = false;
				$obj->data["enc"] = $enc;
				$obj->data["valid"] = $valid;
				$obj->data["obj_size"] = $size2 + 8;
				$obj->data["data_mod"] = false;
				$obj->data["chunks"] = array();

				if (!($this->fileheader["ifds_features"] & self::FEATURES_NODE_IDS))  $extra = 0;
				else
				{
					if ($id > 0)
					{
						unset($this->objcache[$obj->data["id"]]);

						if ($obj->data["id"] === $this->nextminid + 1)  $this->nextminid++;

						$obj->data["id"] = $id;

						$this->objcache[$id] = &$obj->data;
					}

					$extra = 4;
				}

				$sizeleft = $size2 - $extra;

				if (isset($this->typedecoders[$basetype]) && !call_user_func_array($this->typedecoders[$basetype], array($obj, &$data, $pos + $extra + 4, &$sizeleft)))  return array("success" => false, "error" => self::IFDSTranslate("Type decoder failed.  Insufficient structure size."), "errorcode" => "type_decoder_failed");

				$encmethod = $enc & self::ENCODER_MASK_DATA;

				if ($encmethod === self::ENCODER_INTERNAL_DATA)
				{
					if ($sizeleft < 2)  return array("success" => false, "error" => self::IFDSTranslate("Insufficient structure size."), "errorcode" => "structure_too_small");

					$datasize = unpack("n", substr($data, $pos + 4 + $size2 - 2, 2))[1];
					if ($datasize > $sizeleft - 2)
					{
						$datasize = $sizeleft - 2;

						$valid = false;
					}

					$obj->data["chunks"][] = array(
						"mod" => false,
						"valid" => $valid,
						"type" => self::DC_DATA_TERM,
						"channel" => false,
						"file_pos" => 0,
						"file_size" => 0,
						"data_pos" => 0,
						"data" => substr($data, $pos + $size2 + 2 - $datasize, $datasize)
					);

					$obj->data["data_size"] = strlen($obj->data["chunks"][0]["data"]);

					$obj->data["est_ram"] += $obj->data["data_size"] + 50;

					$this->estram += $obj->data["data_size"] + 50;
				}
			}

			$result = array("success" => true, "type" => $basetype, "origtype" => $type, "enc" => $enc, "valid" => $valid, "obj" => $obj);

			if ($raw)  $result["raw"] = substr($data, $pos, $size2 + 8);

			$pos += $size2 + 8;

			return $result;
		}

		public static function ExtractDataLocationsTable(&$table, &$data)
		{
			$y = strlen($data);

			if ($y % 10 !== 0)  return false;

			$table = array();
			$pos = 0;

			for ($x = 0; $x + 19 < $y; $x += 10)
			{
				$y2 = unpack("n", substr($data, $x, 2))[1];

				if ($y2 > 0)
				{
					$table[] = array(
						"file_pos" => unpack("J", substr($data, $x + 2, 8))[1],
						"file_size" => $y2 * 65536,
						"data_pos" => $pos,
						"data_size" => $y2 * 65528
					);

					$pos += $y2 * 65528;
				}
			}

			$y2 = unpack("n", substr($data, $x, 2))[1];

			$table[] = array(
				"file_pos" => unpack("J", substr($data, $x + 2, 8))[1],
				"file_size" => $y2,
				"data_pos" => $pos,
				"data_size" => $y2 - 8
			);

			return true;
		}

		public function LoadObjectDataTable($obj, &$data, $size)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to load and initialize object data table.  File is not open."), "errorcode" => "file_not_open");

			if ($obj->data["data_tab"] === false)
			{
				$encmethod = $obj->data["enc"] & self::ENCODER_MASK_DATA;

				$filepos = $obj->data["obj_pos"] + $obj->data["obj_size"];

				if ($encmethod === self::ENCODER_DATA_CHUNKS)
				{
					// Load the DATA locations table.
					$result = $this->ReadNextStructure($filepos, $data, $size);
					if (!$result["success"])  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve DATA locations table header.  Read failed."), "errorcode" => "read_data_failed", "info" => $result);

					if ($result["enc"] !== self::DC_DATA_LOCATIONS)  return array("success" => false, "error" => self::IFDSTranslate("DATA locations table expected to follow object.  Read failed."), "errorcode" => "read_data_failed");

					if (!$result["valid"])  $obj->data["valid"] = false;

					if (!self::ExtractDataLocationsTable($obj->data["data_tab"], $result["data"]))  return array("success" => false, "error" => self::IFDSTranslate("The DATA locations table is invalid."), "errorcode" => "invalid_data_locations_table");

					$tinfo = &$obj->data["data_tab"][count($obj->data["data_tab"]) - 1];

					$obj->data["data_tsize"] = $size;
					$obj->data["data_size"] = $tinfo["data_pos"] + $tinfo["data_size"];
				}
				else if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM)
				{
					// Initialize stream reader.
					while (strlen($data) < 4)
					{
						$data2 = $this->ReadDataInternal($filepos, 4 - strlen($data));
						if ($data2 === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve interleaved DATA chunk header.  Read failed."), "errorcode" => "read_data_failed");

						$data .= $data2;
					}

					if ($data[0] !== "\xBF" || ($data[1] !== "\x00" && $data[1] !== "\x01"))  return array("success" => false, "error" => self::IFDSTranslate("Interleaved DATA chunk expected to follow streaming object.  Read failed."), "errorcode" => "read_data_failed");

					$obj->data["data_tab"] = array(
						"pos" => $filepos,
						"data" => $data
					);
				}
			}

			return array("success" => true);
		}

		public function ReadNextStructure(&$filepos, &$data, $size, $raw = false)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read next structure.  File is not open."), "errorcode" => "file_not_open");
			if ($this->fileheader !== false && $filepos < $this->fileheader["size"])  return array("success" => false, "error" => self::IFDSTranslate("Unable to read next structure.  File position is located inside the file header."), "errorcode" => "invalid_filepos");

			if ($size < 8)  $size = 8;

			$y = strlen($data);
			while ($y < $size)
			{
				// Read from the file.
				$data2 = $this->ReadDataInternal($filepos + $y, $size - $y);
				if ($data2 === false)  break;

				$data .= $data2;

				$y = strlen($data);
			}

			$size = $y;

			$pos = 0;
			$result = $this->ExtractNextStructure($data, $pos, $size, $raw);
			if (!$result["success"])
			{
				if ($result["errorcode"] !== "insufficient_data")  return $result;

				// Read the number of bytes needed.
				while ($result["size"] > 0)
				{
					$data2 = $this->ReadDataInternal($filepos + $size, $result["size"]);
					if ($data2 === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read next structure.  Read failed."), "errorcode" => "read_data_failed", "nextsize" => $size + $result["size"]);

					$data .= $data2;

					$y = strlen($data2);

					$size += $y;
					$result["size"] -= $y;
				}

				// Attempt to read the next structure type and size so that the next read operation is more efficient.
				$data2 = $this->ReadDataInternal($filepos + $size, 4);
				if ($data2 !== false)  $data .= $data2;

				$size = strlen($data);

				$result = $this->ExtractNextStructure($data, $pos, $size, $raw);
				if (!$result["success"])  return $result;
			}

			// Update the object position map.
			if ($result["type"] !== self::TYPE_DELETED && $result["type"] !== self::TYPE_DATA_CHUNKS)
			{
				$obj = $result["obj"];
				$obj->data["obj_pos"] = $filepos;

				$this->objposmap[$filepos] = $obj->data["id"];
			}

			$data = substr($data, $pos);
			$filepos += $pos;
			$size -= $pos;

			// Calculate the next size.
			if ($size < 4)  $size = 8;
			else
			{
				$type = ord($data[0]);
				$basetype = $type & self::TYPE_BASE_MASK;

				if ($basetype !== 0)
				{
					$enc = ord($data[1]);
					$size2 = unpack("n", substr($data, 2, 2))[1];

					if ($basetype !== self::TYPE_DATA_CHUNKS)  $size = $size2 + 8;
					else
					{
						if ($type & self::TYPE_STREAMED)  $size = $size2 + 10;
						else if ($enc === self::DC_DATA_LOCATIONS)  $size = $size2 * 10 + 18;
						else  $size = $size2 + 8;
					}
				}
			}

			// Load the DATA table for the object.
			if ($result["type"] !== self::TYPE_DELETED && $result["type"] !== self::TYPE_DATA_CHUNKS)
			{
				$result2 = $this->LoadObjectDataTable($obj, $data, $size);
				if (!$result2["success"])  return $result2;
			}

			$result["nextsize"] = $size;

			return $result;
		}

		public function GetObjectByPosition($filepos, $size = 4092)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve object.  File is not open."), "errorcode" => "file_not_open");

			if (!isset($this->objposmap[$filepos]))
			{
				$filepos2 = $filepos;
				$data = "";

				// Read the size of the object + 4 bytes to pre-read the start of DATA CHUNKS.
				$result = $this->ReadNextStructure($filepos2, $data, $size + 4);
				if (!$result["success"])  return $result;

				if ($result["type"] === self::TYPE_DELETED || $result["type"] === self::TYPE_DATA_CHUNKS)  return array("success" => false, "error" => self::IFDSTranslate("The data at the specified location is not an object.  Read failed."), "errorcode" => "not_an_object");
			}

			return array("success" => true, "obj" => new IFDS_RefCountObj($this->objcache[$this->objposmap[$filepos]]));
		}

		public function GetObjectByID($id, $updatelastaccess = true)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve object.  File is not open."), "errorcode" => "file_not_open");

			$id = (int)$id;

			if ($id < 1)  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve object.  Invalid object ID specified."), "errorcode" => "invalid_id");

			if (!isset($this->objcache[$id]))
			{
				// Find the object in the ID table map.
				$id2 = $id - 1;
				$pagenum = (int)($id2 / 65536);
				$pageid = $id2 % 65536;

				if (!isset($this->idmap->data["entries"][$pagenum]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve object.  Object does not exist."), "errorcode" => "no_object");

				$result = $this->LoadObjectIDTableMap($pagenum);
				if (!$result["success"])  return $result;

				$pageobj = $this->idmap->data["entries"][$pagenum];

				if (!isset($pageobj->data["entries"][$pageid]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve object.  Object does not exist."), "errorcode" => "no_object");

				// Each entry contains position, size, and last updated.
				$entry = &$pageobj->data["entries"][$pageid];

				$filepos = $entry[0];

				if ($filepos === 0 || ($this->fileheader !== false && $filepos < $this->fileheader["size"]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to retrieve object.  Object does not exist."), "errorcode" => "no_object");

				$size = $entry[1];

				// Load the object.
				$result = $this->GetObjectByPosition($filepos, $size);
				if (!$result["success"])  return $result;

				$obj = $result["obj"];

				if (isset($this->objcache[$obj->data["id"]]))
				{
					$data = &$this->objcache[$obj->data["id"]];

					unset($this->objposmap[$data["obj_pos"]]);
					unset($this->objcache[$obj->data["id"]]);

					if ($obj->data["id"] === $this->nextminid + 1)  $this->nextminid++;
				}

				$obj->data["id"] = $id;

				$this->objcache[$id] = &$obj->data;
				$this->objposmap[$obj->data["obj_pos"]] = $id;

				// Update the ID table map to reflect that the object was accessed/retrieved today.
				if ($this->fileheader !== false && $updatelastaccess && ($this->fileheader["ifds_features"] & self::FEATURES_OBJECT_ID_LAST_ACCESS) && $entry[2] < $this->fileheader["date_diff"])
				{
					$entry[2] = $this->fileheader["date_diff"];

					$pageobj->data["data_mod"] = true;

					$this->idmap->data["data_mod"] = true;
				}
			}

			return array("success" => true, "obj" => new IFDS_RefCountObj($this->objcache[$id]));
		}

		public function GetObjectByName($name)
		{
			$id = $this->GetNameMapID($name);
			if ($id === false)  return array("success" => false, "error" => self::IFDSTranslate("Object name does not exist."), "errorcode" => "name_not_found");

			return $this->GetObjectByID($id);
		}

		protected function MoveDataChunksInternal($obj, $srcpos, $srcsize, $destpos)
		{
			$origsrcpos = $srcpos;
			$origsrcsize = $srcsize;
			$origdestpos = $destpos;

			// Move the data.
			while ($srcsize > 0)
			{
				$data = $this->ReadDataInternal($srcpos, ($srcsize >= 65536 ? 65536 : $srcsize));
				if ($data === false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read data chunks during relocation."), "errorcode" => "move_read_failed");

				if (!$this->WriteDataInternal($data, $destpos))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data chunks during relocation."), "errorcode" => "move_write_failed");

				$y = strlen($data);
				$data = str_repeat("\x00", $y);

				if (!$this->WriteDataInternal($data, $destpos))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write zero data during data chunks relocation."), "errorcode" => "move_free_bytes_failed");

				$srcpos += $y;
				$srcsize -= $y;

				$destpos += $y;
			}

			// Update loaded chunks in the object.
			foreach ($obj->data["chunks"] as $chunknum => &$cinfo)
			{
				if ($cinfo["file_pos"] >= $origsrcpos && $cinfo["file_pos"] + $cinfo["file_size"] <= $origsrcpos + $origsrcsize)
				{
					$cinfo["file_pos"] = $cinfo["file_pos"] - $origsrcpos + $origdestpos;
				}
			}

			return array("success" => true);
		}

		protected function MergeDownObjectDataChunks($obj, $newtableentries)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to merge down object data chunks.  File is not open."), "errorcode" => "file_not_open");
			if (!($obj instanceof IFDS_RefCountObj))  return array("success" => false, "error" => self::IFDSTranslate("Unable to merge down object data chunks.  Object is not valid."), "errorcode" => "object_not_valid");
			if (($obj->data["enc"] & self::ENCODER_MASK_DATA) !== self::ENCODER_DATA_CHUNKS)  return array("success" => false, "error" => self::IFDSTranslate("Unable to merge down object data chunks.  Object is not using a DATA locations table."), "errorcode" => "object_not_using_data_locations");

			if ($obj->data["data_tab"] !== false)
			{
				// A merge down minimally occurs around every 4.2GB.
				// Realistically though, only objects vastly exceeding 4.2GB will experience merge down operations and will probably only happen very rarely.
				if (count($obj->data["data_tab"]) + $newtableentries > 65536)
				{
					$tnum = 0;
					$y = count($obj->data["data_tab"]) - 1;
					if ($y >= 65535)  $y = 65534;
					$numleft = $y + $newtableentries - 52268;

					while ($tnum < $y && $numleft > 0)
					{
						$tinfo = &$obj->data["data_tab"][$tnum];

						// If the current entry is less than the max size, merge the data in the next entries into the current entry.
						if ($tinfo["file_size"] < 65535 * 65536)
						{
							$pos = $this->ReserveBytesInternal(65535 * 65536);

							$result = $this->MoveDataChunksInternal($obj, $tinfo["file_pos"], $tinfo["file_size"], $pos);
							if (!$result["success"])  return $result;

							$tinfo["file_pos"] = $pos;

							$pos += $tinfo["file_size"];
							$spaceleft = 65535 * 65536 - $tinfo["file_size"];

							// Merge the next set of DATA chunks until full or none are left.
							while ($tnum + 1 < $y && $numleft > 0 && $spaceleft > 0)
							{
								$tinfo2 = &$obj->data["data_tab"][$tnum + 1];

								if ($spaceleft >= $tinfo2["file_size"])
								{
									// There is plenty of space to move the entire next entry into the current entry.
									$result = $this->MoveDataChunksInternal($obj, $tinfo2["file_pos"], $tinfo2["file_size"], $pos);
									if (!$result["success"])  return $result;

									$tinfo["file_size"] += $tinfo2["file_size"];
									$tinfo["data_size"] += $tinfo2["data_size"];

									$pos += $tinfo2["file_size"];
									$spaceleft -= $tinfo2["file_size"];

									array_splice($obj->data["data_tab"], $tnum + 1, 1);
									$tinfo = &$obj->data["data_tab"][$tnum];

									if ($obj->data["data_tnum"] > $tnum)  $obj->data["data_tnum"]--;

									$y--;
									$numleft--;
								}
								else
								{
									// Move part of the next entry into the current entry.
									$result = $this->MoveDataChunksInternal($obj, $tinfo2["file_pos"], $spaceleft, $pos);
									if (!$result["success"])  return $result;

									$datadiff = (int)($spaceleft / 65536) * 65528;

									$tinfo["file_size"] += $spaceleft;
									$tinfo["data_size"] += $datadiff;

									$tinfo2["file_pos"] += $spaceleft;
									$tinfo2["file_size"] -= $spaceleft;
									$tinfo2["data_pos"] += $datadiff;
									$tinfo2["data_size"] -= $datadiff;

									$spaceleft = 0;
								}
							}

							if ($spaceleft > 0)
							{
								$result = $this->FreeBytesInternal($pos, $spaceleft);
								if (!$result["success"])  return $result;
							}
						}

						$obj->data["mod"] = true;

						$tnum++;
					}

					if (count($obj->data["data_tab"]) + $newtableentries > 65536)  return array("success" => false, "error" => self::IFDSTranslate("Unable to fulfill merge down request.  Too many new table entries."), "errorcode" => "merge_down_failed");
				}
			}

			return array("success" => true);
		}

		protected function WriteObjectDataLocationsTable($obj)
		{
			$y = ($obj->data["data_tab"] !== false ? count($obj->data["data_tab"]) : 0);

			if ($y > 65536)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write the DATA locations table.  Too many entries."), "errorcode" => "write_data_locations_table_failed");

			// Generate DATA locations table.
			$data = "\x3F\x02";
			$data .= pack("n", (int)(($obj->data["data_tsize"] - 18) / 10));

			for ($x = 0; $x < $y - 1; $x++)
			{
				$tinfo = &$obj->data["data_tab"][$x];

				$data .= pack("n", $tinfo["file_size"] / 65536);
				$data .= pack("J", $tinfo["file_pos"]);
			}

			// Add padding to fill extra space.
			if (strlen($data) + 14 < $obj->data["data_tsize"])  $data .= str_repeat("\x00", $obj->data["data_tsize"] - strlen($data) - 14);

			// Append the last entry.
			if ($y < 1)  $data .= "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
			else
			{
				$tinfo = &$obj->data["data_tab"][$y - 1];

				$data .= pack("n", $tinfo["file_size"]);
				$data .= pack("J", $tinfo["file_pos"]);
			}

			// Append CRC-32.
			$data .= pack("N", crc32($data));

			if (strlen($data) !== $obj->data["data_tsize"])  return array("success" => false, "error" => self::IFDSTranslate("Unable to write the DATA locations table.  Invalid size detected."), "errorcode" => "write_data_locations_table_failed");

			// Write the DATA locations table.
			if (!$this->WriteDataInternal($data, $obj->data["obj_pos"] + $obj->data["obj_size"]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write the DATA locations table."), "errorcode" => "write_data_locations_table_failed");

			return array("success" => true);
		}

		public function WriteObject($obj)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write object.  File is not open."), "errorcode" => "file_not_open");
			if (!($obj instanceof IFDS_RefCountObj))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write object.  Object is not valid."), "errorcode" => "object_not_valid");

			$result = $this->ProcessVarData();
			if (!$result["success"])  return $result;

			if ($this->vardata !== false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write object.  Streaming object data is being written."), "errorcode" => "write_conflict");

			$encmethod = $obj->data["enc"] & self::ENCODER_MASK_DATA;

			// Calculate the DATA locations table size.
			if ($encmethod === self::ENCODER_DATA_CHUNKS)
			{
				$y = 18;

				// If data has already been output, then attempt to append the rest of the data.
				if ($obj->data["data_tab"] !== false)
				{
					// Write and release data.
					$result = $this->FlushObjectDataChunks($obj->data, ($this->fileheader === false || $obj->data["obj_pos"] < $this->fileheader["size"]));
					if (!$result["success"])  return $result;
				}

				// Get the number of newly full DATA chunks.
				$numchunks = 0;
				$minchunksize = 0;
				foreach ($obj->data["chunks"] as $chunknum => &$cinfo)
				{
					$y2 = strlen($cinfo["data"]);

					if ($cinfo["file_size"] < $y2 + 8)
					{
						if ($y2 >= 65528)  $numchunks++;
						else  $minchunksize = $y2 + 8;
					}
				}

				$newtableentries = (int)($numchunks / 65535) + 1;

				if ($obj->data["data_tab"] !== false)
				{
					// Merge blocks to make space in the DATA locations table as needed.
					if ($numchunks > 0)
					{
						$result = $this->MergeDownObjectDataChunks($obj, $newtableentries);
						if (!$result["success"])  return $result;
					}

					$y += (count($obj->data["data_tab"]) - 1) * 10;
				}

				if ($numchunks > 0)  $y += $newtableentries * 10;

				// Time to find a new home for the object and the DATA locations table.
				if ($obj->data["data_tsize"] < $y)
				{
					$result = $this->ClearObject($obj);
					if (!$result["success"])  return $result;

					$obj->data["data_tsize"] = $y;

					$obj->data["mod"] = true;
				}
			}
			else if ($encmethod === self::ENCODER_INTERNAL_DATA)
			{
				if ($obj->data["data_mod"])  $obj->data["mod"] = true;
			}

			// Only write the object if it has been modified.
			if ($obj->data["mod"])
			{
				$data = chr($obj->data["type"]) . chr($obj->data["enc"]);

				$basetype = $obj->data["type"] & self::TYPE_BASE_MASK;
				$data2 = (isset($this->typeencoders[$basetype]) ? call_user_func_array($this->typeencoders[$basetype], array($obj)) : "");

				// Calculate main object size.
				$y = strlen($data2);
				if ($this->fileheader["ifds_features"] & self::FEATURES_NODE_IDS)  $y += 4;
				if ($encmethod === self::ENCODER_INTERNAL_DATA)  $y += $obj->data["data_size"] + 2;

				if ($y + 8 < $obj->data["obj_size"])  $y = $obj->data["obj_size"] - 8;
				if ($y > 32763)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write object.  Object is too large.  Something went wrong."), "errorcode" => "object_too_large");

				// Append object data.
				$data .= pack("n", $y);
				if ($this->fileheader["ifds_features"] & self::FEATURES_NODE_IDS)  $data .= pack("N", ($obj->data["id"] > 0 ? $obj->data["id"] : 0));
				$data .= $data2;

				// Append internal data.
				if ($encmethod === self::ENCODER_INTERNAL_DATA)
				{
					$y2 = $y + 4 - strlen($data) - $obj->data["data_size"] - 2;
					if ($y2 > 0)  $data .= str_repeat("\x00", $y2);

					$data .= $obj->data["chunks"][0]["data"];
					$data .= pack("n", $obj->data["data_size"]);
				}
				else
				{
					// Apply object padding.
					$y2 = $y + 4 - strlen($data);
					if ($y2 > 0)  $data .= str_repeat("\x00", $y2);
				}

				// Append CRC-32.
				$data .= pack("N", crc32($data));

				$y = strlen($data);

				// Move the object as needed.
				if ($obj->data["obj_size"] < $y)
				{
					if ($obj->data["obj_pos"] > 0)
					{
						$result = $this->ClearObject($obj);
						if (!$result["success"])  return $result;
					}

					// Find an open position to store this object.
					if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM)
					{
						if ($this->CanWriteDataInternal($obj->data))  $obj->data["obj_pos"] = $this->maxpos;
						else
						{
							$obj->data["obj_pos"] = $this->ReserveBytesInternal($y + $obj->data["data_size"] + $obj->data["chunk_num"] * 10);

							$pos = $obj->data["obj_pos"] + $y;

							// Calculate new DATA chunks storage positions.
							foreach ($obj->data["chunks"] as $chunknum => &$cinfo)
							{
								$size = strlen($cinfo["data"]) + 10;

								$cinfo["file_pos"] = $pos;
								$cinfo["file_size"] = $size;

								$pos += $size;
							}
						}
					}
					else
					{
						$obj->data["obj_pos"] = $this->ReserveBytesInternal($y + $obj->data["data_tsize"]);
					}

					$this->objposmap[$obj->data["obj_pos"]] = $obj->data["id"];

					$obj->data["obj_size"] = $y;

//echo $obj->data["id"] . ":  Type = " . $obj->data["type_str"] . ", New pos = " . $obj->data["obj_pos"] . ", New size = " . $obj->data["obj_size"] . "\n";

					// Update the position in the object ID map.
					if ($obj->data["id"] > 0)
					{
						$id = $obj->data["id"];

						$id2 = $id - 1;
						$pagenum = (int)($id2 / 65536);
						$pageid = $id2 % 65536;

						if (isset($this->idmap->data["entries"][$pagenum]))
						{
							$result = $this->LoadObjectIDTableMap($pagenum);
							if (!$result["success"])  return $result;

							$pageobj = $this->idmap->data["entries"][$pagenum];

							if (isset($pageobj->data["entries"][$pageid]))
							{
								$entry = &$pageobj->data["entries"][$pageid];

								$entry[0] = $obj->data["obj_pos"];
								$entry[1] = $obj->data["obj_size"];

								if ($this->fileheader !== false)  $entry[2] = $this->fileheader["date_diff"];

								$pageobj->data["data_mod"] = true;

								$this->idmap->data["data_mod"] = true;
							}
						}
					}
				}

//debug_print_backtrace();
//echo $obj->data["id"] . ":  Type = " . $obj->data["type_str"] . ", Pos = " . $obj->data["obj_pos"] . ", Size = " . $obj->data["obj_size"] . " (" . strlen($data) . ")\n";

				// Write the object.
				if (!$this->WriteDataInternal($data, $obj->data["obj_pos"]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write the object."), "errorcode" => "write_object_failed");

				// Calculate new DATA chunks storage positions.
				if ($encmethod === self::ENCODER_DATA_CHUNKS)
				{
					if ($numchunks + $minchunksize > 0)
					{
						$remappos = $obj->data["obj_pos"] + $obj->data["obj_size"];

						// Reserve sufficient space to hold the DATA chunks.  Remapping only happens when at the end of the file.
						$pos = $this->ReserveBytesInternal(65536 * $numchunks + $minchunksize, $remappos + $obj->data["data_tsize"]);
						if ($pos === $remappos)  $pos = $remappos + $obj->data["data_tsize"];

						foreach ($obj->data["chunks"] as $chunknum => &$cinfo)
						{
							$y2 = strlen($cinfo["data"]);

							// Sanity check.
							if ($y2 > 65528)
							{
								$cinfo["data"] = substr($cinfo["data"], 0, 65528);

								$y2 = 65528;
							}

							if ($cinfo["file_size"] < $y2 + 8)
							{
								// Free partial chunk from a previous write.
								if ($cinfo["file_size"] > 0)
								{
									$result = $this->FreeBytesInternal($cinfo["file_pos"], $cinfo["file_size"]);
									if (!$result["success"])  return $result;
								}

								if ($y2 >= 65528)
								{
									if ($obj->data["data_tab"] === false || count($obj->data["data_tab"]) == 1)
									{
										$obj->data["data_tab"] = array(
											array("file_pos" => $pos, "file_size" => 0, "data_pos" => 0, "data_size" => 0),
											array("file_pos" => 0, "file_size" => 0, "data_pos" => 0, "data_size" => 0)
										);
									}

									$tnum = count($obj->data["data_tab"]) - 2;
									$tinfo = &$obj->data["data_tab"][$tnum];

									// If the last DATA locations table entry the position isn't valid or is full, insert a new entry.
									if ($tinfo["file_pos"] + $tinfo["file_size"] !== $pos || $tinfo["file_size"] >= 65535 * 65536)
									{
										$tempentry = array_pop($obj->data["data_tab"]);

										$obj->data["data_tab"][] = array("file_pos" => $pos, "file_size" => 0, "data_pos" => $cinfo["data_pos"], "data_size" => 0);
										$obj->data["data_tab"][] = $tempentry;

										$tnum = count($obj->data["data_tab"]) - 2;
										$tinfo = &$obj->data["data_tab"][$tnum];
									}

									$tinfo["file_size"] += 65536;
									$tinfo["data_size"] += 65528;

									$cinfo["file_pos"] = $pos;
									$cinfo["file_size"] = 65536;

									$pos += 65536;
								}
								else
								{
									if ($obj->data["data_tab"] === false)  $obj->data["data_tab"] = array();
									else  array_pop($obj->data["data_tab"]);

									$obj->data["data_tab"][] = array("file_pos" => $pos, "file_size" => $y2 + 8, "data_pos" => $cinfo["data_pos"], "data_size" => $y2);

									$cinfo["file_pos"] = $pos;
									$cinfo["file_size"] = $y2 + 8;

									$pos += $y2 + 8;
								}
							}
						}
					}

					// Write the DATA locations table.
					$result = $this->WriteObjectDataLocationsTable($obj);
					if (!$result["success"])  return $result;

					// Write and release data.
					$result = $this->FlushObjectDataChunks($obj->data, true);
					if (!$result["success"])  return $result;

					// Update the DATA locations table position.
					$result = $this->Seek($obj, $obj->data["data_pos"]);
					if (!$result["success"])  return $result;
				}

				$obj->data["mod"] = false;

				if ($encmethod === self::ENCODER_INTERNAL_DATA)  $obj->data["data_mod"] = false;
			}

			// If this is a streaming, interleaved multi-channel object, deny access to this function until all data for the stream has been written.
			if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				$this->vardata = $obj->data;

				$result = $this->ProcessVarData();
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function Seek($obj, $pos)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to seek.  File is not open."), "errorcode" => "file_not_open");

			if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				// Deny seeking while writing the data.  Seeking inside streamed data is mostly discouraged anyway since it doesn't make much sense.
				if ($this->CanWriteDataInternal($obj->data))  return array("success" => false, "error" => self::IFDSTranslate("Streaming object data has not been fully written."), "errorcode" => "object_not_written");

				// If past the desired the position in the stream, rewind to the beginning of the data.
				if ($obj->data["data_pos"] > $pos)
				{
					if ($obj->data["obj_pos"] === 0 || ($this->fileheader !== false && $obj->data["obj_pos"] < $this->fileheader["size"]))  return array("success" => false, "error" => self::IFDSTranslate("Unable to seek.  Invalid object position.  Has the object been written?"), "errorcode" => "invalid_object_position");

					$filepos = $obj->data["obj_pos"] + $obj->data["obj_size"];

					$data = $this->ReadDataInternal($filepos, 4);
					if ($data === false || strlen($data) != 4)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read chunk header data."), "errorcode" => "chunk_header_load_failed");

					$obj->data["data_tab"] = array(
						"pos" => $filepos,
						"data" => $data
					);

					$obj->data["data_pos"] = 0;
					$obj->data["chunk_num"] = 0;
					$this->ClearLoadedObjectDataChunksInternal($obj->data);
				}

				// Seek forward by reading the data up to the desired position.
				while ($obj->data["data_pos"] < $pos)
				{
					$result = $this->ReadData($obj, $pos - $obj->data["data_pos"]);
					if (!$result["success"])  return $result;

					if ($result["end"] && $result["channel"] === 0)  break;
				}
			}
			else
			{
				if ($pos > $obj->data["data_size"])  $pos = $obj->data["data_size"];

				if ($obj->data["data_tab"] === false)  $obj->data["data_tnum"] = 0;
				else
				{
					$y = count($obj->data["data_tab"]);

					for ($x = 0; $x < $y && $pos < $obj->data["data_tab"][$x]["data_pos"]; $x++);

					$obj->data["data_tnum"] = $x;
				}

				$obj->data["chunk_num"] = (int)($pos / 65528);

				$obj->data["data_pos"] = $pos;
			}

			return array("success" => true);
		}

		protected function ExtractDataChunk($obj, $chunknum, $filepos, $filesize, $datapos, &$data)
		{
			if ($filesize > 65536)  return false;

			// Sanity check the first few bytes and then extract the data.
			if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				if ($data[0] !== "\xBF" || ($data[1] !== "\x00" && $data[1] !== "\x01") || substr($data, 2, 2) !== pack("n", $filesize - 10))  return false;

				$chunk = array(
					"mod" => false,
					"valid" => (pack("N", crc32(substr($data, 0, -4))) === substr($data, -4)),
					"type" => ($data[1] === "\x00" ? self::DC_DATA : self::DC_DATA_TERM),
					"channel" => unpack("n", substr($data, 4, 2))[1],
					"file_pos" => $filepos,
					"file_size" => $filesize,
					"data_pos" => $datapos,
					"data" => substr($data, 6, -4)
				);
			}
			else
			{
				if ($data[0] !== "\x3F" || ($data[1] !== "\x00" && $data[1] !== "\x01") || substr($data, 2, 2) !== pack("n", $filesize - 8))  return false;

				$chunk = array(
					"mod" => false,
					"valid" => (pack("N", crc32(substr($data, 0, -4))) === substr($data, -4)),
					"type" => ($data[1] === "\x00" ? self::DC_DATA : self::DC_DATA_TERM),
					"channel" => false,
					"file_pos" => $filepos,
					"file_size" => $filesize,
					"data_pos" => $datapos,
					"data" => substr($data, 4, -4)
				);
			}

			$size = strlen($chunk["data"]) + 50;

			$obj->data["chunks"][$chunknum] = $chunk;
			$obj->data["est_ram"] += $size;

			$this->estram += $size;

			return true;
		}

		protected function LoadCurrLocationTableChunk($obj)
		{
			if ($obj->data["data_tab"] === false)  return false;

			// Locate the chunk in the file using the locations table info.
			$tinfo = &$obj->data["data_tab"][$obj->data["data_tnum"]];

			$filepos = $tinfo["file_pos"] + ((int)(($obj->data["data_pos"] - $tinfo["data_pos"]) / 65528) * 65536);

			$size = ($obj->data["data_tnum"] < count($obj->data["data_tab"]) - 1 ? 65536 : $tinfo["file_size"]);
			if ($size < 8)  return false;

			// Load and extract the chunk.
			$data = $this->ReadDataInternal($filepos, $size);
			if ($data === false || strlen($data) != $size)  return false;

			return $this->ExtractDataChunk($obj, $obj->data["chunk_num"], $filepos, $size, $obj->data["data_pos"] - $obj->data["data_pos"] % 65528, $data);
		}

		public function ReadData($obj, $size = -1, $channel = false)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data.  File is not open."), "errorcode" => "file_not_open");

			if ($obj->data["enc"] === 0)  return array("success" => true, "data" => null, "channel" => false, "end" => true);
			else if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				// Interleaved multi-channel data mode.
				do
				{
					if (isset($obj->data["chunks"][$obj->data["chunk_num"] - 1]) && $obj->data["chunks"][$obj->data["chunk_num"] - 1]["type"] === self::DC_DATA_TERM && $obj->data["chunks"][$obj->data["chunk_num"] - 1]["channel"] === 0)  return array("success" => true, "data" => "", "channel" => 0, "end" => true);

					// Load and extract chunk.
					if (!isset($obj->data["chunks"][$obj->data["chunk_num"]]))
					{
						if ($obj->data["data_tab"] === false)  return array("success" => false, "error" => self::IFDSTranslate("No chunk header data.  Possible data corruption detected."), "errorcode" => "no_chunk_header");

						$filepos = $obj->data["data_tab"]["pos"];
						$data = $obj->data["data_tab"]["data"];

						$size = unpack("n", substr($data, 2, 2))[1];
						if ($size > 65526)  return array("success" => false, "error" => self::IFDSTranslate("Invalid chunk size."), "errorcode" => "invalid_chunk_size");

						$data2 = $this->ReadDataInternal($filepos + 4, $size + 10);
						if ($data2 === false || strlen($data2) < $size + 6)  return array("success" => false, "error" => self::IFDSTranslate("Unable to read chunk data."), "errorcode" => "chunk_load_failed");

						$data .= substr($data2, 0, $size + 6);

						if (!$this->ExtractDataChunk($obj, $obj->data["chunk_num"], $filepos, $size + 10, $obj->data["data_pos"], $data))  return array("success" => false, "error" => self::IFDSTranslate("Unable to extract chunk data."), "errorcode" => "data_extraction_failed");

						if ($obj->data["data_size"] < $obj->data["data_pos"] + $size)  $obj->data["data_size"] = $obj->data["data_pos"] + $size;

						$data2 = substr($data2, $size + 6);
						if (strlen($data2) < 4 || $data2[0] !== "\xBF" || ($data2[1] !== "\x00" && $data2[1] !== "\x01"))  $obj->data["data_tab"] = false;
						else
						{
							$obj->data["data_tab"]["pos"] += $size + 10;
							$obj->data["data_tab"]["data"] = $data2;
						}
					}

					$result = array("success" => true, "data" => "");
					$y = 0;

					// Copy data.
					$cinfo = &$obj->data["chunks"][$obj->data["chunk_num"]];

					$x2 = $obj->data["data_pos"] - $cinfo["data_pos"];
					$y2 = strlen($cinfo["data"]);
					if ($channel === false || $cinfo["channel"] === $channel)
					{
						while ($x2 < $y2 && ($size < 0 || $y < $size))
						{
							$result["data"] .= $cinfo["data"][$x2];
							$y++;

							$x2++;
							$obj->data["data_pos"]++;
						}

						$result["channel"] = $cinfo["channel"];
						$result["end"] = ($cinfo["type"] === self::DC_DATA_TERM);
						$result["valid"] = $cinfo["valid"];
					}
					else
					{
						$obj->data["data_pos"] += $y2 - $x2;
						$x2 = $y2;
					}

					if ($x2 >= $y2)
					{
						if ($cinfo["channel"] !== 0 || $cinfo["type"] !== self::DC_DATA_TERM)
						{
							$size = strlen($cinfo["data"]) + 50;

							$obj->data["est_ram"] -= $size;

							$this->estram -= $size;

							unset($obj->data["chunks"][$obj->data["chunk_num"]]);
						}

						$obj->data["chunk_num"]++;
					}
				} while ($channel !== false && $cinfo["channel"] !== $channel);
			}
			else
			{
				// Seekable data mode.
				$result = array("success" => true, "data" => "", "channel" => false, "end" => false, "valid" => true);
				$x = 0;

				while ($obj->data["data_pos"] < $obj->data["data_size"] && ($size < 0 || $x < $size))
				{
					// Load chunk.
					if (!isset($obj->data["chunks"][$obj->data["chunk_num"]]) && !$this->LoadCurrLocationTableChunk($obj))  return array("success" => false, "error" => self::IFDSTranslate("Unable to load the current object data chunk for reading.  Possible data corruption."), "errorcode" => "data_chunk_load_failed", "info" => $obj);

					// Copy data.
					$cinfo = &$obj->data["chunks"][$obj->data["chunk_num"]];

					if (!$cinfo["valid"])  $result["valid"] = false;

					$x2 = $obj->data["data_pos"] - $cinfo["data_pos"];
					$y2 = strlen($cinfo["data"]);
					$diff = $y2 - $x2;
					if ($size < 0 || $diff <= $size - $x)  $result["data"] .= ($x2 === 0 ? $cinfo["data"] : substr($cinfo["data"], $x2));
					else
					{
						$diff = $size - $x;

						$result["data"] .= substr($cinfo["data"], $x2, $diff);
					}

					$x += $diff;
					$x2 += $diff;
					$obj->data["data_pos"] += $diff;

					// Move to the next chunk.
					if ($x2 >= 65528)
					{
						$obj->data["chunk_num"]++;

						if ($obj->data["data_tab"] !== false && isset($obj->data["data_tab"][$obj->data["data_tnum"]]))
						{
							$tinfo = &$obj->data["data_tab"][$obj->data["data_tnum"]];

							if ($obj->data["data_pos"] - $tinfo["data_pos"] >= $tinfo["data_size"])  $obj->data["data_tnum"]++;
						}
					}
				}

				if ($obj->data["data_pos"] >= $obj->data["data_size"])  $result["end"] = true;
			}

			$this->ReduceObjectCache();

			return $result;
		}

		protected function CanWriteDataInternal(&$data)
		{
			if ($data["enc"] === 0)  return false;

			if (($data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				if ($data["data_pos"] < $data["data_size"] || $data["data_tab"] !== false)  return false;
				if (isset($data["chunks"][$data["chunk_num"] - 1]) && $data["chunks"][$data["chunk_num"] - 1]["type"] === self::DC_DATA_TERM && $data["chunks"][$data["chunk_num"] - 1]["channel"] === 0)  return false;
			}

			return true;
		}

		public function CanWriteData($obj)
		{
			return $this->CanWriteDataInternal($obj->data);
		}

		public function WriteData($obj, $data, $channel = false, $final = false)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data.  File is not open."), "errorcode" => "file_not_open");

			$this->ReduceObjectCache();

			if ($data === null && $final)
			{
				// Store NULL.
				$result = $this->Truncate($obj);
				if (!$result["success"])  return $result;

				$result = $this->ClearObject($obj);
				if (!$result["success"])  return $result;

				$obj->data["enc"] = (self::ENCODER_NONE | self::ENCODER_NO_DATA);
				$this->ClearLoadedObjectDataChunksInternal($obj->data);
			}
			else if ($channel === false)
			{
				// Seekable data.
				if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write interleaved data.  Channel must be specified."), "errorcode" => "missing_channel");
				if ($obj->data["enc"] === 0)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data.  Object encoding set to NULL data."), "errorcode" => "invalid_encoding");

				$data = (string)$data;
				$x = 0;
				$y = strlen($data);
				while ($x < $y)
				{
					// Load chunk.
					if (!isset($obj->data["chunks"][$obj->data["chunk_num"]]) && !$this->LoadCurrLocationTableChunk($obj))  return array("success" => false, "error" => self::IFDSTranslate("Unable to load the current object data chunk for writing.  Possible data corruption."), "errorcode" => "data_chunk_load_failed", "info" => $obj);

					$cinfo = &$obj->data["chunks"][$obj->data["chunk_num"]];

					// Copy data.
					$x2 = $obj->data["data_pos"] - $cinfo["data_pos"];
					$y2 = strlen($cinfo["data"]);
					$diff = $y2 - $x2;
					if ($diff <= $y - $x)
					{
						// Overwrite to the end of the data.
						$cinfo["data"] = ($x2 === 0 ? "" : substr($cinfo["data"], 0, $x2));
						$cinfo["data"] .= substr($data, $x, $diff);

						$x += $diff;
						$x2 = $y2;
						$obj->data["data_pos"] += $diff;
					}
					else if ($x2 === 0)
					{
						// Overwrite the beginning of the data.
						$diff = $y - $x;
						$tempdata = substr($cinfo["data"], $y - $x);
						$cinfo["data"] = ($x == 0 ? $data : substr($data, $x));
						$cinfo["data"] .= $tempdata;

						$x = $y;
						$x2 += $diff;
						$obj->data["data_pos"] += $diff;
					}
					else
					{
						// PHP is very slow when overwriting one byte at a time in a string.
						while ($x2 < $y2 && $x < $y)
						{
							$cinfo["data"][$x2] = $data[$x];

							$x++;
							$x2++;
							$obj->data["data_pos"]++;
						}
					}

					// PHP is a lot faster at concatenating new data to a string.
					if ($y2 < 65528 && $x < $y)
					{
						$size = (65528 - $y2 < $y - $x ? 65528 - $y2 : $y - $x);
						$cinfo["data"] .= ($x == 0 && $size == $y ? $data : substr($data, $x, $size));

						$x += $size;
						$x2 += $size;
						$obj->data["data_pos"] += $size;
					}
*/

/*
					// Copy data.
					$x2 = $obj->data["data_pos"] % 65528;
					$y2 = strlen($cinfo["data"]);
					$diff = 65528 - $x2;
					$size = ($diff <= $y - $x ? $diff : $y - $x);
					$size2 = ($x2 + $size > $y2 ? $y2 - $x2 : $size);

					str_splice($cinfo["data"], $x2, $size2, $data, $x, $size);

					$x += $size;
					$x2 += $size;
					$obj->data["data_pos"] += $size;
*/

					$obj->data["data_mod"] = true;

					$cinfo["mod"] = true;

					if ($obj->data["data_pos"] > $obj->data["data_size"])
					{
						$diff = $obj->data["data_pos"] - $obj->data["data_size"];

						$obj->data["est_ram"] += $diff;
						$this->estram += $diff;

						$obj->data["data_size"] = $obj->data["data_pos"];

						// If using INTERNAL DATA and data gets larger than 3K, switch to DATA CHUNKS.
						if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_INTERNAL_DATA && $obj->data["data_size"] > 3072)
						{
							$result = $this->ClearObject($obj);
							if (!$result["success"])  return $result;

							$obj->data["enc"] = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM) | self::ENCODER_DATA_CHUNKS;
							$obj->data["mod"] = true;
						}
					}

					// Move to the next chunk.
					if ($x2 >= 65528)
					{
						$obj->data["chunk_num"]++;

						if ($obj->data["data_tab"] !== false && isset($obj->data["data_tab"][$obj->data["data_tnum"]]))
						{
							$tinfo = &$obj->data["data_tab"][$obj->data["data_tnum"]];

							if ($obj->data["data_pos"] - $tinfo["data_pos"] >= $tinfo["data_size"])  $obj->data["data_tnum"]++;
						}

						if ($cinfo["type"] === self::DC_DATA_TERM)
						{
							$cinfo["type"] = self::DC_DATA;

							$obj->data["chunks"][$obj->data["chunk_num"]] = array(
								"mod" => true,
								"valid" => true,
								"type" => self::DC_DATA_TERM,
								"channel" => false,
								"file_pos" => 0,
								"file_size" => 0,
								"data_pos" => $obj->data["data_pos"],
								"data" => ""
							);

							$obj->data["est_ram"] += 50;
							$this->estram += 50;
						}
					}
				}
			}
			else
			{
				// Interleaved multi-channel data mode.
				if ($obj->data["enc"] === 0)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data.  Object encoding set to NULL data."), "errorcode" => "invalid_encoding");

				// Enable interleaved mode as long as no data has been written.
				if (($obj->data["enc"] & self::ENCODER_MASK_DATA) !== self::ENCODER_DATA_CHUNKS_STREAM)
				{
					if ($obj->data["data_size"] > 0)  return array("success" => false, "error" => self::IFDSTranslate("Unable to switch to interleaved data mode.  Object has already been written to."), "errorcode" => "data_already_output");

					$result = $this->ClearObject($obj);
					if (!$result["success"])  return $result;

					$obj->data["enc"] = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM) | self::ENCODER_DATA_CHUNKS_STREAM;
					$obj->data["data_tab"] = false;
					$obj->data["data_tsize"] = 0;
					$this->ClearLoadedObjectDataChunksInternal($obj->data);
				}

				if ($obj->data["data_pos"] < $obj->data["data_size"] || $obj->data["data_tab"] !== false)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data.  Object position is not at the end of the data."), "errorcode" => "invalid_data_pos");
				if (isset($obj->data["chunks"][$obj->data["chunk_num"] - 1]) && $obj->data["chunks"][$obj->data["chunk_num"] - 1]["type"] === self::DC_DATA_TERM && $obj->data["chunks"][$obj->data["chunk_num"] - 1]["channel"] === 0)  return array("success" => false, "error" => self::IFDSTranslate("Unable to write data.  Data stream already terminated."), "errorcode" => "stream_terminated");

				$data = (string)$data;
				$y = strlen($data);
				$size = strlen($data);
				for ($x = 0; $x < $y; $x += 65526)
				{
					$obj->data["chunks"][$obj->data["chunk_num"]] = array(
						"mod" => true,
						"valid" => true,
						"type" => ($final && $x + 65526 >= $y ? self::DC_DATA_TERM : self::DC_DATA),
						"channel" => $channel,
						"file_pos" => 0,
						"file_size" => 0,
						"data_pos" => $obj->data["data_pos"],
						"data" => substr($data, $x, 65526)
					);

					$obj->data["data_pos"] += ($x + 65526 < $y ? 65526 : $y - $x);

					$size += 50;

					$obj->data["chunk_num"]++;
				}

				$obj->data["data_size"] = $obj->data["data_pos"];
				$obj->data["est_ram"] += $size;

				$this->estram += $size;
			}

			return array("success" => true);
		}

		public function Truncate($obj, $newsize = 0)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to truncate data.  File is not open."), "errorcode" => "file_not_open");

			if ($newsize < 0)  $newsize = 0;
			if ($obj->data["data_size"] <= $newsize)  return array("success" => true);

			$encmethod = $obj->data["enc"] & self::ENCODER_MASK_DATA;

			if ($encmethod === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				if ($newsize > 0)  return array("success" => false, "error" => self::IFDSTranslate("Unable to truncate data.  New size must be zero when truncating streams."), "errorcode" => "invalid_size");

				if ($obj->data["obj_pos"] > 0)
				{
					// Finalize and flush an active write operation.
					if ($this->CanWriteDataInternal($obj->data))
					{
						$result = $this->WriteData($obj, "", 0, true);
						if (!$result["success"])  return $result;

						$result = $this->ProcessVarData();
						if (!$result["success"])  return $result;

						$result = $this->FlushObjectDataChunks($obj->data, true);
						if (!$result["success"])  return $result;
					}

					// Read all data to determine the actual size.
					$filepos = $obj->data["obj_pos"] + $obj->data["obj_size"];

					$nextsize = 65536;

					do
					{
						if (strlen($data) < $nextsize)  $nextsize = 65536;

						$result = $this->ReadNextStructure($filepos, $data, $nextsize);
						if (!$result["success"])  return $result;

						if ($result["type"] !== self::TYPE_DATA_CHUNKS || !($result["origtype"] & self::TYPE_STREAMED))  return array("success" => false, "error" => self::IFDSTranslate("Unable to find the end of the interleaved DATA chunks.  Invalid/Unexpected data structure encountered."), "errorcode" => "invalid_data_structure");

						$nextsize = $result["nextsize"];

					} while ($result["enc"] !== self::DC_DATA_TERM || $result["channel"] !== 0);

					// Free the object and all data.
					$result = $this->FreeBytesInternal($obj->data["obj_pos"], $filepos - $obj->data["obj_pos"]);
					if (!$result["success"])  return $result;

					unset($this->objposmap[$obj->data["obj_pos"]]);

					$obj->data["obj_pos"] = 0;
					$obj->data["obj_size"] = 0;

					$obj->data["mod"] = true;
				}

				// Clear loaded DATA chunks.
				$this->ClearLoadedObjectDataChunksInternal($obj->data);

				$obj->data["data_tab"] = false;
				$obj->data["data_pos"] = 0;
				$obj->data["data_size"] = 0;
				$obj->data["chunk_num"] = 0;

				// Switch back to internal data.
				$obj->data["enc"] = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM) | self::ENCODER_INTERNAL_DATA;
			}
			else if ($encmethod === self::ENCODER_DATA_CHUNKS)
			{
				// Read the last chunk.
				if ($newsize > 0)
				{
					$pos = $obj->data["data_pos"];

					$result = $this->Seek($obj, $newsize);
					if (!$result["success"])  return $result;

					// Load chunk.
					if (!isset($obj->data["chunks"][$obj->data["chunk_num"]]) && !$this->LoadCurrLocationTableChunk($obj))  return array("success" => false, "error" => self::IFDSTranslate("Unable to load the object data chunk.  Possible data corruption."), "errorcode" => "data_chunk_load_failed", "info" => $obj);

					// Restore current seek position.
					$result = $this->Seek($obj, $pos);
					if (!$result["success"])  return $result;
				}

				// Clear loaded DATA chunks past the new size.
				foreach ($obj->data["chunks"] as $chunknum => &$cinfo)
				{
					if ($cinfo["data_pos"] > $newsize)  unset($obj->data["chunks"][$chunknum]);
					else if ($cinfo["data_pos"] === $newsize || $cinfo["data_pos"] + strlen($cinfo["data"]) > $newsize)
					{
						// Truncate last chunk.
						if ($cinfo["file_pos"] > 0)
						{
							$result = $this->FreeBytesInternal($cinfo["file_pos"], $cinfo["file_size"]);
							if (!$result["success"])  return $result;

							$cinfo["file_pos"] = 0;
							$cinfo["file_size"] = 0;
						}

						$cinfo["type"] = self::DC_DATA_TERM;

						$cinfo["data"] = substr($cinfo["data"], 0, $newsize - $cinfo["data_pos"]);

						$cinfo["mod"] = true;

						$obj->data["data_mod"] = true;
					}
				}

				// Clear the object.
				$result = $this->ClearObject($obj);
				if (!$result["success"])  return $result;

				// Alter the DATA locations table.
				if ($obj->data["data_tab"] !== false)
				{
					$y = count($obj->data["data_tab"]);

					// Find the entry that contains the base truncation position.
					for ($x = 0; $x < $y && $obj->data["data_tab"][$x]["data_pos"] + $obj->data["data_tab"][$x]["data_size"] <= $newsize; $x++);

					if ($x < $y - 1)
					{
						$tinfo = &$obj->data["data_tab"][$x];

						$x2 = (int)(($newsize - $tinfo["data_pos"]) / 65528);

						if ($x2 > 0)
						{
							$result = $this->FreeBytesInternal($tinfo["file_pos"] + ($x2 * 65536), $tinfo["file_size"] - ($x2 * 65536));
							if (!$result["success"])  return $result;

							$tinfo["file_size"] = $x2 * 65536;
							$tinfo["data_size"] = $x2 * 65528;

							$x++;
						}
					}

					// Free all remaining bytes.
					for ($x2 = $x; $x2 < $y; $x2++)
					{
						$tinfo = &$obj->data["data_tab"][$x2];

						if ($tinfo["file_pos"] > 0)
						{
							$result = $this->FreeBytesInternal($tinfo["file_pos"], $tinfo["file_size"]);
							if (!$result["success"])  return $result;
						}
					}

					// Resize the DATA locations table.
					array_splice($obj->data["data_tab"], $x);

					$obj->data["data_tab"][] = array("file_pos" => 0, "file_size" => 0, "data_pos" => ($x > 0 ? $obj->data["data_tab"][$x - 1]["data_pos"] + $obj->data["data_tab"][$x - 1]["data_size"] : 0), "data_size" => 0);
				}

				$obj->data["data_size"] = $newsize;

				// Determine if the data storage type should be altered.
				if ($obj->data["data_size"] <= 3072)
				{
					$obj->data["data_tab"] = false;
					$obj->data["data_tnum"] = 0;

					$obj->data["enc"] = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM) | self::ENCODER_INTERNAL_DATA;
					$obj->data["mod"] = true;
				}

				// Seek to current position.
				$result = $this->Seek($obj, $obj->data["data_pos"]);
				if (!$result["success"])  return $result;
			}
			else if ($encmethod === self::ENCODER_INTERNAL_DATA)
			{
				// Clear the object as needed.
				if ($obj->data["data_size"] > 3072 || $newsize < 1)
				{
					$result = $this->ClearObject($obj);
					if (!$result["success"])  return $result;
				}

				// Reduce the data chunk.
				$cinfo = &$obj->data["chunks"][0];

				$cinfo["file_pos"] = 0;
				$cinfo["file_size"] = 0;

				$cinfo["data"] = substr($cinfo["data"], 0, $newsize);

				$obj->data["data_size"] = $newsize;

				$cinfo["mod"] = true;

				$obj->data["data_mod"] = true;

				// Update the current position.
				if ($obj->data["data_pos"] > $obj->data["data_size"])  $obj->data["data_pos"] = $obj->data["data_size"];
			}

			return array("success" => true);
		}

		public function ClearObject($obj)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to clear object.  File is not open."), "errorcode" => "file_not_open");

			if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				if ($obj->data["data_size"] > 0 || $this->CanWriteDataInternal($obj->data))  return array("success" => false, "error" => self::IFDSTranslate("Unable to clear object.  Streaming data must be truncated first."), "errorcode" => "truncate_first");
			}

			if ($obj->data["obj_size"] > 0)
			{
				$result = $this->FreeBytesInternal($obj->data["obj_pos"], $obj->data["obj_size"] + $obj->data["data_tsize"]);
				if (!$result["success"])  return $result;

				unset($this->objposmap[$obj->data["obj_pos"]]);

				$obj->data["obj_pos"] = 0;
				$obj->data["obj_size"] = 0;
				$obj->data["data_tsize"] = 0;

				$obj->data["mod"] = true;
			}

			return array("success" => true);
		}

		public function DeleteObject($obj)
		{
			if (!$this->open)  return array("success" => false, "error" => self::IFDSTranslate("Unable to delete object.  File is not open."), "errorcode" => "file_not_open");

			$basetype = $obj->data["type"] & self::TYPE_BASE_MASK;

			// Check to make sure the object can be deleted.
			if (isset($this->typedeleteverifiers[$basetype]) && !call_user_func_array($this->typedeleteverifiers[$basetype], array($obj)))  return array("success" => false, "error" => self::IFDSTranslate("Unable to delete object.  Object has not been detached."), "errorcode" => "object_not_detached");

			// Remove DATA chunks.
			$result = $this->Truncate($obj);
			if (!$result["success"])  return $result;

			// Clear the object.
			$result = $this->ClearObject($obj);
			if (!$result["success"])  return $result;

			// Remove the object ID from the ID map and object cache.
			if ($obj->data["id"] > 0)
			{
				$id = $obj->data["id"];

				$id2 = $id - 1;
				$pagenum = (int)($id2 / 65536);
				$pageid = $id2 % 65536;

				if (isset($this->idmap->data["entries"][$pagenum]))
				{
					$result = $this->LoadObjectIDTableMap($pagenum);
					if (!$result["success"])  return $result;

					$pageobj = $this->idmap->data["entries"][$pagenum];

					if (isset($pageobj->data["entries"][$pageid]) && ($pageobj->data["entries"][$pageid][0] > 0 || $pageobj->data["entries"][$pageid][1] > 0))
					{
						$pageobj->data["entries"][$pageid][0] = 0;
						$pageobj->data["entries"][$pageid][1] = 0;

						$pageobj->data["data_mod"] = true;
						$pageobj->data["assigned"]--;

						$this->idmap->data["data_mod"] = true;
					}

					if ($this->nextid > $obj->data["id"])  $this->nextid = $obj->data["id"];

					$this->estram -= $obj->data["est_ram"];

					unset($this->objcache[$id]);
				}

				$obj->data["id"] = 0;
			}

			return array("success" => true);
		}

		public function GetNextKeyValueMapEntry($obj, $maxvalsize = 10485760, $channel = false)
		{
			$dataencodernum = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM);

			if ($dataencodernum !== self::ENCODER_KEY_ID_MAP && $dataencodernum !== self::ENCODER_KEY_VALUE_MAP)  return array("success" => false, "error" => self::IFDSTranslate("The supplied object encoding is not a key-ID or key-value map."), "errorcode" => "invalid_object");

			$usevals = ($dataencodernum === self::ENCODER_KEY_VALUE_MAP);

			$state = "read";
			$nextstate = "keysize";
			$sizeleft = 2;
			$data = "";
			$key = false;
			$val = false;
			while ($state !== "done")
			{
				switch ($state)
				{
					case "read":
					case "skip":
					{
						$result = $this->ReadData($obj, ($sizeleft > 65536 ? 65536 : $sizeleft), $channel);
						if (!$result["success"])  return $result;

						if ($state === "read")  $data .= $result["data"];

						$sizeleft -= strlen($result["data"]);

						if ($sizeleft < 1)  $state = $nextstate;
						else if ($result["end"] && ($result["channel"] === false || $result["channel"] === 0))  $state = "done";

						break;
					}
					case "keysize":
					{
						$size = unpack("n", substr($data, 0, 2))[1];

						$data = "";

						if ($size & 0x8000)
						{
							// String key.
							$sizeleft = $size & 0x7FFF;

							$state = "read";
							$nextstate = "strkey";
						}
						else if ($size === 1 || $size === 2 || $size === 4 || $size === 8)
						{
							// Integer key.
							$sizeleft = $size;

							$state = "read";
							$nextstate = "intkey";
						}
						else
						{
							// Integer key.
							$key = $size;

							$state = "initval";
						}

						break;
					}
					case "strkey":
					{
						// String key.
						$key = $data;
						$data = "";

						$state = "initval";

						break;
					}
					case "intkey":
					{
						// Integer key.

						// PHP unpack() doesn't appear to have an option for unpacking big endian signed integers.
						if ($size === 1)  $key = unpack("c", $data)[1];
						else if ($size === 2)  $key = unpack("s", pack("s", unpack("n", $data)[1]))[1];
						else if ($size === 4)  $key = unpack("l", pack("l", unpack("N", $data)[1]))[1];
						else if ($size === 8)  $key = unpack("q", pack("q", unpack("J", $data)[1]))[1];

						$data = "";

						$state = "initval";

						break;
					}
					case "initval":
					{
						if ($usevals)
						{
							$sizeleft = 2;
							$nextstate = "strinitval";
						}
						else
						{
							$sizeleft = 4;
							$nextstate = "idval";
						}

						$state = "read";

						break;
					}
					case "idval":
					{
						$val = unpack("N", $data)[1];

						$state = "done";

						break;
					}
					case "strinitval":
					{
						$size = unpack("n", $data)[1];

						$data = "";

						if ($size & 0x8000)
						{
							$sizeleft = 2;
							$state = "read";
							$nextstate = "strinitval2";
						}
						else
						{
							$state = "strinitread";
						}

						break;
					}
					case "strinitval2":
					{
						$size = ($size & 0x7FFF) << 16 | unpack("n", $data)[1];

						$data = "";

						$state = "strinitread";

						break;
					}
					case "strinitread":
					{
						$sizeleft = $size;

						if ($size > $maxvalsize)
						{
							$state = "skip";
							$nextstate = "done";
						}
						else
						{
							$state = "read";
							$nextstate = "strread";
						}

						break;
					}
					case "strread":
					{
						$val = $data;

						$state = "done";

						break;
					}
				}
			}

			return array("success" => true, "key" => $key, "value" => $val, "end" => $result["end"], "channel" => $result["channel"]);
		}

		public function GetKeyValueMap($obj, $maxvalsize = 10485760, $channel = false)
		{
			$dataencodernum = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM);

			if ($dataencodernum !== self::ENCODER_KEY_ID_MAP && $dataencodernum !== self::ENCODER_KEY_VALUE_MAP)  return array("success" => false, "error" => self::IFDSTranslate("The supplied object encoding is not a key-ID or key-value map."), "errorcode" => "invalid_object");

			// Read the entire key-value map for the object.
			$result = $this->Seek($obj, 0);
			if (!$result["success"])  return $result;

			$map = array();
			$skipbytes = 0;
			$skipped = 0;
			$data = "";
			$key = false;
			$usevals = ($dataencodernum === self::ENCODER_KEY_VALUE_MAP);
			do
			{
				$result = $this->ReadData($obj, ($skipbytes > 0 && $skipbytes < 65536 ? $skipbytes : 65536), $channel);
				if (!$result["success"])  return $result;

				if ($skipbytes > 0)
				{
					$skipbytes -= strlen($result["data"]);

					continue;
				}

				$data .= $result["data"];

				$x = 0;
				$y = strlen($data);
				while ($x < $y)
				{
					if ($key === false)
					{
						if ($x + 2 > $y)  break;

						$size = unpack("n", substr($data, $x, 2))[1];

						if ($size & 0x8000)
						{
							// String key.
							$size = $size & 0x7FFF;

							if ($x + 2 + $size > $y)  break;

							$key = substr($data, $x + 2, $size);

							$x += $size;
						}
						else
						{
							// Integer key.
							if ($size == 1 || $size == 2 || $size == 4 || $size == 8)
							{
								if ($x + 2 + $size > $y)  break;

								$key = substr($data, $x + 2, $size);

								// PHP unpack() doesn't appear to have an option for unpacking big endian signed integers.
								if ($size === 1)  $key = unpack("c", $key)[1];
								else if ($size === 2)  $key = unpack("s", pack("s", unpack("n", $key)[1]))[1];
								else if ($size === 4)  $key = unpack("l", pack("l", unpack("N", $key)[1]))[1];
								else if ($size === 8)  $key = unpack("q", pack("q", unpack("J", $key)[1]))[1];

								$x += $size;
							}
							else
							{
								$key = $size;
							}
						}

						$x += 2;
					}
					else
					{
						if ($usevals)
						{
							// Key-value map.
							if ($x + 2 > $y)  break;

							$size = unpack("n", substr($data, $x, 2))[1];

							if ($size & 0x8000)
							{
								if ($x + 4 > $y)  break;

								$size = ($size & 0x7FFF) << 16 | unpack("n", substr($data, $x + 2, 2))[1];

								// Skip this value if it is too big.
								if ($size > $maxvalsize)
								{
									$skipbytes = $size - min($size, $y - $x - 4);

									$skipped++;
								}
								else
								{
									if ($x + 4 + $size > $y)  break;

									$map[$key] = substr($data, $x + 4, $size);
								}

								$x += 4;
							}
							else
							{
								// Skip this value if it is too big.
								if ($size > $maxvalsize)
								{
									$skipbytes = $size - min($size, $y - $x - 2);

									$skipped++;
								}
								else
								{
									if ($x + 2 + $size > $y)  break;

									$map[$key] = substr($data, $x + 2, $size);
								}

								$x += 2;
							}

							$x += $size;
						}
						else
						{
							// Key-ID map.
							if ($x + 4 > $y)  break;

							$map[$key] = unpack("N", substr($data, $x, 4))[1];

							$x += 4;
						}

						$key = false;
					}
				}

				$data = substr($data, $x);

			} while (!$result["end"] || ($result["channel"] !== false && $result["channel"] > 0));

			return array("success" => true, "map" => $map, "skipped" => $skipped);
		}

		public static function EncodeKeyValueMapEntry(&$data, &$key, &$value, $usevals, $maxvalsize = 10485760)
		{
			if (!is_int($value) && !is_string($value))  return false;

			if (is_string($value) && strlen($value) > $maxvalsize)  return false;

			// Write key.
			if (is_string($key))  $data .= pack("n", min(strlen($key), 0x7FFF) | 0x8000) . substr($key, 0, 0x7FFF);
			else if (!is_int($key))  return false;
			else
			{
				if ($key === 1 || $key === 2 || $key === 4 || $key === 8)  $data .= "\x00\x01" . chr($key);
				else if ($key >= 0 && $key <= 0x7FFF)  $data .= pack("n", $key);
				else if ($key >= -128 && $key <= 127)  $data .= "\x00\x01" . chr($key);
				else if ($key >= -32768 && $key <= 32767)  $data .= "\x00\x02" . pack("n", $key);
				else if ($key >= -2147483648 && $key <= 2147483647)  $data .= "\x00\x04" . pack("N", $key);
				else  $data .= "\x00\x08" . pack("J", $key);
			}

			// Write value.
			if ($usevals)
			{
				// Key-value map.
				if (is_int($value))  $val = pack("J", $value);
				else  $val = &$value;

				$size = strlen($val);

				if ($size > 0x7FFF)  $data .= pack("N", $size | 0x80000000);
				else  $data .= pack("n", $size);

				$data .= $val;
			}
			else
			{
				// Key-ID map.
				$val = (int)$value;

				$data .= pack("N", $val);
			}

			return true;
		}

		public function SetKeyValueMap($obj, $map, $maxvalsize = 10485760)
		{
			$dataencodernum = ($obj->data["enc"] & self::ENCODER_MASK_DATA_NUM);

			if ($dataencodernum !== self::ENCODER_KEY_ID_MAP && $dataencodernum !== self::ENCODER_KEY_VALUE_MAP)  return array("success" => false, "error" => self::IFDSTranslate("The supplied object encoding is not a key-ID or key-value map."), "errorcode" => "invalid_object");
			if (!is_array($map))  return array("success" => false, "error" => self::IFDSTranslate("The supplied map to write is not an array."), "errorcode" => "invalid_map");

			// Truncate the data and reset the encoding to internal object data if it is currently streaming.
			if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
			{
				$result = $this->Truncate($obj);
				if (!$result["success"])  return $result;
			}
			else
			{
				$result = $this->Seek($obj, 0);
				if (!$result["success"])  return $result;
			}

			// Generate and write new data.
			$data = "";
			$key = false;
			$usevals = ($dataencodernum === self::ENCODER_KEY_VALUE_MAP);

			foreach ($map as $key => $val)
			{
				if (self::EncodeKeyValueMapEntry($data, $key, $val, $usevals, $maxvalsize))
				{
					// Flush output.
					if (strlen($data) >= 65536)
					{
						$result = $this->WriteData($obj, $data);
						if (!$result["success"])  return $result;

						$data = "";
					}
				}
			}

			if (strlen($data) > 0)
			{
				$result = $this->WriteData($obj, $data);
				if (!$result["success"])  return $result;
			}

			// Truncate the data if it shrunk.
			if ($obj->data["data_pos"] < $obj->data["data_size"])
			{
				$result = $this->Truncate($obj, $obj->data["data_pos"]);
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function GetNumFixedArrayEntries($obj)
		{
			return (isset($obj->data["info"]["num"]) ? $obj->data["info"]["num"] : 0);
		}

		public function GetFixedArrayEntrySize($obj)
		{
			return (isset($obj->data["info"]["size"]) ? $obj->data["info"]["size"] : 0);
		}

		public function GetNextFixedArrayEntry($obj, $channel = false)
		{
			if (($obj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_FIXED_ARRAY)  return array("success" => false, "error" => self::IFDSTranslate("The supplied object is not a fixed array."), "errorcode" => "invalid_object");

			$valid = true;
			$size = $obj->data["info"]["size"];
			$data = "";
			do
			{
				$result = $this->ReadData($obj, $size, $channel);
				if (!$result["success"])  return $result;

				if (!$result["valid"])  $valid = false;

				$data .= $result["data"];

				$size -= strlen($result["data"]);
			} while ($size > 0 && (!$result["end"] || ($result["channel"] !== false && $result["channel"] > 0)));

			return array("success" => true, "data" => $data, "end" => $result["end"], "channel" => $result["channel"], "valid" => $valid);
		}

		public function GetFixedArrayEntry($obj, $num, $channel = false)
		{
			if (($obj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_FIXED_ARRAY)  return array("success" => false, "error" => self::IFDSTranslate("The supplied object is not a fixed array."), "errorcode" => "invalid_object");

			$result = $this->Seek($obj, $num * $obj->data["info"]["size"]);
			if (!$result["success"])  return $result;

			return $this->GetNextFixedArrayEntry($obj, $channel);
		}

		public function SetFixedArrayEntry($obj, $num, &$data)
		{
			if (($obj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_FIXED_ARRAY)  return array("success" => false, "error" => self::IFDSTranslate("The supplied object is not a fixed array."), "errorcode" => "invalid_object");
			if (strlen($data) !== $obj->data["info"]["size"])  return array("success" => false, "error" => self::IFDSTranslate("The supplied data is not the correct size."), "errorcode" => "invalid_data_size");

			$result = $this->Seek($obj, $obj->data["info"]["size"] * $num);
			if (!$result["success"])  return $result;

			$result = $this->WriteData($obj, $data);
			if (!$result["success"])  return $result;

			$obj->data["info"]["num"] = (int)($obj->data["data_size"] / $obj->data["info"]["size"]);

			$obj->data["mod"] = true;

			return $result;
		}

		public function AppendFixedArrayEntry($obj, $data, $channel = false)
		{
			if (($obj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_FIXED_ARRAY)  return array("success" => false, "error" => self::IFDSTranslate("The supplied object is not a fixed array."), "errorcode" => "invalid_object");
			if (strlen($data) !== $obj->data["info"]["size"])  return array("success" => false, "error" => self::IFDSTranslate("The supplied data is not the correct size."), "errorcode" => "invalid_data_size");

			if (($obj->data["enc"] & self::ENCODER_MASK_DATA) !== self::ENCODER_DATA_CHUNKS_STREAM && $obj->data["data_pos"] < $obj->data["data_size"])
			{
				$result = $this->Seek($obj, $obj->data["data_size"]);
				if (!$result["success"])  return $result;
			}

			$result = $this->WriteData($obj, $data, $channel);
			if (!$result["success"])  return $result;

			$obj->data["info"]["num"] = (int)($obj->data["data_size"] / $obj->data["info"]["size"]);

			$obj->data["mod"] = true;

			return $result;
		}

		public function NormalizeLinkedList($obj)
		{
			if (($obj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || ($obj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("The supplied object is not a linked list."), "errorcode" => "invalid_object");

			// Fix all of the linked list node next pointers for streamed linked lists.
			if ($obj->data["type"] & self::TYPE_STREAMED)
			{
				$num = 0;
				$nextid = $obj->data["id"];

				$id = $obj->data["info"]["last"];
				while ($id > 0 && $id !== $obj->data["id"])
				{
					$this->ReduceObjectCache();

					$result = $this->GetObjectByID($id, false);
					if (!$result["success"])  return $result;

					$nodeobj = $result["obj"];
					if (($nodeobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nodeobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $id), "errorcode" => "invalid_object");

					if ($nodeobj->data["info"]["next"] !== $nextid)
					{
						if ($nodeobj->data["info"]["next"] > 0 && $nodeobj->data["info"]["next"] !== $obj->data["id"])  return array("success" => false, "error" => self::IFDSTranslate("Loop detected at linked list node with object ID %u.", $nextid), "errorcode" => "loop_detected");

						$nodeobj->data["info"]["next"] = $nextid;
						$nodeobj->data["mod"] = true;
					}

					$nextid = $id;
					$id = $nodeobj->data["info"]["prev"];

					if ($id === $obj->data["info"]["last"])  return array("success" => false, "error" => self::IFDSTranslate("Loop detected at linked list node with object ID %u.", $nextid), "errorcode" => "loop_detected");

					$num++;
				}

				$obj->data["info"]["num"] = $num;
				$obj->data["info"]["first"] = $nextid;
				$obj->data["type"] ^= self::TYPE_STREAMED;
				$obj->data["mod"] = true;
			}

			return array("success" => true);
		}

		public function GetNumLinkedListNodes($obj)
		{
			return (isset($obj->data["info"]["num"]) ? $obj->data["info"]["num"] : 0);
		}

		public function CreateLinkedListIterator($obj)
		{
			if (($obj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || ($obj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("The supplied object is not a linked list."), "errorcode" => "invalid_object");

			if ($obj->data["type"] & self::TYPE_STREAMED)
			{
				$result = $this->NormalizeLinkedList($obj);
				if (!$result["success"])  return $result;
			}

			$iter = new stdClass();
			$iter->listobj = $obj;
			$iter->nodeobj = false;
			$iter->result = array("success" => true);

			return array("success" => true, "iter" => $iter);
		}

		public function GetNextLinkedListNode($iter)
		{
			if (!isset($iter->listobj) || !isset($iter->nodeobj))  return false;

			$this->ReduceObjectCache();

			if ($iter->nodeobj === false)
			{
				// Load the first node.
				$previd = 0;
				$id = $iter->listobj->data["info"]["first"];
			}
			else
			{
				// Load the next linked list node.
				$nodeobj = $iter->nodeobj;

				$previd = $nodeobj->data["id"];
				$id = $nodeobj->data["info"]["next"];
			}

			if ($id < 1 || $id === $iter->listobj->data["id"])
			{
				$iter->nodeobj = false;

				return false;
			}

			$result = $this->GetObjectByID($id, false);
			if (!$result["success"])
			{
				$iter->result = $result;

				return false;
			}

			$nodeobj = $result["obj"];

			if (($nodeobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nodeobj->data["type"] & self::TYPE_LEAF))
			{
				$iter->result = array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $id), "errorcode" => "invalid_object");

				return false;
			}

			if ($nodeobj->data["info"]["prev"] !== $previd && $nodeobj->data["info"]["prev"] !== $iter->listobj->data["id"])
			{
				$iter->result = array("success" => false, "error" => self::IFDSTranslate("Loop detected at linked list node with object ID %u.", $id), "errorcode" => "loop_detected");

				return false;
			}

			$iter->nodeobj = $nodeobj;

			return true;
		}

		public function GetPrevLinkedListNode($iter)
		{
			if (!isset($iter->listobj) || !isset($iter->nodeobj))  return false;

			$this->ReduceObjectCache();

			if ($iter->nodeobj === false)
			{
				// Load the last node.
				$nextid = 0;
				$id = $iter->listobj->data["info"]["last"];
			}
			else
			{
				// Load the previous linked list node.
				$nodeobj = $iter->nodeobj;

				$nextid = $nodeobj->data["id"];
				$id = $nodeobj->data["info"]["prev"];
			}

			if ($id < 1 || $id === $iter->listobj->data["id"])
			{
				$iter->nodeobj = false;

				return false;
			}

			$result = $this->GetObjectByID($id, false);
			if (!$result["success"])
			{
				$iter->result = $result;

				return false;
			}

			$nodeobj = $result["obj"];

			if (($nodeobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nodeobj->data["type"] & self::TYPE_LEAF))
			{
				$iter->result = array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $id), "errorcode" => "invalid_object");

				return false;
			}

			if ($nodeobj->data["info"]["next"] !== $nextid && $nodeobj->data["info"]["next"] !== $iter->listobj->data["id"])
			{
				$iter->result = array("success" => false, "error" => self::IFDSTranslate("Loop detected at linked list node with object ID %u.", $id), "errorcode" => "loop_detected");

				return false;
			}

			$iter->nodeobj = $nodeobj;

			return true;
		}

		public function AttachLinkedListNode($headobj, $nodeobj, $after = true)
		{
			if (($headobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || ($headobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("The supplied head object is not a linked list."), "errorcode" => "invalid_object");
			if (($nodeobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nodeobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("The supplied node object is not a linked list node."), "errorcode" => "invalid_object");
			if ($nodeobj->data["id"] < 1)  return array("success" => false, "error" => self::IFDSTranslate("The supplied linked list node does not have an object ID."), "errorcode" => "missing_object_id");
			if ($nodeobj->data["info"]["prev"] > 0 || $nodeobj->data["info"]["next"] > 0)  return array("success" => false, "error" => self::IFDSTranslate("The supplied linked list node is already attached to a linked list."), "errorcode" => "already_attached");
			if ($after !== true && $after !== false && $after < 1)  return array("success" => false, "error" => self::IFDSTranslate("The supplied 'after' object ID is invalid."), "errorcode" => "invalid_object_id");

			$this->ReduceObjectCache();

			// Handle streaming.
			if ($headobj->data["type"] & self::TYPE_STREAMED)
			{
				if ($after === true || $after === $headobj->data["info"]["last"])
				{
					$nodeobj->data["info"]["prev"] = ($headobj->data["info"]["last"] < 1 ? $headobj->data["id"] : $headobj->data["info"]["last"]);
					$nodeobj->data["info"]["next"] = $headobj->data["id"];

					if ($headobj->data["info"]["first"] < 1)  $headobj->data["info"]["first"] = $nodeobj->data["id"];
					$headobj->data["info"]["last"] = $nodeobj->data["id"];
					$headobj->data["info"]["num"]++;

					$headobj->data["mod"] = true;
					$nodeobj->data["mod"] = true;

					return array("success" => true);
				}

				$result = $this->NormalizeLinkedList($headobj);
				if (!$result["success"])  return $result;
			}

			// Attach to the beginning as the first node.
			if ($after === false)
			{
				// Update the next object.
				$nextid = $headobj->data["info"]["first"];
				if ($nextid > 0)
				{
					$result = $this->GetObjectByID($nextid, false);
					if (!$result["success"])  return $result;

					$nextobj = $result["obj"];
					if (($nextobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nextobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $nextid), "errorcode" => "invalid_object");

					$nextobj->data["info"]["prev"] = $nodeobj->data["id"];

					$nextobj->data["mod"] = true;
				}

				$nodeobj->data["info"]["prev"] = ($nextid > 0 ? $nextid : $headobj->data["id"]);
				$nodeobj->data["info"]["next"] = $headobj->data["id"];

				$headobj->data["info"]["first"] = $nodeobj->data["id"];
				if ($headobj->data["info"]["last"] < 1)  $headobj->data["info"]["last"] = $nodeobj->data["id"];
				$headobj->data["info"]["num"]++;

				$headobj->data["mod"] = true;
				$nodeobj->data["mod"] = true;

				return array("success" => true);
			}

			// Update the previous/next node.
			if ($after === true)  $after = $headobj->data["info"]["last"];
			if ($after > 0)
			{
				// Load the previous node.
				$result = $this->GetObjectByID($after, false);
				if (!$result["success"])  return $result;

				$prevobj = $result["obj"];
				if (($prevobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($prevobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $after), "errorcode" => "invalid_object");

				$nextid = $prevobj->data["info"]["next"];

				// Load and update the next node.
				if ($nextid > 0 && $nextid !== $headobj->data["id"])
				{
					$result = $this->GetObjectByID($nextid, false);
					if (!$result["success"])  return $result;

					$nextobj = $result["obj"];
					if (($nextobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nextobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $nextid), "errorcode" => "invalid_object");

					$nextobj->data["info"]["prev"] = $nodeobj->data["id"];

					$nextobj->data["mod"] = true;
				}

				$nodeobj->data["info"]["next"] = $nextid;
				$prevobj->data["info"]["next"] = $nodeobj->data["id"];

				$prevobj->data["mod"] = true;
			}
			else
			{
				$nodeobj->data["info"]["next"] = $headobj->data["id"];
			}

			$nodeobj->data["info"]["prev"] = ($after > 0 ? $after : $headobj->data["id"]);

			if ($headobj->data["info"]["first"] < 1)  $headobj->data["info"]["first"] = $nodeobj->data["id"];
			if ($after === $headobj->data["info"]["last"])  $headobj->data["info"]["last"] = $nodeobj->data["id"];
			$headobj->data["info"]["num"]++;

			$headobj->data["mod"] = true;
			$nodeobj->data["mod"] = true;

			return array("success" => true);
		}

		public function DetachLinkedListNode($headobj, $nodeobj)
		{
			if (($headobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || ($headobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("The supplied head object is not a linked list."), "errorcode" => "invalid_object");
			if (($nodeobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nodeobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("The supplied node object is not a linked list node."), "errorcode" => "invalid_object");
			if ($nodeobj->data["id"] < 1)  return array("success" => false, "error" => self::IFDSTranslate("The supplied linked list node does not have an object ID."), "errorcode" => "missing_object_id");

			$this->ReduceObjectCache();

			// Handle streaming.
			if ($headobj->data["type"] & self::TYPE_STREAMED)
			{
				$result = $this->NormalizeLinkedList($headobj);
				if (!$result["success"])  return $result;
			}

			// Load previous node.
			$previd = $nodeobj->data["info"]["prev"];
			if ($previd < 1)  $previd = $headobj->data["id"];
			$prevobj = false;
			if ($previd > 0 && $previd !== $headobj->data["id"])
			{
				$result = $this->GetObjectByID($previd, false);
				if (!$result["success"])  return $result;

				$prevobj = $result["obj"];
				if (($prevobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($prevobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $previd), "errorcode" => "invalid_object");
			}

			// Load next node.
			$nextid = $nodeobj->data["info"]["next"];
			if ($nextid < 1)  $nextid = $headobj->data["id"];
			$nextobj = false;
			if ($nextid > 0 && $nextid !== $headobj->data["id"])
			{
				$result = $this->GetObjectByID($nextid, false);
				if (!$result["success"])  return $result;

				$nextobj = $result["obj"];
				if (($nextobj->data["type"] & self::TYPE_BASE_MASK) !== self::TYPE_LINKED_LIST || !($nextobj->data["type"] & self::TYPE_LEAF))  return array("success" => false, "error" => self::IFDSTranslate("An object attached to the linked list is not a linked list node.  Object ID %u.", $nextid), "errorcode" => "invalid_object");
			}

			// Detach previous.
			if ($prevobj !== false)
			{
				$prevobj->data["info"]["next"] = $nextid;

				$prevobj->data["mod"] = true;

				if ($nextid < 1 || $nextid === $headobj->data["id"])  $headobj->data["info"]["last"] = $prevobj->data["id"];
			}

			// Detach next.
			if ($nextobj !== false)
			{
				$nextobj->data["info"]["prev"] = $previd;

				$nextobj->data["mod"] = true;

				if ($previd < 1 || $previd === $headobj->data["id"])  $headobj->data["info"]["first"] = $nextobj->data["id"];
			}

			$nodeobj->data["info"]["prev"] = 0;
			$nodeobj->data["info"]["next"] = 0;

			if ($headobj->data["info"]["first"] === $nodeobj->data["id"])  $headobj->data["info"]["first"] = 0;
			if ($headobj->data["info"]["last"] === $nodeobj->data["id"])  $headobj->data["info"]["last"] = 0;
			if ($headobj->data["info"]["num"] > 0)  $headobj->data["info"]["num"]--;

			$headobj->data["mod"] = true;
			$nodeobj->data["mod"] = true;

			return array("success" => true);
		}

		public function DeleteLinkedListNode($headobj, $nodeobj)
		{
			$result = $this->DetachLinkedListNode($headobj, $nodeobj);
			if (!$result["success"])  return $result;

			return $this->DeleteObject($nodeobj);
		}

		public function DeleteLinkedList($obj)
		{
			$result = $this->CreateLinkedListIterator($obj);
			if (!$result["success"])  return $result;

			$iter = $result["iter"];
			while ($this->GetNextLinkedListNode($iter) && $iter->nodeobj !== false)
			{
				$result = $this->DeleteLinkedListNode($obj, $iter->nodeobj);
				if (!$result["success"])  return $result;

				$iter->nodeobj = false;
			}

			if (!$iter->result["success"])  return $iter->result;

			return $this->DeleteObject($obj);
		}

		public function GetEstimatedFreeSpace()
		{
			if (!$this->LoadFreeSpaceTableChunksMap(false))  return 0;

			$total = 0;
			foreach ($this->freemap->data["entries"] as $chunknum => &$obj)
			{
				if (is_array($obj))  $total += $obj["size"];
				else
				{
					foreach ($obj->data["entries"] as &$entry)
					{
						$total += $entry[0];
					}
				}
			}

			return $total;
		}

		protected function LoadFreeSpaceBlock($chunkobj, &$entry, $blocknum)
		{
//debug_print_backtrace();
//var_dump($chunkobj);
//var_dump($entry);
//var_dump($blocknum);

			// Skip loading if completely full or was previously loaded and contains exactly one entry.
			if ($entry[0] === 0)
			{
				$entry[2] = array();
				$chunkobj->data["extracted"]++;

				return true;
			}

			if ($entry[2] === true)
			{
				$entry[2] = array($entry[1] => $entry[0]);
				$chunkobj->data["extracted"]++;

				return true;
			}

			// Load the block.
			$data = $this->ReadDataInternal($blocknum * 65536, 65536);
			if ($data === false)  return false;

			$x = $entry[1];
			$y = strlen($data);

			// Sanity check that the first byte is 0x00.
			if ($x < $y && $data[$x] !== "\x00")  return false;

			// Determine where all free spaces are located.
			$entry[2] = array();
			$size = 0;

			while ($x < $y)
			{
				for ($x2 = $x; $x2 < $y && (ord($data[$x2]) & self::TYPE_BASE_MASK) === 0; $x2++);

				$size2 = $x2 - $x;
				if ($size < $size2)  $size = $size2;

				$entry[2][$x] = $size2;

				$x = $x2;
				while ($x < $y)
				{
					$type = ord($data[$x]);

					if (($type & self::TYPE_BASE_MASK) === 0)  break;

					if ($x + 8 >= $y)  $x = $y;
					else if ($type === self::TYPE_DATA_CHUNKS)
					{
						// Skip DATA chunk or DATA locations table.
						if ($data[$x + 1] === "\x02")  $x += unpack("n", substr($data, $x + 2, 2))[1] * 10 + 18;
						else if ($data[$x + 1] === "\x00" || $data[$x + 1] === "\x01")  $x += unpack("n", substr($data, $x + 2, 2))[1] + 8;
						else  return false;
					}
					else if ($type === (self::TYPE_DATA_CHUNKS | self::TYPE_STREAMED))
					{
						// Skip interleaved DATA chunk.
						if ($data[$x + 1] === "\x00" || $data[$x + 1] === "\x01")  $x += unpack("n", substr($data, $x + 2, 2))[1] + 10;
						else  return false;
					}
					else
					{
						$x += unpack("n", substr($data, $x + 2, 2))[1] + 8;
					}
				}
			}

			if ($entry[0] !== $size)
			{
				$entry[0] = $size;

				$chunkobj->data["data_mod"] = true;

				$this->freemap->data["data_mod"] = true;
			}

			$chunkobj->data["extracted"]++;
//var_dump($chunkobj);
//var_dump($entry);
//var_dump($blocknum);
//exit();

			return true;
		}

		protected function CreateFreeSpaceChunksTable()
		{
			if ($this->freemap === false)
			{
				// Free space chunks table structure (Fixed array, 8 byte free space table entries position + 4 byte largest free space).
				$result = $this->CreateFixedArray(12, false, false);
				if (!$result["success"])  return false;

				$this->freemap = $result["obj"];
				$this->freemap->data["man"] = true;
				$this->freemap->data["entries"] = array();
			}

			return true;
		}

		protected function LoadFreeSpaceTableChunksMap($create)
		{
			if ($this->freemap !== false)  return true;

			if ($this->fileheader === false || $this->fileheader["free_map_pos"] < $this->fileheader["size"])
			{
				if ($create)  return $this->CreateFreeSpaceChunksTable();

				return false;
			}
			else
			{
				// Load the object.
				$result = $this->GetObjectByPosition($this->fileheader["free_map_pos"]);
				if (!$result["success"] || $result["obj"]->data["type"] !== self::TYPE_FIXED_ARRAY || $result["obj"]->data["info"]["size"] != 12)
				{
					// There's a problem with the object or the header.
					$this->fileheader["free_map_pos"] = 0;

					if ($create)  return $this->CreateFreeSpaceChunksTable();

					return false;
				}

				// Seek to the beginning.
				$result2 = $this->Seek($result["obj"], 0);
				if (!$result2["success"])
				{
					// There's a problem with the data for the object.
					$this->fileheader["free_map_pos"] = 0;

					if ($create)  return $this->CreateFreeSpaceChunksTable();

					return false;
				}

				// Read and extract the data.
				$y2 = ((int)($this->maxpos / 4294967296) + 1) * 12;
				$result2 = $this->ReadData($result["obj"], $y2);
				if (!$result2["success"])
				{
					// There's a problem with the data for the object.
					$this->fileheader["free_map_pos"] = 0;

					if ($create)  return $this->CreateFreeSpaceChunksTable();

					return false;
				}

				$this->freemap = $result["obj"];
				$this->freemap->data["man"] = true;
				$this->freemap->data["entries"] = array();

				$y = strlen($result2["data"]);
				if ($y > $y2)  $y = $y2;

				for ($x = 0; $x + 11 < $y; $x += 12)
				{
					$pos = unpack("J", substr($result2["data"], $x, 8))[1];
					$size = unpack("N", substr($result2["data"], $x + 8, 4))[1];

					$this->freemap->data["entries"][] = array("file_pos" => $pos, "size" => $size);
				}

				// Prevent weird circular logic issues.  Root free space map should not have an ID.
				if ($this->freemap->data["id"] > 0)  $this->RemoveObjectIDInternal($this->freemap);

				// Truncate the data and reset the encoding to internal object data if it is currently streaming.  Root free space map should never be streaming.
				if (($this->freemap->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
				{
					$result = $this->Truncate($this->freemap);
					if (!$result["success"])  return false;
				}
			}

			return true;
		}

		protected function CreateFreeSpaceTable($chunknum)
		{
			// Free space table structure (Fixed array, 2 byte largest free space + 2 byte first space start position).
			$result = $this->CreateFixedArray(4, false, false);
			if (!$result["success"])  return $result;

			$result["obj"]->data["man"] = true;
			$result["obj"]->data["entries"] = array();
			$result["obj"]->data["extracted"] = 0;

			$this->freemap->data["entries"][$chunknum] = $result["obj"];
			$this->freemap->data["data_mod"] = true;

			return $result;
		}

		protected function LoadFreeSpaceTableMap($chunknum)
		{
			// Create the object.
			if (!isset($this->freemap->data["entries"][$chunknum]))  return $this->CreateFreeSpaceTable($chunknum);

			if (is_array($this->freemap->data["entries"][$chunknum]))
			{
				// Load the object.
				$result = $this->GetObjectByPosition($this->freemap->data["entries"][$chunknum]["file_pos"]);
				if (!$result["success"] || $result["obj"]->data["type"] !== self::TYPE_FIXED_ARRAY || $result["obj"]->data["info"]["size"] != 4)  return $this->CreateFreeSpaceTable($chunknum);

				// Seek to the beginning.
				$obj = $result["obj"];
				$result = $this->Seek($obj, 0);
				if (!$result["success"])  return $this->CreateFreeSpaceTable($chunknum);

				// Read and extract the data.
				$result = $this->ReadData($obj, 4 * 65536);
				if (!$result["success"])  return $this->CreateFreeSpaceTable($chunknum);

				$this->freemap->data["entries"][$chunknum] = $obj;

				$obj->data["man"] = true;
				$obj->data["entries"] = array();
				$obj->data["extracted"] = 0;

				$y = strlen($result["data"]);
				if ($y > 4 * 65536)  $y = 4 * 65536;

				for ($x = 0; $x + 3 < $y; $x += 4)
				{
					$size = unpack("n", substr($result["data"], $x, 2))[1];
					$pos = unpack("n", substr($result["data"], $x + 2, 2))[1];

					// Handle special cases.
					if ($pos === 0xFFFF)
					{
						if ($size === 0)  $pos = 65536;
						else if ($size === 0xFFFF)
						{
							$size = 65536;
							$pos = 0;
						}
					}

					if ($size + $pos > 65536)  $size = 65536 - $pos;

					$obj->data["entries"][] = array($size, $pos, false);
				}

				// Prevent weird circular logic issues.  Free space tables should not have IDs.
				if ($obj->data["id"] > 0)  $this->RemoveObjectIDInternal($obj);

				// Truncate the data and reset the encoding to internal object data if it is currently streaming.  Free space tables should never be streaming.
				if (($obj->data["enc"] & self::ENCODER_MASK_DATA) === self::ENCODER_DATA_CHUNKS_STREAM)
				{
					$result = $this->Truncate($obj);
					if (!$result["success"])  return $result;
				}
			}

			return array("success" => true);
		}

		protected function ClearFreeSpaceEntryTracker($obj)
		{
			foreach ($obj->data["entries"] as &$entry)
			{
				if ($entry[2] !== false)
				{
					$entry[2] = (count($entry[2]) == 1);

					$obj->data["extracted"]--;
				}
			}
		}

		protected function ReserveBytesInternal_AttemptReservation($pos, $numbytes)
		{
			// Confirm that the space is able to be reserved.
			$origpos = $pos;
			$orignumbytes = $numbytes;
			while ($numbytes > 0)
			{
				$chunknum = (int)($pos / 4294967296);

				$result = $this->LoadFreeSpaceTableMap($chunknum);
				if (!$result["success"])  return false;

				$obj = $this->freemap->data["entries"][$chunknum];

				// Locate the block, position, and size of the starting point to allocate.
				$blocknum = (int)($pos / 65536);
				$blockpos = (int)(($pos % 4294967296) / 65536);

				$y = count($obj->data["entries"]);

				// If the table map entry does not exist, then the block is full.
				if ($y <= $blockpos)  return false;

				$pos2 = $pos % 65536;
				$numbytes2 = 65536 - $pos2;
				if ($numbytes2 > $numbytes)  $numbytes2 = $numbytes;

				// Load free space entry information.  Each entry consists of:  Size, position, and optional position to size map.
				$entry = &$obj->data["entries"][$blockpos];

				if (($entry[2] === true || $entry[2] === false) && !$this->LoadFreeSpaceBlock($obj, $entry, $blocknum))
				{
					// Treat entry as full when there is a loading error.
					$entry[0] = 0;
					$entry[1] = 65536;
					$entry[2] = array();

					$obj->data["data_mod"] = true;
					$obj->data["extracted"]++;

					$this->freemap->data["data_mod"] = true;
				}

				if (isset($entry[2][$pos2]))
				{
					if ($entry[2][$pos2] < $numbytes2)  return false;
				}
				else
				{
					$found = false;
					foreach ($entry[2] as $pos3 => $size2)
					{
						if ($pos3 < $pos2 && $pos3 + $size2 >= $pos2 + $numbytes2)
						{
							$found = true;

							break;
						}
					}

					if (!$found)  return false;
				}

				$pos += $numbytes2;
				$numbytes -= $numbytes2;

				// Reduce memory usage.
				if ($obj->data["extracted"] >= 10000)  $this->ClearFreeSpaceEntryTracker($obj);
			}

			// Reserve the space.
			$pos = $origpos;
			$numbytes = $orignumbytes;
			while ($numbytes > 0)
			{
				$chunknum = (int)($pos / 4294967296);

				$result = $this->LoadFreeSpaceTableMap($chunknum);
				if (!$result["success"])  return false;

				$obj = $this->freemap->data["entries"][$chunknum];

				// Locate the block, position, and size of the starting point to allocate.
				$blocknum = (int)($pos / 65536);
				$blockpos = (int)(($pos % 4294967296) / 65536);

				$y = count($obj->data["entries"]);

				// If the table map entry does not exist, then the block is full.
				if ($y <= $blockpos)  return false;

				$pos2 = $pos % 65536;
				$numbytes2 = 65536 - $pos2;
				if ($numbytes2 > $numbytes)  $numbytes2 = $numbytes;

				// Load free space entry information.  Each entry consists of:  Size, position, and optional position to size map.
				$entry = &$obj->data["entries"][$blockpos];

				if (($entry[2] === true || $entry[2] === false) && !$this->LoadFreeSpaceBlock($obj, $entry, $blocknum))
				{
					// Treat entry as full when there is a loading error.
					$entry[0] = 0;
					$entry[1] = 65536;
					$entry[2] = array();

					$obj->data["extracted"]++;
				}

				// Find and adjust the position/size information.
				$found = false;
				$pos4 = $pos2 + $numbytes2;
				foreach ($entry[2] as $pos3 => $size2)
				{
					if ($pos3 <= $pos2 && $pos3 + $size2 >= $pos4)
					{
						if ($pos3 < $pos2)  $entry[2][$pos3] = $pos2 - $pos3;
						else  unset($entry[2][$pos3]);

						if ($pos3 + $size2 > $pos4)  $entry[2][$pos2 + $numbytes2] = ($pos3 + $size2) - $pos4;

						$found = true;

						break;
					}
				}

				if (!$found)  return false;

				// Recalculate the start position and maximum size information.
				$entry[0] = 0;
				$entry[1] = 65536;
				foreach ($entry[2] as $pos3 => $size2)
				{
					if ($entry[0] < $size2)  $entry[0] = $size2;
					if ($entry[1] > $pos3)  $entry[1] = $pos3;
				}

				$pos += $numbytes2;
				$numbytes -= $numbytes2;

				$obj->data["data_mod"] = true;

				$this->freemap->data["data_mod"] = true;

				// Reduce memory usage.
				if ($obj->data["extracted"] >= 10000)  $this->ClearFreeSpaceEntryTracker($obj);
			}

			return true;
		}

		protected function ReserveBytesInternal_FindNext($pos, $numbytes)
		{
			$y = count($this->freemap->data["entries"]);
			for ($chunknum = (int)($pos / 4294967296); $chunknum < $y; $chunknum++, $pos = ($chunknum + 1) * 4294967296)
			{
				$obj = &$this->freemap->data["entries"][$chunknum];

				// Only load the free space table if there might be an entry that could work.
				if (is_array($obj))
				{
					if ($chunknum + 1 < $y && $obj["size"] < $numbytes)  continue;

					$result = $this->LoadFreeSpaceTableMap($chunknum);
					if (!$result["success"])  return $this->maxpos;

					$obj = $this->freemap->data["entries"][$chunknum];
				}

				// Locate the position of a possible starting point.
				$y2 = count($obj->data["entries"]);
				$basepos = $chunknum * 4294967296;
				for ($blockpos = (int)(($pos % 4294967296) / 65536); $blockpos < $y2; $blockpos++, $pos = $basepos + ($blockpos * 65536))
				{
					// Use only high level, untrusted free space entry information.  Each entry consists of:  Size, position, and optional position to size map.
					$entry = &$obj->data["entries"][$blockpos];

					// Estimate the amount of free space available starting at this block.
					$pos2 = $pos % 65536;
					if ($entry[0] >= $numbytes && ($entry[2] === true || $entry[2] === false))
					{
						$minpos = $entry[1];
						$numbytes2 = $entry[0];
					}
					else
					{
						$minpos = 0;
						$numbytes2 = 0;
						foreach ($entry[2] as $pos3 => $size2)
						{
							// If sufficient free space exists within the loaded block, return the position.
							if ($size2 >= $numbytes)  return $basepos + ($blockpos * 65536) + $pos3;

							if ($minpos < $pos3)
							{
								$minpos = $pos3;
								$numbytes2 = $size2;
							}
						}
					}

					$posdiff = ($pos2 > $minpos ? 0 : $minpos - $pos2);

					// Attempt to span free space across multiple blocks.
					if ($numbytes2 < $numbytes && $numbytes2 > 0 && $minpos + $numbytes2 >= 65536)
					{
						for ($blockpos2 = $blockpos + 1; $blockpos2 < $y2 && $numbytes2 < $numbytes && $obj->data["entries"][$blockpos2][1] === 0; $blockpos2++)
						{
							$entry2 = &$obj->data["entries"][$blockpos2];

							if ($entry[2] === true || $entry[2] === false)  $numbytes2 += $entry2[0];
							else  $numbytes2 += $entry2[2][0];

							if ($entry2[0] < 65536)  break;
						}

						$blockpos = $blockpos2 - 1;
					}

					if ($numbytes2 >= $numbytes || $pos + $posdiff + $numbytes2 >= $this->maxpos)  return $pos + $posdiff;
				}
			}

			return $this->maxpos;
		}

		protected function ReserveBytesInternal($numbytes, $prefpos = false)
		{
			if ($this->fileheader === false || $numbytes < 1 || $prefpos >= $this->maxpos || !$this->LoadFreeSpaceTableChunksMap(false))  return $this->maxpos;

			// If a preferred position is specified, attempt to reserve those bytes.
			if ($prefpos !== false && $prefpos > 0 && $this->ReserveBytesInternal_AttemptReservation($prefpos, $numbytes))  return $prefpos;

			// Locate and reserve the bytes at a suitable position in the file.
			$pos = $this->fileheader["size"];

			do
			{
//$prepos = $pos;
//echo "Start:  " . $pos . " (" . $numbytes . ")\n";
				$pos = $this->ReserveBytesInternal_FindNext($pos, $numbytes);
//echo "Done:  " . $pos . "\n";

				if ($pos >= $this->maxpos)  return $this->maxpos;

				if ($this->ReserveBytesInternal_AttemptReservation($pos, $numbytes))  return $pos;
//if ($prepos === $pos)  exit();
			} while (true);

			return $pos;
		}

		protected function FreeBytesInternal($pos, $numbytes)
		{
//debug_print_backtrace();
//var_dump($this->freemap);
			if ($pos > $this->maxpos)  return array("success" => true);
			if ($pos + $numbytes > $this->maxpos)  $numbytes = $this->maxpos - $pos;
			if ($numbytes < 1)  return array("success" => true);

			if ($this->fileheader !== false && $pos < $this->fileheader["size"])  return array("success" => false, "error" => self::IFDSTranslate("Unable to free bytes contained in the file header."), "errorcode" => "free_bytes_failed");

			if (!$this->LoadFreeSpaceTableChunksMap(true))  return array("success" => false, "error" => self::IFDSTranslate("Unable to load/create the free space chunks map."), "errorcode" => "free_bytes_failed");

			while ($numbytes > 0)
			{
				$chunknum = (int)($pos / 4294967296);

				$result = $this->LoadFreeSpaceTableMap($chunknum);
				if (!$result["success"])  return $result;

				$obj = $this->freemap->data["entries"][$chunknum];

				// Locate the block, position, and size of the starting point to free.
				$blocknum = (int)($pos / 65536);
				$blockpos = (int)(($pos % 4294967296) / 65536);

				$y = count($obj->data["entries"]);

				while ($y <= $blockpos)
				{
					$obj->data["entries"][] = array(0, 65536, false);

					$y++;
				}

				$pos2 = $pos % 65536;
				$numbytes2 = 65536 - $pos2;
				if ($numbytes2 > $numbytes)  $numbytes2 = $numbytes;

				$tempdata = str_repeat("\x00", $numbytes2);

				if (!$this->WriteDataInternal($tempdata, $pos))  return array("success" => false, "error" => self::IFDSTranslate("Unable to write zero data."), "errorcode" => "free_bytes_failed");

				// Update free space information.  Each entry consists of:  Size, position, and optional position to size map.
				$entry = &$obj->data["entries"][$blockpos];

				if ($entry[0] == 0)
				{
					$entry[0] = $numbytes2;
					$entry[1] = $pos2;
					$entry[2] = array($pos2 => $numbytes2);

					$obj->data["extracted"]++;
				}
				else if ($entry[2] === false && !$this->LoadFreeSpaceBlock($obj, $entry, $blocknum))
				{
					// Failed to load the free space block.  Assume this is the first entry.
					$entry[0] = $numbytes2;
					$entry[1] = $pos2;
					$entry[2] = array($pos2 => $numbytes2);

					$obj->data["extracted"]++;
				}
				else
				{
					// Merge spaces.
					$size = $numbytes2;
					if (isset($entry[2][$pos2 + $numbytes2]))
					{
						$size += $entry[2][$pos2 + $numbytes2];

						unset($entry[2][$pos2 + $numbytes2]);
					}

					foreach ($entry[2] as $pos3 => $size2)
					{
						if ($pos3 + $size2 === $pos2)
						{
							$pos2 = $pos3;
							$size += $size2;

							break;
						}
					}

					$entry[2][$pos2] = $size;

					if ($entry[0] < $size)  $entry[0] = $size;
					if ($entry[1] > $pos2)  $entry[1] = $pos2;
				}

				$pos += $numbytes2;
				$numbytes -= $numbytes2;

				$obj->data["data_mod"] = true;

				$this->freemap->data["data_mod"] = true;

				// Reduce memory usage.
				if ($obj->data["extracted"] >= 10000)  $this->ClearFreeSpaceEntryTracker($obj);
			}
//var_dump($this->freemap);

			return array("success" => true);
		}

		protected function FixedArrayTypeEncoder($obj)
		{
			$data = pack("N", $obj->data["info"]["size"]);
			$data .= pack("N", $obj->data["info"]["num"]);

			return $data;
		}

		protected function FixedArrayTypeDecoder($obj, &$data, $pos, &$size)
		{
			if ($size < 8)  return false;

			$obj->data["info"]["size"] = unpack("N", substr($data, $pos, 4))[1];
			$obj->data["info"]["num"] = unpack("N", substr($data, $pos + 4, 4))[1];

			$size -= 8;

			return true;
		}

		protected function LinkedListTypeEncoder($obj)
		{
			if ($obj->data["type"] & self::TYPE_LEAF)
			{
				$data = pack("N", $obj->data["info"]["prev"]);
				$data .= pack("N", $obj->data["info"]["next"]);
			}
			else
			{
				$data = pack("N", $obj->data["info"]["num"]);
				$data .= pack("N", $obj->data["info"]["first"]);
				$data .= pack("N", $obj->data["info"]["last"]);
			}

			return $data;
		}

		protected function LinkedListTypeDecoder($obj, &$data, $pos, &$size)
		{
			if ($obj->data["type"] & self::TYPE_LEAF)
			{
				if ($size < 8)  return false;

				$obj->data["info"]["prev"] = unpack("N", substr($data, $pos, 4))[1];
				$obj->data["info"]["next"] = unpack("N", substr($data, $pos + 4, 4))[1];

				$size -= 8;
			}
			else
			{
				if ($size < 12)  return false;

				$obj->data["info"]["num"] = unpack("N", substr($data, $pos, 4))[1];
				$obj->data["info"]["first"] = unpack("N", substr($data, $pos + 4, 4))[1];
				$obj->data["info"]["last"] = unpack("N", substr($data, $pos + 8, 4))[1];

				$size -= 12;
			}

			return true;
		}

		protected function LinkedListTypeCanDelete($obj)
		{
			if ($obj->data["type"] & self::TYPE_LEAF)  return ($obj->data["info"]["prev"] == 0 && $obj->data["info"]["next"] == 0);

			return ($obj->data["info"]["first"] == 0 && $obj->data["info"]["last"] == 0);
		}

		protected function UnknownTypeEncoder($obj)
		{
			return $obj->data["info"];
		}

		protected function UnknownTypeDecoder($obj, &$data, $pos, &$size)
		{
			$encmethod = $obj->data["enc"] & self::ENCODER_MASK_DATA;

			if ($encmethod === self::ENCODER_INTERNAL_DATA)
			{
				if ($size < 2)  $obj->data["info"] = "";
				else
				{
					$datasize = unpack("n", substr($data, $pos + $size - 2, 2))[1];

					if ($datasize > $size - 2)  $datasize = $size - 2;

					$obj->data["info"] = substr($data, $pos, $size - $datasize - 2);

					$size -= ($datasize + 2);
				}
			}
			else
			{
				$obj->data["info"] = substr($data, $pos, $size);

				$size = 0;
			}

			return true;
		}

		public static function IFDSTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}

	class IFDS_RefCountObj
	{
		public $data;

		public function __construct(&$data)
		{
			$this->data = &$data;

			$this->data["refs"]++;
		}

		public function __destruct()
		{
			$this->data["refs"]--;
		}

		public function GetID()
		{
			return $this->data["id"];
		}

		public function GetBaseType()
		{
			return ($this->data["type"] & IFDS::TYPE_BASE_MASK);
		}

		public function GetType()
		{
			return $this->data["type"];
		}

		public function GetTypeStr()
		{
			return $this->data["type_str"];
		}

		public function GetEncoder()
		{
			return ($this->data["enc"] & IFDS::ENCODER_MASK_DATA_NUM);
		}

		public function GetDataMethod()
		{
			return ($this->data["enc"] & IFDS::ENCODER_MASK_DATA);
		}

		public function GetDataPos()
		{
			return $this->data["data_pos"];
		}

		public function GetDataSize()
		{
			return $this->data["data_size"];
		}

		public function SetManualWrite($enable)
		{
			$this->data["man"] = (bool)$enable;
		}

		public function IsDataNull()
		{
			return ($this->data["enc"] === (IFDS::ENCODER_NONE | IFDS::ENCODER_NO_DATA));
		}

		public function IsValid()
		{
			return $this->data["valid"];
		}

		public function IsModified()
		{
			return ($this->data["mod"] || $this->data["data_mod"]);
		}

		public function IsInterleaved()
		{
			return (($this->data["enc"] & IFDS::ENCODER_MASK_DATA) === IFDS::ENCODER_DATA_CHUNKS_STREAM);
		}

		public function IsManualWrite()
		{
			return $this->data["man"];
		}
	}
?>