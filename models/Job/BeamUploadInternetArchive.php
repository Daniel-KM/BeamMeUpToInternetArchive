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

    // Beam for current item, beam for current file, current item and file.
    private $_beam_item;
    private $_beam;
    private $_item;
    private $_file;

    public function perform()
    {
        $beam = $this->_beam = $this->_beam_item = get_record_by_id('BeamInternetArchiveBeam', $this->_options['beamItem']);
        $item = $this->_item = get_record_by_id('item', $this->_beam_item->record_id);

        // Insert or update an Internet Archive bucket for the item.
        try {
            $result = $this->_createBucket();
        } catch (Exception $e) {
            // Status is already updated.
            throw new Exception(__("Beam me up to Internet Archive: Error during the creation of the bucket for item " . $beam->record_id . ".\n" . $e->getMessage()));
        }

        // Wait the end of the creation of the bucket before import of files.
        if (!$beam->isRemoteAvailable()) {
            // No error, but wait creation of bucket. Another perform is needed.
            return;
        }

        // Now that bucket is ready, run multi-threaded curl to beam up files.
        $this->_beams['files'] = $this->_db->getTable('BeamInternetArchiveBeam')->findMultiple($this->_options['beamFiles']);
        $curlMultiHandle = curl_multi_init();
        // Add only files that are not beamed up yet.
        $curls = array();
        foreach ($this->_beams['files'] as $beam) {
            $this->_beam = $beam;
            $this->_file = get_record_by_id('File', $beam->record_id);
            try {
                $result = $this->_checkBeamFile();
            } catch (Exception $e) {
                $beam->saveWithStatus($beam::NO_RECORD);
                throw new Exception(__("Beam me up to Internet Archive: Error during the upload of file " . $beam->record_id . ".\n" . $e->getMessage()));
            }

            if ($result === true) {
                try {
                    $beam->setIndex(($_POST['BeamiaIndexAtInternetArchive'] == '1') ? $beam::IS_PUBLIC : $beam::IS_PRIVATE);
                    $beam->setRemoteIdForFile();
                    $beam->setSettings();
                } catch (Exception $e) {
                    throw new Exception("Beam me up to Internet Archive: File " . $this->record_id . " cannot be beamed up." . "\n" . $e->getMessage());
                }

                $curl = $this->_initializeCurl();
                curl_setopt($curl, CURLOPT_HTTPHEADER, $this->_getMetadataHeadersForCurl());
                curl_setopt($curl, CURLOPT_URL, $beam->getUrlForFileToUpload());
                curl_setopt($curl, CURLOPT_INFILE, fopen(FILES_DIR . '/original/' . $this->_file->filename, 'r'));
                curl_setopt($curl, CURLOPT_INFILESIZE, $this->_file->size);

                $curls[] = $this->_addHandle($curlMultiHandle, $curl);
                $beam->saveWithStatus($beam::BEAMING_UP);
            }
        }

        // TODO Add a check [remove multihandle to get full progress info!].
        $result = $this->_execMultiHandle($curlMultiHandle);

        // Remove the handles.
        foreach ($curls as $curl) {
            curl_multi_remove_handle($curlMultiHandle, $curl);
        }

        curl_multi_close($curlMultiHandle);

        // TODO Update local status. Currently, presume good, because bucket
        // succeed.
        foreach ($this->_beams['files'] as $beam) {
            $this->_beam = $beam;
            // Check remote status updates sending status only for a good beam.
            $beam->checkRemoteStatus();
        }
    }

    /**
     * Insert or update an Internet Archive bucket for the item.
     *
     * @todo Check for update.
     * @return boolean True for success or False for failure.
     */
    private function _createBucket()
    {
        $beam = $this->_beam;
        $item = $this->_item;

        // Check if item still exists.
        if (!$item) {
            $beam->saveWithStatus($beam::NO_RECORD);
            throw new Exception(__("Beam me up to Internet Archive: Item " . $beam->record_id . " doesn't exist."));
        }

        // Check the current status before trying to create bucket.
        switch ($beam->status) {
            case $beam::TO_BEAM_UP_WAITING_BUCKET:
                // Impossible for item.
                return false;
            case $beam::TO_BEAM_UP:
                break;
            case $beam::FAILED:
                // A previous creation of a bucket failed, so try it again.
                break;
            case $beam::NO_RECORD:
                throw new Exception(__("Beam me up to Internet Archive: File " . $beam->record_id . " doesn't exist in a previous check."));
            case $beam::BEAMING_UP:
                return false;
            case $beam::BEAMED_UP_WAITING_BUCKET_CREATION:
                // Need to wait end of creation of the bucket.
                return false;
            case $beam::BEAMED_UP_WAITING_REMOTE:
                // Ready to upload something else.
            case $beam::BEAMED_UP:
                return true;
            default:
                return false;
        }

        // Beam is ok and not created yet.
        // Content are metadata.
        $content = all_element_texts($item, array('show_empty_elements' => true));

        try {
            $filePointer = $this->_prepareFileFromItem($content);
        } catch (Exception $e) {
            $beam->saveWithStatus($beam::FAILED);
            throw new Exception($e->getMessage());
        }

        // Beam and content are ok, so fill beam and prepare the bucket.
        $beam->setIndex(($_POST['BeamiaIndexAtInternetArchive'] == '1') ? $beam::IS_PUBLIC : $beam::IS_PRIVATE);
        $beam->setRemoteIdForItem();
        $beam->setSettings();

        // Beam item up.
        try {
            $curl = $this->_initializeCurl();

            // Old note kept for information about http header.
            // Note that curl_setopt does not seem to work with predefined arrays,
            // which is a real deterent to good code.
            curl_setopt($curl, CURLOPT_HTTPHEADER, $this->_getMetadataHeadersForCurl(true));
            curl_setopt($curl, CURLOPT_URL, $beam->getUrlForMetadataToUpload());
            curl_setopt($curl, CURLOPT_INFILE, $filePointer);
            curl_setopt($curl, CURLOPT_INFILESIZE, strlen($content));

            $beam->saveWithStatus($beam::BEAMING_UP);
            $curlInfo = $this->_execSingleHandle($curl);
        } catch (Exception $e) {
            curl_close($curl);
            $beam->saveWithStatus($beam::FAILED);
            throw new Exception($e->getMessage() . ' (item: ' . $beam->record_id . ')');
        }
        curl_close($curl);

        return true;
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
            throw new Exception(__('Upload to Internet Archive aborted: Could not open temp memory data.'), 1);
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
    private function _checkBeamFile()
    {
        $beam = $this->_beam;

        // Check if file still exists.
        if (!$this->_file) {
            $beam->saveWithStatus($beam::NO_RECORD);
            throw new Exception(__("Beam me up to Internet Archive: File " . $beam->record_id . " doesn't exist."));
        }

        // Check if bucket for the item is created.
        $beamItem = $this->_beam_item;
        if (empty($beam->required_beam_id) || $beam->required_beam_id == '0') {
            throw new Exception(__("Beam me up to Internet Archive: File " . $beam->record_id . " can't be beamed up while parent item is not created."));
        }
        if (!$beamItem) {
            throw new Exception(__("Beam me up to Internet Archive: File " . $beam->record_id . " need a beam for the parent item before it can be uploaded."));
        }
        if (!$beamItem->isBeamedUpOrWaiting()) {
            $beam->saveWithStatus($beam::TO_BEAM_UP_WAITING_BUCKET);
            return false;
        }

        // Check the current status.
        switch ($beam->status) {
            case $beam::TO_BEAM_UP_WAITING_BUCKET:
                $beam->saveWithStatus($beam::TO_BEAM_UP);
                return true;
            case $beam::TO_BEAM_UP:
                return true;
            case $beam::FAILED:
                // A previous creation of a bucket failed, so try it again.
                return true;
            case $beam::NO_RECORD:
                throw new Exception(__("Beam me up to Internet Archive: File " . $beam->record_id . " doesn't exist in a previous check."));
            case $beam::BEAMING_UP:
                // Need a remote status (checked later).
                return false;
            case $beam::BEAMED_UP_WAITING_BUCKET_CREATION:
                // Need to wait end of creation of the bucket.
                // This status is only for item, because we don't send file
                // before end of bucket creation.
                return false;
            case $beam::BEAMED_UP_WAITING_REMOTE:
            case $beam::BEAMED_UP:
                return false;
            default:
                return false;
        }
    }

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
        if (curl_errno($curl) || $result === false) {
            throw new Exception(__('Beam me up to Internet Archive: Upload returns error "' . curl_errno($curl) . ' (' . curl_error($curl) . ')".'));
        }
        return curl_getinfo($curl);
    }

    /**
     * Executes the curl multi handle until there are no outstanding jobs.
     *
     * @return void
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
