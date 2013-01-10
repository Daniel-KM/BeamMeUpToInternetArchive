<?php

/**
 * Upload an item with its files and metadata to Internet Archive.
 *
 * Progress is saved in base, so a job can be restarted at any time if it fails.
 *
 * @see http://archive.org/help/abouts3.txt
 */
class Job_BeamUploadInternetArchive extends Omeka_Job_AbstractJob
{
    // Use to save upload progress and main beam info to display.
    private $session;

    // Use to save all beams to process.
    private $_beams = array();
    // Beam for current record.
    private $_beam;
    private $_record;
    private $_required_beam;

    // Use to save curl handlers and file pointers
    private $_ressources = array();

    public function perform()
    {
        // Prepare and manage beam me up session in order to keep info on curls. 
        $this->session = new Zend_Session_Namespace('BeamMeUpToInternetArchive');

        // Get records to process and order items before their attached files.
        $this->_prepareListOfQueuedBeams();

        // First, we need to create buckets for items that don't have one.
        foreach ($this->_beams as $key => $beam) {
            if ($beam->isBeamForItem()
                    && !$beam->isBucketReady()
                    && $beam->process != BeamInternetArchiveRecord::PROCESS_QUEUED_WAITING_BUCKET
                ) {
                $this->_beam = $beam;
                $this->_createBucket();
            }
        }

        // Now, process each record via a multi-threaded curl.
        $curlMultiHandle = curl_multi_init();

        // Prepare each curl.
        foreach ($this->_beams as $key => $beam) {
            $this->_beam = $beam;
            $curl = $this->_beamMeUp();
            // Check process and add instance of curl in the multi handle curl.
            if (!empty($curl)) {
                $this->_addHandle($curlMultiHandle, $curl);
            }
            // Avoid later checks.
            else {
                // Curl is not set or is already reset.
                unset($this->_beams[$key]);
            }
        }

        // Avoid some useless tasks.
        if (count($this->_beams) == 0) {
            curl_multi_close($curlMultiHandle);
            return;
        }

        // Launch multihandle with all curls.
        $result = $this->_execMultiHandle($curlMultiHandle);

        // Check whole result of multihandle.
        // In fact, the multihandler can't return without success.

        // Check individual results and remove the handles.
        foreach ($this->_ressources as $beamId => $ressource) {
            $curl = $ressource['curlHandler'];
            $curlInfo = curl_getinfo($curl);
            $beam = $this->_beams[$beamId];

            // Because it's not a bucket, there is no problem to set error and
            // to relaunch process later, so check is basic.
            // Curl error.
            if (curl_errno($curl)) {
                $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_FAILED_CONNECTION);
                $message = __('Connection error #%d (%s) when processing "%s" for %s #%d.', curl_errno($curl), curl_error($curl), $beam->status, $beam->record_type, $beam->record_id);
                $this->_log($message, Zend_Log::WARN, $beamId);
            }
            // Http error.
            elseif ($curlInfo['http_code'] != 200) {
                $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_FAILED_CONNECTION);
                $message = __('Connection error with http code #%d when processing "%s" for %s #%d.', $curlInfo['http_code'], $beam->status, $beam->record_type, $beam->record_id);
                $this->_log($message, Zend_Log::WARN, $beamId);
            }
            // Success.
            else {
                $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_IN_PROGRESS_WAITING_REMOTE);
                $message = __('Finishing processing %s #%d. Waiting remote status.', $beam->record_type, $beam->record_id);
                $this->_log($message, Zend_Log::INFO, $beamId);
            }

            curl_multi_remove_handle($curlMultiHandle, $curl);
            $this->_closeCurlForRecord();
        }

        curl_multi_close($curlMultiHandle);

        // Finalize process and check remote status.
        foreach ($this->_beams as $beam) {
            $this->_beam = $beam;
            $this->_closeCurlForRecord();
            $beam->checkRemoteStatus();
        }

        return;
    }

    /**
     * Get, lock and order a list of records to beam up.
     *
     * Order beams in order to give the priority to required records, which have
     * a lower id than non required records.
     */
    private function _prepareListOfQueuedBeams()
    {
        // Get beam record to process if any.
        if (!empty($this->_options['beams'])) {
            // Remove duplicates beams.
            $beams = array_combine($this->_options['beams'], $this->_options['beams']);
            sort($beams);
            // TODO Add param to filter queued only.
            // Get full beams.
            $beams = $this->_db
                ->getTable('BeamInternetArchiveRecord')
                ->findMultiple($beams);
            // Remove records that are not queued.
            foreach ($beams as $key => $beam) {
                if (!$beam->isProcessQueued()) {
                   unset($beams[$key]);
                }
            }
        }

        // Lock records to beam and save keyed array.
        foreach ($beams as $beam) {
            // Status "Waiting bucket" can be changed only when the bucket is
            // created.
            if ($beam->process != BeamInternetArchiveRecord::PROCESS_QUEUED_WAITING_BUCKET) {
                $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_IN_PROGRESS);
            }

            $this->_beams[$beam->id] = $beam;

            $message = __('%s #%d is queued and process "%s" is in progress.', $beam->record_type, $beam->record_id, $beam->status);
            $this->_log($message, Zend_Log::INFO, $beam->id);
        }
    }

    /**
     * Create an empty Internet Archive bucket for a checked and queued item.
     *
     * Metadata of bucket will be created later in a second step, like files.
     *
     * @todo Check for update.
     * @return boolean True for success or False for failure.
     */
    private function _createBucket()
    {
        $beam = $this->_beam;

        $message = __('Starting to create bucket for %s #%d.', $beam->record_type, $beam->record_id);
        $this->_log($message, Zend_Log::INFO, $beam->id);

        // Check remote id. It's important, because some time can happen between
        // the preparation of the record to beam up and the true upload and we
        // need to avoid a duplicate name.
        $beam->setRemoteId($beam->remote_id);
        $beam->save();
        if (empty($beam->remote_id)) {
            $this->_closeCurlForRecord();
            $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_FAILED_CONNECTION);
            $message = __('Failed to create bucket for %s #%d: %s.', $beam->record_type, $beam->record_id, (isset($beam->remote_metadata->error) ? $beam->remote_metadata->error : __('Bucket without identifier.')));
            $this->_log($message, Zend_Log::WARN, $beam->id);
            return false;
        }

        // Use an empty file in order to be able to do a put.
        $filePointer = fopen('/dev/null', 'r');

        // Prepare curl.
        $httpHeader = array();
        $httpHeader[] = 'Host:' . $beam->remote_id . '.s3.us.archive.org';
        $httpHeader[] = 'Content-Type:application/octet-stream';
        $httpHeader[] = 'authorization: LOW ' . get_option('beamia_S3_access_key') . ':' . get_option('beamia_S3_secret_key');
        $httpHeader[] = 'x-archive-interactive-priority:1';
        $httpHeader[] = 'x-amz-auto-make-bucket:1';
        $httpHeader[] = 'x-archive-metadata-collection:' . get_option('beamia_collection_name');
        $httpHeader[] = 'x-archive-meta-creator:' . preg_replace('/www/', '', $_SERVER["SERVER_NAME"], 1);
        $httpHeader[] = 'x-archive-meta-noindex:' . $beam->getNoIndex();

        // Add all prepared metadata.
        $httpHeader = array_merge($httpHeader, $beam->getSettings());

        // Generics option for curl.
        $curl = $this->_initializeCurlForRecord('single');

        // Specific options for curl.
        curl_setopt($curl, CURLOPT_HTTPHEADER, $httpHeader);
        curl_setopt($curl, CURLOPT_URL, $beam->getUrlForBucket());
        curl_setopt($curl, CURLOPT_INFILE, $filePointer);
        curl_setopt($curl, CURLOPT_INFILESIZE, 0);

        // Run a single instance of curl, because it's the bucket.
        try {
            $curlInfo = $this->_execSingleHandle($curl);
        } catch (Exception_BeamInternetArchiveConnection $e) {
            curl_close($curl);
            fclose($filePointer);

            // Beam is created even if there was a curl error, for example with
            // error #28 (operation too slow). So a check is needed. This case
            // doesn't occur often, because no content is sent when a bucket is
            // created. Nevertheless, the case should be managed with a new
            // remote check. Some other extremely rare cases are not managed.
            $flagSuccess = false;
            if ($e->getCode() == 2) {
                $flagSuccess = $beam->checkIfBucketIsReady();
                // Other cases mean an unstable connection and is not managed.
            }

            if (!$flagSuccess) {
                $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_FAILED_CONNECTION);
                $message = __('Failed to create bucket for %s #%d: %s.', $beam->record_type, $beam->record_id, $e->getMessage());
                $this->_log($message, Zend_Log::WARN, $beam->id);
                return false;
            }
        }

        // Finish process of creation of a bucket.
        curl_close($curl);
        fclose($filePointer);
        $beam->remote_checked = date('Y-m-d G:i:s');
        $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_QUEUED_WAITING_BUCKET);
        $message = __('Succesful creation of bucket for %s #%d. Waiting remote status for it.', $beam->record_type, $beam->record_id);
        $this->_log($message, Zend_Log::NOTICE, $beam->id);

        return true;
    }

    /**
     * Prepare a record to be uploaded, updated or removed on the remote site.
     *
     * @return curl handler.
     */
    private function _beamMeUp()
    {
        $beam = $this->_beam;

        // Normally this should never occur.
        if ($beam->status ===  BeamInternetArchiveRecord::STATUS_NOT_TO_BEAM_UP) {
            return false;
        }

        // Check if the bucket is created. This is important to do it now.
        if (!$beam->checkIfBucketIsReady()) {
            // Record is "in progress". Unlock it because this is not an error,
            // except if process is "waiting bucket", because this process
            // status cannot be changed here.
            if ($beam->isBeamForItem()) {
                if (!in_array($beam->process, array(
                        BeamInternetArchiveRecord::PROCESS_FAILED_RECORD,
                        BeamInternetArchiveRecord::PROCESS_QUEUED_WAITING_BUCKET,
                    ))) {
                    $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_QUEUED);
                }
            }
            else {
                $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_QUEUED_WAITING_BUCKET);
            }
            $message = __('Process "%s" failed for %s #%d: bucket is not ready.', $beam->status, $beam->record_type, $beam->record_id);
            $this->_log($message, Zend_Log::NOTICE, $beam->id);
            return false;
        }

        // If the bucket was created in a previous job, status need to be set
        // one more time to 'in progress'.
        if ($beam->isBeamForItem()) {
            $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_IN_PROGRESS);
        }

        // No update is possible if tasks are pending, so process is postponed.
        $beam->checkRemoteStatus();
        if ($beam->hasPendingTasks() && $beam->isToUpdateOrToRemove()) {
            $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_QUEUED);
            $message = __('Process is postponed for %s #%d: tasks are pending.', $beam->record_type, $beam->record_id);
            $this->_log($message, Zend_Log::NOTICE, $beam->id);
            return false;
        }

        // Bucket is ready, so process file.
        $message = __('Starting process "%s" for %s #%d.', $beam->status, $beam->record_type, $beam->record_id);
        $this->_log($message, Zend_Log::INFO, $beam->id);

        // Prepare content to be uploaded from the record.
        $record = get_record_by_id($beam->record_type, $beam->record_id);

        // Check remote id (it will be not changed if already created).
        $beam->setRemoteId($beam->remote_id);

        // Generic options for curl.
        $curl = $this->_initializeCurlForRecord('multiple');

        // Specific options for curl.
        curl_setopt($curl, CURLOPT_URL, $beam->getUrlForBeamUp());
        switch ($beam->status) {
            case BeamInternetArchiveRecord::STATUS_NOT_TO_BEAM_UP:
                // Already managed.
                return;

            case BeamInternetArchiveRecord::STATUS_TO_BEAM_UP:
                // No difference with to update.
            case BeamInternetArchiveRecord::STATUS_TO_UPDATE:
                // If we are here, there are no pending tasks.
                $httpHeader = $this->_getMetadataHeadersForCurl();
                switch ($beam->record_type) {
                    case BeamInternetArchiveRecord::RECORD_TYPE_ITEM:
                        // Prepare content to be uploaded. Content are metadata of item.
                        $content = all_element_texts($record, array(
                            'show_empty_elements' => true,
                            'return_type' => 'html',
                        ));
                        try {
                            $this->_ressources[$beam->id]['filePointer'] = $this->_prepareFileFromString($content);
                        } catch (Exception_BeamInternetArchiveConnection $e) {
                            $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_FAILED_CONNECTION);
                            $message = __('Process "%s" failed for %s #%d: %s.', $beam->status, $beam->record_type, $beam->record_id, $e->getMessage());
                            $this->_log($message, Zend_Log::WARN, $beam->id);
                            $this->_closeCurlForRecord();
                            return false;
                        }
                        curl_setopt($curl, CURLOPT_INFILE, $this->_ressources[$beam->id]['filePointer']);
                        curl_setopt($curl, CURLOPT_INFILESIZE, strlen($content));
                        $content = null;
                        break;
                    case BeamInternetArchiveRecord::RECORD_TYPE_FILE:
                        try {
                            $this->_ressources[$beam->id]['filePointer'] = fopen(FILES_DIR . '/original/' . $record->filename, 'r');
                        } catch (Exception $e) {
                            $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_FAILED_RECORD);
                            $message = __('Process "%s" failed for %s #%d: %s.', $beam->status, $beam->record_type, $beam->record_id, $e->getMessage());
                            $this->_log($message, Zend_Log::WARN, $beam->id);
                            $this->_closeCurlForRecord();
                            return false;
                        }
                        curl_setopt($curl, CURLOPT_INFILE, $this->_ressources[$beam->id]['filePointer']);
                        curl_setopt($curl, CURLOPT_INFILESIZE, $record->size);
                        break;
                }
                break;

            case BeamInternetArchiveRecord::STATUS_TO_REMOVE:
                // Use an empty file in order to be able to do a put.
                $this->_ressources[$beam->id]['filePointer'] = fopen('/dev/null', 'r');

                // Prepare generic metadata.
                $httpHeader = array();
                $httpHeader[] = 'authorization: LOW ' . get_option('beamia_S3_access_key') . ':' . get_option('beamia_S3_secret_key');
                $httpHeader[] = 'x-archive-cascade-delete:1';
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($curl, CURLOPT_INFILE, $this->_ressources[$beam->id]['filePointer']);
                curl_setopt($curl, CURLOPT_INFILESIZE, 0);
                break;
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $httpHeader);
        return $curl;
    }

    /**
     * Create a temporary file with content in order to beam them up.
     *
     * @param string $content Content to be sent.
     *
     * @return file pointer.
     */
    private function _prepareFileFromString($content)
    {
        // Use a max of 256KB of RAM before going to disk.
        $filePointer = fopen('php://temp/maxmemory:256000', 'w'); // File pointer.
        if (!$filePointer) {
            // Not really a connect exception, but similar (not logical error).
            throw new Exception_BeamInternetArchiveConnection(__('Upload to Internet Archive aborted: Could not open temp memory data.'));
        }
        fwrite($filePointer, $content);
        fseek($filePointer, 0);
        return $filePointer;
    }

    /**
     * Check if a file can be uploaded, without check of required item.
     *
     * @param string $mode 'single' [default] or 'multiple'.
     *
     * @return curl handler.
     */
    private function _initializeCurlForRecord($mode = 'single')
    {
        $beamId = $this->_beam->id;

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($curl, CURLOPT_LOW_SPEED_TIME, 180);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);
        curl_setopt($curl, CURLOPT_PUT, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);

        // No session management for single curl since it uses only with bucket,
        // which is a zero lenght request.
        if ($mode == 'multiple') {
            $this->_ressources[$beamId]['curlHandler'] = $curl;
            // The normal function works for a single curl, but not for a multi
            // one (need the curl identifier).
            // curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, array(__CLASS__, '_curlProgressInfo'));
            // curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, __CLASS__ . '::_curlProgressInfo');
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt($curl, CURLOPT_BUFFERSIZE, 1024);
            $this->_define_progress_callback($curl, $beamId);
            // Reset session progress.
            if (isset($this->session->beams[$beamId]['finish'])) {
                unset($this->session->beams[$beamId]['downloadTotal']);
                unset($this->session->beams[$beamId]['downloadNow']);
                unset($this->session->beams[$beamId]['uploadTotal']);
                unset($this->session->beams[$beamId]['uploadNow']);
                unset($this->session->beams[$beamId]['finish']);
            }
        }

        return $curl;
    }

    /**
     * Close a curl handler and remove it from ressources.
     *
     * @return void.
     */
    private function _closeCurlForRecord()
    {
        $beamId = $this->_beam->id;

        if (isset($this->_ressources[$beamId])) {
            if (isset($this->_ressources[$beamId]['curlHandler'])) {
                @curl_close($this->_ressources[$beamId]['curlHandler']);
            }
            if (isset($this->_ressources[$beamId]['filePointer'])) {
                @fclose($this->_ressources[$beamId]['filePointer']);
            }
        }
    }

    /**
     * Put all metadata of the current beam in one array to prepare headers.
     *
     * @return array for A curl object with parameters set to upload a record.
     */
    private function _getMetadataHeadersForCurl()
    {
        $beam = $this->_beam;

        // Prepare generic metadata.
        $httpHeader = array();
        $httpHeader[] = 'authorization: LOW ' . get_option('beamia_S3_access_key') . ':' . get_option('beamia_S3_secret_key');
        $httpHeader[] = 'x-archive-metadata-collection:' . get_option('beamia_collection_name');
        $httpHeader[] = 'x-archive-meta-creator:' . preg_replace('/www/', '', $_SERVER["SERVER_NAME"], 1);
        $httpHeader[] = 'x-archive-meta-noindex:' . $beam->getNoIndex();

        // Add all prepared metadata.
        $httpHeader = array_merge($httpHeader, $beam->getSettings());

        return $httpHeader;
    }

    /**
     * Add handle for to curl multi object.
     *
     * @param $curlMultiHandle pointer to multi curl multi handle that will be added to
     * @param $curlHandler single curl handle to add
     *
     * @return $curl the object for curl_multi_remove_handle
     */
    private function _addHandle(&$curlMultiHandle, $curlHandler)
    {
        curl_multi_add_handle($curlMultiHandle, $curlHandler);
        return $curlHandler;
    }

    /**
     * Execute PUT method against remote server.
     *
     * @param ressource $curlHandler Curl handle to execute.
     *
     * @return curl info if success or throw error message.
     */
    private function _execSingleHandle($curlHandler)
    {
        $result = curl_exec($curlHandler);
        $httpCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);
        if ($result === false) {
            throw new Exception_BeamInternetArchiveConnection(__('Upload returns error: no connection.'), 1);
        }
        if (curl_errno($curlHandler)) {
            throw new Exception_BeamInternetArchiveConnection(__('Upload returns error #%d (%s).', curl_errno($curlHandler), curl_error($curlHandler)), 2);
        }
        if ($httpCode != 200) {
            throw new Exception_BeamInternetArchiveConnection(__('Unable to upload item (error http #%d). Check your proxy or your server.', $httpCode), 3);
        }
        return curl_getinfo($curlHandler);
    }

    /**
     * Execute the curl multi handle until there are no outstanding jobs.
     *
     * @return void
     *
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

    /**
     * Save info about a curl process into the session and check killing.
     *
     * This function can manage multi curls.
     * @see http://pastebin.com/9YytCX9P
     * 
     * @return curl command (0 to continue).
     */
    private function _define_progress_callback($curlHandler, $beamId)
    {
        $finish = 0;
        curl_setopt($curlHandler, CURLOPT_PROGRESSFUNCTION,
            // Don't use context here. 
            function ($a = 0, $b = 0, $c = 0, $d = 0) use($beamId, &$finish) {
                if ($finish == 0 && (($a > 0 && $b == $a) || ($c > 0 && $d == $c) || ($a + $c == 0))) {
                    /* Put here the code to execute every time that a download finishes */
                    $finish = 1;
                }
                $session = new Zend_Session_Namespace('BeamMeUpToInternetArchive');
                $session->beams[$beamId]['downloadTotal'] = $a;
                $session->beams[$beamId]['downloadNow'] = $b;
                $session->beams[$beamId]['uploadTotal'] = $c;
                $session->beams[$beamId]['uploadNow'] = $d;
                $session->beams[$beamId]['finish'] = $finish;
                return 0;
            }
        );
    }

    /**
     * Helper to set log and session message.
     */
    private function _log($message, $level, $beamId = 0)
    {
        _log(__('Beam me up to Internet Archive: %s', $message), $level);
        if ($beamId != 0) {
            $this->session->beams[$beamId]['message'][date('Y-m-d G:i:s')] = '[' . $level . ']: ' . $message;
            $this->session->beams[$beamId]['level'] = $level;
        }
    }
}
