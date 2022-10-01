PagingFileCache Class:  'support/paging_file_cache.php'
=======================================================

The PagingFileCache class applies a page cache layer over physical file and in-memory data.  By default, each page is 4,096 bytes and the class will cache up to 2,048 pages (8MB) in RAM but the defaults can, of course, be adjusted as desired.  This allows for very large, structured files to be quickly navigated, keeping the most frequently used pages in RAM.  Released under a MIT or LGPL license, your choice.

Supports reserved bytes to be able to extend this class to encrypt the data of each individual page.

Also supports streaming for dynamically generating content, reading and writing lines, reading until a match is found, and reading and writing CSV records.

Example usage can be seen in the test suite.

PagingFileCache::GetPageSize()
------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the real page size minus reserved bytes.

This function returns the actual amount of data per page.

PagingFileCache::GetRealPageSize()
----------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the real page size.

This function returns the real page size including reserved bytes.

PagingFileCache::SetRealPageSize($size)
---------------------------------------

Access:  public

Parameters:

* $size - An integer containing the number of bytes to use for each page.

Returns:  Nothing.

This function sets the real page size and recalculates the amount of data per page.

PagingFileCache::GetPageReservedBytes()
---------------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the number of reserved bytes per page.

This function returns the number of reserved bytes per page.

PagingFileCache::SetPageReservedBytes($numbytes)
------------------------------------------------

Access:  public

Parameters:

* $numbytes - An integer containing the number of bytes to reserve per page.

Returns:  Nothing.

This function sets the number of bytes to reserve per page and recalculates the amount of data per page.  Reserved bytes are useful for encryption operations (e.g. padding) and validation operations (e.g. CRC-32).

PagingFileCache::GetMaxCachedPages()
------------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the maximum number of cached pages to keep in memory.

This function returns the maximum number of cached pages to keep in memory.

PagingFileCache::SetMaxCachedPages($maxpages)
---------------------------------------------

Access:  public

Parameters:

* $maxpages - An integer containing the maximum number of cached pages to keep in memory.

Returns:  Nothing.

This function sets the maximum number of cached pages to keep in memory.

PagingFileCache::GetNumCachedPages()
------------------------------------

Access:  public

Parameters:  None.

Returns:  The current number of cached pages.

This function returns the current number of cached pages in the page map.

PagingFileCache::GetRealPos($pos)
---------------------------------

Access:  public

Parameters:

* $pos - An integer containing a virtual position.

Returns:  An integer containing a real position.

This function maps a virtual position to a real position.  When pages contain reserved bytes, positions need to be adjusted to point at the actual location.

PagingFileCache::GetVirtualPos($pos)
------------------------------------

Access:  public

Parameters:

* $pos - An integer containing a real position.

Returns:  An integer containing a virtual position.

This function maps a real position to a virtual position.

Note that this function does not, by design, work properly when the position is in the reserved bytes space.

PagingFileCache::SetData($data, $mode = PagingFileCache::PAGING_FILE_MODE_READ | PagingFileCache::PAGING_FILE_MODE_WRITE)
-------------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $data - A string containing the raw data to use in place of a file.
* $mode - An integer containing the mode to open the data as (Default is PagingFileCache::PAGING_FILE_MODE_READ | PagingFileCache::PAGING_FILE_MODE_WRITE).

Returns:  A standard array of information.

This function sets the raw data to use in place of a file.  Can be used to perform all operations in memory.

The class becomes streaming enabled when $data is an empty string and $mode is write only (i.e. PagingFileCache::PAGING_FILE_MODE_WRITE).

Example usage:

```php
<?php
	require_once "support/paging_file_cache.php";

	$pfc = new PagingFileCache();

	// Start streaming mode.
	$result = $pfc->SetData("", PagingFileCache::PAGING_FILE_MODE_WRITE);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$data = "";
	for ($x = 1; $x < 5000; $x++)
	{
		// Write data into the stream.
		$result = $pfc->Write("Line " . $x . "\n");
		if (!$result["success"])
		{
			var_dump($result);

			exit();
		}

		// Collect data as it becomes available.
		$result = $pfc->GetData();
		if ($result === false)
		{
			echo "Failed to get data.\n";

			exit();
		}

		$data .= $result;
	}

	// Finalize remaining data.
	$result = $pfc->Sync(true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	// Collect final data.
	$result = $pfc->GetData();
	if ($result === false)
	{
		echo "Failed to get data.\n";

		exit();
	}

	$data .= $result;

	echo $data . "\n";
?>
```

PagingFileCache::Open($filename, $mode = PagingFileCache::PAGING_FILE_MODE_READ | PagingFileCache::PAGING_FILE_MODE_WRITE)
--------------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $filename - A string containing a filename to open.
* $mode - An integer containing the mode to open the data as (Default is PagingFileCache::PAGING_FILE_MODE_READ | PagingFileCache::PAGING_FILE_MODE_WRITE).

Returns:  A standard array of information.

This function opens the specified file.  If the file doesn't exist, then it is created unless it is being opened read only.

PagingFileCache::Close()
------------------------

Access:  public

Parameters:  None.

Returns:  Nothing.

This function performs a final synchronization and resets internal structures.  Automatically called by the destructor.

PagingFileCache::CanRead()
--------------------------

Access:  public

Parameters:  None.

Returns:  A boolean indicating whether or not the file cache is readable.

This function returns whether or not the file cache is readable.

PagingFileCache::CanWrite()
---------------------------

Access:  public

Parameters:  None.

Returns:  A boolean indicating whether or not the file cache is writable.

This function returns whether or not the file cache is writable.

PagingFileCache::CanSeek()
--------------------------

Access:  public

Parameters:  None.

Returns:  A boolean indicating whether or not the file cache is seekable.

This function returns whether or not the file cache is seekable.

PagingFileCache::GetCurrPos()
-----------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the current file cache virtual position.

This function returns the current virtual position within the file cache.

PagingFileCache::GetMaxPos()
----------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the current file cache maximum virtual position.

This function returns the current maximum virtual position within the file cache.

PagingFileCache::Seek($offset, $whence = SEEK_SET)
--------------------------------------------------

Access:  public

Parameters:

* $offset - An integer containing an offset.
* $whence - An integer containing one of SEEK_SET, SEEK_CUR, SEEK_END (Default SEEK_SET).

Returns:  A standard array of information.

This function sets the virtual seek position if the file cache is seekable.

PagingFileCache::Read($numbytes)
--------------------------------

Access:  public

Parameters:

* $numbytes - An integer containing the number of bytes to read.

Returns:  A standard array of information.

This function reads in up to the specified number of bytes from the file cache from the current virtual position.  Adjusts the current virtual position accordingly.  If the end of the file cache is reached, eof will be set to true.

Example usage:

```php
<?php
	require_once "support/paging_file_cache.php";

	$pfc = new PagingFileCache();

	// Open a file.
	$result = $pfc->Open("somefile.dat");
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	// Read the data.
	$data = "";
	do
	{
		$result = $pfc->Read(1024);
		if (!$result["success"])
		{
			var_dump($result);

			exit();
		}

		$data .= $result["data"];
	} while (!$result["eof"]);

	$pfc->Close();

	echo $data . "\n";
?>
```

PagingFileCache::ReadUntil($matches, $options = array())
--------------------------------------------------------

Access:  public

Parameters:

* $matches - An array containing one or more strings to match against.
* $options - An array containing options (Default is array()).

Returns:  A standard array of information.

This function reads data until it finds a match.

The $options array accepts these options:

* include_match - A boolean that indicates whether or not to include the match in the response data (Default is true).
* rewind_match - A boolean that indicates whether or not to rewind to the start of the match (Default is false).
* regex_match - A boolean that indicates whether or not to treat the strings in $matches as regular expressions (Default is false).
* return_data - A boolean that indicates whether or not to return read data (Default is true).  Can be used to seek to the start/end of some data.
* min_buffer - An integer containing the size, in bytes, of the buffer to maintain for matching or a boolean to automatically calculate (Default is to auto-calculate).  This should always be an integer when `regex_match` is true.

PagingFileCache::ReadLine($includenewline = true)
-------------------------------------------------

Access:  public

Parameters:

* $includenewline - A boolean indicating whether or not to return the newline (Default is true).

Returns:  A standard array of information.

This function calls `ReadUntil()` to retrieve content up to and including the next newline and returns it.

PagingFileCache::ReadCSV($nulls = false, $separator = ",", $enclosure = "\"")
-----------------------------------------------------------------------------

Access:  public

Parameters:

* $nulls - A boolean indicating whether or not to treat empty columns as null (Default is false).
* $separator - A string containing a single character column separator (Default is ,).
* $enclosure - A string containing a single character enclosure start/end (Default is ").

Returns:  A standard array of information.

This function reads a CSV record and returns it.

PagingFileCache::Write($data)
-----------------------------

Access:  public

Parameters:

* $data - A string containing the data to write.

Returns:  A standard array of information.

This function writes data to the file cache.

PagingFileCache::WriteCSV($record)
----------------------------------

Access:  public

Parameters:

* $record - An array of values containing the column data to write.

Returns:  A standard array of information.

This function writes a record in the CSV file format.

PagingFileCache::Sync($final = false)
-------------------------------------

Access:  public

Parameters:

* $final - A boolean indicating whether or not the final write operation has occurred (Default is false).

Returns:  A standard array of information.

This function commits unwritten data to disk/memory.  When $final is true, partial data at the end of the file is written and writing is disabled.

PagingFileCache::GetData()
--------------------------

Access:  public

Parameters:  None.

Returns:  A string containing the current raw data in the file cache or a boolean of false if using a real file.

This function returns the raw data set by `SetData()` plus any modifications.  When in streaming/write only mode, this also clears the internal raw data and mapped pages and adjusts the base virtual position.

PagingFileCache::InternalSeek($pos)
-----------------------------------

Access:  protected

Parameters:

* $pos - An integer containing the real position to seek to.

Returns:  A boolean indicating whether or not the seek operation was successful.

This internal function seeks to a real position on disk.  This does not change the virtual position information.

PagingFileCache::UnloadExcessPages()
------------------------------------

Access:  protected

Parameters:  None.

Returns:  Nothing.

This internal function unloads about 25% of the loaded pages in memory when the total number of pages exceeds the maximum allowed.  Modified pages are written to disk/memory before being unloaded.

PagingFileCache::LoadPageForCurrPos()
-------------------------------------

Access:  protected

Parameters:  None.

Returns:  A standard array of information.

This internal function loads the page for the current position from disk/memory.  Returns the pagemap position and offset on success.

PagingFileCache::PostLoadPageData(&$data, $pagepos)
---------------------------------------------------

Access:  protected

Parameters:

* $data - A string reference to the page data to load/modify.
* $pagepos - An integer containing the page position in the page map.

Returns:  A boolean of true if page data was loaded successfully, false otherwise.

This function is designed to be overridden by other classes that might decrypt or validate per-page data.

PagingFileCache::SavePage($pagepos)
-----------------------------------

Access:  protected

Parameters:

* $pagepos - An integer containing the page position in the page map.

Returns:  A standard array of information.

This internal function saves a modified page to disk/memory.

PagingFileCache::PreSavePageData(&$data, $pagepos)
--------------------------------------------------

Access:  protected

Parameters:

* $data - A string reference to the page data to modify/save.
* $pagepos - An integer containing the page position in the page map.

Returns:  A boolean of true if page data was loaded successfully, false otherwise.

This function is designed to be overridden by other classes that might encrypt per-page data.

PagingFileCache::PFCTranslate($format, ...)
-------------------------------------------

Access:  _internal_ static

Parameters:

* $format - A string containing valid sprintf() format specifiers.

Returns:  A string containing a translation.

This internal static function takes input strings and translates them from English to some other language if CS_TRANSLATE_FUNC is defined to be a valid PHP function name.
