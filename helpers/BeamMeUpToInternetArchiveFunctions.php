<?php
/**
 * Return a link to a Beam record.
 *
 * @param string $text HTML for the text of the link.
 * @param array $props Properties for the <a> tag.
 * @param string $action The page to link to (default: 'beam-me-up').
 * @param BeamInternetArchiveRecord $beam Used for dependency injection testing
 *   or to use this function outside the context of a loop.
 *
 * @return string HTML
 */
function link_to_beamia($text = null, $props = array(), $action = 'beam-me-up', $beam = null)
{
    if (!$beam) {
        $beam = get_current_record('BeamInternetArchiveRecord');
    }

    // We don't use link_to() because the name of the plugin is different from
    // the name of the class.
    $url = WEB_DIR . '/beam-me-up-to-internet-archive/index/' . $action . '/' . $beam->id;
    $attr = !empty($props) ? ' ' . tag_attributes($props) : '';
    $text = !empty($text) ? $text : __('beam record #%d', $beam->id);
    return '<a href="'. html_escape($url) . '"' . $attr . '>' . $text . '</a>';
}

/**
 * Return a link or a simple text.
 *
 * @uses link_to()
 * @param string $text HTML for the text of the link.
 * @param array $props Properties for the <a> tag.
 * @param BeamInternetArchiveRecord $beam Used for dependency injection testing
 *   or to use this function outside the context of a loop.
 *
 * @return string HTML
 */
function link_to_beamia_url_if_any($url, $text = null, $props = array('target' => '_blank'), $beam = null)
{
    if (!$beam) {
        $beam = get_current_record('BeamInternetArchiveRecord');
    }
    $attr = !empty($props) ? ' ' . tag_attributes($props) : '';
    $text = (!empty($text) ? $text : __('beam record #%d', $beam->id));

    return empty($url) ?
        $text :
        '<a href="'. html_escape($url) . '"' . $attr . '>' . $text . '</a>';
}

/**
 * Return a link to a beamed file.
 *
 * @uses link_to()
 * @param string $text HTML for the text of the link.
 * @param array $props Properties for the <a> tag.
 * @param BeamInternetArchiveRecord $beam Used for dependency injection testing
 *   or to use this function outside the context of a loop.
 *
 * @return string HTML
 */
function link_to_beamia_remote_if_any($text = null, $props = array('target' => '_blank'), $beam = null)
{
    $url = $beam->getUrlRemote();
    return link_to_beamia_url_if_any($url, $text, $props, $beam);
}

/**
 * Return a link to the task page of a record.
 *
 * @uses link_to()
 * @param string $text HTML for the text of the link.
 * @param array $props Properties for the <a> tag.
 * @param BeamInternetArchiveRecord $beam Used for dependency injection testing
 *   or to use this function outside the context of a loop.
 *
 * @return string HTML
 */
function link_to_beamia_tasks_if_any($text = null, $props = array('target' => '_blank'), $beam = null)
{
    $url = $beam->getUrlForTasks();
    return link_to_beamia_url_if_any($url, $text, $props, $beam);
}

/**
 * Helper to set list of possible tasks for a record.
 *
 * @param BeamInternetArchiveRecord $beam Used for dependency injection testing
 *   or to use this function outside the context of a loop.
 *
 * @return string HTML
 */
function beamia_listActions($beam = null)
{
    if (!$beam) {
        $beam = get_current_record('BeamInternetArchiveRecord');
    }

    $pendingTasks = $beam->hasPendingTasks();

    $output = '';

    if (!$beam->hasRecord()) {
        $output .= '<li>' . link_to_beamia(__('Remove'), array(), 'remove', $beam) . '</li>';
    }
    // It's always possible to set a record to be beamed up for the first time.
    elseif (!empty($pendingTasks) && $beam->isToUpdateOrToRemove()) {
        $output .= '<li>' . __('Wait %d pending tasks before a new process.', $pendingTasks) . '</li>';
    }
    elseif (!$beam->isToUpdateOrToRemove()) {
        $output .= '<li>' . link_to_beamia(__('Beam me up'), array(), 'beam-me-up', $beam) . '</li>';
    }
    else {
        $output .= '<li>' . link_to_beamia(__('Update'), array(), 'update', $beam) . '</li>';
        $output .= '<li>' . link_to_beamia(__('Remove'), array(), 'remove', $beam) . '</li>';
    }

    return $output;
}

/**
 * Helper to get the required beam identifier from a beam record.
 *
 * @param BeamInternetArchiveRecord $beam Used for dependency injection testing
 *   or to use this function outside the context of a loop.
 *
 * @return string HTML
 */
function beamia_getRequiredRecordIdFromBeam($beam = null)
{
    if (!$beam) {
        $beam = get_current_record('BeamInternetArchiveRecord');
    }
    if ($beam->isBeamForItem()) {
        return 0;
    }

    $beam = get_record_by_id('BeamInternetArchiveRecord', $beam->required_beam_id);
    return $beam->record_id;
}

/**
 * Helper to get the percent of progress of a process.
 *
 * @param BeamInternetArchiveRecord $beam Used for dependency injection testing
 *   or to use this function outside the context of a loop.
 *
 * @return array with progress percent and total bytes.
 */
function beamia_getProgress($beam = null)
{
    if (!$beam) {
        $beam = get_current_record('BeamInternetArchiveRecord');
    }

    if (!isset($_SESSION['BeamUploadInternetArchive']['curls'][$beam->id])) {
        return array();
    }

    $curlArray = $_SESSION['BeamUploadInternetArchive']['curls'][$beam->id];
    if ($curlArray['downloadTotal'] + $curlArray['uploadTotal'] == 0) {
        $progress = 0;
        $total = 0;
    }
    elseif ($curlArray['uploadTotal'] > 0) {
        $progress = round($curlArray['uploadNow'] * 100 / $curlArray['uploadTotal']);
        $total = $uploadTotal;
    }
    elseif ($curlArray['downloadTotal'] > 0) {
        $progress = round($curlArray['downloadNow'] * 100 / $curlArray['downloadTotal']);
        $total = $downloadTotal;
    }

    return array(
        'progress' => $progress,
        'total' => $total,
    );
}
