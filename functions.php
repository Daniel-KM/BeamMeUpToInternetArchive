<?php

class Beam_Upload_Job extends Omeka_JobAbstract
{
    private $_memoryLimit;
    private $_harvestId;

    public function perform()
    {
        curl_exec(beam_getMetadataCurlObject(true));

        while (preg_replace('/\s/', '', file_get_contents('http://archive.org/metadata/' . beamGetBucketName())) == '{}') {
            usleep(1000);
        }

        // Now that bucket has been created, run multi-threaded cURL.
        $curlMultiHandle = curl_multi_init();

        $i = 0;
        while (loop_files_for_item()) {
            $curl[$i] = beam_addHandle($curlMultiHandle, beam_getFileCurlObject(true, get_current_file()));
        }

        beam_execMultiHandle($curlMultiHandle);

        // Remove the handles.
        for ($i = 0; $i < count($curl); $i++) {
            curl_multi_remove_handle($curlMultiHandle, $curl[$i]);
        }

        curl_multi_close($curlMultiHandle);
    }

    public function setHarvestId($id)
    {
        $this->_harvestId = $id;
    }
}

function beam_getInitializedCurlObject($first, $title)
{
    $cURL = curl_init();

    curl_setopt($cURL, CURLOPT_HEADER, 1);
    curl_setopt($cURL, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($cURL, CURLOPT_LOW_SPEED_LIMIT, 1);
    curl_setopt($cURL, CURLOPT_LOW_SPEED_TIME, 180);
    curl_setopt($cURL, CURLOPT_NOSIGNAL, 1);
    curl_setopt($cURL, CURLOPT_PUT, 1);
    curl_setopt($cURL, CURLOPT_RETURNTRANSFER, true);

    // Note that curl_setopt does not seem to work with predefined arrays,
    // which is a real deterent to good code.
    if ($first) {
        curl_setopt($cURL, CURLOPT_HTTPHEADER, array(
            'x-amz-auto-make-bucket:1',
            //TODO: which works?
            'x-archive-metadata-collection:' . get_option('beam_collection_name'),
            'x-archive-meta-collection:' . get_option('beam_collection_name'),
            'x-archive-meta-mediatype:' . get_option('beam_media_type'),
            'x-archive-meta-title:' . $title,
            'x-archive-meta-noindex:' . (($_POST['BeamIndexAtInternetArchive'] == '1') ? '0' : '1'),
            'x-archive-meta-creator:' . preg_replace('/www/', '', $_SERVER["SERVER_NAME"], 1),
            'authorization: LOW ' . get_option('beam_S3_access_key') . ':' . get_option('beam_S3_secret_key'),
        ));
    }
    else {
        curl_setopt($cURL, CURLOPT_HTTPHEADER, array(
            //TODO: which works?
            'x-archive-metadata-collection:' . get_option('beam_collection_name'),
            'x-archive-meta-collection:' . get_option('beam_collection_name'),
            'x-archive-meta-mediatype:' . get_option('beam_media_type'),
            'x-archive-meta-title:' . $title,
            'x-archive-meta-noindex:' . (($_POST['BeamIndexAtInternetArchive'] == '1') ? '0' : '1'),
            'x-archive-meta-creator:' . preg_replace('/www/', '', $_SERVER["SERVER_NAME"], 1),
            'authorization: LOW ' . get_option('beam_S3_access_key') . ':' . get_option('beam_S3_secret_key'),
        ));
    }

    return $cURL;
}

/**
 * @param $first true if this is the first PUT to the bucket, false  otherwise
 * @return A cURL object with parameters set to upload metadata
 */
function beam_getMetadataCurlObject($first)
{
    $cURL = beam_getInitializedCurlObject($first,'Item Metadata');
    $body = show_item_metadata($options = array('show_empty_elements' => true), $item = $item);

    echo $body;

    /** use a max of 256KB of RAM before going to disk */
    $fp = fopen('php://temp/maxmemory:256000', 'w');
    if (!$fp) {
        die('could not open temp memory data');
    }
    fwrite($fp, $body);
    fseek($fp, 0);

    curl_setopt($cURL, CURLOPT_URL, 'http://s3.us.archive.org/' . beamGetBucketName() . '/metadata.html');
    curl_setopt($cURL, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($cURL, CURLOPT_INFILE, $fp); // file pointer
    curl_setopt($cURL, CURLOPT_INFILESIZE, strlen($body));

    return $cURL;
}

/**
 * @param $first true if this is the first PUT to the bucket, false otherwise
 * @param $fileToBePut the Omeka file to by uploaded to the Internet Archive
 * @return A cURL object with parameters set to upload an Omeka File
 */
function beam_getFileCurlObject($first, File $fileToBePut)
{
    $cURL = beam_getInitializedCurlObject($first, item_file('original filename'));

    // open this directory
    set_current_file($fileToBePut);
    echo "Julia's Lullaby";
    echo preg_replace('/&#\d+;/' , '_', htmlspecialchars(preg_replace('/\s/','_',"Julia's Lullaby"), ENT_QUOTES));
    echo item_file('original filename');
    echo preg_replace('/&#\d+;/', '_', htmlspecialchars(preg_replace('/\s/', '_', item_file('original filename')), ENT_QUOTES));

    //TODO Test with hyphen, apostrophe
    curl_setopt($cURL, CURLOPT_URL, 'http://s3.us.archive.org/' . beamGetBucketName() . '/' . preg_replace('/&#\d+;/', '_', htmlspecialchars(preg_replace('/\s/', '_', item_file('original filename')), ENT_QUOTES)));
    curl_setopt($cURL, CURLOPT_INFILE, fopen(FILES_DIR . '/' . item_file('archive filename'), 'r'));
    curl_setopt($cURL, CURLOPT_INFILESIZE, item_file('Size'));

    return $cURL;
}

/**
 * Adds handle for to cURL multi object
 * @param $curlMultiHandle pointer to multi cURL multi handle that will be added to
 * @param $cURL single cURL handle to add
 * @return $curl the object for curl_multi_remove_handle
 */
function beam_addHandle(&$curlMultiHandle,$cURL)
{
    curl_multi_add_handle($curlMultiHandle,$cURL);
    return $cURL;
}

/**
 * Executes PUT method to upload Omeka metadata
 * @param $successful pointer to success flag. Will be set to false if HTTP code is  not 200
 * @param $cURL single cURL handle to execute
 * @return void
 */
function beam_execSingleHandle(&$successful,$cURL)
{
    curl_exec($cURL);

    if (curl_getinfo($cURL, CURLINFO_HTTP_CODE) != 200) {
        $successful = false;
    }

    print_r(curl_getinfo($cURL));
}

/**
 * Executes the cURL multi handle until there are no outstanding jobs
 * @return void
 */
function beam_execMultiHandle(&$curlMultiHandle)
{
    $flag = null;
    do {
        //fetch pages in parallel
        curl_multi_exec($curlMultiHandle, $flag);
    }
    while ($flag > 0);
}
