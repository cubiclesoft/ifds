IFDS and IFDS_RefCountObj Classes:  'support/ifds.php'
======================================================

The IFDS PHP class is the official reference implementation of the Incredibly Flexible Data Storage (IFDS) file format.  Supports up to 4.2 billion objects and files up to 18EB (2^64) in size with support for multiple data structure and data storage types.  The official reference implementation is offered under a MIT or LGPL license, your choice.

The IFDS_RefCountObj class is used by the IFDS class to track referece-counted objects in use.

Example usage can be seen in the main IFDS documentation and the IFDS test suite.

IFDS::GetMaxRAM()
-----------------

Access:  public

Parameters:  None.

Returns:  An integer containing the current maximum target RAM for cached objects.

This function returns the amount of RAM that the IFDS class aims to limit itself to.  The limit is only used as an estimate rather than actual usage.  The default limit for the IFDS class is approximately 10MB.

IFDS::SetMaxRAM($maxram)
------------------------

Access:  public

Parameters:

* $maxram - An integer containing the new RAM limit to set.

Returns:  Nothing.

This function sets the amount of RAM that the IFDS class will aim to limit itself to.

IFDS::GetEstimatedRAM()
-----------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the estimated amount of RAM used by the object cache.

This function returns the current estimated amount of RAM in use.

IFDS::SetMagic($magic)
----------------------

Access:  public

Parameters:

* $magic - A string to use for the magic string (up to 123 bytes) for the file format or a boolean of false.

Returns:  Nothing.

This function sets the magic string to use/verify when creating/opening a file.  The class default is 'IFDS'.  When set to false, the class will attempt to autodetect the magic string when opening a file.

IFDS::SetTypeEncoder($type, $encodercallback)
---------------------------------------------

Access:  public

Parameters:

* $type - An integer containing an IFDS object structure type (1-62).
* $encodercallback - A function to call when encoding the specified object structure type.

Returns:  Nothing.

This function sets a structure encoder callback for the specified IFDS object structure type.

The callback function is called as:  `callback($obj)`

The callback is expected to return a string to the caller containing the encoded data.

IFDS::SetTypeDecoder($type, $decodercallback)
---------------------------------------------

Access:  public

Parameters:

* $type - An integer containing an IFDS object structure type (1-62).
* $decodercallback - A function to call when decoding the specified object structure type.

Returns:  Nothing.

This function sets a structure decoder callback for the specified IFDS object structure type.

The callback function is called as:  `callback($obj, &$data, $pos, &$size)`

The callback function is expected to return a boolean that indicates whether or not the data was successfully decoded and reduce the size by the number of bytes read.  Reading structure information starts at `$pos` in `$data`.

IFDS::SetTypeDeleteVerifier($type, $deleteverifiercallback)
-----------------------------------------------------------

Access:  public

Parameters:

* $type - An integer containing an IFDS object structure type (1-62).
* $decodercallback - A function to call when deleting an object of the specified object structure type.

Returns:  Nothing.

This function sets a structure deletion verification callback for the specified IFDS object structure type.

The callback function is called as:  `callback($obj)`

The callback is expected to return a boolean that indicates whether or not the object can be safely deleted.

IFDS::CreateObjectIDChunksTable()
---------------------------------

Access:  protexted

Parameters:  None.

Returns:  A boolean of true if the ID chunks table was successfully created, false otherwise.

This internal function creates the ID chunks table.

IFDS::RemoveObjectIDInternal($obj)
----------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  Nothing.

This internal function removes an assigned object ID from an object and updates the cache accordingly.  Primarily used to clean up invalid IDs assigned to the object ID and free space tables.

IFDS::LoadObjectIDTableChunksMap($create)
-----------------------------------------

Access:  protected

Parameters:

* $create - A boolean indicating whether or not to create the ID chunks table if it doesn't exist.

Returns:  A boolean of true if the ID chunks table was successfully loaded, false otherwise.

This internal function loads the ID chunks table.  When $create is true, errors are ignored.

IFDS::CreateObjectIDTable($chunknum)
------------------------------------

Access:  protected

Parameters:

* $chunknum - An integer containing the entry number to create in the ID chunks table.

Returns:  A standard array of information.

This internal function creates an ID table entries entry in the ID chunks table.

IFDS::LoadObjectIDTableMap($chunknum)
-------------------------------------

Access:  protected

Parameters:

* $chunknum - An integer containing the entry number to create in the ID chunks table.

Returns:  A standard array of information.

This internal function loads an ID table entries entry object.

IFDS::Create($pfcfilename, $appmajorver, $appminorver, $appbuildnum, $magic = false, $ifdsfeatures = IFDS::FEATURES_OBJECT_ID_STRUCT_SIZE, $fmtfeatures = 0)
------------------------------------------------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache, a string containing a filename, or a boolean of false.
* $appmajorver - An integer from 0-65535 containing the app/custom format major version.
* $appminorver - An integer from 0-65535 containing the app/custom format minor version.
* $appbuildnum - An integer from 0-65535 containing the app/custom format patch/build number.
* $magic - A string containing the magic string (up to 123 bytes) to use or a boolean of false to use the magic string assigned in a previous SetMagic() call.
* $ifdsfeatures - An integer containing a bitfield of IFDS features (Default is IFDS::FEATURES_OBJECT_ID_STRUCT_SIZE).
* $fmtfeatures - An integer containing a bitfield of app/custom format features (Default is 0).

Returns:  A standard array of information.

This function creates a new IFDS file format file.  Optionally supports PagingFileCache for improved performance.  When $pfcfilename is false, internal buffering is used.

Available IFDS features:

* IFDS::FEATURES_NODE_IDS - Store a 4 byte object ID with each object.  Useful when generating streaming output.
* IFDS::FEATURES_OBJECT_ID_STRUCT_SIZE - Stores a 2 byte structure size in the object ID table entries structure.  Recommended for improved object loading performance and on by default.
* IFDS::FEATURES_OBJECT_ID_LAST_ACCESS - Stores a 2 byte last access/modification date.  Recommended for use in large files so they can be sorted during optimization.

IFDS::LoadFileHeader()
----------------------

Access:  protected

Parameters:  None.

Returns:  A standard array of information.

This internal function attempts to read and parse the header of a IFDS file.

IFDS::Open($filename, $magic = false)
-------------------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache or a string containing a filename.
* $magic - A string containing the magic string (up to 123 bytes) to use or a boolean of false to use the magic value assigned in a previous SetMagic() call.

Returns:  A standard array of information.

This function opens a IFDS file format file.  Optionally supports PagingFileCache for improved performance.

IFDS::InitStreamReader($magic = false)
--------------------------------------

Access:  public

Parameters:

* $magic - A string containing the magic string (up to 123 bytes) to use or a boolean of false to use the magic value assigned in a previous SetMagic() call.

Returns:  Nothing.

This function initializes the IFDS class for reading data as a stream.

IFDS::AppendStreamReader($data)
-------------------------------

Access:  public

Parameters:

* $data - A string containing the data to append.

Returns:  A standard array of information.

This function appends data to the stream reader.  If the file header has not been loaded/processed yet, this function will attempt to load and process the file header.

IFDS::GetStreamPos()
--------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the current stream position.

This function returns the current stream position.

IFDS::ReadNextFromStreamReader(&$data, $size, $raw = false)
-----------------------------------------------------------

Access:  public

Parameters:

* $data - A string containing buffered data.  The first call must be an empty string.  The function will load the buffer as needed.
* $size - An integer containing the predicted size to read.
* $raw - A boolean indicating whether or not to return the raw data read with the next structure (Default is false).

Returns:  A standard array of information.

This function attempts to read the next structure from the stream reader.  If there isn't enough data available, the error code is 'insufficient_data'.  This function also attempts to keep the internal buffer down to around 1MB.

IFDS::ResetInternal()
---------------------

Access:  protected

Parameters:  None.

Returns:  Nothing.

This internal function resets the IFDS class.

IFDS::Close()
-------------

Access:  public

Parameters:  None.

Returns:  Nothing.

This function closes a IFDS file and resets the class.

IFDS::OptimizeCopyObjectInternal($srcifds, $destifds, $obj)
-----------------------------------------------------------

Access:  public static

Parameters:

* $srcifds - An instance of IFDS for the source.
* $destifds - An instance of IFDS for the destination.
* $obj - An instance of a IFDS_RefCountObj object to copy from the source to the destination.

Returns:  A standard array of information.

This static function copies an object and all of its data from a source IFDS instance to a destination IFDS instance.  Used while optimizing a file.

IFDS::LinkedListOptimizeInternal($srcifds, $destifds, $obj)
-----------------------------------------------------------

Access:  _internal_ static

Parameters:

* $srcifds - An instance of IFDS for the source.
* $destifds - An instance of IFDS for the destination.
* $obj - An instance of a IFDS_RefCountObj object to copy from the source to the destination.

Returns:  A standard array of information.

This internal static function copies a linked list and all of its nodes while optimizing a file.

IFDS::Optimize($srcfile, $destfile, $magic = false, $typeoptimizecallbacks = array(IFDS::TYPE_LINKED_LIST => __CLASS__ . "::LinkedListOptimizeInternal"))
---------------------------------------------------------------------------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $srcfile - An IFDS instance, a PagingFileCache instance, or a string containing a filename to use for the source.
* $destfile - An IFDS instance, a PagingFileCache instance, or a string containing a filename to use for the destination.
* $magic - A string containing the magic string (up to 123 bytes) to use or a boolean of false to use the magic value assigned in a previous SetMagic() call.
* $typeoptimizecallbacks - An array containing a mapping of types to callbacks to use when optimizing those types (Default is to call LinkedListOptimizeInternal() for linked lists).

Returns:  A standard array of information.

This static function rebuilds and optimizes a source file.  Objects are sorted by most recently accessed/modified date and then by ID.  By default, linked lists are treated specially.

IFDS::GetHeader()
-----------------

Access:  public

Parameters:  None.

Returns:  An array containing the IFDS file header or a boolean of false if the header has not been loaded yet.

This function returns the current IFDS file header.

IFDS::SetAppFormatVersion($majorver, $minorver, $buildnum)
----------------------------------------------------------

Access:  public

Parameters:

* $majorver - An integer from 0-65535 containing the app/custom format major version.
* $minorver - An integer from 0-65535 containing the app/custom format minor version.
* $buildnum - An integer from 0-65535 containing the app/custom format patch/build number.

Returns:  Nothing.

This function sets the file header's app/custom format version information.  Nothing happens if the file header has not been loaded yet.

IFDS::GetAppFormatFeatures()
----------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the enabled app/custom format features.

This function returns an integer, usually a bitfield, containing app/custom format features from the file header.

IFDS::SetAppFormatFeatures($features)
-------------------------------------

Access:  public

Parameters:

* $features - A 32-bit integer containing the app/custom format features.

Returns:  Nothing.

This function sets the app/custom format format features integer/bitfield in the file header.

IFDS::WriteHeader()
-------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the file header was successfully written, false otherwise.

This function attempts to write the file header to the file.  This operation can fail when streaming file data.

IFDS::WriteNameMap()
--------------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function writes the name map to the file if it exists and has been modified.

IFDS::WriteIDMap()
------------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function writes the object ID tables to the file if they exist and have been modified.

IFDS::WriteFreeSpaceMap()
-------------------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function writes the free space tables to the file if they exist, have been loaded, and have been modified.

IFDS::ReadDataInternal($pos, $size)
-----------------------------------

Access:  public

Parameters:

* $pos - An integer containing the position to start reading at.
* $size - An integer containing the number of bytes to attempt to read.

Returns:  A string containing the data read or a boolean of false on EOF/error.

This function attempts to read and return up to $size bytes starting at $pos from the underlying storage handler.  This is not the same thing as reading data from an object via `ReadData()`.

IFDS::WriteDataInternal(&$data, $pos)
-------------------------------------

Access:  protected

Parameters:

* $data - A string containing the data to write.
* $pos - An integer containing the position to start writing at.

Returns:  A boolean of true if the write operation was successful, false otherwise.

This internal function writes data to the file.  This is not the same thing as writing data to an object via `WriteData()`.

IFDS::GetMaxPos()
-----------------

Access:  public

Parameters:  None.

Returns:  An integer containing the maximum position in the file.

This function returns the maximum position (i.e. the size) of the file in bytes.

IFDS::GetStreamData()
---------------------

Access:  public

Parameters:  None.

Returns:  A string containing the currently available stream data or a boolean of false on error.

This function returns stream data and adjusts internal pointers.

IFDS::FlushAll()
----------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function attempts to flush all unwritten data to the file.  Note that this function will finalize both any open streaming objects and the file header.

IFDS::GetNameMap()
------------------

Access:  public

Parameters:  None.

Returns:  An array containing the name map or a boolean of false if it hasn't been loaded.

This function returns a copy of the name map.

IFDS::GetNameMapID($name)
-------------------------

Access:  public

Parameters:

* $name - A string containing a name to lookup in the name map.

Returns:  An integer containing an object ID or a boolean of false on failure.

This function attempts to lookup a name in the name map and return an object ID.

IFDS::SetNameMapID($name, $id)
------------------------------

Access:  public

Parameters:

* $name - A string containing a name to assign in the name map.
* $id - An integer containing an object ID to assign.

Returns:  Nothing.

This function assigns a name to an object ID in the name map.

IFDS::UnsetNameMapID($name)
---------------------------

Access:  public

Parameters:

* $name - A string containing a name to remove from the name map.

Returns:  Nothing.

This function removes a name from the name map.

IFDS::FlushObjectDataChunks(&$data, $flushall = false)
------------------------------------------------------

Access:  protected

Parameters:

* $data - An array containing internal object data.
* $flushall - A boolean indicating whether or not to flush all object data chunks (Default is false).

Returns:  A standard array of information.

This internal function flushes object data chunks to the file.

IFDS::ProcessVarData()
----------------------

Access:  public

Parameters:  None.

Returns:  A standard array of information.

This function processes internal object data (vardata is the internal variable name) for an ongoing data chunks interleaved stream that is currently being written out for an object.

IFDS::ReduceObjectCache()
-------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the function was successful, false otherwise.

This function attempts to reduce the size of the internal object cache if it has grown too large.  Automatically called by various functions.

IFDS::FindNextAvailableID($id)
------------------------------

Access:  public

Parameters:

* $id - An integer containing the previous object ID.

Returns:  An integer containing the next available object ID.

This function finds the next available object ID after the specified object ID.

IFDS::CreateObject($type, $dataencodernum, $name, $typeinfo, $extra, $withid = true)
------------------------------------------------------------------------------------

Access:  public

Parameters:

* $type - An integer (1-3, 32-62) containing an IFDS object structure type + optional bitmask.
* $dataencodernum - An integer (0-3, 16-63) containing a IFDS data encoding.
* $name - A string containing a unique name to reserve or a boolean of false.  Only a handful of objects should have names.
* $typeinfo - An array containing data structure type information (structure-specific) or a boolean of false.
* $extra - An array containing extra options to add to the internal object data.
* $withid - A boolean indicating whether or not to assign an object ID in the object ID table (Default is true).  In general, objects should have IDs.

Returns:  A standard array of information.

This function creates an object.  For predefined object data structure types, calling the appropriate function to create such objects is preferred.

The following types are predefined:

* IFDS::TYPE_RAW_DATA (1) - Raw data.
* IFDS::TYPE_FIXED_ARRAY (2) - Fixed array.  `$typeinfo` must be `array("size" => $entrysize, "num" => 0)`.
* IFDS::TYPE_LINKED_LIST (3) - Linked list.  `$typeinfo` must be `array("num" => 0, "first" => 0, "last" => 0)`.
* IFDS::TYPE_LINKED_LIST (3) | IFDS::TYPE_LEAF (0x40) - Linked list node.  `$typeinfo` must be `array("prev" => 0, "next" => 0)`.

Structure types 4-31 are reserved for future use.

The following type bitmasks are available:

* IFDS::TYPE_LEAF (0x40) - Object is a leaf node in a data structure.
* IFDS::TYPE_STREAMED (0x80) - Object was generated as part of streaming output.  Pointers to other nodes may need to be updated before accessing the structure.

The following data encoders are predefined:

* IFDS::ENCODER_NONE (0) - NULL.
* IFDS::ENCODER_RAW (1) - Raw data.
* IFDS::ENCODER_KEY_ID_MAP (2) - Key-ID map.
* IFDS::ENCODER_KEY_VALUE_MAP (3) - Key-value map.

Data encoders 4-15 are reserved for future use.

IFDS::CreateRawData($dataencodernum, $name = false)
---------------------------------------------------

Access:  public

Parameters:

* $dataencodernum - An integer (0-3, 16-63) containing a IFDS data encoding.
* $name - A string containing a unique name to reserve or a boolean of false (Default is false).  Only a handful of objects should have names.

Returns:  A standard array of information.

This function creates a raw data object.

See `IFDS::CreateObject()` for the list of predefined and reserved data encodings.

IFDS::CreateKeyIDMap($name = false)
-----------------------------------

Access:  public

Parameters:

* $name - A string containing a unique name to reserve or a boolean of false (Default is false).  Only a handful of objects should have names.

Returns:  A standard array of information.

This function creates a key-ID map object.

See `IFDS::CreateObject()` for the list of predefined and reserved data encodings.

IFDS::CreateKeyValueMap($name = false, $withid = true)
------------------------------------------------------

Access:  public

Parameters:

* $name - A string containing a unique name to reserve or a boolean of false (Default is false).  Only a handful of objects should have names.
* $withid - A boolean indicating whether or not to assign an object ID in the object ID table (Default is true).  In general, objects should have IDs.

Returns:  A standard array of information.

This function creates a key-value map object.

See `IFDS::CreateObject()` for the list of predefined and reserved data encodings.

IFDS::CreateFixedArray($dataencodernum, $entrysize, $name = false, $withid = true)
----------------------------------------------------------------------------------

Access:  public

Parameters:

* $dataencodernum - An integer (0-3, 16-63) containing a IFDS data encoding.
* $entrysize - An integer containing the size of each entry in the fixed array.
* $name - A string containing a unique name to reserve or a boolean of false (Default is false).  Only a handful of objects should have names.
* $withid - A boolean indicating whether or not to assign an object ID in the object ID table (Default is true).  In general, objects should have IDs.

Returns:  A standard array of information.

This function creates a fixed array object.  Each entry in a fixed array must be the same number of bytes.

See `IFDS::CreateObject()` for the list of predefined and reserved data encodings.

IFDS::CreateLinkedList($name = false, $streaming = false, $dataencodernum = IFDS::ENCODER_NONE)
-----------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $name - A string containing a unique name to reserve or a boolean of false (Default is false).  Only a handful of objects should have names.
* $streaming - A boolean indicating whether or not this object will be streamed (Default is false);
* $dataencodernum - An integer (0-3, 16-63) containing a IFDS data encoding (Default is IFDS::ENCODER_NONE).

Returns:  A standard array of information.

This function creates a doubly-linked list object.  By default, a linked list object does not store any data.

See `IFDS::CreateObject()` for the list of predefined and reserved data encodings.

IFDS::CreateLinkedListNode($dataencodernum, $name = false)
----------------------------------------------------------

Access:  public

Parameters:

* $dataencodernum - An integer (0-3, 16-63) containing a IFDS data encoding.
* $name - A string containing a unique name to reserve or a boolean of false (Default is false).  Only a handful of objects should have names.

Returns:  A standard array of information.

This function creates a linked list node object.  To attach the node to a linked list, call `IFDS::AttachLinkedListNode()`.

See `IFDS::CreateObject()` for the list of predefined and reserved data encodings.

IFDS::GetObjectID($obj)
-----------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the object ID.

This function returns the object ID of an object.  If the ID is negative, then it is a position only object.

IFDS::GetObjectBaseType($obj)
-----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the base object data structure type.

This function returns the base object data structure type.  That is, the returned value does not include leaf node and streaming bits.

IFDS::GetObjectType($obj)
-------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the full object data structure type.

This function returns the full object data structure type.  That is, the returned value includes leaf node and streaming bits.

IFDS::GetObjectTypeStr($obj)
----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A string containing the object data structure type as a human readable string.

This function returns the object data structure type as a human readable string.  Only predefined values are supported.  All other values return an "unknown" string.

IFDS::GetObjectEncoder($obj)
----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the object's data encoding.

This function returns the object's data encoding.

IFDS::GetObjectDataMethod($obj)
-------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the data method bits.

This function returns one of the following values:

* IFDS::ENCODER_NO_DATA (0x00) - NULL.
* IFDS::ENCODER_INTERNAL_DATA (0x40) - Data is stored internally inside the object itself.
* IFDS::ENCODER_DATA_CHUNKS (0x80) - A DATA locations table follows the object in the file and is used to track up to 280TB of seekable information.
* IFDS::ENCODER_DATA_CHUNKS_STREAM (0xC0) - Interleaved, multi-channel streaming data follows the object.

IFDS::GetObjectDataPos($obj)
----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the current object data position.

This function returns the current data position inside the object.

IFDS::GetObjectDataSize($obj)
-----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the current object data size.

This function returns the current data size for the object.

IFDS::SetManualWriteObject($obj, $enable)
-----------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $enable - A boolean indicating whether or not to enable manual object writing.

Returns:  Nothing.

This function enables/disables manual object writing.  When enabled, the application is expected to call `WriteObject()`.

IFDS::IsObjectDataNull($obj)
----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if the data is NULL, false otherwise.

This function checks the object data to determine if it is NULL.

IFDS::IsObjectValid($obj)
-------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if the object is valid, false otherwise.

This function returns whether or not the loaded object is valid.  Objects are not valid if their CRC-32 does not match the data that was stored.

IFDS::IsObjectModified($obj)
----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if the object or its data has been modified, false otherwise.

This function returns whether or not the object or its data has been modified.

IFDS::IsInterleavedObject($obj)
-------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if the data for the object is interleaved, multi-channel data, false otherwise.

This function returns whether or not the data is interleaved, multi-channel data.

IFDS::IsManualWriteObject($obj)
-------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if manual object writing is enabled, false otherwise.

This function returns whether or not the object has manual object writing enabled.

IFDS::ClearLoadedObjectDataChunksInternal(&$data)
-------------------------------------------------

Access:  protected

Parameters:

* $data - An array containing internal object data.

Returns:  Nothing.

This internal function clears all chunks in the array and reduces RAM usage calculations.

IFDS::SetObjectEncoder($obj, $dataencodernum)
---------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $dataencodernum - An integer (0-3, 16-63) containing a IFDS data encoding.

Returns:  A standard array of information.

This function alters the IFDS data encoding for the object.

IFDS::ExtractNextStructure(&$data, &$pos, $size, $raw = false)
--------------------------------------------------------------

Access:  public

Parameters:

* $data - A string containing data to extract the next structure from.
* $pos - An integer containing the starting position in the `$data`.
* $size - An integer containing the length of `$data`.
* $raw - A boolean indicating whether or not to return the raw data for the structure (Default is false).

Returns:  A standard array of information.

This function attempts to extract the next data structure in the buffer.  If it is an object, it will also return an instance of IFDS_RefCountObj.  Calling `ReadNextStructure()` is preferred over calling this function.

IFDS::ExtractDataLocationsTable(&$table, &$data)
------------------------------------------------

Access:  public static

Parameters:

* $table - An array to load the DATA locations table into.
* $data - A string containing the data to extract.

Returns:  A boolean of true on success, false on failure.

This function extracts a DATA locations table into an array.  Used primarily by `LoadObjectDataTable()`.

IFDS::LoadObjectDataTable($obj, &$data, $size)
----------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $data - A string containing data to read/append to.
* $size - An integer containing the size of the data.

Returns:  A standard array of information.

This function loads an object's "data_tab" with appropriate information.  Used primarily by `ReadNextStructure()`.

IFDS::ReadNextStructure(&$filepos, &$data, $size, $raw = false)
---------------------------------------------------------------

Access:  public

Parameters:

* $filepos - An integer containing the file position of the start of the data.
* $data - A string containing a data buffer as previously read by this function.
* $size - An integer specifying the size of the next structure.
* $raw - A boolean indicating whether or not to return the raw data for the structure (Default is false).

Returns:  A standard array of information.

This function reads the next structure from the file starting at the specified file position.

IFDS::GetObjectByPosition($filepos, $size = 4092)
-------------------------------------------------

Access:  public

Parameters:

* $filepos - An integer containing the starting position of the object.
* $size - An integer containing the number of bytes to read (Default is 4092).

Returns:  A standard array of information.

This function loads an object located at the specified position in the file.  The size of the object may or may not be known.

IFDS::GetObjectByID($id, $updatelastaccess = true)
--------------------------------------------------

Access:  public

Parameters:

* $id - A positive integer containing an object ID.
* $updatelastaccess - A boolean indicating whether or not to update the object's last access time.

Returns:  A standard array of information.

This function loads an object by looking up the ID in the object ID tables and reading the object at the specified position.  If the object is already in the object cache, the function simply returns a new instance of `IFDS_RefCountObj`.

The `$updatelastaccess` option is only used if the object has not been loaded, the relevant IFDS feature is enabled, and the date difference is greater than the stored value.

IFDS::GetObjectByName($name)
----------------------------

Access:  public

Parameters:

* $name - A string containing a name in the name map.

Returns:  A standard array of information.

This function is a convenience wrapper function around `GetNameMapID()` and `GetObjectByID()` to retrieve an object by a name.  In general, only a handful of objects should have names.

IFDS::MoveDataChunksInternal($obj, $srcpos, $srcsize, $destpos)
---------------------------------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $srcpos - An integer containing the starting position in the file for the source.
* $srcsize - An integer containing the number of bytes to move.
* $destpos - An integer containing the starting position in the file for the destination.

Returns:  A standard array of information.

This internal function moves large quantities of data around from one location in a file to another and zeroes out the original source data.  The source and destination regions may not overlap.

IFDS::MergeDownObjectDataChunks($obj, $newtableentries)
-------------------------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $newtableentries - An integer containing the number of new table entries that will be needed.

Returns:  A standard array of information.

This internal function merges down DATA chunks in DATA locations tables so that they are more contiguous.  A merge down minimally occurs around every 4.2GB.  Realistically though, only objects vastly exceeding 4.2GB will experience merge down operations and will probably only happen very rarely.  In short, only exceptionally large or heavily fragmented files will ever see this function execute any of its code.

IFDS::WriteObjectDataLocationsTable($obj)
-----------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A standard array of information.

This internal function writes out the DATA locations table for an object.  The function assumes that sufficient space has been reserved for the table.  Used primarily by `WriteObject()`.

IFDS::WriteObject($obj)
-----------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A standard array of information.

This function writes an object, a DATA locations table when using seekable DATA CHUNKS, and as much data as possible to the file.  This function attempts to place the object ahead of its data so that reads are sequential but may not be possible with large data blobs.

IFDS::Seek($obj, $pos)
----------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $pos - An integer containing the position in the object's data to move the internal pointer to.

Returns:  A standard array of information.

This function moves the internal object data pointer to the specified position in the data but not beyond the data size.  Note that while this function supports "seeking" inside interleaved, multi-channel data, it doesn't make much sense to do so.  Primarily intended for use with seekable DATA CHUNKS and INTERNAL DATA.

The IFDS class will only ever load one instance of an object into the object cache and there is only one internal data pointer per object.  This means that two instances of IFDS_RefCountObj pointing at the same object will affect each other when calling this function.

IFDS::ExtractDataChunk($obj, $chunknum, $filepos, $filesize, $datapos, &$data)
------------------------------------------------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $chunknum - An integer containing the chunk number.
* $filepos - An integer containing the base file position for the DATA chunk.
* $filesize - An integer containing the expected size of the data.
* $datapos - An integer containing the base data position for the DATA chunk.
* $data - A string containing the DATA chunk to extract.

Returns:  A boolean of true if the DATA chunk was successfully extracted, false otherwise.

This internal function extracts a DATA chunk and stores it in the object's 'chunks' array at the specified chunk number.  Performs some rudimentary checking to make sure the data appears to be valid.

IFDS::LoadCurrLocationTableChunk($obj)
--------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if the current DATA chunk was successfully loaded, false otherwise.

This internal function uses the DATA locations table to calculate the starting location in the file and the chunk size to read and extract a DATA chunk.

IFDS::ReadData($obj, $size = -1, $channel = false)
--------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $size - An integer containing the maximum number of bytes to read (Default is -1).
* $channel - An integer containing a channel number (0-65535) or a boolean of false (Default is false).

Returns:  A standard array of information.

This function reads up to the specified amount of data from an object.  When reading interleaved, multi-channel data and specifying a channel, this function restricts itself to reading only that channel's data and will skip over other channels.

IFDS::CanWriteDataInternal(&$data)
----------------------------------

Access:  protected

Parameters:

* $data - An array containing internal object data.

Returns:  A boolean of true if `WriteData()` for the object can be called, false otherwise.

This internal function returns whether or not the object is in a state that allows `WriteData()` to be called.

IFDS::CanWriteData($obj)
------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if `WriteData()` for the object can be called, false otherwise.

This function returns whether or not the object is in a state that allows `WriteData()` to be called.  Internally calls `IFDS::CanWriteDataInternal()`.

IFDS::WriteData($obj, $data, $channel = false, $final = false)
--------------------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $data - A string containing the data to write to the object or NULL.
* $channel - An integer containing a channel number (0-65535) or a boolean of false (Default is false).
* $final - A boolean indicating whether or not this is intended to be the final call to this function (Default is false).  Not used for seekable DATA chunks and INTERNAL DATA.

Returns:  A standard array of information.

This function writes data to the object starting at the internal data position.  Data is split up based on the data storage mode.  This function will automatically change an object's data storage mode from INTERNAL DATA to DATA CHUNKS when 3,072 bytes or more is stored in an object.

Writing NULL with `$final` set to true will truncate the data and change the data encoding to `IFDS::ENCODER_NONE | IFDS::ENCODER_NO_DATA` (i.e. NULL).

IFDS::Truncate($obj, $newsize = 0)
----------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $newsize - An integer containing the new size to truncate to (Default is 0).

Returns:  A standard array of information.

This function truncates stored data to the specified size.  Interleaved, multi-channel data only supports a value of 0 for `$newsize`.

IFDS::ClearObject($obj)
-----------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A standard array of information.

This function clears an object and any DATA locations table from the file.  Does not `Truncate()` data.  Primarily used to move an object when its size notably changes.

IFDS::DeleteObject($obj)
------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A standard array of information.

This function deletes an object and all of its data from the file.  The function will only delete objects that have been fully detached (e.g. linked list nodes detached from a linked list).

IFDS::GetNextKeyValueMapEntry($obj, $maxvalsize = 10485760, $channel = false)
-----------------------------------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $maxvalsize - An integer containing the maximum size allowed for a value (Default is 10485760).
* $channel - An integer containing a channel number (0-65535) or a boolean of false (Default is false).

Returns:  A standard array of information.

This function gets the next key and value in a key-ID or key-value map.  Skips over values that are too large.

IFDS::GetKeyValueMap($obj, $maxvalsize = 10485760, $channel = false)
--------------------------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $maxvalsize - An integer containing the maximum size allowed for a value (Default is 10485760).
* $channel - An integer containing a channel number (0-65535) or a boolean of false (Default is false).

Returns:  A standard array of information.

This function loads an entire key-ID or key-value map into a PHP array.  Skips over values that are too large.

IFDS::EncodeKeyValueMapEntry(&$data, &$key, &$value, $usevals, $maxvalsize = 10485760)
--------------------------------------------------------------------------------------

Access:  public static

Parameters:

* $data - A string to append the new key and ID/value to.
* $key - A string or integer key.
* $value - A string or integer value.
* $usevals - A boolean indicating whether or not to use value encoding (true) or ID encoding (false).
* $maxvalsize - An integer containing the maximum size allowed for a value (Default is 10485760).

Returns:  A boolean of true if successfully encoded, false otherwise.

This function encodes a key-ID or key-value and appends the result to the data.

IFDS::SetKeyValueMap($obj, $map, $maxvalsize = 10485760)
--------------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $map - An array containing key-ID or key-value pairs.
* $maxvalsize - An integer containing the maximum size allowed for a value (Default is 10485760).

Returns:  A standard array of information.

This function encodes a PHP array into a key-ID or key-value map and overwrites the existing data in the object.

IFDS::GetNumFixedArrayEntries($obj)
-----------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the number of entries in the fixed array.

This function returns the self-described number of entries in a fixed array.  Note that the returned value may not be accurate/correct and should only be used for informational purposes.

IFDS::GetFixedArrayEntrySize($obj)
----------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the per-entry size (in bytes) in the fixed array.

This function returns the per-entry size of the fixed array.  Applications should validate that the reported size is correct before reading any data.

IFDS::GetNextFixedArrayEntry($obj, $channel = false)
----------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $channel - An integer containing a channel number (0-65535) or a boolean of false (Default is false).

Returns:  A standard array of information.

This function reads and returns the next entry in a fixed array.

IFDS::GetFixedArrayEntry($obj, $num, $channel = false)
------------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $num - An integer containing the specific entry to read.
* $channel - An integer containing a channel number (0-65535) or a boolean of false (Default is false).

Returns:  A standard array of information.

This function seeks to the start of the specified entry and reads and returns it.

IFDS::SetFixedArrayEntry($obj, $num, &$data)
--------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $num - An integer containing the specific entry to read.
* $data - A string containing the data to write.  Must be the exact number of bytes to fill an entry.

Returns:  A standard array of information.

This function sets a specific entry's data in the fixed array.

IFDS::AppendFixedArrayEntry($obj, $data, $channel = false)
----------------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $data - A string containing the data to write.  Must be the exact number of bytes to fill an entry.
* $channel - An integer containing a channel number (0-65535) or a boolean of false (Default is false).

Returns:  A standard array of information.

This function appends a new entry to the fixed array.

IFDS::NormalizeLinkedList($obj)
-------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A standard array of information.

This function normalizes a linked list that was streamed by iterating through all nodes and correcting the "next" pointers.

IFDS::GetNumLinkedListNodes($obj)
---------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  An integer containing the number of entries in the linked list.

This function returns the self-described number of nodes in a linked list.  Note that the returned value may not be accurate/correct and should only be used for informational purposes.

IFDS::CreateLinkedListIterator($obj)
------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A standard array of information.

This function creates an iterator that can be used to iterate over the nodes in the linked list with `GetNextLinkedListNode()` and `GetPrevLinkedListNode()`.

The iterator is a class instance of `stdClass` containing:

* listobj - The linked list object.
* nodeobj - The current linked list node object or a boolean of false.
* result - A standard array of information.

All three items are public and intended to be accessed by the application.

IFDS::GetNextLinkedListNode($iter)
----------------------------------

Access:  public

Parameters:

* $iter - An object containing a linked list iterator.

Returns:  A boolean of true if the next node was reached, false otherwise.

This function loads the next node object in the linked list.

IFDS::GetPrevLinkedListNode($iter)
----------------------------------

Access:  public

Parameters:

* $iter - An object containing a linked list iterator.

Returns:  A boolean if the previous node was reached, false otherwise.

This function loads the previous node object in the linked list.

IFDS::AttachLinkedListNode($headobj, $nodeobj, $after = true)
-------------------------------------------------------------

Access:  public

Parameters:

* $headobj - An instance of a IFDS_RefCountObj object.  Must be a linked list.
* $nodeobj - An instance of a IFDS_RefCountObj object.  Must be a linked list node.
* $after - An integer containing the node ID to insert the new node after, a boolean of false to attach to the beginning, or a boolean of true to attach to the end (Default is true).

Returns:  A standard array of information.

This function attaches a node object to a linked list.

IFDS::DetachLinkedListNode($headobj, $nodeobj)
----------------------------------------------

Access:  public

Parameters:

* $headobj - An instance of a IFDS_RefCountObj object.  Must be a linked list.
* $nodeobj - An instance of a IFDS_RefCountObj object.  Must be a linked list node.

Returns:  A standard array of information.

This function detaches a node object from a linked list.

IFDS::DeleteLinkedListNode($headobj, $nodeobj)
----------------------------------------------

Access:  public

Parameters:

* $headobj - An instance of a IFDS_RefCountObj object.  Must be a linked list.
* $nodeobj - An instance of a IFDS_RefCountObj object.  Must be a linked list node.

Returns:  A standard array of information.

This function detaches and deletes a node object from a linked list.

IFDS::DeleteLinkedList($obj)
----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.  Must be a linked list.

Returns:  A standard array of information.

This function detaches all node objects in the linked list and then deletes the linked list object.

IFDS::GetEstimatedFreeSpace()
-----------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the estimated amount of free space in the file.

This function calculates the estimated free space in the file using the free space map.  Note that the returned value may not be accurate/correct and should only be used for informational purposes.

IFDS::LoadFreeSpaceBlock($chunkobj, &$entry, $blocknum)
-------------------------------------------------------

Access:  protected

Parameters:

* $chunkobj - An instance of a IFDS_RefCountObj object.
* $entry - An array containing a free space entry to load.
* $blocknum - An integer containing the block number to load from the file.

Returns:  A boolean of true if the free space entry was successfully loaded, false otherwise.

This internal function loads and validates and processes up to a 65,356 byte chunk of data for free space.

IFDS::CreateFreeSpaceChunksTable()
----------------------------------

Access:  protected

Parameters:  None.

Returns:  A boolean of true if the free space chunks table was created, false otherwise.

This internal function creates the free space chunks table.

IFDS::LoadFreeSpaceTableChunksMap($create)
------------------------------------------

Access:  protected

Parameters:

* $create - A boolean indicating whether or not to create the free space chunks table if it doesn't exist.

Returns:  A boolean of true if the free space chunks table was successfully loaded, false otherwise.

This internal function loads the free space chunks table and optionally creates it if it doesn't exist or is corrupted.

IFDS::CreateFreeSpaceTable($chunknum)
-------------------------------------

Access:  protected

Parameters:

* $chunknum - An integer containing the chunk number.

Returns:  A standard array of information.

This internal function creates a free space table entry in the free space chunks table.

IFDS::LoadFreeSpaceTableMap($chunknum)
--------------------------------------

Access:  protected

Parameters:

* $chunknum - An integer containing the chunk number.

Returns:  A standard array of information.

This internal function loads a free space table object.

IFDS::ClearFreeSpaceEntryTracker($obj)
--------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  Nothing.

This internal function clears entries in a free space table object of loaded free space block information.  Used primarily to keep RAM usage down.

IFDS::ReserveBytesInternal_AttemptReservation($pos, $numbytes)
--------------------------------------------------------------

Access:  protected

Parameters:

* $pos - An integer containing a file position.
* $numbytes - An integer containing the number of bytes to reserve.

Returns:  A boolean of true if the space at the position was successfully reserved, false otherwise.

This internal function attempts to reserve the requested space at the specified position using the free space map.  If the free space map contains invalid/inaccurate information, the function will fail to reserve the space.

IFDS::ReserveBytesInternal_FindNext($pos, $numbytes)
----------------------------------------------------

Access:  protected

Parameters:

* $pos - An integer containing a file position to start at.
* $numbytes - An integer containing the number of bytes to reserve.

Returns:  An integer containing the next available position in the free space map.

This internal function attempts to find the next available position that will potentially fit the data.

IFDS::ReserveBytesInternal($numbytes, $prefpos = false)
-------------------------------------------------------

Access:  protected

Parameters:

* $numbytes - An integer containing the number of bytes to reserve.
* $prefpos - An integer containing the preferred file position to use for the reservation.

Returns:  An integer containing the new reserved position.

This internal function reserves bytes within the file for storing structures and data, using the free space table map as needed.

IFDS::FreeBytesInternal($pos, $numbytes)
----------------------------------------

Access:  protected

Parameters:

* $pos - An integer containing a file position to start at.
* $numbytes - An integer containing the number of bytes to free.

Returns:  A standard array of information.

This internal function frees the specified bytes of data and adds updates the free space map.

IFDS::FixedArrayTypeEncoder($obj)
---------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A string containing the encoded structure data for a fixed array.

This internal function encodes fixed array information into a string and returns it.

IFDS::FixedArrayTypeDecoder($obj, &$data, $pos, &$size)
-------------------------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $data - A string containing the object being decoded.
* $pos - An integer containing starting position of the data structure info.
* $size - An integer containing how many bytes are left in the data.

Returns:  A boolean of true if there is sufficient data for a fixed array object, false otherwise.

This internal function decodes fixed array information into an array within the object.

IFDS::LinkedListTypeEncoder($obj)
---------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A string containing the encoded structure data for a linked list or a linked list node.

This internal function encodes linked list or linked list node information into a string and returns it.

IFDS::LinkedListTypeDecoder($obj, &$data, $pos, &$size)
-------------------------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $data - A string containing the object being decoded.
* $pos - An integer containing starting position of the data structure info.
* $size - An integer containing how many bytes are left in the data.

Returns:  A boolean of true if there is sufficient data for a linked list or linked list node object, false otherwise.

This internal function decodes linked list or linked list node information into an array within the object.

IFDS::LinkedListTypeCanDelete($obj)
-----------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A boolean of true if the linked list or linked list node can be deleted, false otherwise.

This function prevents deleting linked list and linked list node objects that are still attached to another object.

IFDS::UnknownTypeEncoder($obj)
------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.

Returns:  A string containing the unknown object information.

This internal function returns the unknown object information as-is.

IFDS::UnknownTypeDecoder($obj, &$data, $pos, &$size)
----------------------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object.
* $data - A string containing the object being decoded.
* $pos - An integer containing starting position of the data structure info.
* $size - An integer containing how many bytes are left in the data.

Returns:  A boolean of true.

This internal function extracts information into a string within the object.  Called when the object data structure type has an unknown encoding.

IFDS::IFDSTranslate($format, ...)
---------------------------------

Access:  _internal_ static

Parameters:

* $format - A string containing valid sprintf() format specifiers.

Returns:  A string containing a translation.

This internal static function takes input strings and translates them from English to some other language if CS_TRANSLATE_FUNC is defined to be a valid PHP function name.

IFDS_RefCountObj::__construct(&$data)
-------------------------------------

Access:  public

Parameters:

* $data - An array containing IFDS-compatible data.

Returns:  Nothing.

This function initializes a new reference-counted object that points to the underlying data.  Used by the IFDS class to initialize an object.  Note that while the data is declared public in this class, it is not intended to be used outside of the IFDS class.

IFDS_RefCountObj::GetID()
-------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the object ID.

This function returns the object ID of an object.  If the ID is negative, then it is a position only object.

IFDS_RefCountObj::GetBaseType()
-------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the base object data structure type.

This function returns the base object data structure type.  That is, the returned value does not include leaf node and streaming bits.

IFDS_RefCountObj::GetType()
---------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the full object data structure type.

This function returns the full object data structure type.  That is, the returned value includes leaf node and streaming bits.

IFDS_RefCountObj::GetTypeStr()
------------------------------

Access:  public

Parameters:  None.

Returns:  A string containing the object data structure type as a human readable string.

This function returns the object data structure type as a human readable string.  Only predefined values are supported.  All other values return an "unknown" string.

IFDS_RefCountObj::GetEncoder()
------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the object's data encoding.

This function returns the object's data encoding.

IFDS_RefCountObj::GetDataMethod()
---------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the data method bits.

This function returns one of the following values:

* IFDS::ENCODER_NO_DATA (0x00) - NULL.
* IFDS::ENCODER_INTERNAL_DATA (0x40) - Data is stored internally inside the object itself.
* IFDS::ENCODER_DATA_CHUNKS (0x80) - A DATA locations table follows the object in the file and is used to track up to 280TB of seekable information.
* IFDS::ENCODER_DATA_CHUNKS_STREAM (0xC0) - Interleaved, multi-channel streaming data follows the object.

IFDS_RefCountObj::GetDataPos()
------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the current object data position.

This function returns the current data position inside the object.

IFDS_RefCountObj::GetDataSize()
-------------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the current object data size.

This function returns the current data size for the object.

IFDS_RefCountObj::SetManualWrite($enable)
-----------------------------------------

Access:  public

Parameters:

* $enable - A boolean indicating whether or not to enable manual object writing.

Returns:  Nothing.

This function enables/disables manual object writing.  When enabled, the application is expected to call `WriteObject()`.

IFDS_RefCountObj::IsDataNull()
------------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the data is NULL, false otherwise.

This function checks the object data to determine if it is NULL.

IFDS_RefCountObj::IsValid()
---------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the object is valid, false otherwise.

This function returns whether or not the loaded object is valid.  Objects are not valid if their CRC-32 does not match the data that was stored.

IFDS_RefCountObj::IsModified()
------------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the object or its data has been modified, false otherwise.

This function returns whether or not the object or its data has been modified.

IFDS_RefCountObj::IsInterleavedObject()
---------------------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if the data for the object is interleaved, multi-channel data, false otherwise.

This function returns whether or not the data is interleaved, multi-channel data.

IFDS_RefCountObj::IsManualWrite()
---------------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if manual object writing is enabled, false otherwise.

This function returns whether or not the object has manual object writing enabled.
