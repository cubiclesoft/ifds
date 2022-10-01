<?php
	// IFDS text file format class.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	class IFDS_Text
	{
		protected $ifds, $metadata, $rootobj, $rootentries, $mod, $compresslevel;

		const FEATURES_COMPRESS_DATA = 0x0001;
		const FEATURES_TRAILING_NEWLINE = 0x0002;

		const ENCODER_DEFLATE = 16;

		public function __construct()
		{
			$this->ifds = false;
			$this->compresslevel = -1;
		}

		public function __destruct()
		{
			$this->Close();
		}

		public function SetCompressionLevel($level)
		{
			$this->compresslevel = (int)$level;
		}

		public function Create($pfcfilename, $options = array())
		{
			$this->Close();

			if (!class_exists("IFDS", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/ifds.php";

			$this->ifds = new IFDS();
			$result = $this->ifds->Create($pfcfilename, 1, 0, 0, "TEXT");
			if (!$result["success"])  return $result;

			$features = 0;
			if (isset($options["compress"]) && (bool)$options["compress"])  $features |= self::FEATURES_COMPRESS_DATA;
			if (!isset($options["trail"]) || (bool)$options["trail"])  $features |= self::FEATURES_TRAILING_NEWLINE;

			$this->ifds->SetAppFormatFeatures($features);

			// Store metadata.
			$this->metadata = array(
				"newline" => (isset($options["newline"]) ? (string)$options["newline"] : "\n"),
				"charset" => (isset($options["charset"]) ? (string)$options["charset"] : "utf-8"),
				"mimetype" => (isset($options["mimetype"]) ? (string)$options["mimetype"] : "text/plain"),
				"language" => (isset($options["language"]) ? (string)$options["language"] : "en-us"),
				"author" => (isset($options["author"]) ? (string)$options["author"] : "")
			);

			$result = $this->ifds->CreateKeyValueMap("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->SetKeyValueMap($metadataobj, $this->metadata);
			if (!$result["success"])  return $result;

			// Create the root object.
			$result = $this->ifds->CreateFixedArray(8, "root");
			if (!$result["success"])  return $result;

			$this->rootobj = $result["obj"];

			return array("success" => true);
		}

		public function Open($pfcfilename)
		{
			$this->Close();

			if (!class_exists("IFDS", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/ifds.php";

			$this->ifds = new IFDS();
			$result = $this->ifds->Open($pfcfilename, "TEXT");
			if (!$result["success"])  return $result;

			if ($result["header"]["fmt_major_ver"] != 1)  return array("success" => false, "error" => self::IFDSTranslate("Invalid file header.  Expected text format major version 1."), "errorcode" => "invalid_fmt_major_ver");

			// Get metadata.
			$result = $this->ifds->GetObjectByName("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->GetKeyValueMap($metadataobj);
			if (!$result["success"])  return $result;

			$this->metadata = $result["map"];

			if (!isset($this->metadata["newline"]))  return array("success" => false, "error" => self::IFDSTextTranslate("Missing 'newline' in metadata."), "errorcode" => "invalid_metadata");
			if (!isset($this->metadata["charset"]))  return array("success" => false, "error" => self::IFDSTextTranslate("Missing 'charset' in metadata."), "errorcode" => "invalid_metadata");
			if (!isset($this->metadata["mimetype"]))  return array("success" => false, "error" => self::IFDSTextTranslate("Missing 'mimetype' in metadata."), "errorcode" => "invalid_metadata");
			if (!isset($this->metadata["language"]))  return array("success" => false, "error" => self::IFDSTextTranslate("Missing 'language' in metadata."), "errorcode" => "invalid_metadata");

			// Load the root object.
			$result = $this->ifds->GetObjectByName("root");
			if (!$result["success"])  return $result;

			$this->rootobj = $result["obj"];

			if ($this->ifds->GetFixedArrayEntrySize($this->rootobj) !== 8)  return array("success" => false, "error" => self::IFDSTextTranslate("Invalid root object."), "errorcode" => "invalid_root");

			// Extract root entries.
			do
			{
				$result = $this->ifds->GetNextFixedArrayEntry($this->rootobj);
				if (!$result["success"])  return $result;

				$this->rootentries[] = array(
					"lines" => unpack("N", substr($result["data"], 0, 4))[1],
					"id" => unpack("N", substr($result["data"], 4, 4))[1],
					"obj" => false,
					"entries" => array()
				);

			} while (!$result["end"]);

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
			$this->rootobj = false;
			$this->rootentries = array();
			$this->mod = false;
		}

		public function Save($flush = true)
		{
			if ($this->ifds === false)  return array("success" => false, "error" => self::IFDSTextTranslate("Unable to save information.  File is not open."), "errorcode" => "file_not_open");

			if ($this->mod)
			{
				$result = $this->ifds->Truncate($this->rootobj);
				if (!$result["success"])  return $result;

				foreach ($this->rootentries as $num => &$rinfo)
				{
					if (!$rinfo["lines"])  unset($this->rootentries[$num]);
					else
					{
						if ($rinfo["obj"] !== false)
						{
							$result = $this->ifds->Truncate($rinfo["obj"]);
							if (!$result["success"])  return $result;

							foreach ($rinfo["entries"] as $num2 => &$entry)
							{
								$data2 = pack("n", $entry["lines"]);
								$data2 .= pack("N", $entry["id"]);

								$result = $this->ifds->AppendFixedArrayEntry($rinfo["obj"], $data2);
								if (!$result["success"])  return $result;
							}
						}

						$data = pack("N", $rinfo["lines"]);
						$data .= pack("N", $rinfo["id"]);

						$result = $this->ifds->AppendFixedArrayEntry($this->rootobj, $data);
						if (!$result["success"])  return $result;
					}
				}

				$this->rootentries = array_values($this->rootentries);

				$this->mod = false;
			}

			if ($flush)
			{
				$result = $this->ifds->FlushAll();
				if (!$result["success"])  return $result;
			}

			return array("success" => true);
		}

		public function GetNumLines()
		{
			$total = 0;

			foreach ($this->rootentries as &$rinfo)  $total += $rinfo["lines"];

			if ($this->IsTrailingNewlineEnabled())  $total++;

			return $total;
		}

		protected function WriteMetadata()
		{
			$result = $this->ifds->GetObjectByName("metadata");
			if (!$result["success"])  return $result;

			$metadataobj = $result["obj"];

			$result = $this->ifds->SetKeyValueMap($metadataobj, $this->metadata);

			return $result;
		}

		public function GetNewline()
		{
			return $this->metadata["newline"];
		}

		public function IsCompressEnabled()
		{
			return ($this->ifds->GetAppFormatFeatures() & self::FEATURES_COMPRESS_DATA);
		}

		public function SetCompressData($enable)
		{
			$features = $this->ifds->GetAppFormatFeatures();

			if ($enable)  $features |= self::FEATURES_COMPRESS_DATA;
			else  $features &= ~self::FEATURES_COMPRESS_DATA;

			$this->ifds->SetAppFormatFeatures($features);
		}

		public function IsTrailingNewlineEnabled()
		{
			return ($this->ifds->GetAppFormatFeatures() & self::FEATURES_TRAILING_NEWLINE);
		}

		public function SetTrailingNewline($enable)
		{
			$features = $this->ifds->GetAppFormatFeatures();

			if ($enable)  $features |= self::FEATURES_TRAILING_NEWLINE;
			else  $features &= ~self::FEATURES_TRAILING_NEWLINE;

			$this->ifds->SetAppFormatFeatures($features);
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

		public function GetMIMEType()
		{
			return $this->metadata["mimetype"];
		}

		public function SetMIMEType($mimetype)
		{
			$this->metadata["mimetype"] = $mimetype;

			return $this->WriteMetadata();
		}

		public function GetLanguage()
		{
			return $this->metadata["language"];
		}

		public function SetLanguage($language)
		{
			$this->metadata["language"] = $language;

			return $this->WriteMetadata();
		}

		public function GetAuthor()
		{
			return (isset($this->metadata["author"]) ? $this->metadata["author"] : "");
		}

		public function SetAuthor($author)
		{
			$this->metadata["author"] = $author;

			return $this->WriteMetadata();
		}

		protected function CreateSuperTextChunk($chunknum)
		{
			$result = $this->ifds->CreateFixedArray(6);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			array_splice($this->rootentries, $chunknum, 0, array(array("lines" => 0, "id" => $obj->GetID(), "obj" => $obj, "entries" => array())));

			$this->mod = true;

			return array("success" => true);
		}

		protected function LoadSuperTextChunk($chunknum)
		{
			$rinfo = &$this->rootentries[$chunknum];

			if ($rinfo["obj"] !== false)  return array("success" => true);

			$result = $this->ifds->GetObjectByID($rinfo["id"]);
			if (!$result["success"])  return $result;

			$obj = $result["obj"];

			if ($this->ifds->GetFixedArrayEntrySize($obj) !== 6)  return array("success" => false, "error" => self::IFDSTextTranslate("Invalid super text chunk object size."), "errorcode" => "invalid_super_text_chunk");

			// Extract root entries.
			$total = 0;
			do
			{
				$result = $this->ifds->GetNextFixedArrayEntry($obj);
				if (!$result["success"])  return $result;

				$numlines = unpack("n", substr($result["data"], 0, 2))[1];

				$total += $numlines;

				$rinfo["entries"][] = array(
					"lines" => $numlines,
					"id" => unpack("N", substr($result["data"], 2, 4))[1]
				);

			} while (!$result["end"]);

			if ($rinfo["lines"] !== $total)
			{
				$rinfo["lines"] = $total;

				$this->mod = true;
			}

			return array("success" => true);
		}

		protected function ReadDataInternal($obj)
		{
			$result = $this->ifds->Seek($obj, 0);
			if (!$result["success"])  return $result;

			$result = $this->ifds->ReadData($obj);
			if (!$result["success"])  return $result;

			if ($obj->GetEncoder() === self::ENCODER_DEFLATE)
			{
				if (!class_exists("DeflateStream", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/deflate_stream.php";

				if (!DeflateStream::IsSupported())  return array("success" => false, "error" => self::IFDSTextTranslate("Unable to decompress data.  Deflate/zlib stream filter support is not enabled."), "errorcode" => "zlib_support_not_enabled");

				$result["data"] = DeflateStream::Uncompress($result["data"]);
				if ($result["data"] === false)  return array("success" => false, "error" => self::IFDSTextTranslate("Unable to decompress data.  Decompression failed."), "errorcode" => "uncompress_failed");
			}

			return $result;
		}

		protected function WriteDataInternal($obj, &$data)
		{
			$result = $this->ifds->Seek($obj, 0);
			if (!$result["success"])  return $result;

			$y = strlen($data);

			// Attempt to compress the data.
			if ($this->IsCompressEnabled())
			{
				if (class_exists("DeflateStream", false) || file_exists(str_replace("\\", "/", dirname(__FILE__)) . "/deflate_stream.php"))
				{
					require_once str_replace("\\", "/", dirname(__FILE__)) . "/deflate_stream.php";

					if (DeflateStream::IsSupported())
					{
						$data2 = DeflateStream::Compress($data, $this->compresslevel);
						if ($data2 !== false)
						{
							$y2 = strlen($data2);

							if ($y2 < $y)
							{
								$result = array("success" => true);

								if ($obj->GetEncoder() !== self::ENCODER_DEFLATE)  $result = $this->ifds->SetObjectEncoder($obj, self::ENCODER_DEFLATE);

								if ($result["success"] && ($y2 < $obj->GetDataSize() || !$this->ifds->CanWriteData($obj)))  $result = $this->ifds->Truncate($obj, ($this->ifds->CanWriteData($obj) ? $y2 : 0));

								return $this->ifds->WriteData($obj, $data2);
							}
						}
					}
				}
			}

			// Write uncompressed data.
			if ($obj->GetEncoder() !== IFDS::ENCODER_RAW)  $result = $this->ifds->SetObjectEncoder($obj, IFDS::ENCODER_RAW);

			if ($result["success"] && ($y < $obj->GetDataSize() || !$this->ifds->CanWriteData($obj)))
			{
				$result = $this->ifds->Truncate($obj, ($this->ifds->CanWriteData($obj) ? $y : 0));
				if (!$result["success"])  return $result;
			}

			return $this->ifds->WriteData($obj, $data);
		}

		public function WriteLines($lines, $offset, $removelines)
		{
			if ($this->ifds === false)  return array("success" => false, "error" => self::IFDSTextTranslate("Unable to write data.  File is not open."), "errorcode" => "file_not_open");

			if (is_array($lines))
			{
				// Cleanup the lines.
				foreach ($lines as &$line)
				{
					if (strpos($line, $this->metadata["newline"]) !== false)  $line = str_replace($this->metadata["newline"], "", $line);
				}

				unset($line);
			}
			else
			{
				$lines = (string)$lines;

				$lines = ($lines === "" ? array() : explode($this->metadata["newline"], $lines));

				$y = count($lines);
				if ($y > 0 && $lines[$y - 1] === "")  array_pop($lines);
			}

			$y = count($lines);

			// Find the offset in the root.
			$currline = 0;
			$y = count($this->rootentries);
			for ($x = 0; $x < $y && $offset > $currline + $this->rootentries[$x]["lines"]; $x++)  $currline += $this->rootentries[$x]["lines"];

			if ($x >= $y)
			{
				if ($x > 0)
				{
					$x--;

					$result = $this->LoadSuperTextChunk($x);
					if (!$result["success"])  return $result;
				}
				else
				{
					$result = $this->CreateSuperTextChunk($x);
					if (!$result["success"])  return $result;
				}

				$offset = $currline;

				$rinfo = &$this->rootentries[$x];

				$y2 = count($rinfo["entries"]);
				$x2 = $y2;
			}
			else
			{
				// Find the offset in the super text chunk.
				$result = $this->LoadSuperTextChunk($x);
				if (!$result["success"])  return $result;

				$rinfo = &$this->rootentries[$x];

				$y2 = count($rinfo["entries"]);
				for ($x2 = 0; $x2 < $y2 && $offset > $currline + $rinfo["entries"][$x2]["lines"]; $x2++)  $currline += $rinfo["entries"][$x2]["lines"];
			}

			// Attempt to append to the last entry if at the end.
			if ($x2 >= $y2 && $x2 > 0)
			{
				$x2--;

				$currline -= $rinfo["entries"][$x2]["lines"];
			}

			$readsuper = $x;
			$nextread = $x2;
			$nextread2 = $y2;

			$writesuper = $x;
			$nextwrite = $x2;
			$nextwrite2 = $y2;
			$winfo = &$this->rootentries[$x];

			$last = false;
			$size = 0;
			$newlinelen = strlen($this->metadata["newline"]);
			$lines2 = array();
			$lines3 = array();
			do
			{
				if ($currline < $offset || $removelines > 0)
				{
					// Load DATA chunk.
					if (!count($lines2))
					{
						if ($nextread >= $nextread2)
						{
							if ($currline < $offset)  $offset = $currline;
							else  $removelines = 0;
						}
						else
						{
							$result = $this->ifds->GetObjectByID($rinfo["entries"][$nextread]["id"]);
							if (!$result["success"])  return $result;

							$obj = $result["obj"];

							$result = $this->ReadDataInternal($obj);
							if (!$result["success"])  return $result;

							$lines2 = explode($this->metadata["newline"], $result["data"]);

							$y2 = count($lines2) - 1;
							if ($y2 >= 0 && $lines2[$y2] === "")  array_pop($lines2);

							$rinfo["entries"][$nextread]["lines"] = $y2;

							$nextread++;

							if ($nextread >= $nextread2 && $readsuper < $y - 1)
							{
								$readsuper++;
								$rinfo = &$this->rootentries[$readsuper];

								$nextread = 0;
								$nextread2 = count($rinfo["entries"]);
							}
						}
					}

					if (count($lines2))
					{
						if ($currline < $offset)
						{
							foreach ($lines2 as $num => $line)
							{
								$size += strlen($line) + $newlinelen;

								$lines3[] = $line;

								$currline++;

								unset($lines2[$num]);

								if ($currline >= $offset || $size > 65527)  break;
							}
						}
						else if ($removelines > 0)
						{
							foreach ($lines2 as $num => $line)
							{
								unset($lines2[$num]);

								$removelines--;

								if ($removelines < 1)  break;
							}
						}
					}
				}
				else if (count($lines))
				{
					// Append new lines.
					foreach ($lines as $num => $line)
					{
						$size += strlen($line) + $newlinelen;

						$lines3[] = $line;

						unset($lines[$num]);

						if ($size > 65527)  break;
					}
				}
				else if (count($lines2))
				{
					// Append remaining original lines that are left.
					foreach ($lines2 as $num => $line)
					{
						$size += strlen($line) + $newlinelen;

						$lines3[] = $line;

						unset($lines2[$num]);

						if ($size > 65527)  break;
					}
				}
				else if ($size > 0)
				{
					// Force the remaining lines to be written.
					$last = true;
				}
				else
				{
					break;
				}

				// The 65527 size check is not an accident.  65528 bytes is the maximum IFDS DATA chunk size but requires a terminating DATA chunk to follow.
				// 65527 bytes allows the single DATA chunk to also be a terminating DATA chunk.
				if ($size > 65527 || $last)
				{
					if ($size > 65527)
					{
						// Attempt to split the chunk in approximately half.
						$size2 = 0;
						$lines4 = array();
						foreach ($lines3 as $num => $line)
						{
							$size2 += strlen($line) + $newlinelen;

							$lines4[] = $line;

							unset($lines3[$num]);

							if ($size2 > 32767)  break;
						}

						$size -= $size2;
					}
					else
					{
						$size = 0;
						$lines4 = $lines3;
						$lines3 = array();
					}

					// Write the DATA chunk.
					if ($nextwrite === $nextwrite2 || ($writesuper === $readsuper && $nextwrite === $nextread))
					{
						// Split the super text chunk.
						if ($nextwrite >= 65536)
						{
							$result = $this->CreateSuperTextChunk($writesuper + 1);
							if (!$result["success"])  return $result;

							if ($writesuper < $readsuper)  $readsuper++;
							else  $nextread -= 32768;

							$this->rootentries[$writesuper + 1]["entries"] = array_splice($winfo["entries"], 32768, $nextwrite - 32768);

							// Recalculate the number of lines.
							$winfo = &$this->rootentries[$writesuper];

							$winfo["lines"] = 0;
							foreach ($winfo["entries"] as &$winfo2)  $winfo["lines"] += $winfo2["lines"];

							$writesuper++;

							$winfo = &$this->rootentries[$writesuper];

							$winfo["lines"] = 0;
							foreach ($winfo["entries"] as &$winfo2)  $winfo["lines"] += $winfo2["lines"];

							$nextwrite -= 32768;
							$nextwrite2 = $nextwrite;
						}

						$result = $this->ifds->CreateRawData(IFDS::ENCODER_RAW);
						if (!$result["success"])  return $result;

						$obj = $result["obj"];

						array_splice($winfo["entries"], $nextwrite, 0, array(array("lines" => 0, "id" => $obj->GetID())));

						$nextwrite2++;

						if ($writesuper === $readsuper)
						{
							$nextread++;
							$nextread2 = $nextwrite2;
						}
					}

					$y2 = count($lines4);
					$diff = $y2 - $winfo["entries"][$nextwrite]["lines"];

					$winfo["lines"] += $diff;
					$winfo["entries"][$nextwrite]["lines"] = $y2;

					$lines4[] = "";

					$lines4 = implode($this->metadata["newline"], $lines4);

					$result = $this->ifds->GetObjectByID($winfo["entries"][$nextwrite]["id"]);
					if (!$result["success"])  return $result;

					$obj = $result["obj"];

					$result = $this->WriteDataInternal($obj, $lines4);
					if (!$result["success"])  return $result;

					$nextwrite++;
				}

			} while (1);

			// Delete empty objects.
			while (($writesuper < $readsuper && $writesuper < $y) || $nextwrite < $nextwrite2)
			{
				while ($nextwrite < $nextwrite2)
				{
					$result = $this->ifds->DeleteObject($winfo["entries"][$nextwrite2 - 1]["id"]);
					if (!$result["success"])  return $result;

					unset($winfo["entries"][$nextwrite2 - 1]);

					$nextwrite2--;
				}

				$writesuper++;

				if ($writesuper < $y)
				{
					$winfo = &$this->rootentries[$writesuper];

					$nextwrite = 0;
					$nextwrite2 = count($winfo["entries"]);
				}
			}

			$this->mod = true;

			return array("success" => true);
		}

		public function ReadLines($offset, $numlines, $ramlimit = 10485760)
		{
			if ($this->ifds === false)  return array("success" => false, "error" => self::IFDSTextTranslate("Unable to write data.  File is not open."), "errorcode" => "file_not_open");

			// Find the offset in the root.
			$currline = 0;
			$y = count($this->rootentries);
			for ($x = 0; $x < $y && $offset > $currline + $this->rootentries[$x]["lines"]; $x++)  $currline += $this->rootentries[$x]["lines"];

			if ($x >= $y)  return array("success" => true, "lines" => array(), "eof" => true);

			// Find the offset in the super text chunk.
			$result = $this->LoadSuperTextChunk($x);
			if (!$result["success"])  return $result;

			$rinfo = &$this->rootentries[$x];

			$y2 = count($rinfo["entries"]);
			for ($x2 = 0; $x2 < $y2 && $offset > $currline + $rinfo["entries"][$x2]["lines"]; $x2++)  $currline += $rinfo["entries"][$x2]["lines"];

			// Read lines in until either the memory limit or the line limit is reached.
			$size = 0;
			$newlinelen = strlen($this->metadata["newline"]);
			$lines = array();
			$lines2 = array();
			while ($numlines > 0 && $size < $ramlimit)
			{
				// Load DATA chunk.
				if (!count($lines2))
				{
					if ($x2 >= $y2)
					{
						if ($currline < $offset)  $offset = $currline;
						else  break;
					}
					else
					{
						$result = $this->ifds->GetObjectByID($rinfo["entries"][$x2]["id"]);
						if (!$result["success"])  return $result;

						$obj = $result["obj"];

						$result = $this->ReadDataInternal($obj);
						if (!$result["success"])  return $result;

						$lines2 = explode($this->metadata["newline"], $result["data"]);

						$y3 = count($lines2) - 1;
						if ($y3 >= 0 && $lines2[$y3] === "")  array_pop($lines2);

						if ($rinfo["entries"][$x2]["lines"] !== $y3)
						{
							$rinfo["entries"][$x2]["lines"] = $y3;

							$this->mod = true;
						}

						$x2++;

						if ($x2 >= $y2)
						{
							$x++;

							if ($x < $y)
							{
								$rinfo = &$this->rootentries[$x];

								$x2 = 0;
								$y2 = count($rinfo["entries"]);
							}
						}
					}
				}

				if (count($lines2))
				{
					if ($currline < $offset)
					{
						foreach ($lines2 as $num => $line)
						{
							$currline++;

							unset($lines2[$num]);

							if ($currline >= $offset)  break;
						}
					}
					else
					{
						foreach ($lines2 as $num => $line)
						{
							$size += strlen($line) + $newlinelen;

							unset($lines2[$num]);

							$lines[] = $line;

							$numlines--;

							if ($numlines < 1 || $size >= $ramlimit)  break;
						}
					}
				}
			}

			$eof = ($x >= $y && $x2 >= $y2 && !count($lines2));

			if ($eof && $this->IsTrailingNewlineEnabled())  $lines[] = "";

			return array("success" => true, "lines" => &$lines, "eof" => $eof);
		}

		public static function IFDSTextTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>