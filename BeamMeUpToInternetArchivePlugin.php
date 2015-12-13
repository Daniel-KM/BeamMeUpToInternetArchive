<?php
/**
 * Beam Me Up to Internet Archive
 *
 * Posts items to the Internet Archive as they are saved.
 *
 * @copyright Daniel Berthereau for Pop Up Archive, 2012-2013
 * @copyright Daniel Vizzini and Dave Lester for Pop Up Archive, 2012
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

require_once dirname(__FILE__) . '/helpers/BeamMeUpToInternetArchiveFunctions.php';

/**
 * The Beam Me Up to Internet Archive plugin.
 * @package Omeka\Plugins\BeamMeUpToInternetArchive
 */

class BeamMeUpToInternetArchivePlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array This plugin's hooks.
     */
    protected $_hooks = array(
        'install',
        'uninstall',
        'define_acl',
        'config_form',
        'config',
        'after_save_item',
        'admin_items_show_sidebar',
        'admin_items_form_files',
        'admin_files_show_sidebar',
    );

    /**
     * @var array This plugin's filters.
     */
    protected $_filters = array(
        'admin_navigation_main',
        'admin_items_form_tabs',
    );

    /**
     * @var array This plugin's options.
     */
    protected $_options = array(
        'beamia_post_to_internet_archive' => true,
        'beamia_index_at_internet_archive' => true,
        'beamia_S3_access_key' => 'Enter S3 access key here',
        'beamia_S3_secret_key' => 'Enter S3 secret key here',
        'beamia_collection_name' => 'Please contact the Internet Archive',
        'beamia_media_type' =>  'Please contact the Internet Archive',
        'beamia_bucket_prefix' => 'omeka',
        'beamia_job_type' => 'normal',
        'beamia_max_time_to_check_bucket' => 300,
        'beamia_min_time_before_new_check' => 60,
        'beamia_max_simultaneous_process' => 5,
    );

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        // Set default bucket prefix: omeka_SERVERNAME.
        $bucketPrefix = str_replace('.', '_', preg_replace('/www/', '', $_SERVER['SERVER_NAME'], 1));
        $this->_options['beamia_bucket_prefix'] = 'omeka' . ((strpos($bucketPrefix, '_') == 0) ? '' : '_') . $bucketPrefix;

        $this->_installOptions();

        $this->_installTable();

        // Fill the table with all existing items and files in order to get
        // a record to beam by default, with status set to "not to beam up".
        $this->_fillTable();
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $sql = "DROP TABLE IF EXISTS `{$this->_db->BeamInternetArchiveRecord}`;";
        $this->_db->query($sql);

        $this->_uninstallOptions();
    }

    /**
     * Helper for install and upgrade.
     *
     * @return void
     */
    private function _installTable()
    {
        $sql = "
        CREATE TABLE IF NOT EXISTS `{$this->_db->BeamInternetArchiveRecord}` (
            `id` int unsigned NOT NULL auto_increment COMMENT 'Identifier (id of an item should be lower than ids of its attached files)',
            `record_type` varchar(50) NOT NULL COMMENT 'Omeka record type of the record to upload',
            `record_id` int unsigned NOT NULL COMMENT 'Identifier of the record to upload',
            `required_beam_id` int unsigned DEFAULT 0 COMMENT 'Identifier of the beam, used when the record depends on another one',
            `status` enum('not to beam up', 'to beam up', 'to update', 'to remove') COLLATE 'utf8_unicode_ci' NULL DEFAULT 'not to beam up' COMMENT 'Status to set for the record',
            `public` tinyint DEFAULT 0 COMMENT 'Make public or not',
            `process` enum('completed', 'queued', 'queued waiting bucket creation', 'processing', 'processing remotely', 'failed after connection error', 'failed after record error') COLLATE 'utf8_unicode_ci' NULL DEFAULT 'completed' COMMENT 'Process status of the record',
            `remote_id` varchar(256) DEFAULT '' COMMENT 'Identifier of the record on the remote site',
            `remote_metadata` text COLLATE utf8_unicode_ci COMMENT 'Json object of last informations get from remote site',
            `remote_checked` timestamp NOT NULL  DEFAULT '0000-00-00 00:00:00' COMMENT 'Last check of the record on the remote site',
            `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP COMMENT 'Last modification of the record',
            PRIMARY KEY  (`id`),
            KEY `record_type_record_id` (`record_type`, `record_id`),
            KEY `record_type` (`record_type`),
            KEY `required_beam_id` (`required_beam_id`),
            KEY `status` (`status`),
            KEY `process` (`process`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ";
        $this->_db->query($sql);
    }

    /**
     * Helper for install and upgrade to fill the beam table with all existing
     * items and files, in order to get a default record for all of them, with
     * status set to "not to beam up".
     *
     * @return void
     */
    private function _fillTable()
    {
        // Direct queries are used to avoid overload. No need to check for
        // unique record ids, because this is an installation.

        // Query for item. All id for items should be lower than the attached
        // those for the attached files.
        $sql = "
            INSERT INTO `{$this->_db->BeamInternetArchiveRecord}` (record_type, record_id)
            SELECT 'Item', id
            FROM `{$this->_db->Item}`
        ";
        $this->_db->query($sql);

        // Query for files. This is not a problem if attached files are not
        // inserted just after the parent item, because this is managed in view.
        $sql = "
            INSERT INTO `{$this->_db->BeamInternetArchiveRecord}` (record_type, record_id, required_beam_id)
            SELECT 'File', files.id, beams.id
            FROM `{$this->_db->File}` files
                JOIN `{$this->_db->BeamInternetArchiveRecord}` beams
                    ON beams.record_type = 'Item'
                        AND beams.record_id = files.item_id
        ";
        $this->_db->query($sql);
    }

    /**
     * Define the ACL.
     *
     * @param Omeka_Acl
     */
    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];

        $indexResource = new Zend_Acl_Resource('BeamMeUpToInternetArchive_Index');
        $acl->add($indexResource);

        $acl->allow(array('super', 'admin'), array('BeamMeUpToInternetArchive_Index'));
    }

    /**
     * Displays configuration form.
     *
     * @return void
     */
    public function hookConfigForm()
    {
        include('config_form.php');
    }

    /**
     * Saves plugin configuration.
     *
     * @todo Improve check of account.
     *
     * @return void
     */
    public function hookConfig($args)
    {
        $post = $args['post'];

        set_option('beamia_post_to_internet_archive', $post['BeamiaPostToInternetArchive']);
        set_option('beamia_index_at_internet_archive', $post['BeamiaIndexAtInternetArchive']);
        set_option('beamia_S3_access_key', trim($post['BeamiaS3AccessKey']));
        set_option('beamia_S3_secret_key', trim($post['BeamiaS3SecretKey']));
        set_option('beamia_collection_name', trim($post['BeamiaCollectionName']));
        set_option('beamia_media_type', trim($post['BeamiaMediaType']));
        set_option('beamia_bucket_prefix', trim($post['BeamiaBucketPrefix']));
        set_option('beamia_job_type', ($post['BeamiaJobType']) ? 'long running' : 'normal');
        set_option('beamia_max_time_to_check_bucket', (int) $post['BeamiaMaxTimeToCheckBucket']);
        set_option('beamia_min_time_before_new_check', (int) $post['BeamiaMinTimeBeforeNewCheck']);
        set_option('beamia_max_simultaneous_process', (int) $post['beamiaMaxSimultaneousProcess']);

        $BeamiaS3AccessKey = get_option('beamia_S3_access_key');
        $BeamiaS3SecretKey = get_option('beamia_S3_secret_key');
        $BeamiaAccountChecked = (strlen($BeamiaS3AccessKey) == 16 && strlen($BeamiaS3SecretKey) == 16);
        set_option('beamia_account_checked', (int) $BeamiaAccountChecked);
    }

    /**
     * Prepare records to beam up to Internet Archive.
     *
     * The process occurs only when all files are saved, at the end of the
     * creation of item. All items and files have a matching record in beam
     * table.
     *
     * @return void
     */
    public function hookAfterSaveItem($args)
    {
        $post = $args['post'];
        $item = $args['record'];
        $options = array('beams' => array());

        // Create beam records even if the item and files are not uploaded.
        if ($args['insert']) {
            $beamItem = new BeamInternetArchiveRecord;
            $beamItem->setBeamForItem($item->id);
            if ($post['BeamiaPostToInternetArchive'] == 1) {
                if ((boolean) get_option('beamia_account_checked')) {
                    $beamItem->saveWithStatus(BeamInternetArchiveRecord::STATUS_TO_BEAM_UP);
                    $options['beams'][$beamItem->id] = $beamItem->id;
                }
                else {
                    $warn = new Omeka_Controller_Action_Helper_FlashMessenger;
                    $message = __('Your account is not configured. Item and files cannot be beamed up or updated.');
                    $message = __('Beam me up to Internet Archive: %s', $message);
                    $warn->addMessage($message, 'alert');
                    $beamItem->save();
                }
            }
            else {
                $beamItem->save();
            }

            $files = $item->getFiles();
            foreach ($files as $key => $file) {
                $beam = new BeamInternetArchiveRecord;
                $beam->setBeamForFile($file->id);
                if ($post['BeamiaPostToInternetArchive'] == 1) {
                    if ((boolean) get_option('beamia_account_checked')) {
                        $beam->saveWithStatus(BeamInternetArchiveRecord::STATUS_TO_BEAM_UP);
                        $options['beams'][$beam->id] = $beam->id;
                    }
                    else {
                        $warn = new Omeka_Controller_Action_Helper_FlashMessenger;
                        $message = __('Your account is not configured. Item and files cannot be beamed up or updated.');
                        $message = __('Beam me up to Internet Archive: %s', $message);
                        $warn->addMessage($message, 'alert');
                        $beam->save();
                    }
                }
                else {
                    $beam->save();
                }
            }
        }
        // Update or remove (see status).
        else {
            if (!(boolean) get_option('beamia_account_checked')) {
                $warn = new Omeka_Controller_Action_Helper_FlashMessenger;
                $message = __('Your account is not configured. Item and files cannot be beamed up or updated.');
                $message = __('Beam me up to Internet Archive: %s', $message);
                $warn->addMessage($message, 'error');
                return;
            }

            $beamItem = $this->_getBeamForRecord($item);
            // TODO Currently, change of status is not managed here.
            $beamItem->saveWithStatus(BeamInternetArchiveRecord::STATUS_TO_UPDATE);
            $options['beams'][$beamItem->id] = $beamItem->id;

            $files = $item->getFiles();
            foreach ($files as $key => $file) {
                $beam = $this->_getBeamForRecord($file);
                // TODO Currently, change of status is not managed here.
                $beam->saveWithStatus(BeamInternetArchiveRecord::STATUS_TO_UPDATE);
                $options['beams'][$beam->id] = $beam->id;
            }
        }

        // Do something after every record save.
        if (count($options['beams']) > 0) {
            $this->_prepareJob($options);
        }
    }

    /**
     * Displays Internet Archive links in admin/show section.
     *
     * @return void
     */
    public function hookAdminItemsShowSidebar($args) {
        $item = $args['item'];

        $output = '';
        $output .= '<div class="info panel">' . PHP_EOL;
        $output .= '<h4>Beam me up to Internet Archive</h4>' . PHP_EOL;
        $output .= $this->_warnAccountConfiguration();
        $output .= '<h5>Status of item</h5>' . PHP_EOL;
        $output .= $this->_listInternetArchiveLinks($item, true);
        $output .= '<h5>Status of files</h5>' . PHP_EOL;
        $output .= $this->_listInternetArchiveLinksForFiles($item);
        $output .= '</div>' . PHP_EOL;

        echo $output;
        return $output;
    }

    /**
     * Displays Internet Archive links in admin/show section.
     *
     * @return void
     */
    public function hookAdminFilesShowSidebar($args) {
        $file = $args['file'];

        $output = '';
        $output .= '<div class="info panel">' . PHP_EOL;
        $output .= '<h4>Beam me up to Internet Archive</h4>' . PHP_EOL;
        $output .= $this->_warnAccountConfiguration();
        $output .= $this->_listInternetArchiveLinks($file, true);
        $output .= '</div>' . PHP_EOL;

        echo $output;
        return $output;
    }

    /**
     * Gives user the option to post to the Internet Archive.
     *
     * @return void
     */
    public function hookAdminItemsFormFiles($args) {
        $warn = $this->_warnAccountConfiguration();
        if ($warn != '') {
            echo $warn;
            return $warn;
        }

        $output = '';
        $output .= '<div class="field">' . PHP_EOL;
        $output .= '  <div id="BeamiaPostToInternetArchive_label" class="one columns alpha">';
        $output .=     get_view()->formLabel('BeamiaPostToInternetArchive', __('Upload to Internet Archive'));
        $output .= '  </div>' . PHP_EOL;
        $output .= '  <div class="inputs">' . PHP_EOL;
        $output .=     get_view()->formCheckbox('BeamiaPostToInternetArchive', true, array('checked' => (boolean) get_option('beamia_post_to_internet_archive')));
        $output .= '    <p class="explanation">';
        $output .=       __('Note that if this box is checked, saving the item may take a while.');
        $output .=     '</p>' . PHP_EOL;
        $output .= '  </div>' . PHP_EOL;
        $output .= '</div>' . PHP_EOL;

        $output .= '<div class="field">' . PHP_EOL;
        $output .= '  <div id="BeamiaIndexAtInternetArchive_label" class="one columns alpha">';
        $output .=     get_view()->formLabel('BeamiaIndexAtInternetArchive', __('Index at Internet Archive'));
        $output .= '  </div>' . PHP_EOL;
        $output .= '  <div class="inputs">' . PHP_EOL;
        $output .=     get_view()->formCheckbox('BeamiaIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beamia_index_at_internet_archive')));
        $output .= '    <p class="explanation">';
        $output .=       __("If you index your items, they will appear on the results of search engines such as Google's.");
        $output .=     '</p>' . PHP_EOL;
        $output .= '  </div>' . PHP_EOL;
        $output .= '</div>' . PHP_EOL;

        echo $output;
        return $output;
    }

    /**
     * Add the plugin main link to the admin main navigation.
     *
     * @param array Navigation array.
     * @return array Filtered navigation array.
     */
    public function filterAdminNavigationMain($nav)
    {
        $nav[] = array(
            'label' => __('Internet Archive'),
            // TODO Currently, need to set the full url in order to get the good
            // beamia_link_to() in browse view (else, the controller name is not
            // added to the url).
            'uri' => url('beam-me-up-to-internet-archive/index/browse'),
            'resource' => 'BeamMeUpToInternetArchive_Index',
            'privilege' => 'browse'
        );
        return $nav;
    }

    /**
     * Add BeamMeUp tab to the edit item page.
     *
     * @return array
     */
    public function filterAdminItemsFormTabs($tabs)
    {
        $tabs['Internet Archive'] = $this->_beamia_form();
        return $tabs;
    }

    /**
     * Set a message when the account is not configured.
     *
     * @return string.
     */
    private function _warnAccountConfiguration()
    {
        $output = '';

        if (!(boolean) get_option('beamia_account_checked')) {
            $output .= '<div class="field">' . PHP_EOL;
            $output .= '    <p class="explanation">';
            $output .=       '<strong>' . __('Warning:') . '</strong>' . ' ';
            $output .=       __('Your account is not configured. Item and files cannot be beamed up or updated.');
            $output .=     '</p>' . PHP_EOL;
            $output .= '</div>' . PHP_EOL;
        }

        return $output;
    }

    /**
     * Each time we save an item, post it to the Internet Archive.
     *
     * @return void
     */
    private function _beamia_form()
    {
        $item = get_current_record('item');

        $output = '';

        $output .= '<div>' . __("If the box at the bottom of the files tab is checked, the files in this item, along with their metadata, will upload to the Internet Archive upon save.") . '</div><br />' . PHP_EOL;
        $output .= '<div>' . __("Note that BeamMeUp may make saving an item take a while, and that it may take additional time for the Internet Archive to post the files after you save.") . '</div><br />' . PHP_EOL;
        $output .= '<div>' . __("To change the upload default or to alter the upload's configuration, visit the plugin's configuration settings on this site.") . '</div><br />' . PHP_EOL;
        $output .= $this->_warnAccountConfiguration();
        if (metadata($item, 'id') == '') {
            $output .= '<div>' . __('Please revisit this tab after you save the item to view its Internet Archive links.') . '</div><br />' . PHP_EOL;
        }
        else {
            $output .= $this->_listInternetArchiveLinks($item, true);
            $output .= $this->_listInternetArchiveLinksForFiles($item);
        }

        return $output;
    }

    /**
     * Return output containing Internet Archive links for a record.
     *
     * @param object $record Omeka record (item or file) to get links for.
     * @param boolean $checkRemoteStatus Check remote status or not.
     *
     * @return string containing IA links for a record.
     */
    private function _listInternetArchiveLinks($record, $checkRemoteStatus = true)
    {
        $beam = $this->_getBeamForRecord($record);
        if ($checkRemoteStatus
                && (!$beam->isRemoteChecked() || (time() - strtotime($beam->remote_checked)) >  get_option('beamia_min_time_before_new_check'))
            ) {
            $beam->checkRemoteStatus();
        }
        if ($checkRemoteStatus) {
            $beam->checkRemoteStatus();
        }

        $pendingTasks = $beam->hasPendingTasks();

        $output = '';
        $output .= '<div id="beam-list">' . PHP_EOL;
        $output .= '  <ul>' . PHP_EOL;
        if ($beam->hasUrl()) {
            $output .= '    <li>' . __('Status') . ': ' . beamia_link_to_remote_if_any(__($beam->status), array(), $beam) . '</a>' . '</li>' . PHP_EOL;
            $output .= '    <li>' . __('Process') . ': <a href="' . $beam->getUrlForTasks() . '" target="_blank">' . __($beam->process) . '</a>';
            $output .= ' (' . (!empty($pendingTasks) ? __('%d pending tasks', $pendingTasks) : __('No pending tasks')) . ')';
            $output .= '</li>' . PHP_EOL;
        }
        else {
            $output .= '    <li>' . __('Status') . ': ' . __($beam->status) . '</li>' . PHP_EOL;
            $output .= '    <li>' . __('Process') . ': ' . __($beam->process) . '</li>' . PHP_EOL;
        }

        $progressInfo = beamia_getProgress($beam);
        if (!empty($progressInfo) && $progressInfo['total'] > 0) {
            $output .= '    <li>' . __('Progress: %d%% of %d bytes.', $progressInfo['progress'], $progressInfo['total']) . '</li>' . PHP_EOL;
        }

        $output .= beamia_listActions($beam);

        $output .= '  </ul>' . PHP_EOL;
        $output .= '</div>' . PHP_EOL;

        return $output;
    }

    /**
     * Output list of Internet Archive links for files attached to an item.
     *
     * @param Item $item Omeka item to get links for.
     *
     * @return string containing IA links for files attached to an item.
     */
    private function _listInternetArchiveLinksForFiles($item)
    {
        $beamItem = $this->_getBeamForRecord($item);

        $output = '';

        if (!metadata($item, 'has files')) {
            $output .= '<div id="beam-list">' . PHP_EOL;
            $output .= '<ul>' . PHP_EOL;
            $output .= '<li>' . __('No files') . '</li>' . PHP_EOL;
            $output .= '</ul>' . PHP_EOL;
            $output .= '</div>' . PHP_EOL;
            return $output;
        }

        // Update status of all files in one check.
        $beamItem->checkRemoteStatusForFilesOfItem();
        $files = $item->getFiles();

        $output .= '<div id="beam-files-list">' . PHP_EOL;
        $output .= '<ul>' . PHP_EOL;
        foreach ($files as $file) {
            $beam = $this->_getBeamForRecord($file);
            $output .= '<li>';
            $output .= link_to_file_show(array('class'=>'show', 'title'=>__('View File Metadata')), null, $file) . ': ' . PHP_EOL;
            $output .= $this->_listInternetArchiveLinks($file, false);
            $output .= '</li>' . PHP_EOL;
        }
        $output .= '</ul>' . PHP_EOL;
        $output .= '</div>' . PHP_EOL;

        return $output;
    }

    /**
     * Get a beam for an item or a file. If none exists, create a default one.
     *
     * @param Item|File $record Record to get beam record for.
     *
     * @return BeamInternetArchiveRecord object.
     */
    private function _getBeamForRecord($record)
    {
        // Check if a beam exists for this record.
        $beam = $this->_db->getTable('BeamInternetArchiveRecord')->findByRecordTypeAndRecordId(get_class($record), $record->id);

        // If no beam is found, set default record for item.
        if (empty($beam)) {
            $beam = new BeamInternetArchiveRecord;
            $beam->setBeam(get_class($record), $record->id);
            $beam->save();
        }
        return $beam;
    }

    /**
     * Prepare and send job if possible.
     */
    private function _prepareJob($options = array())
    {
        // Prepare a job for all these beams.
        $jobDispatcher = Zend_Registry::get('bootstrap')->getResource('jobs');
        // Use long running job if server supports it (php-cli with curl).
        if (get_option('beamia_job_type') == 'long running') {
            $jobDispatcher->setQueueNameLongRunning('beamia_uploads');
            $jobDispatcher->sendLongRunning('Job_BeamUploadInternetArchive', $options);
        }
        // Standard jobs.
        else {
            $jobDispatcher->setQueueName('beamia_uploads');
            $jobDispatcher->send('Job_BeamUploadInternetArchive', $options);
        }
    }
}
