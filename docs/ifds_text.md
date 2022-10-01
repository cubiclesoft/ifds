IFDS_Text Class:  'support/ifds_text.php'
=========================================

The IFDS_Text class is one possible implementation of an example replacement format for the classic text file format.  Utilizes the Incredibly Flexible Data Storage (IFDS) file format for storing and reading extremely large text files.  Can also transparently compress and decompress data.  Released under a MIT or LGPL license, your choice.

Example usage can be seen in the main IFDS documentation and the IFDS test suite.

IFDS_Text::SetCompressionLevel($level)
--------------------------------------

Access:  public

Parameters:

* $level - An integer containing a compression level (0-9).  Default is -1.

Returns:  Nothing.

This function sets the compression level to pass to `DeflateStream::Compress()`.

IFDS_Text::Create($pfcfilename, $options = array())
---------------------------------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache, a string containing a filename, or a boolean of false.
* $options - An array containing options to set (Default is array()).

Returns:  A standard array of information.

This function creates an IFDS text format file with the specified options.

The $options array accepts these options:

* compress - A boolean that specifies whether or not data should be compressed (Default is false).
* trail - A boolean that specifies whether or not there is a trailing newline at the end of the file (Default is true).
* newline - A string containing the bytes that represent a newline (Default is "\n").
* charset - A string containing the character set (Default is "utf-8").
* mimetype - A string containing the MIME type (Default is "text/plain").
* language - A string containing the language (Default is "en-us").
* author - A string containing the author (Default is "").

IFDS_Text::Open($pfcfilename)
-----------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache, a string containing a filename, or a boolean of false.

Returns:  A standard array of information.

This function opens an IFDS text format file.

IFDS_Text::GetIFDS()
--------------------

Access:  public

Parameters:  None.

Returns:  The internal IFDS object.

This function returns the internal IFDS object.  Can be useful for custom modifications (e.g. adding metadata for a editor, compiler, or tool).

IFDS_Text::Close()
------------------

Access:  public

Parameters:  None.

Returns:  Nothing.

This function closes an open IFDS text format file.

IFDS_Text::Save($flush = true)
------------------------------

Access:  public

Parameters:

* $flush - A boolean indicating whether or not to also flush data in the IFDS object (Default is true).

Returns:  A standard array of information.

This function saves internal state information and optionally flushes the internal IFDS object data as well.

IFDS_Text::GetNumLines()
------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the number of lines in the file.

This function uses the line lookup table to calculate the total number of lines in the file.

IFDS_Text::WriteMetadata()
--------------------------

Access:  protected

Parameters:  None.

Returns:  A standard array of information.

This internal function writes updated metadata to the named metadata object.

IFDS_Text::GetNewline()
-----------------------

Access:  public

Parameters:  None.

Returns:  A string containing the bytes used for newlines.

This function returns the bytes used for newlines in the file data.

IFDS_Text::IsCompressEnabled()
------------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if data should be compressed when writing new data, false otherwise.

This function returns whether or not data should be compressed when writing new data.

IFDS_Text::SetCompressData($enable)
-----------------------------------

Access:  public

Parameters:

* $enable - A boolean indicating whether or not new data should be compressed.

Returns:  Nothing.

This function enables/disables the data compression bit for the file.  Only affects new data written to the file.

IFDS_Text::IsTrailingNewlineEnabled()
-------------------------------------

Access:  public

Parameters:  None.

Returns:  A boolean of true if there is a trailing newline at the end of the file, false otherwise.

This function returns whether or not there is a trailing newline at the end of the file.

IFDS_Text::SetTrailingNewline($enable)
--------------------------------------

Access:  public

Parameters:

* $enable - A boolean indicating whether or not a trailing newline exists at the end of the file.

Returns:  A standard array of information.

This function is used later to determine whether or not there is a trailing newline at the end of the last line in the file when reading data.

IFDS_Text::GetCharset()
-----------------------

Access:  public

Parameters:  None.

Returns:  A string containing the character set of the file data.

This function returns the character set for the data.

IFDS_Text::SetCharset($charset)
-------------------------------

Access:  public

Parameters:

* $charset - A string containing the character set.

Returns:  A standard array of information.

This function sets the character set for the file.

IFDS_Text::GetMIMEType()
------------------------

Access:  public

Parameters:  None.

Returns:  A string containing the MIME type of the file data.

This function returns the MIME type for the data.

IFDS_Text::SetMIMEType($mimetype)
---------------------------------

Access:  public

Parameters:

* $mimetype - A string containing the MIME type.

Returns:  A standard array of information.

This function sets the MIME type for the file.

IFDS_Text::GetLanguage()
------------------------

Access:  public

Parameters:  None.

Returns:  A string containing the IANA language code of the file data.

This function returns the IANA language code for the data.

IFDS_Text::SetLanguage($language)
---------------------------------

Access:  public

Parameters:

* $language - A string containing the IANA language code.

Returns:  A standard array of information.

This function sets the IANA language code for the file.

IFDS_Text::GetAuthor()
----------------------

Access:  public

Parameters:  None.

Returns:  A string containing the author or owner of the file.

This function returns the author or owner of the file.

IFDS_Text::SetAuthor($author)
-----------------------------

Access:  public

Parameters:

* $author - A string containing the author or owner of the file.

Returns:  A standard array of information.

This function sets the author or owner of the file.

IFDS_Text::CreateSuperTextChunk($chunknum)
------------------------------------------

Access:  protected

Parameters:

* $chunknum - An integer containing the location of the new super text chunk.

Returns:  A standard array of information.

This internal function creates and inserts a super text chunk.

IFDS_Text::LoadSuperTextChunk($chunknum)
----------------------------------------

Access:  protected

Parameters:

* $chunknum - An integer containing the super text chunk to load.

Returns:  A standard array of information.

This internal function loads a super text chunk and extracts it.

IFDS_Text::ReadDataInternal($obj)
---------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object to read data from.

Returns:  A standard array of information.

This internal function reads and decompresses data from an IFDS_RefCountObj object.

IFDS_Text::WriteDataInternal($obj, &$data)
------------------------------------------

Access:  protected

Parameters:

* $obj - An instance of a IFDS_RefCountObj object to write data to.
* $data - A string containing the data to write.

Returns:  A standard array of information.

This internal function compresses (optional) and writes data to an IFDS_RefCountObj object.  Any existing data is overwritten.

IFDS_Text::WriteLines($lines, $offset, $removelines)
----------------------------------------------------

Access:  public

Parameters:

* $lines - An array or a string containing lines to insert.
* $offset - An integer containing the offset, in lines, to start removing/inserting lines.
* $removelines - An integer containing the number of lines to remove at the offset before inserting lines at the offset.

Returns:  A standard array of information.

This function removes lines and inserts new lines starting at the specified offset.

IFDS_Text::ReadLines($offset, $numlines, $ramlimit = 10485760)
--------------------------------------------------------------

Access:  public

Parameters:

* $offset - An integer containing the offset, in lines, to start reading lines.
* $numlines - An integer containing the maximum number of lines to read.
* $ramlimit - An integer containing the maximum number of bytes to read (Default is 10485760).

Returns:  A standard array of information.

This function reads lines starting at the specified offset.

IFDS_Text::IFDSTextTranslate($format, ...)
------------------------------------------

Access:  _internal_ static

Parameters:

* $format - A string containing valid sprintf() format specifiers.

Returns:  A string containing a translation.

This internal static function takes input strings and translates them from English to some other language if CS_TRANSLATE_FUNC is defined to be a valid PHP function name.
