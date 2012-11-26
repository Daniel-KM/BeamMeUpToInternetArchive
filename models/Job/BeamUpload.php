<?php

/**
 * @todo Use ZendS3?
 */
class Job_BeamUpload extends Omeka_Job_AbstractJob
{
    private $_item;

    // List of fileId of files to be uploaded.
    private $_files = array();

    public function perform()
    {
        $this->_item = get_record_by_id('item', $this->_options['itemId']); 
        $this->_files = $this->_options['files'];

        $curl = $this->_getMetadataCurlObject(true);
        curl_exec($curl);

        while (preg_replace('/\s/', '', file_get_contents('http://archive.org/metadata/' . beamGetBucketName($this->_item->id))) == '{}') {
            usleep(1000);
        }

        // Now that bucket has been created, run multi-threaded cURL.
        $curlMultiHandle = curl_multi_init();

        $curls = array();
        $files = $this->_item->getFiles();
        foreach ($files as $file) {
            if (in_array($file->id, $this->_files)) {
                $curl = $this->_getFileCurlObject(true, $file);
                $curls[] = $this->_addHandle($curlMultiHandle, $curl);
            }
        }

        $this->_execMultiHandle($curlMultiHandle);

        // Remove the handles.
        foreach ($curls as $curl) {
            curl_multi_remove_handle($curlMultiHandle, $curl);
        }

        curl_multi_close($curlMultiHandle);
    }

    private function _getInitializedCurlObject($first, $title)
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
     * @param $first true if this is the first PUT to the bucket, false otherwise
     * @return A cURL object with parameters set to upload metadata
     */
    private function _getMetadataCurlObject($first)
    {
        $cURL = $this->_getInitializedCurlObject($first, 'Item Metadata');
        $body = all_element_texts($this->_item, array('show_empty_elements' => true));

        // Use a max of 256KB of RAM before going to disk.
        $fp = fopen('php://temp/maxmemory:256000', 'w');
        if (!$fp) {
            throw new Exception('Upload to Internet Archive aborted: Could not open temp memory data.', 1);
        }
        fwrite($fp, $body);
        fseek($fp, 0);

        curl_setopt($cURL, CURLOPT_URL, 'http://s3.us.archive.org/' . beamGetBucketName($this->_item->id) . '/metadata.html');
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
    private function _getFileCurlObject($first, File $fileToBePut)
    {
        $cURL = $this->_getInitializedCurlObject($first, $fileToBePut->original_filename);

        // Open this directory.
        //TODO Test with hyphen, apostrophe, etc.
        $originalFilename = preg_replace('/&#\d+;/', '_', htmlspecialchars(preg_replace('/\s/', '_', $fileToBePut->original_filename), ENT_QUOTES));
        curl_setopt($cURL, CURLOPT_URL, 'http://s3.us.archive.org/' . beamGetBucketName($this->_item->id) . '/' . $originalFilename);
        curl_setopt($cURL, CURLOPT_INFILE, fopen(FILES_DIR . '/original/' . $fileToBePut->filename, 'r'));
        curl_setopt($cURL, CURLOPT_INFILESIZE, $fileToBePut->size);

        return $cURL;
    }

    /**
     * Adds handle for to cURL multi object.
     *
     * @param $curlMultiHandle pointer to multi cURL multi handle that will be added to
     * @param $cURL single cURL handle to add
     * @return $curl the object for curl_multi_remove_handle
     */
    private function _addHandle(&$curlMultiHandle, $cURL)
    {
        curl_multi_add_handle($curlMultiHandle, $cURL);
        return $cURL;
    }

    /**
     * Executes PUT method to upload Omeka metadata.
     *
     * @param $successful pointer to success flag. Will be set to false if HTTP code is not 200
     * @param $cURL single cURL handle to execute
     * @return void
     */
    private function _execSingleHandle(&$successful, $cURL)
    {
        curl_exec($cURL);

        if (curl_getinfo($cURL, CURLINFO_HTTP_CODE) != 200) {
            $successful = false;
        }

        print_r(curl_getinfo($cURL));
    }

    /**
     * Executes the cURL multi handle until there are no outstanding jobs.
     *
     * @return void
     */
    private function _execMultiHandle(&$curlMultiHandle)
    {
        $flag = null;
        do {
            // Fetch pages in parallel.
            curl_multi_exec($curlMultiHandle, $flag);
        }
        while ($flag > 0);
    }
}
