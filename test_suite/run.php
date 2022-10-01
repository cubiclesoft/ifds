<?php
	// Test suite.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/cli.php";
	require_once $rootpath . "/../support/paging_file_cache.php";
	require_once $rootpath . "/../support/ifds.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"c" => "cleanonly",
			"p" => "perftests",
			"?" => "help"
		),
		"rules" => array(
			"cleanonly" => array("arg" => false),
			"perftests" => array("arg" => false),
			"help" => array("arg" => false)
		)
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Binary File Structure test suite\n";
		echo "Purpose:  Runs the Binary File Structure test suite.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options]\n";
		echo "Options:\n";
		echo "\t-c   Cleans up the test data files and exits.\n";
		echo "\t-p   Run optional performance tests.\n";
		echo "\t-?   This help documentation.\n";

		exit();
	}

	ini_set("error_reporting", E_ALL);

	$passed = 0;
	$failed = 0;
	$skipped = 0;

	function ProcessResult($test, $result, $bail_on_error = true)
	{
		global $passed, $failed;

		if (is_bool($result))  $str = ($result ? "[PASS]" : "[FAIL]") . " " . $test;
		else
		{
			$str = ($result["success"] ? "[PASS]" : "[FAIL - " . $result["error"] . " (" . $result["errorcode"] . ")]");
			$str .= " " . $test;
			if (!$result["success"])  $str .= "\n" . var_export($result, true) . "\n";
		}

		if (substr($str, 0, 2) == "[P")  $passed++;
		else
		{
			if ($bail_on_error)  echo "\n";
			$failed++;
		}
		echo $str . "\n";

		if ($bail_on_error && substr($str, 0, 2) == "[F")
		{
			echo "\n[FATAL] Unable to complete test suite.  Copy the failure data above when opening an issue.\n";
			exit();
		}
	}

	if (isset($args["opts"]["cleanonly"]))
	{
		@unlink($rootpath . "/../test_01.ifds");
		@unlink($rootpath . "/../test_02.ifds");
		@unlink($rootpath . "/../test_03.ifds");
		@unlink($rootpath . "/../test_04.ifds");
		@unlink($rootpath . "/../test_05.ifds");
		@unlink($rootpath . "/../test_06.ifds");
		@unlink($rootpath . "/../test_07.ifds");
		@unlink($rootpath . "/../test_08.ifds");
		@unlink($rootpath . "/../test_09.ifds");
		@unlink($rootpath . "/../test_10.ifds");

		exit();
	}

	// Paging file cache tests.
	$pfc = new PagingFileCache();
	$pfc2 = new PagingFileCache();

	// Load 'test_1.txt'.
	$data = file_get_contents($rootpath . "/test_1.txt");
	$result = $pfc->SetData($data);
	ProcessResult("[PagingFileCache] SetData() with contents of 'test_1.txt'", $result);

	// Read all lines.
	$data2 = "";
	$num = 1;
	do
	{
		$result = $pfc->ReadLine();
		ProcessResult("[PagingFileCache] Read line " . $num, $result);
		$data2 .= $result["data"];

		$num++;
	} while (!$result["eof"]);

	ProcessResult("[PagingFileCache] Contents match", ($data === $data2));

	// Open 'test_1.txt' as read only.
	$result = $pfc->Open($rootpath . "/test_1.txt", PagingFileCache::PAGING_FILE_MODE_READ);
	ProcessResult("[PagingFileCache] Open() 'test_1.txt' read only", $result);

	// Read all lines.
	$data2 = "";
	$num = 1;
	do
	{
		$result = $pfc->ReadLine();
		ProcessResult("[PagingFileCache] Read line " . $num, $result);
		$data2 .= $result["data"];

		$num++;
	} while (!$result["eof"]);

	ProcessResult("[PagingFileCache] Contents match", ($data === $data2));

	// Load 'test_2.txt' (CSV file).
	$data = file_get_contents($rootpath . "/test_2.txt");
	$result = $pfc->SetData($data);
	ProcessResult("[PagingFileCache] SetData() with contents of 'test_2.txt'", $result);

	$result = $pfc->ReadCSV(true);
	ProcessResult("[PagingFileCache] ReadCSV(true)", $result);
	ProcessResult("[PagingFileCache] Record contains 8 columns", (count($result["record"]) == 8));
	ProcessResult("[PagingFileCache] Column 6 is NULL", ($result["record"][5] === null));
	ProcessResult("[PagingFileCache] Column 7 is an empty string", ($result["record"][6] === ""));

	// Seek to the start.
	$result = $pfc->Seek(0);
	ProcessResult("[PagingFileCache] Seek start", $result);

	$result = $pfc->ReadCSV(false);
	ProcessResult("[PagingFileCache] ReadCSV(false)", $result);
	ProcessResult("[PagingFileCache] Record contains 8 columns", (count($result["record"]) == 8));
	ProcessResult("[PagingFileCache] Column 6 is an empty string", ($result["record"][5] === ""));
	ProcessResult("[PagingFileCache] Column 7 is an empty string", ($result["record"][6] === ""));

	$result = $pfc->ReadCSV();
	ProcessResult("[PagingFileCache] ReadCSV(false)", $result);
	ProcessResult("[PagingFileCache] Record contains 8 columns", (count($result["record"]) == 8));
	ProcessResult("[PagingFileCache] Column 5 is the string:  5,\",6", ($result["record"][4] === "5,\",6"));
	ProcessResult("[PagingFileCache] EOF reached", $result["eof"]);

	// Read 'paging_file_cache.php'.
	$filename = realpath($rootpath . "/../support/paging_file_cache.php");
	$data = file_get_contents($filename);
	ProcessResult("[PagingFileCache] Contents of 'paging_file_cache.php' are over the page size (4,096 bytes)", (strlen($data) > $pfc->GetPageSize()));
	$result = $pfc->SetData($data);
	ProcessResult("[PagingFileCache] SetData() with contents of 'paging_file_cache.php'", $result);

	// Read all lines.
	$data2 = "";
	$num = 1;
	do
	{
		$result = $pfc->ReadLine();
		if (!$result["success"])  ProcessResult("[PagingFileCache] Read line " . $num, $result);
		$data2 .= $result["data"];

		$num++;
	} while (!$result["eof"]);

	ProcessResult("[PagingFileCache] Contents match (line read)", ($data === $data2));

	// Seek to the start.
	$result = $pfc->Seek(0);
	ProcessResult("[PagingFileCache] Seek start", $result);

	// Read as data.
	$data2 = "";
	$num = 1;
	do
	{
		$result = $pfc->Read(7);
		if (!$result["success"])  ProcessResult("[PagingFileCache] Read operation " . $num, $result);
		$data2 .= $result["data"];

		$num++;
	} while (!$result["eof"]);

	ProcessResult("[PagingFileCache] Contents match (small read)", ($data === $data2));

	// Seek to the start.
	$result = $pfc->Seek(0);
	ProcessResult("[PagingFileCache] Seek start", $result);

	// Read as data.
	$data2 = "";
	$num = 1;
	do
	{
		$result = $pfc->Read(8193);
		if (!$result["success"])  ProcessResult("[PagingFileCache] Read operation " . $num, $result);
		$data2 .= $result["data"];

		$num++;
	} while (!$result["eof"]);

	ProcessResult("[PagingFileCache] Contents match (large read)", ($data === $data2));

	// Start streaming output.
	$result = $pfc->SetData("", PagingFileCache::PAGING_FILE_MODE_WRITE);
	ProcessResult("[PagingFileCache] SetData() for stream writing", $result);

	// Write data.
	$data2 = "";
	$x = 0;
	$y = strlen($data);
	$num = 1;
	do
	{
		$result = $pfc->Write(substr($data, $x, 7));
		if (!$result["success"])  ProcessResult("[PagingFileCache] Write operation " . $num, $result);
		$x += 7;

		$result = $pfc->GetData();
		if ($result === false)  ProcessResult("[PagingFileCache] Read operation " . $num, $result);
		$data2 .= $result;

		$num++;
	} while ($x < $y);

	$result = $pfc->Sync(true);
	ProcessResult("[PagingFileCache] Final sync", $result);

	$result = $pfc->GetData();
	if ($result === false)  ProcessResult("[PagingFileCache] Read operation " . $num, $result);
	$data2 .= $result;

	ProcessResult("[PagingFileCache] Contents match (small read)", ($data === $data2));

	// Start streaming output.
	$result = $pfc->SetData("", PagingFileCache::PAGING_FILE_MODE_WRITE);
	ProcessResult("[PagingFileCache] SetData() for stream writing", $result);

	// Write data.
	$data2 = "";
	$x = 0;
	$y = strlen($data);
	$num = 1;
	do
	{
		$result = $pfc->Write(substr($data, $x, 4097));
		if (!$result["success"])  ProcessResult("[PagingFileCache] Write operation " . $num, $result);
		$x += 4097;

		$result = $pfc->GetData();
		if ($result === false)  ProcessResult("[PagingFileCache] Read operation " . $num, $result);
		$data2 .= $result;

		$num++;
	} while ($x < $y);

	$result = $pfc->Sync(true);
	ProcessResult("[PagingFileCache] Final sync", $result);

	$result = $pfc->GetData();
	if ($result === false)  ProcessResult("[PagingFileCache] Read operation " . $num, $result);
	$data2 .= $result;

	ProcessResult("[PagingFileCache] Contents match (large read)", ($data === $data2));

	// Start streaming output.
	$result = $pfc2->SetData("", PagingFileCache::PAGING_FILE_MODE_WRITE);
	ProcessResult("[PagingFileCache] SetData() for stream writing (2)", $result);

	// Load 'test_2.txt' (CSV file).
	$data = file_get_contents($rootpath . "/test_2.txt");
	$result = $pfc->SetData($data);
	ProcessResult("[PagingFileCache] SetData() with contents of 'test_2.txt'", $result);

	$result = $pfc->ReadCSV(true);
	ProcessResult("[PagingFileCache] ReadCSV(true)", $result);

	$result = $pfc2->WriteCSV($result["record"]);
	ProcessResult("[PagingFileCache] WriteCSV() record (2)", $result);

	$result = $pfc->ReadCSV(true);
	ProcessResult("[PagingFileCache] ReadCSV(true)", $result);

	$result = $pfc2->WriteCSV($result["record"]);
	ProcessResult("[PagingFileCache] WriteCSV() record (2)", $result);

	$result = $pfc2->Sync(true);
	ProcessResult("[PagingFileCache] Final sync (2)", $result);

	$result = $pfc2->GetData();
	if ($result === false)  ProcessResult("[PagingFileCache] Read operation " . $num . " (2)", $result);

	$result = $pfc2->SetData($result);
	ProcessResult("[PagingFileCache] SetData() with contents of result (2)", $result);

	// Seek to the start.
	$result = $pfc->Seek(0);
	ProcessResult("[PagingFileCache] Seek start", $result);

	$result = $pfc->ReadCSV(true);
	ProcessResult("[PagingFileCache] ReadCSV(true)", $result);

	$result2 = $pfc2->ReadCSV(true);
	ProcessResult("[PagingFileCache] ReadCSV(true) (2)", $result2);

	ProcessResult("[PagingFileCache] Contents match", (json_encode($result, JSON_UNESCAPED_SLASHES) === json_encode($result2, JSON_UNESCAPED_SLASHES)));

	$result = $pfc->ReadCSV(true);
	ProcessResult("[PagingFileCache] ReadCSV(true)", $result);

	$result2 = $pfc2->ReadCSV(true);
	ProcessResult("[PagingFileCache] ReadCSV(true) (2)", $result2);

	ProcessResult("[PagingFileCache] Contents match", (json_encode($result, JSON_UNESCAPED_SLASHES) === json_encode($result2, JSON_UNESCAPED_SLASHES)));

	// Test 1MB loaded content.
	$data = "";
	for ($x = 0; $x < 256; $x++)  $data .= "\n" . str_repeat(chr(ord("0") + ($x % 10)), 4094) . "\r";

	// Reduce the number of cached pages for testing (Default is 2048).
	$pfc->SetMaxCachedPages(50);

	$result = $pfc->SetData($data);
	ProcessResult("[PagingFileCache] SetData() with dynamically generated 1MB data", $result);

	// Read all lines.
	$data2 = "";
	$num = 1;
	do
	{
		$result = $pfc->ReadLine();
		if (!$result["success"])  ProcessResult("[PagingFileCache] Read line " . $num, $result);
		$data2 .= $result["data"];

		$num++;
	} while (!$result["eof"]);

	ProcessResult("[PagingFileCache] Cached pages:  " . $pfc->GetNumCachedPages(), true);
	ProcessResult("[PagingFileCache] Contents match", ($data === $data2));


	// Incredibly Flexible Data Storage (IFDS) tests.
	$ifds = new IFDS();

	$pfc->SetData("");
	$result = $ifds->Create($pfc, 1, 0, 0);
	ProcessResult("[IFDS] Create with PagingFileCache", $result);

	// Bonus:  Track the equivalent in an array.
	$equiv = array(array("m" => "IFDS", "v" => "1.0.0", "d" => (int)(time() / 86400)), array(), array(), array());

	// Test raw data.
	$result = $ifds->CreateRawData(3, "testrawdata");
	ProcessResult("[IFDS] Create 'testrawdata' raw data", $result);

	$testrawdataobj = $result["obj"];

	$equiv[1]["testrawdata"] = $testrawdataobj->GetID();

	$data = "It works!";

	$result = $ifds->WriteData($testrawdataobj, $data);
	ProcessResult("[IFDS] Write data to 'testrawdata'", $result);

	$result = $ifds->Seek($testrawdataobj, 0);
	ProcessResult("[IFDS] Seek to start of 'testrawdata'", $result);

	$result = $ifds->ReadData($testrawdataobj);
	ProcessResult("[IFDS] Read object data from 'testrawdata'", $result);
	ProcessResult("[IFDS] Contents match", ($result["data"] === $data));

	$equiv[2][$testrawdataobj->GetID()] = $data;

	// Test NULL data.
	$result = $ifds->CreateRawData(IFDS::ENCODER_RAW, "testrawdata2");
	ProcessResult("[IFDS] Create 'testrawdata2' raw data", $result);

	$testrawdataobj2 = $result["obj"];

	$equiv[1]["testrawdata2"] = $testrawdataobj2->GetID();

	$result = $ifds->WriteData($testrawdataobj2, null, false, true);
	ProcessResult("[IFDS] Write NULL to 'testrawdata2'", $result);
	ProcessResult("[IFDS] Verify 'testrawdata2' object data is NULL", $testrawdataobj2->IsDataNull());

	$result = $ifds->SetObjectEncoder($testrawdataobj2, IFDS::ENCODER_RAW);
	ProcessResult("[IFDS] Revert 'testrawdata2' to inline data", $result);
	ProcessResult("[IFDS] Verify 'testrawdata2' object encoder/method was reverted", ($testrawdataobj2->GetEncoder() === IFDS::ENCODER_RAW && $testrawdataobj2->GetDataMethod() === IFDS::ENCODER_INTERNAL_DATA));

	$result = $ifds->WriteData($testrawdataobj2, null, false, true);
	ProcessResult("[IFDS] Write NULL to 'testrawdata2'", $result);
	ProcessResult("[IFDS] Verify 'testrawdata2' object data is NULL", $testrawdataobj2->IsDataNull());

	$equiv[2][$testrawdataobj2->GetID()] = null;

	// Test key-value map.
	$result = $ifds->CreateKeyValueMap("metadata");
	ProcessResult("[IFDS] Create 'metadata' key-value map", $result);

	$metadataobj = $result["obj"];

	$equiv[1]["metadata"] = $metadataobj->GetID();

	$metadata = array(
		"charset" => "utf-8",
		"language" => "en-us",
		"extra" => "yes"
	);

	$result = $ifds->SetKeyValueMap($metadataobj, $metadata);
	ProcessResult("[IFDS] Write metadata key-value map", $result);
	ProcessResult("[IFDS] Data size is 45 bytes", ($metadataobj->GetDataSize() === 45));

	$result = $ifds->GetKeyValueMap($metadataobj);
	ProcessResult("[IFDS] Read metadata key-value map", $result);
	ProcessResult("[IFDS] Contents match", (json_encode($result["map"]) === json_encode($metadata)));
	ProcessResult("[IFDS] Object modified", $metadataobj->IsModified());
	ProcessResult("[IFDS] Object ID is 3", ($metadataobj->GetID() === 3));

	$result = $ifds->WriteObject($metadataobj);
	ProcessResult("[IFDS] Write metadata object", $result);
	ProcessResult("[IFDS] Object not modified", !$metadataobj->IsModified());
	ProcessResult("[IFDS] Object GetID() compare", ($metadataobj->GetID() === $ifds->GetObjectID($metadataobj)));
	ProcessResult("[IFDS] Object GetBaseType() compare", ($metadataobj->GetBaseType() === $ifds->GetObjectBaseType($metadataobj)));
	ProcessResult("[IFDS] Object GetType() compare", ($metadataobj->GetType() === $ifds->GetObjectType($metadataobj)));
	ProcessResult("[IFDS] Object GetTypeStr() compare", ($metadataobj->GetTypeStr() === $ifds->GetObjectTypeStr($metadataobj)));
	ProcessResult("[IFDS] Object GetEncoder() compare", ($metadataobj->GetEncoder() === $ifds->GetObjectEncoder($metadataobj)));
	ProcessResult("[IFDS] Object GetDataMethod() compare", ($metadataobj->GetDataMethod() === $ifds->GetObjectDataMethod($metadataobj)));
	ProcessResult("[IFDS] Object GetDataPos() compare", ($metadataobj->GetDataPos() === $ifds->GetObjectDataPos($metadataobj)));
	ProcessResult("[IFDS] Object GetDataSize() compare", ($metadataobj->GetDataSize() === $ifds->GetObjectDataSize($metadataobj)));

	$equiv[2][$metadataobj->GetID()] = $metadata;

	// Test key-ID map.
	$result = $ifds->CreateKeyIDMap("contexts");
	ProcessResult("[IFDS] Create 'contexts' key-ID map", $result);

	$contextsobj = $result["obj"];

	$equiv[1]["contexts"] = $contextsobj->GetID();

	$contexts = array(
		"meta" => $metadataobj->GetID(),
		"fun" => 12
	);

	$result = $ifds->SetKeyValueMap($contextsobj, $contexts);
	ProcessResult("[IFDS] Write contexts key-ID map", $result);
	ProcessResult("[IFDS] Data size is 19 bytes", ($contextsobj->GetDataSize() === 19));

	$result = $ifds->GetKeyValueMap($contextsobj);
	ProcessResult("[IFDS] Read contexts key-ID map", $result);
	ProcessResult("[IFDS] Contents match", (json_encode($result["map"]) === json_encode($contexts)));

	$equiv[2][$contextsobj->GetID()] = $contexts;

	// Test fixed array.
	$result = $ifds->CreateFixedArray(5, "testarray");
	ProcessResult("[IFDS] Create 'testarray' fixed array", $result);

	$testarrayobj = $result["obj"];
	ProcessResult("[IFDS] Entry size of 'testarray' is 5", ($ifds->GetFixedArrayEntrySize($testarrayobj) === 5));

	$equiv[1]["testarray"] = $testarrayobj->GetID();
	$equiv[2][$testarrayobj->GetID()] = array();

	for ($x = 0; $x < 10; $x++)
	{
		$data = "\x01";
		$data .= pack("N", $x);

		$result = $ifds->AppendFixedArrayEntry($testarrayobj, $data);
		ProcessResult("[IFDS] Append 'testarray' item " . ($x + 1), $result);

		$equiv[2][$testarrayobj->GetID()][] = array(true, $x);
	}

	ProcessResult("[IFDS] Number of entries in 'testarray' is 10", ($ifds->GetNumFixedArrayEntries($testarrayobj) === 10));

	// Test linked list.
	$result = $ifds->CreateLinkedList("testlist");
	ProcessResult("[IFDS] Create 'testlist' linked list", $result);

	$testlistobj = $result["obj"];

	$equiv[1]["testlist"] = $testlistobj->GetID();

	for ($x = 0; $x < 10; $x++)
	{
		$result = $ifds->CreateLinkedListNode(IFDS::ENCODER_RAW);
		ProcessResult("[IFDS] Create linked list node " . ($x + 1), $result);

		$nodeobj = $result["obj"];

		$result = $ifds->AttachLinkedListNode($testlistobj, $nodeobj);
		ProcessResult("[IFDS] Attach linked list node " . ($x + 1), $result);

		$data = ($x + 1) . ":  It works!";

		$result = $ifds->WriteData($nodeobj, $data);
		ProcessResult("[IFDS] Write data to linked list node " . ($x + 1), $result);
	}

	$equiv[2][$testlistobj->GetID()] = array("first" => $testlistobj->data["info"]["first"], "last" => $testlistobj->data["info"]["last"]);

	$result = $ifds->CreateLinkedListIterator($testlistobj);
	ProcessResult("[IFDS] Create 'testlist' linked list iterator", $result);

	$iter = $result["iter"];

	$x = 0;
	while ($ifds->GetNextLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		$result = $ifds->Seek($iter->nodeobj, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Seek to start of object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$result = $ifds->ReadData($iter->nodeobj);
		if (!$result["success"])  ProcessResult("[IFDS] Read object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$data = ($x + 1) . ":  It works!";

		ProcessResult("[IFDS] Linked list object data matches (Linked list node ID " . $iter->nodeobj->GetID() . ")", ($result["data"] === $data));

		$equiv[2][$testlistobj->GetID()] = array("prev" => $iter->nodeobj->data["info"]["prev"], "next" => $iter->nodeobj->data["info"]["next"], "data" => $data);

		$x++;
	}

	ProcessResult("[IFDS] Number of nodes in 'testlist' is 10", ($x === 10));
	ProcessResult("[IFDS] Last 'testlist' iterator result", $iter->result);

	while ($ifds->GetPrevLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		$x--;

		$result = $ifds->Seek($iter->nodeobj, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Seek to start of object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$result = $ifds->ReadData($iter->nodeobj);
		if (!$result["success"])  ProcessResult("[IFDS] Read object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$data = ($x + 1) . ":  It works!";

		ProcessResult("[IFDS] Linked list object data matches (Linked list node ID " . $iter->nodeobj->GetID() . ")", ($result["data"] === $data));
	}

	ProcessResult("[IFDS] Last 'testlist' iterator result", $iter->result);

	foreach ($equiv[2] as $id => &$val)
	{
		$equiv[3][$id] = crc32(json_encode($val, JSON_UNESCAPED_SLASHES));
	}

	// Close the file.
	$result = $ifds->FlushAll();
	ProcessResult("[IFDS] FlushAll()", $result);

	$pfc->Sync(true);
	$filedata = $pfc->GetData();
	file_put_contents($rootpath . "/../test_01.ifds", $filedata);

	$ifds->Close();

	// Basic comparison to other types of storage.
	ProcessResult("[IFDS] Storage comparison | IFDS:  " . strlen($filedata) . " bytes | JSON (compact):  " . strlen(json_encode($equiv, JSON_UNESCAPED_SLASHES)) . " bytes | JSON (pretty print):  " . strlen(json_encode($equiv, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . " bytes | PHP serialize:  " . strlen(serialize($equiv)) . " bytes", true);

	// Verify the file and its contents.
	$pfc->SetData($filedata);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	$namemap = $ifds->GetNameMap();
	ProcessResult("[IFDS] Contains 6 name mappings", count($namemap) == 6);

	$result = $ifds->GetObjectByName("testrawdata");
	ProcessResult("[IFDS] Get 'testrawdata' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testrawdataobj = $result["obj"];

	$data = "It works!";

	$result = $ifds->ReadData($testrawdataobj);
	ProcessResult("[IFDS] Read object data from 'testrawdata'", $result);
	ProcessResult("[IFDS] Contents match", ($result["data"] === $data));

	$result = $ifds->GetObjectByName("testrawdata2");
	ProcessResult("[IFDS] Get 'testrawdata2' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testrawdataobj2 = $result["obj"];

	ProcessResult("[IFDS] Verify 'testrawdata2' object data is NULL", $testrawdataobj2->IsDataNull());

	$result = $ifds->GetObjectByName("metadata");
	ProcessResult("[IFDS] Get 'metadata' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$metadataobj = $result["obj"];

	foreach ($metadata as $key => $val)
	{
		$result = $ifds->GetNextKeyValueMapEntry($metadataobj);
		ProcessResult("[IFDS] Read next key-value entry", $result);
		ProcessResult("[IFDS] Compare '" . $key . "' => '" . $val . "'", ($key === $result["key"] && $val === $result["value"]));
	}

	ProcessResult("[IFDS] Reached end", $result["end"]);

	$result = $ifds->GetKeyValueMap($metadataobj);
	ProcessResult("[IFDS] Read metadata key-value map", $result);
	ProcessResult("[IFDS] Contents match", (json_encode($result["map"]) === json_encode($metadata)));

	$result = $ifds->GetObjectByName("contexts");
	ProcessResult("[IFDS] Get 'contexts' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$contextsobj = $result["obj"];

	$result = $ifds->GetKeyValueMap($contextsobj);
	ProcessResult("[IFDS] Read contexts key-ID map", $result);
	ProcessResult("[IFDS] Contents match", (json_encode($result["map"]) === json_encode($contexts)));

	$result = $ifds->GetObjectByName("testarray");
	ProcessResult("[IFDS] Get 'testarray' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testarrayobj = $result["obj"];

	ProcessResult("[IFDS] Entry size of 'testarray' is 5", ($ifds->GetFixedArrayEntrySize($testarrayobj) === 5));
	ProcessResult("[IFDS] Number of entries in 'testarray' is 10", ($ifds->GetNumFixedArrayEntries($testarrayobj) === 10));

	for ($x = 0; $x < 10; $x++)
	{
		$data = "\x01";
		$data .= pack("N", $x);

		$result = $ifds->GetNextFixedArrayEntry($testarrayobj);
		ProcessResult("[IFDS] Get 'testarray' item " . ($x + 1), $result);
		ProcessResult("[IFDS] Item " . ($x + 1) . " matches", ($result["data"] === $data));
		ProcessResult("[IFDS] Item " . ($x + 1) . " is valid", $result["valid"]);

	}

	for ($x = 0; $x < 10; $x++)
	{
		$data = "\x01";
		$data .= pack("N", $x);

		$result = $ifds->GetFixedArrayEntry($testarrayobj, $x);
		ProcessResult("[IFDS] Get 'testarray' item " . ($x + 1), $result);
		ProcessResult("[IFDS] Item " . ($x + 1) . " matches", ($result["data"] === $data));
		ProcessResult("[IFDS] Item " . ($x + 1) . " is valid", $result["valid"]);
	}

	$result = $ifds->GetObjectByName("testlist");
	ProcessResult("[IFDS] Get 'testlist' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testlistobj = $result["obj"];

	$result = $ifds->CreateLinkedListIterator($testlistobj);
	ProcessResult("[IFDS] Create 'testlist' linked list iterator", $result);

	$iter = $result["iter"];

	$x = 0;
	while ($ifds->GetNextLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		ProcessResult("[IFDS] Linked list object is valid (Linked list node ID " . $iter->nodeobj->GetID() . ")", $iter->nodeobj->IsValid());

		$result = $ifds->Seek($iter->nodeobj, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Seek to start of object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$result = $ifds->ReadData($iter->nodeobj);
		if (!$result["success"])  ProcessResult("[IFDS] Read object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$data = ($x + 1) . ":  It works!";

		ProcessResult("[IFDS] Linked list object data matches (Linked list node ID " . $iter->nodeobj->GetID() . ")", ($result["data"] === $data));

		$x++;
	}

	ProcessResult("[IFDS] Number of nodes in 'testlist' is 10", ($x === 10));
	ProcessResult("[IFDS] Last 'testlist' iterator result", $iter->result);

	while ($ifds->GetPrevLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		$x--;

		$result = $ifds->Seek($iter->nodeobj, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Seek to start of object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$result = $ifds->ReadData($iter->nodeobj);
		if (!$result["success"])  ProcessResult("[IFDS] Read object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$data = ($x + 1) . ":  It works!";

		ProcessResult("[IFDS] Linked list object data matches (Linked list node ID " . $iter->nodeobj->GetID() . ")", ($result["data"] === $data));
	}

	ProcessResult("[IFDS] Last 'testlist' iterator result", $iter->result);

	// Validate the file data through the streaming functions.
	$filedata2 = $filedata;
	$ifds->InitStreamReader();
	$tempdata = "";
	do
	{
		$result = $ifds->AppendStreamReader(substr($filedata2, 0, 20));
		$filedata2 = substr($filedata2, 20);
		if (!$result["success"])
		{
			if ($result["errorcode"] !== "insufficient_data")  ProcessResult("[IFDS] AppendStreamReader()", $result);
		}
		else
		{
			$pos = $ifds->GetStreamPos();
			$size = 1024;

			do
			{
				$result = $ifds->ReadNextFromStreamReader($tempdata, $size);
				if (!$result["success"])
				{
					if ($result["errorcode"] !== "insufficient_data")  ProcessResult("[IFDS] ReadNextFromStreamReader()", $result);
				}
				else
				{
					ProcessResult("[IFDS] Structure starting at " . $pos . " is valid", $result["valid"]);

					$size = $result["nextsize"];
				}
			} while ($result["success"]);

		}

	} while ($filedata2 !== "");

	ProcessResult("[IFDS] Stream position is " . strlen($filedata), strlen($filedata) === $ifds->GetStreamPos());

	$ifds->Close();

	// Open the file again.
	$pfc->SetData($filedata);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	// Delete 'testrawdata2'.
	$result = $ifds->GetObjectByName("testrawdata2");
	ProcessResult("[IFDS] Get 'testrawdata2' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testrawdataobj2 = $result["obj"];

	$id = $testrawdataobj2->GetID();
	$result = $ifds->DeleteObject($testrawdataobj2);
	ProcessResult("[IFDS] Delete 'testrawdata2' object " . $id, $result);

	$ifds->UnsetNameMapID("testrawdata2");
	ProcessResult("[IFDS] Unset 'testrawdata2' mapping", ($ifds->GetNameMapID("testrawdata2") === false));

	// Modify metadata.
	$result = $ifds->GetObjectByName("metadata");
	ProcessResult("[IFDS] Get 'metadata' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$metadataobj = $result["obj"];

	unset($metadata["extra"]);

	$result = $ifds->SetKeyValueMap($metadataobj, $metadata);
	ProcessResult("[IFDS] Write metadata key-value map", $result);
	ProcessResult("[IFDS] Data size is 33 bytes", ($metadataobj->GetDataSize() === 33));

	$result = $ifds->GetKeyValueMap($metadataobj);
	ProcessResult("[IFDS] Read metadata key-value map", $result);
	ProcessResult("[IFDS] Contents match", (json_encode($result["map"]) === json_encode($metadata)));
	ProcessResult("[IFDS] Object modified", $metadataobj->IsModified());

	// Modify fixed array.
	$result = $ifds->GetObjectByName("testarray");
	ProcessResult("[IFDS] Get 'testarray' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testarrayobj = $result["obj"];

	$result = $ifds->GetFixedArrayEntry($testarrayobj, 2);
	ProcessResult("[IFDS] Get 'testarray' item 3", $result);

	$data = $result["data"];
	$data[0] = "\x00";

	$result = $ifds->SetFixedArrayEntry($testarrayobj, 2, $data);
	ProcessResult("[IFDS] Modify 'testarray' item 3", $result);

	$result = $ifds->GetFixedArrayEntry($testarrayobj, 2);
	ProcessResult("[IFDS] Get 'testarray' item 3", $result);
	ProcessResult("[IFDS] Item 3 matches", ($result["data"] === $data));

	$data = "\x01";
	$data .= pack("N", 10);

	$result = $ifds->AppendFixedArrayEntry($testarrayobj, $data);
	ProcessResult("[IFDS] Append 'testarray' item 11", $result);

	ProcessResult("[IFDS] Number of entries in 'testarray' is 11", ($ifds->GetNumFixedArrayEntries($testarrayobj) === 11));

	// Create a second linked list.
	$result = $ifds->CreateLinkedList("testlist2");
	ProcessResult("[IFDS] Create 'testlist2' linked list", $result);
	ProcessResult("[IFDS] Object ID is " . $id, ($result["obj"]->GetID() === $id));

	$testlistobj2 = $result["obj"];

	// Move a few list nodes from the first list to the second.
	$result = $ifds->GetObjectByName("testlist");
	ProcessResult("[IFDS] Get 'testlist' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testlistobj = $result["obj"];

	$result = $ifds->CreateLinkedListIterator($testlistobj);
	ProcessResult("[IFDS] Create 'testlist' linked list iterator", $result);

	$iter = $result["iter"];

	$ifds->GetNextLinkedListNode($iter);
	$nodeobj = $iter->nodeobj;
	$ifds->GetNextLinkedListNode($iter);
	$nodeobj2 = $iter->nodeobj;
	$iter->nodeobj = false;
	$ifds->GetPrevLinkedListNode($iter);
	$nodeobj3 = $iter->nodeobj;
	$ifds->GetPrevLinkedListNode($iter);
	$nodeobj4 = $iter->nodeobj;
	$ifds->GetPrevLinkedListNode($iter);
	$nodeobj5 = $iter->nodeobj;

	$result = $ifds->DetachLinkedListNode($testlistobj, $nodeobj2);
	ProcessResult("[IFDS] Detach linked list node " . $nodeobj2->GetID(), $result);
	$result = $ifds->AttachLinkedListNode($testlistobj2, $nodeobj2);
	ProcessResult("[IFDS] Attach linked list node " . $nodeobj2->GetID(), $result);

	$result = $ifds->DetachLinkedListNode($testlistobj, $nodeobj);
	ProcessResult("[IFDS] Detach linked list node " . $nodeobj->GetID(), $result);
	$result = $ifds->AttachLinkedListNode($testlistobj2, $nodeobj);
	ProcessResult("[IFDS] Attach linked list node " . $nodeobj->GetID(), $result);

	$result = $ifds->DetachLinkedListNode($testlistobj, $nodeobj4);
	ProcessResult("[IFDS] Detach linked list node " . $nodeobj4->GetID(), $result);
	$result = $ifds->AttachLinkedListNode($testlistobj2, $nodeobj4);
	ProcessResult("[IFDS] Attach linked list node " . $nodeobj4->GetID(), $result);

	$result = $ifds->DetachLinkedListNode($testlistobj, $nodeobj3);
	ProcessResult("[IFDS] Detach linked list node " . $nodeobj3->GetID(), $result);
	$result = $ifds->AttachLinkedListNode($testlistobj2, $nodeobj3);
	ProcessResult("[IFDS] Attach linked list node " . $nodeobj3->GetID(), $result);

	$id = $nodeobj5->GetID();
	$result = $ifds->DeleteLinkedListNode($testlistobj, $nodeobj5);
	ProcessResult("[IFDS] Delete linked list node " . $id, $result);

	$result = $ifds->CreateLinkedListIterator($testlistobj);
	ProcessResult("[IFDS] Create 'testlist' linked list iterator", $result);

	$iter = $result["iter"];

	$x = 0;
	while ($ifds->GetNextLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		$x++;
	}

	ProcessResult("[IFDS] Number of nodes in 'testlist' is 5", ($x === 5));
	ProcessResult("[IFDS] Last 'testlist' iterator result", $iter->result);

	$result = $ifds->CreateLinkedListIterator($testlistobj2);
	ProcessResult("[IFDS] Create 'testlist2' linked list iterator", $result);

	$iter = $result["iter"];

	$x = 0;
	while ($ifds->GetNextLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		$x++;
	}

	ProcessResult("[IFDS] Number of nodes in 'testlist2' is 4", ($x === 4));
	ProcessResult("[IFDS] Last 'testlist2' iterator result", $iter->result);

	// Close the file.
	$result = $ifds->FlushAll();
	ProcessResult("[IFDS] FlushAll()", $result);

	$estfree = $ifds->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata2 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_02.ifds", $filedata2);

	$ifds->Close();

	// Dump size info.
	ProcessResult("[IFDS] " . strlen($filedata2) . " bytes | " . $estfree . " bytes free", true);

	// Open the file again.
	$pfc->SetData($filedata2);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	// Verify 'testrawdata2' was deleted.
	$result = $ifds->GetObjectByName("testrawdata2");
	ProcessResult("[IFDS] Verify 'testrawdata2' object deletion", (!$result["success"] && $result["errorcode"] === "name_not_found"));

	// Verify metadata.
	$result = $ifds->GetObjectByName("metadata");
	ProcessResult("[IFDS] Get 'metadata' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$metadataobj = $result["obj"];

	$result = $ifds->GetKeyValueMap($metadataobj);
	ProcessResult("[IFDS] Read metadata key-value map", $result);
	ProcessResult("[IFDS] Contents match", (json_encode($result["map"]) === json_encode($metadata)));
	ProcessResult("[IFDS] Object not modified", !$metadataobj->IsModified());

	// Verify fixed array.
	$result = $ifds->GetObjectByName("testarray");
	ProcessResult("[IFDS] Get 'testarray' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());
	ProcessResult("[IFDS] Number of entries in 'testarray' is 11", ($ifds->GetNumFixedArrayEntries($testarrayobj) === 11));

	$testarrayobj = $result["obj"];

	$data = "\x00";
	$data .= pack("N", 2);

	$result = $ifds->GetFixedArrayEntry($testarrayobj, 2);
	ProcessResult("[IFDS] Get 'testarray' item 3", $result);
	ProcessResult("[IFDS] Item 3 matches", ($result["data"] === $data));

	$data = "\x01";
	$data .= pack("N", 10);

	$result = $ifds->GetFixedArrayEntry($testarrayobj, 10);
	ProcessResult("[IFDS] Get 'testarray' item 11", $result);
	ProcessResult("[IFDS] Item 11 matches", ($result["data"] === $data));

	// Verify test list.
	$result = $ifds->GetObjectByName("testlist");
	ProcessResult("[IFDS] Get 'testlist' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testlistobj = $result["obj"];

	ProcessResult("[IFDS] Number of nodes in 'testlist' is 5", ($ifds->GetNumLinkedListNodes($testlistobj) === 5));

	// Verify 'testlist2'.
	$result = $ifds->GetObjectByName("testlist2");
	ProcessResult("[IFDS] Get 'testlist2' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testlistobj2 = $result["obj"];

	ProcessResult("[IFDS] Number of nodes in 'testlist2' is 4", ($ifds->GetNumLinkedListNodes($testlistobj2) === 4));

	// Delete 'testlist2'.
	$id = $testlistobj2->GetID();
	$result = $ifds->DeleteObject($testlistobj2);
	ProcessResult("[IFDS] Fail to delete 'testlist2' object " . $id, (!$result["success"] && $result["errorcode"] === "object_not_detached"));

	$result = $ifds->CreateLinkedListIterator($testlistobj2);
	ProcessResult("[IFDS] Create 'testlist2' linked list iterator", $result);

	$iter = $result["iter"];

	$result = $ifds->GetNextLinkedListNode($iter);
	ProcessResult("[IFDS] Get next node", $result);
	$nodeobj = $iter->nodeobj;

	$id2 = $nodeobj->GetID();
	$result = $ifds->DeleteObject($nodeobj);
	ProcessResult("[IFDS] Fail to delete linked list node object " . $id2, (!$result["success"] && $result["errorcode"] === "object_not_detached"));

	$result = $ifds->DeleteLinkedList($testlistobj2);
	ProcessResult("[IFDS] Delete 'testlist2' linked list object " . $id, $result);

	$ifds->UnsetNameMapID("testlist2");
	ProcessResult("[IFDS] Unset 'testlist2' mapping", ($ifds->GetNameMapID("testlist2") === false));

	// Close the file.
	$result = $ifds->FlushAll();
	ProcessResult("[IFDS] FlushAll()", $result);

	$estfree = $ifds->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata3 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_03.ifds", $filedata3);

	$ifds->Close();

	// Dump size info.
	ProcessResult("[IFDS] " . strlen($filedata3) . " bytes | " . $estfree . " bytes free", true);

	// Open the file again.
	$pfc->SetData($filedata3);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	// Verify 'testlist2' was deleted.
	$result = $ifds->GetObjectByName("testlist2");
	ProcessResult("[IFDS] Verify 'testlist2' object deletion", (!$result["success"] && $result["errorcode"] === "name_not_found"));

	// Test DATA chunks.
	$result = $ifds->CreateRawData(IFDS::ENCODER_RAW, "testdatachunks");
	ProcessResult("[IFDS] Create 'testdatachunks' raw data", $result);

	$testdatachunksobj = $result["obj"];

	$result = $ifds->WriteObject($testdatachunksobj);
	ProcessResult("[IFDS] Write object", $result);

	$basedata = "";
	for ($x = 0; $x < 256; $x++)  $basedata .= chr($x);
	$data = str_repeat($basedata, 16);

	ProcessResult("[IFDS] Verify internal DATA encoding", ($testdatachunksobj->GetDataMethod() === IFDS::ENCODER_INTERNAL_DATA));

	$result = $ifds->WriteData($testdatachunksobj, $data);
	ProcessResult("[IFDS] Write 4096 bytes of binary data", $result);
	ProcessResult("[IFDS] Verify DATA chunks encoding", ($testdatachunksobj->GetDataMethod() === IFDS::ENCODER_DATA_CHUNKS));

	$result = $ifds->WriteObject($testdatachunksobj);
	ProcessResult("[IFDS] Write object", $result);

	$basedata2 = strrev($basedata);
	$data2 = str_repeat($basedata2, 256);

	$result = $ifds->CreateRawData(IFDS::ENCODER_RAW, "testdatachunks2");
	ProcessResult("[IFDS] Create 'testdatachunks2' raw data", $result);

	$testdatachunksobj2 = $result["obj"];

	ProcessResult("[IFDS] Verify internal DATA encoding", ($testdatachunksobj2->GetDataMethod() === IFDS::ENCODER_INTERNAL_DATA));

	$result = $ifds->WriteData($testdatachunksobj2, $data2);
	ProcessResult("[IFDS] Write 65536 bytes of binary data", $result);
	ProcessResult("[IFDS] Verify DATA chunks encoding", ($testdatachunksobj2->GetDataMethod() === IFDS::ENCODER_DATA_CHUNKS));

	$result = $ifds->WriteObject($testdatachunksobj2);
	ProcessResult("[IFDS] Write object", $result);

	// Test interleaved, multi-channel data.
	$result = $ifds->CreateRawData(IFDS::ENCODER_RAW, "testinterleaved");
	ProcessResult("[IFDS] Create 'testinterleaved' raw data", $result);

	$testinterleavedobj = $result["obj"];

	$result = $ifds->CreateRawData(IFDS::ENCODER_RAW, "testinterleaved2");
	ProcessResult("[IFDS] Create 'testinterleaved2' raw data", $result);

	$testinterleavedobj2 = $result["obj"];

	for ($x = 101; $x < 106; $x++)
	{
		$data3 = $x . ":  It works!";

		$result = $ifds->WriteData($testinterleavedobj, $data3, $x, true);
		ProcessResult("[IFDS] Write interleaved data for 'testinterleaved' on channel " . $x, $result);

		$result = $ifds->WriteData($testinterleavedobj2, $data3, $x, true);
		ProcessResult("[IFDS] Write interleaved data for 'testinterleaved2' on channel " . $x, $result);
	}

	ProcessResult("[IFDS] Can write data to 'testinterleaved'", $ifds->CanWriteData($testinterleavedobj));
	$result = $ifds->WriteData($testinterleavedobj, $data, 0, true);
	ProcessResult("[IFDS] Write final interleaved data for 'testinterleaved'", $result);
	ProcessResult("[IFDS] Cannot write data to 'testinterleaved'", !$ifds->CanWriteData($testinterleavedobj));

	ProcessResult("[IFDS] Can write data to 'testinterleaved2'", $ifds->CanWriteData($testinterleavedobj2));
	$result = $ifds->WriteData($testinterleavedobj2, $data2, 0, true);
	ProcessResult("[IFDS] Write final interleaved data for 'testinterleaved2'", $result);
	ProcessResult("[IFDS] Cannot write data to 'testinterleaved2'", !$ifds->CanWriteData($testinterleavedobj2));

	$result = $ifds->WriteObject($testinterleavedobj);
	ProcessResult("[IFDS] Write 'testinterleaved' object", $result);

	$result = $ifds->WriteObject($testinterleavedobj2);
	ProcessResult("[IFDS] Write 'testinterleaved2' object", $result);

	// Close the file.
	$result = $ifds->FlushAll();
	ProcessResult("[IFDS] FlushAll()", $result);

	$estfree = $ifds->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata4 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_04.ifds", $filedata4);

	$ifds->Close();

	// Dump size info.
	ProcessResult("[IFDS] " . strlen($filedata4) . " bytes | " . $estfree . " bytes free", true);

	// Open the file again.
	$pfc->SetData($filedata4);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	// Verify 'testdatachunks'.
	$result = $ifds->GetObjectByName("testdatachunks");
	ProcessResult("[IFDS] Get 'testdatachunks' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testdatachunksobj = $result["obj"];

	$result = $ifds->ReadData($testdatachunksobj);
	ProcessResult("[IFDS] Read object data from 'testdatachunks'", $result);
	ProcessResult("[IFDS] Contents match", ($result["data"] === $data));

	// Verify 'testdatachunks2'.
	$result = $ifds->GetObjectByName("testdatachunks2");
	ProcessResult("[IFDS] Get 'testdatachunks2' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testdatachunksobj2 = $result["obj"];

	$result = $ifds->ReadData($testdatachunksobj2);
	ProcessResult("[IFDS] Read object data from 'testdatachunks2'", $result);
	ProcessResult("[IFDS] Contents match", ($result["data"] === $data2));

	// Verify 'testinterleaved' and 'testinterleaved2'.
	$result = $ifds->GetObjectByName("testinterleaved");
	ProcessResult("[IFDS] Get 'testinterleaved' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());
	ProcessResult("[IFDS] Cannot write data to 'testinterleaved'", !$ifds->CanWriteData($testinterleavedobj));

	$testinterleavedobj = $result["obj"];

	$result = $ifds->GetObjectByName("testinterleaved2");
	ProcessResult("[IFDS] Get 'testinterleaved2' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());
	ProcessResult("[IFDS] Cannot write data to 'testinterleaved2'", !$ifds->CanWriteData($testinterleavedobj2));

	$testinterleavedobj2 = $result["obj"];

	for ($x = 101; $x < 106; $x++)
	{
		$data3 = $x . ":  It works!";

		$result = $ifds->ReadData($testinterleavedobj);
		ProcessResult("[IFDS] Read interleaved data for 'testinterleaved'", $result);
		ProcessResult("[IFDS] Compare interleaved data for 'testinterleaved' on channel " . $result["channel"], ($result["data"] === $data3));

		$result = $ifds->ReadData($testinterleavedobj2);
		ProcessResult("[IFDS] Read interleaved data for 'testinterleaved2'", $result);
		ProcessResult("[IFDS] Compare interleaved data for 'testinterleaved2' on channel " . $result["channel"], ($result["data"] === $data3));
	}

	$data3 = "";
	do
	{
		$result = $ifds->ReadData($testinterleavedobj);
		ProcessResult("[IFDS] Read final interleaved data for 'testinterleaved'", $result);

		$data3 .= $result["data"];
	} while (!$result["end"]);

	ProcessResult("[IFDS] Compare final interleaved data for 'testinterleaved'", ($data3 === $data));
	ProcessResult("[IFDS] Cannot write data to 'testinterleaved'", !$ifds->CanWriteData($testinterleavedobj));

	$data3 = "";
	do
	{
		$result = $ifds->ReadData($testinterleavedobj2);
		ProcessResult("[IFDS] Read final interleaved data for 'testinterleaved2'", $result);

		$data3 .= $result["data"];
	} while (!$result["end"]);

	ProcessResult("[IFDS] Compare final interleaved data for 'testinterleaved2'", ($data3 === $data2));
	ProcessResult("[IFDS] Cannot write data to 'testinterleaved2'", !$ifds->CanWriteData($testinterleavedobj2));

	// Truncate 'testdatachunks2'.
	ProcessResult("[IFDS] Verify 'testdatachunks2' DATA chunks encoding", ($testdatachunksobj2->GetDataMethod() === IFDS::ENCODER_DATA_CHUNKS));
	$result = $ifds->Truncate($testdatachunksobj2, 2048);
	ProcessResult("[IFDS] Truncate 'testdatachunks2' to 2048 bytes", $result);
	ProcessResult("[IFDS] Verify 'testdatachunks2' internal DATA encoding", ($testdatachunksobj2->GetDataMethod() === IFDS::ENCODER_INTERNAL_DATA));

	// Close the file.
	$result = $ifds->FlushAll();
	ProcessResult("[IFDS] FlushAll()", $result);

	$estfree = $ifds->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata5 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_05.ifds", $filedata5);

	$ifds->Close();

	// Dump size info.
	ProcessResult("[IFDS] " . strlen($filedata5) . " bytes | " . $estfree . " bytes free", true);

	// Open the file again.
	$pfc->SetData($filedata5);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	// Verify 'testdatachunks2'.
	$result = $ifds->GetObjectByName("testdatachunks2");
	ProcessResult("[IFDS] Get 'testdatachunks2' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testdatachunksobj2 = $result["obj"];

	$result = $ifds->ReadData($testdatachunksobj2);
	ProcessResult("[IFDS] Read object data from 'testdatachunks2'", $result);
	ProcessResult("[IFDS] Contents match", ($result["data"] === substr($data2, 0, 2048)));

	@unlink($rootpath . "/../test_06.ifds");
	$result = IFDS::Optimize($ifds, $rootpath . "/../test_06.ifds");
	ProcessResult("[IFDS] Optimize()", $result);

	$filedata6 = file_get_contents($rootpath . "/../test_06.ifds");

	$ifds->Close();

	// Dump size info.
	ProcessResult("[IFDS] " . strlen($filedata6) . " bytes | 0 bytes free", true);

	// Open the file again.
	$pfc->SetData($filedata6);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	// Verify 'testdatachunks' again.
	$result = $ifds->GetObjectByName("testdatachunks");
	ProcessResult("[IFDS] Get 'testdatachunks' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testdatachunksobj = $result["obj"];

	$result = $ifds->ReadData($testdatachunksobj);
	ProcessResult("[IFDS] Read object data from 'testdatachunks'", $result);
	ProcessResult("[IFDS] Contents match", ($result["data"] === $data));

	// Verify 'testdatachunks2' again.
	$result = $ifds->GetObjectByName("testdatachunks2");
	ProcessResult("[IFDS] Get 'testdatachunks2' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testdatachunksobj2 = $result["obj"];

	$result = $ifds->ReadData($testdatachunksobj2);
	ProcessResult("[IFDS] Read object data from 'testdatachunks2'", $result);
	ProcessResult("[IFDS] Contents match", ($result["data"] === substr($data2, 0, 2048)));

	$ifds->Close();

	// Create a new file to test streaming content.
	$result = $ifds->Create(false, 1, 0, 0);
	ProcessResult("[IFDS] Create with internal string", $result);

	$filedata7 = $ifds->GetStreamData();

	// Test streaming linked list.
	$result = $ifds->CreateLinkedList("testlist", true);
	ProcessResult("[IFDS] Create 'testlist' streaming linked list", $result);

	$testlistobj = $result["obj"];

	for ($x = 0; $x < 10; $x++)
	{
		$result = $ifds->CreateLinkedListNode(IFDS::ENCODER_RAW);
		ProcessResult("[IFDS] Create linked list node " . ($x + 1), $result);

		$nodeobj = $result["obj"];

		$result = $ifds->AttachLinkedListNode($testlistobj, $nodeobj);
		ProcessResult("[IFDS] Attach linked list node " . ($x + 1), $result);

		$data = ($x + 1) . ":  It works!";

		$result = $ifds->WriteData($nodeobj, $data);
		ProcessResult("[IFDS] Write data to linked list node " . ($x + 1), $result);

		$result = $ifds->WriteObject($nodeobj);
		ProcessResult("[IFDS] Write linked list node " . ($x + 1), $result);

		$filedata7 .= $ifds->GetStreamData();
	}

	$result = $ifds->FlushAll();
	ProcessResult("[IFDS] FlushAll()", $result);

	$filedata7 .= $ifds->GetStreamData();

	$estfree = $ifds->GetEstimatedFreeSpace();

	$ifds->Close();

	file_put_contents($rootpath . "/../test_07.ifds", $filedata7);

	// Dump size info.
	ProcessResult("[IFDS] " . strlen($filedata7) . " bytes | " . $estfree . " bytes free", true);

	// Open the streamed file.
	$pfc->SetData($filedata7);
	$result = $ifds->Open($pfc);
	ProcessResult("[IFDS] Open with PagingFileCache", $result);
	ProcessResult("[IFDS] File is valid so far", $result["valid"]);

	$result = $ifds->GetObjectByName("testlist");
	ProcessResult("[IFDS] Get 'testlist' object", $result);
	ProcessResult("[IFDS] Object is valid", $result["obj"]->IsValid());

	$testlistobj = $result["obj"];

	$result = $ifds->CreateLinkedListIterator($testlistobj);
	ProcessResult("[IFDS] Create 'testlist' linked list iterator", $result);

	$iter = $result["iter"];

	$x = 0;
	while ($ifds->GetNextLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		ProcessResult("[IFDS] Linked list object is valid (Linked list node ID " . $iter->nodeobj->GetID() . ")", $iter->nodeobj->IsValid());

		$result = $ifds->Seek($iter->nodeobj, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Seek to start of object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$result = $ifds->ReadData($iter->nodeobj);
		if (!$result["success"])  ProcessResult("[IFDS] Read object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$data = ($x + 1) . ":  It works!";

		ProcessResult("[IFDS] Linked list object data matches (Linked list node ID " . $iter->nodeobj->GetID() . ")", ($result["data"] === $data));

		$x++;
	}

	ProcessResult("[IFDS] Number of nodes in 'testlist' is 10", ($x === 10));
	ProcessResult("[IFDS] Last 'testlist' iterator result", $iter->result);

	while ($ifds->GetPrevLinkedListNode($iter) && $iter->nodeobj !== false)
	{
		$x--;

		$result = $ifds->Seek($iter->nodeobj, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Seek to start of object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$result = $ifds->ReadData($iter->nodeobj);
		if (!$result["success"])  ProcessResult("[IFDS] Read object data (Linked list node ID " . $iter->nodeobj->GetID() . ")", $result);

		$data = ($x + 1) . ":  It works!";

		ProcessResult("[IFDS] Linked list object data matches (Linked list node ID " . $iter->nodeobj->GetID() . ")", ($result["data"] === $data));
	}

	ProcessResult("[IFDS] Last 'testlist' iterator result", $iter->result);

	// Close the file.
	$result = $ifds->FlushAll();
	ProcessResult("[IFDS] FlushAll()", $result);

	$estfree = $ifds->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata8 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_08.ifds", $filedata8);

	$ifds->Close();

	// Dump size info.
	ProcessResult("[IFDS] " . strlen($filedata8) . " bytes | " . $estfree . " bytes free", true);

	// Performance tests.
	if (isset($args["opts"]["perftests"]))
	{
		@ini_set("memory_limit", "-1");

		ProcessResult("[IFDS] Beginning performance tests (approximately 3 seconds per test)", true);

		$pfc->SetData("");
		$result = $ifds->Create($pfc, 1, 0, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Create with PagingFileCache", $result);

		$ts = microtime(true);
		$y = 0;
		do
		{
			for ($x = 0; $x < 100; $x++)
			{
				$result = $ifds->CreateKeyValueMap();
				if (!$result["success"])  ProcessResult("[IFDS] Create key-value map", $result);

				$obj = $result["obj"];

				$result = $ifds->SetKeyValueMap($obj, $metadata);
				if (!$result["success"])  ProcessResult("[IFDS] Write metadata to key-value map", $result);

				$y++;
			}

			$ts2 = microtime(true);
		} while ($ts2 - $ts < 3.0);

		ProcessResult("[IFDS] Created and encoded " . number_format($y) . " key-value objects (" . number_format((int)($y / ($ts2 - $ts))) . "/sec)", true);

		$ts = microtime(true);
		$y2 = 0;
		do
		{
			for ($x = 0; $x < 100; $x++)
			{
				$result = $ifds->GetObjectByID(($y2 % $y) + 1);
				if (!$result["success"])  ProcessResult("[IFDS] Retrieve key-value map object", $result);

				$obj = $result["obj"];

				$result = $ifds->GetKeyValueMap($obj);
				if (!$result["success"])  ProcessResult("[IFDS] Retrieve key-value map data", $result);

				$y2++;
			}

			$ts2 = microtime(true);
		} while ($ts2 - $ts < 3.0);

		ProcessResult("[IFDS] Sequentially read and decoded " . number_format($y2) . " key-value objects (" . number_format((int)($y2 / ($ts2 - $ts))) . "/sec)", true);

		$ts = microtime(true);
		$y2 = 0;
		do
		{
			for ($x = 0; $x < 100; $x++)
			{
				$result = $ifds->GetObjectByID(mt_rand(0, $y - 1) + 1);
				if (!$result["success"])  ProcessResult("[IFDS] Retrieve key-value map object", $result);

				$obj = $result["obj"];

				$result = $ifds->GetKeyValueMap($obj);
				if (!$result["success"])  ProcessResult("[IFDS] Retrieve key-value map data", $result);

				$y2++;
			}

			$ts2 = microtime(true);
		} while ($ts2 - $ts < 3.0);

		ProcessResult("[IFDS] Randomly read and decoded " . number_format($y2) . " key-value objects (" . number_format((int)($y2 / ($ts2 - $ts))) . "/sec)", true);

		$ifds->Close();

		$pfc->SetData("");
		$result = $ifds->Create($pfc, 1, 0, 0);
		if (!$result["success"])  ProcessResult("[IFDS] Create with PagingFileCache", $result);

		$result = $ifds->CreateRawData(IFDS::ENCODER_RAW);
		ProcessResult("[IFDS] Create raw data", $result);
		ProcessResult("[IFDS] Confirm 65536 bytes", (strlen($data2) === 65536));

		$rawobj = $result["obj"];

		$ts = microtime(true);
		$y = 0;
		do
		{
			for ($x = 0; $x < 10; $x++)
			{
				$result = $ifds->WriteData($rawobj, $data2);
				if (!$result["success"])  ProcessResult("[IFDS] Write 65536 bytes", $result);

				$y++;
			}

			$ts2 = microtime(true);
		} while ($ts2 - $ts < 3.0);

		ProcessResult("[IFDS] Sequentially wrote " . number_format($y * 65536) . " bytes (" . number_format((int)(($y * 65536) / ($ts2 - $ts))) . " bytes/sec)", true);

		$size = ($y - 1) * 65536;

		$ts = microtime(true);
		$y = 0;
		do
		{
			for ($x = 0; $x < 10; $x++)
			{
				if ($rawobj->GetDataPos() > $size)
				{
					$result = $ifds->Seek($rawobj, 0);
					if (!$result["success"])  ProcessResult("[IFDS] Seek to start", $result);
				}

				$result = $ifds->ReadData($rawobj, 65536);
				if (!$result["success"])  ProcessResult("[IFDS] Read 65536 bytes", $result);

				$y++;
			}

			$ts2 = microtime(true);
		} while ($ts2 - $ts < 3.0);

		ProcessResult("[IFDS] Sequentially read " . number_format($y * 65536) . " bytes (" . number_format((int)(($y * 65536) / ($ts2 - $ts))) . " bytes/sec)", true);

		$ts = microtime(true);
		$y = 0;
		do
		{
			for ($x = 0; $x < 10; $x++)
			{
				$pos = mt_rand(0, $size);

				$result = $ifds->Seek($rawobj, $pos);
				if (!$result["success"])  ProcessResult("[IFDS] Seek to " . $pos, $result);

				$result = $ifds->ReadData($rawobj, 65536);
				if (!$result["success"])  ProcessResult("[IFDS] Read 65536 bytes", $result);

				$y++;
			}

			$ts2 = microtime(true);
		} while ($ts2 - $ts < 3.0);

		ProcessResult("[IFDS] Randomly read " . number_format($y * 65536) . " bytes (" . number_format((int)(($y * 65536) / ($ts2 - $ts))) . " bytes/sec)", true);

		$ts = microtime(true);
		$y = 0;
		do
		{
			for ($x = 0; $x < 10; $x++)
			{
				$pos = mt_rand(0, $size);

				$result = $ifds->Seek($rawobj, $pos);
				if (!$result["success"])  ProcessResult("[IFDS] Seek to " . $pos, $result);

				$result = $ifds->WriteData($rawobj, $data2);
				if (!$result["success"])  ProcessResult("[IFDS] Write 65536 bytes", $result);

				$y++;
			}

			$ts2 = microtime(true);
		} while ($ts2 - $ts < 3.0);

		ProcessResult("[IFDS] Randomly wrote " . number_format($y * 65536) . " bytes (" . number_format((int)(($y * 65536) / ($ts2 - $ts))) . " bytes/sec)", true);
	}


	// IFDS text file format tests.
	require_once $rootpath . "/../support_extra/ifds_text.php";

	$ifdstext = new IFDS_Text();
	$ifdstext->SetCompressionLevel(9);

	$pfc->SetData("");
	$result = $ifdstext->Create($pfc, array("trail" => false, "mimetype" => "application/x-php"));
	ProcessResult("[IFDS_Text] Create with PagingFileCache", $result);

	$data = file_get_contents($rootpath . "/../support/ifds.php");
	$numlines = count(explode("\n", $data));

	$result = $ifdstext->WriteLines($data, 0, 0);
	ProcessResult("[IFDS_Text] Write " . strlen($data) . " bytes", $result);

	// Close the file.
	$result = $ifdstext->Save();
	ProcessResult("[IFDS_Text] Save()", $result);

	$estfree = $ifdstext->GetIFDS()->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata9 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_09.ifds", $filedata9);

	$ifdstext->Close();

	// Dump size info.
	ProcessResult("[IFDS_Text] " . strlen($filedata9) . " bytes | " . $estfree . " bytes free", true);

	// Open the IFDS text file.
	$pfc->SetData($filedata9);
	$result = $ifdstext->Open($pfc);
	ProcessResult("[IFDS_Text] Open with PagingFileCache", $result);

	ProcessResult("[IFDS_Text] Contains " . $numlines . " lines", ($numlines === $ifdstext->GetNumLines()));

	$result = $ifdstext->ReadLines(0, $numlines);
	ProcessResult("[IFDS_Text] Read lines", $result);
	ProcessResult("[IFDS_Text] Contains " . $numlines . " lines", ($numlines === count($result["lines"])));
	ProcessResult("[IFDS_Text] At end of file", $result["eof"]);
	ProcessResult("[IFDS_Text] Compare data", ($data === implode("\n", $result["lines"])));

	$ifdstext->Close();

	// Create a new file with compression enabled.
	$pfc->SetData("");
	$result = $ifdstext->Create($pfc, array("compress" => true, "trail" => false, "mimetype" => "application/x-php"));
	ProcessResult("[IFDS_Text] Create with PagingFileCache", $result);

	$result = $ifdstext->WriteLines($data, 0, 0);
	ProcessResult("[IFDS_Text] Write " . strlen($data) . " bytes", $result);

	// Close the file.
	$result = $ifdstext->Save();
	ProcessResult("[IFDS_Text] Save()", $result);

	$estfree = $ifdstext->GetIFDS()->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata10 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_10.ifds", $filedata10);

	$ifdstext->Close();

	// Dump size info.
	ProcessResult("[IFDS_Text] " . strlen($filedata10) . " bytes (" . number_format(strlen($filedata10) / strlen($data) * 100, 2) . "% of original file size) | " . $estfree . " bytes free", true);

	// Open the IFDS text file.
	$pfc->SetData($filedata10);
	$result = $ifdstext->Open($pfc);
	ProcessResult("[IFDS_Text] Open with PagingFileCache", $result);

	ProcessResult("[IFDS_Text] Contains " . $numlines . " lines", ($numlines === $ifdstext->GetNumLines()));

	$result = $ifdstext->ReadLines(0, $numlines);
	ProcessResult("[IFDS_Text] Read lines", $result);
	ProcessResult("[IFDS_Text] Contains " . $numlines . " lines", ($numlines === count($result["lines"])));
	ProcessResult("[IFDS_Text] At end of file", $result["eof"]);
	ProcessResult("[IFDS_Text] Compare data", ($data === implode("\n", $result["lines"])));

	$ifdstext->Close();


	// IFDS configuration file format tests.
	require_once $rootpath . "/../support_extra/ifds_conf.php";

	$ifdsconf = new IFDS_Conf();

	$pfc->SetData("");
	$result = $ifdsconf->Create($pfc, array("app" => "PHP", "ver" => phpversion()));
	ProcessResult("[IFDS_Conf] Create with PagingFileCache", $result);

	$result = $ifdsconf->CreateSection("PHP", "ini");
	ProcessResult("[IFDS_Conf] Create 'PHP' section", $result);

	$iniobj = $result["obj"];
	$iniopts = $result["options"];

	$phpini = ini_get_all();
	foreach ($phpini as $key => $info)
	{
		if (isset($info["global_value"]))  $iniopts[$key] = array("use" => true, "type" => IFDS_Conf::OPTION_TYPE_STRING, "vals" => array($info["global_value"]));
	}

	$result = $ifdsconf->UpdateSection($iniobj, $iniopts);
	ProcessResult("[IFDS_Conf] Update 'PHP' section", $result);

	// Close the file.
	$result = $ifdsconf->Save();
	ProcessResult("[IFDS_Conf] Save()", $result);

	$estfree = $ifdsconf->GetIFDS()->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata11 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_11.ifds", $filedata11);

	$ifdsconf->Close();

	// Dump size info.
	ProcessResult("[IFDS_Conf] " . strlen($filedata11) . " bytes | " . $estfree . " bytes free", true);

	// Open the IFDS configuration file.
	$pfc->SetData($filedata11);
	$result = $ifdsconf->Open($pfc);
	ProcessResult("[IFDS_Conf] Open with PagingFileCache", $result);

	$result = $ifdsconf->GetSection("PHP");
	ProcessResult("[IFDS_Conf] Get 'PHP' section", $result);

	foreach ($phpini as $key => $info)
	{
		if (isset($info["global_value"]))
		{
			if (!isset($result["options"][$key]))  ProcessResult("[IFDS_Conf] Missing '" . $key . "'", false);
			if (!$result["options"][$key]["use"] || $result["options"][$key]["type"] !== IFDS_Conf::OPTION_TYPE_STRING)  ProcessResult("[IFDS_Conf] The option '" . $key . "' use or type is not valid", false);
			if ($result["options"][$key]["vals"][0] !== $info["global_value"])  ProcessResult("[IFDS_Conf] The data for option '" . $key . "' does not match", false);
		}
	}

	ProcessResult("[IFDS_Conf] Compare data", true);

	$ifdsconf->Close();

	// Create a configuration definition file.
	$ifdsconfdef = new IFDS_ConfDef();

	$pfc->SetData("");
	$result = $ifdsconfdef->Create($pfc, array("app" => "PHP", "ver" => phpversion()));
	ProcessResult("[IFDS_ConfDef] Create with PagingFileCache", $result);

	$result = $ifdsconfdef->CreateContext("ini");
	ProcessResult("[IFDS_ConfDef] Create 'ini' context", $result);

	$iniobj = $result["obj"];
	$iniopts = $result["options"];

	$langmap = array(
		"en-us" => "PHP INI options control how PHP loads itself as well as processes and generates content."
	);

	$result = $ifdsconfdef->CreateDoc($langmap);
	ProcessResult("[IFDS_ConfDef] Create 'ini' context documentation", $result);

	$iniopts[""] = $result["obj"]->GetID();

	foreach ($phpini as $key => $info)
	{
		if (isset($info["global_value"]))
		{
			$result = $ifdsconfdef->CreateOption(IFDS_ConfDef::OPTION_TYPE_STRING, array("defaults" => array($info["global_value"])));
			if (!$result["success"])  ProcessResult("[IFDS_ConfDef] Create '" . $key . "' option", $result);

			$iniopts[$key] = $result["obj"]->GetID();
		}
	}

	ProcessResult("[IFDS_ConfDef] Create " . (count($iniopts) - 1) . " options", $result);

	$result = $ifdsconfdef->UpdateContext($iniobj, $iniopts);
	ProcessResult("[IFDS_ConfDef] Update 'ini' context", $result);

	// Close the file.
	$result = $ifdsconfdef->Save();
	ProcessResult("[IFDS_ConfDef] Save()", $result);

	$estfree = $ifdsconfdef->GetIFDS()->GetEstimatedFreeSpace();

	$pfc->Sync(true);
	$filedata12 = $pfc->GetData();
	file_put_contents($rootpath . "/../test_12.ifds", $filedata12);

	$ifdsconfdef->Close();

	// Dump size info.
	ProcessResult("[IFDS_ConfDef] " . strlen($filedata12) . " bytes | " . $estfree . " bytes free", true);

	// Open the IFDS configuration definition file.
	$pfc->SetData($filedata12);
	$result = $ifdsconfdef->Open($pfc);
	ProcessResult("[IFDS_ConfDef] Open with PagingFileCache", $result);

	$result = $ifdsconfdef->GetContext("ini");
	ProcessResult("[IFDS_ConfDef] Get 'ini' context", $result);

	$iniobj = $result["obj"];
	$iniopts = $result["options"];

	foreach ($phpini as $key => $info)
	{
		if (isset($info["global_value"]))
		{
			if (!isset($iniopts[$key]))  ProcessResult("[IFDS_ConfDef] Missing '" . $key . "'", false);

			$result = $ifdsconfdef->GetOption($iniopts[$key]);
			if (!$result["success"])  ProcessResult("[IFDS_ConfDef] The option '" . $key . "' is not valid", $result);

			if ($result["options"]["type"] !== IFDS_Conf::OPTION_TYPE_STRING)  ProcessResult("[IFDS_ConfDef] The option '" . $key . "' type is not valid", false);
			if ($result["options"]["defaults"][0] !== $info["global_value"])  ProcessResult("[IFDS_ConfDef] The default data for option '" . $key . "' does not match", false);
		}
	}

	ProcessResult("[IFDS_ConfDef] Compare data", true);

	$ifdsconfdef->Close();

	// Output results.
	echo "\n-----\n";
	if (!$failed && !$skipped)  echo "All tests were successful.\n";
	else  echo "Results:  " . $passed . " passed, " . $failed . " failed, " . $skipped . " skipped.\n";
?>