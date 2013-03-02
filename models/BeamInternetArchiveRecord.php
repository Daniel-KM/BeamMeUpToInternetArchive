<?php

/**
 * A row represent a record to beam up to Internet Archive.
 *
 * @see http://archive.org/help/abouts3.txt
 */
class BeamInternetArchiveRecord extends Omeka_Record_AbstractRecord
{
    const URL_TO_CHECK = 'http://archive.org';
    const URL_BASE_ARCHIVE = 'http://s3.us.archive.org/';
    const URL_BASE_METADATA = 'http://archive.org/metadata/';
    const URL_BASE_DETAILS = 'http://archive.org/details/';
    const URL_BASE_DOWNLOAD = 'https://archive.org/download/';
    const URL_BASE_TASKS = 'https://archive.org/catalog.php?history=1&identifier=';

    // All possible status for a beam record, chosed by user.
    // Default status is "not to beam up". Its process can be "completed" only.
    const STATUS_NOT_TO_BEAM_UP = 'not to beam up';
    const STATUS_TO_BEAM_UP = 'to beam up';
    // Update is very similar to 'to beam up', except that remote exists.
    const STATUS_TO_UPDATE = 'to update';
    const STATUS_TO_REMOVE = 'to remove';

    // All possible operation status.
    // As default status is not to beam up, the default process is "completed".
    const PROCESS_COMPLETED = 'completed';
    // A change has been made on the status of the record.
    const PROCESS_QUEUED = 'queued';
    // Before any upload, a bucket need to be created. For an item, this status
    // is applied only when the creation of the bucket succeeded. For an item,
    // this status goes back to "queued" when the creation is confirmed and
    // metadata are completed. For a file, it is used when the bucket of the
    // required record is not ready.
    const PROCESS_QUEUED_WAITING_BUCKET = 'queued waiting bucket creation';
    // A job is working on this record.
    const PROCESS_IN_PROGRESS = 'processing';
    // After upload of a file, Internet Archive needs some time to integrate it.
    const PROCESS_IN_PROGRESS_WAITING_REMOTE = 'processing remotely';
    // Process can fail for two reasons.
    const PROCESS_FAILED_CONNECTION = 'failed after connection error';
    const PROCESS_FAILED_RECORD = 'failed after record error';

    const IS_PRIVATE = 0;
    const IS_PUBLIC = 1;

    // Managed record types.
    const RECORD_TYPE_ITEM = 'Item';
    const RECORD_TYPE_FILE = 'File';

    public $id;
    public $record_type;
    public $record_id;
    public $required_beam_id = 0;
    public $status = self::STATUS_NOT_TO_BEAM_UP;
    public $public = self::IS_PRIVATE;
    public $process = self::PROCESS_COMPLETED;
    // In Internet archive, the remote identifier is the bucket for item and the
    // the bucket followed by the sanitized filename for file.
    public $remote_id = '';
    public $remote_metadata;
    public $remote_checked = '0000-00-00 00:00:00';
    public $modified;

    // Temporary save of current item or file.
    private $_record = null;
    // Temporary save the parent of the record to beam (item for a file).
    private $_required_beam = null;

    // Use to save upload progress and main beam info to display.
    private $session;

    public function setBeam($recordType, $recordId)
    {
        $this->record_type = inflector::camelize($recordType);
        $this->record_id = $recordId;
        $this->_setRequiredBeamId();
        $this->setDefaultProperties();
    }

    public function setBeamForItem($itemId)
    {
        $this->setBeam(self::RECORD_TYPE_ITEM, $itemId);
    }

    public function setBeamForFile($fileId)
    {
        $this->setBeam(self::RECORD_TYPE_FILE, $fileId);
    }

    /**
     * Set the required record to beam up.
     */
    private function _setRequiredBeamId()
    {
        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                $this->required_beam_id = 0;
                $this->_required_beam = null;
                break;

            case self::RECORD_TYPE_FILE:
                if (!empty($this->required_beam_id)) {
                    $beam = get_record_by_id('BeamInternetArchiveRecord', $this->required_beam_id);
                }
                else {
                    $record = $this->_getRecord();
                    $beam = $this->_db
                        ->getTable('BeamInternetArchiveRecord')
                        ->findByItemId($record->item_id);
                    if (empty($beam)) {
                        $beam = new BeamInternetArchiveRecord();
                        $beam->setBeamForItem($record->item_id);
                        $beam->save();
                    }
                    $this->required_beam_id = $beam->id;
                }
                $this->_required_beam = $beam;
                break;
        }
    }

    /**
     * Set status of the record to beam up and set process status accordingly.
     *
     * @internal All the complexity of the plugin is here. So, to set the status
     * should be the first thing to do when managing a record.
     */
    public function setStatus($status)
    {
        // Status can be changed only if an account is configured.
        if (!$this->_isAccountConfigured()) {
            // We cannot be here via the UI, so checks are minimal.
            if ($this->status != self::STATUS_NOT_TO_BEAM_UP
                    || $status != self::STATUS_NOT_TO_BEAM_UP
                ) {
                $warn = new Omeka_Controller_Action_Helper_FlashMessenger;
                $message = __('Your account is not configured. Item and files cannot be beamed up or updated.');
                $message = __('Beam me up to Internet Archive: %s', $message);
                $warn->addMessage($message, 'alert');
            }
            return;
        }

        // Special case according to process.
        // If we wait the bucket creation, don't change anything.
        // It's just a problem of time, because this status is set only if the
        // bucket has been successfully created.
        if ($this->process == self::PROCESS_QUEUED_WAITING_BUCKET) {
            $this->status = self::STATUS_TO_BEAM_UP;
            return;
        }

        switch ($status) {
            // Once uploaded, status cannot go back to 'not to beam up', but
            // only to 'to remove', if possible, because buckets cannot be
            // removed.
            case self::STATUS_NOT_TO_BEAM_UP:
                switch ($this->status) {
                    case self::STATUS_NOT_TO_BEAM_UP:
                        break;
                    case self::STATUS_TO_BEAM_UP:
                        if ($this->process == self::PROCESS_COMPLETED) {
                            $status = self::STATUS_TO_REMOVE;
                        }
                        elseif (!$this->isBucketReady()
                                && !($this->isBeamForItem() && $this->process == self::PROCESS_QUEUED_WAITING_BUCKET)
                            ) {
                            $status = self::STATUS_NOT_TO_BEAM_UP;
                        }
                        // Keep status 'to beam up'.
                        else {
                            $status = self::STATUS_TO_BEAM_UP;
                        }
                        break;
                    case self::STATUS_TO_UPDATE:
                    case self::STATUS_TO_REMOVE:
                        $status = self::STATUS_TO_REMOVE;
                        break;
                }
                break;

            // Once uploaded, status cannot go back to 'to beam up', but only to
            // 'to update'.
            case self::STATUS_TO_BEAM_UP:
                switch ($this->status) {
                    case self::STATUS_NOT_TO_BEAM_UP:
                        break;
                    case self::STATUS_TO_BEAM_UP:
                        if ($this->process == self::PROCESS_COMPLETED) {
                            $status = self::STATUS_TO_UPDATE;
                        }
                        break;
                    case self::STATUS_TO_UPDATE:
                    case self::STATUS_TO_REMOVE:
                        $status = self::STATUS_TO_UPDATE;
                        break;
                }
                break;

            // It is impossible to update an item that was never beamed up.
            case self::STATUS_TO_UPDATE:
                switch ($this->status) {
                    case self::STATUS_NOT_TO_BEAM_UP:
                        $status = self::STATUS_TO_BEAM_UP;
                        break;
                    case self::STATUS_TO_BEAM_UP:
                        // Don't change status if process is not completed.
                        if ($this->process != self::PROCESS_COMPLETED) {
                            $status = self::STATUS_TO_BEAM_UP;
                        }
                        break;
                    case self::STATUS_TO_UPDATE:
                        break;
                    case self::STATUS_TO_REMOVE:
                        // Cases are managed below.
                        break;
                }
                break;

            // It is impossible to remove an item that was never beamed up.
            case self::STATUS_TO_REMOVE:
                switch ($this->status) {
                    case self::STATUS_NOT_TO_BEAM_UP:
                        $status = self::STATUS_NOT_TO_BEAM_UP;
                        break;
                    case self::STATUS_TO_BEAM_UP:
                        // Don't change status if process is not completed.
                        if ($this->process != self::PROCESS_COMPLETED) {
                            $status = self::STATUS_TO_BEAM_UP;
                        }
                        break;
                    case self::STATUS_TO_UPDATE:
                        break;
                    case self::STATUS_TO_REMOVE:
                        // Cases are managed below.
                        break;
                }
                break;

            // Error.
            default:
                return;
        }

        // If status is not changed and process is not failed, don't change
        // anything. Case for 'in progress' below allows to send a reset. Of
        // course, 'to update' is an exception.
        if ($status == self::STATUS_TO_UPDATE) {
        }
        elseif ($status == $this->status
            && in_array($this->process, array(
                self::PROCESS_COMPLETED,
                self::PROCESS_QUEUED,
                self::PROCESS_QUEUED_WAITING_BUCKET,
                self::PROCESS_IN_PROGRESS_WAITING_REMOTE,
            ))) {
            return;
        }

        // If the process is in progress, kill it before to change it.
        if ($this->process == self::PROCESS_IN_PROGRESS) {
            // TODO To be completed. Currently, only to wait is possible.
            return;
        }

        // For all other process status, set it directly. Job is queued even if
        // bucket is not ready. Job will manage it.
        $this->status = $status;
        if ($this->status == self::STATUS_NOT_TO_BEAM_UP) {
            $this->setProcess(self::PROCESS_COMPLETED);
        }
        else {
            $this->setProcess(self::PROCESS_QUEUED);
        }
    }

    public function saveWithStatus($status)
    {
        $this->setStatus($status);
        $this->save();
    }

    /**
     * Set all elements needed before uploading.
     */
    public function setDefaultProperties()
    {
        $this->setPublic();
        $this->setRemoteId();
    }

    /**
     * Set if a record is indexed on search engines (public) or not (private).
     *
     * @param boolean|null $index Set if record is public or not. If null, set
     *   default value.
     */
    public function setPublic($index = null)
    {
        if ($index == null) {
            $index = get_option('beamia_index_at_internet_archive');
        }
        $this->public = (int) (boolean) $index;
    }

    /**
     * Set remote identifier of the record. Update the required beam if needed.
     *
     * Note: the remote identifier cannot be changed once it is set.
     *
     * @return string The remote identifier if any, empty string if problem.
     */
    public function setRemoteId($remoteId = '')
    {
        // Avoid to change a remote id if a record is removed.
        if (!$this->hasRecord()) {
            return '';
        }

        // It's impossible to rename a bucket name.
        if ($this->status == self::STATUS_TO_BEAM_UP) {
            try {
                switch ($this->record_type) {
                    case self::RECORD_TYPE_ITEM:
                        // An identifier cannot be renamed when the bucket is
                        // created.
                        if (!$this->isBucketReady() && $this->process != self::PROCESS_QUEUED_WAITING_BUCKET) {
                            $this->_setRemoteIdForItem($remoteId);
                        }
                        break;
                    case self::RECORD_TYPE_FILE:
                        $this->_setRemoteIdForFile($remoteId);
                        break;
                }
            } catch (Exception_BeamInternetArchiveConnection $e) {
                // The remote id will be set during job.
                $message = __('Failed to set remote identifier (bucket) for %s #%d: %s.', $this->record_type, $this->record_id, $e->getMessage());
                $this->_log($message, Zend_Log::WARN);
                return '';
            }
        }

        return $this->remote_id;
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
            $this->remote_id = '';
            $this->save();
            throw new Exception_BeamInternetArchiveConnection(__('Cannot check if bucket "%s" exists already', $remoteId));
        }

        // Append a serial if this remote id is used.
        if ($isUsed === true) {
            $i = 0;
            while (($result = $this->checkIfRemoteIdIsUsed($remoteId . '_' . (++$i))) === true) {
                // Needed in case of a connection failed now.
                if ($result === null) {
                    $this->remote_id = '';
                    $this->save();
                    throw new Exception_BeamInternetArchiveConnection(__('Cannot check if bucket "%s" exists already', $remoteId));
                }
            }
            $remoteId .= '_' . $i;
        }

        $this->remote_id = $remoteId;
    }

    /**
     * Set remote identifier for file identifier of item and original_filename.
     *
     * The remote identifier cannot be set as long as the bucket is not created.
     */
    private function _setRemoteIdForFile($filename = '')
    {
        // Check if the required beam has a remote id.
        $beamItem = $this->_getRequiredBeam();
        if (empty($beamItem->remote_id)) {
            $beamItem->setDefaultProperties();
            if (empty($beamItem->remote_id)) {
                $this->remote_id = '';
                return null;
            }
        }

        if (empty($filename)) {
            $file = $this->_getRecord();
            $filename = $file->original_filename;
        }

        // If another file attached to item has the same name, append the id.
        $sql = "SELECT COUNT(id) FROM `{$this->_db->File}` WHERE item_id = ? AND original_filename = ?";
        $params = array($file->item_id, $file->original_filename);
        if ($this->_db->fetchOne($sql, $params) > 1) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '_' . $file->id . ($extension ? '.' . $extension : '');
        }

        // Check if this remote identifier exists already.
        $identifier = $beamItem->remote_id . '/' . $this->_sanitizeString($filename);
        $extension = pathinfo($identifier, PATHINFO_EXTENSION);
        $filename = pathinfo($identifier, PATHINFO_FILENAME);

        $isUsed = $this->checkIfRemoteIdIsUsed($identifier);
        // Connection problem. A new check will be made later.
        if ($isUsed === null) {
            $this->remote_id = '';
            return;
        }

        // Append a serial if this filename is used and check it.
        if ($isUsed === true) {
            $i = 0;
            while (($result = $this->checkIfRemoteIdIsUsed($identifier = ($beamItem->remote_id . '/' . $filename . '_' . (++$i) . ($extension ? '.' . $extension : '')))) !== false) {
                if ($result === null) {
                    $this->remote_id = '';
                    return;
                }
            }
        }

        $this->remote_id = $identifier;
    }

    /**
     * Check if an identifier is already used on remote server.
     *
     * This function uses the fact that http://archive.org/download/$identifier
     * redirects to the true server if the item or the file exists.
     *
     * @param string $identifier The string to check.
     *
     * @return boolean|null A boolean indicates that the identifier exists or
     *   not. A null indicates that the check cannot be done because of
     *   connection or required beam.
     */
    public function checkIfRemoteIdIsUsed($identifier)
    {
        if (trim($identifier) == '' || $identifier == '.' || $identifier == '/') {
            return true;
        }

        if (!$this->isConnectedToRemote()) {
            return null;
        }

        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM :
                return $this->_isRemoteIdUsedForItem($identifier);
            case self::RECORD_TYPE_FILE:
                return $this->_isRemoteIdUsedForFile($identifier);
        }
    }

    /**
     * Check if a bucket is already used on remote server.
     *
     * This function uses the fact that https://archive.org/download/$identifier
     * redirects to the true server if the bucket exists.
     *
     * @param string $identifier The string to check.
     *
     * @return boolean|null A boolean indicates that the identifier exists or
     *   not. A null indicates that the check cannot be done because of
     *   connection or required beam.
     */
    private function _isRemoteIdUsedForItem($identifier)
    {
        $curl = curl_init(BeamInternetArchiveRecord::URL_BASE_DOWNLOAD . $identifier);
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
     * Check if a file is already on remote server.
     *
     * Check is done after a check of the required item.
     *
     * @param string $identifier The string to check.
     *
     * @return boolean|null A boolean indicates that the identifier exists or
     *   not. A null indicates that the check cannot be done because of
     *   connection or required beam.
     */
    private function _isRemoteIdUsedForFile($identifier)
    {
        // Quick check of identifier.
        $bucket = pathinfo($identifier, PATHINFO_DIRNAME);
        if (empty($bucket) || $bucket == '.' || $bucket == '/') {
            return null;
        }

        $filename = pathinfo($identifier, PATHINFO_BASENAME);
        if (empty($filename)) {
            return null;
        }

        // Check if bucket of the item is ready.
        if (!$this->isBucketReady()) {
            return null;
        }

        // Check bucket before filename and keep redirected url.
        $curl = curl_init(BeamInternetArchiveRecord::URL_BASE_DOWNLOAD . $bucket);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        curl_close($curl);
        // Connection error.
        if ($output === false
                || $curlInfo['http_code'] != 302
                || substr(parse_url($curlInfo['redirect_url'], PHP_URL_HOST), -12) != '.archive.org'
            ) {
            return null;
        }

        // Now check filename if filename is available via the redirected url.
        $curl = curl_init($curlInfo['redirect_url'] . '/' . $filename);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        curl_close($curl);
        if ($output === false) {
            return null;
        }

        // If http code is 404, the page doesn't exist.
        return ($curlInfo['http_code'] == 200);
    }

    public function setProcess($process)
    {
        $this->process = $process;
    }

    public function saveWithProcess($process)
    {
        $this->setProcess($process);
        $this->save();
    }

    public function getIndex()
    {
        return $this->public;
    }

    /**
     * This function is used by curl for Internet Archive.
     */
    public function getNoIndex()
    {
        return (int) !$this->public;
    }

    public function getRemoteId()
    {
        return $this->remote_id;
    }

    /**
     * Get the list of settings of the record (Dublin Core by default).
     */
    public function getSettings()
    {
        if (!$this->hasRecord()) {
            return;
        }

        $record = $this->_getRecord();

        // Default settings are Dublin Core metadata.
        $settings = $this->_getMetadataForHeader('Dublin Core');

        // Call hooks.
        $args = array(
            'settings' => &$settings,
            'record' => $record,
        );
        $result = fire_plugin_hook('beamia_set_settings', &$args);

        // Minimal metadata is a title, so we search for such a header.
        $checkTitle = empty($settings) ?
            0 :
            count(array_filter($settings, 'self::_checkTitle'));
        if ($checkTitle == 0) {
            // Try to add a Dublin Core title.
            $title = metadata($record, array('Dublin Core', 'Title'));
            // Else add a generic title.
            if (empty($title)) {
                $title = $this->record_type . ' #' . $this->record_id;
            }
            $settings[] = 'x-archive-meta-title:' . $title;
        }

        // Add a collection if it is not set.
        if ($this->isBeamForItem() && !empty($record->collection_id)) {
            $collection = get_record_by_id('collection', $record->collection_id);
            $collectionTitle = metadata($collection, array('Dublin Core', 'Title'));
            if ($collectionTitle) {
                $settings[] = 'x-archive-meta-collection:' . $collection;
            }
        }

        // Add the required generic media type.
        $settings[] = 'x-archive-meta-mediatype:' . $this->_getMediaType();

        return $settings;
    }

    /**
     * Return formatted array of metadata to use for headers.
     *
     * @param string $elementSetName Restrict metadata to this element set.
     *
     * @return array of strings used for headers.
     */
    private function _getMetadataForHeader($elementSetName)
    {
        $record = $this->_getRecord();
        $settings = array();

        // Add existing elements.
        $options = array(
            'show_empty_elements' => false,
            'return_type' => 'array',
        );
        if ($elementSetName) {
            $options['show_element_sets'] = $elementSetName;
        }
        $elementTexts = all_element_texts($record, $options);

        // Don't add "Dublin Core" in the header, because this is the standard 
        // on Internet Archive.
        $cleanElementSetName = ($elementSetName == 'Dublin Core') ?
            '' :
            preg_replace('#[^a-z0-9]+#', '-', strtolower($elementSetName)) . '-';

        foreach ($elementTexts[$elementSetName] as $element => $texts) {
            // Replace unique or serie of non-alphanumeric character by "-".
            $meta = preg_replace('#[^a-z0-9]+#', '-', strtolower($element));
            foreach ($texts as $key => $text) {
                $base = (count($texts) == 1) ?
                    'x-archive-meta-' :
                    'x-archive-meta' . sprintf('%02d', $key) . '-';
                $settings[] = $base . $cleanElementSetName . $meta . ':' . $text;
            }

            // Add default title if it exists. If none, a generic name will be
            // added automatically.
            if ($element == 'Title') {
                $settings[] = 'x-archive-meta-title:' . $texts[0];
            }
        }

        return $settings;
    }

    /**
     * Callback function used to check if a title is set in headers.
     */
    private static function _checkTitle($value) {
        return (strpos($value, 'x-archive-meta-title:') === 0);
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

    public function getProcess()
    {
        return $this->process;
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
     * This url is used to create bucket of a record.
     *
     * @return url
     */
    public function getUrlForBucket()
    {
        return parse_url(self::URL_BASE_ARCHIVE, PHP_URL_SCHEME) . '://' . $this->remote_id . '.' . parse_url(self::URL_BASE_ARCHIVE, PHP_URL_HOST);
    }

    /**
     * This url is used to upload, update or remove a file.
     *
     * @return url
     */
    public function getUrlForBeamUp()
    {
        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                return self::URL_BASE_ARCHIVE . $this->remote_id . '/metadata.html';
            case self::RECORD_TYPE_FILE:
                return self::URL_BASE_ARCHIVE . $this->remote_id;
        }
    }

    /**
     * Get the url of the beam on the remote site, only if beamed up.
     *
     * @return url
     */
    public function getUrlRemote()
    {
        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                return self::URL_BASE_DETAILS . $this->remote_id;
            case self::RECORD_TYPE_FILE:
                // The download url redirects to the true server automatically.
                return self::URL_BASE_DOWNLOAD . $this->remote_id;
        }
    }

    /**
     * This url is used to get full history of a bucket.
     *
     * @return url
     */
    public function getUrlForTasks()
    {
        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                return self::URL_BASE_TASKS . $this->remote_id;
            // For files, tasks are saved those of the parent bucket.
            case self::RECORD_TYPE_FILE:
                $requiredBeam = $this->_getRequiredBeam();
                return $requiredBeam->getUrlForTasks();
        }
    }

    /**
     * Indicate if a record has been removed or not from the Omeka database.
     *
     * @return boolean.
     */
    public function hasRecord()
    {
        $record = $this->_getRecord();

        return empty($record) ? false : true;
    }

    /**
     * Indicate if record has a true url or not (all cases except exceptions).
     *
     * @return boolean.
     */
    public function hasUrl()
    {
        if ($this->status == self::STATUS_NOT_TO_BEAM_UP && $this->process == self::PROCESS_COMPLETED) {
            return false;
        }
        if ($this->status == self::STATUS_TO_BEAM_UP && !$this->isProcessCompleted()) {
            return false;
        }
        return true;
    }

    /**
     * Indicate if record is already beamed up or queued to be beamed.
     *
     * @return boolean.
     */
    public function isBeamedUp()
    {
        return ($this->status != self::STATUS_NOT_TO_BEAM_UP);
    }

    /**
     * Indicate that a new process is set for the record (even completed).
     *
     * @return boolean.
     */
    public function isToUpdateOrToRemove()
    {
        return (($this->status == self::STATUS_TO_BEAM_UP && $this->process == self::PROCESS_COMPLETED)
            || $this->status == self::STATUS_TO_UPDATE
            || $this->status == self::STATUS_TO_REMOVE);
    }

    /**
     * Indicate if record is an item or not.
     *
     * @return boolean.
     */
    public function isBeamForItem()
    {
        return ($this->record_type == self::RECORD_TYPE_ITEM);
    }

    /**
     * Indicate if record is a file or not.
     *
     * @return boolean.
     */
    public function isBeamForFile()
    {
        return ($this->record_type == self::RECORD_TYPE_FILE);
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
     * Indicate if the process is completed.
     *
     * @return boolean.
     */
    public function isProcessCompleted()
    {
        return ($this->process == self::PROCESS_COMPLETED
            || $this->process == self::PROCESS_IN_PROGRESS_WAITING_REMOTE);
    }

    /**
     * Indicate if the process is queued.
     *
     * @return boolean.
     */
    public function isProcessQueued()
    {
        return ($this->process == self::PROCESS_QUEUED
            || $this->process == self::PROCESS_QUEUED_WAITING_BUCKET);
    }

    /**
     * Indicate if the process is in progress.
     *
     * @return boolean.
     */
    public function isProcessInProgress()
    {
        return ($this->process == self::PROCESS_IN_PROGRESS);
    }

    /**
     * Indicate if the process failed.
     *
     * @return boolean.
     */
    public function isProcessFailed()
    {
        return ($this->process == self::PROCESS_FAILED_CONNECTION
            || $this->process == self::PROCESS_FAILED_RECORD);
    }

    /**
     * Return the number of pending tasks.
     *
     * @return integer|null (null if bucket is not created).
     */
    public function hasPendingTasks()
    {
        if (!$this->isBucketReady()) {
            return null;
        }

        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                return (isset($this->remote_metadata->tasks) ? count($this->remote_metadata->tasks) : 0);
            // For files, tasks are saved those of the parent bucket.
            case self::RECORD_TYPE_FILE:
                $requiredBeam = $this->_getRequiredBeam();
                return $requiredBeam->hasPendingTasks();
        }
    }

    /**
     * Indicate if the account is configured to avoid useless checks.
     *
     * @return boolean.
     */
    private function _isAccountConfigured()
    {
        return (boolean) get_option('beamia_account_checked');
    }

    /**
     * Indicate if the remote server is checkable according to status of record.
     *
     * @return boolean.
     */
    public function isRemoteCheckable()
    {
        if (!$this->_isAccountConfigured()) {
            return false;
        }

        // Check status.
        switch ($this->status) {
            case self::STATUS_NOT_TO_BEAM_UP:
                return false;
            case self::STATUS_TO_BEAM_UP:
                return in_array($this->process, array(
                    self::PROCESS_COMPLETED,
                    self::PROCESS_QUEUED_WAITING_BUCKET,
                    self::PROCESS_IN_PROGRESS,
                    self::PROCESS_IN_PROGRESS_WAITING_REMOTE,
                ));
            case self::STATUS_TO_UPDATE:
                return true;
            case self::STATUS_TO_REMOVE:
                return true;
        }
    }

    /**
     * Indicate if the remote was checked.
     *
     * @return boolean.
     */
    public function isRemoteChecked()
    {
        if (!$this->_isAccountConfigured()) {
            return false;
        }

        return ($this->remote_checked != '0000-00-00 00:00:00');
    }

    /**
     * Indicate if the bucket of a record is ready. This method doesn't check
     * the remote server.
     *
     * Generally, creation of a bucket takes some seconds, but it can be some
     * minutes and even some hours in case of maintenance.
     *
     * @see checkIfBucketIsReady()
     *
     * @return boolean.
     */
    public function isBucketReady()
    {
        if (!$this->_isAccountConfigured()) {
            return false;
        }

        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                break;
            case self::RECORD_TYPE_FILE:
                $requiredBeam = $this->_getRequiredBeam();
                return $requiredBeam->isBucketReady();
        }

        // Check status.
        switch ($this->status) {
            case self::STATUS_NOT_TO_BEAM_UP:
                return false;
            case self::STATUS_TO_BEAM_UP:
                // Manage the case for "in progress" and other similar cases.
                return !empty($this->remote_metadata) && !isset($this->remote_metadata->error);
            case self::STATUS_TO_UPDATE:
                // Status of a beam cannot be 'to update' if there is no bucket.
                return true;
            case self::STATUS_TO_REMOVE:
                // Status of a beam cannot be 'to remove' if there is no bucket.
                return true;
        }
    }

    /**
     * Check if bucket is really created on remote server via a true check.
     *
     * If bucket is created, remote_metadata is filled. Status is not changed,
     * Process is changed only if bucket is created.
     *
     * Generally, creation takes some seconds, but it can be some minutes and
     * even some hours in case of maintenance.
     *
     * @uses isBucketReady()
     * @uses checkRemoteStatus()
     *
     * @return boolean True for success or false for failure to check.
     */
    public function checkIfBucketIsReady()
    {
        // Avoid to check multiple times.
        if ($this->isBucketReady()) {
            return true;
        }

        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                $result = $this->checkRemoteStatus();
                return $this->isBucketReady();
            case self::RECORD_TYPE_FILE:
                $requiredBeam = $this->_getRequiredBeam();
                return $requiredBeam->checkIfBucketIsReady();
        }
    }

    /**
     * Check remote status and update beam record accordingly.
     *
     * The check doesn't simply return status, but update it, because metadata
     * of item can be updated at any time, in particular when files are sent.
     *
     * @return boolean True for success or false for failure.
     */
    public function checkRemoteStatus()
    {
        try {
            $result = $this->_updateMetadata();
        } catch (Exception_BeamInternetArchiveRecord $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            return false;
        } catch (Exception_BeamInternetArchiveConnection $e) {
            _log($e->getMessage(), Zend_Log::WARN);
            return false;
        }
        return (boolean) $result;
    }

    /**
     * Check and update all files attached to an item.
     *
     * @return void.
     */
    public function checkRemoteStatusForFilesOfItem()
    {
        if (!$this->isBeamForItem()) {
            return;
        }

        // Check and update status of item only one time.
        if (!$this->checkIfBucketIsReady()) {
            // All beams for files has got process 'waiting remote' or
            // 'queued waiting bucket' already.
            return;
        }
        if (!$this->_updateMetadata()) {
            return;
        }

        // Check files even if remote is not available in order to update their
        // status.
        $beams = $this->_db
            ->getTable('BeamInternetArchiveRecord')
            ->findBeamsOfAttachedFiles($this->id);
        foreach ($beams as $key => $beam) {
            $result = $beam->_updateMetadata(false);
        }
    }

    /**
     * Get metadata from remote server.
     *
     * @param boolean $updateRequiredRecord Update or not the required record
     *   before update of this record.
     *
     * @return boolean|null True for success, false for failure, null for bucket
     *   not ready.
     */
    private function _updateMetadata($updateRequiredRecord = true)
    {
        if (!$this->isRemoteCheckable()) {
            return false;
        }

        if (!$this->isConnectedToRemote()) {
            return false;
        }

        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                return $this->_updateMetadataForItem();
            case self::RECORD_TYPE_FILE:
                return $this->_updateMetadataForFile($updateRequiredRecord);
        }
    }

    /**
     * Get bucket metadata from remote server, whatever status or process are.
     *
     * @return boolean|null True for success, false for failure, null for bucket
     *   not ready.
     */
    private function _updateMetadataForItem()
    {
        // TODO Use curl.
        $url = $this->getUrlForMetadata();
        $maxTime = time() + get_option('beamia_max_time_to_check_bucket');
        while (($result = preg_replace('/\s/', '', $remoteMetadata = file_get_contents($url))) == '{}' && time() < $maxTime) {
            sleep(1);
        }

        $this->remote_checked = date('Y-m-d G:i:s');

        if ($remoteMetadata === false) {
            $this->save();
            // No change of status, because it's a connection failure.
            return false;
        }

        // Not a connection failure, but bucket is not ready. Another check is
        // done in job just after creation, so all cases are already managed
        // and we don't change status of process now.
        if ($result == '{}') {
            $this->save();
            return null;
        }

        $this->remote_metadata = json_decode($remoteMetadata);
        if ($this->process == self::PROCESS_COMPLETED) {
            $this->_removeSession();
        }

        if (isset($this->remote_metadata->error)) {
            if ($this->process != self::PROCESS_FAILED_RECORD) {
                $this->setProcess(self::PROCESS_FAILED_RECORD);
                $message = __('Error during creation of bucket for %s #%d: %s.', $this->record_type, $this->record_id, $this->remote_metadata->error);
                $this->_log($message, Zend_Log::WARN);
            }
            $this->save();
            return true;
        }

        if ($this->process == self::PROCESS_QUEUED_WAITING_BUCKET) {
            // Because the bucket is created, we can queue the metadata of item.
            $this->setProcess(self::PROCESS_QUEUED);
            $message = __('Bucket fully created for %s #%d.', $this->record_type, $this->record_id);
            $this->_log($message, Zend_Log::INFO);
            $this->save();
            return true;
        }

        if ($this->process == self::PROCESS_IN_PROGRESS_WAITING_REMOTE) {
            $this->setProcess(self::PROCESS_COMPLETED);
            $message = __('Finished operation "%s" for %s #%d.', $this->status, $this->record_type, $this->record_id);
            $this->_log($message, Zend_Log::INFO);
            $this->_removeSession();
            $this->save();
            return true;
        }

        $this->save();
        return true;
    }

    /**
     * Get metadata for a file from remote server.
     *
     * @param boolean $updateRequiredRecord Update or not the required record
     *   before update of this record.
     *
     * @return boolean|null True for success, false for failure, null for bucket
     *   not ready.
     */
    private function _updateMetadataForFile($updateRequiredRecord = true)
    {
        // No check is needed when a process is completed.
        if ($this->process == self::PROCESS_COMPLETED) {
            return false;
        }

        $requiredBeam = $this->_getRequiredBeam();
        if ($updateRequiredRecord) {
            $requiredBeam->_updateMetadata();
        }

        if (!$requiredBeam->isBucketReady()) {
            // Bucket is not created, so files cannot be ready.
            if ($this->process === self::PROCESS_QUEUED) {
                $this->saveWithProcess(self::PROCESS_QUEUED_WAITING_BUCKET);
            }
            return null;
        }

        // Update process status if queued and waiting bucket.
        if ($this->process === self::PROCESS_QUEUED_WAITING_BUCKET) {
            $this->saveWithProcess(self::PROCESS_QUEUED);
            return false;
        }

        $this->remote_checked = date('Y-m-d G:i:s');

        // We can do a request to the true file or a check of metadata.
        // To avoid a new connection, a simple check is done.
        if (!isset($requiredBeam->remote_metadata->files_count) || $requiredBeam->remote_metadata->files_count === 0) {
            $this->save();
            return false;
        }

        foreach ($requiredBeam->remote_metadata->files as $remoteFile) {
            // For files, bucket is the identifier of the parent bucket.
            if ($remoteFile->name == pathinfo($this->remote_id, PATHINFO_BASENAME)) {
                $this->remote_metadata = $remoteFile;
                if ($this->process == self::PROCESS_IN_PROGRESS_WAITING_REMOTE) {
                    $this->setProcess(self::PROCESS_COMPLETED);
                    $message = __('Finished operation "%s" for %s #%d.', $this->status, $this->record_type, $this->record_id);
                    $this->_log($message, Zend_Log::WARN);
                    $this->_removeSession();
                }
                $this->save();
                return true;
            }
        }

        // Not found in the list of files of the bucket.
        if ($this->status == self::STATUS_TO_REMOVE && $this->process == self::PROCESS_IN_PROGRESS_WAITING_REMOTE) {
            $this->saveWithProcess(self::PROCESS_COMPLETED);
            $this->remote_metadata = null;
            $message = __('Finished operation "%s" for %s #%d.', $this->status, $this->record_type, $this->record_id);
            $this->_log($message, Zend_Log::WARN);
            $this->_removeSession();
            return true;
        }

        $this->save();
        return false;
    }

    /**
     * Quick check of connectivity to avoid wasting time.
     */
    public function isConnectedToRemote()
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
     * Get/set the current record to beam up.
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
     * Get/set the required beam or set it if not ready.
     *
     * @return BeamInternetArchiveRecord record.
     */
    private function _getRequiredBeam()
    {
        switch ($this->record_type) {
            case self::RECORD_TYPE_ITEM:
                $this->required_beam_id = 0;
                $this->_required_beam = null;
                break;

            case self::RECORD_TYPE_FILE:
                // Need to update each time, because status of process may have
                // been updated since last use.
                $this->_setRequiredBeamId();
                break;
        }

        return $this->_required_beam;
    }

    protected function beforeSave($args)
    {
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
        if (!in_array($this->record_type, array(
                'Item',
                'File',
            ))) {
            $this->addError('record_type', __('This record type "%s" is not managed.', $this->record_type));
        }
        // Don't do this check before the record is saved (auto-incremented id).
        if (!empty($this->id) && $this->id <= $this->required_beam_id) {
            $this->addError('record_id', __('Invalid record identifier: it cannot be lower than the id of the required beam.'));
        }
        if ($this->isBeamForFile() && (empty($this->required_beam_id) || $this->required_beam_id == '0')) {
            $this->addError('required_beam_id', __('A file cannot be uploaded if its parent item is not uploaded before.'));
        }
        if (!in_array($this->status, array(
                self::STATUS_NOT_TO_BEAM_UP,
                self::STATUS_TO_BEAM_UP,
                self::STATUS_TO_UPDATE,
                self::STATUS_TO_REMOVE,
            ))) {
            $this->addError('status', __('This status "%s" is not managed.', $this->status));
        }
        if (!in_array($this->process, array(
                self::PROCESS_COMPLETED,
                self::PROCESS_QUEUED,
                self::PROCESS_QUEUED_WAITING_BUCKET,
                self::PROCESS_IN_PROGRESS,
                self::PROCESS_IN_PROGRESS_WAITING_REMOTE,
                self::PROCESS_FAILED_CONNECTION,
                self::PROCESS_FAILED_RECORD,
            ))) {
            $this->addError('process', __('This process "%s" is not managed.', $this->process));
        }
        if ($this->status == self::STATUS_NOT_TO_BEAM_UP && $this->process != self::PROCESS_COMPLETED) {
            $this->addError('process', __('Process for this status "%s" cannot be changed.', $this->status));
        }
        if ($this->process == self::PROCESS_QUEUED_WAITING_BUCKET && $this->status != self::STATUS_TO_BEAM_UP) {
            $this->addError('status', __('When the bucket is created, its status can only be "%s".', self::STATUS_TO_BEAM_UP));
        }
    }

    protected function afterSave($args)
    {
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
     * Helper to set log and session message.
     */
    private function _log($message, $level)
    {
        _log(__('Beam me up to Internet Archive: %s', $message), $level);

        if (is_null($this->id)) {
            return;
        }

        $result = $this->_getSession();
        $this->session->beams[$this->id]['message'][date('Y-m-d G:i:s')] = '[' . $level . ']: ' . $message;
        $this->session->beams[$this->id]['level'] = $level;
    }

    /**
     * Helper to remove a session for a record.
     */
    private function _removeSession()
    {
        if (is_null($this->id)) {
            return;
        }

        $result = $this->_getSession();
        if (isset($this->session->beams[$this->id])) {
            unset($this->session->beams[$this->id]);
        }
    }

    private function _getSession()
    {
        if (is_null($this->session)) {
            $this->session = new Zend_Session_Namespace('BeamMeUpToInternetArchive');
        }
        return $this->session;
    }
}
