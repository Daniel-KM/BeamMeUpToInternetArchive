<script type="text/javascript">
    jQuery(document).ready(function() {
        $("#BeamS3AccessKey").Watermark("Enter S3 access key here.");
        $("#BeamS3SecretKey").Watermark("Enter S3 secret key here.");

        jQuery("form").bind("submit", function(event) {
            //cannot use .children() because form has no name or id
            if (jQuery('input[name=BeamS3AccessKey]').val().indexOf(' ') != -1 || jQuery('input[name=BeamS3AccessKey]').val() == '') {
                alert('Please enter valid secret key.');
            }
            if (jQuery('input[name=BeamS3SecretKey]').val().indexOf(' ') != -1 || jQuery('input[name=BeamS3SecretKey]').val() == '') {
                alert('Please enter valid access key.');
            }
            if (jQuery('input[name=BeamBucketPrefix]').val().indexOf(' ') != -1 || jQuery('input[name=BeamBucketPrefix]').val() == '') {
                alert('Please enter valid bucket prefix.');
            }
            else {
                jQuery('input[name=BeamBucketPrefix]').val(jQuery('input[name=BeamBucketPrefix]').val().toLowerCase()));
            }

            return false;
        });
    });
</script>

<h3><strong><em>Note that if you are uploading to the Internet Archive, saving an item may take a while.</em></strong></h3>

<div class="field">
    <?php echo __v()->formLabel('BeamPostToInternetArchive', __('Upload to Internet Archive by default'));?>
    <div class="inputs">
        <?php echo __v()->formCheckbox('BeamPostToInternetArchive', true, array('checked' => (boolean) get_option('beam_post_to_internet_archive')));?>
        <p class="explanation">
            <?php echo __('You can change this option on a per-item basis.');?>
        </p>
    </div>
</div>
<div class="field">
    <?php echo __v()->formLabel('BeamIndexAtInternetArchive', __('Index at Internet Archive by default'));?>
    <div class="inputs">
        <?php echo __v()->formCheckbox('BeamIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beam_index_at_internet_archive')));?>
        <p class="explanation">
            <?php echo __("If you index your items, they will appear on the results of search engines such as Google's.") . '<br />';
            echo __('You can change this option on a per-item basis.');?>
        </p>
    </div>
</div>
<h3>Please visit <a href="http://www.archive.org/account/s3.php" target="_blank">The Internet Archive's S3 Page</a> to generate the keys below.</h3>
<h3>Be sure to log in with the account used for your archives.</h3>
<div class="field">
    <?php echo __v()->formLabel('BeamS3AccessKey', __('S3 access key'));?>
    <div class="inputs">
        <?php echo __v()->formText('BeamS3AccessKey', get_option('beam_S3_access_key'), null);?>
    </div>
</div>
<div class="field">
    <?php echo __v()->formLabel('BeamS3SecretKey', __('S3 secret key'));?>
    <div class="inputs">
        <?php echo __v()->formText('BeamS3SecretKey', get_option('beam_S3_secret_key'), null);?>
    </div>
</div>
<div class="field">
    <?php echo __v()->formLabel('BeamCollectionName', __('Collection name'));?>
    <div class="inputs">
        <?php echo __v()->formText('BeamCollectionName', get_option('beam_collection_name'), null);?>
        <p class="explanation">
            <?php echo __('You must contact <a href="mailto:info@archive.org" >info@archive.org</a> and get an Internet Archive Collection to use this plugin. You can use "test_collection".');
            echo __('Do not fear. It is free and the Internet Archive is staffed exclusively with friendly and responsive people.');?>
        </p>
    </div>
</div>
<div class="field">
    <?php echo __v()->formLabel('BeamMediaType', __('Media type'));?>
    <div class="inputs">
        <?php echo __v()->formText('BeamMediaType', get_option('beam_media_type'), null);?>
        <p class="explanation">
            <?php echo __('Ask the Internet Archive what do put here, for example "texts". They will tell you what to enter here when you get your collection.');
            echo __('Again, they would love to hear from you so please contact them.');?>
        </p>
    </div>
</div>
<div>
    <h2><?php echo __('Bucket prefix') . ': "<em>' . get_option('beam_bucket_prefix') . '</em>"' ;?></h2>
    <p>
    <?php echo __('Bucket prefix is used to build the url of each item you beam up to Internet Archive. The url will be this prefix followed by "_" and the item id.');?>
    <br />
    <?php echo __(' You may have changed it in plugin.ini before installation of the plugin.');?>
    </p>
    <p>
    <strong><?php echo __('WARNING:');?></strong>
    <br />
    <?php echo __('With the current version of BeamMeUp, you cannot change it once it has been defined and you started to upload items on Internet Archive.');?>
    </p>
</div>
<div>
    <h2><?php echo __('Output Formats'); ?></h2>
    <?php echo output_format_list(false, ' · '); ?>
</div>
