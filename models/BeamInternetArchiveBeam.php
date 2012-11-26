<?php

/**
 * A row represent a record to beam up to Internet Archive.
 *
 * @see http://archive.org/help/abouts3.txt
 */
class BeamInternetArchiveBeam extends Omeka_Record_AbstractRecord
{
    const BASE_URL_ARCHIVE = 'http://s3.us.archive.org/';
    const BASE_URL_METADATA = 'http://archive.org/metadata/';
    const BASE_URL_DETAILS = 'http://archive.org/details/';
    const BASE_URL_TASKS = 'http://archive.org/catalog.php?history=1&identifier=';

    // All possible status for a beam.
    const TO_BEAM_UP = 'to beam up';
    // This status is only for file.
    const TO_BEAM_UP_WAITING_BUCKET = 'to beam up after bucket creation';
    const BEAMING_UP = 'processing beam up';
    // After upload of a file, Internet Archive needs some time to integrate it.
    const BEAMED_UP_WAITING_BUCKET_CREATION = 'waiting remote bucket creation';
    // As the request of creation was successful, other commands are possible.
    const BEAMED_UP_WAITING_REMOTE = 'waiting remote processing';
    const BEAMED_UP = 'beamed up';
    const FAILED = 'failed';
    const NO_RECORD = 'no record';
    // TODO In a future release.
    const TO_UPDATE = 'to update';
    const UPDATING = 'processing update';
    const UPDATED = 'updated';
    // TODO In a future release.
    // Note: Delete is not allowed on Internet Archive.
    const TO_DELETE = 'to delete';
    const DELETING = 'processing delete';
    const DELETED = 'deleted';

    // All possible remote status.
    const REMOTE_CHECK_FAILED = 'check failed';
    const REMOTE_NO_BUCKET = 'no bucket';
    const REMOTE_PROCESSING_BUCKET_CREATION = 'processing bucket creation';
    const REMOTE_PROCESSING = 'processing outstanding tasks';
    const REMOTE_READY = 'ready';
    const REMOTE_UNKNOWN = 'unknown';

    const IS_PRIVATE = 0;
    const IS_PUBLIC = 1;

    public $id;
    public $record_type;
    public $record_id;
    public $required_beam_id = 0;
    public $status = null;
    public $public = self::IS_PRIVATE;
    public $settings = array();
    // In Internet archive, the remote identifier is the bucket for item and the
    // the bucket followed by the sanitized filename for file.
    public $remote_id = '';
    public $remote_status = '';
    public $remote_metadata;
    public $remote_checked = '0000-00-00 00:00:00';
    public $modified;

    // Temporary save of current item or file.
    private $_record = null;
    // Temporary save the beam of item, in particular when the beam is a file.
    private $_beam_item = null;
    private $_required_beam = null;

    public function setItem($itemId)
    {
        $this->record_type = 'Item';
        $this->record_id = $itemId;
    }

    public function setFile($fileId)
    {
        $this->record_type = 'File';
        $this->record_id = $fileId;
    }

    public function setItemToBeamUp($itemId)
    {
        $this->record_type = 'Item';
        $this->record_id = $itemId;
        $this->status = self::TO_BEAM_UP;
    }

    public function setFileToBeamUp($fileId, $requiredBeamId = 0)
    {
        $this->record_type = 'File';
        $this->record_id = $fileId;
        $this->required_beam_id = $requiredBeamId;
        $this->status = self::TO_BEAM_UP;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function saveWithStatus($status)
    {
        $this->status = $status;
        $this->save();
    }

    public function setToBeamUp()
    {
        $this->status = self::TO_BEAM_UP;
    }

    /**
     * Set if a record is indexed on search engines (public) or not (private).
     */
    public function setIndex($index)
    {
        $this->public = (int) $index;
    }

    /**
     * Set default remote identifier, prefix and id by default.
     */
    public function setRemoteIdForItem($remoteId = '')
    {
        if (empty($remoteId)) {
            $remoteId = get_option('beamia_bucket_prefix') . '_' . $this->record_id;
        }
        $remoteId = $this->_sanitizeString($remoteId);
        // TODO check if this remote identifier exists.

        $this->remote_id = $remoteId;
    }

    /**
     * Set remote identifier for file: identifier of item and original_filename.
     */
    public function setRemoteIdForFile($filename = '')
    {
        if (empty($filename)) {
            $file = $this->_getRecord();
            $filename = $file->original_filename;
        }

        if (!$this->required_beam_id) {
            throw new Exception(__("Beam me up to Internet Archive: File " . $this->record_id . " need a beam for the parent item before it can be uploaded."));
        }

        $requiredBeam = $this->_getRequiredBeam();
        if (!$requiredBeam) {
            throw new Exception(__("Beam me up to Internet Archive: Beam " . $this->required_beam_id . " for item " . $this->record_id . " doesn't exist."));
        }

        // Don't sanitize full filepath, because we need the separator '/'.
        $remoteId = $requiredBeam->remote_id . '/' . $this->_sanitizeString($filename);

        // TODO Check if this remote identifier exists.

        $this->remote_id = $remoteId;
    }

    /**
     * Set the list of metadata of the record (Dublin Core).
     */
    public function setSettings()
    {
        $settings = fire_plugin_hook('beamia_set_metadata', array(
            'record_type' => $this->record_type,
            'record_id' => $this->record_id,
        ));

        if (!$settings) {
            $record = $this->_getRecord();

            $title = metadata($record, array('Dublin Core', 'Title'));
            if (!$title) {
                $title = $this->record_type . ' ' . $this->record_id;
            }

            $settings = array(
                'x-archive-meta-collection:' . 'test_collection',//'collection of the record',
                'x-archive-meta-title:' . $title,
            );
        }

        // Add the generic media type.
        $settings[] = 'x-archive-meta-mediatype:' . $this->_getMediaType();

        $this->settings = $settings;
    }

    /**
     * Get the Internet Archive media type.
     * Allowed main media types are: movies, texts, audio, education.
     *
     * @todo Use mime type and return the expected type for Internet Archive.
     */
    private function _getMediaType()
    {
        // Use media type of the file.
        if ($this->record_type == 'file') {
            $record = $this->_getRecord();
            // Can't use strstr(), because Omeka is compatible with php 5.2.
            $mainMimeType = substr($this->_record->mime_type, 0, strpos($this->_record->mime_type, '/'));
            switch ($mainMimeType) {
                case 'text':
                    return 'texts';
                case 'video':
                    return 'movies';
                case 'audio':
                    return 'audio';
            }
        }
        // For item and when type can't be defined.
        return get_option('beamia_media_type');
    }

    public function getIndex()
    {
        return $this->public;
    }

    public function getNoIndex()
    {
        return (int) !$this->public;
    }

    public function getSettings()
    {
        return $this->settings;
    }

    public function getRemoteStatus()
    {
        return $this->remote_status;
    }

    public function getRemoteMetadata()
    {
        return $this->remote_metadata;
    }

    /**
     * This url is where the metadata of the item are available. It can be used
     * to check if the bucket of an item is created or not and to get infos
     * about upload of files.
     *
     * @return url
     */
    public function getUrlForMetadata()
    {
        return self::BASE_URL_METADATA . $this->remote_id;
    }

    /**
     * This url is used to upload metadata of an item.
     *
     * @return url
     */
    public function getUrlForMetadataToUpload()
    {
        return self::BASE_URL_ARCHIVE . $this->remote_id . '/metadata.html';
    }

    /**
     * This url is used to upload a file.
     * @return url
     */
    public function getUrlForFileToUpload()
    {
        return self::BASE_URL_ARCHIVE . $this->remote_id;
    }

    /**
     * This url is the main page on Internet Archive.
     *
     * @return url
     */
    public function getUrlForItem()
    {
        if ($this->record_type != 'Item') {
            return '';
        }
        return self::BASE_URL_DETAILS . $this->remote_id;
    }

    /**
     * This url is where the file is saved and downloadable.
     *
     * @return url
     */
    public function getUrlForFile()
    {
        if ($this->record_type != 'File') {
            return '';
        }
        if (!$this->isBeamedUp()) {
            return '';
        }

        // No other check: if file is beamed up, there is a beam and metadata.
        $beamItem = $this->_getRequiredBeam();
        return 'https://' . $beamItem->remote_metadata->server . $beamItem->remote_metadata->dir . '/' . $this->remote_metadata->name;
    }

    /**
     * This url is used to get full history of a bucket.
     *
     * @return url
     */
    public function getUrlForTasks()
    {
        return self::BASE_URL_TASKS . $this->remote_id;
    }

    /**
     * Indicate if record is public or not.
     *
     * @return boolean.
     */
    public function isPublic()
    {
        return ($this->public == self::IS_PUBLIC);
    }

    /**
     * Indicate if record is marked to be beam up or not.
     *
     * @return boolean.
     */
    public function isToBeamUp()
    {
        return ($this->status == self::TO_BEAM_UP);
    }

    /**
     * Indicate if record is marked beaming up or not.
     *
     * @return boolean.
     */
    public function isBeamingUp()
    {
        return ($this->status == self::BEAMING_UP);
    }

    /**
     * Indicate if record is marked beamed up or not.
     *
     * @return boolean.
     */
    public function isBeamedUp()
    {
        return ($this->status == self::BEAMED_UP);
    }

    /**
     * Indicate if record is marked already beamed up.
     *
     * @return boolean.
     */
    public function isBeamedUpOrWaiting()
    {
        return ($this->status == self::BEAMED_UP || $this->status == self::BEAMED_UP_WAITING_REMOTE);
    }

    /**
     * Once bucket is created, files can be uploaded even if they can't be
     * accessed.
     *
     * @param boolean $update If false, don't update of item before.
     *
     * @return boolean.
     */
    public function isRemoteReady($update = true)
    {
        if ($update) {
            $this->checkRemoteStatus();
        }
        return ($this->remote_status == self::REMOTE_READY);
    }

    /**
     * Once bucket is created, files can be uploaded even if they can't be
     * accessed.
     *
     * @param boolean $update If false, don't update of item before.
     *
     * @return boolean.
     */
    public function isRemoteAvailable($update = true)
    {
        if ($update) {
            $this->checkRemoteStatus();
        }
        return ($this->remote_status == self::REMOTE_READY || $this->remote_status == self::REMOTE_PROCESSING);
    }

    /**
     * Check remote status and update beam accordingly.
     *
     * @return remote status.
     */
    public function checkRemoteStatus()
    {
        if ($this->record_type == 'Item') {
            $this->_checkRemoteStatusForItem();
        }
        else {
            $this->_checkRemoteStatusForFile();
        }

        return $this->remote_status;
    }

    /**
     * @return array of beams of files attached to an item to be beamed up.
     */
    public function checkRemoteStatusForFilesOfItem()
    {
        if ($this->record_type != 'Item') {
            return;
        }

        // Check status of item only one time.
        $itemRemoteStatus = $this->isRemoteAvailable();

        $beams = $this->_db->getTable('BeamInternetArchiveBeam')->findBeamsOfAttachedFiles($this->id);
        foreach ($beams as $key => $beam) {
            $beam->_checkRemoteStatusForFile(false);
        }
    }

    private function _checkRemoteStatusForItem()
    {
        // Don't simply return status, but update it, because metadata of item
        // can be updated in particular when files are sent.
        $url = $this->getUrlForMetadata();

        // Generally, creation of a bucket takes some seconds, but it can be
        // some minutes and even some hours in case of maintenance.
        $maxTime = time() + get_option('beamia_max_time_item');
        while (($result = preg_replace('/\s/', '', $remoteMetadata = file_get_contents($url))) == '{}' && time() < $maxTime) {
            sleep(1);
        }

        $this->remote_checked = date('Y-m-d G:i:s');
        if ($remoteMetadata === false) {
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            // Previous metadata are kept, if any, because it can be a simple
            // problem of connection. Previous status is kept too.
            $this->save();
            throw new Exception(__('Beam me up to Internet Archive: Cannot connect the remote site to check url "' . $url . '"'));
        }

        if ($result == '{}') {
            // A complementary check is needed to know if the bucket is
            // currently being created or if there is an error.

            // We simply need to wait more time for the creation of the bucket.
            try {
                $result = $this->_checkRemoteTasks();
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
            return;
        }

        $this->remote_metadata = json_decode($remoteMetadata);

        if ($this->remote_metadata->files_count === 0) {
            $this->remote_status = self::REMOTE_PROCESSING;
            $this->saveWithStatus(self::BEAMED_UP_WAITING_REMOTE);
            return;
        }

        $this->remote_status = self::REMOTE_READY;
        // As we get metadata, we remove settings to reduce memory use.
        $this->settings = array();
        $this->saveWithStatus(self::BEAMED_UP);
    }

    /**
     * Check remote status for a file.
     *
     * @param boolean $updateItem If false, don't update status of item before.
     *
     * @return void.
     *
     * @todo The remote status is incorrect when it is updated.
     */
    private function _checkRemoteStatusForFile($updateItem = true)
    {
        // For a file, check status of parent item is needed.
        try {
            $this->_beam_item = $this->_getRequiredBeam();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $this->remote_checked = date('Y-m-d G:i:s');

        if (!$this->_beam_item->isRemoteAvailable($updateItem)) {
            $this->remote_status = self::REMOTE_PROCESSING_BUCKET_CREATION;
            $this->save();
            return;
        }

        // Required beam is ok, so we can check status of beam for file.
        if ($this->_beam_item->remote_metadata->files_count === 0) {
            $this->remote_status = self::REMOTE_PROCESSING;
            $this->save();
            return;
        }

        // We can do a request to the true file or a simple check of metadata.
        $remoteFiles = $this->_beam_item->remote_metadata->files;
        foreach ($remoteFiles as $key => $remoteFile) {
            $file = get_record_by_id($this->record_type, $this->record_id);
            if ($remoteFile->name == $file->original_filename) {
                $this->remote_metadata = $remoteFile;
                $this->remote_status = self::REMOTE_READY;
                // As we get metadata, we remove settings to reduce memory use.
                $this->settings = array();
                $this->saveWithStatus(self::BEAMED_UP);
                return;
            }
        }

        // No metadata, so waiting for beam.
        $this->remote_metadata = null;
        $this->remote_status = self::REMOTE_UNKNOWN;
        $this->save();
        // No change of status of beam.
    }

    protected function beforeSave($args)
    {
        if (!$this->_isSerialized($this->settings)) {
            $this->settings = serialize($this->settings);
        }
        if (is_object($this->remote_metadata)) {
            $this->remote_metadata = json_encode($this->remote_metadata);
        }
    }

    /**
     * Validate the record prior to saving.
     */
    protected function _validate()
    {
        if (empty($this->record_type)) {
            $this->addError('record_type', __('All records must have a record type.'));
        }
        if ($this->record_id < 1) {
            $this->addError('record_id', __('Invalid record identifier.'));
        }
    }

    protected function afterSave($args)
    {
        if ($this->_isSerialized($this->settings)) {
            $this->settings = unserialize($this->settings);
        }
        if (!is_object($this->remote_metadata)) {
            $this->remote_metadata = json_decode($this->remote_metadata);
        }
    }

    private function _isSerialized($s)
    {
        if (!is_string($s)) {
            return false;
        }
        return (($s === 'b:0;') || (@unserialize($s) !== false));
    }

    /**
     * Returns a sanitized and unaccentued string for folder or file path.
     *
     * @param string $string The string to sanitize.
     *
     * @return string The sanitized string to use as a folder or a file name.
     * @see http://archive.org/about/faqs.php.
     */
    private function _sanitizeString($string)
    {
        $string = trim(strip_tags($string));
        $string = htmlentities($string, ENT_NOQUOTES, 'utf-8');
        $string = preg_replace('#\&([A-Za-z])(?:uml|circ|tilde|acute|grave|cedil|ring)\;#', '\1', $string);
        $string = preg_replace('#\&([A-Za-z]{2})(?:lig)\;#', '\1', $string);
        $string = preg_replace('#\&[^;]+\;#', '_', $string);
        $string = preg_replace('/[^[:alnum:]\(\)\[\]_\-\.#~@+:]/', '_', $string);
        return preg_replace('/_+/', '_', $string);
    }

    /**
     * Get/set the current record.
     *
     * @return current record.
     */
    private function _getRecord()
    {
        if (!$this->_record) {
            $this->_record = get_record_by_id($this->record_type, $this->record_id);
        }
        return $this->_record;
    }

    /**
     * Get/set the required beam.
     *
     * @return current record.
     */
    private function _getRequiredBeam()
    {
        if (!$this->_required_beam) {
            if (!$this->required_beam_id) {
                $this->_required_beam = null;
            }
            else {
                $this->_required_beam = get_record_by_id('BeamInternetArchiveBeam', $this->required_beam_id);
                if (!$this->_required_beam) {
                    throw new Exception(__("Beam me up to Internet Archive: Beam " . $this->required_beam_id . " doesn't exist."));
                }
            }
        }
        return $this->_required_beam;
    }

    /**
     * Check creation of a bucket for an item.
     *
     * @return true|null Success or see status in $this->remote_status.
     */
    private function _checkRemoteTasks() {
        $url = $this->getUrlForTasks();

        // Quick check: Internet Archive respond 409 during creation of bucket.
        // This error is good: this bucket is currently being created because we
        // can't access to this url now!
        if ($this->_checkUrlAndGetHttpCode($url) == 409) {
            $this->remote_status = self::REMOTE_PROCESSING_BUCKET_CREATION;
            $this->saveWithStatus(self::BEAMED_UP_WAITING_BUCKET_CREATION);
            return true;
        }

        $tasksHistory = file_get_contents($url);

        // Connection problem.
        if ($tasksHistory === false) {
            // Previous metadata are kept, if any, because it can be a simple
            // problem of connection.
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception(__('Beam me up to Internet Archive: Cannot connect the remote server for url "' . $url . '".'));
        }

        $oldTasks = (strpos($tasksHistory, 'No historical tasks.') === false);
        $newTasks = (strpos($tasksHistory, 'No outstanding tasks.') === false);

        // No bucket created for this url, because there are no old and no new
        // tasks.
        if (!$oldTasks && !$newTasks) {
            // No metadata can exist.
            $this->remote_metadata = null;
            $this->remote_status = self::REMOTE_NO_BUCKET;
            $this->saveWithStatus(self::FAILED);
            throw new Exception(__('Beam me up to Internet Archive: No bucket exists for item "' . $this->record_id . '". Check your configuration and your keys.'));
        }

        // No need to do more check: if we are here, that's because metadata are
        // not available.
        $this->remote_status = self::REMOTE_PROCESSING_BUCKET_CREATION;
        $this->saveWithStatus(self::BEAMED_UP_WAITING_BUCKET_CREATION);
        return true;
    }

    /**
     * Return http status of a request.
     */
    private function _checkUrlAndGetHttpCode($url) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return $httpCode;
    }
}
