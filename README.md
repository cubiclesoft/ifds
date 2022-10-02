Incredibly Flexible Data Storage (IFDS) File Format
===================================================

The Incredibly Flexible Data Storage (IFDS) file format enables the rapid creation of highly scalable and flexible custom file formats.  Create your own customized binary file format with ease with IFDS.

Implementations of the [IFDS file format specification](docs/ifds_specification.md) internally handle all of the difficult bookkeeping bits that occur when inventing a brand new file format, which lets software developers focus on more important things like high level design and application development.  See the use-cases and examples below that use the PHP reference implementation (MIT or LGPL, your choice) of the specification to understand what is possible with IFDS!

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/) [![Discord](https://img.shields.io/discord/777282089980526602?label=chat&logo=discord)](https://cubiclesoft.com/product-support/github/)

Features
--------

* Custom magic strings (up to 123 bytes) and semantic file versioning.
* Included default data structures:  Raw storage, binary key-value/key-ID map, fixed array, linked list.
* Extensible.  Add your own low level data structures (supports up to 31 custom structures such as trees) and format encoders/decoders (supports up to 48).
* Name to object ID map.  Create "well-known" strings for your custom format to quickly retrieve root objects.
* Supports up to 4.2 billion objects.  Dynamically create, modify, and delete objects.
* Supports four types of data storage per object:  NULL, dynamic internal (up to 31KB; 2^15), dynamic seekable (up to 280TB; 2^48), and static interleaved multi-channel (up to 65,536 channels and up to 17EB; a little less than 2^64).
* Compact object storage with minimal overhead.
* Memory efficient.  Most baseline read/write operations are carefully designed to fit into 65,536 byte chunks.  Compatible with Paging File Cache (PFC).  Exception:  An object's DATA locations table can be as large as 655,378 bytes.
* Verifiable.  Every structure and data chunk uses a standard CRC-32 to readily detect partial data corruption within the file.
* Streaming capable.  Generate, read, and verify file data on the fly using minimal system resources.
* Optimized.  Free space is efficiently managed to aid in minimizing file fragmentation.  Whole file content can be compacted and sorted by most recently accessed objects to allow for better sequential file access.
* And more.

Performance
-----------

On an Intel Core i7 6th generation CPU, the following measurements were taken using the IFDS PHP reference implementation with the default PFC in-memory layer (can be replicated via the test suite's `-perftests` option):

* Key-value objects created/encoded:  85,427/sec
* Key-value objects read/decoded (sequential):  64,346/sec
* Key-value objects read/decoded (random):  54,986/sec
* DATA chunks sequential write rate:  543 MB/sec
* DATA chunks sequential read rate:  241 MB/sec
* DATA chunks random read rate:  302 MB/sec
* DATA chunks random write rate:  37.0 MB/sec

The IFDS PHP reference implementation is actually fairly inefficient due to both being implemented in PHP userland and a number of operations are intentionally not fully optimized so that the code is easier to read and understand.  PHP itself doesn't perform all that well when modifying large binary data blobs as it is missing multiple inline string modifier functions.

For an apples to oranges comparison, SQLite via PHP PDO on the same hardware can insert approximately 138,000 rows/sec (using transactions and commits) containing the same data into an in-memory SQLite database.  SQLite is approximately 2 times faster than the PHP IFDS reference implementation for bulk insertions.  However, if you need a database, then you should probably use an existing database.

Use Cases
---------

Here is a short list of ideas for using IFDS:

* Invent a new, multi-layered image file format.  See "JPEG-PNG-SVG" below.
* Redesign text files and text editors to handle multi-TB file sizes.  See "Redesign Text Files" below.
* Replace configuration files with a universally compatible configuration format.  See "Replace Configuration Files" below.
* Streaming video container for holding multiple interleaved video and audio streams.
* Executable files that can be easily modified.
* Custom database-like indexes for large, external files like CSV and JSON-lines.
* File compression container to replace ZIP archives.
* A scalable virtual filesystem.
* Spreadsheet storage for handling unlimited cells.
* Custom databases.
* Layer and access IFDS files inside IFDS files inside IFDS files.  IFDS Inception!
* Bundle documentation in the same file as source code but not inlined inside the source code itself.
* Store and retrieve tiled images in a format similar to Google Maps for efficient memory usage when displaying massive image files.

The possibilities are endless.

Limitations
-----------

* The underlying file system and/or the Operating System may limit the maximum size of a single file to something much less than 2^64.  For example, NTFS limits individual files to 256TB, ext4's limit is 16TB, and XFS' limit is 8EB.  The only known file system to date that supports files up to 2^64 in size is ZFS, but ZFS requires considerably more system resources to run well.
* The 4.2 billion object limit is due to using 4 byte object IDs.  The limitation is somewhat moot.  Just storing 4.2 billion empty/NULL objects would require about 34.3GB of storage.
* Page level disk alignment is not currently feasible due to Paging File Cache (PFC) compatibility.  Using a PFC layer is recommended for performance.  PFC is useful for applying transparent disk page encryption/decryption, Hamming codes for automatic error correction, and more.
* By default, the PHP reference implementation of IFDS attempts to limit estimated RAM usage of the IFDS object cache to around 10MB (configurable).  For files under 10MB, the entire file can be cached in RAM and a structured order can be maintained.  For larger files, data and structures may be stored out of order, which can impact reading I/O performance later.  A PFC layer can help alleviate performance related problems when processing very large files.
* Interleaved multi-channel data storage can only be written one time, is mostly only written to the end of a file since the size is usually unknown, and can only be read sequentially.  It is ideal for interleaved, multi-channel data that is written exactly one time and read many times (e.g. interleaved audio and video data for a streaming video file format).

Use Case:  JPEG-PNG-SVG
-----------------------

This use case is a bit contrived but demonstrates combining three different existing file formats into a single, unified file without having to make major changes to an existing file format.

The JPEG file format:

* Is lossy.  Multiple saves of the image loses more and more information.
* Consistently produces the same file size for images of the same width, height, and JPEG quality setting.
* Great at storing photos.  Okay at storing gradients.
* Terrible at storing line art and text.  Lots of obvious artifacting appears around the lines.  Especially noticeable on solid color backgrounds.
* Supports millions of colors.
* No transparency support.
* Doesn't resize well to larger image sizes.

The PNG file format:

* Is lossless.  Saving the image over and over will not lose information.
* Produces very small files when storing line art and text.  Excellent for fixed size icons where pixel perfect results matter.
* Produces extremely large files when storing photos and nearly all gradients.
* No artifacting around lines and text at 100% size.
* Supports millions of colors.
* 256 levels of transparency.
* Doesn't resize well to larger image sizes.

The SVG file format:

* Is lossless.  Saving the image over and over will not lose information.
* Stores vector graphics - line art and text.  Generally produces very small files and supports common, simple gradients.
* Does not support storing photos or more complex gradients.
* No artifacting around lines and text except at very small sizes (e.g. icons).
* Supports millions of colors.
* Theoretically unlimited levels of transparency.
* Resizes to most sizes reasonably well.  However, it does not resize down to small icon sizes very well (e.g. 16x16) when rendering complex, multicolor icons where pixel perfect results matter.

There is no single image file format that works for all images.  This is especially true for images that combine photos/gradients and line art.

So let's make a combined image file format using IFDS and just four objects to store up to three images:

* 'jpeg' object - Stores a JPEG image.
* 'png' object - Stores a PNG image.
* 'svg' object - Stores a SVG image.
* 'metadata' object - Stores metadata.

Using the PHP IFDS reference implementation:

```php
<?php
	require_once "support/paging_file_cache.php";
	require_once "support/ifds.php";
	require_once "test_suite/cli.php";

	// Delete any previous file.
	@unlink("a.jps");

	// Create the file.
	$pfc = new PagingFileCache();
	$pfc->Open("a.jps");

	$ifds = new IFDS();
	$result = $ifds->Create($pfc, 1, 0, 0, "JPEG-PNG-SVG");
	if (!$result["success"])  CLI::DisplayError($result);

	// Create 'jpg' object.
	$result = $ifds->CreateRawData("jpg");
	if (!$result["success"])  CLI::DisplayError($result);

	$jpgobj = $result["obj"];

	$data = file_get_contents("a.jpg");

	$result = $ifds->WriteData($jpgobj, $data);
	if (!$result["success"])  CLI::DisplayError($result);

	// Create 'png' object.
	$result = $ifds->CreateRawData("png");
	if (!$result["success"])  CLI::DisplayError($result);

	$pngobj = $result["obj"];

	$data = file_get_contents("a.png");

	$result = $ifds->WriteData($pngobj, $data);
	if (!$result["success"])  CLI::DisplayError($result);

	// Create 'svg' object.
	$result = $ifds->CreateRawData("svg");
	if (!$result["success"])  CLI::DisplayError($result);

	$svgobj = $result["obj"];

	$data = file_get_contents("a.svg");

	$result = $ifds->WriteData($svgobj, $data);
	if (!$result["success"])  CLI::DisplayError($result);

	// Create 'metadata' object.
	$result = $ifds->CreateKeyValueMap("metadata");
	if (!$result["success"])  CLI::DisplayError($result);

	$metadataobj = $result["obj"];

	$metadata = array(
		"width" => "500",
		"height" => "250",
		"desc" => "An amazing, multi-layered image with crisp text and line art!"
	);

	$result = $ifds->SetKeyValueMap($metadataobj, $metadata);
	if (!$result["success"])  CLI::DisplayError($result);

	// Generally a good idea to explicitly call Close() so that all objects get flushed to disk.
	$ifds->Close();
?>
```

The idea here is that the JPEG image stores photo portion while the PNG image stores the line art and the SVG stores a vector-scalable version of the line art.  To display the image, the JPEG would be read and the PNG or SVG is then layered on top.  The overall file size is only slightly larger than the combined size of the JPEG + PNG + SVG and the result would be a cleaner, crisper image when displayed on high density devices.

Unfortunately, no existing software out there will currently read this brand new file format and display the contents (e.g. your favorite web browser or image editor won't read this file).  It could be argued that the PNG format could handle this internally OR a simpler, combined format could be created.  However, this is only meant as a very rudimentary example that barely scratches the surface of what can be accomplished with IFDS.

Use Case:  Redesign Text Files
------------------------------

Today's text files and text editors are stuck in the 1970's where storage, RAM, and CPU cycles were extremely limited and every single bit and byte actually mattered.  Those extreme limitations generally no longer apply.  However, "modern" text editors still act like they do.  Editing files that are just a few MB in size dramatically slows down most text editors to a noticeable degree and gets exponentially worse with files in the 50MB+ range.  What if someone needs to edit a multi-TB text file...over a network?

There are several major problems with current text files and text editors:

* Line endings.  Text files use specific byte(s) to indicate the end of the line.  This is metadata to the file but every text editor, compiler, interpreter, operating system, and software application has to figure it out over and over again for every single file.  Line endings also rely on an extremely outdated and very specific portion of the ASCII character set with no flexibility for alternate byte sequences.
* Counting the number of lines is difficult.  Nearly all text editors load the full file data into RAM to determine...how many lines are in that file.  Syntax highlighting is an inconsequential side effect.  Reading in the whole file just to determine the number of lines in that file is crazy.
* Line lengths.  Lines are of arbitrary length, which makes it impossible to jump to a specific line in a large file without reading the entire file sequentially from the very beginning to the point of interest.
* Character sets.  Text files store data for multiple character sets and it is up to the text editor to determine which character set is in use and display the file with that character set.  While most documents these days are stored as UTF-8, that isn't a guarantee.  The character set that a document uses is metadata.  Even HTML has a special tag called "meta" for faking metadata within the document to declare the character set.
* No dedicated metadata section.  There are so many "hacky workarounds" that have been applied over the years when it comes to metadata storage in text files.  From simple, mostly benign things such as a full page of a commented out copyright statement (seriously, this doesn't belong at the top of your code) to storing text-editor specific formatting instructions in a comment to compiler/preprocessor file parsing instructions!  Metadata does not belong anywhere in the main content of a text file.
* Not suitable for log files.  Text files are not a great storage medium for storing logs.  The current solution for logs stored in text files is to rename and compress the files, which causes all kinds of issues.  Log files should simply rotate within the file itself.
* Not suitable for configuration files.  Text files are a terrible storage medium for configuration data.  See the next Use Case for a proper replacement for configuration files using IFDS.
* Not suitable for anything but very small files.  Text files with lines that exceed 1MB in length are severely problematic in most text editors.  Text files 10MB and over take notably longer to load and save and start having serious problems with files 50MB and larger.  Text files exceeding 1GB in total size tax most text editors and some even crash making the attempt.  Forget opening a 1TB text file in 99% of all text editors out there let alone loading, editing, and saving the file in a reasonable amount of time over a network.  Opening a file should be instantaneous, editing any portion of the file should be instantaneous, and saving should be - you guessed it - instantaneous.  It also shouldn't matter what text editor is used.
* Text files do not have compression support.  Therefore, really large text files are just really large files occupying unnecessary space on disk.
* Text files can't be internally digitally signed in a standard way.  There's no option to have a dedicated signature section within the file.  Even if there was, it wouldn't be able to be implemented in a universally consistent, standard way.

IFDS could be used to solve all of those problems.

```
IFDS TEXT file format

Root text object (Fixed array, points at Super text chunk objects that can store millions to billions of lines each)
  Each array entry:
    4 byte num lines
    4 byte Super chunk object ID

Super text chunk object (Fixed array, max 65536 entries before splitting the Super chunk)
  Each array entry:
    2 byte num lines
    4 byte Chunk object ID (generally limited to 1 DATA chunk)

Chunk object (Raw data)
  Encoding options:  1 = Raw data, 16 = Deflate compression

Metadata
  newline = Sequence of bytes that denote a line ending (e.g. \r\n, \x00)
  charset = String containing the character set encoding used (e.g. utf-8)
  mimetype = String containing the primary MIME type of the file data (e.g. text/plain, text/html, application/json, text/x-cpp)
  language = String containing the IANA language code used in the file contents (e.g. en-us)
  author = String containing the author of the file (e.g. Bob's Document Farm)
  signature = Object ID of digital signature object (design and implementation is left as an exercise)
```

With this general structure, the average text editor could handle editing files up to 280TB in size before any notable problems would arise.  This is an improvement of 5.6 million times greater when compared to today's average text editor, which begins to have notable problems at around 50MB.

Example implementation usage:

```php
<?php
	require_once "support/paging_file_cache.php";
	require_once "support/ifds.php";
	require_once "support_extra/ifds_text.php";
	require_once "test_suite/cli.php";

	// Delete any previous file.
	@unlink("ifds.iphp");

	// Create the file.
	$pfc = new PagingFileCache();
	$pfc->Open("ifds.iphp");

	$ifdstext = new IFDS_Text();
	$result = $ifdstext->Create($pfc, array("compress" => true, "trail" => false, "mimetype" => "application/x-php"));
	if (!$result["success"])  CLI::DisplayError($result);

	// Write the data.
	$data = file_get_contents("support/ifds.php");

	$result = $ifdstext->WriteLines($data, 0, 0);
	if (!$result["success"])  CLI::DisplayError($result);

	// Generally a good idea to explicitly call Close() so that all objects get flushed to disk.
	$ifdstext->Close();
?>
```

Now every text editor, every CLI tool (grep, sed, git, etc.), and every library just needs to be updated to support the IFDS TEXT file format.  Not difficult and most definitely won't cause anyone to get upset at all.

Again, this simple example barely scratches the surface of IFDS.

Use case:  Replace Configuration Files
--------------------------------------

Configuration files (e.g. INI, conf) are special, common cases of text files.  They have a myriad of problems:

* Inconsistent definitions across multiple applications.
* The signal to noise ratio of "configuration key-values to comments" is always either too low or too high depending on skill level/understanding.  Comments that do exist are generally nonsensical to first-time users and make navigation annoying later on to those who understand the options.
* Configuration hierarchies are difficult to visualize and generally require spanning configurations across multiple, possibly hundreds to thousands of files (e.g. Nginx config files).  This slows down application startup.
* Error prone.  A single option or value entered incorrectly results in wasted time, sometimes taking hours of debugging to figure out what went wrong.  Also, in many cases, an invalid entry causes the application to fail to start, which results in downtime.
* Each application that interfaces with any given configuration file has to do a lot of extra work to correctly parse each file for both syntax and structure.  Again, this is error prone and likely to break.
* There are no data type controls/option limits to allow a generic configuration editing tool to be created that works for all applications.
* No support for binary data.
* No multilingual support.  Generally limited to the author's language.
* Difficult to modify programmatically.
* Difficult to upgrade.  When a new version of an application comes out, it has to be infinitely backwards compatible with all kinds of previous configuration files that it used to support.
* Configuration information is not actually suitable for storage in text files.  Current configuration files are binary data being stored in a pleasingly laid out format suitable for humans to read in a text editor but the result is not compatible with how computer systems actually work.

IFDS could be used to solve all of those problems.

```
IFDS CONF file format

Sections (Key-ID map)
  The keys are section names.  The object IDs link to Section objects.

Section object (Key-Value map)
  The keys are option names.
  The empty string key's value is a string that specifies the Context for the section.
  For other keys, the values are as follows:
    1 byte Status/Type (Bit 7:  0 = Use default value(s)/Disabled section, 1 = Use option value(s); Bit 6:  Multiple values; Bits 0-6:  Option Type)
    Remaining bytes are the option's value(s) (Big-endian storage; When using multiple values, string/binary data/section name/unknown is preceded by 4 byte size, other types preceded by 1 byte size)

Metadata
  app = String containing the application this configuration is intended to be used with (e.g. Frank's App)
  ver = String containing the version of the application this configuration is intended to be used with (e.g. 1.0.3)
  charset = String containing the character set encoding used for Strings (e.g. utf-8)
```

```
IFDS CONF-DEF file format

Contexts (Key-ID map)
  A mapping of all Context objects.  All keys are strings.

Context object (Key-ID map)
  A mapping of all options for the context.
  The empty string maps to a Doc object (documentation object) for this context.
  All other keys map to Option objects.

Options list (Linked list)

Option object (Linked list node, Raw data)
  1 byte Option Info (Bit 0 = Deprecated)
  1 byte Option Type (0 = Boolean, 1 = Integer, 2 = IEEE Float, 3 = IEEE Double, 4 = String, 5 = Binary data, 6 = Section names; Bit 6 = Multiple values; Bit 7 is reserved)
  4 byte Doc object ID (0 = No documentation)
  4 byte Option Values object ID (0 = Freeform)
  2 byte size of MIME type string + MIME type string for the data that immediately follows (e.g. 'int/bytes', 'int/bits', 'application/json', 'image/jpeg'), allows a configuration tool to adapt what users see/enter
  Remaining bytes are the application's default value(s) for the option (Big-endian storage; When using multiple values, string/binary data/section name/unknown is preceded by 4 byte size, other types preceded by 1 byte size)

Option Values object (Key-ID map)
  The keys are the raw internal allowed values.  The object IDs link to Doc objects.  For Integer types, keys may be an allowed range of values and are stored as a string ("1-4").

Docs list (Linked list)

Doc object (Linked list node, Key-Value map)
  The keys are lowercase IANA language keys (e.g. 'en-us').  Each value is the string containing the documentation to display in that language.
  If the string is a URI/URL, the configuration tool can display the URI/URL as-is and/or grab the content from that URI/URL and display it.

Metadata
  app = String containing the application this configuration definition is intended to be used with (e.g. Frank's App)
  ver = String containing the version of the application this configuration definition is intended to be used with (e.g. 1.0.3)
  charset = String containing the character set encoding used for Strings (e.g. utf-8)
```

Example implementation usage:

```php
<?php
	require_once "support/paging_file_cache.php";
	require_once "support/ifds.php";
	require_once "support_extra/ifds_conf.php";
	require_once "test_suite/cli.php";

	// Delete any previous file.
	@unlink("ifds.iini");

	// Create the file.
	$pfc = new PagingFileCache();
	$pfc->Open("ifds.iini");

	$ifdsconf = new IFDS_Conf();
	$result = $ifdsconf->Create($pfc, array("app" => "PHP", "ver" => phpversion()));
	if (!$result["success"])  CLI::DisplayError($result);

	// Create a new configuration section.
	$result = $ifdsconf->CreateSection("PHP", "ini");
	if (!$result["success"])  CLI::DisplayError($result);

	$iniobj = $result["obj"];
	$iniopts = $result["options"];

	// Copy all PHP variables to the section.
	$phpini = ini_get_all();
	foreach ($phpini as $key => $info)
	{
		if (isset($info["global_value"]))  $iniopts[$key] = array("use" => true, "type" => IFDS_Conf::OPTION_TYPE_STRING, "vals" => array($info["global_value"]));
	}

	$result = $ifdsconf->UpdateSection($iniobj, $iniopts);
	if (!$result["success"])  CLI::DisplayError($result);

	// Generally a good idea to explicitly call Close() so that all objects get flushed to disk.
	$ifdsconf->Close();
?>
```

See the test suite for example usage of the `IFDS_ConfDef` class, which implements the IFDS CONF-DEF format for defining a configuration file that a generic tool could use to modify a IFDS CONF file.

Now every OS just needs to be updated to support the IFDS CONF and IFDS CONF-DEF file formats with both CLI and GUI tools to make it easy and painless to manage application configurations.  Not difficult and most definitely won't cause anyone to get upset at all.

Also, once again, this simple example barely scratches the surface of IFDS.

Documentation
-------------

* [PagingFileCache class](docs/paging_file_cache.md) - Applies a page cache layer over physical file and in-memory data.
* [IFDS class](docs/ifds.md) - The official reference implementation of the Incredibly Flexible Data Storage (IFDS) file format specification.
* [IFDS specification](docs/ifds_specification.md) - The Incredibly Flexible Data Storage (IFDS) technical specification of the IFDS file format.
* [IFDS_Text class](docs/ifds_text.md) - One possible implementation of an example replacement format for the classic text file format with nifty features such as support for massive file sizes (up to roughly 280TB) and transparent data compression and decompression.
* [IFDS_Conf and IFDS_ConfDef classes](docs/ifds_conf.md) - An example implementation of a possible replacement format for configuration and configuration definition files respectively.
