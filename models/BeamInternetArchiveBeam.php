<?php

/**
 * A row represent a record to beam up to Internet Archive.
 *
 * @see http://archive.org/help/abouts3.txt
 */
class BeamInternetArchiveBeam extends Omeka_Record_AbstractRecord
{
    const BASE_URL_CHECK = 'http://archive.org';
    const BASE_URL_ARCHIVE = 'http://s3.us.archive.org/';
    const BASE_URL_METADATA = 'http://archive.org/metadata/';
    const BASE_URL_DETAILS = 'http://archive.org/details/';
    const BASE_URL_TASKS = 'http://archive.org/catalog.php?history=1&identifier=';

    // All possible status for a beam.
    const STATUS_TO_BEAM_UP = 'to beam up';
    // This status is only for file.
    const STATUS_TO_BEAM_UP_WAITING_BUCKET = 'to beam up after bucket creation';
    const STATUS_IN_PROGRESS = 'beam up in progress';
    const STATUS_FAILED_TO_BEAM_UP = 'failed to beam up';
    // After upload of a file, Internet Archive needs some time to integrate it.
    const STATUS_COMPLETED_WAITING_BUCKET_CREATION = 'waiting remote bucket creation';
    const STATUS_COMPLETED_WAITING_REMOTE = 'waiting remote processing';
    const STATUS_COMPLETED = 'beamed up';
    const STATUS_NO_RECORD = 'no record';
    const STATUS_ERROR = 'error in record';
    // TODO In a future release.
    const STATUS_TO_UPDATE = 'to update';
    const STATUS_UPDATING = 'processing update';
    // TODO In a future release.
    // Note: To delete a bucket is not allowed on Internet Archive.
    const STATUS_TO_DELETE = 'to delete';
    const STATUS_DELETING = 'processing delete';
    const STATUS_DELETED = 'deleted';

    // All possible remote status.
    const REMOTE_NOT_TO_CHECK = 'N/A';
    const REMOTE_CHECK_FAILED = 'check failed';
    const REMOTE_NO_BUCKET = 'no bucket';
    const REMOTE_IN_PROGRESS_BUCKET_CREATION = 'processing bucket creation';
    const REMOTE_IN_PROGRESS = 'processing outstanding tasks';
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
    public $remote_status = self::REMOTE_NOT_TO_CHECK;
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
        $this->status = self::STATUS_TO_BEAM_UP;
    }

    public function setFileToBeamUp($fileId, $requiredBeamId = 0)
    {
        $this->record_type = 'File';
        $this->record_id = $fileId;
        $this->required_beam_id = $requiredBeamId;
        $this->status = self::STATUS_TO_BEAM_UP;
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
        $this->status = self::STATUS_TO_BEAM_UP;
    }

    /**
     * Set if a record is indexed on search engines (public) or not (private).
     */
    public function setPublic($index)
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
        // TODO check if this remote identifier exists already.

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

        try {
            $this->_beam_item = $this->_getRequiredBeam();
        } catch (Exception_BeamInternetArchiveBeam $e) {
            // Log is already done.
            throw new Exception_BeamInternetArchiveBeam($msg);
        }

        // Don't sanitize full filepath, because we need the separator '/'.
        $remoteId = $this->_beam_item->remote_id . '/' . $this->_sanitizeString($filename);

        // TODO Check if this remote identifier exists already.

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

        // Base metadata of record.
        if (!$settings) {
            $record = $this->_getRecord();

            $title = metadata($record, array('Dublin Core', 'Title'));
            if (!$title) {
                $title = $this->record_type . ' #' . $this->record_id;
            }
            $settings[] = 'x-archive-meta-title:' . $title;

            if ($this->record_type == 'Item' && !empty($record->collection_id)) {
                $collection = get_record_by_id('collection', $record->collection_id);
                $collectionTitle = metadata($collection, array('Dublin Core', 'Title'));
                if ($collectionTitle) {
                    $settings[] = 'x-archive-meta-collection:' . $collection;
                }
            }
        }

        // Add the required generic media type.
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
     * This url is where the file is saved and downloadable (only if beamed up).
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
        try {
            $this->_beam_item = $this->_getRequiredBeam();
        } catch (Exception_BeamInternetArchiveBeam $e) {
            // Log is already done.
            return '';
        }

        return 'https://' . $this->_beam_item->remote_metadata->server . $this->_beam_item->remote_metadata->dir . '/' . $this->remote_metadata->name;
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
     * Check if the record is well formed and ready to be beam up or not.
     *
     * @return boolean.
     */
    public function isReadyToBeamUp()
    {
        $record = $this->_getRecord();
        // Generic check to verify if record still exists.
        if (!$record) {
            // Check to avoid to repeat log.
            if ($this->status != self::STATUS_NO_RECORD) {
                $this->saveWithStatus(self::STATUS_NO_RECORD);
                _log(__('Beam me up to Internet Archive: %s #%d does not exist.', $this->record_type, $this->record_id), Zend_Log::WARN);
            }
            return false;
        }
        // No other check for item.

        // Check required item for file.
        if ($this->record_type == 'File') {
            // Check if bucket for the item is created.
            try {
                $this->_beam_item = $this->_getRequiredBeam();
            } catch (Exception_BeamInternetArchiveBeam $e) {
                // Log is already done.
                return false;
            }

            if ($this->_beam_item->isBeamedUpOrFinishing()) {
                if ($this->status == self::STATUS_TO_BEAM_UP_WAITING_BUCKET) {
                    $beam->saveWithStatus(self::STATUS_TO_BEAM_UP);
                }
            }
            else {
                if ($this->status == self::STATUS_TO_BEAM_UP) {
                    $beam->saveWithStatus(self::STATUS_TO_BEAM_UP_WAITING_BUCKET);
                }
            }
        }

        return ($this->status == self::STATUS_TO_BEAM_UP);
    }

    /**
     * Indicate if record is marked beaming up or not.
     *
     * @return boolean.
     */
    public function isBeamingUp()
    {
        return ($this->status == self::STATUS_IN_PROGRESS);
    }

    /**
     * Indicate if record is marked beamed up or not.
     *
     * @return boolean.
     */
    public function isBeamedUp()
    {
        return ($this->status == self::STATUS_COMPLETED);
    }

    /**
     * Indicate if record is marked already beamed up.
     *
     * @return boolean.
     */
    public function isBeamedUpOrFinishing()
    {
        return ($this->status == self::STATUS_COMPLETED || $this->status == self::STATUS_COMPLETED_WAITING_REMOTE);
    }

    /**
     * It's useless to check a remote server if the record is not uploaded.
     *
     * @return boolean.
     */
    public function isRemoteStatusCheckable()
    {
        return in_array($this->status, array(
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED_WAITING_BUCKET_CREATION,
            self::STATUS_COMPLETED_WAITING_REMOTE,
            self::STATUS_COMPLETED,
            self::STATUS_NO_RECORD,
            self::STATUS_TO_UPDATE,
            self::STATUS_UPDATING,
            self::STATUS_TO_DELETE,
            self::STATUS_DELETING,
            self::STATUS_DELETED,
        ));
    }

    /**
     * Check if the remote server of a beam is ready.
     *
     * @return boolean.
     */
    public function isRemoteReady()
    {
        $this->checkRemoteStatus();
        return ($this->remote_status == self::REMOTE_READY);
    }

    /**
     * Check if the remote server of a beam is ready or available.
     *
     * Once bucket is created, files can be uploaded even if they can't be
     * accessed.
     *
     * @return boolean.
     */
    public function isRemoteAvailable()
    {
        $this->checkRemoteStatus();
        return ($this->remote_status == self::REMOTE_READY || $this->remote_status == self::REMOTE_IN_PROGRESS);
    }

    /**
     * Check if the remote server of a beam is ready or available.
     *
     * Once bucket is created, files can be uploaded even if they can't be
     * accessed. This function avoids multiple check when a batch is processing.
     *
     * @return boolean.
     */
    private function _isRemoteAvailableNoCheck()
    {
        return ($this->remote_status == self::REMOTE_READY || $this->remote_status == self::REMOTE_IN_PROGRESS);
    }

    /**
     * Check remote status and update beam accordingly.
     *
     * The check is done only when the record is uploading or has been uploaded.
     * The check doesn't simply return status, but update it, because metadata
     * of item can be updated at any time, in particular when files are sent.
     *
     * @return remote status.
     */
    public function checkRemoteStatus()
    {
        try {
            if ($this->record_type == 'Item') {
                $this->_checkRemoteStatusForItem();
            }
            else {
                $this->_checkRemoteStatusForFile();
            }
        } catch (Exception_BeamInternetArchiveBeam $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
        } catch (Exception_BeamInternetArchiveConnect $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
        }
        return $this->remote_status;
    }

    /**
     * Check and update status for all files attached to an item.
     */
    public function checkRemoteStatusForFilesOfItem()
    {
        if ($this->record_type != 'Item') {
            return;
        }

        // Check and update status of item only one time.
        $result = $this->isRemoteAvailable();

        // Check files even if remote is not available in order to update their
        // status.
        $beams = $this->_db->getTable('BeamInternetArchiveBeam')->findBeamsOfAttachedFiles($this->id);
        foreach ($beams as $key => $beam) {
            $beam->_checkRemoteStatusForFile(false);
        }
    }

    /**
     * Check and update remote status for an item.
     */
    private function _checkRemoteStatusForItem()
    {
        // Don't try to check remote status if record is not beamed up.
        if (!$this->isRemoteStatusCheckable()) {
            $this->remote_status = self::REMOTE_NOT_TO_CHECK;
            $this->save();
            return;
        }

        // Quick check connection to avoid useless file_get_contents() below.
        if (!$this->_isConnectedToRemote()) {
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Cannot connect the remote site. Check your connection.'));
        }

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
            $this->save();
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Cannot connect the remote site to check url "%s".', $url));
        }

        if ($result == '{}') {
            // A complementary check is needed to know if the bucket is
            // currently being created or if there was an error.

            // We simply need to wait more time for the creation of the bucket.
            try {
                $result = $this->_checkRemoteTasks();
            } catch (Exception_BeamInternetArchiveConnect $e) {
                // Remote status is already updated.
                throw new Exception_BeamInternetArchiveConnect($e->getMessage());
            }
            return;
        }

        // As we get metadata, we remove settings to reduce memory use.
        $this->remote_metadata = json_decode($remoteMetadata);
        $this->settings = array();

        if ($this->remote_metadata->files_count === 0) {
            $this->remote_status = self::REMOTE_IN_PROGRESS;
            $this->saveWithStatus(self::STATUS_COMPLETED_WAITING_REMOTE);
            return;
        }

        if ($this->remote_status != self::REMOTE_READY) {
            $this->remote_status = self::REMOTE_READY;
            _log(__('Beam me up to Internet Archive: Successful upload of %s #%d.', $this->record_type, $this->record_id), Zend_Log::INFO);
        }
        $this->saveWithStatus(self::STATUS_COMPLETED);
    }

    /**
     * Check and update remote status for a file.
     *
     * @param boolean $updateItem If false, don't update status of item before.
     *
     * @todo The remote status is incorrect when it is updated.
     */
    private function _checkRemoteStatusForFile($updateItem = true)
    {
        // Don't try to check remote status if record is not beamed up.
        if (!$this->isRemoteStatusCheckable()) {
            $this->remote_status = self::REMOTE_NOT_TO_CHECK;
            $this->save();
            return;
        }

        // For a file, check status of parent item is needed.
        try {
            $this->_beam_item = $this->_getRequiredBeam();
        } catch (Exception_BeamInternetArchiveBeam $e) {
            // Log is already done.
            throw new Exception_BeamInternetArchiveBeam($e->getMessage());
        }

        $this->remote_checked = date('Y-m-d G:i:s');

        $result = ($updateItem) ?
            $this->_beam_item->isRemoteAvailable() :
            $this->_beam_item->_isRemoteAvailableNoCheck();
        if (!$result) {
            $this->remote_status = self::REMOTE_IN_PROGRESS_BUCKET_CREATION;
            $this->save();
            return;
        }

        // Required beam is ok, so we can check status of beam for file.
        if ($this->_beam_item->remote_metadata->files_count === 0) {
            $this->remote_status = self::REMOTE_IN_PROGRESS;
            $this->save();
            return;
        }

        // We can do a request to the true file or a simple check of metadata.
        // To avoid problem of connection, a simple check of metadata is done.
        $remoteFiles = $this->_beam_item->remote_metadata->files;
        foreach ($remoteFiles as $key => $remoteFile) {
            // Check with remote id, the sanitized version of original filename.
            if ($remoteFile->name == pathinfo($this->remote_id, PATHINFO_BASENAME)) {
                if ($this->status != self::STATUS_COMPLETED) {
                    _log(__('Beam me up to Internet Archive: Successful upload of %s #%d.', $this->record_type, $this->record_id), Zend_Log::INFO);
                }
                $this->remote_metadata = $remoteFile;
                $this->remote_status = self::REMOTE_READY;
                // As we get metadata, we remove settings to reduce memory use.
                $this->settings = array();
                $this->saveWithStatus(self::STATUS_COMPLETED);
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
        if ($this->record_id <= $this->required_beam_id) {
            $this->addError('record_id', __('Invalid record identifier: it cannot be lower than the id of the required beam.'));
        }
        if ($this->record_type == 'File' && (empty($this->required_beam_id) || $this->required_beam_id == '0')) {
            $this->addError('required_beam_id', __('A file cannot be uploaded if its parent item is not uploaded before.'));
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
        if ($this->record_type == 'item') {
            $this->required_beam_id = 0;
            $this->_required_beam = null;
            return $this->_required_beam;
        }

        // Check for file.
        if (!$this->_required_beam) {
            // Avoid to relog.
            if ($this->status == self::STATUS_ERROR) {
                $msg = __('Beam me up to Internet Archive: Status of File #%d is error.', $this->record_id);
                throw new Exception_BeamInternetArchiveBeam($msg);
            }

            if (!$this->required_beam_id) {
                $msg = __('Beam me up to Internet Archive: File #%d needs a beam for the parent item before it can be uploaded.', $this->record_id);
                $this->saveWithStatus(self::STATUS_ERROR);
                _log($msg, Zend_Log::WARN);
                throw new Exception_BeamInternetArchiveBeam($msg);
            }

            $this->_required_beam = get_record_by_id('BeamInternetArchiveBeam', $this->required_beam_id);
            if (!$this->_required_beam) {
                $msg = __("Beam me up to Internet Archive: Beam #%d for item #%d doesn't exist.", $this->required_beam_id, $this->record_id);
                $this->saveWithStatus(self::STATUS_ERROR);
                _log($msg, Zend_Log::WARN);
                throw new Exception_BeamInternetArchiveBeam($msg);
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

        // Quick check connection to avoid useless file_get_contents() below.
        // It's important to check one more time for the next verification.
        if (!$this->_isConnectedToRemote()) {
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Cannot connect the remote site. Check your connection.'));
        }

        $tasksHistory = file_get_contents($url);

        // As we know there is no connection issue, tasksHistory can't be false.
        if ($tasksHistory === false) {
            $this->remote_status = self::REMOTE_NO_BUCKET;
            $this->saveWithStatus(self::STATUS_FAILED_TO_BEAM_UP);
            $this->save();
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Cannot connect the remote server for url "%s".', $url));
        }

        $oldTasks = (strpos($tasksHistory, 'No historical tasks.') === false);
        $newTasks = (strpos($tasksHistory, 'No outstanding tasks.') === false);

        // No bucket created for the url because there are no old nor new tasks.
        if (!$oldTasks && !$newTasks) {
            $this->remote_status = self::REMOTE_NO_BUCKET;
            $this->saveWithStatus(self::STATUS_FAILED_TO_BEAM_UP);
            throw new Exception_BeamInternetArchiveBeam(__('Beam me up to Internet Archive: No bucket exists for item "%s". Check your configuration and your keys.', $this->record_id));
        }

        // No need to do more check: if we are here, that's because metadata are
        // not available. No change of status.
        $this->remote_status = self::REMOTE_IN_PROGRESS_BUCKET_CREATION;
        $this->save();
        return true;
    }

    /**
     * Quick check of connectivity to avoid wasting time.
     */
    private function _isConnectedToRemote() {
        $curl = curl_init(self::BASE_URL_CHECK);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ($response !== false && $httpCode == 200);
    }
}
