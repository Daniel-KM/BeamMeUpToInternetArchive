<?php
/**
 * @copyright Daniel Berthereau for Pop Up Archive, 2012
 * @copyright Daniel Vizzini and Dave Lester for Pop Up Archive, 2012
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2-en.txt
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package BeamMeUp
 */

/**
 * Posts items to the Internet Archive as they are saved.
 *
 * @see README.md
 */

/**
 * Contains code used to integrate the plugin into Omeka.
 *
 * @package BeamMeUp
 */

class BeamMeUpPlugin extends Omeka_Plugin_Abstract
{
    protected $_hooks = array(
        'install',
        'uninstall',
        'upgrade',
        'config_form',
        'config',
        'after_save_item', // The main one.
        'admin_theme_header',
        'admin_append_to_items_show_secondary',
        'admin_append_to_items_form_files',
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
        $options = $this->_options;
        if (!is_array($options)) {
            return;
        }
        foreach ($options as $name => $value) {
            delete_option($name);
        }
    }

    public function hookUpgrade($oldVersion, $newVersion)
    {
        $db = get_db();
        switch ($oldVersion) {
            case '0.1':
            case '0.2':
                if ($newVersion == '0.2') {
                    // Drop the table created in 0.1 if it exists.
                    $db = get_db();
                    $sql = "DROP TABLE IF EXISTS `{$db->prefix}internet_archive_files`";
                    $db->query($sql);
                }
        }
    }

    /**
     * Displays configuration form.
     * @return void
     */
    public function hookConfigForm()
    {
        include('config_form.php');
    }

    /**
     * Saves plugin configuration.
     *
     * @param array Options set in the config form.
     * @return void
     */
    public function hookConfig($post)
    {
        set_option('beam_post_to_internet_archive', $post['BeamPostToInternetArchive']);
        set_option('beam_index_at_internet_archive', $post['BeamIndexAtInternetArchive']);
        set_option('beam_S3_access_key', $post['BeamS3AccessKey']);
        set_option('beam_S3_secret_key', $post['BeamS3SecretKey']);
        set_option('beam_collection_name', $post['BeamCollectionName']);
        set_option('beam_media_type', $post['BeamMediaType']);
    }

    /**
     * Post Files and metadata of an Omeka Item to the Internet Archive.
     * @return void
     */
    public function hookAfterSaveItem($item)
    {
        if ($_POST['BeamPostToInternetArchive'] == '1') {
            require_once dirname(__FILE__) . '/functions.php';

            // Set item.
            require_once HELPER_DIR . '/Functions.php';
            require_once HELPER_DIR . '/ItemFunctions.php';
            require_once HELPER_DIR . '/StringFunctions.php';
            set_current_item($item);

            // Prepare and run job for this item.
            $jobDispatcher = Zend_Registry::get('job_dispatcher');
            $jobDispatcher->setQueueName('uploads');
            $jobDispatcher->send('Beam_Upload_Job', array());
        }
    }

    /**
     * Adds theme header.
     */
    public function hookAdminThemeHeader($request)
    {
    }

    /**
     * Displays Internet Archive links in admin/show section
     * @return void
     */
    public function hookAdminAppendToItemsShowSecondary() {
        echo '<div class="info-panel">';
        echo '<h2>Beam me up to Internet Archive</h2>';
        echo $this->listInternetArchiveLinks();
        echo '</div>';
    }

    /**
     * Gives user the option to post to the Internet Archive
     * @return void
     */
    public function hookAdminAppendToItemsFormFiles() {
        echo '<span><strong>' . __('Upload to Internet Archive') . '</strong></span>';
        echo __v()->formCheckbox('BeamPostToInternetArchive', true, array('checked' => (boolean) get_option('beam_post_to_internet_archive')));
        echo '<div><em>' . __('Note that if this box is checked, saving the item may take a while.') . '</em></div>';

        echo '<span><strong>' . __('Index at Internet Archive') . '</strong></span>';
        echo __v()->formCheckbox('BeamIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beam_index_at_internet_archive')));
        echo '<div><em>' . __("If you index your item, it will appear on the results of search engines such as Google's.") . '</em></div>';
    }

    /**
     * Add BeamMeUp tab to the edit item page
     * @return array
     */
    public function filterAdminItemsFormTabs($tabs)
    {
        // insert the map tab before the Miscellaneous tab
        $item = get_current_item();
        $ttabs = array();
        foreach ($tabs as $key => $html) {
            if ($key == 'Miscellaneous') {
                $ht = '';
                $ht .= $this->beam_form($item);
                $ttabs['Beam me up to Internet Archive'] = $ht;
            }
            $ttabs[$key] = $html;
        }
        $tabs = $ttabs;
        return $tabs;
    }

    /**
     * Each time we save an item, post to the Internet Archive.
     * @return void
     */
    private function beam_form($item)
    {
        $ht = '';
        ob_start();

        echo '<div>' . __("If the box at the bottom of the files tab is checked, the files in this item, along with their metadata, will upload to the Internet Archive upon save.") . '</div>';
        echo "<br />\n";
        echo '<div>' . __("Note that BeamMeUp may make saving an item take a while, and that it may take additional time for the Internet Archive to post the files after you save.") . '</div>';
        echo "<br />\n";
        echo '<div>' . __("To change the upload default or to alter the upload's configuration, visit the plugin's configuration settings on this site.") . '</div>';
        echo "<br />\n";

        if (item('id') == '') {
            echo '<div>' . __('Please revisit this tab after you save the item to view its Internet Archive links.') . '</div>';
        }
        else {
            echo $this->listInternetArchiveLinks();
        }

        $ht .= ob_get_contents();
        ob_end_clean();

        return $ht;
    }

    /**
     * @return string containing IA links
     */
    private function listInternetArchiveLinks()
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
function beamGetBucketName()
{
    return get_option('beam_bucket_prefix') . '_' . item('id');
}

/** Installation of the plugin. */
$beam = new BeamMeUpPlugin();
$beam->setUp();
