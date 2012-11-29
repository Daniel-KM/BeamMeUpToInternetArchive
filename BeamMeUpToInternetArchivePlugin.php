<?php
/**
 * Posts items to the Internet Archive as they are saved.
 *
 * @see README.md
 *
 * @copyright Daniel Berthereau for Pop Up Archive, 2012
 * @copyright Daniel Vizzini and Dave Lester for Pop Up Archive, 2012
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2-en.txt
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package BeamMeUpToInternetArchive
 *
 * This is a development version. Things to do to finish the plugin:
 * done Replace most of throw exceptions by logs.
 * done Full check of upload progress.
 * done Update sent status of files (every time an item is displayed).
 * @todo Relaunch processes if failed or not sent.
 * @todo Check if pbcore metadata are used.
 * @todo Change echo by return.
 * @todo Save direct url to file in database [item server + item dir + filename]?
 *
 * Things to do to improve plugin:
 * @todo Check files with the same original name (flat directory on IA).
 * @todo Check item name for bucket (should be unique, else it's updated).
 * @todo Manage update and deletion of item and files (important).
 * @todo Individual select for files of an item.
 * @todo Status page of all files and items.
 * @todo Merge settings and remote_metadata columns in one metadata column?
 * @todo Merge local status and remote_status columns in table?
 *
 * Less important, just for better MVC.
 * @todo Beam builder.
 * @todo Beam controller.
 * @todo Curl object builder (or Zend http).
 */

/**
 * Contains code used to integrate the plugin into Omeka.
 *
 * @package BeamMeUpToInternetArchive
 */

class BeamMeUpToInternetArchivePlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array This plugin's hooks.
     */
    protected $_hooks = array(
        'install',
        'uninstall',
        'config_form',
        'config',
        'after_save_item',
        'admin_theme_header',
        'admin_items_show_sidebar',
        'admin_items_form_files',
        'admin_files_show_sidebar',
    );

    /**
     * @var array This plugin's filters.
     */
    protected $_filters = array(
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
        // Max time to check success for the bucket creation.
        // TODO To be removed.
        'beamia_max_time_item' => 60,
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
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $this->_uninstallOptions();

        $sql = "DROP TABLE IF EXISTS `{$this->_db->prefix}beam_internet_archive_beams`;";
        $this->_db->query($sql);
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
     * @return void
     */
    public function hookConfig($args)
    {
        $post = $args['post'];
        set_option('beamia_post_to_internet_archive', $_POST['BeamiaPostToInternetArchive']);
        set_option('beamia_index_at_internet_archive', $_POST['BeamiaIndexAtInternetArchive']);
        set_option('beamia_S3_access_key', trim($_POST['BeamiaS3AccessKey']));
        set_option('beamia_S3_secret_key', trim($_POST['BeamiaS3SecretKey']));
        set_option('beamia_collection_name', trim($_POST['BeamiaCollectionName']));
        set_option('beamia_media_type', trim($_POST['BeamiaMediaType']));
        set_option('beamia_bucket_prefix', trim($_POST['BeamiaBucketPrefix']));
        set_option('beamia_job_type', ($_POST['BeamiaJobType']) ? 'long running' : 'normal');
        set_option('beamia_max_time_item', (int) $_POST['BeamiaMaxTimeItem']);
    }

    /**
     * Post Files and metadata of an Omeka Item to the Internet Archive.
     *
     * The process occurs only when all files are saved, at the end of the
     * creation of item.
     *
     * @return void
     */
    public function hookAfterSaveItem($args)
    {
        if ($_POST['BeamiaPostToInternetArchive'] == '1') {
            $item = $args['record'];
            $options = array();

            $beamItem = $this->_setBeamItem($item);
            $options['beams']['item'] = $beamItem->id;

            $files = $item->getFiles();
            foreach ($files as $key => $file) {
                $beam = $this->_setBeamFile($file, $beamItem);
                $options['beams'][] = $beam->id;
            }

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

    /**
     * Adds theme header.
     */
    public function hookAdminThemeHeader($request)
    {
    }

    /**
     * Displays Internet Archive links in admin/show section.
     *
     * @return void
     */
    public function hookAdminItemsShowSidebar($args) {
        echo '<div class="info panel">';
        echo '<h4>Beam me up to Internet Archive</h4>';
        echo '<h5>Status of item</h5>';
        echo $this->_listInternetArchiveLinks();
        echo '<h5>Status of files</h5>';
        echo $this->_listInternetArchiveLinksForFiles();
        echo '</div>';
    }

    /**
     * Displays Internet Archive links in admin/show section.
     *
     * @return void
     */
    public function hookAdminFilesShowSidebar($args) {
        echo '<div class="info panel">';
        echo '<h4>Beam me up to Internet Archive</h4>';
        echo $this->_listInternetArchiveLinksForFile();
        echo '</div>';
    }

    /**
     * Gives user the option to post to the Internet Archive.
     *
     * @return void
     */
    public function hookAdminItemsFormFiles($args) {
        echo '<div class="field">';
        echo   '<div id="BeamiaPostToInternetArchive_label" class="one columns alpha">';
        echo     get_view()->formLabel('BeamiaPostToInternetArchive', __('Upload to Internet Archive'));
        echo   '</div>';
        echo   '<div class="inputs">';
        echo     get_view()->formCheckbox('BeamiaPostToInternetArchive', true, array('checked' => (boolean) get_option('beamia_post_to_internet_archive')));
        echo     '<p class="explanation">';
        echo       __('Note that if this box is checked, saving the item may take a while.');
        echo     '</p>';
        echo   '</div>';
        echo '</div>';

        echo '<div class="field">';
        echo   '<div id="BeamiaIndexAtInternetArchive_label" class="one columns alpha">';
        echo     get_view()->formLabel('BeamiaIndexAtInternetArchive', __('Index at Internet Archive'));
        echo   '</div>';
        echo   '<div class="inputs">';
        echo     get_view()->formCheckbox('BeamiaIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beamia_index_at_internet_archive')));
        echo     '<p class="explanation">';
        echo       __("If you index your items, they will appear on the results of search engines such as Google's.");
        echo     '</p>';
        echo   '</div>';
        echo '</div>';
    }

    /**
     * Add BeamMeUp tab to the edit item page.
     *
     * @return array
     */
    public function filterAdminItemsFormTabs($tabs)
    {
        $item = get_current_record('item');
        $tabs['Beam me up to Internet Archive'] = $this->_beamia_form($item);
        return $tabs;
    }

    /**
     * @return void
     */
    private function _installTable()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `{$this->_db->prefix}beam_internet_archive_beams` (
              `id` int unsigned NOT NULL auto_increment COMMENT 'Identifier',
              `record_type` varchar(50) NOT NULL COMMENT 'Omeka record type of the record to upload',
              `record_id` int unsigned NOT NULL COMMENT 'Identifier of the record to upload',
              `required_beam_id` int unsigned default 0 COMMENT 'Identifier of the beam, used when the record depends on another one',
              `status` varchar(255) collate utf8_unicode_ci default '' COMMENT 'Record to upload or not',
              `public` tinyint default 0 COMMENT 'Record to make public or not',
              `settings` text collate utf8_unicode_ci COMMENT 'Serialized list of parameters used to upload the record',
              `remote_id` varchar(256) default '' COMMENT 'Identifier of the record on the remote site',
              `remote_status` varchar(255) collate utf8_unicode_ci COMMENT 'Last remote status of the uploaded record',
              `remote_metadata` text collate utf8_unicode_ci COMMENT 'Serialized list of last informations get from remote site',
              `remote_checked` timestamp NOT NULL  default '0000-00-00 00:00:00' COMMENT 'Last check of the record on the remote site',
              `modified` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP COMMENT 'Last modification of the record',
              PRIMARY KEY  (`id`),
              KEY `record_type_record_id` (`record_type`, `record_id`),
              KEY `required_beam_id` (`required_beam_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ";
        $this->_db->query($sql);
    }

    /**
     * Build a beam for an item.
     *
     * @param $item
     *
     * @return beam object.
     */
    private function _setBeamItem($item) {
        // Check if a beam exists for this item.
        $beam = $this->_db->getTable('BeamInternetArchiveBeam')->findByItemId($item->id);
        if ($beam) {
            _log(__('Beam me up to Internet Archive: Currently, update of a record is not managed (for record #%d and %s #%d).', $beam->id, $beam->record_type, $beam->record_id), Zend_Log::WARN);
        }
        else {
            $beam = new BeamInternetArchiveBeam;
            $beam->setItemToBeamUp($item->id);
            $beam->setRemoteIdForItem();
            $beam->setPublic(($_POST['BeamiaIndexAtInternetArchive'] == '1') ? BeamInternetArchiveBeam::IS_PUBLIC : BeamInternetArchiveBeam::IS_PRIVATE);
            $beam->setSettings();
            $beam->save();
        }
        return $beam;
    }

    /**
     * Build a beam for a file.
     *
     * @param $file
     * @param $beamItem Beam of the
     *
     * @return beam object.
     */
    private function _setBeamFile($file, $beamItem) {
        // Check if a beam exists for this file.
        $beam = $this->_db->getTable('BeamInternetArchiveBeam')->findByFileId($file->id);
        if ($beam) {
            _log(__('Beam me up to Internet Archive: Currently, update of a record is not managed (for record #%d and %s #%d).', $beam->id, $beam->record_type, $beam->record_id), Zend_Log::WARN);
        }
        else {
            $beam = new BeamInternetArchiveBeam;
            $beam->setFileToBeamUp($file->id, $beamItem->id);
            $beam->setRemoteIdForFile();
            $beam->setPublic(($_POST['BeamiaIndexAtInternetArchive'] == '1') ? BeamInternetArchiveBeam::IS_PUBLIC : BeamInternetArchiveBeam::IS_PRIVATE);
            $beam->setSettings();
            $beam->save();
        }
        return $beam;
    }

    /**
     * Each time we save an item, post it to the Internet Archive.
     *
     * @return void
     */
    private function _beamia_form($item)
    {
        ob_start();

        echo '<div>' . __("If the box at the bottom of the files tab is checked, the files in this item, along with their metadata, will upload to the Internet Archive upon save.") . '</div>' . "<br />\n";
        echo '<div>' . __("Note that BeamMeUp may make saving an item take a while, and that it may take additional time for the Internet Archive to post the files after you save.") . '</div>' . "<br />\n";
        echo '<div>' . __("To change the upload default or to alter the upload's configuration, visit the plugin's configuration settings on this site.") . '</div>' . "<br />\n";

        if (metadata($item, 'id') == '') {
            echo '<div>' . __('Please revisit this tab after you save the item to view its Internet Archive links.') . '</div>' . "<br />\n";
        }
        else {
            echo $this->_listInternetArchiveLinks();
            echo $this->_listInternetArchiveLinksForFiles();
        }

        $output = ob_get_contents();
        ob_end_clean();

        return $output;
    }

    /**
     * @return string containing IA links for an item.
     */
    private function _listInternetArchiveLinks()
    {
        $item = get_current_record('item');
        $beam = $this->_db->getTable('BeamInternetArchiveBeam')->findByItemId($item->id);

        $output = '';
        if (empty($beam)) {
            $output .= '<div id="beam-list">';
            $output .= '<ul>';
            $output .= '<li>' . __('Status') . ': ' . __('Not to beam up') . '</li>';
            $output .= '<li>' . __('Remote status') . ': ' . __('N/A') . '</li>';
            $output .= '</ul>';
            $output .= '</div>';
        }
        else {
            // Update status.
            $beam->checkRemoteStatus();

            $output .= '<div id="beam-list">';
            $output .= '<ul>';
            $output .= '<li>' . __('Status') . ': <a href="' . $beam->getUrlForItem() . '" target="_blank">' . $beam->status . '</a>' . '</li>';
            $output .= '<li>' . __('Remote status') . ': <a href="' . $beam->getUrlForTasks() . '" target="_blank">' . $beam->remote_status . '</a>';
            if (isset($beam->remote_metadata->pending_tasks)) {
                $output .= ' (' . count($beam->remote_metadata->pending_tasks) . ' pending tasks)';
            }
            $output .= '</li>';
            $output .= '</ul>';
            $output .= '</div>';
        }

        return $output;
    }

    /**
     * @return string containing IA links for files attached to an item.
     */
    private function _listInternetArchiveLinksForFiles()
    {
        $item = get_current_record('item');
        $beamItem = $this->_db->getTable('BeamInternetArchiveBeam')->findByItemId($item->id);

        $output = '';
        if (empty($beamItem)) {
            $output .= '<div id="beam-list">';
            $output .= '<ul>';
            $output .= '<li>' . __('Not to beam up') . '</li>';
            $output .= '</ul>';
            $output .= '</div>';
            return $output;
        }

        if (!metadata('item', 'has files')) {
            $output .= '<div id="beam-list">';
            $output .= '<ul>';
            $output .= '<li>' . __('No files') . '</li>';
            $output .= '</ul>';
            $output .= '</div>';
            return $output;
        }

        // Update status of files.
        $beamItem->checkRemoteStatusForFilesOfItem();
        $files = $item->getFiles();

        $output .= '<div id="beam-files-list">';
        $output .= '<ul>';
        foreach ($files as $file) {
            $beam = $this->_db->getTable('BeamInternetArchiveBeam')->findByFileId($file->id);
            $beamUrl = $beam->getUrlForFile();
            $output .= '<li>';
            $output .= link_to_file_show(array('class'=>'show', 'title'=>__('View File Metadata')), null, $file) . ': ';
            if (empty($beamUrl)) {
                $output .= '<div>' . $beam->status . '</div>' . '</li>';
            }
            else {
                $output .= '<div><a href="' . $beamUrl . '" target="_blank">' . $beam->status . '</a></div>' . '</li>';
            }
        }
        $output .= '</ul>';
        $output .= '</div>';

        return $output;
    }

    /**
     * @return string containing IA links for one file.
     */
    private function _listInternetArchiveLinksForFile()
    {
        $file = get_current_record('file');
        $beam = $this->_db->getTable('BeamInternetArchiveBeam')->findByFileId($file->id);

        $output = '';
        if (empty($beam)) {
            $output .= '<div>' . __('Not to beam up') . '</div>';
            return $output;
        }

        // Update status of files.
        $beam->checkRemoteStatus();
        $beamUrl = $beam->getUrlForFile();

        if (empty($beamUrl)) {
            $output .= '<div>' . $beam->status . '</div>';
        }
        else {
            $output .= '<div><a href="' . $beamUrl . '" target="_blank">' . $beam->status . '</a></div>' . '</div';
        }

        return $output;
    }
}
