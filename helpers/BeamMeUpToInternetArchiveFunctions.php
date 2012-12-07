<?php
/**
 * Return a link to a Beam record.
 *
 * @param string $text HTML for the text of the link.
 * @param array $props Properties for the <a> tag.
 * @param string $action The page to link to (default: 'beam-me-up').
 * @param BeamInternetArchiveBeam $beam Used for dependency injection testing or
 *   to use this function outside the context of a loop.
 * @return string HTML
 */
function link_to_beamia($text = null, $props = array(), $action = 'beam-me-up', $beam = null)
{
    if (!$beam) {
        $beam = get_current_record('BeamInternetArchiveBeam');
    }

    // We don't use link_to() because the name of the plugin is different from
    // the name of the class.
    $url = $action . '/' . $beam->id;
    $attr = !empty($props) ? ' ' . tag_attributes($props) : '';
    $text = !empty($text) ? $text : __('beam #%d', $beam->id);
    return '<a href="'. html_escape($url) . '"' . $attr . '>' . $text . '</a>';
}

/**
 * Return a link to the task page of a record.
 *
 * @uses link_to()
 * @param string $text HTML for the text of the link.
 * @param array $props Properties for the <a> tag.
 * @param BeamInternetArchiveBeam $beam Used for dependency injection testing or
 *   to use this function outside the context of a loop.
 * @return string HTML
 */
function link_to_beamia_url_if_any($url, $text = null, $props = array('target' => '_blank'), $beam = null)
{
    if (!$beam) {
        $beam = get_current_record('BeamInternetArchiveBeam');
    }
    $attr = !empty($props) ? ' ' . tag_attributes($props) : '';
    $text = (!empty($text) ? $text : __('beam #%d', $beam->id));

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
 * @param BeamInternetArchiveBeam $beam Used for dependency injection testing or
 *   to use this function outside the context of a loop.
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
 * @param BeamInternetArchiveBeam $beam Used for dependency injection testing or
 *   to use this function outside the context of a loop.
 * @return string HTML
 */
function link_to_beamia_tasks_if_any($text = null, $props = array('target' => '_blank'), $beam = null)
{
    $url = $beam->getUrlForTasks();
    return link_to_beamia_url_if_any($url, $text, $props, $beam);
}
