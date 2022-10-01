<?php
	// Generic paging file cache class.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	class PagingFileCache
	{
		protected $open, $fp, $readable, $writable, $seekable, $basepos, $currpos, $maxpos, $realcurrpos, $realmaxpos, $seekpos;
		protected $pagemap, $recentpages, $pagesize, $reservedbytes, $realpagesize, $maxpages;

		const PAGING_FILE_MODE_READ = 1;
		const PAGING_FILE_MODE_WRITE = 2;

		const PAGING_FILE_PAGE_NOT_LOADED = 0;
		const PAGING_FILE_PAGE_LOADED = 1;
		const PAGING_FILE_PAGE_MODIFIED = 2;
		const PAGING_FILE_PAGE_MODIFIED_LAST = 3;

		public function __construct()
		{
			$this->open = false;
			$this->fp = false;
			$this->realpagesize = 4096;
			$this->reservedbytes = 0;
			$this->maxpages = 2048;

			$this->Close();
		}

		public function __destroy()
		{
			$this->Close();
		}

		public function GetPageSize()
		{
			return $this->pagesize;
		}

		public function GetRealPageSize()
		{
			return $this->realpagesize;
		}

		public function SetRealPageSize($size)
		{
			$this->realpagesize = $size;

			$this->pagesize = $this->realpagesize - $this->reservedbytes;
		}

		public function GetPageReservedBytes()
		{
			return $this->reservedbytes;
		}

		// The number of bytes guaranteed to be reserved per page.  Useful for encryption operations.
		public function SetPageReservedBytes($numbytes)
		{
			$this->reservedbytes = $numbytes;

			$this->pagesize = $this->realpagesize - $this->reservedbytes;
		}

		public function GetMaxCachedPages()
		{
			return $this->maxpages;
		}

		public function SetMaxCachedPages($maxpages)
		{
			$this->maxpages = $maxpages;
		}

		public function GetNumCachedPages()
		{
			return count($this->pagemap);
		}

		// Maps a virtual position to a real position.
		public function GetRealPos($pos)
		{
			return ((int)($pos / $this->pagesize) * $this->realpagesize) + ($pos % $this->pagesize);
		}

		// Maps a real position to a virtual position.  Note that this does not, by design, work properly when the position is in the reserved bytes space.
		public function GetVirtualPos($pos)
		{
			return ((int)($pos / $this->realpagesize) * $this->pagesize) + ($pos % $this->realpagesize);
		}

		public function SetData($data, $mode = self::PAGING_FILE_MODE_READ | self::PAGING_FILE_MODE_WRITE)
		{
			$this->Close();

			$this->fp = str_split($data, $this->realpagesize);

			$this->open = true;

			$this->readable = ($mode & self::PAGING_FILE_MODE_READ ? true : false);
			$this->writable = ($mode & self::PAGING_FILE_MODE_WRITE ? true : false);
			$this->seekable = ($mode & self::PAGING_FILE_MODE_READ ? true : false);

			$this->realmaxpos = strlen($data);

			$this->maxpos = $this->GetVirtualPos($this->realmaxpos - $this->reservedbytes);

			return array("success" => true);
		}

		public function Open($filename, $mode = self::PAGING_FILE_MODE_READ | self::PAGING_FILE_MODE_WRITE)
		{
			$this->Close();

			if ($mode & 0x03 === self::PAGING_FILE_MODE_READ | self::PAGING_FILE_MODE_WRITE)  $mode2 = (file_exists($filename) ? "r+b" : "w+b");
			else if ($mode & 0x03 === self::PAGING_FILE_MODE_READ)  $mode2 = "rb";
			else if ($mode & 0x03 === self::PAGING_FILE_MODE_WRITE)  $mode2 = (file_exists($filename) ? "r+b" : "wb");
			else  return array("success" => false, "error" => self::PFCTranslate("Invalid mode specified."), "errorcode" => "invalid_mode");

			$this->fp = @fopen($filename, $mode2);
			if ($this->fp === false)  return array("success" => false, "error" => self::PFCTranslate("Unable to open the file."), "errorcode" => "fopen_failed");

			$this->open = true;
			$this->readable = ($mode & self::PAGING_FILE_MODE_READ ? true : false);
			$this->writable = ($mode & self::PAGING_FILE_MODE_WRITE ? true : false);

			$metadata = stream_get_meta_data($this->fp);
			$this->seekable = $metadata["seekable"];

			if (!$this->seekable)  $this->realmaxpos = PHP_INT_MAX;
			else
			{
				fseek($this->fp, 0, SEEK_END);
				$this->realmaxpos = ftell($this->fp);
				fseek($this->fp, 0, SEEK_SET);
			}

			$this->maxpos = $this->GetVirtualPos($this->realmaxpos - $this->reservedbytes);

			return array("success" => true);
		}

		public function Close()
		{
			$this->Sync(true);

			if ($this->fp !== false && is_resource($this->fp))  fclose($this->fp);

			$this->fp = false;
			$this->readable = false;
			$this->writable = false;
			$this->seekable = false;
			$this->basepos = 0;
			$this->currpos = 0;
			$this->maxpos = 0;
			$this->realcurrpos = false;
			$this->realmaxpos = 0;
			$this->seekpos = 0;

			$this->pagemap = array();
			$this->recentpages = array();
			$this->pagesize = $this->realpagesize - $this->reservedbytes;
		}

		public function CanRead()
		{
			return $this->readable;
		}

		public function CanWrite()
		{
			return $this->writable;
		}

		public function CanSeek()
		{
			return $this->seekable;
		}

		public function GetCurrPos()
		{
			return $this->currpos;
		}

		public function GetMaxPos()
		{
			return $this->maxpos;
		}

		public function Seek($offset, $whence = SEEK_SET)
		{
			if (!$this->open)  return array("success" => false, "error" => self::PFCTranslate("Unable to seek.  File is not open."), "errorcode" => "file_not_open");
			if (!$this->seekable)  return array("success" => false, "error" => self::PFCTranslate("Unable to seek.  File is not seekable."), "errorcode" => "file_not_seekable");

			if ($whence === SEEK_SET)  $this->currpos = $offset;
			else if ($whence === SEEK_CUR)  $this->currpos += $offset;
			else if ($whence === SEEK_END)  $this->currpos = $this->maxpos + $offset;
			else  return array("success" => false, "error" => self::PFCTranslate("Invalid whence."), "errorcode" => "invalid_whence");

			if ($this->currpos < 0)  $this->currpos = 0;
			else if ($this->currpos > $this->maxpos)  $this->currpos = $this->maxpos;

			$this->realcurrpos = false;

			return array("success" => true);
		}

		public function Read($numbytes)
		{
			if (!$this->open)  return array("success" => false, "error" => self::PFCTranslate("Unable to read.  File is not open."), "errorcode" => "file_not_open");
			if (!$this->readable)  return array("success" => false, "error" => self::PFCTranslate("Unable to read.  File is not readable."), "errorcode" => "file_not_readable");

			$data = "";
			while ($numbytes > 0)
			{
				$result = $this->LoadPageForCurrPos();
				if (!$result["success"])  return $result;

				$y = strlen($this->pagemap[$result["pos"]][0]);
				if ($y < 1 || $y <= $result["offset"])  break;

				$size = ($y > $result["offset"] + $numbytes ? $numbytes : $y - $result["offset"]);
				$data .= ($size === $y ? $this->pagemap[$result["pos"]][0] : substr($this->pagemap[$result["pos"]][0], $result["offset"], $size));

				$numbytes -= $size;
				$this->currpos += $size;
				$this->realcurrpos = false;
			}

			return array("success" => true, "data" => $data, "eof" => ($numbytes > 0));
		}

		public function ReadUntil($matches, $options = array())
		{
			if (!$this->open)  return array("success" => false, "error" => self::PFCTranslate("Unable to read.  File is not open."), "errorcode" => "file_not_open");
			if (!$this->readable)  return array("success" => false, "error" => self::PFCTranslate("Unable to read.  File is not readable."), "errorcode" => "file_not_readable");

			if (!isset($options["include_match"]))  $options["include_match"] = true;
			if (!isset($options["rewind_match"]))  $options["rewind_match"] = false;
			if (!isset($options["regex_match"]))  $options["regex_match"] = false;
			if (!isset($options["return_data"]))  $options["return_data"] = true;

			if (!isset($options["min_buffer"]) || is_bool($options["min_buffer"]))
			{
				$options["min_buffer"] = 0;
				foreach ($matches as &$match)
				{
					$y = strlen($match);

					if ($options["min_buffer"] < $y)  $options["min_buffer"] = $y;
				}
			}

			$origcurrpos = $this->currpos;
			$data = "";
			$startpos = 0;
			$pos = false;
			$matchlen = 0;
			do
			{
				$result = $this->LoadPageForCurrPos();
				if (!$result["success"])  return $result;

				$y = strlen($this->pagemap[$result["pos"]][0]);
				if ($y < 1 || $y <= $result["offset"])  return array("success" => true, "data" => $data, "eof" => true);

				$data .= substr($this->pagemap[$result["pos"]][0], $result["offset"]);
				$y2 = strlen($data);

				foreach ($matches as &$match)
				{
					if (!$options["regex_match"])  $pos2 = strpos($data, $match, $startpos);
					else if (!preg_match($match, $data, $matches2, PREG_OFFSET_CAPTURE, $startpos))  $pos2 = false;
					else  $pos2 = $matches2[0][1];

					if ($pos2 !== false && $pos2 < $y2 - strlen($match) && ($pos === false || $pos > $pos2))
					{
						$pos = $pos2;

						$matchlen = strlen($match);
					}
				}

				if ($pos === false)
				{
					$this->currpos += $y - $result["offset"];

					if ($options["return_data"])
					{
						$startpos = $y2 - $options["min_buffer"];

						if ($startpos < 0)  $startpos = 0;
					}
					else if ($y2 > $options["min_buffer"])
					{
						$origcurrpos += $y2 - $options["min_buffer"];

						$data = substr($data, -$options["min_buffer"]);
					}
				}

				$this->realcurrpos = false;
			} while ($pos === false);

			$this->currpos = $origcurrpos + $pos + ($options["rewind_match"] ? 0 : $matchlen);

			if ($options["return_data"])  $data = substr($data, 0, $pos + ($options["include_match"] ? $matchlen : 0));
			else  $data = ($options["include_match"] ? substr($data, $pos, $matchlen) : "");

			return array("success" => true, "data" => $data, "eof" => false);
		}

		public function ReadLine($includenewline = true)
		{
			$result = $this->ReadUntil(array("\r\n", "\r", "\n"));
			if (!$result["success"])  return $result;

			if (!$includenewline)  $result["data"] = rtrim($result["data"], "\r\n");

			return $result;
		}

		public function ReadCSV($nulls = false, $separator = ",", $enclosure = "\"")
		{
			$record = array();
			$val = null;
			$enclosed = false;
			do
			{
				$result = $this->ReadLine();
				if (!$result["success"])  return $result;

				$y = strlen($result["data"]);
				for ($x = 0; $x < $y; $x++)
				{
					if ($enclosed)
					{
						if ($result["data"][$x] !== $enclosure)  $val .= $result["data"][$x];
						else if ($x + 1 <= $y && $result["data"][$x + 1] === $enclosure)
						{
							$val .= $result["data"][$x];
							$x++;
						}
						else  $enclosed = false;
					}
					else if ($result["data"][$x] === $enclosure)
					{
						if ($val === null)  $val = "";

						$enclosed = true;
					}
					else if ($result["data"][$x] === "\r" || $result["data"][$x] === "\n")  break;
					else if ($result["data"][$x] === $separator)
					{
						if (!$nulls && $val === null)  $val = "";

						$record[] = $val;

						$val = null;
					}
					else
					{
						if ($val === null)  $val = "";

						$val .= $result["data"][$x];
					}
				}

				if ($result["eof"])  break;
			} while ($enclosed);

			if (!$nulls && $val === null)  $val = "";

			$record[] = $val;

			return array("success" => true, "record" => $record, "eof" => $result["eof"]);
		}

		public function Write($data)
		{
			if (!$this->open)  return array("success" => false, "error" => self::PFCTranslate("Unable to write.  File is not open."), "errorcode" => "file_not_open");
			if (!$this->writable)  return array("success" => false, "error" => self::PFCTranslate("Unable to write.  File is not writable."), "errorcode" => "file_not_writable");

			$data = (string)$data;
			$x = 0;
			$y = strlen($data);
			while ($x < $y)
			{
				if ($this->realcurrpos === false)  $this->realcurrpos = $this->GetRealPos($this->currpos);

				$pagepos = $this->realcurrpos - ($this->realcurrpos % $this->realpagesize);

				if (!isset($this->pagemap[$pagepos]))
				{
					if ($pagepos === $this->realcurrpos && $this->realcurrpos === $this->realmaxpos)
					{
						// Page aligned and at the end of the file.  Create a new page.
						$this->pagemap[$pagepos] = array("", self::PAGING_FILE_PAGE_LOADED);
					}
					else
					{
						// Attempt to load the page.
						$result = $this->LoadPageForCurrPos();
						if (!$result["success"])  return $result;
					}
				}

				if ($this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_NOT_LOADED)
				{
					if (!$this->PostLoadPageData($this->pagemap[$pagepos][0], $pagepos))  return array("success" => false, "error" => self::PFCTranslate("Unable to process loaded page data."), "errorcode" => "post_load_page_data_failed");

					$this->pagemap[$pagepos][1] = self::PAGING_FILE_PAGE_LOADED;
				}

				unset($this->recentpages[$pagepos]);
				$this->recentpages[$pagepos] = true;

				// Copy data up to page size.
				$x2 = $this->currpos % $this->pagesize;
				$y2 = strlen($this->pagemap[$pagepos][0]);
				$diff = $y2 - $x2;
				if ($diff <= $y - $x)
				{
					$this->pagemap[$pagepos][0] = ($x2 === 0 ? "" : substr($this->pagemap[$pagepos][0], 0, $x2));
					$this->pagemap[$pagepos][0] .= substr($data, $x, $diff);

					$x += $diff;
					$x2 = $y2;
					$this->currpos += $diff;
				}
				else if ($x2 === 0)
				{
					$diff = $y - $x;
					$tempdata = substr($this->pagemap[$pagepos][0], $y - $x);
					$this->pagemap[$pagepos][0] = ($x == 0 ? $data : substr($data, $x));
					$this->pagemap[$pagepos][0] .= $tempdata;

					$x = $y;
					$x2 += $diff;
					$this->currpos += $diff;
				}
				else
				{
					// PHP is very slow when appending one byte at a time to a string.
					while ($x2 < $y2 && $x < $y)
					{
						$this->pagemap[$pagepos][0][$x2] = $data[$x];

						$x++;
						$x2++;
						$this->currpos++;
					}
				}

				if ($y2 < $this->pagesize && $x < $y)
				{
					$size = ($this->pagesize - $y2 < $y - $x ? $this->pagesize - $y2 : $y - $x);
					$this->pagemap[$pagepos][0] .= ($x == 0 && $size == $y ? $data : substr($data, $x, $size));

					$x += $size;
					$x2 += $size;
					$this->currpos += $size;
				}

				if ($this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_LOADED)
				{
					$maxpagepos = $this->realmaxpos - ($this->realmaxpos % $this->realpagesize);

					$this->pagemap[$pagepos][1] = ($pagepos === $maxpagepos ? self::PAGING_FILE_PAGE_MODIFIED_LAST : self::PAGING_FILE_PAGE_MODIFIED);
				}

				// Adjust position.
				$this->realcurrpos = false;

				if ($this->maxpos < $this->currpos)
				{
					$this->maxpos = $this->currpos;
					$this->realmaxpos = $this->GetRealPos($this->maxpos);
					$this->realcurrpos = $this->realmaxpos;
				}

				// Write out the last page if it was filled.
				if ($x2 === $this->pagesize && $this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_MODIFIED_LAST)
				{
					$result = $this->SavePage($pagepos);
					if (!$result["success"])  return $result;

					$this->UnloadExcessPages();
				}
			}

			return array("success" => true, "bytes" => $y);
		}

		public function WriteCSV($record)
		{
			$data = array();

			foreach ($record as $val)
			{
				$data[] = ($val === null ? "" : "\"" . str_replace(array("\r\n", "\r", "\""), array("\n", "\n", "\"\""), $val) . "\"");
			}

			$data = implode(",", $data) . "\n";

			return $this->Write($data);
		}

		public function Sync($final = false)
		{
			if (!$this->open)  return array("success" => false, "error" => self::PFCTranslate("Unable to sync.  File is not open."), "errorcode" => "file_not_open");
			if (!$this->writable)  return array("success" => false, "error" => self::PFCTranslate("Unable to sync.  File is not writable."), "errorcode" => "file_not_writable");

			foreach ($this->recentpages as $pagepos => $val)
			{
				if ($this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_MODIFIED || ($final && $this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_MODIFIED_LAST))
				{
					$result = $this->SavePage($pagepos);
					if (!$result["success"])  return $result;
				}
			}

			if ($final)  $this->writable = false;

			return array("success" => true);
		}

		// Returns data only for non-filename instances.  Clears the data in write-only mode.
		public function GetData()
		{
			if ($this->fp === false || is_resource($this->fp))  return false;

			$result = implode("", $this->fp);

			if (!$this->readable)
			{
				$y = strlen($result);
				$this->fp = array();

				// Unload pages.
				$pagepos = $this->basepos - ($this->basepos % $this->realpagesize);
				$this->basepos += $y;
				$maxpagepos = $this->basepos - ($this->basepos % $this->realpagesize);

				for (; $pagepos < $maxpagepos; $pagepos += $this->realpagesize)
				{
					unset($this->pagemap[$pagepos]);
					unset($this->recentpages[$pagepos]);
				}
			}

			return $result;
		}

		// Seeks to a position in a file if possible.
		protected function InternalSeek($pos)
		{
			if ($pos !== $this->seekpos)
			{
				if (is_resource($this->fp))
				{
					if ($this->seekable)
					{
						if (@fseek($this->fp, $pos, SEEK_SET) < 0)  return false;
					}
					else if ($pos > $this->seekpos)
					{
						// Seek forward by reading data.
						$bytesleft = $pos - $this->seekpos;
						while ($bytesleft > 0 && !feof($this->fp))
						{
							$tempdata = @fread($this->fp, ($bytesleft >= 1048576 ? 1048576 : $bytesleft));
							if ($tempdata === false)  return false;

							$bytesleft -= strlen($tempdata);
						}
					}
					else
					{
						return false;
					}
				}
				else if ($pos < $this->basepos)
				{
					return false;
				}

				$this->seekpos = $pos;
			}

			return true;
		}

		protected function UnloadExcessPages()
		{
			$y = count($this->pagemap);
			if ($y > $this->maxpages)
			{
				$num = (int)($y / 4);

				// Save modified page chunks.
				$num2 = 0;
				foreach ($this->recentpages as $pagepos => $val)
				{
					if ($this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_MODIFIED)  $this->SavePage($pagepos);

					if ($this->pagemap[$pagepos][1] !== self::PAGING_FILE_PAGE_MODIFIED_LAST)
					{
						unset($this->pagemap[$pagepos]);
						unset($this->recentpages[$pagepos]);
					}

					$num2++;
					if ($num2 >= $num)  break;
				}
			}
		}

		protected function LoadPageForCurrPos()
		{
			if ($this->realcurrpos === false)  $this->realcurrpos = $this->GetRealPos($this->currpos);

			$pagepos = $this->realcurrpos - ($this->realcurrpos % $this->realpagesize);

			// Load pages.
			if (!isset($this->pagemap[$pagepos]))
			{
				if (!$this->readable)  return array("success" => false, "error" => self::PFCTranslate("Unable to read page chunk.  File is not open for reading."), "errorcode" => "no_read");

				if (!$this->InternalSeek($pagepos))  return array("success" => false, "error" => self::PFCTranslate("Unable to seek to page start."), "errorcode" => "seek_failed");

				if (is_resource($this->fp))
				{
					$data = "";
					$size = $this->realpagesize;
					while ($size > 0 && !feof($this->fp))
					{
						$tempdata = @fread($this->fp, $size);
						if ($tempdata === false)  break;

						$data .= $tempdata;
						$size -= strlen($tempdata);
						$this->seekpos += strlen($tempdata);
					}
				}
				else
				{
					$pagenum = (int)($pagepos / $this->realpagesize);
					$data = (isset($this->fp[$pagenum]) ? $this->fp[$pagenum] : "");
					$this->seekpos += strlen($data);
				}

				$y = strlen($data);
				if ($y > 0)  $this->pagemap[$pagepos] = array($data, self::PAGING_FILE_PAGE_NOT_LOADED);
				else if (!isset($this->pagemap[$pagepos]))  $this->pagemap[$pagepos] = array("", self::PAGING_FILE_PAGE_LOADED);

				$this->UnloadExcessPages();
			}

			if ($this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_NOT_LOADED)
			{
				if (!$this->PostLoadPageData($this->pagemap[$pagepos][0], $pagepos))  return array("success" => false, "error" => self::PFCTranslate("Unable to process loaded page data."), "errorcode" => "post_load_page_data_failed");

				$this->pagemap[$pagepos][1] = self::PAGING_FILE_PAGE_LOADED;
			}

			unset($this->recentpages[$pagepos]);
			$this->recentpages[$pagepos] = true;

			return array("success" => true, "pos" => $pagepos, "offset" => $this->realcurrpos - $pagepos);
		}

		// Designed to be overridden by other classes that might decrypt per-page data.
		protected function PostLoadPageData(&$data, $pagepos)
		{
			return true;
		}

		// Saves sequential modified whole pages near to the specified page.
		protected function SavePage($pagepos)
		{
			if (isset($this->pagemap[$pagepos]) && ($this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_MODIFIED || $this->pagemap[$pagepos][1] === self::PAGING_FILE_PAGE_MODIFIED_LAST))
			{
				// Generate data to write.
				$data = $this->pagemap[$pagepos][0];

				if (!$this->PreSavePageData($data, $pagepos))  return array("success" => false, "error" => self::PFCTranslate("Unable to process page data for saving."), "errorcode" => "pre_save_page_data_failed");

				$this->pagemap[$pagepos][1] = self::PAGING_FILE_PAGE_LOADED;

				// Write data.
				if (!$this->InternalSeek($pagepos))  return array("success" => false, "error" => self::PFCTranslate("Unable to seek to page start."), "errorcode" => "seek_failed");

				$y = strlen($data);

				if (is_resource($this->fp))
				{
					$x = 0;
					while ($x < $y)
					{
						$x2 = @fwrite($this->fp, $data);
						if ($x2 < 1)  break;

						$data = substr($data, $x2);
						$this->seekpos += $x2;
						$x += $x2;
					}

					if ($x < $y)  return array("success" => false, "error" => self::PFCTranslate("Unable to write page data."), "errorcode" => "fwrite_failed");
				}
				else
				{
					$this->fp[(int)($pagepos / $this->realpagesize)] = $data;

					$this->seekpos += $y;
				}
			}

			return array("success" => true);
		}

		// Designed to be overridden by other classes that might encrypt per-page data.
		protected function PreSavePageData(&$data, $pagepos)
		{
			return true;
		}

		public static function PFCTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>