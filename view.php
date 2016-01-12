<?php

require_once("../../config.php");
require_once("lib.php");

$id = required_param('id', PARAM_INT);

$url = new moodle_url('/mod/hvp/view.php', array('id' => $id));
$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('hvp', $id)) {
  print_error('invalidcoursemodule');
}
if (! $course = $DB->get_record('course', array('id' => $cm->course))) {
  print_error('coursemisconf');
}

require_course_login($course, false, $cm);

// Load H5P Core
$core = hvp_get_instance('core');

// Load H5P Content
$content = $core->loadContent($cm->instance);
if ($content === NULL) {
    print_error('invalidhvp');
}

$PAGE->set_title(format_string($content['title']));
$PAGE->set_heading($course->fullname);

// Mark viewed by user (if required)
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Attach scripts, styles, etc. from core
$settings = hvp_get_core_assets();

// Add global disable settings
if (!isset($content['disable'])) {
  $content['disable'] = $core->getGlobalDisable();
}
else {
  $content['disable'] |= $core->getGlobalDisable();
}

// Embed is not supported in Moodle
$content['disable'] |= H5PCore::DISABLE_EMBED;

// Filter content parameters
$safe_parameters = $core->filterParameters($content);

$export = '';
if (!isset($CFG->mod_hvp_export) || $CFG->mod_hvp_export === TRUE) {
  // Find course context
  $context = \context_course::instance($course->id);

  $export_filename = ($content['slug'] ? $content['slug'] . '-' : '') . $content['id'] . '.h5p';
  $export = "/pluginfile.php/{$context->id}/mod_hvp/exports/{$export_filename}";
}

// Add JavaScript settings for this content
$cid = 'cid-' . $content['id'];
$settings['contents'][$cid] = array(
    'library' => H5PCore::libraryToString($content['library']),
    'jsonContent' => $safe_parameters,
    'fullScreen' => $content['library']['fullscreen'],
    'exportUrl' => $export,
    'title' => $content['title'],
    'disable' => $content['disable'],
    'contentUserData' => array(
      0 => array(
        'state' => \mod_hvp\Content_User_Data::load_user_data($content['id'])
        )
    )
);

// Get assets for this content
$preloaded_dependencies = $core->loadContentDependencies($content['id'], 'preloaded');
$files = $core->getDependenciesFiles($preloaded_dependencies);

// Determine embed type
$embedtype = H5PCore::determineEmbedType($content['embedType'], $content['library']['embedTypes']);
if ($embedtype === 'div') {
  $context = \context_system::instance();
  $hvp_path = "/pluginfile.php/{$context->id}/mod_hvp";

  // Schedule JavaScripts for loading through Moodle
  foreach ($files['scripts'] as $script) {
    $url = "{$CFG->httpswwwroot}{$hvp_path}{$script->path}{$script->version}";
    $settings['loadedJs'][] = $url;
    $PAGE->requires->js(new moodle_url($url), true);
  }

  // Schedule stylesheets for loading through Moodle
  foreach ($files['styles'] as $style) {
    $url = "{$CFG->httpswwwroot}{$hvp_path}{$style->path}{$style->version}";
    $settings['loadedCss'][] = $url;
    $PAGE->requires->css(new moodle_url($url));
  }
}
else {
  // JavaScripts and stylesheets will be loaded through h5p.js
  $settings['contents'][$cid]['scripts'] = $core->getAssetsUrls($files['scripts']);
  $settings['contents'][$cid]['styles'] = $core->getAssetsUrls($files['styles']);
}

// Print JavaScript settings to page
$PAGE->requires->data_for_js('H5PIntegration', $settings, true);

// Print page HTML
echo $OUTPUT->header();
echo '<div class="clearer"></div>';

if ($embedtype === 'div') {
    echo '<div class="h5p-content" data-content-id="' .  $content['id'] . '"></div>';
}
else {
    echo '<div class="h5p-iframe-wrapper"><iframe id="h5p-iframe-' . $content['id'] . '" class="h5p-iframe" data-content-id="' . $content['id'] . '" style="height:1px" src="about:blank" frameBorder="0" scrolling="no"></iframe></div>';
}

echo $OUTPUT->footer();
