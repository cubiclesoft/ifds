Incredibly Flexible Data Storage (IFDS) Specification
-----------------------------------------------------

This document contains the official specification for the Incredibly Flexible Data Storage (IFDS) file format.  This specification is released under a Creative Commons 0 (CC0) license.

The current version of this specification is:  1.0.

All data is stored in big endian format.  For example, a 32-bit integer containing the value of 1 would be stored as `00 00 00 01`.  IFDS supports up to 2^64 storage, so implementations must use/support a minimum of 64-bit integers.

Every IFDS file has a name mapping and object ID table.  The free space table is optional and only created when an object/data is resized or moved.

The IFDS file format is carefully designed such that external tools can read and verify files in the IFDS file format without having to understand the contents of the data being stored.

IFDS Header
-----------

Every IFDS file format starts with the IFDS header:

```
0x80 | length + magic string (Default is "\x89IFDS\r\n\x00\x1A\n").  Magic string must end with "\r\n\x00\x1A\n".
1 byte IFDS major version (newer major version = completely incompatible with previous versions but also very unlikely to ever change in the first place)
1 byte IFDS minor version (newer minor version = probably minor/compatible changes)
2 byte app/custom format major version
2 byte app/custom format minor version
2 byte app/custom format patch/build number
4 byte enabled format features (bit field)
4 byte enabled app features (bit field)
8 byte base date (UNIX timestamp / 86400)
8 byte name table position
8 byte ID chunks table position
8 byte free space chunks table position
4 byte CRC-32
```

Format Features
---------------

Various features can be enabled that alter the file format.  Currently, three format features are defined.

* Bit 0:  Node IDs.  The object/node ID is appended after the object size.  Mostly redundant but useful when reading streaming content or objects that will be frequently added and removed.
* Bit 1:  Object ID table structure size.  The object ID table has a 2 byte structure size to allow for performing a single read operation to load an object.  Recommended for improved read performance.
* Bit 2:  Object ID table last access.  The object ID table has a 2 byte last access day.  Used to sort objects in large files when optimizing so that the most recently accessed objects generally appear earlier in the file.

App Features
------------

Application-defined features can be specified.  These features could be used to alter application-defined node types.

Name Table (Key-ID map)
-----------------------

The name table maps string keys to object IDs.

ID Chunks Table (Fixed array)
-----------------------------

The ID chunks table points to ID table entries structures.

Format of each entry:

```
8 byte ID table entries position
2 byte number of unassigned IDs
```

Supports a maximum of 65,536 entries in this fixed array.

ID Table Entries (Fixed array)
------------------------------

Contains a pointer to an object, object size, and last access/modification information for each object ID.  The actual size of each entry in this table can vary depending on Format Features and how large the file is.

The first object ID is 1 but is stored at position 0.  2 => 1, 3 => 2, etc.

Format of each entry:

```
2/4/8 byte object position
[2 byte structure size]
[2 byte last access day]
```

The integer size of the object position is determined by the fixed array size.  Structure size and last access day exist for each entry when relevant Format Features are enabled.  Structure size feature is recommended for improved read performance.

Supports a maximum of 65,536 entries in this fixed array.

Free Space Chunks Table (Fixed array)
-------------------------------------

The free space chunks table is not defined for all files.

Format of each entry:

```
8 byte free space table entries position
4 byte largest free space
```

The design of the free space structures has notable performance and security implications:

* Position/size information can be imprecise or intentionally falsified.
* Free space table structures can overlay valid data.

Implementations must never trust the information found in the free space structures.  When a problem is detected, just assume the space is actually full (i.e. 0 bytes free).

There is no maximum number of entries in this fixed array but implementations should cap the number of allowed entries to the size of the file.

Free Space Table Entries (Fixed array)
--------------------------------------

Each free space table tracks up to 4.2GB of storage.  Each entry in this table tracks the largest free space in a 65,536 byte chunk and the starting position of that space using just 4 bytes of data.

Format of each entry:

```
2 byte largest free space
2 byte first space start position
```

There are two combinations of free space and position that have special meaning:

* 0x0000FFFF = 65,536 bytes free
* 0xFFFFFFFF = 0 bytes free

These special cases allows the values 0 through 65,536 to be represented in just 2 bytes.

Supports a maximum of 65,536 entries in this fixed array.

Free space tables are not defined for all files.  When defined, entries not specified after the last entry are assumed to be full.

Streaming
---------

If the name table position and ID chunks table position of the header are zero, then this is a streamed file and critical data is located at the end of the file.

Restrictions when generating streamed files:  Insert before the end, deletions, and detach/attach are not allowed.  Linked nodes "next" pointers are invalid.

Note that even if the entire file hasn't been streamed, various internal aspects within the file may have been streamed during construction (e.g. interleaved data channels).

The last node of a streamed file is a terminating DATA CHUNKS containing finalized header information:

```
1 byte (0x3F)
1 byte (0x01)
2 byte data chunk size (16)
8 byte name table position
8 byte ID chunks table position
4 byte CRC-32
```

Upon opening a completed streaming file, load the 16 bytes from the end to get the name and ID chunks table positions.

Opening an incomplete streaming file (e.g. a file being read over a network) is possible but with these restrictions:  There is no way to obtain the last node, name table, or ID chunks table position in advance.  However, an application can use the node type and encoder/decoder byte of a node to determine that node's purpose.  Also, when the previous node ID for a node exists and is 0, the node is the start of the linked list for the structure.

When the Node ID feature bit is set, the application can also keep track of the current node ID and the previous node IDs for each structure.

Objects
-------

Each object contains a data structure type byte (deleted, raw data, map, etc.), a data encoder + data type byte, 2 byte object size, optional format information (e.g. object ID), data structure information, and a 4 byte CRC-32.

The maximum size of any object may not be over 32,767 bytes (2^15 - 1).  This limit allows for a single read operation of both structure and the start of most DATA CHUNKS sections.

The first byte of an object is the data structure type:

* 0 - Deleted/Padding byte (not actually an object)
* 1 - Raw data
* 2 - Fixed array
* 3 - Double-linked list and nodes
* 4-31 - Reserved
* 32-62 - Application-defined data structure
* 63 (0x3F) - Reserved for DATA CHUNKS

The two high bits of this byte are:

* Bit 6 - Leaf node (For structures with nodes - e.g. linked list nodes)
* Bit 7 - Streamed (Structure and object pointers need to be updated before the application can access the structure)

If the data structure type is not 0 (i.e. not a Deleted/Padding byte), the next byte of an object is the data encoder/data type byte:

* 0 - None (linked list header, NULL data, etc. - bits 6 and 7 must also be 0)
* 1 - Raw data (No data decoding)
* 2 - Key-ID map
* 3 - Key-value map
* 4-15 - Reserved
* 16-63 - Application/implementation-defined (e.g. for a custom file format, 16 could be defined as JSON, 17 = JPEG, 18 = PNG, ...)

The two high bits (6 and 7) of this byte are:

* 0 - No data
* 1 - INTERNAL DATA used (DATA CHUNKS not used)
* 2 - DATA CHUNKS used, seekable (DATA locations table 0x02 follows immediately after this object)
* 3 - DATA CHUNKS used, streaming, uses virtual channels (Interleaved DATA CHUNKS are used)

When bits 6 and 7 of this byte are equal to 1, INTERNAL DATA is used.  Data to be stored must fit inside the object.  This is recommended only when using 3KB or less of data.

INTERNAL DATA is placed at the end of the object with a 2 byte size appended to the data.  This allows data to shrink and grow inside an object, which helps minimize file fragmentation.

Users of IFDS implementations must never assume that position information in an IFDS file is static and won't change.  All objects are free to move to other locations within an IFDS file - even outside the application the IFDS file is intended to be used with.

Key-ID/Key-value Map
--------------------

Each entry of a key-ID map is encoded as follows:

```
2 byte length + key (high bit of length:  0 = signed integer, 1 = binary string/data)
4 byte object ID
```

Each entry of a key-value map is encoded as follows:

```
2 byte length + key (high bit of length:  0 = signed integer, 1 = binary string/data)
2 byte length (minus high bit) + 2 byte second length (only if high bit of the first length is set) + value
```

For integer keys, when length is 1, 2, 4, or 8, that many bytes follow containing the actual key.  Signed integers are stored.  For all other small, positive integer keys from 0 through 32,767, the length is just used by itself as the key.  String keys can be up to 32,767 bytes in length.

The length of values of a key-value map can technically be up to 2.1GB but should be practically limited to just a few MB.  Implementations and applications may enforce hard limits on the length that can be used for a single value.

Object:  Raw Data
-----------------

Stores raw data.

```
1 byte data structure type (1)
1 byte data encoder/decoder
2 byte structure size
[4 byte node ID]
INTERNAL DATA
4 byte CRC-32
DATA CHUNKS
```

Object:  Fixed Array
--------------------

Stores sequential fixed-size entries.

```
1 byte data structure type (2)
1 byte data encoder/decoder (1)
2 byte structure size
[4 byte node ID]
4 byte entry size
4 byte num entries
INTERNAL DATA
4 byte CRC-32
DATA CHUNKS
```

The total amount of data stored is supposed to be `entry size * num entries`.  However, users of fixed arrays should not rely on the number of entries to be correct and should validate that the entry size is correct before reading any data.

Object:  Double-linked List
---------------------------

Stores the head of a double-linked list.

```
1 byte data structure type (3)
1 byte data encoder/decoder (0)
2 byte structure size
[4 byte node ID]
4 byte num nodes
4 byte first node ID
4 byte last node ID
4 byte CRC-32
```

This object generally does not store any data as the data in a linked list is usually stored in the linked list nodes themselves.

Object:  Linked List Node
-------------------------

Stores a node for a double-linked list.

```
1 byte data structure type (3 | 0x40)
1 byte data encoder/decoder
2 byte structure size
[4 byte node ID]
4 byte prev node ID
4 byte next node ID
INTERNAL DATA
4 byte CRC-32
DATA CHUNKS
```

When attached to a linked list, the first and last node point at the linked list head ID (if it has one).

When streaming, next node IDs should point at the linked list head ID.  Next node IDs will need to be corrected before accessing a streamed linked list for the first time.

Object:  Application-defined Data Structure/Node
------------------------------------------------

Stores a custom data structure.

```
1 byte data structure type (32-62)
1 byte data encoder/decoder
2 byte structure size
[4 byte node ID]
Application-defined type info
INTERNAL DATA
4 byte CRC-32
DATA CHUNKS
```

DATA CHUNKS
-----------

Data that does not fit inside an object is stored in DATA CHUNKS.  DATA CHUNKS come in two flavors:  Dynamic seekable and static interleaved multi-channel.  Dynamic seekable is tracked using a special DATA locations table, which allows for rapidly storing and retrieving up to 280TB of data scattered around any IFDS file.  Static interleaved multi-channel doesn't support seeking but instead supports interleaved multi-channel data storage and can theoretically store up to 17EB.

Note that dynamic seekable DATA CHUNKS can be stored anywhere in a file and can even be stored out of order.  Data for an object can appear both before and after an object.

If the first byte of an object's structure type is 0x3F, then it is actually DATA CHUNKS.

Seekable DATA chunk:

```
1 byte = 0x3F (Invalid data structure type specifically reserved for DATA CHUNKS that usually follow an object node)
1 byte = 0x00 and 0x01 (DATA chunk and DATA chunk w/ termination respectively)
2 byte data size - Values up to 65528 are valid.
  The 65528 limit allows exactly 65536 bytes to be retrieved per read operation for memory alignment but not disk read alignment purposes.
  Disk alignment isn't really possible anyway due to PagingFileCache.
DATA
4 byte CRC-32
```

Interleaved DATA chunk:

```
1 byte = 0x3F | 0x80 (DATA chunk with streaming/interleaved bit set)
1 byte = 0x00 and 0x01 (DATA chunk and DATA chunk w/ termination respectively)
2 byte data size - Values up to 65526 are valid.
2 byte virtual channel ID (application/implementation-defined)
DATA
4 byte CRC-32
```

When interleaving, all DATA chunks for the object must be interleaved.  This allows external tools to differentiate between interleaved and non-interleaved DATA CHUNKS.  Non-interleaved is both seekable and streamable while interleaved is streamable only.  No particular limit on data length.

An interleaved channel is terminated when using 0x01.

DATA CHUNKS itself always ends when channel 0 is terminated.  Other channels MAY remain active across multiple data structure nodes in a single structure (application-defined).  This allows for scenarios such as constructing two linked lists and flushing data accumulated for the second list every few seconds and then picking up again in the first list after that.

Note that interleaved data may only be written one time, must be written sequentially, and is considered read only after channel 0 is closed.

DATA locations table:

```
1 byte = 0x3F
1 byte = 0x02 (DATA location table)
2 byte num entries
(2 byte number of full seekable DATA chunks (65,536 bytes each) + 8 byte seekable DATA chunks start position) * num entries
2 byte last seekable DATA chunk size + 8 byte seekable DATA chunk start position
4 byte CRC-32

This table must appear immediately after a seekable object.  When appending new max DATA chunks and num entries is 65535 (0xFFFF), merge down until num entries is 52268 (80%).  Maximum data size supported is approximately 280TB.

Implementation Issues
---------------------

Implementing IFDS is not a simple, straightforward task.  Even veteran software developers will find IFDS to be a challenge to implement both correctly and securely.  The PHP reference implementation weighs in at around 5,600 lines of fairly dense code and is intended as a reference rather than being a highly optimized implementation of the IFDS specification (e.g. some math is expanded to better mirror the specification).

Here are several difficult "gotchas" to watch out for when implementing IFDS in a new language/library:

* False object and free space size reporting.  Whether from data corruption or malicious intent, injecting false size information can lead to buffer overflows or corrupting other data.
* Object ID map tables should not use object IDs.  Almost certainly done with malicious intent, using object IDs in the object ID map can lead to data corruption.  A well-written library will detect and clear the obvious attempt to cause problems.
* There are a number of "chicken or the egg" problems:  First, writing a IFDS-based file requires implementing the entire specification first, which makes a "test often" approach virtually impossible.  Second, writing most objects such that they appear before their data requires knowing the size of the data but not all data sizes can be known in advance.  Third, the free space mapping table, when defined, will tend to occupy free space.
* Linked lists might have loops.  It is possible to maliciously create a loop in a linked list.  It is harder to detect loops in linked lists.
* Deciding what to do with objects that are invalid.  Every object has a CRC-32 associated with it.  Ignoring invalid objects might create issues later on but simply failing when encountering an invalid object might cause a file to fail to load that would otherwise be fine.
* Managing memory can get tricky.  This file format is designed to handle extremely large data storage far beyond what today's OSes can generally handle.  Actively managing and limiting system memory usage when reading and writing IFDS files is a very important feature of any IFDS library implementation.
* Object IDs start at 1 but the ID table starts at 0.  Off-by-one errors can result in reading from or writing to the wrong location.
* Unassigned IDs only go up to 65,535.  Each ID table supports up to 65,536 entries while the ID chunks table will only report up to 65,535 unassigned IDs.  Implementations should really only care about zero vs. non-zero when locating the first available, unused object ID.
* Merge down operations are exceptionally rare.  The DATA locations table, when it gets full, should be merged down to make room for more data.  The process effectively defragments a portion of DATA chunks by combining them.  The earliest this process occurs is after 4.2GB of fragmented data has been written.  Due to the rarity of this happening, merge down code may contain bugs and be less frequently tested.

Implementation Patterns
-----------------------

Creating an object follows this procedure:

* Reserve/Assign an object ID.
* If the object data size is known, attempt to find a free space that is large enough for the data but no more than 20% larger.
* If the object data is of unknown size OR free space was not found, create the object at the end of the file.
* Update the ID table entries structure with the new file position.

Moving an object follows this procedure:

* Attempt to find a free space that is large enough for the data.
* If not found, copy the object to the end of the file, zero out the old bytes, and update the free space table.
* Update the ID table entries structure with the new file position.

Adding bytes to the free space table follows this procedure:

* Fill the space with zeroes.
* Find the largest size for each 65536 byte chunk.
* Update the first space start position and largest size in the free space table.

Deleting an object follows this procedure:

* Verify that the object has detached all connections (e.g. linked list nodes).
* Delete the DATA CHUNKS (if any).
* If the object ID is not zero (anything but those in the header), zero the file position in the ID table entries structure and reduce assignment count in chunks table structure.
* Update free space table.

Optimizing the file follows this procedure (in a new, temporary file):

* Write header.  Set creation date to today.
* Write name table.
* Write placeholder ID chunks table and ID table entries structures.
* Copy each object in order of next linked list node (when handling a list), last access day (descending), and object ID (ascending).  Reset last access day to 0.  Update positions in the ID table entries structure.  Minimize the structure size of each object and recalculate the CRC-32.
* Write ID chunks table and ID table entries structures.  Reset last access day to 0.
* Finalize header.
* Delete original file.
* Rename new file to original file.
