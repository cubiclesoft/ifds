IFDS_Conf and IFDS_ConfDef Classed:  'support/ifds_conf.php'
============================================================

The IFDS_Conf class is an example implementation of a possible replacement format for configuration files.  Utilizes the Incredibly Flexible Data Storage (IFDS) file format for storing and reading application configuration information in a binary/machine readable format.  Released under a MIT or LGPL license, your choice.

The IFDS_ConfDef class is an example implementation of a potential file format for making it possible to create a universal application configuration tool.  Utilizes the Incredibly Flexible Data Storage (IFDS) file format for storing and reading contexts, options, selectable option values, and multilingual documentation.

Example usage can be seen in the main IFDS documentation and the IFDS test suite.

IFDS_Conf::Create($pfcfilename, $options = array())
---------------------------------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache, a string containing a filename, or a boolean of false.
* $options - An array containing options to set (Default is array()).

Returns:  A standard array of information.

This function creates an IFDS configuration format file with the specified options.

The $options array accepts these options:

* app - A string containing the application this configuration is intended to be used with (Default is "").
* ver - A string containing the version of the application this configuration is intended to be used with (Default is "").
* charset - A string containing the character set of Strings (Default is "utf-8").

IFDS_Conf::Open($pfcfilename)
-----------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache, a string containing a filename, or a boolean of false.

Returns:  A standard array of information.

This function opens an IFDS configuration format file.

IFDS_Conf::GetIFDS()
--------------------

Access:  public

Parameters:  None.

Returns:  The internal IFDS object.

This function returns the internal IFDS object.  Can be useful for custom modifications (e.g. adding metadata for a editor, compiler, or tool).

IFDS_Conf::Close()
------------------

Access:  public

Parameters:  None.

Returns:  Nothing.

This function closes an open IFDS configuration format file.

IFDS_Conf::Save($flush = true)
------------------------------

Access:  public

Parameters:

* $flush - A boolean indicating whether or not to also flush data in the IFDS object (Default is true).

Returns:  A standard array of information.

This function saves internal state information and optionally flushes the internal IFDS object data as well.

IFDS_Conf::WriteMetadata()
--------------------------

Access:  protected

Parameters:  None.

Returns:  A standard array of information.

This internal function writes updated metadata to the named metadata object.

IFDS_Conf::GetApp()
-------------------

Access:  public

Parameters:  None.

Returns:  A string containing the application.

This function returns the application this configuration file is intended to be used with.

IFDS_Conf::SetApp($app)
-----------------------

Access:  public

Parameters:

* $app - A string containing the application.

Returns:  A standard array of information.

This function sets the application this configuration file is intended to be used with.

IFDS_Conf::GetVer()
-------------------

Access:  public

Parameters:  None.

Returns:  A string containing the application version.

This function returns the version of the application this configuration file is intended to be used with.

IFDS_Conf::SetVer($ver)
-----------------------

Access:  public

Parameters:

* $ver - A string containing the application version.

Returns:  A standard array of information.

This function sets the version of the application this configuration file is intended to be used with.

IFDS_Conf::GetCharset()
-----------------------

Access:  public

Parameters:  None.

Returns:  A string containing the character set for string options.

This function returns the character set for string options.

IFDS_Conf::SetCharset($charset)
-------------------------------

Access:  public

Parameters:

* $charset - A string containing the character set.

Returns:  A standard array of information.

This function sets the character set for string options.

IFDS_Conf::GetSectionsMap()
---------------------------

Access:  public

Parameters:  None.

Returns:  An array containing a section name to ID mapping.

This function returns the section name to ID map.

IFDS_Conf::CreateSection($name, $contextname)
---------------------------------------------

Access:  public

Parameters:

* $name - A string containing the name of the new section.  Must be unique.
* $contextname - A string containing the name of a configuration definition context.

Returns:  A standard array of information.

This function creates a new configuration section.  A context allows a generic configuration tool to manage the configuration file via the IFDS configuration definition file format.

IFDS_Conf::DeleteSection($name)
-------------------------------

Access:  public

Parameters:

* $name - A string containing the name of the section to delete.

Returns:  A standard array of information.

This function deletes a configuration section.

IFDS_Conf::RenameSection($oldname, $newname)
--------------------------------------------

Access:  public

Parameters:

* $oldname - A string containing the name of the section to rename.
* $newname - A string containing the new name of the section.  Must be unique.

Returns:  A standard array of information.

This function renames a configuration section.

IFDS_Conf::ExtractTypeData(&$val, $pos, $type, $multiple)
---------------------------------------------------------

Access:  _internal_ static

Parameters:

* $val - A string containing data to extract type data from.
* $pos - An integer containing the starting position in $val.
* $type - An integer containing a valid data type.
* $multiple - A boolean indicating whether or not to extract multiple values.

Returns:  An array containing extracted values.

This internal function extracts type data for both the IFDS_Conf and IFDS_ConfDef classes.

IFDS_Conf::GetSection($name)
----------------------------

Access:  public

Parameters:

* $name - A string containing the name of the section to retrieve.

Returns:  A standard array of information.

This function loads and extracts a configuration section.

IFDS_Conf::AppendTypeData(&$val, $type, &$vals)
-----------------------------------------------

Access:  _internal_ static

Parameters:

* $val - A string to append type data to.
* $type - An integer containing a valid data type.
* $vals - An array containing values to append.

Returns:  Nothing.

This internal function appends type data for both the IFDS_Conf and IFDS_ConfDef classes.

IFDS_Conf::UpdateSection($obj, $options)
----------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object to write section options to.
* $options - An array containing section options.

Returns:  A standard array of information.

This function prepares and writes section options to the section object.

The empty string key in the $options array maps to a string containing the context.

All other entries in the $options array maps a key to:

* use - A boolean of true to use this option's value(s) or a boolean of false to use default value(s) or disable the section (for a section type).
* type - An integer containing a valid data type.
* vals - An array containing the values to store.

Valid data types are one of:

* IFDS_Conf::OPTION_TYPE_BOOL (0) - Boolean
* IFDS_Conf::OPTION_TYPE_INT (1) - Integer (signed, variable size)
* IFDS_Conf::OPTION_TYPE_FLOAT (2) - IEEE Float
* IFDS_Conf::OPTION_TYPE_DOUBLE (3) - IEEE Double
* IFDS_Conf::OPTION_TYPE_STRING (4) - String
* IFDS_Conf::OPTION_TYPE_BINARY (5) - Binary data
* IFDS_Conf::OPTION_TYPE_SECTION (6) - Section name

IFDS_Conf::IFDSConfTranslate($format, ...)
------------------------------------------

Access:  _internal_ static

Parameters:

* $format - A string containing valid sprintf() format specifiers.

Returns:  A string containing a translation.

This internal static function takes input strings and translates them from English to some other language if CS_TRANSLATE_FUNC is defined to be a valid PHP function name.

IFDS_ConfDef::Create($pfcfilename, $options = array())
------------------------------------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache, a string containing a filename, or a boolean of false.
* $options - An array containing options to set (Default is array()).

Returns:  A standard array of information.

This function creates an IFDS configuration definition format file with the specified options.

The $options array accepts these options:

* app - A string containing the application this configuration definition is intended to be used with (Default is "").
* ver - A string containing the version of the application this configuration definition is intended to be used with (Default is "").
* charset - A string containing the character set of Strings (Default is "utf-8").

IFDS_ConfDef::Open($pfcfilename)
--------------------------------

Access:  public

Parameters:

* $pfcfilename - An instance of PagingFileCache, a string containing a filename, or a boolean of false.

Returns:  A standard array of information.

This function opens an IFDS configuration definition format file.

IFDS_ConfDef::GetIFDS()
-----------------------

Access:  public

Parameters:  None.

Returns:  The internal IFDS object.

This function returns the internal IFDS object.  Can be useful for custom modifications (e.g. adding metadata for a editor, compiler, or tool).

IFDS_ConfDef::Close()
---------------------

Access:  public

Parameters:  None.

Returns:  Nothing.

This function closes an open IFDS configuration definition format file.

IFDS_ConfDef::Save($flush = true)
---------------------------------

Access:  public

Parameters:

* $flush - A boolean indicating whether or not to also flush data in the IFDS object (Default is true).

Returns:  A standard array of information.

This function saves internal state information and optionally flushes the internal IFDS object data as well.

IFDS_ConfDef::WriteMetadata()
-----------------------------

Access:  protected

Parameters:  None.

Returns:  A standard array of information.

This internal function writes updated metadata to the named metadata object.

IFDS_ConfDef::GetApp()
----------------------

Access:  public

Parameters:  None.

Returns:  A string containing the application.

This function returns the application this configuration definition file is intended to be used with.

IFDS_ConfDef::SetApp($app)
--------------------------

Access:  public

Parameters:

* $app - A string containing the application.

Returns:  A standard array of information.

This function sets the application this configuration definition file is intended to be used with.

IFDS_ConfDef::GetVer()
----------------------

Access:  public

Parameters:  None.

Returns:  A string containing the application version.

This function returns the version of the application this configuration definition file is intended to be used with.

IFDS_ConfDef::SetVer($ver)
--------------------------

Access:  public

Parameters:

* $ver - A string containing the application version.

Returns:  A standard array of information.

This function sets the version of the application this configuration definition file is intended to be used with.

IFDS_ConfDef::GetCharset()
--------------------------

Access:  public

Parameters:  None.

Returns:  A string containing the character set for string options.

This function returns the character set for string options.

IFDS_ConfDef::SetCharset($charset)
----------------------------------

Access:  public

Parameters:

* $charset - A string containing the character set.

Returns:  A standard array of information.

This function sets the character set for string options.

IFDS_ConfDef::GetContextsMap()
------------------------------

Access:  public

Parameters:  None.

Returns:  An array containing a context name to ID mapping.

This function returns the context name to ID map.  The empty string key maps to a documentation object ID (Doc object ID) for the context.  All other keys map to Option object IDs.

IFDS_ConfDef::CreateContext($name)
----------------------------------

Access:  public

Parameters:

* $name - A string containing the name of the context to create.

Returns:  A standard array of information.

This function creates a new configuration context.  Contexts contain options.  Options specify data types, link to selectable values (or are freeform), and link to multilingual documentation objects.

IFDS_ConfDef::DeleteContext($name)
----------------------------------

Access:  public

Parameters:

* $name - A string containing the name of the context to create.

Returns:  A standard array of information.

This function deletes a configuration context.  Note that this only deletes the context object and removes it from the contexts map.  Option objects and documentation objects are not deleted.

IFDS_ConfDef::RenameContext($oldname, $newname)
-----------------------------------------------

Access:  public

Parameters:

* $oldname - A string containing the name of the context to rename.
* $newname - A string containing the new name of the context.  Must be unique.

Returns:  A standard array of information.

This function renames a configuration context.

IFDS_ConfDef::GetContext($name)
-------------------------------

Access:  public

Parameters:

* $name - A string containing the name of the context to retrieve.

Returns:  A standard array of information.

This function retrieves a configuration context.

IFDS_ConfDef::UpdateContext($obj, $optionsmap)
----------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object to write options to.
* $options - An array containing options.

Returns:  A standard array of information.

This function updates the context object.  The empty string key maps to a documentation object ID (Doc object ID) for the context.  All other keys map to Option object IDs.

IFDS_ConfDef::GetOptionsList()
------------------------------

Access:  public

Parameters:  None.

Returns:  An instance of a IFDS_RefCountObj object containing a linked list of all options.

This function returns the IFDS linked list object containing all options.

IFDS_ConfDef::CreateOption($type, $options = array())
-----------------------------------------------------

Access:  public

Parameters:

* $type - An integer containing a valid data type.
* $options - An array containing options for the option (Default is array()).

Returns:  A standard array of information.

This function creates an option object with the specified type and options and attaches the object to the options linked list.

Valid data types are one of:

* IFDS_ConfDef::OPTION_TYPE_BOOL (0) - Boolean
* IFDS_ConfDef::OPTION_TYPE_INT (1) - Integer (signed, variable size)
* IFDS_ConfDef::OPTION_TYPE_FLOAT (2) - IEEE Float
* IFDS_ConfDef::OPTION_TYPE_DOUBLE (3) - IEEE Double
* IFDS_ConfDef::OPTION_TYPE_STRING (4) - String
* IFDS_ConfDef::OPTION_TYPE_BINARY (5) - Binary data
* IFDS_ConfDef::OPTION_TYPE_SECTION (6) - Section name

The $options array accepts these options:

* info - An integer containing a bitmask (Default is IFDS_ConfDef::OPTION_INFO_NORMAL).  Possible values:  IFDS_ConfDef::OPTION_INFO_NORMAL (0x00), IFDS_ConfDef::OPTION_INFO_DEPRECATED (0x01).
* doc - An integer containing a documentation object ID (Default is 0).  No documentation when 0.
* values - An integer containing an option values object ID (Default is 0).  Freeform when 0.  Option values specify all possible/allowed values.
* mimetype - A string containing a MIME type (Default is "application/octet-stream" for OPTION_TYPE_BINARY, "" for all other types).
* defaults - An array containing option defaults (Default is array()).

IFDS_ConfDef::DeleteOption($obj)
--------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object containing an option to delete.

Returns:  A standard array of information.

This function detaches and deletes an option object.  Note that this only deletes the option object and removes it from the options linked list.  Option Value objects and documentation objects are not deleted.

IFDS_ConfDef::UpdateOption($obj, $type, $options = array())
-----------------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object containing an option to update.
* $type - An integer containing a valid data type.
* $options - An array containing options for the option (Default is array()).

Returns:  A standard array of information.

This function updates an option object with the specified type and options.  See CreateOption() for details on $type and $options.

IFDS_ConfDef::ExtractOptionData(&$data)
---------------------------------------

Access:  _internal_ static

Parameters:

* $data - A string containing option data to extract.

Returns:  An array of options on success, a boolean of false otherwise.

This internal function extracts option data into an array.

IFDS_ConfDef::GetOption($id)
----------------------------

Access:  public

Parameters:

* $id - An integer containing an option object ID.

Returns:  A standard array of information.

This function retrieves an option by its object ID.

IFDS_ConfDef::CreateOptionValues($valuesmap)
--------------------------------------------

Access:  public

Parameters:

* $valuesmap - An array containing key-ID pairs to set.

Returns:  A standard array of information.

This function creates a new option values object.  Option values allow a configuration tool to display a list of selectable items, restrict data entry, or simply provide visual feedback that a value may be incorrect depending on data type, MIME type, or other factors.  The keys are the values and the IDs are documentation object IDs.

IFDS_ConfDef::DeleteOptionValues($obj)
--------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object containing an option values object to delete.

Returns:  A standard array of information.

This function deletes an option values object.

IFDS_ConfDef::UpdateOptionValues($obj, $valuesmap)
--------------------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object containing an option values object to update.
* $valuesmap - An array containing key-ID pairs to set.

Returns:  A standard array of information.

This function updates an option values object with new key-ID pairs.

IFDS_ConfDef::GetOptionValues($id)
----------------------------------

Access:  public

Parameters:

* $id - An integer containing an option values object ID.

Returns:  A standard array of information.

This function retrieves the specified option values object and values map.

IFDS_ConfDef::GetDocsList()
---------------------------

Access:  public

Parameters:  None.

Returns:  An instance of a IFDS_RefCountObj object containing a linked list of all documentation objects.

This function returns the IFDS linked list object containing all documentation objects.

IFDS_ConfDef::CreateDoc($langmap)
---------------------------------

Access:  public

Parameters:

* $langmap - An array containing IANA language codes mapped to strings to display.

Returns:  A standard array of information.

This function creates a new documentation object and sets the language mapping.  Can be used by a universal configuration tool to display localized, translated content to a user for contexts, options, and option values.

IFDS_ConfDef::DeleteDoc($obj)
-----------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object containing a documentation object to delete.

Returns:  A standard array of information.

This function deletes a documentation object.  Note that this does not delete any references to the object.

IFDS_ConfDef::UpdateDoc($obj, $langmap)
---------------------------------------

Access:  public

Parameters:

* $obj - An instance of a IFDS_RefCountObj object containing a documentation object to update.
* $langmap - An array containing IANA language codes mapped to strings to display.

Returns:  A standard array of information.

This function updates a documentation object with the specified language mapping.

IFDS_ConfDef::GetDoc($id)
-------------------------

Access:  public

Parameters:

* $id - An integer containing a documentation object ID.

Returns:  A standard array of information.

This function retrieves the specified documentation object and language map.

IFDS_ConfDef::IFDSConfTranslate($format, ...)
---------------------------------------------

Access:  _internal_ static

Parameters:

* $format - A string containing valid sprintf() format specifiers.

Returns:  A string containing a translation.

This internal static function takes input strings and translates them from English to some other language if CS_TRANSLATE_FUNC is defined to be a valid PHP function name.
