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
 * @package BeamMeUp
 */

/**
 * Contains code used to integrate the plugin into Omeka.
 *
 * @package BeamMeUp
 */

class BeamMeUpPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'install',
        'uninstall',
        'upgrade',
        'config_form',
        'config',
        'after_save_item',
        'admin_theme_header',
        'admin_items_show_sidebar',
        'admin_items_form_files',
    );

    protected $_filters = array(
        'admin_items_form_tabs',
    );

    protected $_options = array(
        'beam_post_to_internet_archive' => true,
        'beam_index_at_internet_archive' => true,
        'beam_S3_access_key' => 'Enter S3 access key here',
        'beam_S3_secret_key' => 'Enter S3 secret key here',
        'beam_collection_name' => 'Please contact the Internet Archive',
        'beam_media_type' =>  'Please contact the Internet Archive',
        'beam_bucket_prefix' => 'omeka',
    );

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        // Set default bucket prefix: omeka_SERVERNAME.
        $bucketPrefix = get_plugin_ini('BeamMeUp', 'beam_bucket_prefix');
        if (empty($bucketPrefix)) {
            $bucketPrefix = str_replace('.', '_', preg_replace('/www/', '', $_SERVER['SERVER_NAME'], 1));
            $bucketPrefix = 'omeka' . ((strpos($bucketPrefix, '_') == 0) ? '' : '_') . $bucketPrefix;
        }
        $this->_options['beam_bucket_prefix'] = $bucketPrefix;

        $this->_installOptions();
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $this->_uninstallOptions();
    }

    /**
     * Upgrades the plugin.
     */
    public function hookUpgrade($args)
    {
        $oldVersion = $args['old_version'];
        $newVersion = $args['new_version'];

        switch ($oldVersion) {
            case '0.1':
            case '0.2':
                if ($newVersion == '0.2') {
                    // Drop the table created in 0.1 if it exists.
                    $sql = "DROP TABLE IF EXISTS `{$this->_db->prefix}internet_archive_files`";
                    $this->_db->query($sql);
                }
        }
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
    public function hookConfig()
    {
        set_option('beam_post_to_internet_archive', $_POST['BeamPostToInternetArchive']);
        set_option('beam_index_at_internet_archive', $_POST['BeamIndexAtInternetArchive']);
        set_option('beam_S3_access_key', $_POST['BeamS3AccessKey']);
        set_option('beam_S3_secret_key', $_POST['BeamS3SecretKey']);
        set_option('beam_collection_name', $_POST['BeamCollectionName']);
        set_option('beam_media_type', $_POST['BeamMediaType']);
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
        if ($_POST['BeamPostToInternetArchive'] == '1') {
            $item = $args['record'];

            // Beam up files only if there are files. 
            if ($item->fileCount() > 0) {
                // Keep only primitive data types of files to be uploaded.
                $files = $item->getFiles();
                foreach ($files as $key => $file) {
                    $files[$key] = $file->id;
                }

                // Prepare and run job for this item.
                $jobDispatcher = Zend_Registry::get('bootstrap')->getResource('jobs');
                // TODO Currently, long running jobs don't allow curl.
                // $jobDispatcher->setQueueNameLongRunning('beam_uploads');
                // $jobDispatcher->sendLongRunning('Job_BeamUpload', array(
                $jobDispatcher->setQueueName('beam_uploads');
                $jobDispatcher->send('Job_BeamUpload', array(
                    'itemId' => $item->id,
                    'files' => $files,
                ));
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
        echo $this->_listInternetArchiveLinks();
        echo '</div>';
    }

    /**
     * Gives user the option to post to the Internet Archive.
     *
     * @return void
     */
    public function hookAdminItemsFormFiles($args) {
        echo '<div class="field">';
        echo   '<div id="BeamPostToInternetArchive_label" class="one columns alpha">';
        echo     get_view()->formLabel('BeamPostToInternetArchive', __('Upload to Internet Archive'));
        echo   '</div>';
        echo   '<div class="inputs">';
        echo     get_view()->formCheckbox('BeamPostToInternetArchive', true, array('checked' => (boolean) get_option('beam_post_to_internet_archive')));
        echo     '<p class="explanation">';
        echo       __('Note that if this box is checked, saving the item may take a while.');
        echo     '</p>';
        echo   '</div>';
        echo '</div>';

        echo '<div class="field">';
        echo   '<div id="BeamIndexAtInternetArchive_label" class="one columns alpha">';
        echo     get_view()->formLabel('BeamIndexAtInternetArchive', __('Index at Internet Archive'));
        echo   '</div>';
        echo   '<div class="inputs">';
        echo     get_view()->formCheckbox('BeamIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beam_index_at_internet_archive')));
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
        // Insert the map tab before the Miscellaneous tab.
        $item = get_current_record('item');
        $ttabs = array();
        foreach ($tabs as $key => $html) {
            if ($key == 'Miscellaneous') {
                $ht = '';
                $ht .= $this->_beam_form($item);
                $ttabs['Beam me up to Internet Archive'] = $ht;
            }
            $ttabs[$key] = $html;
        }
        $tabs = $ttabs;
        return $tabs;
    }

    /**
     * Each time we save an item, post to the Internet Archive.
     *
     * @return void
     */
    private function _beam_form($item)
    {
        $ht = '';
        ob_start();

        echo '<div>' . __("If the box at the bottom of the files tab is checked, the files in this item, along with their metadata, will upload to the Internet Archive upon save.") . '</div>';
        echo "<br />\n";
        echo '<div>' . __("Note that BeamMeUp may make saving an item take a while, and that it may take additional time for the Internet Archive to post the files after you save.") . '</div>';
        echo "<br />\n";
        echo '<div>' . __("To change the upload default or to alter the upload's configuration, visit the plugin's configuration settings on this site.") . '</div>';
        echo "<br />\n";

        if (metadata($item, 'id') == '') {
            echo '<div>' . __('Please revisit this tab after you save the item to view its Internet Archive links.') . '</div>';
        }
        else {
            echo $this->_listInternetArchiveLinks();
        }

        $ht .= ob_get_contents();
        ob_end_clean();

        return $ht;
    }

    /**
     * @return string containing IA links
     */
    private function _listInternetArchiveLinks()
    {
        return '<div>' . __('If you uploaded the files and the Internet Archive has fully processed them, you can view them <strong><a href="http://archive.org/details/' . beamGetBucketName() . '" target="_blank">here</a></strong>') . '</div>'
            . '<br />'
            . '<div>' . __("You can view the upload's Internet Archive history and progress <strong><a href=\"http://archive.org/catalog.php?history=1&identifier=" . beamGetBucketName() . '" target="_blank">here</a></strong>.') . '</div>'
            . '<br />';
    }
}

/**
 * @return bucket name for Omeka Item
 */
function beamGetBucketName($identifier = '')
{
    if (empty($identifier)) {
        $item = get_current_record('item');
        $identifier = $item->id;
    }
    return get_option('beam_bucket_prefix') . '_' . $identifier;
}
