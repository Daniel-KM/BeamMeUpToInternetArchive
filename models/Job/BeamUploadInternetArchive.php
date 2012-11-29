<?php

/**
 * Upload an item with its files and metadata to Internet Archive.
 *
 * Progress is saved in base, so a job can be restared at any time if it fails.
 *
 * @see http://archive.org/help/abouts3.txt
 *
 * @todo Use Zend S3? No, because some differences?
 * @todo Use Zend http instead of curl?
 */
class Job_BeamUploadInternetArchive extends Omeka_Job_AbstractJob
{
    private $_beams = array();
    private $_curls = array();

    // Beam for current item, beam for current file, current item and file.
    private $_beam_item;
    private $_item;

    private $_beam;
    private $_file;

    public function perform()
    {
        // Remove duplicates beams.
        $this->_beams = array_combine($this->_options['beams'], $this->_options['beams']);
        $beam = $this->_beam = $this->_beam_item = get_record_by_id('BeamInternetArchiveBeam', $this->_options['beams']['item']);
        $item = $this->_item = get_record_by_id('item', $this->_beam_item->record_id);

        // Insert or update an Internet Archive bucket for the item.
        try {
            if ($this->_createBucket() === false) {
                // More precisely, the job queue status depends on beam status.
                return JOB_QUEUE_STATUS_WAITING;
            };
        } catch (Exception_BeamInternetArchiveBeam $e) {
            // Status is already updated.
            _log(__('Beam me up to Internet Archive: Error during the creation of the bucket for item "%s".', $beam->record_id), Zend_Log::WARN);
            _log($e->getMessage(), Zend_Log::WARN);
            return JOB_QUEUE_STATUS_LOGICALLY_FAILED;
        } catch (Exception_BeamInternetArchiveConnect $e) {
            $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_FAILED_TO_BEAM_UP);
            _log(__('Beam me up to Internet Archive: Connection error during the creation of the bucket for item "%s".', $beam->record_id), Zend_Log::WARN);
            _log($e->getMessage(), Zend_Log::WARN);
            return JOB_QUEUE_STATUS_EXECUTION_FAILED;
        }

        // Wait the end of the creation of the bucket before import of files.
        if (!$beam->isRemoteAvailable()) {
            // No error, but wait creation of bucket. Another perform is needed.
            return JOB_QUEUE_STATUS_WAITING;
        }

        // Now that bucket is ready, run multi-threaded curl to beam up files.
        // Beam item will be checked only.
        unset($this->_options['beams']['item']);
        $this->_beams = $this->_db->getTable('BeamInternetArchiveBeam')->findMultiple($this->_options['beams']);

        $curlMultiHandle = curl_multi_init();
        // Prepare only files that are not beamed up yet.
        foreach ($this->_beams as $beam) {
            // Check if beam is ok and not created yet.
            if (!$beam->isReadyToBeamUp()) {
                $this->_curls[$beam->id] = 0;
                continue;
            }

            $this->_beam = $beam;

            // Prepare content to be uploaded from the record.
            $this->_file = get_record_by_id($beam->record_type, $beam->record_id);

            _log(__('Starting to beam up %s #%d.', $beam->record_type, $beam->record_id), Zend_Log::INFO);
            $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_IN_PROGRESS);

            // Generic option for curl.
            $curl = $this->_initializeCurl();
            // Specific option for curl.
            curl_setopt($curl, CURLOPT_HTTPHEADER, $this->_getMetadataHeadersForCurl());
            curl_setopt($curl, CURLOPT_URL, $beam->getUrlForFileToUpload());
            curl_setopt($curl, CURLOPT_INFILE, fopen(FILES_DIR . '/original/' . $this->_file->filename, 'r'));
            curl_setopt($curl, CURLOPT_INFILESIZE, $this->_file->size);
            // Add this instance of curl in a multi handle curl.
            $this->_curls[$beam->id] = $this->_addHandle($curlMultiHandle, $curl);
        }

        // Launch multihandle with all curls.
        $result = $this->_execMultiHandle($curlMultiHandle);

        // Check whole result of multihandle.
        // In fact, the multihandler can't return without success.

        // Check individual results and remove the handles.
        foreach ($this->_curls as $curl) {
            $curlGetInfo = curl_getinfo($curl);
            if (curl_errno($curl)) {
                $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_FAILED_TO_BEAM_UP);
                _log(__('Beam me up to Internet Archive: Connection error during the upload of %s #%d.', $beam->record_type, $beam->record_id), Zend_Log::WARN);
                _log(__('Beam me up to Internet Archive: Upload returns error #%d (%s).', curl_errno($curl), curl_error($curl)), Zend_Log::WARN);
            }
            else {
                $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_COMPLETED_WAITING_REMOTE);
                _log(__('Finishing to upload %s #%d. Waiting remote status.', $beam->record_type, $beam->record_id), Zend_Log::INFO);
            }
            curl_multi_remove_handle($curlMultiHandle, $curl);
        }

        curl_multi_close($curlMultiHandle);

        foreach ($this->_beams as $beam) {
            // Check remote status updates sending status only for a good beam.
            $beam->checkRemoteStatus();
        }
        return JOB_QUEUE_STATUS_SUCCESS;
    }

    /**
     * Insert an Internet Archive bucket for the item. Check has been done.
     *
     * @todo Check for update.
     * @return boolean True for success or False for failure.
     */
    private function _createBucket()
    {
        $beam = $this->_beam;
        $item = $this->_item;

        // Check if beam is ok and not created yet.
        if (!$beam->isReadyToBeamUp()) {
            // In fact, the status depends on beam status.
            return false;
        }

        // Prepare content to be uploaded.
        // Content are metadata of item.
        $content = all_element_texts($item, array('show_empty_elements' => true));
        try {
            $filePointer = $this->_prepareFileFromItem($content);
        } catch (Exception_BeamInternetArchiveConnect $e) {
            $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_FAILED_TO_BEAM_UP);
            throw new Exception_BeamInternetArchiveConnect($e->getMessage());
        }

        // Beam item up.
        $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_IN_PROGRESS);
        _log(__('Starting to beam up %s #%d.', $beam->record_type, $beam->record_id), Zend_Log::INFO);
        try {
            // Generic option for curl.
            $curl = $this->_initializeCurl();
            // Specific option for curl.
            curl_setopt($curl, CURLOPT_HTTPHEADER, $this->_getMetadataHeadersForCurl(true));
            curl_setopt($curl, CURLOPT_URL, $beam->getUrlForMetadataToUpload());
            curl_setopt($curl, CURLOPT_INFILE, $filePointer);
            curl_setopt($curl, CURLOPT_INFILESIZE, strlen($content));
            // Run a single instance of curl, because it's the bucket.
            $curlInfo = $this->_execSingleHandle($curl);
        } catch (Exception_BeamInternetArchiveBeam $e) {
            curl_close($curl);
            // Status is already updated.
            throw new Exception_BeamInternetArchiveBeam($e->getMessage());
        } catch (Exception_BeamInternetArchiveConnect $e) {
            curl_close($curl);
            $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_FAILED_TO_BEAM_UP);
            throw new Exception_BeamInternetArchiveConnect($e->getMessage());
        }
        $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_COMPLETED_WAITING_BUCKET_CREATION);
        _log(__('Finishing to upload item #%d. Waiting remote status for bucket creation.', $item->id), Zend_Log::INFO);
        curl_close($curl);
    }

    /**
     * Create a temporary file with content in order to beam them up.
     *
     * @param string $content Content to be sent.
     *
     * @return file pointer.
     */
    private function _prepareFileFromItem($content)
    {
        // Use a max of 256KB of RAM before going to disk.
        $filePointer = fopen('php://temp/maxmemory:256000', 'w'); // File pointer.
        if (!$filePointer) {
            // Not really a connect exception, but similar (not logical error).
            throw new Exception_BeamInternetArchiveConnect(__('Upload to Internet Archive aborted: Could not open temp memory data.'));
        }
        fwrite($filePointer, $content);
        fseek($filePointer, 0);
        return $filePointer;
    }

    /**
     * Check if a file can be uploaded, without check of required item.
     *
     * @return boolean True for success or False for failure.
     */
    private function _initializeCurl()
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($curl, CURLOPT_LOW_SPEED_TIME, 180);
        curl_setopt($curl, CURLOPT_NOSIGNAL, 1);
        curl_setopt($curl, CURLOPT_PUT, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);

        return $curl;
    }

    /**
     * Put all metadata of the current beam in one array to prepare headers.
     *
     * @param boolean $isBucket Create the bucket or not.
     *
     * @return string for A curl object with parameters set to upload a record.
     */
    private function _getMetadataHeadersForCurl($isBucket = false)
    {
        $beam = $this->_beam;

        // Prepare generic metadata.
        $metadata = array();
        $metadata[] = 'authorization: LOW ' . get_option('beamia_S3_access_key') . ':' . get_option('beamia_S3_secret_key');
        $metadata[] = 'x-archive-metadata-collection:' . get_option('beamia_collection_name');
        $metadata[] = 'x-archive-meta-creator:' . preg_replace('/www/', '', $_SERVER["SERVER_NAME"], 1);

        // Add specific metadata.
        if ($isBucket) {
            $metadata[] = 'x-amz-auto-make-bucket:1';
        }
        $metadata[] = 'x-archive-meta-noindex:' . $beam->getNoIndex();

        // Add all prepared metadata.
        $metadata = array_merge($metadata, $beam->getSettings());

        return $metadata;
    }

    /**
     * Adds handle for to curl multi object.
     *
     * @param $curlMultiHandle pointer to multi curl multi handle that will be added to
     * @param $curl single curl handle to add
     *
     * @return $curl the object for curl_multi_remove_handle
     */
    private function _addHandle(&$curlMultiHandle, $curl)
    {
        curl_multi_add_handle($curlMultiHandle, $curl);
        return $curl;
    }

    /**
     * Executes PUT method to upload Omeka metadata.
     *
     * @param $curl single curl handle to execute
     *
     * @return curl info if success.
     */
    private function _execSingleHandle($curl)
    {
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (curl_errno($curl) || $result === false) {
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Upload returns error #%d (%s).', curl_errno($curl), curl_error($curl)));
        }
        if ($httpCode != 200) {
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Unable to upload item (error http #%d). Check your proxy or your server.', $httpCode));
        }
        return curl_getinfo($curl);
    }

    /**
     * Executes the curl multi handle until there are no outstanding jobs.
     *
     * @return void
     *
     * @todo progress info.
     * @todo limit number of parallel upload.
     */
    private function _execMultiHandle(&$curlMultiHandle)
    {
        // Fetch pages in parallel.
        $active = null;
        do {
            $mrc = curl_multi_exec($curlMultiHandle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($curlMultiHandle) != -1) {
                do {
                    $mrc = curl_multi_exec($curlMultiHandle, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }
    }
}
