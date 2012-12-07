<?php

/**
 * A row represent a record to beam up to Internet Archive.
 *
 * @see http://archive.org/help/abouts3.txt
 */
class BeamInternetArchiveBeam extends Omeka_Record_AbstractRecord
{
    const URL_TO_CHECK = 'http://archive.org';
    const URL_BASE_ARCHIVE = 'http://s3.us.archive.org/';
    const URL_BASE_METADATA = 'http://archive.org/metadata/';
    const URL_BASE_DETAILS = 'http://archive.org/details/';
    const URL_BASE_DOWNLOAD = 'https://archive.org/download/';
    const URL_BASE_TASKS = 'https://archive.org/catalog.php?history=1&identifier=';

    // All possible status for a beam record.
    // Default status is not to beam up.
    const STATUS_NOT_TO_BEAM_UP = 'not to beam up';
    const STATUS_TO_BEAM_UP = 'to beam up';
    // This status is only for file.
    const STATUS_TO_BEAM_UP_WAITING_BUCKET = 'to beam up after bucket creation';
    const STATUS_IN_PROGRESS = 'beam up in progress';
    const STATUS_FAILED_TO_BEAM_UP = 'failed to beam up';
    // After upload of a file, Internet Archive needs some time to integrate it.
    const STATUS_COMPLETED_WAITING_REMOTE = 'waiting remote processing';
    const STATUS_COMPLETED = 'beamed up';
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
    const REMOTE_NOT_APPLICABLE = 'not applicable';
    const REMOTE_CHECK_FAILED = 'check failed';
    const REMOTE_NO_BUCKET = 'no bucket';
    const REMOTE_IN_PROGRESS = 'processing outstanding tasks';
    const REMOTE_READY = 'ready';

    const IS_PRIVATE = 0;
    const IS_PUBLIC = 1;

    public $id;
    public $record_type;
    public $record_id;
    public $required_beam_id = 0;
    public $status = self::STATUS_NOT_TO_BEAM_UP;
    public $public = self::IS_PRIVATE;
    public $settings = array();
    // In Internet archive, the remote identifier is the bucket for item and the
    // the bucket followed by the sanitized filename for file.
    public $remote_id = '';
    public $remote_status = self::REMOTE_NOT_APPLICABLE;
    public $remote_metadata;
    public $remote_checked = '0000-00-00 00:00:00';
    public $modified;

    // Temporary save of current item or file.
    private $_record = null;
    // Temporary save the beam of item, in particular when the beam is a file.
    private $_beam_item = null;
    private $_required_beam = null;

    // Curl is used to check if the bucket exists and to check upload success.
    private $_curl;
    private $_cookie_jar;

    public function setBeamForItem($itemId)
    {
        $this->record_type = 'Item';
        $this->record_id = $itemId;
    }

    public function setItemToBeamUp($itemId)
    {
        $this->record_type = 'Item';
        $this->record_id = $itemId;
        $this->status = self::STATUS_TO_BEAM_UP;
    }

    public function setBeamForFile($fileId)
    {
        $this->record_type = 'File';
        $this->record_id = $fileId;
    }

    public function setBeamForFileWithRequiredBeamId($fileId, $requiredBeamId = 0)
    {
        $this->record_type = 'File';
        $this->record_id = $fileId;
        $this->required_beam_id = $requiredBeamId;
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

    public function setFullBeam()
    {
        try {
            $this->setRemoteId();
            $this->public = (boolean) get_option('beamia_index_at_internet_archive');
            $this->setSettings();
        } catch (Exception_BeamInternetArchiveBeam $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception_BeamInternetArchiveBeam($e->getmessage());
        } catch (Exception_BeamInternetArchiveConnect $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception_BeamInternetArchiveConnect($e->getmessage());
        }
    }

    /**
     * Set if a record is indexed on search engines (public) or not (private).
     */
    public function setPublic($index)
    {
        $this->public = (int) $index;
    }

    /**
     * Set default remote identifier, the prefix and the id by default.
     */
    public function setRemoteId($remoteId = '')
    {
        try {
            if ($this->isBeamForItem()) {
                $this->_setRemoteIdForItem($remoteId);
            }
            else {
                $this->_setRemoteIdForFile($remoteId);
            }
        } catch (Exception_BeamInternetArchiveBeam $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception_BeamInternetArchiveBeam($e->getmessage());
        } catch (Exception_BeamInternetArchiveConnect $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception_BeamInternetArchiveBeam($e->getmessage());
        }
    }

    /**
     * Set default remote identifier, the prefix and the id by default.
     */
    private function _setRemoteIdForItem($remoteId = '')
    {
        if (empty($remoteId)) {
            $remoteId = get_option('beamia_bucket_prefix') . '_' . $this->record_id;
        }
        $remoteId = $this->_sanitizeString($remoteId);

        // Check if this remote identifier exists already.
        $isUsed = $this->checkIfRemoteIdIsUsed($remoteId); 
        if ($isUsed === null) {
            throw new Exception_BeamInternetArchiveConnect(__('Cannot check if identifier "%s" exists already.', $remoteId));
        }

        // Append a serial if this remote id is used.
        if ($isUsed === true) {
            $i = 0;
            while (($result = $this->checkIfRemoteIdIsUsed($remoteId . '_' . (++$i))) === true) {
                if ($result === null) {
                    throw new Exception_BeamInternetArchiveConnect(__('Cannot check if identifier "%s" exists already.', $remoteId));
                }
            }
            $remoteId .= '_' . $i;
        }

        $this->remote_id = $remoteId;
    }

    /**
     * Set remote identifier for file: identifier of item and original_filename.
     */
    private function _setRemoteIdForFile($filename = '')
    {
        if (empty($filename)) {
            $file = $this->_getRecord();
            $filename = $file->original_filename;
        }
        $sanitizedFilename = $this->_sanitizeString($filename);

        try {
            $this->_beam_item = $this->_getRequiredBeam();
        } catch (Exception_BeamInternetArchiveBeam $e) {
            // Log is already done.
            throw new Exception_BeamInternetArchiveBeam($e->getMessage());
        }

        // Check if this remote identifier exists already.
        $isUsed = $this->checkIfRemoteIdIsUsed($this->_beam_item->remote_id . '/' . $sanitizedFilename); 
        if ($isUsed === null) {
            throw new Exception_BeamInternetArchiveConnect(__('Cannot check if identifier "%s" exists already.', $sanitizedFilename));
        }

        // Append a serial if this filename is used.
        if ($isUsed === true) {
            $i = 0;
            while (($result = $this->checkIfRemoteIdIsUsed($this->_beam_item->remote_id . '/' . $sanitizedFilename . '_' . (++$i))) === true) {
                if ($result === null) {
                    throw new Exception_BeamInternetArchiveConnect(__('Cannot check if identifier "%s" exists already.', $sanitizedFilename));
                }
            }
            $sanitizedFilename .= '_' . $i;
        }

        $remoteId = $this->_beam_item->remote_id . '/' . $sanitizedFilename;

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

            if ($this->isBeamForItem() && !empty($record->collection_id)) {
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
        if ($this->isBeamForFile()) {
            $record = $this->_getRecord();
            // Can't use strstr(), because Omeka should be compatible with php
            // 5.2.
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

    public function getRemoteId()
    {
        return $this->remote_id;
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
        return self::URL_BASE_METADATA . $this->remote_id;
    }

    /**
     * This url is used to upload metadata of an item.
     *
     * @return url
     */
    public function getUrlForMetadataToUpload()
    {
        return self::URL_BASE_ARCHIVE . $this->remote_id . '/metadata.html';
    }

    /**
     * This url is used to upload a file.
     * @return url
     */
    public function getUrlForRemoteFileToUpload()
    {
        return self::URL_BASE_ARCHIVE . $this->remote_id;
    }

    /**
     * Get the url of the beam on the remote site, only if beamed up.
     *
     * @return url
     */
    public function getUrlRemote()
    {
        return ($this->isBeamForItem()) ?
            $this->getUrlForRemoteItem() :
            $this->getUrlForRemoteFile();
    }

    /**
     * This url is the main page on Internet Archive (only if beamed up).
     *
     * @return url
     */
    public function getUrlForRemoteItem()
    {
        if ($this->record_type != 'Item') {
            return '';
        }
        if (!$this->isBeamedUp()) {
            return '';
        }
        return self::URL_BASE_DETAILS . $this->remote_id;
    }

    /**
     * This url is where the file is saved and downloadable (only if beamed up).
     *
     * Note that the server can change randomly each time a file is checked.
     *
     * @return url
     */
    public function getUrlForRemoteFile()
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

        // The download url redirects to the true server automatically.
        return self::URL_BASE_DOWNLOAD . $this->remote_metadata->name;
        // return 'https://' . $this->_beam_item->remote_metadata->server . $this->_beam_item->remote_metadata->dir . '/' . $this->remote_metadata->name;
    }

    /**
     * This url is used to get full history of a bucket.
     *
     * @return url
     */
    public function getUrlForTasks()
    {
        if ($this->isNotToBeamUp()) {
            return '';
        }

        if (empty($this->remote_id)) {
            return '';
        }

        if ($this->isBeamForItem()) {
            return self::URL_BASE_TASKS . $this->remote_id;
        }

        $this->_beam_item = $this->_getRequiredBeam();
        return $this->_beam_item->getUrlForTasks();
    }

    /**
     * Indicate if record is an item or not.
     *
     * @return boolean.
     */
    public function isBeamForItem()
    {
        return ($this->record_type == 'Item');
    }

    /**
     * Indicate if record is a file or not.
     *
     * @return boolean.
     */
    public function isBeamForFile()
    {
        return ($this->record_type == 'File');
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
     * Indicate if record should be beamed up.
     *
     * @return boolean.
     */
    public function isNotToBeamUp()
    {
        return ($this->status == self::STATUS_NOT_TO_BEAM_UP);
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
            if ($this->status != self::STATUS_ERROR) {
                $this->saveWithStatus(self::STATUS_ERROR);
                _log(__('Beam me up to Internet Archive: %s #%d does not exist.', $this->record_type, $this->record_id), Zend_Log::WARN);
            }
            return false;
        }
        // No other check for item.

        // Check required item for file.
        if ($this->isBeamForFile()) {
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
     * Indicate if record is marked already created or creating.
     *
     * @return boolean.
     */
    public function isAlreadyCreated()
    {
        return in_array($this->status, array(
            BeamInternetArchiveBeam::STATUS_IN_PROGRESS,
            BeamInternetArchiveBeam::STATUS_COMPLETED_WAITING_REMOTE,
            BeamInternetArchiveBeam::STATUS_COMPLETED,
            BeamInternetArchiveBeam::STATUS_UPDATING,
            BeamInternetArchiveBeam::STATUS_TO_UPDATE,
            BeamInternetArchiveBeam::STATUS_TO_DELETE,
            BeamInternetArchiveBeam::STATUS_DELETING,
            BeamInternetArchiveBeam::STATUS_DELETED,
        ));
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
     * Indicate if a beam is correct.
     *
     * @return boolean.
     */
    public function isError()
    {
        return ($this->status == self::STATUS_ERROR);
    }

    /**
     * Indicate if a record can be checked, because it's useless to check a
     * remote server if the record is not uploaded.
     *
     * @return boolean.
     */
    public function isRemoteStatusCheckable()
    {
        return in_array($this->status, array(
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED_WAITING_REMOTE,
            self::STATUS_COMPLETED,
            self::STATUS_TO_UPDATE,
            self::STATUS_UPDATING,
            self::STATUS_TO_DELETE,
            self::STATUS_DELETING,
            self::STATUS_DELETED,
        ));
    }

    /**
     * Indicate if the remote was checked.
     *
     * @return boolean.
     */
    public function isRemoteChecked()
    {
        return ($this->remote_checked != -1);
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
            if ($this->isBeamForItem()) {
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
        $beams = $this->_db
            ->getTable('BeamInternetArchiveBeam')
            ->findBeamsOfAttachedFiles($this->id);
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
            $this->remote_status = self::REMOTE_NOT_APPLICABLE;
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
            try {
                $result = $this->_checkRemoteTasksForItem();
            } catch (Exception_BeamInternetArchiveConnect $e) {
                // Remote status is already updated.
                throw new Exception_BeamInternetArchiveConnect($e->getMessage());
            }
            return;
        }

        $this->remote_metadata = json_decode($remoteMetadata);
        if (!isset($this->remote_metadata->files_count)) {
            $this->remote_status = self::REMOTE_NO_BUCKET;
            $this->saveWithStatus(self::STATUS_FAILED_TO_BEAM_UP);
            throw new Exception_BeamInternetArchiveBeam(__('Beam me up to Internet Archive: No bucket exists for item "%d". Check your configuration and your keys.', $this->record_id));
        }

        // As we get metadata, we remove settings to reduce memory use.
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
     * Check creation of a bucket for an item.
     *
     * @return true|null Success or see status in $this->remote_status.
     */
    private function _checkRemoteTasksForItem() {
        // Quick check connection to avoid useless file_get_contents() below.
        // It's important to check one more time for the next verification.
        if (!$this->_isConnectedToRemote()) {
            $this->remote_status = self::REMOTE_CHECK_FAILED;
            $this->save();
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Cannot connect the remote site. Check your connection.'));
        }

        $tasks = $this->_getRemoteTasks();

        // Login or connection error. So progress is not available.
        if ($tasks === null) {
            $this->remote_status = self::REMOTE_IN_PROGRESS;
            $this->save();
            return false;
        }

        // As we know there is no connection issue, tasks can't be empty.
        if ($tasks === false) {
            $this->remote_status = self::REMOTE_NO_BUCKET;
            $this->saveWithStatus(self::STATUS_FAILED_TO_BEAM_UP);
            $this->save();
            throw new Exception_BeamInternetArchiveConnect(__('Beam me up to Internet Archive: Cannot connect the remote server for url "%s".', $url));
        }

        $oldTasks = (strpos($tasks, 'No historical tasks.') === false);
        $newTasks = (strpos($tasks, 'No outstanding tasks.') === false);

        // No bucket created for the url because there are no old nor new tasks.
        if (!$oldTasks && !$newTasks) {
            $this->remote_status = self::REMOTE_NO_BUCKET;
            $this->saveWithStatus(self::STATUS_FAILED_TO_BEAM_UP);
            throw new Exception_BeamInternetArchiveBeam(__('Beam me up to Internet Archive: No bucket exists for item "%d". Check your configuration and your keys.', $this->record_id));
        }

        // There are new tasks, but no old tasks, so the bucket is creating.
        if (!$oldTasks && $newTasks) {
            $this->remote_status = self::REMOTE_IN_PROGRESS;
        }

        // There are old and new tasks, so bucket is ready for files.
        elseif ($oldTasks && $newTasks) {
            $this->remote_status = self::REMOTE_IN_PROGRESS;
        }

        // There are old tasks but no new ones so no upload is in progress.
        elseif ($oldTasks && !$newTasks) {
            $this->remote_status = self::REMOTE_READY;
        }
        $this->save();

        return true;
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
            $this->remote_status = self::REMOTE_NOT_APPLICABLE;
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
            $this->remote_status = self::REMOTE_IN_PROGRESS;
            $this->save();
            return;
        }

        // Required beam is ok, so we can check status of beam for file.
        // We check the files count first and don't use pending_tasks.
        if ($this->_beam_item->remote_metadata->files_count === 0) {
            if ($this->_beam_item->remote_status === self::REMOTE_READY) {
                $this->remote_status = self::REMOTE_NOT_APPLICABLE;
                $this->saveWithStatus(self::STATUS_FAILED_TO_BEAM_UP);
                return;
            }
            $this->remote_status = self::REMOTE_IN_PROGRESS;
            $this->save();
            return;
        }

        // We can do a request to the true file or a simple check of metadata.
        // To avoid problem of connection, a simple check of metadata is done.
        foreach ($this->_beam_item->remote_metadata->files as $remoteFile) {
            // Check with remote id, the sanitized version of original filename.
            if ($remoteFile->name == pathinfo($this->remote_id, PATHINFO_BASENAME)) {
                if ($this->status != self::STATUS_COMPLETED) {
                    _log(__('Beam me up to Internet Archive: Successful upload of "%s #%d".', $this->record_type, $this->record_id), Zend_Log::INFO);
                }
                $this->remote_metadata = $remoteFile;
                $this->remote_status = self::REMOTE_READY;
                // As we get metadata, we remove settings to reduce memory use.
                $this->settings = array();
                $this->saveWithStatus(self::STATUS_COMPLETED);
                return;
            }
        }

        // Check against pending tasks of the item. 
        if (isset($this->_beam_item->remote_metadata->pending_tasks) && $this->_beam_item->remote_metadata->pending_tasks) {
            $this->remote_status = self::REMOTE_IN_PROGRESS;
            $this->save();
            return;
        }

        // Check against the identifier. 
        if ($this->checkIfRemoteIdIsUsed()) {
            $this->remote_status = self::REMOTE_IN_PROGRESS;
            $this->save();
            return;
        }

        // As there is no pending tasks, this file is not uploading.
        // Problem is that pending tasks list is not updated quickly.
        // So need to check only http code 200?
        // Currently, we don't update status as a fail.
        $this->remote_status = self::REMOTE_NOT_APPLICABLE;
        $this->save();
    }

    /**
     * Check if an identifier is already used on remote server.
     *
     * This function uses the fact that http://archive.org/download/$identifier 
     * redirects to the true server if the item or the file exists.
     *
     * @param string|null $identifier The string to check. If null, check the
     *   default one.
     *
     * @return boolean|null A boolean indicates that the identifier exists or. 
     *   not. A null indicates that the connection and the check fail.
     */
    public function checkIfRemoteIdIsUsed()
    {
        if (!$this->_isConnectedToRemote()) {
            return null;
        }

        if (empty($identifier)) {
            $identifier = $this->getRemoteId();
        }

        if ($this->isBeamForItem()) {
            return $this->_isRemoteIdUsedForItem($identifier);
        }

        elseif ($this->isBeamForFile()) {
            // Need to check item before file.
            try {
                $this->_beam_item = $this->_getRequiredBeam();
            } catch (Exception_BeamInternetArchiveBeam $e) {
                // Log is already done.
                throw new Exception_BeamInternetArchiveBeam($msg);
            }
            $remoteForRequiredBeam = $this->_beam_item->checkIfRemoteIdIsUsed();
            if (!$remoteForRequiredBeam) {
                return $remoteForRequiredBeam;
            }

            return $this->_isRemoteIdUsedForFile($identifier);
        }
    }

    /**
     * Check if a bucket is already used on remote server.
     *
     * This function uses the fact that http://archive.org/download/$identifier 
     * redirects to the true server if the bucket exists.
     *
     * @param string|null $identifier The string to check.
     *
     * @return boolean|null A boolean indicates that the identifier exists or 
     *   not. A null indicates that the connection and the check fail.
     */
    private function _isRemoteIdUsedForItem($identifier)
    {
        $curl = curl_init(BeamInternetArchiveBeam::URL_BASE_DOWNLOAD . $identifier);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        curl_close($curl);
        // Connection error.
        if ($output === false) {
            return null;
        }
        return ($curlInfo['http_code'] == 302
            && substr(parse_url($curlInfo['redirect_url'], PHP_URL_HOST), -12) == '.archive.org');
    }

    /**
     * Check if a file is already on remote server. Bucket is already checked.
     *
     * Check is done against the list of files and not the true file.
     *
     * @param string|null $identifier The string to check.
     *
     * @return boolean|null A boolean indicates that the identifier exists or 
     *   not. A null indicates that the connection and the check fail.
     */
    private function _isRemoteIdUsedForFile($identifier)
    {
        $bucket = pathinfo($identifier, PATHINFO_DIRNAME);
        if (empty($bucket) || $bucket == '.') {
            return true;
        }
        $curl = curl_init(BeamInternetArchiveBeam::URL_BASE_DOWNLOAD . $bucket . '/' . $bucket . '_files.xml');
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        curl_close($curl);
        // Connection error.
        if ($curlInfo['http_code'] != 302 
                || substr(parse_url($curlInfo['redirect_url'], PHP_URL_HOST), -12) != '.archive.org'
            ) {
            return null;
        }

        // Now check filename in list of files available via the redirected url.
        $filename = pathinfo($identifier, PATHINFO_BASENAME);
        if (empty($filename)) {
            return true;
        }
        $curl = curl_init($curlInfo['redirect_url']);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        curl_close($curl);
        if ($output === false || $curlInfo['http_code'] != 200) {
            return null;
        }
        return (strpos($output, '<file name="' . $filename . '"') !== false);
    }

    /**
     * Quick check of connectivity to avoid wasting time.
     */
    private function _isConnectedToRemote()
    {
        $curl = curl_init(self::URL_TO_CHECK);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ($response !== false && $httpCode == 200);
    }

    /**
     * @return list of tasks of an item.
     */
    private function _getRemoteTasks($identifier)
    {
        $result = $this->_loginToRemoteServer();
        if ($result === false) {
            return null;
        }

        $url = URL_BASE_TASKS . $identifier;

        if ($this->_curl === null) {
            $this->_curl = curl_init();
        }
        curl_setopt($this->_curl, CURLOPT_URL, $url);
        curl_setopt($this->_curl, CURLOPT_COOKIE, 'test-cookie=1');
        curl_setopt($this->_curl, CURLOPT_COOKIEJAR, $this->_cookie_jar);
        curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->_curl, CURLOPT_HTTPGET, true);
        curl_setopt($this->_curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, true);
        $output = curl_exec($this->_curl);
        $curlInfo = curl_getinfo($this->_curl);
        curl_close($this->_curl);
        $this->_curl = null;
        if ($output === false) {
            return null;
        }
        if ($curlInfo['http_code'] != 200) {
            return false;
        }

        return $output;
    }

    /**
     * Login to remote site and create a persistant cookie to stay connected.
     */
    private function _loginToRemoteServer()
    {
        if (empty(get_option('Beamia_username')) || empty(get_option('Beamia_password'))) {
            return false;
        }

        // All we really care about here is filling the cookie jar.
        if (!isset($this->_cookie_jar) || !file_exists($this->_cookie_jar)) {
            $this->_cookie_jar = tempnam(sys_get_temp_dir(), 'OmekaCk');

            // Gather our POST fields
            $fields = array(
                'username'  => urlencode(get_option('Beamia_username')),
                'password'  => urlencode(get_option('Beamia_password')),
                'openid'  => '',
                'remember'  => 'CHECKED',
                'referer'  => urlencode('https://www.archive.org/account/login.php'),
                'submit'  => urlencode('Log in')
            );

            $post_data = '';
            // Url-ify the data for the POST.
            foreach ($fields as $key => $value) {
                $post_data .= $key . '=' . $value . '&';
            }
            rtrim($post_data, '&');

            // Do POST.
            if (!isset($this->_curl)) {
                $this->_curl = curl_init();
            }
            curl_setopt($this->_curl, CURLOPT_URL, 'https://archive.org/account/login.php');
            curl_setopt($this->_curl, CURLOPT_POST, count($fields));
            curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($this->_curl, CURLOPT_COOKIEJAR, $this->_cookie_jar);
            curl_setopt($this->_curl, CURLOPT_COOKIE, 'test-cookie=1');
            curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->_curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, true);

            // Execute post.
            $output = curl_exec($this->_curl);
            if (preg_match('/Invalid password or username/', $output)) {
                return false;
            }
            return true;
        }
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
        if ($this->isBeamForItem()) {
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
        // Don't do this check before the record is saved (auto-incremented id).
        if (!empty($this->id) && $this->id <= $this->required_beam_id) {
            $this->addError('record_id', __('Invalid record identifier: it cannot be lower than the id of the required beam.'));
        }
        if ($this->isBeamForFile() && (empty($this->required_beam_id) || $this->required_beam_id == '0')) {
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
}
